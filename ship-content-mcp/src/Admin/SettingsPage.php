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
use ShipContentMcp\Content\StructureLock;
use ShipContentMcp\Core\Consts;
use ShipContentMcp\Core\Plugin;
use ShipContentMcp\Core\Settings;
use ShipContentMcp\MCP\Server;
use ShipContentMcp\Policy\Policies;
use ShipContentMcp\Security\AccessGate;
use ShipContentMcp\Security\AuditLog;
use ShipContentMcp\Security\ConnectionCode;
use ShipContentMcp\Security\DeviceKeys;
use ShipContentMcp\Security\SiteKey;

defined( 'ABSPATH' ) || exit;

final class SettingsPage {

	public const SLUG = 'ship-content-mcp';

	private const TABS = array(
		'settings'    => '基本設定',
		'policy'      => '制作方針',
		'keys'        => '接続',
		'diagnostics' => '診断',
		'log'         => '記録',
	);

	public static function hooks(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		foreach ( array( 'save_settings', 'save_policies', 'add_key', 'revoke_key', 'prelaunch' ) as $action ) {
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
		echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>使う</th><th>投稿タイプ</th><th>状態</th><th>確かめた構造</th></tr></thead><tbody>';
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
			echo '</select></td><td>' . self::verified_cell( $name, $row ) . '</td></tr>';
		}
		echo '</tbody></table><p class="description">最初は works だけを使います。「未検証」は読み取りだけ。「試験中」で下書きの保存を試し（作った下書きに試験の印が付き、WP-CLI の cleanup-tests で片付けられます）、実機の試験に合格したら「試験合格」にしてください。「試験合格」「稼働」にしたときの入力欄（ACF）の構造を記録し、その後に構造が変わったら、確かめ直すまで書き込みを止めます。</p></td></tr>';
		self::checkbox_row( 'reject_privileged', '権限の強いユーザーを拒否する', $s['reject_privileged'], '投稿の編集・公開・アップロード・設定変更などの権限を持つユーザーは、MCP で使えません（推奨）。PM には「' . Consts::ROLE_LABEL . '」のユーザーを使ってください。' );
		self::checkbox_row( 'auth_token', 'トークンで認証する', $s['auth_token'], '「接続」タブで発行したトークンを、Claude Code・Codex などの MCP の設定に入れるだけでつながります（いちばん簡単）。' );
		self::checkbox_row( 'token_http', 'トークンを HTTP でも受け付ける', $s['token_http'], '公開前の確認用 URL（HTTP）で使うためです。HTTP ではトークンが暗号化されずに流れるので、公開するときはこのプラグインごと外してください（「接続」タブの「公開の前に片付ける」）。' );
		self::checkbox_row( 'auth_signature', '端末の署名で認証する', $s['auth_signature'], 'パスワードを通信に乗せません。HTTP の動作確認URLや、Basic 認証のあるサイトでも使えます。' );
		self::checkbox_row( 'auth_app_password', 'Application Password で認証する', $s['auth_app_password'], 'HTTPS のサイトだけで使えます（WordPress の決まり）。' );
		echo '<tr><th scope="row"><label for="scm-max">写真の上限</label></th><td><input id="scm-max" type="number" name="media_max_mb" min="1" max="100" value="' . esc_attr( (string) $s['media_max_mb'] ) . '" class="small-text"> MB<p class="description">写真は JPEG・PNG・WebP だけ受け付けます（URL からの取得はしません）。</p></td></tr>';
		self::checkbox_row( 'strip_metadata', '写真の位置情報などを消す', $s['strip_metadata'], '撮影場所（GPS）・カメラの情報などのメタデータを、登録の前に消します（向きは写真に反映してから消します）。お客様の住所が分かってしまうのを防ぐため、有効のままにしてください。' );
		echo '<tr><th scope="row">メディアの公開範囲</th><td><p class="description" style="color:#b32d2e">' . esc_html( \ShipContentMcp\Media\UploadService::PUBLIC_NOTICE ) . '</p></td></tr>';
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
			echo '<h2>変更の履歴（変更前の文、新しい順）</h2><table class="widefat striped"><thead><tr><th>対象</th><th>その文を保存した日時・人</th><th>変更前の文</th></tr></thead><tbody>';
			foreach ( $p['history'] as $h ) {
				$who = ! empty( $h['modified_by'] ) ? get_userdata( (int) $h['modified_by'] ) : null;
				echo '<tr><td>' . esc_html( (string) $h['scope'] ) . '</td><td>' . esc_html( ( $h['modified'] ? wp_date( 'Y-m-d H:i', (int) $h['modified'] ) : '（初期）' ) . ( $who ? '・' . $who->display_name : '' ) ) . '</td><td><div style="white-space:pre-wrap;max-height:8em;overflow:auto">' . esc_html( (string) $h['text'] ) . '</div></td></tr>';
			}
			echo '</tbody></table>';
		}
		foreach ( $types as $name ) {
			$out = Policies::for_output( $name );
			echo '<details style="margin-top:12px"><summary>AI が読む内容の確認（' . esc_html( $name ) . '）</summary><pre style="white-space:pre-wrap;background:#fff;border:1px solid #c3c4c7;padding:8px;max-height:320px;overflow:auto">' . esc_html( (string) wp_json_encode( $out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) ) . '</pre></details>';
		}
	}

	private static function tab_keys(): void {
		$endpoint = Server::endpoint();
		$users    = get_users(
			array(
				'capability' => Consts::CAP,
				'number'     => 200,
			)
		);

		self::token_section( $users, $endpoint );

		echo '<h2>発行したトークン・鍵</h2><table class="widefat striped"><thead><tr><th>種類</th><th>鍵 ID</th><th>ユーザー</th><th>名前</th><th>発行</th><th>最後に使った日時</th><th>状態</th><th></th></tr></thead><tbody>';
		foreach ( DeviceKeys::all() as $row ) {
			$user = get_userdata( (int) $row['user_id'] );
			echo '<tr><td>' . ( DeviceKeys::is_token( $row ) ? 'トークン' : '端末の鍵' ) . '</td><td><code>' . esc_html( (string) $row['key_id'] ) . '</code></td><td>' . esc_html( $user ? $user->user_login : '（削除されたユーザー）' ) . '</td><td>' . esc_html( (string) $row['label'] ) . '</td>';
			echo '<td>' . esc_html( self::local_time( (string) $row['created_at'] ) ) . '</td><td>' . esc_html( self::local_time( (string) ( $row['last_used_at'] ?? '' ) ) ) . '</td>';
			echo '<td>' . ( empty( $row['revoked_at'] ) ? '有効' : '止めた（' . esc_html( self::local_time( (string) $row['revoked_at'] ) ) . '）' ) . '</td><td>';
			if ( empty( $row['revoked_at'] ) ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'止めます。これを使っている AI からは接続できなくなります。\');">';
				wp_nonce_field( 'ship_content_mcp_revoke_key' );
				echo '<input type="hidden" name="action" value="ship_content_mcp_revoke_key"><input type="hidden" name="key_id" value="' . esc_attr( (string) $row['key_id'] ) . '">';
				submit_button( '止める', 'delete small', 'submit', false );
				echo '</form>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<h2>公開の前に片付ける</h2>';
		echo '<p>この仕組みは、公開前（制作中）に使う前提です。サイトを公開するときは、次の2つを行ってください。</p><ol><li>下の「すべて止めて、MCP を止める」を押す（発行したトークン・鍵をすべて止め、MCP の入口をなくします）。</li><li>「プラグイン」の画面で「Ship Content MCP」と「MCP Adapter」を無効にして削除する（このプラグインの設定・記録も消えます。作った下書きと写真は残ります）。</li></ol>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'発行したトークン・鍵をすべて止め、MCP を止めます。よろしいですか？\');">';
		wp_nonce_field( 'ship_content_mcp_prelaunch' );
		echo '<input type="hidden" name="action" value="ship_content_mcp_prelaunch">';
		submit_button( 'すべて止めて、MCP を止める', 'delete', 'submit', false );
		echo ' <a class="button" href="' . esc_url( admin_url( 'plugins.php' ) ) . '">プラグインの画面へ</a></form>';

		echo '<details style="margin-top:2em"><summary style="cursor:pointer;font-weight:600">ほかのつなぎ方（接続コード・端末の公開鍵。ふだんは使いません）</summary>';
		echo '<h3>接続の情報（中継プログラムに設定する）</h3><table class="form-table" role="presentation">';
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
		self::issue_section( $users, $endpoint );
		echo '<h3>端末の公開鍵を登録する（鍵を PM のパソコンで作る方式）</h3>';
		echo '<p class="description">PM が中継プログラムの keygen で鍵を作り、公開鍵を送ってくる場合に使います。秘密が PM のパソコンから一度も出ない、いちばん堅い方式です。</p>';
		if ( array() !== $users ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'ship_content_mcp_add_key' );
			echo '<input type="hidden" name="action" value="ship_content_mcp_add_key"><table class="form-table" role="presentation">';
			echo '<tr><th><label for="scm-user">ユーザー</label></th><td>' . self::user_select( 'scm-user', 'user_id', $users, false ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- user_select でエスケープ済み
			echo '<tr><th><label for="scm-label">端末の名前</label></th><td><input id="scm-label" name="label" class="regular-text" maxlength="100" placeholder="例：PM 山田 MacBook"></td></tr>';
			echo '<tr><th><label for="scm-pub">公開鍵</label></th><td><input id="scm-pub" name="public_key" class="large-text code" maxlength="64" placeholder="中継プログラムの keygen が表示した「公開鍵」"><p class="description">秘密鍵は貼らないでください（受け付けません）。端末ごとに別の鍵を登録します。</p></td></tr>';
			echo '</table>';
			submit_button( '登録する' );
			echo '</form>';
		}
		echo '</details>';
	}

	/**
	 * @param list<\WP_User> $users
	 */
	private static function user_select( string $id, string $name, array $users, bool $offer_new ): string {
		$out = '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';
		if ( $offer_new ) {
			$out .= '<option value="new"' . selected( array() === $users, true, false ) . '>（MCP 用のユーザーを新しく作る）</option>';
		}
		foreach ( $users as $u ) {
			$why  = AccessGate::user_problem( (int) $u->ID );
			$out .= '<option value="' . esc_attr( (string) $u->ID ) . '"' . disabled( '' !== $why, true, false ) . '>' . esc_html( $u->display_name . '（' . $u->user_login . '）' . ( '' !== $why ? ' — 使えません：' . $why : '' ) ) . '</option>';
		}
		return $out . '</select>';
	}

	/** MCP 用のユーザー（権限グループ Content MCP Operator、ログインしない）を作る。 */
	private static function create_operator_user(): int {
		$login = 'mcp-operator';
		for ( $i = 2; username_exists( $login ) && $i < 100; $i++ ) {
			$login = 'mcp-operator-' . $i;
		}
		$id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_pass'    => wp_generate_password( 40, true, true ),
				'display_name' => 'MCP 用ユーザー',
				'role'         => Consts::ROLE,
			)
		);
		if ( is_wp_error( $id ) ) {
			throw new \InvalidArgumentException( 'MCP 用のユーザーを作れませんでした（' . $id->get_error_message() . '）' );
		}
		AuditLog::write( 'admin', 'ok', '', 'operator user created: ' . $login );
		return (int) $id;
	}

	/**
	 * トークンの発行（フォームと、発行した直後の1回だけの表示）。秘密を含むので、POST の応答にだけ出す。
	 *
	 * @param list<\WP_User> $users
	 */
	private static function token_section( array $users, string $endpoint ): void {
		echo '<h2>トークンを発行する</h2>';
		echo '<p>発行すると、PM が自分の AI（Claude Code・Codex など）にそのまま貼るだけの文が出ます。AI が自分で MCP の設定に登録します。デモサイトで発行しておけば、デモサイトからコピーしたサイトでも、同じトークンが使えます（入口の URL だけサイトごとに変えます）。トークンは合鍵と同じです。漏れたら下の一覧で「止める」を押してください。</p>';

		$result = null;
		$error  = '';
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['scm_token'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- 下の guard で確認
			self::guard( 'ship_content_mcp_issue_token' );
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- guard() で確認済み
			$once_token = isset( $_POST['issue_token'] ) ? sanitize_key( wp_unslash( $_POST['issue_token'] ) ) : '';
			$who        = isset( $_POST['user_id'] ) ? sanitize_key( wp_unslash( $_POST['user_id'] ) ) : '';
			$label      = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
			$url        = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ), array( 'http', 'https' ) ) : '';
			$basic      = isset( $_POST['basic_auth'] ) ? trim( (string) wp_unslash( $_POST['basic_auth'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- パスワードは文字を変えない（形は下で確かめる）
			// phpcs:enable
			$once = 'ship_content_mcp_issued_' . md5( 'token' . $once_token );
			$norm = ConnectionCode::normalize_url( '' === trim( $url ) ? $endpoint : $url, $endpoint );
			if ( '' === $once_token || false !== get_transient( $once ) ) {
				$error = 'このフォームからは発行済みです（再読み込みでは発行しません）。もう一度発行するときは、「接続」タブを開き直してください。';
			} elseif ( '' === $norm ) {
				$error = '入口の URL が違います（http:// か https:// で始め、「/wp-json/…」の部分は変えないでください）';
			} elseif ( ! ConnectionCode::valid_basic_auth( $basic ) ) {
				$error = 'Basic 認証は「ID:パスワード」の形で入れてください';
			} else {
				set_transient( $once, 1, DAY_IN_SECONDS );
				try {
					$user_id = 'new' === $who ? self::create_operator_user() : absint( $who );
					if ( '' !== AccessGate::user_problem( $user_id ) ) {
						throw new \InvalidArgumentException( 'このユーザーは MCP を使えません（専用の権限がない、または権限が強すぎます）' );
					}
					$issued = DeviceKeys::add_token( $user_id, $label, get_current_user_id() );
					AuditLog::write( 'admin', 'ok', '', 'token issued for user #' . $user_id, array( 'key_id' => $issued['key_id'] ) );
					$result = $issued + array(
						'url'   => $norm,
						'name'  => 'ship-' . ConnectionCode::default_name( $norm ),
						'basic' => $basic,
					);
				} catch ( \InvalidArgumentException $e ) {
					$error = $e->getMessage();
				}
			}
		}
		if ( '' !== $error ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $error ) . '</p></div>';
		}
		if ( is_array( $result ) ) {
			$cmd = ConnectionCode::client_configs( $result['name'], $result['url'], $result['token'], $result['basic'] );
			echo '<div class="notice notice-success inline" style="padding:12px 16px">';
			echo '<p><strong>発行しました（鍵 ID ' . esc_html( $result['key_id'] ) . '）。この画面を閉じると、トークンは二度と表示されません。</strong>控えが要るときは、パスワード管理ソフトに入れてください。</p>';
			$copy = static function ( string $id, string $title, string $text, int $rows ): void {
				echo '<p style="margin:14px 0 4px"><strong>' . esc_html( $title ) . '</strong></p>';
				echo '<textarea id="' . esc_attr( $id ) . '" class="large-text code" rows="' . (int) $rows . '" readonly onclick="this.select()">' . esc_textarea( $text ) . '</textarea>';
				echo '<button type="button" class="button" onclick="var t=document.getElementById(\'' . esc_attr( $id ) . '\');t.select();if(navigator.clipboard){navigator.clipboard.writeText(t.value);}else{document.execCommand(\'copy\');}this.textContent=\'コピーしました\';">コピー</button>';
			};
			$copy( 'scm-t-prompt', 'AI に貼る文（Claude Code・Codex など）：これを PM に送り、PM は自分の AI にそのまま貼るだけです', $cmd['prompt'], 14 );
			if ( DesktopExtension::available() ) {
				echo '<p style="margin:14px 0 4px"><strong>Claude デスクトップアプリ（チャット）を使う PM</strong>：このサイト用の拡張機能のファイルと、下のトークンを送ります。PM はファイルを開いて、トークンを貼ります（ファイルにトークンは入っていません）。</p>';
				echo DesktopExtension::form( $result['url'], $result['basic'], '拡張機能（' . $result['name'] . '.mcpb）をダウンロード' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- form() でエスケープ済み
			}
			$copy( 'scm-t-token', 'トークン', $result['token'], 1 );
			echo '<details style="margin-top:12px"><summary style="cursor:pointer">AI に頼まず、自分で設定するとき（コマンド）</summary>';
			$copy( 'scm-t-claude', 'Claude Code：ターミナルで実行', $cmd['claude_code'], 3 );
			$copy( 'scm-t-codex', 'Codex：ターミナルで実行（~/.codex/config.toml に足します）', $cmd['codex'], 6 );
			$copy( 'scm-t-json', 'ほかの AI（mcpServers の形の設定。Cursor など）', $cmd['json'], 9 );
			echo '</details>';
			echo '<p class="description" style="margin-top:12px">デモサイトからコピーしたサイトで使うときは、上の文の名前と URL を、そのサイトのものに変えるだけです（トークンは同じ）。PM が AI に「同じトークンで、この URL の MCP をもう1つ足して」と頼んでもかまいません。</p>';
			echo '</div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'options-general.php?page=' . self::SLUG . '&tab=keys' ) ) . '" autocomplete="off">';
		wp_nonce_field( 'ship_content_mcp_issue_token' );
		echo '<input type="hidden" name="scm_token" value="1"><input type="hidden" name="issue_token" value="' . esc_attr( strtolower( wp_generate_password( 24, false ) ) ) . '">';
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th><label for="scm-token-user">ユーザー</label></th><td>' . self::user_select( 'scm-token-user', 'user_id', $users, true ) . '<p class="description">トークンで作った下書きは、このユーザーが作ったものになります。ふつうは「MCP 用のユーザー」1人で足ります。</p></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- user_select でエスケープ済み
		echo '<tr><th><label for="scm-token-label">名前</label></th><td><input id="scm-token-label" name="label" class="regular-text" maxlength="100" value="制作チーム"><p class="description">あとで「止める」ときに見分けるための名前です。</p></td></tr>';
		echo '<tr><th><label for="scm-token-url">入口の URL</label></th><td><input id="scm-token-url" name="url" class="large-text code" value="' . esc_attr( $endpoint ) . '"><p class="description">コマンドに入れる入口です。https:// の入口があれば https:// に変えてもかまいません。「/wp-json/…」の部分は変えないでください。</p></td></tr>';
		echo '<tr><th><label for="scm-token-basic">Basic 認証（ある場合だけ）</label></th><td><input id="scm-token-basic" name="basic_auth" class="regular-text code" autocomplete="off" placeholder="ID:パスワード"><p class="description">公開前で、サーバーの Basic 認証がかかっているサイトだけ。コマンドに入れるためだけに使い、サーバーには保存しません。</p></td></tr>';
		echo '</table>';
		submit_button( 'トークンを発行する', 'primary', 'submit', true );
		echo '</form><hr>';
	}

	private static function tab_diagnostics(): void {
		$labels = array(
			'ok'   => 'OK',
			'warn' => '要確認',
			'ng'   => '問題あり',
			'info' => '情報',
		);
		echo '<p>表示するだけで、何も書き込みません。この画面は管理者だけが見られます（PM には、エラーのコードと短い説明だけが伝わります）。</p><table class="widefat striped"><thead><tr><th>項目</th><th>結果</th><th>内容</th><th>対処</th></tr></thead><tbody>';
		foreach ( Diagnostics::collect() as $row ) {
			$color = 'ng' === $row['status'] ? ' style="color:#b32d2e;font-weight:600"' : ( 'warn' === $row['status'] ? ' style="color:#996800;font-weight:600"' : '' );
			echo '<tr><td>' . esc_html( $row['label'] ) . '</td><td' . $color . '>' . esc_html( $labels[ $row['status'] ] ?? $row['status'] ) . '</td><td>' . esc_html( $row['detail'] ) . '</td><td>' . esc_html( $row['remedy'] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $color は固定の文字列
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
		foreach ( array( 'enabled', 'disable_default_server', 'reject_privileged', 'auth_token', 'token_http', 'auth_signature', 'auth_app_password', 'strip_metadata' ) as $k ) {
			$in[ $k ] = ! empty( $_POST[ $k ] );
		}
		$in['media_max_mb'] = isset( $_POST['media_max_mb'] ) ? absint( $_POST['media_max_mb'] ) : 20;
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
		$before  = Settings::get();
		$reverify = isset( $_POST['reverify'] ) && is_array( $_POST['reverify'] ) ? array_map( 'sanitize_key', array_keys( wp_unslash( $_POST['reverify'] ) ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput -- guard() で確認済み・キーだけ使う
		foreach ( $in['post_types'] as $name => $row ) {
			$in['post_types'][ $name ]['verified'] = StructureLock::next_verified( $name, (string) $row['status'], $before, in_array( $name, $reverify, true ) );
		}
		$after = Settings::save( $in );
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

	public static function handle_prelaunch(): void {
		self::guard( 'ship_content_mcp_prelaunch' );
		$count = DeviceKeys::revoke_all( get_current_user_id() );
		$s            = Settings::get();
		$s['enabled'] = false;
		Settings::save( $s );
		AuditLog::write( 'admin', 'ok', '', 'prelaunch cleanup: revoked ' . $count . ', disabled' );
		set_transient( 'ship_content_mcp_notice_' . get_current_user_id(), 'トークン・鍵を ' . $count . ' 件止め、MCP を止めました。続けて「プラグイン」の画面で「Ship Content MCP」と「MCP Adapter」を無効にして削除してください。', 60 );
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::SLUG . '&tab=keys&scm_notice=prelaunch' ) );
		exit;
	}

	public static function handle_revoke_key(): void {
		self::guard( 'ship_content_mcp_revoke_key' );
		$key_id = isset( $_POST['key_id'] ) ? sanitize_key( wp_unslash( $_POST['key_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() で確認済み
		$ok     = DeviceKeys::revoke( $key_id, get_current_user_id() );
		AuditLog::write( 'admin', $ok ? 'ok' : 'error', '', 'key revoked', array( 'key_id' => $key_id ) );
		self::back( 'keys', $ok ? 'key_revoked' : 'error', $ok ? '' : '止められませんでした（すでに止めた鍵の可能性があります）' );
	}

	// ---- 接続コード ---------------------------------------------------------

	/**
	 * 接続コードの発行（フォームと、発行した直後の1回だけの表示）。
	 *
	 * コードは秘密を含むので、保存も転送もしない：この画面への POST の応答にだけ出す。
	 * 再読み込みで二重に発行しないよう、フォームごとの使い捨ての印を持つ。
	 *
	 * @param list<\WP_User> $users
	 */
	private static function issue_section( array $users, string $endpoint ): void {
		echo '<h3>接続コードを発行する（中継プログラムの setup 用）</h3>';
		echo '<p>PM のパソコン1台につき1つ発行します。中継プログラム（setup --clipboard）で設定します。コードは合鍵と同じです（持っている人は、その PM として下書きを作れます）。漏れたら下の一覧で「止める」を押してください。</p>';

		$result = null;
		$error  = '';
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['scm_issue'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- 下の guard で確認
			self::guard( 'ship_content_mcp_issue_code' );
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- guard() で確認済み
			$token   = isset( $_POST['issue_token'] ) ? sanitize_key( wp_unslash( $_POST['issue_token'] ) ) : '';
			$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
			$label   = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
			$url     = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ), array( 'http', 'https' ) ) : '';
			$basic   = isset( $_POST['basic_auth'] ) ? trim( (string) wp_unslash( $_POST['basic_auth'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- パスワードは文字を変えない（形は ConnectionCode で確かめる）
			// phpcs:enable
			$once = 'ship_content_mcp_issued_' . md5( $token );
			if ( '' === $token || false !== get_transient( $once ) ) {
				$error = 'このフォームからは発行済みです（再読み込みでは発行しません）。もう一度発行するときは、「端末の鍵」タブを開き直してください。';
			} elseif ( '' !== AccessGate::user_problem( $user_id ) ) {
				$error = 'このユーザーは MCP を使えません（専用の権限がない、または権限が強すぎます）';
			} else {
				set_transient( $once, 1, DAY_IN_SECONDS );
				try {
					$result = ConnectionCode::issue( $user_id, $label, $url, $basic, get_current_user_id() );
					AuditLog::write( 'admin', 'ok', '', 'connection code issued for user #' . $user_id, array( 'key_id' => $result['key_id'] ) );
				} catch ( \InvalidArgumentException $e ) {
					$error = $e->getMessage();
				}
			}
		}

		if ( '' !== $error ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $error ) . '</p></div>';
		}
		if ( is_array( $result ) ) {
			$message = $result['site'] . " の接続コードです。中継プログラムの setup で設定してください。\n"
				. "このコードは合鍵と同じです。ほかの人に渡したり、共有のチャンネルに貼ったりしないでください。\n"
				. $result['code'];
			echo '<div class="notice notice-success inline" style="padding:12px 16px">';
			echo '<p><strong>発行しました（鍵 ID ' . esc_html( $result['key_id'] ) . '）。この画面を閉じると、コードは二度と表示されません。</strong></p>';
			echo '<p>次の文を、そのまま PM に DM で送ってください（PM は文ごとコピーしても、コードだけコピーしても使えます）。</p>';
			echo '<textarea id="scm-issued" class="large-text code" rows="5" readonly onclick="this.select()">' . esc_textarea( $message ) . '</textarea>';
			echo '<p><button type="button" class="button button-primary" onclick="var t=document.getElementById(\'scm-issued\');t.select();if(navigator.clipboard){navigator.clipboard.writeText(t.value);}else{document.execCommand(\'copy\');}this.textContent=\'コピーしました\';">送る文をコピー</button></p>';
			echo '<p class="description">入口：' . esc_html( $result['url'] ) . '　／　AI への登録の名前：ship-content-' . esc_html( $result['name'] ) . '</p>';
			echo '</div>';
		}

		if ( array() === $users ) {
			echo '<p>MCP を使えるユーザーがいません。まず「ユーザー → 新規追加」で、権限グループ「' . esc_html( Consts::ROLE_LABEL ) . '」のユーザーを PM ごとに作ってください。</p>';
			return;
		}
		$action = admin_url( 'options-general.php?page=' . self::SLUG . '&tab=keys' );
		echo '<form method="post" action="' . esc_url( $action ) . '" autocomplete="off">';
		wp_nonce_field( 'ship_content_mcp_issue_code' );
		echo '<input type="hidden" name="scm_issue" value="1"><input type="hidden" name="issue_token" value="' . esc_attr( strtolower( wp_generate_password( 24, false ) ) ) . '">';
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th><label for="scm-issue-user">ユーザー</label></th><td><select id="scm-issue-user" name="user_id">';
		foreach ( $users as $u ) {
			$why = AccessGate::user_problem( (int) $u->ID );
			echo '<option value="' . esc_attr( (string) $u->ID ) . '"' . disabled( '' !== $why, true, false ) . '>' . esc_html( $u->display_name . '（' . $u->user_login . '）' . ( '' !== $why ? ' — 使えません：' . $why : '' ) ) . '</option>';
		}
		echo '</select></td></tr>';
		echo '<tr><th><label for="scm-issue-label">端末の名前</label></th><td><input id="scm-issue-label" name="label" class="regular-text" maxlength="100" placeholder="例：PM 山田 MacBook"><p class="description">あとで「止める」ときに見分けるための名前です。</p></td></tr>';
		echo '<tr><th><label for="scm-issue-url">入口の URL</label></th><td><input id="scm-issue-url" name="url" class="large-text code" value="' . esc_attr( $endpoint ) . '"><p class="description">同じサイトに https:// の入口（正しい証明書）があれば、https:// に変えてください（文章・写真が暗号化されます）。「/wp-json/…」の部分は変えないでください。</p></td></tr>';
		echo '<tr><th><label for="scm-issue-basic">Basic 認証（ある場合だけ）</label></th><td><input id="scm-issue-basic" name="basic_auth" class="regular-text code" autocomplete="off" placeholder="ID:パスワード"><p class="description">公開前などで、サーバーの Basic 認証がかかっているサイトだけ入れます。コードに入れて PM に渡し、サーバーには保存しません。</p></td></tr>';
		echo '</table>';
		submit_button( '接続コードを発行する', 'primary', 'submit', true );
		echo '</form>';
	}

	// ---- 部品 -------------------------------------------------------------

	/**
	 * @param array<string,mixed> $row
	 */
	private static function verified_cell( string $name, array $row ): string {
		$v = $row['verified'] ?? null;
		if ( ! is_array( $v ) || array() === (array) ( $v['groups'] ?? array() ) ) {
			return '<span class="description">（「試験合格」にすると記録します）</span>';
		}
		$out = esc_html( count( $v['groups'] ) . 'グループ・' . ( $v['at'] ? wp_date( 'Y-m-d H:i', (int) $v['at'] ) : '' ) );
		try {
			$now = StructureLock::current( $name );
		} catch ( \Throwable $e ) {
			$now = null;
		}
		if ( null !== $now && $now != $v['groups'] ) { // phpcs:ignore Universal.Operators.StrictComparisons -- 順番は問わない
			$out .= '<br><strong style="color:#b32d2e">記録の後に構造が変わっています（確かめ直すまで書き込みを止めます）</strong>';
		}
		$out .= '<br><label><input type="checkbox" name="reverify[' . esc_attr( $name ) . ']" value="1"> いまの構造を確かめ直した</label>';
		return $out;
	}

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
			'key_revoked'    => array( 'success', '止めました。' ),
		);
		if ( 'prelaunch' === $code ) {
			$msg = (string) get_transient( 'ship_content_mcp_notice_' . get_current_user_id() );
			delete_transient( 'ship_content_mcp_notice_' . get_current_user_id() );
			echo '<div class="notice notice-success"><p>' . esc_html( '' !== $msg ? $msg : 'MCP を止めました。' ) . ' <a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">プラグインの画面へ</a></p></div>';
			return;
		}
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
