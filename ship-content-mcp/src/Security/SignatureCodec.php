<?php
/**
 * 署名の形（WordPress に依存しない。中継プログラム bridge/ と同じ決まり。docs/AUTH_SIGNATURE.md）。
 *
 * リクエスト（PM の端末の秘密鍵で署名、Ed25519）：
 *   ship-content-mcp-req-v1 \n METHOD \n host \n request-uri \n timestamp \n nonce \n key-id \n mcp-session-id \n sha256hex(body)
 * 応答（サイトの秘密鍵で署名、Ed25519）：
 *   ship-content-mcp-res-v1 \n request-nonce \n status \n mcp-session-id \n sha256hex(body)
 *
 * @package ShipContentMcp
 */

namespace ShipContentMcp\Security;

defined( 'ABSPATH' ) || exit;

final class SignatureCodec {

	public const REQ_PREFIX = 'ship-content-mcp-req-v1';
	public const RES_PREFIX = 'ship-content-mcp-res-v1';

	/** 時刻のずれの許容（秒）。 */
	public const MAX_SKEW = 300;

	public const H_KEY       = 'X-Ship-MCP-Key';
	public const H_TIMESTAMP = 'X-Ship-MCP-Timestamp';
	public const H_NONCE     = 'X-Ship-MCP-Nonce';
	public const H_SIGNATURE = 'X-Ship-MCP-Signature';
	public const H_RES_SIG   = 'X-Ship-MCP-Response-Signature';
	public const H_RES_KEY   = 'X-Ship-MCP-Server-Key';

	public static function canonical_request( string $method, string $host, string $uri, string $timestamp, string $nonce, string $key_id, string $session_id, string $body ): string {
		return implode(
			"\n",
			array(
				self::REQ_PREFIX,
				strtoupper( $method ),
				strtolower( $host ),
				$uri,
				$timestamp,
				$nonce,
				$key_id,
				$session_id,
				hash( 'sha256', $body ),
			)
		);
	}

	public static function canonical_response( string $nonce, int $status, string $session_id, string $body ): string {
		return implode(
			"\n",
			array(
				self::RES_PREFIX,
				$nonce,
				(string) $status,
				$session_id,
				hash( 'sha256', $body ),
			)
		);
	}

	public static function valid_key_id( string $v ): bool {
		return 1 === preg_match( '/^k[0-9a-f]{16}$/', $v );
	}

	public static function valid_timestamp( string $v ): bool {
		return 1 === preg_match( '/^[0-9]{10}$/', $v );
	}

	public static function valid_nonce( string $v ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9_-]{22,64}$/', $v );
	}

	public static function valid_session_id( string $v ): bool {
		return '' === $v || 1 === preg_match( '/^[A-Za-z0-9._:-]{1,128}$/', $v );
	}

	/**
	 * 公開鍵（生の32バイトを base64 にしたもの）を確かめて、32バイトを返す。
	 */
	public static function decode_public_key( string $b64 ): ?string {
		$raw = base64_decode( trim( $b64 ), true );
		return ( false !== $raw && SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES === strlen( $raw ) ) ? $raw : null;
	}

	public static function decode_signature( string $b64 ): ?string {
		$raw = base64_decode( trim( $b64 ), true );
		return ( false !== $raw && SODIUM_CRYPTO_SIGN_BYTES === strlen( $raw ) ) ? $raw : null;
	}

	public static function verify( string $signature_raw, string $message, string $public_key_raw ): bool {
		try {
			return sodium_crypto_sign_verify_detached( $signature_raw, $message, $public_key_raw );
		} catch ( \SodiumException $e ) {
			return false;
		}
	}

	public static function sign( string $message, string $secret_key_raw ): string {
		return base64_encode( sodium_crypto_sign_detached( $message, $secret_key_raw ) );
	}

	/** 鍵の指紋（表示用。sha256 の先頭16文字）。 */
	public static function fingerprint( string $public_key_raw ): string {
		return substr( hash( 'sha256', $public_key_raw ), 0, 16 );
	}

	/** 時刻のずれが許容内か。 */
	public static function fresh( string $timestamp, int $now ): bool {
		return abs( $now - (int) $timestamp ) <= self::MAX_SKEW;
	}
}
