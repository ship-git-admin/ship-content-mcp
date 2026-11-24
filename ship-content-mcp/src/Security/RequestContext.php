<?php
/**
 * いまのリクエストが、このプラグインの MCP の入口に来たものかを判断する。
 *
 * - URL で判断（署名の認証の前に使う）：/{rest 接頭辞}/ship-content/mcp、/index.php/... 、?rest_route=
 * - REST の振り分けで判断（本当の判定）：rest_pre_dispatch のルートが /ship-content/mcp か
 * - 署名で認証したリクエストは、MCP の入口以外へ行かせない（URL の書き方で別の REST に届かせない）
 *
 * @package ShipContentMcp
 */

namespace ShipContentMcp\Security;

use ShipContentMcp\Core\Consts;

defined( 'ABSPATH' ) || exit;

final class RequestContext {

	private static ?bool $url_match = null;
	private static bool $dispatching = false;
	private static string $auth = '';
	private static string $key_id = '';
	private static string $nonce = '';
	private static string $request_id = '';

	public static function hooks(): void {
		add_filter( 'rest_pre_dispatch', array( self::class, 'on_pre_dispatch' ), 0, 3 );
	}

	/**
	 * @param mixed            $result
	 * @param \WP_REST_Server  $server
	 * @param \WP_REST_Request $request
	 * @return mixed
	 */
	public static function on_pre_dispatch( $result, $server, $request ) {
		unset( $server );
		$route = untrailingslashit( (string) $request->get_route() );
		if ( Consts::REST_ROUTE === $route ) {
			self::$dispatching = true;
		} elseif ( 'signature' === self::$auth || 'token' === self::$auth ) {
			AuditLog::write( 'auth', 'denied', 'MCP-002', self::$auth . ' used outside MCP route' );
			return new \WP_Error( 'ship_content_mcp_route', 'この認証では、この操作はできません', array( 'status' => 403 ) );
		}
		return $result;
	}

	/**
	 * URL が MCP の入口か（REST の振り分けより前に使える）。
	 */
	public static function is_mcp_url(): bool {
		if ( null !== self::$url_match ) {
			return self::$url_match;
		}
		$target = Consts::REST_ROUTE;
		// phpcs:disable WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- ルートの文字列を比べるだけ
		$get  = isset( $_GET['rest_route'] ) && is_string( $_GET['rest_route'] ) ? '/' . trim( wp_unslash( $_GET['rest_route'] ), '/' ) : null;
		$post = isset( $_POST['rest_route'] ) && is_string( $_POST['rest_route'] ) ? '/' . trim( wp_unslash( $_POST['rest_route'] ), '/' ) : null;
		// phpcs:enable
		if ( null !== $get || null !== $post ) {
			self::$url_match = ( null === $get || $target === $get ) && ( null === $post || $target === $post );
			return self::$url_match;
		}
		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$path   = rtrim( (string) wp_parse_url( $uri, PHP_URL_PATH ), '/' );
		$home   = rtrim( (string) wp_parse_url( (string) get_option( 'home' ), PHP_URL_PATH ), '/' );
		$prefix = trim( rest_get_url_prefix(), '/' );
		$ok     = array( $home . '/' . $prefix . $target, $home . '/index.php/' . $prefix . $target );

		self::$url_match = in_array( $path, $ok, true );
		return self::$url_match;
	}

	/** REST の振り分けで MCP の入口に決まったか。 */
	public static function in_mcp_dispatch(): bool {
		return self::$dispatching;
	}

	private static bool $pretend_http = false;

	public static function is_cli(): bool {
		return ! self::$pretend_http && defined( 'WP_CLI' ) && WP_CLI;
	}

	/** 自己テスト用（WP-CLI の中で、HTTP から来たものとして権限を確かめる）。 */
	public static function pretend_http_for_tests( bool $on ): void {
		self::$pretend_http = $on;
	}

	public static function set_token( string $key_id ): void {
		self::$auth   = 'token';
		self::$key_id = $key_id;
	}

	public static function set_signature( string $key_id, string $nonce ): void {
		self::$auth   = 'signature';
		self::$key_id = $key_id;
		self::$nonce  = $nonce;
	}

	/** 応答の署名に使う、リクエストの使い捨て番号（形だけ確かめたもの）。 */
	public static function set_nonce( string $nonce ): void {
		self::$nonce = $nonce;
	}

	public static function nonce(): string {
		return self::$nonce;
	}

	public static function key_id(): string {
		return self::$key_id;
	}

	/**
	 * どの方法で認証したか：token / signature / app_password / cookie / cli / ''。
	 */
	public static function auth_method(): string {
		if ( '' !== self::$auth ) {
			return self::$auth;
		}
		if ( self::is_cli() ) {
			return 'cli';
		}
		if ( function_exists( 'rest_get_authenticated_app_password' ) && rest_get_authenticated_app_password() ) {
			return 'app_password';
		}
		return is_user_logged_in() ? 'cookie' : '';
	}

	/** 記録・応答に付ける ID（中身に意味はない）。 */
	public static function request_id(): string {
		if ( '' === self::$request_id ) {
			self::$request_id = bin2hex( random_bytes( 8 ) );
		}
		return self::$request_id;
	}

	/** 自己テスト用（同じプロセスで状態を戻す）。 */
	public static function reset_for_tests(): void {
		self::$url_match   = null;
		self::$dispatching = false;
		self::$auth        = '';
		self::$key_id      = '';
		self::$nonce       = '';
		self::$request_id  = '';
	}

	/** 自己テスト用（MCP の振り分け中とみなす）。 */
	public static function simulate_dispatch_for_tests( bool $on ): void {
		self::$dispatching = $on;
	}
}
