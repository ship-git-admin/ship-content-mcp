<?php
/**
 * OAuth の入口（MCP の認可の仕様：RFC 9728・RFC 8414・RFC 7591・OAuth 2.1 の PKCE）。
 *
 * - /.well-known/oauth-protected-resource（…/wp-json/ship-content/mcp）：この MCP の認証の窓口の案内
 * - /.well-known/oauth-authorization-server：窓口の入口の一覧
 * - /wp-json/ship-content/oauth/register：AI の登録（AI が自動で呼ぶ）
 * - /wp-json/ship-content/oauth/authorize：「接続の許可」の画面（制作担当者のブラウザで開く。招待コードを入れて許可）
 * - /wp-json/ship-content/oauth/token・revoke：トークンの発行・更新・取り消し（AI が自動で呼ぶ）
 * - MCP の入口の 401 に WWW-Authenticate（Bearer resource_metadata=…）を付ける（AI が窓口を見つけるため）
 *
 * URL は、リクエストが来たホストで組み立てる（確認用 URL・本番のドメインのどちらで来ても、その URL で返す）。
 * サーバーの Basic 認証があるサイトでは、AI が自動で呼ぶ道（.well-known・register・token・revoke・mcp・upload）だけを
 * Basic 認証の例外にする（許可の画面は Basic 認証のまま。ブラウザが ID とパスワードを聞く）。
 *
 * @package ShipContentMcp
 */

namespace ShipContentMcp\Security;

use ShipContentMcp\Core\Consts;
use ShipContentMcp\Core\Settings;
use ShipContentMcp\MCP\Server;

defined( 'ABSPATH' ) || exit;

final class OAuthServer {

	private const HTML_MARK = 'X-Ship-Content-Html';

	public static function hooks(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		add_action( 'init', array( self::class, 'well_known' ), 1 );
		add_filter( 'rest_post_dispatch', array( self::class, 'challenge' ), 10, 3 );
		add_filter( 'rest_pre_serve_request', array( self::class, 'serve_html' ), 10, 4 );
	}

	/** OAuth を受け付けるか（MCP が有効・OAuth が有効・HTTPS か、HTTP を許しているか）。 */
	public static function enabled(): bool {
		$s = Settings::get();
		return $s['enabled'] && $s['auth_oauth'] && ( is_ssl() || $s['token_http'] );
	}

	// ---- URL -----------------------------------------------------------------

	public static function origin(): string {
		$host = isset( $_SERVER['HTTP_HOST'] ) && is_string( $_SERVER['HTTP_HOST'] ) ? strtolower( (string) $_SERVER['HTTP_HOST'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- 形を下で確かめる
		if ( 1 !== preg_match( '/^[a-z0-9.\-]+(:[0-9]{1,5})?$/', $host ) ) {
			$h    = wp_parse_url( home_url() );
			$host = (string) ( $h['host'] ?? '' ) . ( isset( $h['port'] ) ? ':' . $h['port'] : '' );
		}
		return ( is_ssl() ? 'https' : 'http' ) . '://' . $host;
	}

	/** WordPress が作った URL を、リクエストが来たホストの URL にする。 */
	public static function localize( string $wp_url ): string {
		$p = wp_parse_url( $wp_url );
		return self::origin() . (string) ( $p['path'] ?? '' ) . ( isset( $p['query'] ) ? '?' . $p['query'] : '' );
	}

	public static function resource(): string {
		return self::localize( Server::endpoint() );
	}

	public static function issuer(): string {
		return self::origin();
	}

	public static function endpoint_url( string $name ): string {
		return self::localize( rest_url( Consts::NS . '/oauth/' . $name ) );
	}

	public static function prm_url(): string {
		return self::origin() . '/.well-known/oauth-protected-resource' . (string) wp_parse_url( self::resource(), PHP_URL_PATH );
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function protected_resource_metadata(): array {
		return array(
			'resource'                 => self::resource(),
			'authorization_servers'    => array( self::issuer() ),
			'bearer_methods_supported' => array( 'header' ),
			'resource_name'            => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) . '（Ship Content MCP）',
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function authorization_server_metadata(): array {
		return array(
			'issuer'                                         => self::issuer(),
			'authorization_endpoint'                         => self::endpoint_url( 'authorize' ),
			'token_endpoint'                                 => self::endpoint_url( 'token' ),
			'registration_endpoint'                          => self::endpoint_url( 'register' ),
			'revocation_endpoint'                            => self::endpoint_url( 'revoke' ),
			'response_types_supported'                       => array( 'code' ),
			'grant_types_supported'                          => array( 'authorization_code', 'refresh_token' ),
			'code_challenge_methods_supported'               => array( 'S256' ),
			'token_endpoint_auth_methods_supported'          => array( 'none' ),
			'revocation_endpoint_auth_methods_supported'     => array( 'none' ),
			'authorization_response_iss_parameter_supported' => true,
		);
	}

	// ---- .well-known -----------------------------------------------------------

	public static function well_known(): void {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- パスを比べるだけ
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		if ( ! str_starts_with( $path, '/.well-known/' ) ) {
			return;
		}
		$prm  = '/.well-known/oauth-protected-resource';
		$as   = '/.well-known/oauth-authorization-server';
		$kind = '';
		if ( $prm === $path || str_starts_with( $path, $prm . '/' ) ) {
			$kind = 'prm';
		} elseif ( $as === $path || str_starts_with( $path, $as . '/' ) ) {
			$kind = 'as';
		}
		if ( '' === $kind || ! self::enabled() ) {
			return; // WordPress の 404 のまま。
		}
		status_header( 200 );
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: no-store' );
		header( 'Access-Control-Allow-Origin: *' );
		header( 'X-Robots-Tag: noindex' );
		echo wp_json_encode( 'prm' === $kind ? self::protected_resource_metadata() : self::authorization_server_metadata(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		exit;
	}

	// ---- REST の入口 -------------------------------------------------------------

	public static function register_routes(): void {
		$open = '__return_true'; // 中身で確かめる（招待コード・PKCE・登録した AI）。
		register_rest_route(
			Consts::NS,
			'/oauth/register',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'route_register' ),
				'permission_callback' => $open,
			)
		);
		register_rest_route(
			Consts::NS,
			'/oauth/authorize',
			array(
				'methods'             => array( 'GET', 'POST' ),
				'callback'            => array( self::class, 'route_authorize' ),
				'permission_callback' => $open,
			)
		);
		register_rest_route(
			Consts::NS,
			'/oauth/token',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'route_token' ),
				'permission_callback' => $open,
			)
		);
		register_rest_route(
			Consts::NS,
			'/oauth/revoke',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'route_revoke' ),
				'permission_callback' => $open,
			)
		);
	}

	public static function route_register( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! self::enabled() ) {
			return self::json( self::e( 'invalid_request', 'このサイトでは OAuth を使っていません' ), 404 );
		}
		if ( ! self::rate( 'reg', 30, HOUR_IN_SECONDS ) ) {
			return self::json( self::e( 'temporarily_unavailable', '登録が多すぎます。しばらく待ってください' ), 429 );
		}
		OAuth::cleanup();
		$meta = $request->get_json_params();
		if ( ! is_array( $meta ) ) {
			$meta = $request->get_body_params();
		}
		$out = OAuth::register_client( is_array( $meta ) ? $meta : array() );
		if ( isset( $out['error'] ) ) {
			AuditLog::write( 'oauth', 'denied', 'MCP-002', 'register: ' . $out['error'], self::ctx() );
			return self::json( $out, 400 );
		}
		AuditLog::write( 'oauth', 'ok', '', 'client registered: ' . $out['client_name'] . ' ' . $out['client_id'], self::ctx() );
		return self::json( $out, 201 );
	}

	public static function route_authorize( \WP_REST_Request $request ): \WP_REST_Response {
		$p = array();
		foreach ( array( 'response_type', 'client_id', 'redirect_uri', 'state', 'code_challenge', 'code_challenge_method', 'resource', 'scope' ) as $k ) {
			$v       = $request->get_param( $k );
			$p[ $k ] = is_string( $v ) ? mb_substr( $v, 0, 1000 ) : '';
		}
		if ( ! self::enabled() ) {
			return self::page( self::message_html( 'このサイトでは、ブラウザで許可する接続を使っていません', '管理者に確かめてください。' ), 404 );
		}
		$client = OAuth::find_client( $p['client_id'] );
		if ( null === $client ) {
			return self::page( self::message_html( 'AI の登録が見つかりません', 'AI の画面から、もう一度つなぎ直してください（登録は、使われないと1日で消えます）。' ), 400 );
		}
		$target = $p['redirect_uri'];
		if ( '' === $target && 1 === count( $client['redirect_uris'] ) ) {
			$target = $client['redirect_uris'][0];
		}
		if ( ! OAuth::redirect_matches( $target, $client['redirect_uris'] ) ) {
			return self::page( self::message_html( '戻り先が登録と違います', 'AI の画面から、もう一度つなぎ直してください。' ), 400 );
		}
		// ここからの誤りは、AI（戻り先）に返してよい。
		if ( 'code' !== $p['response_type'] ) {
			return self::back( $target, $p, 'unsupported_response_type', 'response_type は code だけです' );
		}
		if ( '' === $p['code_challenge'] || 'S256' !== $p['code_challenge_method'] ) {
			return self::back( $target, $p, 'invalid_request', 'PKCE（S256）が要ります' );
		}
		if ( '' !== $p['resource'] && ! self::resource_ok( $p['resource'] ) ) {
			return self::back( $target, $p, 'invalid_target', 'この窓口が扱う MCP ではありません' );
		}
		if ( 'POST' !== $request->get_method() ) {
			return self::page( self::form_html( $client, $p, $target, '' ) );
		}
		if ( 'allow' !== (string) $request->get_param( 'decision' ) ) {
			AuditLog::write( 'oauth', 'denied', '', 'authorize: declined by user (' . $client['client_name'] . ')', self::ctx() );
			return self::back( $target, $p, 'access_denied', '許可されませんでした' );
		}
		if ( ! self::rate( 'inv', 10, 15 * MINUTE_IN_SECONDS, false ) ) {
			return self::page( self::form_html( $client, $p, $target, '間違いが続いたので、しばらく受け付けません。15分ほど待ってから、AI の画面でやり直してください。' ), 429 );
		}
		$r = OAuth::consume_invite( (string) $request->get_param( 'invite_code' ), $client );
		if ( is_string( $r ) ) {
			self::rate( 'inv', 10, 15 * MINUTE_IN_SECONDS );
			AuditLog::write( 'oauth', 'denied', 'MCP-002', 'authorize: invite ' . $r . ' (' . $client['client_name'] . ')', self::ctx() );
			$msg = array(
				'bad_format'       => '招待コードの形が違います（「XXXX-XXXX-XXXX-XXXX-XXXX」の20文字です）。',
				'unknown'          => '招待コードが違います。管理者からもらったコードを、そのまま貼ってください。',
				'used'             => 'この招待コードは使用済みです。管理者に新しい招待コードをもらってください。',
				'revoked'          => 'この招待コードは止められています。管理者に確かめてください。',
				'expired'          => 'この招待コードは期限が切れています。管理者に新しい招待コードをもらってください。',
				'user_not_allowed' => 'この招待コードのユーザーは、いま MCP を使えません。管理者に確かめてください。',
			)[ $r ] ?? '招待コードを使えませんでした。';
			return self::page( self::form_html( $client, $p, $target, $msg ), 400 );
		}
		$code = OAuth::new_code(
			$r['key_id'],
			$client['client_id'],
			array(
				'redirect_uri'   => $p['redirect_uri'],
				'code_challenge' => $p['code_challenge'],
				'resource'       => $p['resource'],
			)
		);
		AuditLog::write(
			'oauth',
			'ok',
			'',
			'authorized: ' . $client['client_name'] . ' until ' . ( $r['expires'] > 0 ? gmdate( 'Y-m-d', $r['expires'] ) : 'unlimited' ),
			self::ctx(
				array(
					'key_id'  => $r['key_id'],
					'user_id' => $r['user_id'],
				)
			)
		);
		return self::redirect(
			$target,
			array(
				'code'  => $code,
				'state' => $p['state'],
				'iss'   => self::issuer(),
			)
		);
	}

	public static function route_token( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! self::enabled() ) {
			return self::json( self::e( 'invalid_request', 'このサイトでは OAuth を使っていません' ), 400 );
		}
		OAuth::cleanup();
		$client_id = (string) $request->get_param( 'client_id' );
		if ( '' === $client_id ) {
			$client_id = self::basic_client_id();
		}
		if ( null === OAuth::find_client( $client_id ) ) {
			return self::json( self::e( 'invalid_client', 'AI の登録が見つかりません。つなぎ直してください' ), 401 );
		}
		$grant = (string) $request->get_param( 'grant_type' );
		if ( 'authorization_code' === $grant ) {
			$out = OAuth::exchange_code( (string) $request->get_param( 'code' ), $client_id, (string) $request->get_param( 'redirect_uri' ), (string) $request->get_param( 'code_verifier' ) );
		} elseif ( 'refresh_token' === $grant ) {
			$out = OAuth::refresh( (string) $request->get_param( 'refresh_token' ), $client_id );
		} else {
			$out = self::e( 'unsupported_grant_type', 'authorization_code と refresh_token だけです' );
		}
		if ( isset( $out['error'] ) ) {
			AuditLog::write( 'oauth', 'denied', 'MCP-002', 'token(' . $grant . '): ' . $out['error'] . ' ' . $out['error_description'], self::ctx() );
			return self::json( $out, 400 );
		}
		if ( 'authorization_code' === $grant ) {
			AuditLog::write( 'oauth', 'ok', '', 'token issued', self::ctx() );
		}
		return self::json( $out, 200 );
	}

	public static function route_revoke( \WP_REST_Request $request ): \WP_REST_Response {
		if ( self::enabled() ) {
			OAuth::revoke_token( (string) $request->get_param( 'token' ) );
		}
		return self::json( array(), 200 );
	}

	// ---- MCP の入口の 401 に案内を付ける --------------------------------------------

	/**
	 * @param mixed            $response
	 * @param \WP_REST_Server  $server
	 * @param \WP_REST_Request $request
	 * @return mixed
	 */
	public static function challenge( $response, $server, $request ) {
		unset( $server );
		if ( ! $response instanceof \WP_REST_Response || 401 !== $response->get_status() || ! $request instanceof \WP_REST_Request ) {
			return $response;
		}
		if ( Consts::REST_ROUTE !== untrailingslashit( (string) $request->get_route() ) || ! self::enabled() ) {
			return $response;
		}
		if ( isset( $_SERVER['HTTP_X_SHIP_MCP_TOKEN'] ) || isset( $_SERVER['HTTP_X_SHIP_MCP_SIGNATURE'] ) ) {
			return $response; // トークン・署名でつないでいる AI には、OAuth を始めさせない。
		}
		$value = 'Bearer resource_metadata="' . self::prm_url() . '"';
		if ( TokenAuth::oauth_failed() ) {
			$value .= ', error="invalid_token", error_description="expired or revoked"';
		}
		$response->header( 'WWW-Authenticate', $value );
		return $response;
	}

	// ---- HTML の応答（許可の画面） -------------------------------------------------

	/**
	 * @param bool             $served
	 * @param mixed            $result
	 * @param \WP_REST_Request $request
	 * @param \WP_REST_Server  $server
	 */
	public static function serve_html( $served, $result, $request, $server ): bool {
		unset( $request, $server );
		if ( $served || ! $result instanceof \WP_HTTP_Response ) {
			return (bool) $served;
		}
		$headers = $result->get_headers();
		if ( empty( $headers[ self::HTML_MARK ] ) ) {
			return false;
		}
		if ( ! headers_sent() ) {
			header_remove( self::HTML_MARK );
			header( 'Content-Type: text/html; charset=utf-8' );
			header( 'X-Frame-Options: DENY' );
			header( "Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'none'; base-uri 'none'" );
			header( 'Referrer-Policy: no-referrer' );
			header( 'Cache-Control: no-store' );
			header( 'X-Robots-Tag: noindex, nofollow' );
		}
		echo (string) $result->get_data(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 組み立てるときにエスケープ済み
		return true;
	}

	private static function page( string $html, int $status = 200 ): \WP_REST_Response {
		$r = new \WP_REST_Response( $html, $status );
		$r->header( self::HTML_MARK, '1' );
		return $r;
	}

	private static function shell( string $title, string $body ): string {
		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$css  = 'body{margin:0;background:#f0f0f1;font-family:-apple-system,BlinkMacSystemFont,"Hiragino Sans","Yu Gothic UI","Meiryo",sans-serif;color:#1d2327;line-height:1.7}'
			. '.card{max-width:520px;margin:40px auto;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:28px 28px 20px;box-sizing:border-box}'
			. '@media (max-width:560px){.card{margin:0;border-radius:0;border-left:0;border-right:0;padding:22px 16px}}'
			. '.site{margin:0 0 4px;color:#50575e;font-size:13px}h1{font-size:21px;margin:0 0 16px}'
			. 'dl{margin:0 0 16px;font-size:14px}dt{color:#50575e;font-size:12px;margin-top:8px}dd{margin:0;font-weight:600}.note{font-weight:400;color:#646970;font-size:12px}'
			. '.warn{background:#fcf9e8;border-left:4px solid #dba617;padding:10px 12px;font-size:13px;margin:0 0 16px}'
			. '.err{background:#fcf0f1;border-left:4px solid #d63638;padding:10px 12px;font-size:14px;margin:0 0 16px}'
			. 'label{display:block;font-weight:600;margin:0 0 6px}input[type=text]{width:100%;box-sizing:border-box;font:600 18px/1.4 ui-monospace,Menlo,Consolas,monospace;letter-spacing:.06em;padding:10px;border:1px solid #8c8f94;border-radius:4px}'
			. '.btns{display:flex;gap:10px;margin:16px 0 8px}button{font-size:15px;padding:10px 18px;border-radius:4px;border:1px solid #2271b1;background:#fff;color:#2271b1;cursor:pointer}button.primary{background:#2271b1;color:#fff;flex:1}'
			. '.small{font-size:12px;color:#646970;margin:8px 0 0}';
		return '<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . esc_html( $title . '｜' . $site ) . '</title><style>' . $css . '</style></head><body><main class="card"><p class="site">' . esc_html( $site ) . '</p>' . $body . '</main></body></html>';
	}

	private static function message_html( string $title, string $text ): string {
		return self::shell( $title, '<h1>' . esc_html( $title ) . '</h1><p>' . esc_html( $text ) . '</p>' );
	}

	/**
	 * @param array{client_name:string} $client
	 * @param array<string,string>      $p
	 */
	private static function form_html( array $client, array $p, string $target, string $error ): string {
		$scheme = strtolower( (string) strtok( $target, ':' ) );
		if ( OAuth::is_loopback( $target ) ) {
			$where = 'このパソコンの中で動いている AI';
		} elseif ( 'https' === $scheme ) {
			$where = 'インターネット上のサービス（' . (string) wp_parse_url( $target, PHP_URL_HOST ) . '）';
		} else {
			$where = 'このパソコンのアプリ（' . $scheme . '://）';
		}
		$hidden = '';
		foreach ( $p as $k => $v ) {
			$hidden .= '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
		}
		$body = '<h1>AI からの接続を許可しますか？</h1>'
			. '<dl><dt>つなごうとしている AI</dt><dd>' . esc_html( $client['client_name'] ) . ' <span class="note">（AI が名乗った名前）</span></dd>'
			. '<dt>許可したあとの戻り先</dt><dd>' . esc_html( $where ) . '</dd>'
			. '<dt>できること</dt><dd>このサイトの下書きの作成・修正（公開・削除はできません）。期限は、管理者が招待コードごとに決めた期間です。</dd></dl>'
			. '<div class="warn"><strong>自分の AI に「つないで」と頼んで、この画面が開いたときだけ</strong>許可してください。人から送られてきたリンクで開いた場合は、許可しないでください。</div>'
			. ( '' !== $error ? '<div class="err" role="alert">' . esc_html( $error ) . '</div>' : '' )
			. '<form method="post" autocomplete="off">' . $hidden
			. '<label for="scm-invite">管理者からもらった招待コード</label>'
			. '<input type="text" id="scm-invite" name="invite_code" autocapitalize="characters" autocomplete="off" spellcheck="false" placeholder="XXXX-XXXX-XXXX-XXXX-XXXX" required>'
			. '<div class="btns"><button type="submit" name="decision" value="allow" class="primary">許可する</button><button type="submit" name="decision" value="deny" formnovalidate>やめる</button></div>'
			. '</form><p class="small">許可すると、AI の画面に戻ります。招待コードは1回だけ使えます。</p>';
		return self::shell( 'AI からの接続の許可', $body );
	}

	// ---- 内部 -----------------------------------------------------------------

	private static function resource_ok( string $resource ): bool {
		$path = untrailingslashit( (string) wp_parse_url( $resource, PHP_URL_PATH ) );
		$mine = untrailingslashit( (string) wp_parse_url( self::resource(), PHP_URL_PATH ) );
		return '' === $path || $mine === $path;
	}

	/**
	 * @param array<string,string> $p
	 */
	private static function back( string $target, array $p, string $error, string $desc ): \WP_REST_Response {
		return self::redirect(
			$target,
			array(
				'error'             => $error,
				'error_description' => $desc,
				'state'             => $p['state'],
				'iss'               => self::issuer(),
			)
		);
	}

	/**
	 * @param array<string,string> $params
	 */
	private static function redirect( string $uri, array $params ): \WP_REST_Response {
		$params = array_filter( $params, static fn( $v ) => '' !== (string) $v );
		$to     = $uri . ( str_contains( $uri, '?' ) ? '&' : '?' ) . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
		$r      = new \WP_REST_Response( null, 302 );
		$r->header( 'Location', $to );
		$r->header( 'Cache-Control', 'no-store' );
		return $r;
	}

	/**
	 * @param array<string,mixed> $data
	 */
	private static function json( array $data, int $status ): \WP_REST_Response {
		$r = new \WP_REST_Response( array() === $data ? new \stdClass() : $data, $status );
		$r->header( 'Cache-Control', 'no-store' );
		$r->header( 'Pragma', 'no-cache' );
		return $r;
	}

	/**
	 * @return array{error:string,error_description:string}
	 */
	private static function e( string $code, string $desc ): array {
		return array(
			'error'             => $code,
			'error_description' => $desc,
		);
	}

	/** client_secret_basic の形で client_id だけを送ってくる AI のため（秘密は使わない）。 */
	private static function basic_client_id(): string {
		$auth = isset( $_SERVER['HTTP_AUTHORIZATION'] ) && is_string( $_SERVER['HTTP_AUTHORIZATION'] ) ? (string) $_SERVER['HTTP_AUTHORIZATION'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- 形を下で確かめる
		if ( 1 !== preg_match( '/^Basic\s+([A-Za-z0-9+\/=]+)$/', trim( $auth ), $m ) ) {
			return '';
		}
		$pair = (string) base64_decode( $m[1], true );
		$id   = rawurldecode( (string) strtok( $pair, ':' ) );
		return 1 === preg_match( '/^c[0-9a-f]{24}$/', $id ) ? $id : '';
	}

	private static function rate( string $what, int $max, int $window, bool $count = true ): bool {
		$key = 'ship_content_mcp_rl_' . $what . '_' . md5( IpRules::remote_addr() );
		$n   = (int) get_transient( $key );
		if ( $n >= $max ) {
			return false;
		}
		if ( $count ) {
			set_transient( $key, $n + 1, $window );
		}
		return true;
	}

	/**
	 * @param array<string,mixed> $extra
	 * @return array<string,mixed>
	 */
	private static function ctx( array $extra = array() ): array {
		return $extra + array(
			'user_id' => 0,
			'auth'    => 'oauth',
			'key_id'  => '',
		);
	}
}
