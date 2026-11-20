<?php
/**
 * 二重送信の防止（idempotency_key）。
 *
 * - ユーザーごと・キーごとに1行（主キーへの INSERT IGNORE で、同時に来ても1回だけ処理する）。
 * - 同じキー・同じ内容：前の結果をそのまま返す（下書きを2つ作らない）。
 * - 同じキー・違う内容：拒否（IDEM-001）。
 * - 処理中：拒否（IDEM-002、少し待って送り直してもらう）。10分を過ぎた処理中は、失敗とみなして消す。
 * - 失敗したとき（書く前の検証の失敗など）は行を消し、同じキーで直して送り直せるようにする。
 * - 24時間で消す（リクエストのついでに）。
 *
 * @package ShipContentMcp
 */

namespace ShipContentMcp\Content;

use ShipContentMcp\Core\DomainError;
use ShipContentMcp\Core\Installer;

defined( 'ABSPATH' ) || exit;

final class Idempotency {

	public const KEEP  = DAY_IN_SECONDS;
	public const STALE = 600;

	private const PURGE_OPTION = 'ship_content_mcp_idem_purged';

	/**
	 * @param array<string,mixed> $payload idempotency_key を除いた入力
	 * @return array<string,mixed>|null 前の結果（同じ依頼のとき）。新しい依頼なら null（処理中の印を付けた）
	 * @throws DomainError
	 */
	public static function claim( int $user_id, string $key, string $op, array $payload ): ?array {
		global $wpdb;
		self::maybe_purge();
		$t       = Installer::table( 'idem' );
		$hash    = hash( 'sha256', $key );
		$payload = hash( 'sha256', (string) wp_json_encode( self::sort( $payload ) ) );
		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$t} (user_id, idem_hash, op, payload_hash, status, created_at) VALUES (%d, %s, %s, %s, 'pending', %d)", $user_id, $hash, $op, $payload, time() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			if ( 1 === $inserted ) {
				return null;
			}
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE user_id = %d AND idem_hash = %s", $user_id, $hash ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			if ( ! is_array( $row ) ) {
				throw new DomainError( 'SYS-001', 'idempotency table unavailable', true );
			}
			if ( $row['op'] !== $op || $row['payload_hash'] !== $payload ) {
				throw new DomainError( 'IDEM-001', 'same key, different payload' );
			}
			if ( 'done' === $row['status'] ) {
				$result = json_decode( (string) $row['result'], true );
				return is_array( $result ) ? $result : array();
			}
			if ( time() - (int) $row['created_at'] < self::STALE ) {
				throw new DomainError( 'IDEM-002', 'same request in progress', true );
			}
			self::release( $user_id, $key );
		}
		throw new DomainError( 'IDEM-002', 'could not claim', true );
	}

	/**
	 * @param array<string,mixed> $result
	 */
	public static function complete( int $user_id, string $key, int $post_id, array $result ): void {
		global $wpdb;
		$t = Installer::table( 'idem' );
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$t,
			array(
				'status'  => 'done',
				'post_id' => $post_id,
				'result'  => (string) wp_json_encode( $result ),
			),
			array(
				'user_id'   => $user_id,
				'idem_hash' => hash( 'sha256', $key ),
			)
		);
	}

	public static function release( int $user_id, string $key ): void {
		global $wpdb;
		$wpdb->delete( Installer::table( 'idem' ), array( 'user_id' => $user_id, 'idem_hash' => hash( 'sha256', $key ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	private static function maybe_purge(): void {
		global $wpdb;
		$last = (int) get_option( self::PURGE_OPTION, 0 );
		if ( time() - $last < HOUR_IN_SECONDS ) {
			return;
		}
		update_option( self::PURGE_OPTION, time(), false );
		$t = Installer::table( 'idem' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE created_at < %d", time() - self::KEEP ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * キーの順番で中身が変わらないようにする。
	 *
	 * @param mixed $v
	 * @return mixed
	 */
	private static function sort( $v ) {
		if ( ! is_array( $v ) ) {
			return $v;
		}
		if ( ! array_is_list( $v ) ) {
			ksort( $v );
		}
		return array_map( array( self::class, 'sort' ), $v );
	}
}
