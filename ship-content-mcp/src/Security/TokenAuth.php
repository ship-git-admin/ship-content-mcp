<?php
/**
 * トークンによる認証（管理画面で発行したトークンを、AI の MCP の設定に入れるだけで使う）。
 *
 * - MCP の入口の URL で、X-Ship-MCP-Token ヘッダー（または Authorization: Bearer smcp_…）があるときだけ働く。
 *   X-Ship-MCP-Token を使えば、サーバーの Basic 認証（Authorization ヘッダー）とぶつからない。
 * - HTTPS のほか、設定（token_http）が有効なら HTTP でも受け付ける（公開前の確認用 URL のため。公開時はプラグインごと外す）。
 * - 成功したら、そのトークンのユーザーとして扱う（署名・Application Password より先：優先度 14）。失敗したら 401。
 * - OAuth のアクセストークン（Authorization: Bearer smcpo_…）も、ここで確かめる（OAuth::verify_access）。
 *
 * @package ShipContentMcp
 */

namespace ShipContentMcp\Security;

use ShipContentMcp\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class TokenAuth {

	public const HEADER = 'X-Ship-MCP-Token';

	/** @var array{user_id?:int,error?:string}|null */
	private static ?array $state = null;

	public static function early_hooks(): void {
		add_filter( 'determine_current_user', array( self::class, 'determine_current_user' ), 14 );
	}

	public static function hooks(): void {
		add_filter( 'rest_authentication_errors', array( self::class, 'rest_authentication_errors' ), 4 );
	}

	/** リクエストのトークン（なければ ''）。 */
	public static function presented(): string {
		$t = self::server( 'HTTP_X_SHIP_MCP_TOKEN' );
		if ( '' !== $t ) {
			return $t;
		}
		$auth = self::server( 'HTTP_AUTHORIZATION' );
		if ( '' === $auth ) {
			$auth = self::server( 'REDIRECT_HTTP_AUTHORIZATION' );
		}
		return 1 === preg_match( '/^Bearer\s+(smcpo?_\S+)$/i', $auth, $m ) ? $m[1] : '';
	}

	/**
	 * @param int|false $user_id
	 * @return int|false
	 */
	public static function determine_current_user( $user_id ) {
		if ( ! empty( $user_id ) ) {
			return $user_id;
		}
		if ( null !== self::$state ) {
			return self::$state['user_id'] ?? $user_id;
		}
		$token = self::presented();
		if ( '' === $token || ! RequestContext::is_mcp_url() ) {
			return $user_id;
		}
		$settings = Settings::get();
		if ( str_starts_with( $token, OAuth::ACCESS_PREFIX ) ) {
			return self::determine_oauth( $token, $settings, $user_id );
		}
		$reason = '';
		if ( ! $settings['enabled'] || ! $settings['auth_token'] ) {
			$reason = 'token_disabled';
		} elseif ( ! is_ssl() && ! $settings['token_http'] ) {
			$reason = 'token_http_disabled';
		}
		$parsed = DeviceKeys::parse_token( $token );
		if ( '' === $reason ) {
			$result = DeviceKeys::verify_token( $token );
			if ( is_string( $result ) ) {
				$reason = $result;
			} else {
				self::$state = array( 'user_id' => $result );
				RequestContext::set_token( (string) $parsed['key_id'] );
				return $result;
			}
		}
		self::$state = array( 'error' => $reason );
		AuditLog::write(
			'auth',
			'denied',
			'MCP-002',
			'token: ' . $reason,
			array(
				'key_id'  => null !== $parsed ? $parsed['key_id'] : '',
				'user_id' => 0,
				'auth'    => 'token',
			)
		);
		return $user_id;
	}

	/**
	 * OAuth のアクセストークン。
	 *
	 * @param array<string,mixed> $settings
	 * @param int|false           $user_id
	 * @return int|false
	 */
	private static function determine_oauth( string $token, array $settings, $user_id ) {
		$reason = '';
		if ( ! $settings['enabled'] || ! $settings['auth_oauth'] ) {
			$reason = 'oauth_disabled';
		} elseif ( ! is_ssl() && ! $settings['token_http'] ) {
			$reason = 'token_http_disabled';
		} else {
			$r = OAuth::verify_access( $token );
			if ( is_array( $r ) ) {
				self::$state = array( 'user_id' => $r['user_id'] );
				RequestContext::set_oauth( $r['key_id'] );
				return $r['user_id'];
			}
			$reason = $r;
		}
		self::$state = array(
			'error' => $reason,
			'oauth' => true,
		);
		AuditLog::write(
			'auth',
			'denied',
			'MCP-002',
			'oauth: ' . $reason,
			array(
				'key_id'  => '',
				'user_id' => 0,
				'auth'    => 'oauth',
			)
		);
		return $user_id;
	}

	/** OAuth のアクセストークンが使えなかったか（401 に invalid_token を付けるため）。 */
	public static function oauth_failed(): bool {
		return null !== self::$state && ! empty( self::$state['oauth'] ) && isset( self::$state['error'] );
	}

	/**
	 * @param mixed $result
	 * @return mixed
	 */
	public static function rest_authentication_errors( $result ) {
		if ( ! empty( $result ) || null === self::$state ) {
			return $result;
		}
		if ( isset( self::$state['error'] ) ) {
			$msg = 'token_http_disabled' === self::$state['error'] ? '認証に失敗しました（MCP-002：このサイトは HTTP ではトークンを受け付けません。https:// の入口を使ってください）' : '認証に失敗しました（MCP-002）';
			if ( ! empty( self::$state['oauth'] ) && 'token_http_disabled' !== self::$state['error'] ) {
				$msg = '認証に失敗しました（MCP-002：接続の期限が切れたか、止められています。AI でログインし直すか、管理者に新しい招待コードをもらってください）';
			}
			return new \WP_Error( 'ship_content_mcp_auth', $msg, array( 'status' => 401 ) );
		}
		return true;
	}

	private static function server( string $key ): string {
		return isset( $_SERVER[ $key ] ) && is_string( $_SERVER[ $key ] ) ? trim( (string) $_SERVER[ $key ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- 形は呼び出し側で確かめる
	}

	/** 自己テスト用。 */
	public static function reset_for_tests(): void {
		self::$state = null;
	}
}
