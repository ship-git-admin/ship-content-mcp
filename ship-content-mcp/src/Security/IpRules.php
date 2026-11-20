<?php
/**
 * 接続元の IP 制限（REMOTE_ADDR だけを見る。X-Forwarded-For などは信用しない）。
 *
 * @package ShipContentMcp
 */

namespace ShipContentMcp\Security;

defined( 'ABSPATH' ) || exit;

final class IpRules {

	public static function is_valid_entry( string $entry ): bool {
		if ( false !== filter_var( $entry, FILTER_VALIDATE_IP ) ) {
			return true;
		}
		if ( ! str_contains( $entry, '/' ) ) {
			return false;
		}
		[ $ip, $bits ] = explode( '/', $entry, 2 );
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) || ! ctype_digit( $bits ) ) {
			return false;
		}
		$max = str_contains( $ip, ':' ) ? 128 : 32;
		return (int) $bits >= 0 && (int) $bits <= $max;
	}

	/**
	 * @param list<string> $allowlist 空なら制限しない
	 */
	public static function allowed( string $ip, array $allowlist ): bool {
		if ( array() === $allowlist ) {
			return true;
		}
		$packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- 不正な値は false で扱う
		if ( false === $packed ) {
			return false;
		}
		foreach ( $allowlist as $entry ) {
			if ( self::match( $packed, $entry ) ) {
				return true;
			}
		}
		return false;
	}

	public static function remote_addr(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- 検証は下で行う
		return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	private static function match( string $packed, string $entry ): bool {
		if ( ! str_contains( $entry, '/' ) ) {
			$other = @inet_pton( $entry ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return false !== $other && $other === $packed;
		}
		[ $net, $bits ] = explode( '/', $entry, 2 );
		$net_packed     = @inet_pton( $net ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $net_packed || strlen( $net_packed ) !== strlen( $packed ) ) {
			return false;
		}
		$bits  = (int) $bits;
		$bytes = intdiv( $bits, 8 );
		if ( 0 !== $bytes && substr( $packed, 0, $bytes ) !== substr( $net_packed, 0, $bytes ) ) {
			return false;
		}
		$rest = $bits % 8;
		if ( 0 === $rest ) {
			return true;
		}
		$mask = chr( ( 0xFF << ( 8 - $rest ) ) & 0xFF );
		return ( $packed[ $bytes ] & $mask ) === ( $net_packed[ $bytes ] & $mask );
	}
}
