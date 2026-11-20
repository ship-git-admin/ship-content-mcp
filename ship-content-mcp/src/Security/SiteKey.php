<?php
/**
 * サイトの鍵（応答の署名用、Ed25519）。
 *
 * - 公開鍵は管理画面に表示し、PM の中継プログラムに登録してもらう（応答が本当にこのサイトから来たかを確かめる）。
 * - 秘密鍵は DB のオプションに置く（自動読み込みしない）。管理画面にも記録にも出さない。
 *
 * @package ShipContentMcp
 */

namespace ShipContentMcp\Security;

defined( 'ABSPATH' ) || exit;

final class SiteKey {

	public const OPTION = 'ship_content_mcp_site_key';

	public static function ensure(): void {
		$stored = get_option( self::OPTION );
		if ( is_array( $stored ) && null !== self::decode( $stored, 'secret', SODIUM_CRYPTO_SIGN_SECRETKEYBYTES ) ) {
			return;
		}
		self::create();
	}

	public static function rotate(): void {
		self::create();
	}

	public static function public_b64(): string {
		$stored = get_option( self::OPTION );
		$raw    = is_array( $stored ) ? self::decode( $stored, 'public', SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ) : null;
		return null === $raw ? '' : base64_encode( $raw );
	}

	public static function fingerprint(): string {
		$b64 = self::public_b64();
		return '' === $b64 ? '' : SignatureCodec::fingerprint( (string) base64_decode( $b64, true ) );
	}

	public static function secret_raw(): ?string {
		if ( ! function_exists( 'sodium_crypto_sign_detached' ) ) {
			return null;
		}
		$stored = get_option( self::OPTION );
		return is_array( $stored ) ? self::decode( $stored, 'secret', SODIUM_CRYPTO_SIGN_SECRETKEYBYTES ) : null;
	}

	private static function create(): void {
		$pair = sodium_crypto_sign_keypair();
		update_option(
			self::OPTION,
			array(
				'public'  => base64_encode( sodium_crypto_sign_publickey( $pair ) ),
				'secret'  => base64_encode( sodium_crypto_sign_secretkey( $pair ) ),
				'created' => time(),
			),
			false
		);
	}

	/**
	 * @param array<string,mixed> $stored
	 */
	private static function decode( array $stored, string $field, int $len ): ?string {
		$raw = base64_decode( (string) ( $stored[ $field ] ?? '' ), true );
		return ( false !== $raw && strlen( $raw ) === $len ) ? $raw : null;
	}
}
