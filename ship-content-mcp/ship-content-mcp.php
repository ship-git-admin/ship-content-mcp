<?php
/**
 * Plugin Name:       Ship Content MCP
 * Description:       許可した投稿タイプの下書きを、社内 PM の AI エージェントから MCP で作成・修正するためのプラグインです。WordPress 公式の MCP Adapter（別プラグイン）が必要です。WordPress 側では AI を呼びません。
 * Version:           0.9.14
 * Requires at least: 6.9
 * Requires PHP:      8.1
 * Author:            Ship Inc.
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ship-content-mcp
 * Update URI:        https://github.com/ship-git-admin/ship-content-mcp
 *
 * @package ShipContentMcp
 */

// このファイルは古い PHP でも読めるように、新しい書き方を使わない。

defined( 'ABSPATH' ) || exit;

define( 'SHIP_CONTENT_MCP_VERSION', '0.9.14' );
define( 'SHIP_CONTENT_MCP_FILE', __FILE__ );
define( 'SHIP_CONTENT_MCP_DIR', __DIR__ );

if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>Ship Content MCP には PHP 8.1 以上が必要です。プラグインは動いていません。</p></div>';
		}
	);
	return;
}

spl_autoload_register(
	function ( $class_name ) {
		$prefix = 'ShipContentMcp\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$relative = substr( $class_name, strlen( $prefix ) );
		$path     = __DIR__ . '/src/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);

register_activation_hook( __FILE__, array( 'ShipContentMcp\\Core\\Installer', 'activate' ) );

// 署名の認証は、ほかのコードが現在のユーザーを決める前に付けておく（plugins_loaded では遅いことがある）。
ShipContentMcp\Security\TokenAuth::early_hooks();
ShipContentMcp\Security\SignatureAuth::early_hooks();

add_action( 'plugins_loaded', array( 'ShipContentMcp\\Core\\Plugin', 'boot' ) );
