<?php
/**
 * Claude デスクトップアプリ用の拡張機能（.mcpb）を、このサイト専用に作ってダウンロードさせる。
 *
 * - 中身は manifest.json と中継プログラム（assets/bridge/ship-content-mcp-bridge.mjs）だけ。トークンは入れない（PM が設定の欄に貼る）。
 * - 入口の URL（と、公開前のサイトなら Basic 認証）は、ダウンロードのときに入れておく。PM はトークンを貼るだけ。
 * - 名前をサイトごとに変える（ship-content-<短い名前>）ので、1人の PM が複数のサイトの拡張機能を並べて入れられる。
 * - 形式は MCPB（manifest_version 0.3、server.type node）。Claude デスクトップは Node.js を同梱しているので、PM は何も入れなくてよい。
 *
 * @package ShipContentMcp
 */

namespace ShipContentMcp\Admin;

use ShipContentMcp\MCP\Server;
use ShipContentMcp\Security\AuditLog;
use ShipContentMcp\Security\ConnectionCode;

defined( 'ABSPATH' ) || exit;

final class DesktopExtension {

	public const ACTION = 'ship_content_mcp_download_mcpb';
	public const BRIDGE = '/assets/bridge/ship-content-mcp-bridge.mjs';

	public static function hooks(): void {
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle_download' ) );
	}

	public static function available(): bool {
		return class_exists( '\ZipArchive' ) && is_readable( SHIP_CONTENT_MCP_DIR . self::BRIDGE );
	}

	/**
	 * manifest.json の中身（WordPress に依存しない。試験からも使う）。
	 *
	 * @return array<string,mixed>
	 */
	public static function manifest( string $name, string $site, string $url, string $basic_auth, string $version ): array {
		$label = '' === trim( $site ) ? $name : $site;
		$env   = array(
			'SHIP_MCP_URL'         => $url,
			'SHIP_MCP_TOKEN'       => '${user_config.token}',
			'SHIP_MCP_UPLOAD_DIRS' => '${user_config.photo_dir}',
		);
		if ( '' !== $basic_auth ) {
			$env['SHIP_MCP_BASIC_AUTH'] = $basic_auth;
		}
		return array(
			'manifest_version' => '0.3',
			'name'             => 'ship-content-' . $name,
			'display_name'     => 'Ship Content MCP（' . $label . '）',
			'version'          => $version,
			'description'      => $label . ' の WordPress に、記事の下書きを作る（管理者から届いたトークンでつなぐ。公開・削除はできない）',
			'author'           => array( 'name' => 'Ship Inc.' ),
			'homepage'         => 'https://github.com/ship-git-admin/ship-content-mcp',
			'repository'       => array(
				'type' => 'git',
				'url'  => 'https://github.com/ship-git-admin/ship-content-mcp',
			),
			'license'          => 'GPL-2.0-or-later',
			'server'           => array(
				'type'        => 'node',
				'entry_point' => 'server/ship-content-mcp-bridge.mjs',
				'mcp_config'  => array(
					'command' => 'node',
					'args'    => array( '${__dirname}/server/ship-content-mcp-bridge.mjs' ),
					'env'     => $env,
				),
			),
			'user_config'      => array(
				'token'     => array(
					'type'        => 'string',
					'title'       => 'トークン',
					'description' => '管理者から届いた「smcp_」で始まるトークンを貼ります。合鍵と同じなので、人に渡さないでください。',
					'sensitive'   => true,
					'required'    => true,
				),
				'photo_dir' => array(
					'type'        => 'directory',
					'title'       => '写真のフォルダ',
					'description' => '写真を送ってよいフォルダ。決めなければホームフォルダの中（隠しフォルダ以外）から送れます。',
					'required'    => false,
					'default'     => '${HOME}',
				),
			),
			'tools_generated'  => true,
			'compatibility'    => array(
				'platforms' => array( 'darwin', 'win32' ),
				'runtimes'  => array( 'node' => '>=18.0.0' ),
			),
		);
	}

	/**
	 * .mcpb（ZIP）を作る。
	 *
	 * @param array<string,mixed> $manifest
	 * @throws \RuntimeException 作れないとき
	 */
	public static function build( string $out, array $manifest, string $bridge_file ): void {
		$bridge = @file_get_contents( $bridge_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents,WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $bridge || '' === $bridge ) {
			throw new \RuntimeException( '中継プログラムのファイルがありません（プラグインを入れ直してください）' );
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $out, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			throw new \RuntimeException( '拡張機能のファイルを作れませんでした' );
		}
		$zip->addFromString( 'manifest.json', (string) json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );
		$zip->addFromString( 'server/ship-content-mcp-bridge.mjs', $bridge );
		$zip->close();
	}

	/** このサイト専用の拡張機能を作るための値（入口の URL から短い名前、サイト名、確かめた入口）。 */
	public static function for_url( string $url ): array {
		$endpoint = Server::endpoint();
		$norm     = ConnectionCode::normalize_url( '' === trim( $url ) ? $endpoint : $url, $endpoint );
		if ( '' === $norm ) {
			$norm = $endpoint;
		}
		return array(
			'name' => ConnectionCode::default_name( $norm ),
			'site' => mb_substr( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ), 0, 100 ),
			'url'  => $norm,
		);
	}

	/** ダウンロードのフォーム（入口の URL と Basic 認証は POST で送る。URL に Basic 認証を載せない）。 */
	public static function form( string $url, string $basic_auth, string $button ): string {
		ob_start();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		wp_nonce_field( self::ACTION, '_wpnonce', false );
		echo '<input type="hidden" name="url" value="' . esc_attr( $url ) . '"><input type="hidden" name="basic_auth" value="' . esc_attr( $basic_auth ) . '">';
		echo '<button type="submit" class="button">' . esc_html( $button ) . '</button></form>';
		return (string) ob_get_clean();
	}

	public static function handle_download(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'この操作をする権限がありません。' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );
		if ( ! self::available() ) {
			wp_die( esc_html__( 'このサーバーでは拡張機能のファイルを作れません（PHP の ZipArchive がない、または中継プログラムのファイルがありません）。' ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- check_admin_referer で確認済み
		$url   = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ), array( 'http', 'https' ) ) : '';
		$basic = isset( $_POST['basic_auth'] ) ? trim( (string) wp_unslash( $_POST['basic_auth'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- パスワードは文字を変えない（形は下で確かめる）
		// phpcs:enable
		if ( ! ConnectionCode::valid_basic_auth( $basic ) ) {
			wp_die( esc_html__( 'Basic 認証は「ID:パスワード」の形で入れてください。' ) );
		}
		$v   = self::for_url( $url );
		$tmp = wp_tempnam( 'ship-content-mcp.mcpb' );
		try {
			self::build( $tmp, self::manifest( $v['name'], $v['site'], $v['url'], $basic, SHIP_CONTENT_MCP_VERSION ), SHIP_CONTENT_MCP_DIR . self::BRIDGE );
		} catch ( \RuntimeException $e ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
			wp_die( esc_html( $e->getMessage() ) );
		}
		AuditLog::write( 'admin', 'ok', '', 'desktop extension downloaded (ship-content-' . $v['name'] . ')' );
		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="ship-content-' . $v['name'] . '.mcpb"' );
		header( 'Content-Length: ' . (string) filesize( $tmp ) );
		readfile( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
		exit;
	}
}
