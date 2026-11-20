<?php
/**
 * 端末の鍵（公開鍵だけを持つ。秘密鍵は PM の端末から出さない）。
 *
 * 1台ごとに登録し、いつでも止められる（止めた鍵は消さずに残し、記録と照らせるようにする）。
 *
 * @package ShipContentMcp
 */

namespace ShipContentMcp\Security;

use ShipContentMcp\Core\Installer;

defined( 'ABSPATH' ) || exit;

final class DeviceKeys {

	/** 最終利用日時を書き直す間隔（秒）。 */
	private const TOUCH_INTERVAL = 60;

	/**
	 * @return array<string,mixed>|null
	 */
	public static function find( string $key_id ): ?array {
		global $wpdb;
		if ( ! SignatureCodec::valid_key_id( $key_id ) ) {
			return null;
		}
		$t   = Installer::table( 'keys' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE key_id = %s", $key_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return is_array( $row ) ? $row : null;
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public static function all(): array {
		global $wpdb;
		$t    = Installer::table( 'keys' );
		$rows = $wpdb->get_results( "SELECT * FROM {$t} ORDER BY id DESC LIMIT 500", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @throws \InvalidArgumentException 形が違う・登録済みのとき
	 */
	public static function add( int $user_id, string $label, string $public_key_b64, int $created_by ): string {
		global $wpdb;
		$public_key_b64 = trim( $public_key_b64 );
		$raw            = SignatureCodec::decode_public_key( $public_key_b64 );
		if ( null === $raw ) {
			throw new \InvalidArgumentException( '公開鍵の形が違います（中継プログラムの keygen が表示した文字列を、そのまま貼ってください）' );
		}
		$normalized = base64_encode( $raw );
		$t          = Installer::table( 'keys' );
		$exists     = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE public_key = %s", $normalized ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		if ( (int) $exists > 0 ) {
			throw new \InvalidArgumentException( 'この公開鍵は登録済みです（端末ごとに新しい鍵を作ってください）' );
		}
		$key_id = 'k' . bin2hex( random_bytes( 8 ) );
		$ok     = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$t,
			array(
				'key_id'     => $key_id,
				'user_id'    => $user_id,
				'label'      => mb_substr( sanitize_text_field( $label ), 0, 100 ),
				'public_key' => $normalized,
				'created_at' => current_time( 'mysql', true ),
				'created_by' => $created_by,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%d' )
		);
		if ( ! $ok ) {
			throw new \InvalidArgumentException( '鍵を登録できませんでした（表がない可能性があります。プラグインを一度無効にして有効にし直してください）' );
		}
		return $key_id;
	}

	public static function revoke( string $key_id, int $by ): bool {
		global $wpdb;
		$t = Installer::table( 'keys' );
		return (bool) $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET revoked_at = %s, revoked_by = %d WHERE key_id = %s AND revoked_at IS NULL", current_time( 'mysql', true ), $by, $key_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	public static function touch( string $key_id ): void {
		global $wpdb;
		$t   = Installer::table( 'keys' );
		$now = current_time( 'mysql', true );
		$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET last_used_at = %s WHERE key_id = %s AND ( last_used_at IS NULL OR last_used_at < %s )", $now, $key_id, gmdate( 'Y-m-d H:i:s', time() - self::TOUCH_INTERVAL ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	public static function count_active(): int {
		global $wpdb;
		$t = Installer::table( 'keys' );
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE revoked_at IS NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	/** 自己テスト用：試験の鍵（ラベルの印付き）を消す。 */
	public static function delete_test_keys( string $label_mark ): int {
		global $wpdb;
		$t = Installer::table( 'keys' );
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE label = %s", $label_mark ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	}
}
