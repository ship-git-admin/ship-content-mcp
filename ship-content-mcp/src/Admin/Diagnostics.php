<?php
/**
 * 診断（見るだけ。書き込みはしない。秘密・パスワードは出さない。管理者だけが見る）。
 *
 * @package ShipContentMcp
 */

namespace ShipContentMcp\Admin;

use ShipContentMcp\Core\Consts;
use ShipContentMcp\Core\Installer;
use ShipContentMcp\Core\Plugin;
use ShipContentMcp\Core\Settings;
use ShipContentMcp\MCP\Abilities;
use ShipContentMcp\MCP\Server;
use ShipContentMcp\Security\AccessGate;
use ShipContentMcp\Security\DeviceKeys;
use ShipContentMcp\Security\IpRules;
use ShipContentMcp\Security\SiteKey;

defined( 'ABSPATH' ) || exit;

final class Diagnostics {

	/**
	 * @return list<array{id:string,label:string,status:string,detail:string,remedy:string}> status は ok / warn / ng / info。remedy は対処（なければ空）
	 */
	public static function collect( bool $include_request = true ): array {
		global $wp_version, $wpdb;
		$d        = Plugin::dependencies();
		$settings = Settings::get();
		$out      = array();
		$add      = static function ( string $id, string $label, string $status, string $detail, string $remedy = '' ) use ( &$out ) {
			$out[] = compact( 'id', 'label', 'status', 'detail', 'remedy' );
		};

		$add( 'wp', 'WordPress', version_compare( (string) $wp_version, '6.9', '>=' ) ? 'ok' : 'ng', (string) $wp_version . '（6.9 以上が必要）', version_compare( (string) $wp_version, '6.9', '>=' ) ? '' : 'WordPress を 6.9 以上に更新してください' );
		$add( 'php', 'PHP', version_compare( PHP_VERSION, '8.1', '>=' ) ? 'ok' : 'ng', PHP_VERSION . '（' . PHP_SAPI . '）', version_compare( PHP_VERSION, '8.1', '>=' ) ? '' : 'サーバーの管理画面で PHP 8.1 以上に切り替えてください' );
		$add( 'sodium', '署名の計算（sodium）', $d['sodium'] ? 'ok' : 'ng', $d['sodium'] ? '使える' : '使えません（署名方式が使えません）', $d['sodium'] ? '' : 'PHP の sodium を有効にしてください（ホスティング会社に確認）' );
		$add( 'acf', 'ACF', $d['acf_active'] ? 'ok' : 'warn', $d['acf_active'] ? $d['acf_version'] : '有効ではありません（入力欄の構造を読めません）', $d['acf_active'] ? '' : 'ACF（Advanced Custom Fields）を有効にしてください' );
		$add( 'abilities', 'Abilities API', $d['abilities_api'] ? 'ok' : 'ng', $d['abilities_api'] ? 'コアにあります' : 'ありません（WordPress 6.9 以上が必要）', $d['abilities_api'] ? '' : 'WordPress を 6.9 以上に更新してください' );
		$add( 'adapter', '公式 MCP Adapter', $d['adapter_active'] ? 'ok' : 'ng', $d['adapter_active'] ? '有効（' . ( '' !== $d['adapter_version'] ? $d['adapter_version'] : '版不明' ) . '）' : '入っていないか、無効です（プラグイン「MCP Adapter」を入れて有効にしてください）', $d['adapter_active'] ? '' : 'プラグイン「MCP Adapter」（WordPress.org）を入れて有効にしてください' );
		$add( 'enabled', 'MCP の受け付け', $settings['enabled'] ? 'ok' : 'warn', $settings['enabled'] ? '有効' : '停止中（基本設定で有効にするまで、入口はありません）', $settings['enabled'] ? '' : '準備ができたら、基本設定で「MCP を受け付ける」を有効にしてください' );

		// 登録の状態。
		if ( $d['abilities_api'] && function_exists( 'wp_get_ability' ) ) {
			$missing = array();
			$public  = array();
			foreach ( Abilities::TOOLS as $name ) {
				$ability = wp_get_ability( $name );
				if ( ! $ability ) {
					$missing[] = $name;
					continue;
				}
				$meta = $ability->get_meta();
				if ( ! empty( $meta['public'] ) || ! empty( $meta['show_in_rest'] ) || ! empty( $meta['mcp']['public'] ) ) {
					$public[] = $name;
				}
			}
			if ( array() !== $missing ) {
				$add( 'tools', 'ツールの登録', 'ng', '見つからない：' . implode( ', ', $missing ), 'プラグインを入れ直してください' );
			} elseif ( array() !== $public ) {
				$add( 'tools', 'ツールの登録', 'ng', '公開の印が付いている（REST や既定サーバーに出るおそれ）：' . implode( ', ', $public ), 'ほかのプラグインやコードが Ability の設定を変えていないか確認してください' );
			} else {
				$add( 'tools', 'ツールの登録', 'ok', count( Abilities::TOOLS ) . '件（REST・既定サーバーには出さない設定）' );
			}
		}

		$routes = rest_get_server()->get_routes();
		if ( $settings['enabled'] && $d['adapter_active'] ) {
			$has = isset( $routes[ Consts::REST_ROUTE ] );
			$err = Server::last_error();
			$add( 'endpoint', 'MCP の入口', $has ? 'ok' : 'ng', $has ? Server::endpoint() : '登録されていません' . ( '' !== $err ? '（' . $err . '）' : '' ), $has ? '' : 'MCP Adapter が有効か確認し、「設定 → パーマリンク」を保存し直してください' );
		}
		$default_route = isset( $routes['/mcp/mcp-adapter-default-server'] );
		$add( 'default_server', '公式の既定サーバー', $default_route ? 'warn' : 'ok', $default_route ? '動いています（ほかの Ability が出ていないか確認してください）' : ( $settings['disable_default_server'] ? '止めています' : '動いていません' ), $default_route ? '基本設定で「公式の既定サーバーを止める」を有効にしてください' : '' );

		// 通信経路。
		$https_home = 0 === strpos( (string) get_option( 'home' ), 'https://' );
		$add( 'https', 'サイトの URL', $https_home ? 'ok' : 'warn', (string) get_option( 'home' ) . ( $https_home ? '' : '（HTTP。Application Password は使えません。署名方式を使ってください）' ), $https_home ? '' : 'PM には署名方式（中継プログラム）を使ってもらってください' );
		$app_pw = function_exists( 'wp_is_application_passwords_available' ) && wp_is_application_passwords_available();
		$add( 'app_password', 'Application Password', $app_pw ? 'ok' : 'info', $app_pw ? 'このリクエストでは使えます' : 'このリクエストでは使えません（HTTPS でないなど）' );
		if ( $include_request ) {
			$basic = isset( $_SERVER['PHP_AUTH_USER'] ) || isset( $_SERVER['HTTP_AUTHORIZATION'] ) || isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
			$add( 'basic_auth', 'サーバーの Basic 認証', $basic ? 'warn' : 'ok', $basic ? 'この画面の表示に Authorization が付いています（Basic 認証のあるサイト）。Application Password とぶつかるので、署名方式を使ってください' : '見当たりません', $basic ? 'Application Password とぶつかるので、PM には署名方式を使ってもらってください' : '' );
			$ip = IpRules::remote_addr();
			$add( 'remote_addr', 'この画面を開いた接続元（REMOTE_ADDR）', 'info', '' !== $ip ? $ip : '不明' );
		}
		$add( 'ip_allowlist', '接続元の制限', 'info', array() === $settings['ip_allowlist'] ? '制限なし' : implode( ', ', $settings['ip_allowlist'] ) );

		// REST を止めるほかのコード。
		$others = array_merge( self::foreign_callbacks( 'rest_pre_dispatch' ), self::foreign_callbacks( 'rest_authentication_errors' ) );
		$add( 'rest_filters', 'REST を止めるかもしれないコード', array() === $others ? 'ok' : 'warn', array() === $others ? '見当たりません' : implode( ', ', array_unique( $others ) ) . '（MCP の入口 ship-content/ が止められていないか確認してください）', array() === $others ? '' : 'REST を止めるコード（セキュリティの Snippet など）で、MCP の入口 ship-content/ を通すようにしてください（ほかの REST は止めたままでよい）' );

		// ロール・ユーザー・鍵。
		$role = get_role( Consts::ROLE );
		if ( ! $role ) {
			$add( 'role', 'PM 用のロール', 'ng', 'ありません（プラグインを一度無効にして、有効にし直してください）', 'プラグインを一度無効にして、有効にし直してください' );
		} else {
			$extra = array_diff( array_keys( array_filter( $role->capabilities ) ), array( 'read', Consts::CAP ) );
			$add( 'role', 'PM 用のロール', array() === $extra ? 'ok' : 'warn', Consts::ROLE_LABEL . ( array() === $extra ? '（read と専用の権限だけ）' : '（ほかの権限が付いています：' . implode( ', ', $extra ) . '）' ), array() === $extra ? '' : 'User Role Editor などで、このロールから read と ship_content_mcp_access 以外の権限を外してください' );
		}
		$users = get_users(
			array(
				'capability' => Consts::CAP,
				'fields'     => 'ID',
				'number'     => 200,
			)
		);
		$bad = 0;
		foreach ( $users as $uid ) {
			if ( '' !== AccessGate::user_problem( (int) $uid ) ) {
				++$bad;
			}
		}
		$add( 'operators', 'MCP を使えるユーザー', 0 === count( $users ) ? 'warn' : ( $bad > 0 ? 'warn' : 'ok' ), count( $users ) . '人' . ( $bad > 0 ? '（うち ' . $bad . '人は権限が強すぎるなどで使えません）' : '' ), 0 === count( $users ) ? '「ユーザー → 新規追加」で、権限グループ「' . Consts::ROLE_LABEL . '」のユーザーを PM ごとに作ってください' : ( $bad > 0 ? '権限の強いユーザーではなく、' . Consts::ROLE_LABEL . ' だけのユーザーを使ってください' : '' ) );
		$add( 'keys', '有効な端末の鍵', 'info', DeviceKeys::count_active() . '件' );
		$fp = SiteKey::fingerprint();
		$add( 'site_key', 'サイトの鍵（応答の署名）', '' !== $fp ? 'ok' : 'ng', '' !== $fp ? '指紋 ' . $fp : 'ありません', '' !== $fp ? '' : 'プラグインを一度無効にして、有効にし直してください' );

		// 表。
		$missing_tables = array();
		foreach ( array( 'keys', 'nonces', 'log', 'idem', 'uploads', 'chunks' ) as $t ) {
			$name = Installer::table( $t );
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ) !== $name ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$missing_tables[] = $t;
			}
		}
		$add( 'tables', 'プラグインの表', array() === $missing_tables ? 'ok' : 'ng', array() === $missing_tables ? 'あります' : 'ありません：' . implode( ', ', $missing_tables ), array() === $missing_tables ? '' : 'プラグインを一度無効にして、有効にし直してください' );

		// 確かめた構造（試験合格・稼働の投稿タイプ）。
		foreach ( \ShipContentMcp\Content\PostTypes::allowed() as $pt ) {
			if ( ! in_array( \ShipContentMcp\Content\PostTypes::status( $pt ), \ShipContentMcp\Content\StructureLock::LOCKED_STATUSES, true ) ) {
				continue;
			}
			$verified = (array) ( $settings['post_types'][ $pt ]['verified']['groups'] ?? array() );
			try {
				$now = \ShipContentMcp\Content\StructureLock::current( $pt );
			} catch ( \Throwable $e ) {
				$now = array();
			}
			$same   = array() !== $verified && $now == $verified; // phpcs:ignore Universal.Operators.StrictComparisons -- 順番は問わない
			$strict = ! empty( $settings['structure_strict'] );
			$add(
				'structure_' . $pt,
				'確かめた構造：' . $pt,
				$same ? 'ok' : ( $strict ? 'ng' : 'warn' ),
				$same ? count( $verified ) . 'グループ（記録と同じ）' : '記録の後に入力欄の構造が変わったか、記録がありません（' . ( $strict ? '書き込みを止めています' : '書き込みは止めず、AI に知らせています' ) . '）',
				$same ? '' : '入力欄の変更が意図どおりか確かめてから、基本設定で「いまの構造を確かめ直した」にチェックを入れて保存してください'
			);
		}

		// その他。
		$overdue = 0;
		$cron    = function_exists( '_get_cron_array' ) ? (array) _get_cron_array() : array();
		foreach ( array_keys( $cron ) as $ts ) {
			if ( (int) $ts < time() - HOUR_IN_SECONDS ) {
				++$overdue;
			}
		}
		$add( 'cron', 'WP-Cron', $overdue > 0 ? 'info' : 'ok', $overdue > 0 ? '1時間以上遅れている予定が ' . $overdue . ' 件（このプラグインは WP-Cron に頼りません）' : '遅れなし' );
		$add( 'upload', 'アップロードの上限', 'info', size_format( wp_max_upload_size() ) );

		return $out;
	}

	/**
	 * WordPress のコアとこのプラグイン以外のフック。
	 *
	 * @return list<string>
	 */
	private static function foreign_callbacks( string $hook ): array {
		global $wp_filter;
		$core = array( 'rest_cookie_check_errors', 'rest_application_password_check_errors', 'rest_handle_options_request', 'ACF_Rest_Api::initialize' );
		$out  = array();
		if ( ! isset( $wp_filter[ $hook ] ) ) {
			return $out;
		}
		foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
			foreach ( $callbacks as $cb ) {
				$fn   = $cb['function'];
				$name = is_string( $fn ) ? $fn : ( is_array( $fn ) ? ( is_object( $fn[0] ) ? get_class( $fn[0] ) : (string) $fn[0] ) . '::' . $fn[1] : 'closure' );
				if ( in_array( $name, $core, true ) || str_starts_with( $name, 'ShipContentMcp\\' ) ) {
					continue;
				}
				$out[] = $hook . ': ' . $name;
			}
		}
		return $out;
	}
}
