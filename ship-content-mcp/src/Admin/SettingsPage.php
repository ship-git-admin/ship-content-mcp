<?php
/**
 * 管理画面「設定 → Ship Content MCP」（管理者だけ）。
 *
 * タブ：基本設定 / 制作方針 / 端末の鍵 / 診断 / 記録
 * - 保存はすべて admin-post.php（nonce と manage_options を確かめる）。
 * - 秘密（端末の秘密鍵・パスワード・サイトの秘密鍵）は、受け取らない・表示しない。
 * - ACF の項目ごとの設定画面は作らない（制作方針は自由記述）。
 *
 * @package ShipContentMcp
 */

namespace ShipContentMcp\Admin;

use ShipContentMcp\ACF\SchemaReader;
use ShipContentMcp\Content\PostTypes;
use ShipContentMcp\Core\Consts;
use ShipContentMcp\Core\Plugin;
use ShipContentMcp\Core\Settings;
use ShipContentMcp\MCP\Server;
use ShipContentMcp\Policy\Policies;
use ShipContentMcp\Security\AccessGate;
use ShipContentMcp\Security\AuditLog;
use ShipContentMcp\Security\DeviceKeys;
use ShipContentMcp\Security\SiteKey;

defined( 'ABSPATH' ) || exit;

final class SettingsPage {

	public const SLUG = 'ship-content-mcp';

	private const TABS = array(
		'settings'    => '基本設定',
		'policy'      => '制作方針',
		'keys'        => '端末の鍵',
		'diagnostics' => '診断',
		'log'         => '記録',
	);

	public static function hooks(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		foreach ( array( 'save_settings', 'save_policies', 'add_key', 'revoke_key' ) as $action ) {
			add_action( 'admin_post_ship_content_mcp_' . $action, array( self::class, 'handle_' . $action ) );
		}
		add_action( 'admin_notices', array( self::class, 'dependency_notice' ) );
	}

	public static function menu(): void {
		add_options_page( 'Ship Content MCP', 'Ship Content MCP', 'manage_options', self::SLUG, array( self::class, 'render' ) );
	}

	public static function dependency_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$d = Plugin::dependencies();
		if ( $d['adapter_active'] && $d['abilities_api'] ) {
			return;
		}
		$url = admin_url( 'options-general.php?page=' . self::SLUG . '&tab=diagnostics' );
		echo '<div class="notice notice-warning"><p>Ship Content MCP：公式の MCP Adapter（または Abilities API）が見つかりません。MCP は動いていません。<a href="' . esc_url( $url ) . '">診断を見る</a></p></div>';
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'この画面を開く権限がありません。' ) );
		}
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( self::TABS[ $tab ] ) ) {
			$tab = 'settings';
		}
		echo '<div class="wrap"><h1>Ship Content MCP</h1>';
		self::notice();
		echo '<nav class="nav-tab-wrapper">';
		foreach ( self::TABS as $key => $label ) {
			$url = admin_url( 'options-general.php?page=' . self::SLUG . '&tab=' . $key );
			echo '<a class="nav-tab' . ( $key === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
		match ( $tab ) {
			'policy'      => self::tab_policy(),
			'keys'        => self::tab_keys(),
			'diagnostics' => self::tab_diagnostics(),
			'log'         => self::tab_log(),
			default       => self::tab_settings(),
		};
		echo '</div>';
	}

	// ---- タブ -------------------------------------------------------------

	private static function tab_settings(): void {
		$s = Settings::get();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'ship_content_mcp_save_settings' );
		echo '<input type="hidden" name="action" value="ship_content_mcp_save_settings">';
		echo '<table class="form-table" role="presentation">';
		self::checkbox_row( 'enabled', 'MCP を受け付ける', $s['enabled'], 'オフの間は MCP の入口がありません。' );
		self::checkbox_row( 'disable_default_server', '公式の既定サーバーを止める', $s['disable_default_server'], '既定サーバーは、公開の印が付いたほかの Ability まで出すことがあります。ほかに使う連携がなければ止めてください。' );
		echo '<tr><th scope="row">使える投稿タイプ</th><td>';
		echo '<table class="widefat striped" style="max-width:640px"><thead><tr><th>使う</th><th>投稿タイプ</th><th>状態</th></tr></thead><tbody>';
		foreach ( PostTypes::candidates() as $name => $obj ) {
			$row = $s['post_types'][ $name ] ?? array(
				'enabled' => false,
				'status'  => 'unverified',
			);
			echo '<tr><td><input type="checkbox" name="post_types[' . esc_attr( $name ) . '][enabled]" value="1"' . checked( ! empty( $row['enabled'] ), true, false ) . '></td>';
			echo '<td>' . esc_html( $obj->label ) . ' <code>' . esc_html( $name ) . '</code></td><td><select name="post_types[' . esc_attr( $name ) . '][status]">';
			foreach ( Settings::STATUSES as $value => $label ) {
				echo '<option value="' . esc_attr( $value ) . '"' . selected( $row['status'], $value, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select></td></tr>';
		}
		echo '</tbody></table><p class="description">最初は works だけを使い、実機の試験に合格してから「試験合格」にしてください。下書きの保存（次の段階）は「未検証」では行いません。</p></td></tr>';
		self::checkbox_row( 'reject_privileged', '権限の強いユーザーを拒否する', $s['reject_privileged'], '投稿の編集・公開・アップロード・設定変更などの権限を持つユーザーは、MCP で使えません（推奨）。PM には「' . Consts::ROLE_LABEL . '」のユーザーを使ってください。' );
		self::checkbox_row( 'auth_signature', '端末の署名で認証する', $s['auth_signature'], 'パスワードを通信に乗せません。HTTP の動作確認URLや、Basic 認証のあるサイトでも使えます。' );
		self::checkbox_row( 'auth_app_password', 'Application Password で認証する', $s['auth_app_password'], 'HTTPS のサイトだけで使えます（WordPress の決まり）。' );
		echo '<tr><th scope="row"><label for="scm-ip">接続元の制限</label></th><td><textarea id="scm-ip" name="ip_allowlist" rows="4" cols="40" class="code">' . esc_textarea( implode( "\n", $s['ip_allowlist'] ) ) . '</textarea><p class="description">1行に1つ（IP または CIDR）。空なら制限しません。サーバーが受け取った接続元（REMOTE_ADDR）だけを見ます。</p></td></tr>';
		echo '</table>';
		submit_button( '保存' );
		echo '</form>';
	}

	private static function tab_policy(): void {
		$p     = Policies::get();
		$types = PostTypes::allowed();
		echo '<p>AI は記事を作る前に、この方針を毎回読みます。入力欄の field key と結び付けないので、ACF の構造が変わっても書き直さなくて構いません。「基本〜」は推奨、絶対に守らせたいことは PM の確認や、今後の機能（検証）で扱います。</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'ship_content_mcp_save_policies' );
		echo '<input type="hidden" name="action" value="ship_content_mcp_save_policies">';
		echo '<h2>サイト共通</h2><textarea name="site_policy" rows="6" class="large-text">' . esc_textarea( $p['site']['text'] ) . '</textarea>';
		foreach ( $types as $name ) {
			$obj = get_post_type_object( $name );
			echo '<h2>' . esc_html( $obj ? $obj->label : $name ) . ' <code>' . esc_html( $name ) . '</code></h2>';
			echo '<div style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap">';
			echo '<textarea name="type_policy[' . esc_attr( $name ) . ']" rows="10" class="large-text" style="flex:1 1 420px">' . esc_textarea( $p['types'][ $name ]['text'] ?? '' ) . '</textarea>';
			echo '<details style="flex:1 1 360px;max-height:360px;overflow:auto;background:#fff;border:1px solid #c3c4c7;padding:8px"><summary>この投稿タイプの入力欄（参考・読み取り専用）</summary>';
			self::schema_tree( $name );
			echo '</details></div>';
		}
		echo '<p class="description">版：' . esc_html( (string) Policies::version() ) . '</p>';
		submit_button( '制作方針を保存' );
		echo '</form>';
		if ( array() !== $p['history'] ) {
			echo '<h2>変更の履歴（変更前の文、新しい順）</h2><table class="widefat striped"><thead><tr><th>対象</th><th>変更前の日時</th><th>変更前の文</th></tr></thead><tbody>';
			foreach ( $p['history'] as $h ) {
				echo '<tr><td>' . esc_html( (string) $h['scope'] ) . '</td><td>' . esc_html( $h['modified'] ? wp_date( 'Y-m-d H:i', (int) $h['modified'] ) : '（初期）' ) . '</td><td><div style="white-space:pre-wrap;max-height:8em;overflow:auto">' . esc_html( (string) $h['text'] ) . '</div></td></tr>';
			}
			echo '</tbody></table>';
		}
	}

	private static function tab_keys(): void {
		$endpoint = Server::endpoint();
		echo '<h2>接続の情報（PM の中継プログラムに設定する）</h2><table class="form-table" role="presentation">';
		echo '<tr><th>MCP の入口</th><td><code>' . esc_html( $endpoint ) . '</code></td></tr>';
		echo '<tr><th>サイトの公開鍵</th><td><code style="word-break:break-all">' . esc_html( SiteKey::public_b64() ) . '</code><p class="description">応答が本当にこのサイトから来たかを、中継プログラムが確かめるための鍵です（秘密ではありません）。指紋 ' . esc_html( SiteKey::fingerprint() ) . '</p></td></tr>';
		echo '</table>';

		$new_key = isset( $_GET['scm_key'] ) ? sanitize_key( wp_unslash( $_GET['scm_key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' !== $new_key && DeviceKeys::find( $new_key ) ) {
			$lines = array(
				'SHIP_MCP_URL=' . $endpoint,
				'SHIP_MCP_KEY_ID=' . $new_key,
				'SHIP_MCP_KEY_FILE=（端末の秘密鍵のファイル）',
				'SHIP_MCP_SERVER_KEY=' . SiteKey::public_b64(),
				'SHIP_MCP_BASIC_AUTH=（サーバーの Basic 認証がある場合だけ。ID:パスワード）',
			);
			echo '<div class="notice notice-success inline"><p>登録しました。PM の中継プログラムに、次の値を設定してください（秘密鍵とパスワードは、ここには表示しません）。</p><pre style="white-space:pre-wrap">' . esc_html( implode( "\n", $lines ) ) . '</pre></div>';
		}

		echo '<h2>端末の鍵を登録する</h2>';
		$users = get_users(
			array(
				'capability' => Consts::CAP,
				'number'     => 200,
			)
		);
		if ( array() === $users ) {
			echo '<p>MCP を使えるユーザーがいません。まず「ユーザー → 新規追加」で、権限グループ「' . esc_html( Consts::ROLE_LABEL ) . '」のユーザーを PM ごとに作ってください。</p>';
		} else {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'ship_content_mcp_add_key' );
			echo '<input type="hidden" name="action" value="ship_content_mcp_add_key"><table class="form-table" role="presentation">';
			echo '<tr><th><label for="scm-user">ユーザー</label></th><td><select id="scm-user" name="user_id">';
			foreach ( $users as $u ) {
				$why = AccessGate::user_problem( (int) $u->ID );
				echo '<option value="' . esc_attr( (string) $u->ID ) . '"' . disabled( '' !== $why, true, false ) . '>' . esc_html( $u->display_name . '（' . $u->user_login . '）' . ( '' !== $why ? ' — 使えません：' . $why : '' ) ) . '</option>';
			}
			echo '</select></td></tr>';
			echo '<tr><th><label for="scm-label">端末の名前</label></th><td><input id="scm-label" name="label" class="regular-text" maxlength="100" placeholder="例：PM 山田 MacBook"></td></tr>';
			echo '<tr><th><label for="scm-pub">公開鍵</label></th><td><input id="scm-pub" name="public_key" class="large-text code" maxlength="64" placeholder="中継プログラムの keygen が表示した「公開鍵」"><p class="description">秘密鍵は貼らないでください（受け付けません）。端末ごとに別の鍵を登録します。</p></td></tr>';
			echo '</table>';
			submit_button( '登録する' );
			echo '</form>';
		}

		echo '<h2>登録済みの鍵</h2><table class="widefat striped"><thead><tr><th>鍵 ID</th><th>ユーザー</th><th>端末</th><th>登録</th><th>最後に使った日時</th><th>状態</th><th></th></tr></thead><tbody>';
		foreach ( DeviceKeys::all() as $row ) {
			$user = get_userdata( (int) $row['user_id'] );
			echo '<tr><td><code>' . esc_html( (string) $row['key_id'] ) . '</code></td><td>' . esc_html( $user ? $user->user_login : '（削除されたユーザー）' ) . '</td><td>' . esc_html( (string) $row['label'] ) . '</td>';
			echo '<td>' . esc_html( self::local_time( (string) $row['created_at'] ) ) . '</td><td>' . esc_html( self::local_time( (string) ( $row['last_used_at'] ?? '' ) ) ) . '</td>';
			echo '<td>' . ( empty( $row['revoked_at'] ) ? '有効' : '止めた（' . esc_html( self::local_time( (string) $row['revoked_at'] ) ) . '）' ) . '</td><td>';
			if ( empty( $row['revoked_at'] ) ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'この鍵を止めます。この端末からは接続できなくなります。\');">';
				wp_nonce_field( 'ship_content_mcp_revoke_key' );
				echo '<input type="hidden" name="action" value="ship_content_mcp_revoke_key"><input type="hidden" name="key_id" value="' . esc_attr( (string) $row['key_id'] ) . '">';
				submit_button( '止める', 'delete small', 'submit', false );
				echo '</form>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function tab_diagnostics(): void {
		$labels = array(
			'ok'   => 'OK',
			'warn' => '要確認',
			'ng'   => '問題あり',
			'info' => '情報',
		);
		echo '<p>表示するだけで、何も書き込みません。</p><table class="widefat striped"><thead><tr><th>項目</th><th>結果</th><th>内容</th></tr></thead><tbody>';
		foreach ( Diagnostics::collect() as $row ) {
			echo '<tr><td>' . esc_html( $row['label'] ) . '</td><td>' . esc_html( $labels[ $row['status'] ] ?? $row['status'] ) . '</td><td>' . esc_html( $row['detail'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function tab_log(): void {
		echo '<p>MCP の接続・ツールの実行・認証の失敗の記録です（本文・画像・パスワードは記録しません。90日で消えます）。</p>';
		echo '<table class="widefat striped"><thead><tr><th>日時</th><th>ユーザー</th><th>認証</th><th>鍵</th><th>種類</th><th>ツール</th><th>対象</th><th>結果</th><th>コード</th><th>理由</th><th>接続元</th><th>相関 ID</th></tr></thead><tbody>';
		foreach ( AuditLog::recent( 200 ) as $r ) {
			$user = (int) $r['user_id'] > 0 ? get_userdata( (int) $r['user_id'] ) : null;
			echo '<tr><td>' . esc_html( self::local_time( (string) $r['created_at'] ) ) . '</td><td>' . esc_html( $user ? $user->user_login : ( (int) $r['user_id'] > 0 ? '#' . $r['user_id'] : '-' ) ) . '</td>';
			foreach ( array( 'auth', 'key_id', 'event', 'tool' ) as $k ) {
				echo '<td>' . esc_html( (string) $r[ $k ] ) . '</td>';
			}
			echo '<td>' . esc_html( trim( (string) $r['post_type'] . ( (int) $r['post_id'] > 0 ? ' #' . $r['post_id'] : '' ) ) ) . '</td>';
			foreach ( array( 'result', 'code', 'detail', 'ip', 'request_id' ) as $k ) {
				echo '<td>' . esc_html( (string) $r[ $k ] ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	// ---- 保存 -------------------------------------------------------------

	public static function handle_save_settings(): void {
		self::guard( 'ship_content_mcp_save_settings' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- guard() で確認済み
		$in = array(
			'post_types'   => array(),
			'ip_allowlist' => preg_split( '/\R/', (string) wp_unslash( $_POST['ip_allowlist'] ?? '' ) ),
		);
		foreach ( array( 'enabled', 'disable_default_server', 'reject_privileged', 'auth_signature', 'auth_app_password' ) as $k ) {
			$in[ $k ] = ! empty( $_POST[ $k ] );
		}
		$candidates = PostTypes::candidates();
		$posted     = isset( $_POST['post_types'] ) && is_array( $_POST['post_types'] ) ? wp_unslash( $_POST['post_types'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Settings::sanitize で検証
		// phpcs:enable
		foreach ( array_keys( $candidates ) as $name ) {
			$row                       = is_array( $posted[ $name ] ?? null ) ? $posted[ $name ] : array();
			$in['post_types'][ $name ] = array(
				'enabled' => ! empty( $row['enabled'] ),
				'status'  => (string) ( $row['status'] ?? 'unverified' ),
			);
		}
		$before = Settings::get();
		$after  = Settings::save( $in );
		AuditLog::write( 'admin', 'ok', '', 'settings saved' . ( $before['enabled'] !== $after['enabled'] ? ( $after['enabled'] ? ' (enabled)' : ' (disabled)' ) : '' ) );
		self::back( 'settings', 'saved' );
	}

	public static function handle_save_policies(): void {
		self::guard( 'ship_content_mcp_save_policies' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- guard() で確認済み
		$site  = (string) wp_unslash( $_POST['site_policy'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Policies::save で無害化
		$types = array();
		$raw   = isset( $_POST['type_policy'] ) && is_array( $_POST['type_policy'] ) ? wp_unslash( $_POST['type_policy'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		// phpcs:enable
		foreach ( PostTypes::allowed() as $name ) {
			if ( isset( $raw[ $name ] ) ) {
				$types[ $name ] = (string) $raw[ $name ];
			}
		}
		$changed = Policies::save( $site, $types, get_current_user_id() );
		if ( $changed ) {
			AuditLog::write( 'admin', 'ok', '', 'policies saved' );
		}
		self::back( 'policy', $changed ? 'policies_saved' : 'unchanged' );
	}

	public static function handle_add_key(): void {
		self::guard( 'ship_content_mcp_add_key' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- guard() で確認済み
		$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		$label   = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
		$pub     = isset( $_POST['public_key'] ) ? sanitize_text_field( wp_unslash( $_POST['public_key'] ) ) : '';
		// phpcs:enable
		if ( '' !== AccessGate::user_problem( $user_id ) ) {
			self::back( 'keys', 'error', 'このユーザーは MCP を使えません（専用の権限がない、または権限が強すぎます）' );
		}
		try {
			$key_id = DeviceKeys::add( $user_id, $label, $pub, get_current_user_id() );
		} catch ( \InvalidArgumentException $e ) {
			self::back( 'keys', 'error', $e->getMessage() );
		}
		AuditLog::write( 'admin', 'ok', '', 'key added for user #' . $user_id, array( 'key_id' => $key_id ) );
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::SLUG . '&tab=keys&scm_notice=key_added&scm_key=' . rawurlencode( $key_id ) ) );
		exit;
	}

	public static function handle_revoke_key(): void {
		self::guard( 'ship_content_mcp_revoke_key' );
		$key_id = isset( $_POST['key_id'] ) ? sanitize_key( wp_unslash( $_POST['key_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() で確認済み
		$ok     = DeviceKeys::revoke( $key_id, get_current_user_id() );
		AuditLog::write( 'admin', $ok ? 'ok' : 'error', '', 'key revoked', array( 'key_id' => $key_id ) );
		self::back( 'keys', $ok ? 'key_revoked' : 'error', $ok ? '' : '止められませんでした（すでに止めた鍵の可能性があります）' );
	}

	// ---- 部品 -------------------------------------------------------------

	private static function schema_tree( string $post_type ): void {
		try {
			$out = ( new SchemaReader() )->read( $post_type );
		} catch ( \Throwable $e ) {
			echo '<p>読めませんでした（' . esc_html( $e->getMessage() ) . '）</p>';
			return;
		}
		foreach ( $out['warnings'] as $w ) {
			echo '<p class="description">' . esc_html( $w ) . '</p>';
		}
		foreach ( $out['data']['field_groups'] as $g ) {
			echo '<p><strong>' . esc_html( $g['title'] ) . '</strong> <code>' . esc_html( $g['key'] ) . '</code></p><ul style="margin-left:1em;list-style:disc">';
			self::fields_tree( $g['fields'] );
			echo '</ul>';
		}
	}

	/**
	 * @param list<array<string,mixed>> $fields
	 */
	private static function fields_tree( array $fields ): void {
		foreach ( $fields as $f ) {
			$extra = isset( $f['choices'] ) ? '：' . implode( ' / ', array_map( static fn( $c ) => $c['label'], array_slice( $f['choices'], 0, 6 ) ) ) . ( count( $f['choices'] ) > 6 ? ' …' : '' ) : '';
			echo '<li>' . esc_html( $f['label'] ) . ' <code>' . esc_html( $f['type'] ) . '</code>' . esc_html( $extra );
			if ( ! empty( $f['sub_fields'] ) ) {
				echo '<ul style="margin-left:1em;list-style:circle">';
				self::fields_tree( $f['sub_fields'] );
				echo '</ul>';
			}
			if ( ! empty( $f['layouts'] ) ) {
				echo '<ul style="margin-left:1em;list-style:square">';
				foreach ( $f['layouts'] as $l ) {
					echo '<li>レイアウト：' . esc_html( $l['label'] ) . '<ul style="margin-left:1em;list-style:circle">';
					self::fields_tree( $l['sub_fields'] );
					echo '</ul></li>';
				}
				echo '</ul>';
			}
			echo '</li>';
		}
	}

	private static function checkbox_row( string $name, string $label, bool $checked, string $help ): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><label><input type="checkbox" name="' . esc_attr( $name ) . '" value="1"' . checked( $checked, true, false ) . '> 有効</label><p class="description">' . esc_html( $help ) . '</p></td></tr>';
	}

	private static function guard( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'この操作をする権限がありません。' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	private static function back( string $tab, string $notice, string $message = '' ): void {
		if ( '' !== $message ) {
			set_transient( 'ship_content_mcp_notice_' . get_current_user_id(), $message, 60 );
		}
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::SLUG . '&tab=' . $tab . '&scm_notice=' . rawurlencode( $notice ) ) );
		exit;
	}

	private static function notice(): void {
		$code = isset( $_GET['scm_notice'] ) ? sanitize_key( wp_unslash( $_GET['scm_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$map  = array(
			'saved'          => array( 'success', '保存しました。' ),
			'policies_saved' => array( 'success', '制作方針を保存しました（版が上がりました）。' ),
			'unchanged'      => array( 'info', '変更はありませんでした。' ),
			'key_added'      => array( 'success', '端末の鍵を登録しました。' ),
			'key_revoked'    => array( 'success', '鍵を止めました。' ),
		);
		if ( 'error' === $code ) {
			$msg = (string) get_transient( 'ship_content_mcp_notice_' . get_current_user_id() );
			delete_transient( 'ship_content_mcp_notice_' . get_current_user_id() );
			echo '<div class="notice notice-error"><p>' . esc_html( '' !== $msg ? $msg : 'できませんでした。' ) . '</p></div>';
		} elseif ( isset( $map[ $code ] ) && 'key_added' !== $code ) {
			echo '<div class="notice notice-' . esc_attr( $map[ $code ][0] ) . '"><p>' . esc_html( $map[ $code ][1] ) . '</p></div>';
		}
	}

	private static function local_time( string $gmt ): string {
		if ( '' === $gmt ) {
			return '-';
		}
		$ts = strtotime( $gmt . ' UTC' );
		return false === $ts ? $gmt : wp_date( 'Y-m-d H:i', $ts );
	}
}
