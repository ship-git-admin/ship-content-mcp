<?php
/**
 * 自作の MCP サーバー（公式 MCP Adapter の create_server）。
 *
 * - 入口：/wp-json/ship-content/mcp（HTTP、Streamable HTTP の POST / DELETE）
 * - 載せるのは Abilities::TOOLS だけ。リソース・プロンプトはなし。
 * - 接続の権限：AccessGate::transport（有効・IP・認証の方法・専用の権限・低権限）
 * - MCP が無効のときは、サーバーを作らない（入口がない）。
 *
 * @package ShipContentMcp
 */

namespace ShipContentMcp\MCP;

use ShipContentMcp\Core\Consts;
use ShipContentMcp\Core\Settings;
use ShipContentMcp\Security\AccessGate;

defined( 'ABSPATH' ) || exit;

final class Server {

	private static string $last_error = '';

	/**
	 * @param object $adapter \WP\MCP\Core\McpAdapter
	 */
	public static function register( $adapter ): void {
		if ( ! Settings::enabled() ) {
			return;
		}
		$transport = '\\WP\\MCP\\Transport\\HttpTransport';
		$errors    = '\\WP\\MCP\\Infrastructure\\ErrorHandling\\NullMcpErrorHandler';
		$observe   = '\\WP\\MCP\\Infrastructure\\Observability\\NullMcpObservabilityHandler';
		if ( ! class_exists( $transport ) || ! method_exists( $adapter, 'create_server' ) ) {
			self::$last_error = 'adapter api not found';
			return;
		}
		try {
			$adapter->create_server(
				Consts::SERVER_ID,
				Consts::NS,
				Consts::ROUTE,
				'Ship Content MCP',
				'許可された投稿タイプの下書きを作る・直すためのツール（読み取り・検証・下書きの保存のみ。公開・予約・削除はできません）。',
				SHIP_CONTENT_MCP_VERSION,
				array( $transport ),
				class_exists( $errors ) ? $errors : null,
				class_exists( $observe ) ? $observe : null,
				Abilities::TOOLS,
				array(),
				array(),
				array( AccessGate::class, 'transport' )
			);
		} catch ( \Throwable $e ) {
			self::$last_error = get_class( $e ) . ': ' . mb_substr( $e->getMessage(), 0, 200 );
		}
	}

	public static function last_error(): string {
		return self::$last_error;
	}

	public static function endpoint(): string {
		return rest_url( ltrim( Consts::REST_ROUTE, '/' ) );
	}
}
