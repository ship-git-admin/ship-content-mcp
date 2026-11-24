<?php
/**
 * 端末の鍵とトークン（サイトには公開鍵・トークンのハッシュだけを置く）。
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

	// ---- トークン（管理画面で発行し、MCP の設定に入れるだけで使う） -------------------
	//
	// 形：smcp_<鍵 ID>_<秘密 43文字（32バイトの base64url）>。サイトには秘密の SHA-256 だけを置く（public_key の欄に tok: を付けて）。
	// デモサイトで発行しておけば、コピーしたサイトにも DB ごと入るので、同じトークンが使える。

	public const TOKEN_PREFIX = 'smcp_';
	public const TOKEN_MARK   = 'tok:';

	/**
	 * @return array{key_id:string,secret:string}|null
	 */
	public static function parse_token( string $token ): ?array {
		if ( 1 !== preg_match( '/^smcp_(k[0-9a-f]{16})_([A-Za-z0-9_-]{43})$/', trim( $token ), $m ) ) {
			return null;
		}
		return array(
			'key_id' => $m[1],
			'secret' => $m[2],
		);
	}

	public static function token_hash( string $secret ): string {
		return self::TOKEN_MARK . base64_encode( hash( 'sha256', $secret, true ) );
	}

	public static function is_token( array $row ): bool {
		return str_starts_with( (string) ( $row['public_key'] ?? '' ), self::TOKEN_MARK );
	}

	/**
	 * トークンを発行する（トークンはここでしか手に入らない。サイトにはハッシュだけを置く）。
	 *
	 * @return array{key_id:string,token:string}
	 * @throws \InvalidArgumentException 登録できないとき
	 */
	public static function add_token( int $user_id, string $label, int $by ): array {
		global $wpdb;
		$secret = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
		$key_id = 'k' . bin2hex( random_bytes( 8 ) );
		$ok     = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Installer::table( 'keys' ),
			array(
				'key_id'     => $key_id,
				'user_id'    => $user_id,
				'label'      => mb_substr( sanitize_text_field( '' === trim( $label ) ? 'トークン' : $label ), 0, 100 ),
				'public_key' => self::token_hash( $secret ),
				'created_at' => current_time( 'mysql', true ),
				'created_by' => $by,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%d' )
		);
		if ( ! $ok ) {
			throw new \InvalidArgumentException( 'トークンを登録できませんでした（表がない可能性があります。プラグインを一度無効にして有効にし直してください）' );
		}
		return array(
			'key_id' => $key_id,
			'token'  => self::TOKEN_PREFIX . $key_id . '_' . $secret,
		);
	}

	/**
	 * トークンを確かめる。
	 *
	 * @return int|string ユーザー ID、または失敗の理由
	 */
	public static function verify_token( string $token ) {
		$p = self::parse_token( $token );
		if ( null === $p ) {
			return 'bad_format';
		}
		$row = self::find( $p['key_id'] );
		if ( null === $row || ! self::is_token( $row ) ) {
			return 'unknown_key';
		}
		if ( ! hash_equals( (string) $row['public_key'], self::token_hash( $p['secret'] ) ) ) {
			return 'bad_token';
		}
		if ( ! empty( $row['revoked_at'] ) ) {
			return 'revoked';
		}
		$user_id = (int) $row['user_id'];
		if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
			return 'user_missing';
		}
		self::touch( $p['key_id'] );
		return $user_id;
	}

	/** 有効な鍵・トークンをすべて止める（公開の前の片付け）。止めた数を返す。 */
	public static function revoke_all( int $by ): int {
		global $wpdb;
		$t = Installer::table( 'keys' );
		return (int) $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET revoked_at = %s, revoked_by = %d WHERE revoked_at IS NULL", current_time( 'mysql', true ), $by ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
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
