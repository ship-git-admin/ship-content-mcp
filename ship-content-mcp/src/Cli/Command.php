<?php
/**
 * WP-CLI：wp ship-content-mcp status|selftest|enable|disable|keys [list|add|revoke]|cleanup-tests [--yes]
 *
 * selftest は読み取りが中心。書くのは、使い捨て番号の表への試験の行だけ（最後に消す）。ユーザー・投稿・鍵は作らない。
 *
 * @package ShipContentMcp
 */

namespace ShipContentMcp\Cli;

use ShipContentMcp\ACF\SchemaReader;
use ShipContentMcp\Admin\Diagnostics;
use ShipContentMcp\Content\DraftService;
use ShipContentMcp\Content\PostTypes;
use ShipContentMcp\Core\Consts;
use ShipContentMcp\Core\Settings;
use ShipContentMcp\MCP\Abilities;
use ShipContentMcp\Policy\Policies;
use ShipContentMcp\Security\AccessGate;
use ShipContentMcp\Security\AuditLog;
use ShipContentMcp\Security\DeviceKeys;
use ShipContentMcp\Security\IpRules;
use ShipContentMcp\Security\NonceStore;
use ShipContentMcp\Security\RequestContext;
use ShipContentMcp\Security\SignatureCodec;
use ShipContentMcp\Security\SiteKey;

defined( 'ABSPATH' ) || exit;

final class Command {

	/** @var list<array{0:string,1:bool,2:string}> */
	private array $results = array();

	/**
	 * 診断を表示する（書き込みなし）。
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table または json
	 * ---
	 * default: table
	 * ---
	 *
	 * @param list<string>          $args
	 * @param array<string,string>  $assoc
	 */
	public function status( array $args, array $assoc ): void {
		unset( $args );
		$rows = Diagnostics::collect( false );
		\WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $rows, array( 'id', 'status', 'label', 'detail' ) );
	}

	/**
	 * 試験の印の付いた下書きを消す（「試験中」の投稿タイプで MCP が作った下書きだけ。公開済みは消さない）。
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : 付けたときだけ消す（付けなければ一覧を表示するだけ）
	 *
	 * @param list<string>          $args
	 * @param array<string,string>  $assoc
	 */
	public function cleanup_tests( array $args, array $assoc ): void {
		unset( $args );
		$ids  = get_posts(
			array(
				'post_type'        => array_values( array_diff( get_post_types(), array( 'attachment', 'revision' ) ) ),
				'post_status'      => 'draft',
				'meta_key'         => DraftService::META_TEST, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'       => '1', // phpcs:ignore WordPress.DB.SlowDBQuery
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		);
		$rows = array();
		foreach ( $ids as $id ) {
			if ( '' === (string) get_post_meta( $id, DraftService::META_CREATED, true ) ) {
				continue;
			}
			$rows[] = array(
				'ID'        => $id,
				'post_type' => get_post_type( $id ),
				'title'     => get_the_title( $id ),
			);
		}
		if ( array() === $rows ) {
			\WP_CLI::success( '試験の下書きはありません' );
			return;
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'ID', 'post_type', 'title' ) );
		if ( empty( $assoc['yes'] ) ) {
			\WP_CLI::log( count( $rows ) . ' 件あります。--yes を付けると消します。' );
			return;
		}
		$n = 0;
		foreach ( $rows as $r ) {
			if ( 'draft' === get_post_status( $r['ID'] ) && wp_delete_post( (int) $r['ID'], true ) ) {
				++$n;
			}
		}
		AuditLog::write( 'admin', 'ok', '', 'cleanup-tests deleted ' . $n . ' drafts', array( 'auth' => 'cli' ) );
		\WP_CLI::success( $n . ' 件の試験の下書きを消しました' );
	}

	/**
	 * MCP を受け付ける（管理画面の「MCP を受け付ける」と同じ）。
	 *
	 * @param list<string>          $args
	 * @param array<string,string>  $assoc
	 */
	public function enable( array $args, array $assoc ): void {
		unset( $args, $assoc );
		$this->set_enabled( true );
	}

	/**
	 * MCP を止める。
	 *
	 * @param list<string>          $args
	 * @param array<string,string>  $assoc
	 */
	public function disable( array $args, array $assoc ): void {
		unset( $args, $assoc );
		$this->set_enabled( false );
	}

	private function set_enabled( bool $on ): void {
		$s            = Settings::get();
		$s['enabled'] = $on;
		Settings::save( $s );
		AuditLog::write( 'admin', 'ok', '', ( $on ? 'enabled' : 'disabled' ) . ' via cli', array( 'auth' => 'cli' ) );
		\WP_CLI::success( $on ? 'MCP を受け付けます' : 'MCP を止めました' );
	}

	/**
	 * 端末の鍵の一覧・登録・停止（公開鍵は指紋だけを表示。秘密鍵は受け付けない）。
	 *
	 * ## OPTIONS
	 *
	 * [<action>]
	 * : list / add / revoke
	 * ---
	 * default: list
	 * ---
	 *
	 * [--for-user=<id>]
	 * : add のとき：鍵を使うユーザーの ID（Content MCP Operator のユーザー）
	 *
	 * [--label=<label>]
	 * : add のとき：端末の名前
	 *
	 * [--public-key=<base64>]
	 * : add のとき：中継プログラムの keygen が表示した公開鍵
	 *
	 * [--key-id=<id>]
	 * : revoke のとき：止める鍵の ID
	 *
	 * @param list<string>          $args
	 * @param array<string,string>  $assoc
	 */
	public function keys( array $args, array $assoc ): void {
		$action = $args[0] ?? 'list';
		if ( 'add' === $action ) {
			$user_id = (int) ( $assoc['for-user'] ?? 0 );
			$why     = AccessGate::user_problem( $user_id );
			if ( '' !== $why ) {
				\WP_CLI::error( 'このユーザーは MCP を使えません：' . $why );
			}
			try {
				$key_id = DeviceKeys::add( $user_id, (string) ( $assoc['label'] ?? '' ), (string) ( $assoc['public-key'] ?? '' ), get_current_user_id() );
			} catch ( \InvalidArgumentException $e ) {
				\WP_CLI::error( $e->getMessage() );
			}
			AuditLog::write( 'admin', 'ok', '', 'key added via cli for user #' . $user_id, array( 'key_id' => $key_id, 'auth' => 'cli' ) );
			\WP_CLI::log( 'SHIP_MCP_KEY_ID=' . $key_id );
			\WP_CLI::log( 'SHIP_MCP_SERVER_KEY=' . SiteKey::public_b64() );
			\WP_CLI::success( '登録しました' );
			return;
		}
		if ( 'revoke' === $action ) {
			$key_id = (string) ( $assoc['key-id'] ?? '' );
			$ok     = DeviceKeys::revoke( $key_id, get_current_user_id() );
			AuditLog::write( 'admin', $ok ? 'ok' : 'error', '', 'key revoked via cli', array( 'key_id' => $key_id, 'auth' => 'cli' ) );
			$ok ? \WP_CLI::success( '止めました：' . $key_id ) : \WP_CLI::error( '止められませんでした（ない鍵か、すでに止めた鍵）' );
			return;
		}
		if ( 'list' !== $action ) {
			\WP_CLI::error( 'list / add / revoke のどれかを指定してください' );
		}
		$rows = array();
		foreach ( DeviceKeys::all() as $r ) {
			$raw    = SignatureCodec::decode_public_key( (string) $r['public_key'] );
			$rows[] = array(
				'key_id'      => $r['key_id'],
				'user_id'     => $r['user_id'],
				'label'       => $r['label'],
				'fingerprint' => null === $raw ? '?' : SignatureCodec::fingerprint( $raw ),
				'created_at'  => $r['created_at'],
				'last_used'   => $r['last_used_at'] ?? '',
				'revoked_at'  => $r['revoked_at'] ?? '',
			);
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'key_id', 'user_id', 'label', 'fingerprint', 'created_at', 'last_used', 'revoked_at' ) );
	}

	/**
	 * 自己テスト（読み取り中心。ユーザー・投稿・鍵は作らない）。
	 *
	 * @param list<string>          $args
	 * @param array<string,string>  $assoc
	 */
	public function selftest( array $args, array $assoc ): void {
		unset( $args, $assoc );
		$this->results = array();
		$settings      = Settings::get();
		AuditLog::mute( true );

		// --- 依存と登録 ---
		$this->check( 'T01 Abilities API がある', function_exists( 'wp_register_ability' ) );
		$this->check( 'T02 sodium がある', function_exists( 'sodium_crypto_sign_verify_detached' ) );
		foreach ( Abilities::TOOLS as $name ) {
			$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( $name ) : null;
			$meta    = $ability ? $ability->get_meta() : array();
			$this->check( 'T03 登録：' . $name, null !== $ability );
			$this->check( 'T04 非公開：' . $name, $ability && empty( $meta['public'] ) && empty( $meta['show_in_rest'] ) && isset( $meta['mcp']['public'] ) && false === $meta['mcp']['public'] );
		}
		$routes = rest_get_server()->get_routes();
		$this->check( 'T05 コアの REST に自作ツールが出ていない', ! self::abilities_in_rest( $routes ) );
		if ( $settings['disable_default_server'] ) {
			$this->check( 'T06 既定サーバーの入口がない', ! isset( $routes['/mcp/mcp-adapter-default-server'] ) );
		}
		if ( $settings['enabled'] && class_exists( '\\WP\\MCP\\Core\\McpAdapter' ) ) {
			$server = \WP\MCP\Core\McpAdapter::instance()->get_server( Consts::SERVER_ID );
			$this->check( 'T07 自作サーバーがある', null !== $server );
			$this->check( 'T08 入口がある ' . Consts::REST_ROUTE, isset( $routes[ Consts::REST_ROUTE ] ) );
			if ( $server ) {
				$this->check( 'T09 ツールは ' . count( Abilities::TOOLS ) . ' 件だけ', count( Abilities::TOOLS ) === $server->count_tools(), 'count=' . $server->count_tools() );
				foreach ( Abilities::TOOLS as $name ) {
					$this->check( 'T10 ツール名：' . str_replace( '/', '-', $name ), null !== $server->get_mcp_tool( str_replace( '/', '-', $name ) ) );
				}
				$this->check( 'T11 リソース・プロンプトはない', 0 === $server->count_resources() && 0 === $server->count_prompts() );
			}
		} else {
			\WP_CLI::log( '（MCP が停止中、または Adapter がないため、サーバーの試験 T07〜T11 は飛ばしました）' );
		}

		// --- 権限（HTTP から来たものとして） ---
		RequestContext::pretend_http_for_tests( true );
		$saved_user = get_current_user_id();
		wp_set_current_user( 0 );
		RequestContext::simulate_dispatch_for_tests( true );
		$this->check( 'T20 ログインなしは拒否', is_wp_error( AccessGate::ability() ) );
		$admins = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
				'fields' => 'ID',
			)
		);
		if ( array() !== $admins ) {
			wp_set_current_user( (int) $admins[0] );
			$this->check( 'T21 管理者（権限が強い）は拒否', '' !== AccessGate::user_problem( (int) $admins[0] ) && is_wp_error( AccessGate::ability() ) );
		}
		$subs = get_users(
			array(
				'role__in' => array( 'subscriber', 'reviewer' ),
				'number'   => 1,
				'fields'   => 'ID',
			)
		);
		if ( array() !== $subs ) {
			wp_set_current_user( (int) $subs[0] );
			$this->check( 'T22 専用の権限がないユーザーは拒否', 'no_access_cap' === AccessGate::user_problem( (int) $subs[0] ) && is_wp_error( AccessGate::ability() ) );
		}
		RequestContext::simulate_dispatch_for_tests( false );
		$ops = get_users(
			array(
				'capability' => Consts::CAP,
				'number'     => 1,
				'fields'     => 'ID',
			)
		);
		if ( array() !== $ops ) {
			wp_set_current_user( (int) $ops[0] );
			$this->check( 'T23 MCP の入口の外からは拒否（PM のユーザーでも）', is_wp_error( AccessGate::ability() ) );
		}
		$this->check( 'T24 Cookie での接続は拒否', is_wp_error( AccessGate::transport() ) );
		wp_set_current_user( $saved_user );
		RequestContext::pretend_http_for_tests( false );
		RequestContext::reset_for_tests();

		// --- 入口の URL の判定 ---
		$this->route_tests();

		// --- 署名 ---
		$pair    = sodium_crypto_sign_keypair();
		$pk      = sodium_crypto_sign_publickey( $pair );
		$sk      = sodium_crypto_sign_secretkey( $pair );
		$ts      = (string) time();
		$msg     = SignatureCodec::canonical_request( 'POST', 'example.invalid', '/wp-json/ship-content/mcp', $ts, 'AAAAAAAAAAAAAAAAAAAAAA', 'k0123456789abcdef', '', '{"jsonrpc":"2.0"}' );
		$sig_raw = (string) base64_decode( SignatureCodec::sign( $msg, $sk ), true );
		$this->check( 'T30 正しい署名は通る', SignatureCodec::verify( $sig_raw, $msg, $pk ) );
		$tampered = SignatureCodec::canonical_request( 'POST', 'example.invalid', '/wp-json/ship-content/mcp', $ts, 'AAAAAAAAAAAAAAAAAAAAAA', 'k0123456789abcdef', '', '{"jsonrpc":"2.1"}' );
		$this->check( 'T31 本文を変えると通らない', $tampered !== $msg && ! SignatureCodec::verify( $sig_raw, $tampered, $pk ) );
		$this->check( 'T32 古い時刻は通らない', ! SignatureCodec::fresh( (string) ( time() - 301 ), time() ) && SignatureCodec::fresh( (string) ( time() - 299 ), time() ) );
		$test_key = 'k' . bin2hex( random_bytes( 8 ) );
		$nonce    = rtrim( strtr( base64_encode( random_bytes( 18 ) ), '+/', '-_' ), '=' );
		$first    = NonceStore::claim( $test_key, $nonce );
		$second   = NonceStore::claim( $test_key, $nonce );
		NonceStore::delete_for_key( $test_key );
		$this->check( 'T33 同じ番号は2回通らない', $first && ! $second );
		$site_pk = (string) base64_decode( SiteKey::public_b64(), true );
		$secret  = SiteKey::secret_raw();
		$res_msg = SignatureCodec::canonical_response( $nonce, 200, 'sess', '{}' );
		$this->check( 'T34 サイトの鍵で応答に署名できる', null !== $secret && '' !== $site_pk && SignatureCodec::verify( (string) base64_decode( SignatureCodec::sign( $res_msg, $secret ), true ), $res_msg, $site_pk ) );

		// --- 投稿タイプ・方針・ACF ---
		$this->check( 'T40 固定ページ・メディアは候補にならない', ! isset( PostTypes::candidates()['page'] ) && ! isset( PostTypes::candidates()['attachment'] ) );
		$allowed = PostTypes::allowed();
		$this->check( 'T41 使える投稿タイプがある', array() !== $allowed, implode( ',', $allowed ) );
		foreach ( $allowed as $pt ) {
			$p = Policies::for_output( $pt );
			$this->check( 'T42 制作方針の形：' . $pt, array( 'post_type', 'site_policy', 'type_policy', 'version', 'warnings' ) === array_keys( $p['data'] ) && $p['data']['version'] >= 1 );
			if ( ! function_exists( 'acf_get_field_groups' ) ) {
				continue;
			}
			$a = ( new SchemaReader() )->read( $pt );
			$b = ( new SchemaReader() )->read( $pt );
			$this->check( 'T43 構造の指紋が安定：' . $pt, $a['data']['schema_fingerprint'] === $b['data']['schema_fingerprint'], $a['data']['schema_fingerprint'] );
			$this->check( 'T44 新しい下書きの推定でグループが出る：' . $pt, count( $a['data']['field_groups'] ) > 0, count( $a['data']['field_groups'] ) . ' groups / ' . $a['data']['field_count'] . ' fields' );
			$recent = get_posts(
				array(
					'post_type'      => $pt,
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'fields'         => 'ids',
				)
			);
			if ( array() !== $recent ) {
				$c = ( new SchemaReader() )->read( $pt, (int) $recent[0] );
				$this->check( 'T45 既存の投稿でグループが出る：' . $pt . ' #' . $recent[0], count( $c['data']['field_groups'] ) > 0, count( $c['data']['field_groups'] ) . ' groups, ' . $c['data']['schema_fingerprint'] );
			}
		}

		// --- IP ---
		$this->check( 'T50 IP の判定', IpRules::allowed( '192.0.2.10', array( '192.0.2.0/24' ) ) && ! IpRules::allowed( '198.51.100.1', array( '192.0.2.0/24' ) ) && IpRules::allowed( '2001:db8::1', array( '2001:db8::/32' ) ) && IpRules::allowed( '203.0.113.5', array() ) && ! IpRules::is_valid_entry( '1.2.3.4/33' ) );

		AuditLog::mute( false );
		$fail = count( array_filter( $this->results, static fn( $r ) => ! $r[1] ) );
		foreach ( $this->results as [ $name, $ok, $note ] ) {
			\WP_CLI::log( ( $ok ? '[OK] ' : '[NG] ' ) . $name . ( '' !== $note ? '  (' . $note . ')' : '' ) );
		}
		if ( $fail > 0 ) {
			\WP_CLI::error( $fail . ' 件が不合格です（全 ' . count( $this->results ) . ' 件）' );
		}
		\WP_CLI::success( '全 ' . count( $this->results ) . ' 件が合格しました' );
	}

	private function route_tests(): void {
		$saved_uri  = $_SERVER['REQUEST_URI'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$saved_get  = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$saved_post = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$home       = rtrim( (string) wp_parse_url( (string) get_option( 'home' ), PHP_URL_PATH ), '/' );
		$prefix     = '/' . trim( rest_get_url_prefix(), '/' );
		$cases      = array(
			array( $home . $prefix . '/ship-content/mcp', array(), true ),
			array( $home . $prefix . '/ship-content/mcp/', array(), true ),
			array( $home . '/index.php' . $prefix . '/ship-content/mcp', array(), true ),
			array( $home . '/?rest_route=/ship-content/mcp', array( 'rest_route' => '/ship-content/mcp' ), true ),
			array( $home . $prefix . '/wp/v2/posts', array(), false ),
			array( $home . $prefix . '/ship-content/mcp/../../wp/v2/posts', array(), false ),
			array( $home . $prefix . '/ship-content/mcpx', array(), false ),
			array( $home . '/?rest_route=/wp/v2/posts', array( 'rest_route' => '/wp/v2/posts' ), false ),
			array( $home . $prefix . '/ship-content/mcp?rest_route=/wp/v2/posts', array( 'rest_route' => '/wp/v2/posts' ), false ),
		);
		foreach ( $cases as [ $uri, $get, $expect ] ) {
			RequestContext::reset_for_tests();
			$_SERVER['REQUEST_URI'] = $uri;
			$_GET                   = $get;
			$_POST                  = array();
			$this->check( 'T12 入口の判定：' . $uri, $expect === RequestContext::is_mcp_url() );
		}
		RequestContext::reset_for_tests();
		if ( null === $saved_uri ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $saved_uri;
		}
		$_GET  = $saved_get;
		$_POST = $saved_post;
	}

	/**
	 * @param array<string,mixed> $routes
	 */
	private static function abilities_in_rest( array $routes ): bool {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return false;
		}
		// コアの REST（wp-abilities/v1）が一覧に出すのは show_in_rest のものだけ。自作ツールの印を確かめる。
		foreach ( Abilities::TOOLS as $name ) {
			$ability = wp_get_ability( $name );
			if ( $ability && ! empty( $ability->get_meta()['show_in_rest'] ) ) {
				return true;
			}
		}
		unset( $routes );
		return false;
	}

	private function check( string $name, bool $ok, string $note = '' ): void {
		$this->results[] = array( $name, $ok, $note );
	}
}
