<?php
/**
 * 入口の確認（接続のとき・ツールごと・業務の処理の中で、同じ条件を何度も確かめる）。
 *
 * 通す条件：
 * 1. MCP が有効
 * 2. 接続元の IP が許可されている（設定があるとき。REMOTE_ADDR だけを見る）
 * 3. 認証の方法が許可されている（OAuth / トークン / 署名 / Application Password。Cookie での利用は不可）
 * 4. ユーザーが専用の権限を持つ
 * 5. 一般の編集権限などを持たない（設定で拒否しているとき。初期は拒否）
 * 6. ツールは、MCP の入口の振り分け中（または WP-CLI）でだけ動く（コアの REST などからは動かない）
 *
 * @package ShipContentMcp
 */

namespace ShipContentMcp\Security;

use ShipContentMcp\Core\Consts;
use ShipContentMcp\Core\DomainError;
use ShipContentMcp\Core\Settings;
use ShipContentMcp\MCP\Envelope;

defined( 'ABSPATH' ) || exit;

final class AccessGate {

	private static bool $transport_logged = false;

	/** MCP 用のユーザーが持っていてはいけない権限（どれか1つでもあれば拒否）。 */
	public const PRIVILEGED_CAPS = array(
		'manage_options',
		'activate_plugins',
		'install_plugins',
		'edit_plugins',
		'edit_themes',
		'edit_files',
		'edit_theme_options',
		'edit_users',
		'create_users',
		'promote_users',
		'delete_users',
		'unfiltered_html',
		'unfiltered_upload',
		'edit_posts',
		'edit_pages',
		'edit_others_posts',
		'publish_posts',
		'publish_pages',
		'delete_posts',
		'delete_pages',
		'upload_files',
		'moderate_comments',
		'import',
		'export',
	);

	/**
	 * 接続のとき（公式 Adapter の transport permission callback）。
	 *
	 * @param \WP_REST_Request|null $request
	 * @return true|\WP_Error
	 */
	public static function transport( $request = null ) {
		unset( $request );
		$problem = self::problem( true );
		if ( null === $problem ) {
			return true;
		}
		[ $code, $detail, $status ] = $problem;
		// WordPress は Allow ヘッダーを作るために、同じリクエストでこの確認をメソッドごとに何度も呼ぶ。記録は1回だけ。
		if ( ! self::$transport_logged ) {
			self::$transport_logged = true;
			AuditLog::write( 'transport', 'denied', $code, $detail );
		}
		$msg = DomainError::MESSAGES[ $code ] . '（' . $code . '）';
		if ( 'MCP-002' !== $code && 'admin' === ( DomainError::FIX[ $code ][0] ?? '' ) ) {
			$msg .= ' サイトの管理者が直す必要があります（指示者から管理者に伝えてください）';
		}
		return new \WP_Error( 'ship_content_mcp_denied', $msg, array( 'status' => $status ) );
	}

	/**
	 * ツールごと（Ability の permission_callback）。
	 *
	 * @param mixed $input
	 * @return true|\WP_Error
	 */
	public static function ability( $input = null ) {
		unset( $input );
		if ( ! RequestContext::in_mcp_dispatch() && ! RequestContext::is_cli() ) {
			AuditLog::write( 'ability', 'denied', 'AUTH-001', 'called outside MCP route' );
			return Envelope::wp_error( new DomainError( 'AUTH-001', 'called outside MCP route' ) );
		}
		$problem = self::problem( ! RequestContext::is_cli() );
		if ( null === $problem ) {
			return true;
		}
		[ $code, $detail ] = $problem;
		AuditLog::write( 'ability', 'denied', $code, $detail );
		return Envelope::wp_error( new DomainError( $code, $detail ) );
	}

	/**
	 * 業務の処理の中でもう一度（Ability の外から呼ばれても通らないように）。
	 *
	 * @throws DomainError
	 */
	public static function assert(): void {
		if ( ! RequestContext::in_mcp_dispatch() && ! RequestContext::is_cli() ) {
			throw new DomainError( 'AUTH-001', 'called outside MCP route' );
		}
		$problem = self::problem( ! RequestContext::is_cli() );
		if ( null !== $problem ) {
			throw new DomainError( $problem[0], $problem[1] );
		}
	}

	/**
	 * ユーザーが MCP を使えない理由（使えるなら空）。管理画面の表示にも使う。
	 */
	public static function user_problem( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return 'not_logged_in';
		}
		if ( ! user_can( $user_id, Consts::CAP ) ) {
			return 'no_access_cap';
		}
		if ( Settings::get()['reject_privileged'] ) {
			foreach ( self::PRIVILEGED_CAPS as $cap ) {
				if ( user_can( $user_id, $cap ) ) {
					return 'privileged:' . $cap;
				}
			}
		}
		return '';
	}

	/**
	 * @return array{0:string,1:string,2:int}|null [コード, 管理者向けの理由, HTTP の状態]
	 */
	private static function problem( bool $http ): ?array {
		$settings = Settings::get();
		if ( ! $settings['enabled'] ) {
			return array( 'MCP-001', 'mcp disabled', 503 );
		}
		if ( $http ) {
			$ip = IpRules::remote_addr();
			if ( ! IpRules::allowed( $ip, $settings['ip_allowlist'] ) ) {
				return array( 'AUTH-001', 'ip not allowed', 403 );
			}
			$method = RequestContext::auth_method();
			if ( 'oauth' === $method && ! $settings['auth_oauth'] ) {
				return array( 'MCP-002', 'oauth disabled', 401 );
			}
			if ( 'token' === $method && ! $settings['auth_token'] ) {
				return array( 'MCP-002', 'token auth disabled', 401 );
			}
			if ( 'signature' === $method && ! $settings['auth_signature'] ) {
				return array( 'MCP-002', 'signature auth disabled', 401 );
			}
			if ( 'app_password' === $method && ! $settings['auth_app_password'] ) {
				return array( 'MCP-002', 'application password auth disabled', 401 );
			}
			if ( ! in_array( $method, array( 'oauth', 'token', 'signature', 'app_password' ), true ) ) {
				return array( 'MCP-002', 'unsupported auth: ' . ( '' === $method ? 'none' : $method ), 401 );
			}
		}
		$why = self::user_problem( get_current_user_id() );
		if ( '' !== $why ) {
			return array( 'not_logged_in' === $why ? 'MCP-002' : 'AUTH-001', 'user: ' . $why, 'not_logged_in' === $why ? 401 : 403 );
		}
		return null;
	}
}
