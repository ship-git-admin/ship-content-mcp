<?php
/**
 * 起動（MCP Adapter や Abilities API がなくても、致命的なエラーを出さない）。
 *
 * @package ShipContentMcp
 */

namespace ShipContentMcp\Core;

use ShipContentMcp\Admin\SettingsPage;
use ShipContentMcp\MCP\Abilities;
use ShipContentMcp\MCP\Server;
use ShipContentMcp\Security\RequestContext;
use ShipContentMcp\Security\SignatureAuth;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	public static function boot(): void {
		Installer::maybe_upgrade();

		RequestContext::hooks();
		SignatureAuth::hooks();

		if ( function_exists( 'wp_register_ability' ) ) {
			add_action( 'wp_abilities_api_categories_init', array( Abilities::class, 'register_category' ) );
			add_action( 'wp_abilities_api_init', array( Abilities::class, 'register' ) );
		}

		if ( Settings::get()['disable_default_server'] ) {
			add_filter( 'mcp_adapter_create_default_server', '__return_false', 20 );
		}
		add_action( 'mcp_adapter_init', array( Server::class, 'register' ) );

		// 更新の確認（GitHub の公開リリース）。plugins_loaded の中で作る（PUC の推奨）。
		\ShipContentMcp\Release\Updater::register();

		if ( is_admin() ) {
			SettingsPage::hooks();
			\ShipContentMcp\Admin\DesktopExtension::hooks();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'ship-content-mcp', \ShipContentMcp\Cli\Command::class );
		}
	}

	/**
	 * 依存の状況（診断・管理画面の案内に使う）。
	 *
	 * @return array<string,mixed>
	 */
	public static function dependencies(): array {
		$adapter_version = '';
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( get_plugins() as $file => $data ) {
			if ( 'mcp-adapter' === dirname( $file ) ) {
				$adapter_version = (string) ( $data['Version'] ?? '' );
			}
		}
		return array(
			'abilities_api'   => function_exists( 'wp_register_ability' ),
			'adapter_active'  => class_exists( '\\WP\\MCP\\Core\\McpAdapter' ),
			'adapter_version' => $adapter_version,
			'acf_active'      => function_exists( 'acf_get_field_groups' ),
			'acf_version'     => defined( 'ACF_VERSION' ) ? (string) ACF_VERSION : '',
			'sodium'          => function_exists( 'sodium_crypto_sign_verify_detached' ),
		);
	}
}
