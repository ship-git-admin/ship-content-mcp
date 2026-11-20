<?php
/**
 * 更新の確認（GitHub の公開リリース → WordPress 標準の「更新」画面から1クリック）。
 *
 * - 同梱の Plugin Update Checker（v5.7、MIT）で、GitHub のリリースを12時間ごとに確認する。
 * - 使う ZIP は、公開済みの最新リリースに添付した配布用 ZIP だけ（REQUIRE_RELEASE_ASSETS）。GitHub が自動で作る
 *   ソース一式の ZIP（試験・開発用のファイルを含む）は使わない。添付がないリリースは更新として扱わない。
 * - リリースが見つからないときに「最新のタグ」「ブランチ」へ切り替える PUC の既定の動きは止める
 *   （タグを push しただけ・下書きのリリースの段階で、各サイトに更新が出ないように）。
 * - 公開リポジトリから取得するので認証トークンは使わない（サイトに秘密を置かない）。
 * - 更新の確認で送るのは、GitHub API への通常の取得だけ（サイトの情報・鍵は送らない）。
 * - 止めるとき：wp-config.php で SHIP_CONTENT_MCP_DISABLE_UPDATES を true。取得元を変えるとき：SHIP_CONTENT_MCP_UPDATE_REPO。
 * - 更新しても、設定・制作方針・鍵・記録は消えない（オプションと表はそのまま。表の変更は Installer の版で行う）。
 *
 * @package ShipContentMcp
 */

namespace ShipContentMcp\Release;

defined( 'ABSPATH' ) || exit;

final class Updater {

	public const DEFAULT_REPO = 'https://github.com/ship-git-admin/ship-content-mcp/';
	public const ASSET_REGEX  = '/^ship-content-mcp-[0-9]+\.[0-9]+\.[0-9]+\.zip$/';

	/** @var object|null 生成した Plugin Update Checker（試験・診断用） */
	public static ?object $checker = null;

	public static function register(): void {
		if ( defined( 'SHIP_CONTENT_MCP_DISABLE_UPDATES' ) && SHIP_CONTENT_MCP_DISABLE_UPDATES ) {
			return;
		}
		$loader = SHIP_CONTENT_MCP_DIR . '/vendor/plugin-update-checker/plugin-update-checker.php';
		if ( ! is_readable( $loader ) ) {
			return;
		}
		$repo = self::repository();
		if ( '' === $repo ) {
			return;
		}
		require_once $loader;
		$checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker( $repo, SHIP_CONTENT_MCP_FILE, 'ship-content-mcp' );
		$checker->getVcsApi()->enableReleaseAssets( self::ASSET_REGEX, \YahnisElsts\PluginUpdateChecker\v5p7\Vcs\Api::REQUIRE_RELEASE_ASSETS );
		add_filter( $checker->getUniqueName( 'vcs_update_detection_strategies' ), array( self::class, 'only_latest_release' ) );
		self::$checker = $checker;
	}

	/**
	 * 更新の見つけ方は「公開済みの最新リリース」だけにする。
	 *
	 * @param array<string,callable> $strategies
	 * @return array<string,callable>
	 */
	public static function only_latest_release( array $strategies ): array {
		return array_intersect_key( $strategies, array( \YahnisElsts\PluginUpdateChecker\v5p7\Vcs\Api::STRATEGY_LATEST_RELEASE => true ) );
	}

	/**
	 * 取得元（GitHub のリポジトリの URL だけを許す）。不正なら空文字（更新の確認をしない）。
	 */
	public static function repository(): string {
		$repo = defined( 'SHIP_CONTENT_MCP_UPDATE_REPO' ) && is_string( SHIP_CONTENT_MCP_UPDATE_REPO ) ? SHIP_CONTENT_MCP_UPDATE_REPO : self::DEFAULT_REPO;
		$repo = (string) apply_filters( 'ship_content_mcp_update_repository', $repo );
		return preg_match( '#^https://github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+/?$#', $repo ) ? trailingslashit( $repo ) : '';
	}
}
