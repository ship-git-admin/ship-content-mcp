<?php
/**
 * WordPress なしで試験できる部品だけを読む（署名の形・IP の判定・接続コードの形）。
 */

define( 'ABSPATH', __DIR__ . '/' );
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) { // phpcs:ignore -- 試験用の代わり
		return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
	}
}
require __DIR__ . '/../../ship-content-mcp/src/Security/SignatureCodec.php';
require __DIR__ . '/../../ship-content-mcp/src/Security/IpRules.php';
require __DIR__ . '/../../ship-content-mcp/src/Security/ConnectionCode.php';
require __DIR__ . '/../../ship-content-mcp/src/Admin/DesktopExtension.php';
require __DIR__ . '/../../ship-content-mcp/src/Security/DeviceKeys.php';
require __DIR__ . '/../../ship-content-mcp/src/Security/OAuth.php';
