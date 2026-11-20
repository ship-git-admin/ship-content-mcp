<?php
/**
 * 管理者が確かめた構造だけに書く（「未検証の構造には書かない」）。
 *
 * - 状態を「試験合格」「稼働」にしたとき（または確かめ直したとき）に、その投稿タイプで使われうる ACF のグループの指紋を記録する。
 *   分類などで切り替わるグループも含める（新しい下書きの推定と、分類の条件を1つずつ当てはめた推定を合わせる）。
 * - 「試験合格」「稼働」の投稿タイプに書くとき、使われるグループが記録と同じでなければ止める（ACF-003）。
 *   「試験中」は試験のための状態なので止めない。
 * - グループの指紋は、ラベル・説明文を含めない（SchemaReader::fingerprint と同じ考え方）。
 *
 * @package ShipContentMcp
 */

namespace ShipContentMcp\Content;

use ShipContentMcp\ACF\SchemaReader;
use ShipContentMcp\Core\DomainError;
use ShipContentMcp\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class StructureLock {

	public const LOCKED_STATUSES = array( 'tested', 'active' );

	/**
	 * いま、その投稿タイプで使われうるグループの指紋。
	 *
	 * @return array<string,string> グループ key => 指紋
	 */
	public static function current( string $post_type ): array {
		$reader   = new SchemaReader();
		$base     = $reader->read( $post_type );
		$out      = self::hashes( $base['data']['field_groups'] );
		$allowed  = PostTypes::taxonomy_names( $post_type );
		foreach ( (array) $base['data']['location_dependencies'] as $dep ) {
			if ( 1 !== preg_match( '/^post_taxonomy\s*(==|!=)\s*([a-z0-9_-]+):(.+)$/', (string) $dep, $m ) || ! in_array( $m[2], $allowed, true ) ) {
				continue;
			}
			$term = get_term_by( 'slug', $m[3], $m[2] );
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$variant = $reader->read(
				$post_type,
				0,
				array(
					array(
						'taxonomy' => $m[2],
						'term_ids' => array( (int) $term->term_id ),
					),
				)
			);
			$out += self::hashes( $variant['data']['field_groups'] );
		}
		ksort( $out );
		return $out;
	}

	/**
	 * @param list<array<string,mixed>> $groups SchemaReader の field_groups
	 * @return array<string,string>
	 */
	public static function hashes( array $groups ): array {
		$out = array();
		foreach ( $groups as $g ) {
			$out[ (string) $g['key'] ] = SchemaReader::fingerprint( array( $g ) );
		}
		return $out;
	}

	/**
	 * いまの構造を「確かめた」として記録する形。
	 *
	 * @return array{groups:array<string,string>,at:int,by:int}
	 */
	public static function capture( string $post_type ): array {
		return array(
			'groups' => self::current( $post_type ),
			'at'     => time(),
			'by'     => get_current_user_id(),
		);
	}

	/**
	 * 状態を「試験合格」「稼働」にしたとき・確かめ直したときに、いまの構造を記録する。それ以外は前の記録を残す。
	 * （管理画面の基本設定と、WP-CLI の post-type で使う）
	 *
	 * @param array<string,mixed> $before 変える前の設定
	 * @return array<string,mixed>|null
	 */
	public static function next_verified( string $name, string $status, array $before, bool $reverify ): ?array {
		$old        = $before['post_types'][ $name ] ?? array();
		$old_status = (string) ( $old['status'] ?? 'unverified' );
		$locked     = in_array( $status, self::LOCKED_STATUSES, true );
		if ( $locked && ( $reverify || ! in_array( $old_status, self::LOCKED_STATUSES, true ) || empty( $old['verified'] ) ) ) {
			try {
				$captured = self::capture( $name );
				\ShipContentMcp\Security\AuditLog::write( 'admin', 'ok', '', 'structure verified for ' . $name . ' (' . count( $captured['groups'] ) . ' groups)' );
				return $captured;
			} catch ( \Throwable $e ) {
				return $old['verified'] ?? null;
			}
		}
		return $old['verified'] ?? null;
	}

	/**
	 * 記録と違うグループ（なければ空）。記録がなければ、使われるグループをすべて返す。
	 *
	 * @param list<array<string,mixed>> $groups
	 * @return list<string>
	 */
	public static function differences( string $post_type, array $groups ): array {
		$verified = (array) ( Settings::get()['post_types'][ $post_type ]['verified']['groups'] ?? array() );
		$out      = array();
		foreach ( self::hashes( $groups ) as $key => $hash ) {
			if ( ( $verified[ $key ] ?? '' ) !== $hash ) {
				$out[] = $key;
			}
		}
		return $out;
	}

	/**
	 * 「試験合格」「稼働」なら、確かめた構造と同じかを確かめる。
	 *
	 * @param list<array<string,mixed>> $groups 書こうとしている投稿で使われるグループ
	 * @throws DomainError ACF-003
	 */
	public static function check( string $post_type, array $groups ): void {
		if ( ! in_array( PostTypes::status( $post_type ), self::LOCKED_STATUSES, true ) ) {
			return;
		}
		$diff = self::differences( $post_type, $groups );
		if ( array() !== $diff ) {
			throw new DomainError( 'ACF-003', 'structure not verified: ' . implode( ',', array_slice( $diff, 0, 10 ) ) );
		}
	}
}
