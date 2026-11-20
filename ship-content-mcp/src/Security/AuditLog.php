<?php
/**
 * 操作の記録（日時・ユーザー・認証の方法・ツール・対象・結果・理由・相関 ID）。
 *
 * - 本文・画像・パスワード・署名の値は書かない。
 * - 管理者だけが見る（IP も管理者だけ）。
 * - 90日より古いもの・2万件を超えた分は、書くついでに消す（WP-Cron に頼らない）。
 *
 * @package ShipContentMcp
 */

namespace ShipContentMcp\Security;

use ShipContentMcp\Core\Installer;

defined( 'ABSPATH' ) || exit;

final class AuditLog {

	public const KEEP_DAYS = 90;
	public const MAX_ROWS  = 20000;

	private const PURGE_OPTION = 'ship_content_mcp_log_purged';

	private static bool $muted = false;

	/** 自己テストの間は書かない（試験の拒否を本物の記録に混ぜない）。 */
	public static function mute( bool $on ): void {
		self::$muted = $on;
	}

	/**
	 * ユーザーを決めている途中（determine_current_user の中）から呼ぶときは、user_id と auth を渡す
	 * （いまのユーザーを聞くと、ユーザーの決定が入れ子になるため）。
	 *
	 * @param array<string,mixed> $extra tool, post_type, post_id, key_id, user_id, auth
	 */
	public static function write( string $event, string $result, string $code = '', string $detail = '', array $extra = array() ): void {
		global $wpdb;
		if ( self::$muted ) {
			return;
		}
		$known   = did_action( 'set_current_user' ) > 0;
		$user_id = array_key_exists( 'user_id', $extra ) ? (int) $extra['user_id'] : ( $known ? get_current_user_id() : 0 );
		$auth    = array_key_exists( 'auth', $extra ) ? (string) $extra['auth'] : ( $known ? RequestContext::auth_method() : '' );
		$t       = Installer::table( 'log' );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$t,
			array(
				'created_at' => current_time( 'mysql', true ),
				'request_id' => RequestContext::request_id(),
				'user_id'    => $user_id,
				'auth'       => substr( $auth, 0, 20 ),
				'key_id'     => substr( (string) ( $extra['key_id'] ?? RequestContext::key_id() ), 0, 40 ),
				'event'      => substr( $event, 0, 40 ),
				'tool'       => substr( (string) ( $extra['tool'] ?? '' ), 0, 100 ),
				'post_type'  => substr( (string) ( $extra['post_type'] ?? '' ), 0, 64 ),
				'post_id'    => (int) ( $extra['post_id'] ?? 0 ),
				'result'     => substr( $result, 0, 20 ),
				'code'       => substr( $code, 0, 40 ),
				'detail'     => mb_substr( $detail, 0, 250 ),
				'ip'         => IpRules::remote_addr(),
			)
		);
		self::maybe_purge();
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public static function recent( int $limit = 200 ): array {
		global $wpdb;
		$t    = Installer::table( 'log' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} ORDER BY id DESC LIMIT %d", max( 1, min( 1000, $limit ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return is_array( $rows ) ? $rows : array();
	}

	private static function maybe_purge(): void {
		global $wpdb;
		$last = (int) get_option( self::PURGE_OPTION, 0 );
		if ( time() - $last < HOUR_IN_SECONDS ) {
			return;
		}
		update_option( self::PURGE_OPTION, time(), false );
		$t = Installer::table( 'log' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - self::KEEP_DAYS * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$max = (int) $wpdb->get_var( "SELECT MAX(id) FROM {$t}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		if ( $max > self::MAX_ROWS ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE id <= %d", $max - self::MAX_ROWS ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		}
	}
}
