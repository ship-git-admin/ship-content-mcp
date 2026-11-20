<?php
/**
 * 使い捨て番号（同じ署名付きリクエストを2回通さない）。
 *
 * - 主キー（鍵・番号）への INSERT IGNORE で、同時に来ても1回だけ通す。
 * - 時刻のずれの許容（±5分）より長く覚え、古いものはリクエストのついでに消す（WP-Cron に頼らない）。
 * - 表がないなどで書けないときは、通さない（安全側）。
 *
 * @package ShipContentMcp
 */

namespace ShipContentMcp\Security;

use ShipContentMcp\Core\Installer;

defined( 'ABSPATH' ) || exit;

final class NonceStore {

	/** 覚えておく秒数（許容のずれの前後 + 余裕）。 */
	public const KEEP = 900;

	private const PURGE_OPTION   = 'ship_content_mcp_nonce_purged';
	private const PURGE_INTERVAL = 60;

	public static function claim( string $key_id, string $nonce ): bool {
		global $wpdb;
		self::maybe_purge();
		$t        = Installer::table( 'nonces' );
		$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$t} (key_id, nonce, created_at) VALUES (%s, %s, %d)", $key_id, $nonce, time() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return 1 === $inserted;
	}

	public static function maybe_purge(): void {
		global $wpdb;
		$last = (int) get_option( self::PURGE_OPTION, 0 );
		if ( time() - $last < self::PURGE_INTERVAL ) {
			return;
		}
		update_option( self::PURGE_OPTION, time(), false );
		$t = Installer::table( 'nonces' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE created_at < %d", time() - self::KEEP ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	/** 自己テスト用：試験の鍵の番号を消す。 */
	public static function delete_for_key( string $key_id ): void {
		global $wpdb;
		$t = Installer::table( 'nonces' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE key_id = %s", $key_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	}
}
