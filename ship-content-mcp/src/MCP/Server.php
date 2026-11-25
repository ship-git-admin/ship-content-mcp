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

	/**
	 * サーバーの案内（initialize の instructions）。
	 *
	 * AI のアプリは、これを会話の初めに入れたり（Claude Code）、どのツールの説明にも付けたり（Codex）する。
	 * ツールが最初は見えず、AI が名前や説明で探す形のアプリもあるので、全部のツールの名前と順番・写真の登録の仕方を、英語の言葉も入れて短く書く（0.9.5）。
	 */
	public const INSTRUCTIONS = 'WordPress の下書きを作る・直すツール（Ship Content MCP。読み取り・検証・下書きの保存・写真の登録だけ。公開・予約・削除はできません）。順番：list-post-types → get-creation-policy → get-acf-schema →（search-posts・get-post）→ validate-draft → create-draft か update-draft。写真・画像（photo / image upload to the media library）は1枚ずつ登録する：写真の URL があれば import-image-url、手元のファイルは get-upload-url（返ってきた curl を実行）、コマンドでネットワークにつなげないときは prepare-upload → upload-chunk → finish-upload（base64 は自分で書き写さず、コマンドの出力をそのまま渡す）。返った attachment_id を画像の欄や featured_media に入れる。WordPress の管理画面・ログイン・ブラウザで代わりをしないでください。';

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
				self::INSTRUCTIONS,
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
