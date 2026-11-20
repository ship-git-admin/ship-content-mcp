<?php
/**
 * 端末ごとの署名による認証（パスワードを通信に乗せない。HTTP の動作確認URLでも使える）。
 *
 * - MCP の入口の URL で、専用ヘッダー（X-Ship-MCP-*）があるときだけ働く。ほかの URL では何もしない。
 * - 確かめる順：形 → 時刻（±5分）→ 鍵（有効・ユーザーあり）→ 署名 → 使い捨て番号（使い回し禁止）
 * - 成功したら、その鍵のユーザーとして扱う（コアの Application Password の確認より先に済ませる：優先度 15）。
 * - 失敗したら、REST の認証エラー（401）にする。匿名のまま進めない。
 * - 応答には、サイトの鍵で署名を付ける（中継プログラムが確かめる）。
 * - Authorization ヘッダーは使わない（サーバーの Basic 認証とぶつからない）。
 *
 * @package ShipContentMcp
 */

namespace ShipContentMcp\Security;

use ShipContentMcp\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class SignatureAuth {

	/** @var array{user_id?:int,error?:string}|null */
	private static ?array $state = null;

	public static function early_hooks(): void {
		add_filter( 'determine_current_user', array( self::class, 'determine_current_user' ), 15 );
	}

	public static function hooks(): void {
		add_filter( 'rest_authentication_errors', array( self::class, 'rest_authentication_errors' ), 5 );
		add_filter( 'rest_pre_serve_request', array( self::class, 'sign_response' ), 999, 4 );
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
		if ( ! self::has_signature_headers() || ! RequestContext::is_mcp_url() ) {
			return $user_id;
		}
		$nonce = self::header( SignatureCodec::H_NONCE );
		if ( SignatureCodec::valid_nonce( $nonce ) ) {
			RequestContext::set_nonce( $nonce );
		}
		$settings = Settings::get();
		if ( ! $settings['enabled'] || ! $settings['auth_signature'] ) {
			self::$state = array( 'error' => 'signature_disabled' );
			AuditLog::write(
				'auth',
				'denied',
				'MCP-002',
				'signature auth disabled',
				array(
					'user_id' => 0,
					'auth'    => 'signature',
				)
			);
			return $user_id;
		}
		$result = self::verify();
		if ( is_string( $result ) ) {
			self::$state = array( 'error' => $result );
			AuditLog::write(
				'auth',
				'denied',
				'MCP-002',
				'signature: ' . $result,
				array(
					'key_id'  => substr( preg_replace( '/[^a-z0-9]/', '', self::header( SignatureCodec::H_KEY ) ), 0, 40 ),
					'user_id' => 0,
					'auth'    => 'signature',
				)
			);
			return $user_id;
		}
		self::$state = array( 'user_id' => $result );
		return $result;
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
			return new \WP_Error( 'ship_content_mcp_auth', '認証に失敗しました（MCP-002）', array( 'status' => 401 ) );
		}
		// 成功：ほかの認証の確認（Cookie の nonce など）で消されないようにする。
		return true;
	}

	/**
	 * MCP の入口の応答に、サイトの鍵で署名を付けて送る。
	 *
	 * @param bool              $served
	 * @param \WP_HTTP_Response $result
	 * @param \WP_REST_Request  $request
	 * @param \WP_REST_Server   $server
	 */
	public static function sign_response( $served, $result, $request, $server ): bool {
		if ( $served || ! ( RequestContext::in_mcp_dispatch() || RequestContext::is_mcp_url() ) ) {
			return (bool) $served;
		}
		$nonce = RequestContext::nonce();
		if ( '' === $nonce ) {
			$nonce = self::header( SignatureCodec::H_NONCE );
			if ( ! SignatureCodec::valid_nonce( $nonce ) ) {
				return false;
			}
		}
		$secret = SiteKey::secret_raw();
		if ( null === $secret || 'HEAD' === $request->get_method() ) {
			return false;
		}
		$data   = $server->response_to_data( $result, false );
		$data   = apply_filters( 'rest_pre_echo_response', $data, $server, $request );
		$status = (int) $result->get_status();
		$body   = '';
		if ( 204 !== $status && null !== $data ) {
			$encoded = wp_json_encode( $data );
			if ( false === $encoded ) {
				return false;
			}
			$body = $encoded;
		}
		$session = '';
		foreach ( (array) $result->get_headers() as $name => $value ) {
			if ( 0 === strcasecmp( (string) $name, 'Mcp-Session-Id' ) ) {
				$session = (string) $value;
			}
		}
		$signature = SignatureCodec::sign( SignatureCodec::canonical_response( $nonce, $status, $session, $body ), $secret );
		header( SignatureCodec::H_RES_SIG . ': ' . $signature );
		header( SignatureCodec::H_RES_KEY . ': ' . SiteKey::fingerprint() );
		if ( '' !== $body ) {
			echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON の応答
		}
		return true;
	}

	/**
	 * @return int|string ユーザー ID、または失敗の理由
	 */
	private static function verify() {
		$key_id    = self::header( SignatureCodec::H_KEY );
		$timestamp = self::header( SignatureCodec::H_TIMESTAMP );
		$nonce     = self::header( SignatureCodec::H_NONCE );
		$sig_raw   = SignatureCodec::decode_signature( self::header( SignatureCodec::H_SIGNATURE ) );
		$session   = self::header( 'Mcp-Session-Id' );
		if ( ! SignatureCodec::valid_key_id( $key_id ) || ! SignatureCodec::valid_timestamp( $timestamp ) || ! SignatureCodec::valid_nonce( $nonce ) || null === $sig_raw || ! SignatureCodec::valid_session_id( $session ) ) {
			return 'bad_format';
		}
		if ( ! SignatureCodec::fresh( $timestamp, time() ) ) {
			return 'clock_skew';
		}
		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			return 'no_sodium';
		}
		$row = DeviceKeys::find( $key_id );
		if ( null === $row ) {
			return 'unknown_key';
		}
		if ( ! empty( $row['revoked_at'] ) ) {
			return 'revoked';
		}
		$user_id = (int) $row['user_id'];
		if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
			return 'user_missing';
		}
		$pk = SignatureCodec::decode_public_key( (string) $row['public_key'] );
		if ( null === $pk ) {
			return 'bad_key';
		}
		$body    = (string) file_get_contents( 'php://input' );
		$method  = isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$host    = isset( $_SERVER['HTTP_HOST'] ) ? (string) $_SERVER['HTTP_HOST'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$uri     = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$message = SignatureCodec::canonical_request( $method, $host, $uri, $timestamp, $nonce, $key_id, $session, $body );
		if ( ! SignatureCodec::verify( $sig_raw, $message, $pk ) ) {
			return 'bad_signature';
		}
		if ( ! NonceStore::claim( $key_id, $nonce ) ) {
			return 'replay';
		}
		DeviceKeys::touch( $key_id );
		RequestContext::set_signature( $key_id, $nonce );
		return $user_id;
	}

	private static function has_signature_headers(): bool {
		foreach ( array( SignatureCodec::H_KEY, SignatureCodec::H_TIMESTAMP, SignatureCodec::H_NONCE, SignatureCodec::H_SIGNATURE ) as $h ) {
			if ( '' !== self::header( $h ) ) {
				return true;
			}
		}
		return false;
	}

	private static function header( string $name ): string {
		$key = 'HTTP_' . strtoupper( str_replace( '-', '_', $name ) );
		return isset( $_SERVER[ $key ] ) && is_string( $_SERVER[ $key ] ) ? trim( $_SERVER[ $key ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- 形は呼び出し側で確かめる
	}

	/** 自己テスト用。 */
	public static function reset_for_tests(): void {
		self::$state = null;
	}
}
