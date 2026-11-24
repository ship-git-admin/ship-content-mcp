<?php
/**
 * 管理画面の「使い方」タブ（管理者向け・PM 向けの取説）。
 *
 * - サイト名・入口の URL・期限の日数・設定の状態は、このサイトの値を出す（プラグインにサイト固有の情報を入れない）。
 * - 写真は assets/help/ の画像（架空のサイト「サンプル工務店」で撮ったもの。AI のアプリの画面は「画面のイメージ」）。
 * - PM 向けの部分だけを印刷・PDF に保存して、PM に渡せる。
 *
 * 文の中の **太字** と `コード` は、表示のときに強調・等幅にする（それ以外はすべてエスケープする）。
 *
 * @package ShipContentMcp
 */

namespace ShipContentMcp\Admin;

use ShipContentMcp\Content\PostTypes;
use ShipContentMcp\Core\Settings;
use ShipContentMcp\MCP\Server;
use ShipContentMcp\Policy\Policies;
use ShipContentMcp\Security\DeviceKeys;
use ShipContentMcp\Security\OAuth;

defined( 'ABSPATH' ) || exit;

final class HelpPage {

	/** 使い方で使う画像（assets/help/<名前>.webp）。試験で、すべてあることを確かめる。 */
	public const IMAGES = array(
		'pm_flow_oauth',
		'admin_settings_oauth',
		'admin_invite_form',
		'admin_invite_result',
		'pm_authorize',
		'admin_invite_list',
		'admin_prelaunch',
		'pm_ai_admin_message',
		'pm_paste_prompt_oauth',
		'pm_ai_registered_oauth',
		'pm_mcp_menu',
		'pm_terminal_codex_oauth',
		'pm_basic_dialog',
		'pm_auth_done',
		'pm_ai_check',
		'pm_request',
		'pm_ai_pm_fix',
		'pm_authorize_error',
	);

	private static int $fig = 0;

	/** @var array<string,string> */
	private static array $vars = array();

	public static function render(): void {
		$s        = Settings::get();
		$endpoint = Server::endpoint();
		$https    = str_starts_with( $endpoint, 'https://' ) ? $endpoint : 'https://（https で開けるドメイン）' . (string) wp_parse_url( $endpoint, PHP_URL_PATH );
		$site     = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );

		self::$vars = array(
			'{site}'        => '' === trim( $site ) ? 'このサイト' : $site,
			'{endpoint}'    => $endpoint,
			'{https}'       => $https,
			'{invite_days}' => (string) $s['oauth_invite_days'],
			'{grant_days}'  => (string) $s['oauth_grant_days'],
			'{keys_url}'    => admin_url( 'options-general.php?page=' . SettingsPage::SLUG . '&tab=keys' ),
		);
		self::$fig  = 0;

		self::style();
		echo '<div class="scm-help">';
		echo '<div class="scm-help-top scm-noprint"><a class="button" href="#scm-help-admin">管理者向け</a> <a class="button" href="#scm-help-pm">PM 向け（印刷して渡せます）</a>';
		echo ' <button type="button" class="button" onclick="document.body.classList.add(\'scm-print-pm\');window.print();document.body.classList.remove(\'scm-print-pm\');">PM 向けを印刷・PDF に保存</button>';
		echo ' <button type="button" class="button" onclick="document.body.classList.add(\'scm-print-admin\');window.print();document.body.classList.remove(\'scm-print-admin\');">管理者向けを印刷・PDF に保存</button></div>';
		echo '<p class="description scm-noprint">写真は、架空のサイト「サンプル工務店」で撮ったものです。AI のアプリの画面は「画面のイメージ」で、実際の画面とは細部が違うことがあります。サイト名・入口の URL・期限の日数は、このサイトの値です。</p>';
		self::status( $s, $endpoint );
		echo '<div class="scm-help-admin" id="scm-help-admin">';
		self::admin();
		echo '</div><div class="scm-help-pm" id="scm-help-pm">';
		self::pm();
		echo '</div></div>';
	}

	// ---- このサイトの状態 ---------------------------------------------------------

	/**
	 * @param array<string,mixed> $s
	 */
	private static function status( array $s, string $endpoint ): void {
		$writable = array();
		foreach ( PostTypes::allowed() as $pt ) {
			if ( in_array( (string) ( $s['post_types'][ $pt ]['status'] ?? '' ), Settings::WRITABLE_STATUSES, true ) ) {
				$obj        = get_post_type_object( $pt );
				$writable[] = ( $obj ? $obj->label : $pt ) . '（' . $pt . '）';
			}
		}
		$p       = Policies::get();
		$policy  = '' !== $p['site']['text'] || array() !== array_filter( array_column( $p['types'], 'text' ) );
		$invites = 0;
		$grants  = 0;
		foreach ( DeviceKeys::all() as $row ) {
			if ( ! empty( $row['revoked_at'] ) || OAuth::expired( $row ) ) {
				continue;
			}
			$kind     = OAuth::kind( $row );
			$invites += 'invite' === $kind ? 1 : 0;
			$grants  += 'oauth' === $kind ? 1 : 0;
		}
		$rows = array(
			array( 'MCP を受け付ける', (bool) $s['enabled'], $s['enabled'] ? '有効' : '無効（「基本設定」で有効にする）' ),
			array( 'ブラウザで許可してつなぐ', (bool) $s['auth_oauth'], $s['auth_oauth'] ? '有効（招待コード ' . $s['oauth_invite_days'] . ' 日・許可 ' . $s['oauth_grant_days'] . ' 日）' : '無効（「基本設定」で有効にする）' ),
			array( '書き込める投稿タイプ', array() !== $writable, array() !== $writable ? implode( '・', $writable ) : 'まだない（「基本設定」で「試験中」以上にする）' ),
			array( '制作方針', $policy, $policy ? '書いてある（版 ' . Policies::version() . '）' : 'まだ書いていない（「制作方針」タブ）' ),
			array( '入口の URL', true, $endpoint . ( str_starts_with( $endpoint, 'http://' ) ? '（https:// で開けるなら https:// を PM に教える）' : '' ) ),
			array( '招待コード・接続', true, '未使用の招待コード ' . $invites . ' 件・有効な接続 ' . $grants . ' 件' ),
		);
		echo '<div class="scm-help-status"><h2>このサイトの状態</h2><table class="widefat striped"><tbody>';
		foreach ( $rows as [ $label, $ok, $text ] ) {
			echo '<tr><th>' . esc_html( $label ) . '</th><td><span class="scm-mark ' . ( $ok ? 'ok' : 'ng' ) . '">' . ( $ok ? '✓' : '!' ) . '</span> ' . esc_html( $text ) . '</td></tr>';
		}
		echo '</tbody></table><p class="description">赤の「!」があれば、先に直してください。細かい確認は「診断」タブにあります。</p></div>';
	}

	// ---- 管理者向け ----------------------------------------------------------------

	private static function admin(): void {
		self::$fig = 0;
		self::title( '管理者向け：招待コードを発行して、PM に渡す', 'PM が自分の AI から記事の下書きを作れるようにする手順です。' );

		self::h( '1', 'このしくみと、管理者がすること' );
		self::p( 'PM が自分の AI（Claude Code・Codex など）に頼むと、サイトに記事の下書きができます。管理者がするのは、**招待コード**を発行して PM に送ることだけです。' );
		self::fig( 'pm_flow_oauth', '全体の流れ' );
		self::ol( array( '最初に1回、サイトの準備を確かめる（Basic 認証があるサイトでは、サーバーの設定を1か所変える）', 'PM のパソコン1台ごとに、招待コードを発行する', '出てきた「① AI に貼る文」と「② 招待コード」を PM に DM で送る', 'サイトを公開するときに、MCP を片付けて外す' ) );
		self::box(
			'このやり方のよいところ',
			array(
				'PM は招待コードを**ブラウザの画面にだけ**入れます。秘密が AI の会話に入らない（AI の会社のサーバーを通らない）ので、トークンを貼る方式より安全です。',
				'Windows でも Mac でも同じ手順です。PM がコマンドを打つ必要は、ほとんどありません。',
				'招待コードは1回だけ・{invite_days} 日以内に使えます。許可した接続は {grant_days} 日で切れ、一覧から1台ずつ止められます。',
			)
		);
		self::box( '大事なこと', array( 'この仕組みは、**公開前（制作中）のサイトで使う前提**です。サイトを公開するときは、必ずプラグインごと外します（8.）。' ), 'warn' );

		self::h( '2', '最初に1回：サイトの準備を確かめる' );
		self::p( '「基本設定」で確かめます。上の「このサイトの状態」がすべて ✓ なら、準備はできています。デモサイトで整えておけば、コピーしたサイトにもそのまま入ります。' );
		self::fig( 'admin_settings_oauth', '「基本設定」の関係する項目' );
		self::table(
			array( '確かめること', 'よい状態' ),
			array(
				array( 'MCP を受け付ける', '有効' ),
				array( 'ブラウザで許可してつなぐ（OAuth）', '有効（初期で有効）' ),
				array( '招待コード・許可の期限', '初期は 7日・90日。変えてもよい' ),
				array( 'Web 版の AI からの接続も許す', 'ふつうはオフ（Claude の Web 版などを使うときだけオン）' ),
				array( '使う投稿タイプ', '「試験合格」か「稼働」' ),
				array( '制作方針', '書いてある（AI は下書きを作る前に毎回読みます）' ),
			)
		);
		self::ul( array( 'REST を止めるコード（セキュリティの Snippet など）があるサイトでは、`ship-content/` を通す例外が要ります。', 'PM に教える入口は https:// をおすすめします（`{https}`）。このサイトの設定上の入口は `{endpoint}` です。AI によっては、http:// ではブラウザで許可する接続を使えません。' ) );
		self::h3( 'Basic 認証があるサイト（公開前のサイト）' );
		self::p( 'AI は、Basic 認証を付けたまま接続の手続きをできません。そこで、**AI が自動で呼ぶ道だけ**を Basic 認証の例外にします。サイトのページと、PM が許可する画面は Basic 認証のままです。例外にした道も、トークンがなければ何もできません。' );
		self::p( 'サーバーの `.htaccess` の、Basic 認証の部分（`AuthUserFile` から `require valid-user` まで）を、次の形にします。**変更する前に `.htaccess` の控えを取ってください。**' );
		self::pre(
			"SetEnvIf Request_URI \"^/wp-json/ship-content/(mcp|oauth/(register|token|revoke)|upload/[a-f0-9]{48})/?$\" SHIP_MCP_OPEN\n"
			. "SetEnvIf Request_URI \"^/\\.well-known/(oauth-protected-resource|oauth-authorization-server|openid-configuration)\" SHIP_MCP_OPEN\n"
			. "AuthUserFile （いまの行のまま）\nAuthName （いまの行のまま）\nAuthType Basic\n<RequireAny>\nRequire env SHIP_MCP_OPEN\nRequire env REDIRECT_SHIP_MCP_OPEN\nRequire valid-user\n</RequireAny>"
		);
		self::ul( array( 'サーバーの管理画面（Xserver の「アクセス制限」など）で Basic 認証を設定し直すと、この形が元に戻ることがあります。戻ると、ブラウザで許可する接続だけが使えなくなります。', 'WordPress がドメインの直下にあるサイトで使えます。' ) );

		self::h( '3', '招待コードを発行する' );
		self::p( '「接続」タブのいちばん上、「招待コードを発行する」で入れて、ボタンを押します。' );
		self::fig( 'admin_invite_form', '「接続」タブの「招待コードを発行する」' );
		self::table(
			array( '項目', '入れるもの' ),
			array(
				array( 'ユーザー', 'そのままでよい（最初は「MCP 用のユーザーを新しく作る」、2回目からは作った MCP 用のユーザーが選ばれています）。この接続で作った下書きは、このユーザーが作ったものになります' ),
				array( 'PM の名前', 'あとで見分けるための名前（例：PM 山田（MacBook））' ),
				array( '入口の URL', 'https:// の入口があれば https:// に変える（`{https}`）。「/wp-json/…」は変えない。AI に登録する名前（ship-…）は、この URL のドメインから決まります' ),
			)
		);
		self::ul( array( '**PM 1人のパソコン1台ごとに1つ**発行します。' ) );
		self::link_button( '「接続」タブを開く', '{keys_url}' );

		self::h( '4', 'PM に送る' );
		self::p( '発行すると、緑の枠に2つ出ます。それぞれ「コピー」を押して、PM に DM で送ります。この画面の「PM 向け」を印刷・PDF にして一緒に渡すと、PM が迷いません。' );
		self::fig( 'admin_invite_result', '発行した直後の画面（招待コードは使えない例）' );
		self::table(
			array( '送るもの', 'PM がすること' ),
			array(
				array( '① AI に貼る文', '自分の AI にそのまま貼る（秘密は入っていません）' ),
				array( '② 招待コード', 'ブラウザに出る「接続の許可」の画面に入れて「許可する」を押す。**AI には貼らない**' ),
				array( '（Basic 認証があるサイト）サイトの確認用の ID とパスワード', '許可の画面を開いたときに、ブラウザが聞いてくるので入れる' ),
			)
		);
		self::ul( array( '**招待コードはこの1回しか表示されません。**サーバーには招待コードそのものは残りません。', '招待コードは、使われるまでの間は合鍵と同じです。DM でだけ送り、送り先を間違えたら 6. の「止める」を行います。' ) );

		self::h( '5', 'PM がブラウザで許可する画面' );
		self::p( 'PM の AI がログイン（認証）を始めると、PM のブラウザに次の画面が出ます。PM が招待コードを入れて「許可する」を押すと、一覧に「ブラウザで許可した接続」として出ます。' );
		self::fig( 'pm_authorize', '「AI からの接続を許可しますか？」の画面', false, 520 );
		self::ul( array( '「つなごうとしている AI」は、AI が名乗った名前です（例：Claude Code、Codex）。', '画面には「人から送られてきたリンクで開いた場合は、許可しないでください」と出ます。', '招待コードを続けて間違えると、15分受け付けなくなります。' ) );

		self::h( '6', '止める・発行し直す' );
		self::p( '「接続」タブの一覧で、その行の「止める」を押します。止めた瞬間から、その AI ではつながりません。止めた行は消さずに残ります（「記録」タブと照らし合わせるため）。' );
		self::fig( 'admin_invite_list', '一覧（見本の行）' );
		self::table(
			array( 'こんなとき', 'すること' ),
			array(
				array( '招待コードを間違った相手に送った', 'その招待コードを止めて、新しく発行して送り直す' ),
				array( 'PM が担当を外れた・パソコンをなくした', 'その PM の接続を止める' ),
				array( 'PM がパソコンを替えた', '新しいパソコン用に招待コードを発行する（古い接続は止める）' ),
				array( '許可の期限が切れた（{grant_days} 日）', '新しい招待コードを発行して送る' ),
				array( '招待コードの期限が切れた（{invite_days} 日）', '新しく発行して送り直す' ),
			)
		);

		self::h( '7', 'デモサイトからコピーしたサイトで使う' );
		self::p( 'ブラウザで許可する接続は**サイトごと**です。コピーしたサイトでは、そのサイトの「接続」タブで招待コードを発行して、PM に送ります（PM はそのサイト用にもう一度許可します）。設定と制作方針は、コピーしたサイトにそのまま入ります。Basic 認証を設定したら、2. の例外の形にします。' );

		self::h( '8', '公開するとき：片付ける' );
		self::fig( 'admin_prelaunch', '「接続」タブの「公開の前に片付ける」' );
		self::ol(
			array(
				'「すべて止めて、MCP を止める」を押す（招待コード・接続・トークン・鍵がすべて止まり、MCP の入口がなくなります）',
				'「プラグイン」の画面で「Ship Content MCP」と「MCP Adapter」を無効にして、削除する（このプラグインの設定・制作方針・記録・ロールも消えます。作った下書きと写真は残ります）',
				'REST を止めるコードに足した `ship-content/` の例外を外す',
				'Basic 認証を外す（2. の例外の行も一緒に消えます）',
				'要らなければ、MCP 用のユーザーを削除する（作った下書きは、担当者に引き継ぐ）',
			)
		);

		self::h( '9', '困ったとき' );
		self::p( 'PM の AI は、つながらないときに原因を見分けます。**管理者が直すものだけ**、AI が「管理者に送る文」を作り、PM がそれを送ってきます。PM の側で直せるもの（写し間違い・アプリの開き直しなど）は、AI が PM に直し方を伝えます。' );
		self::fig( 'pm_ai_admin_message', 'PM の AI が作る「管理者に送る文」の例' );
		self::p( 'AI が確かめた `.well-known/oauth-protected-resource` の状態で、サーバーの設定の問題が分かります：200 は正常、401（Basic）は 2. の Basic 認証の例外がない、404 は MCP か「ブラウザで許可してつなぐ」が止まっている（またはプラグインがない）。' );
		self::table(
			array( 'PM から届く言葉', '管理者がすること' ),
			array(
				array( '招待コードが違います', 'そのまま貼り直すよう伝える。直らなければ発行し直す' ),
				array( 'この招待コードは使用済みです／期限が切れています', '新しく発行して送る' ),
				array( 'ブラウザで許可する画面にならない・Needs authentication のまま', '2. の Basic 認証の例外、「ブラウザで許可してつなぐ」、https:// の入口を確かめる' ),
				array( '認証に失敗しました（MCP-002：接続の期限が切れたか、止められています）', '新しい招待コードを発行して送る' ),
				array( 'MCP の入口が見つかりません（404）', '「MCP を受け付ける」と入口の URL を確かめる' ),
				array( 'この操作は許可されていません（AUTH-001）', '「Content MCP Operator」のユーザーで発行し直す' ),
				array( 'まだ書き込みが許可されていません（WRITE-001）', '投稿タイプを「試験中」以上にする' ),
				array( '管理者が確かめた後に変わりました（ACF-003）', '表示を確かめて、基本設定の「いまの構造を確かめ直した」を保存' ),
			)
		);

		self::h( '10', '参考：WP-CLI・記録・トークン' );
		self::pre( "wp ship-content-mcp keys invite --for-user=new --label='PM 山田（MacBook）' --entry-url={https}\nwp ship-content-mcp keys list\nwp ship-content-mcp keys revoke --key-id=<鍵 ID>\nwp ship-content-mcp prelaunch --yes\nwp ship-content-mcp status" );
		self::ul(
			array(
				'「記録」タブに、AI の登録・許可・トークンの発行・認証の失敗・ツールの実行・管理の操作が残ります。招待コードやトークンの中身は残りません。',
				'ブラウザで許可する接続に対応していない AI には、「接続」タブの「トークンを発行する」を使えます（トークンは AI の会話に入ります）。',
			)
		);
	}

	// ---- PM 向け -------------------------------------------------------------------

	private static function pm(): void {
		self::$fig = 0;
		self::title( 'PM 向け：AI につないで、記事の下書きを作る', '{site}の WordPress に、自分の AI から下書きを作るための手順です。文を AI に貼って、ブラウザで「許可する」を押すだけです。' );

		self::h( '1', 'これでできること' );
		self::p( 'AI に普通の言葉で頼むと、{site} に記事の下書きができます。公開は、人が WordPress の管理画面で確かめてから行います。AI にできるのは、下書きの作成と修正だけです（公開・削除はできません）。' );
		self::fig( 'pm_flow_oauth', '全体の流れ' );

		self::h( '2', '用意するもの・使える AI' );
		self::table(
			array( '管理者から届くもの', '使い方' ),
			array(
				array( '① AI に貼る文', '自分の AI にそのまま貼る（秘密は入っていません）' ),
				array( '② 招待コード（XXXX-XXXX-XXXX-XXXX-XXXX）', 'ブラウザの「接続の許可」の画面にだけ入れる。**AI には貼らない**。1回だけ・{invite_days} 日以内' ),
			)
		);
		self::table(
			array( '使う AI', '使えるか' ),
			array(
				array( 'Claude Code（デスクトップアプリの「Code」・ターミナル）', '使える' ),
				array( 'Codex', '使える' ),
				array( 'Cursor・VS Code など、MCP の OAuth に対応した AI', '同じ手順で使えるはず' ),
				array( 'Claude デスクトップアプリ（チャット）・Claude の Web 版', '管理者が設定でオンにしたときだけ' ),
			)
		);
		self::box( '招待コードは AI に貼らないでください', array( '招待コードは、ブラウザに出る「接続の許可」の画面にだけ入れます。AI に貼ると、AI の会社のサーバーを通ってしまいます。' ) );

		self::h( '3', 'つなぐ①：文を AI に貼る' );
		self::ol( array( '管理者から届いた「AI に貼る文」を、丸ごとコピーする', '自分の AI を開いて、新しい会話に貼って送る', 'AI が「このコマンドを実行してよいか」と聞いたら「**許可**」を押す。AI が登録して、次にすることを教えてくれます' ) );
		self::fig( 'pm_paste_prompt_oauth', '届いた文を貼って送る', true );
		self::fig( 'pm_ai_registered_oauth', 'AI が登録して、次にすることを教える（Claude Code の例）', true );

		self::h( '4', 'つなぐ②：ブラウザで許可する' );
		self::h3( 'Claude Code の場合' );
		self::p( 'AI からはログインを始められないので、自分で始めます。Claude Code を開き直して `/mcp` と入力し、登録したサーバー（届いた文の「名前」。ship- で始まるもの）を選んで「**Authenticate（認証）**」を押します。`/mcp` が使えないときは、ターミナル（Mac は「ターミナル」、Windows は「PowerShell」）で `claude mcp login <届いた文の名前>` を実行します。' );
		self::fig( 'pm_mcp_menu', '/mcp から認証を始める', true );
		self::h3( 'Codex の場合' );
		self::p( '登録すると、すぐにブラウザが開きます。開かなかったときは、AI に「codex mcp login を実行して」と頼みます。' );
		self::fig( 'pm_terminal_codex_oauth', 'Codex は登録するとログインが始まる', true );
		self::h3( 'ブラウザで許可する（どの AI でも同じ）' );
		self::ol(
			array(
				'公開前のサイトでは、ブラウザが ID とパスワードを聞いてきます。いつもサイトの確認に使っているものを入れます',
				'「AI からの接続を許可しますか？」の画面で、「つなごうとしている AI」が自分の AI であることを確かめる',
				'管理者から届いた**招待コード**を貼って、「**許可する**」を押す',
				'「認証が終わった」という画面になったら、ブラウザのタブを閉じて AI の画面に戻る',
			)
		);
		self::fig( 'pm_basic_dialog', 'サイトの確認用の ID とパスワード（公開前のサイトだけ）', true );
		self::fig( 'pm_authorize', '接続の許可の画面', false, 520 );
		self::fig( 'pm_auth_done', '許可した後の画面（文は AI によって違います）', true );
		self::box( 'この画面で許可してよいのは', array( '**自分で AI に「つないで」と頼んで、開いた画面のときだけ**です。人から送られてきたリンクで開いた画面では、許可しないでください（その人の AI がつながってしまいます）。' ), 'warn' );

		self::h( '5', 'つながったか確かめる' );
		self::p( 'AI に「{site}で使える投稿タイプを教えて」と聞きます。投稿タイプが返ってくれば、使えます。ツールが見つからないと言われたら、新しい会話にしてから聞き直します。2回目からは、何もしなくてもつながります。許可は {grant_days} 日で切れます。切れたら、管理者に新しい招待コードをもらって 4. をやり直します。' );
		self::fig( 'pm_ai_check', 'つながったか確かめる', true );

		self::h( '6', '使い方' );
		self::p( 'AI の新しい会話で、伝えたいことを普通に書きます。事実（費用・工期・製品名・お客様の名前）は、渡したものだけが使われます。足りないと AI が聞いてきます。' );
		self::box( '頼み方の例', array( '{site}に、施工事例の下書きを作ってください。', '・地域：○○市（番地は書かない）／建物：木造2階建て／施工箇所：外壁・屋根', '・お客様のご要望：（聞いた内容）／ご提案：（提案した内容）', '・費用・工期：（書いてよければ）', '・写真：ピクチャの「現場A」フォルダ。before_ で始まるものが施工前、after_ が施工後' ), 'plain' );
		self::fig( 'pm_request', '頼み方と返事の例', true );
		self::ul( array( '写真は、AI がサイトから「アップロード用の URL」をもらい、コマンド（curl）で送ります。「このコマンドを実行してよいか」と聞かれたら「許可」します。', '作り終わったら、WordPress の管理画面の「下書き」で確かめ、プレビューを見て直してから、人が公開します。', '直したいときは「下書き ○○ のご提案を、こう直して」と頼みます。' ) );
		self::h3( '公開済みの記事を参考にする' );
		self::box( '頼み方の例', array( '公開済みの施工事例「（題名の一部）」を参考に、同じ構成（使う入力欄・写真の並べ方）で、次の現場の下書きを作ってください。', '参考の記事の文章や数字（費用・お客様の名前など）は写さないでください。' ), 'plain' );

		self::h( '7', '写真で気をつけること' );
		self::ul( array( '送れるのは JPEG・PNG・WebP だけです。iPhone の HEIC は、JPEG に変えてから送ります。', '撮影場所（GPS）などの情報は、サイトに登録するときに消えます。', '**サイトに登録した写真は、記事が下書きのままでも、URL を知っていれば誰でも見られます。**公開してよい写真だけを送ってください（お客様の顔・表札・車のナンバーに注意）。' ) );

		self::h( '8', '別のサイト・別のパソコンで使う' );
		self::ul( array( '**別のサイト**：接続はサイトごとです。管理者から、そのサイトの「AI に貼る文」と招待コードをもらって、3.・4. をもう一度行います。', '**別のパソコン**：パソコンごとに招待コードが要ります。古いパソコンを使わなくなったら、管理者に伝えて止めてもらいます。' ) );

		self::h( '9', '困ったとき' );
		self::p( 'まず**自分の AI に、画面に出た言葉をそのまま伝えます**。AI が原因を見分けて、あなたの側で直せるものは直し方を、管理者が直すものは「管理者に送る文」を出します。「管理者に送る文」が出たときだけ、それをコピーして管理者に送ります。' );
		self::fig( 'pm_ai_pm_fix', '自分で直せるときの案内の例', true );
		self::fig( 'pm_ai_admin_message', '管理者に送る文の例（招待コードなどの秘密は入りません）', true );
		self::fig( 'pm_authorize_error', '招待コードが違うときの画面', false, 520 );
		self::table(
			array( '出る言葉・様子', 'すること' ),
			array(
				array( '招待コードが違います', '届いた招待コードを、そのまま貼り直す（別のサイトのものでないか確かめる）' ),
				array( 'この招待コードは使用済みです／期限が切れています', '管理者に新しい招待コードをもらう（AI が作る「管理者に送る文」を送る）' ),
				array( '間違いが続いたので、しばらく受け付けません', '15分ほど待ってから、AI の画面でやり直す' ),
				array( 'AI の登録が見つかりません／戻り先が登録と違います', 'AI の画面から、もう一度ログイン（認証）を始める' ),
				array( 'ブラウザが開かない', 'Claude Code は `/mcp` から認証する。Codex は AI に「codex mcp login を実行して」と頼む' ),
				array( 'MCP のツールが見つからない・使えない', '新しい会話にしてから聞き直す（Codex は起動し直す）' ),
				array( 'アップロード用 URL が使えません', 'AI に「写真をもう一度送って」と頼む（URL は30分・1回だけ使えます）' ),
				array( 'ほかの編集があった・編集中（CONFLICT-001）', '管理画面でその記事を開いている人に閉じてもらってから、もう一度頼む' ),
			)
		);
		self::p( 'パソコンをなくしたとき、担当を外れたときは、すぐに管理者に伝えて、接続を止めてもらいます。' );
	}

	// ---- 部品 ----------------------------------------------------------------------

	/** 変数を埋めて、**太字** と `コード` だけを HTML にする（ほかはエスケープ）。 */
	private static function t( string $text ): string {
		$text = strtr( $text, self::$vars );
		$html = esc_html( $text );
		$html = (string) preg_replace( '/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $html );
		return (string) preg_replace( '/`(.+?)`/u', '<code>$1</code>', $html );
	}

	private static function title( string $title, string $lead ): void {
		echo '<div class="scm-help-title"><h2>' . self::t( $title ) . '</h2><p>' . self::t( $lead ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- t() でエスケープ済み
	}

	private static function h( string $num, string $text ): void {
		echo '<h3 class="scm-h"><span>' . esc_html( $num ) . '</span>' . self::t( $text ) . '</h3>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- t() でエスケープ済み
	}

	private static function h3( string $text ): void {
		echo '<h4 class="scm-h4">' . self::t( $text ) . '</h4>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- t() でエスケープ済み
	}

	private static function p( string $text ): void {
		echo '<p>' . self::t( $text ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- t() でエスケープ済み
	}

	/**
	 * @param list<string> $items
	 */
	private static function ul( array $items ): void {
		echo '<ul class="scm-ul">';
		foreach ( $items as $i ) {
			echo '<li>' . self::t( $i ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- t() でエスケープ済み
		}
		echo '</ul>';
	}

	/**
	 * @param list<string> $items
	 */
	private static function ol( array $items ): void {
		echo '<ol class="scm-ol">';
		foreach ( $items as $i ) {
			echo '<li>' . self::t( $i ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- t() でエスケープ済み
		}
		echo '</ol>';
	}

	/**
	 * @param list<string>       $head
	 * @param list<list<string>> $rows
	 */
	private static function table( array $head, array $rows ): void {
		echo '<table class="widefat striped scm-table"><thead><tr>';
		foreach ( $head as $h ) {
			echo '<th>' . self::t( $h ) . '</th>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- t() でエスケープ済み
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $r ) {
			echo '<tr>';
			foreach ( $r as $c ) {
				echo '<td>' . self::t( $c ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- t() でエスケープ済み
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * @param list<string> $lines
	 */
	private static function box( string $title, array $lines, string $kind = 'note' ): void {
		echo '<div class="scm-box scm-box-' . esc_attr( $kind ) . '"><p class="scm-box-title">' . self::t( $title ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- t() でエスケープ済み
		foreach ( $lines as $l ) {
			echo '<p>' . self::t( $l ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- t() でエスケープ済み
		}
		echo '</div>';
	}

	private static function pre( string $text ): void {
		echo '<pre class="scm-pre">' . esc_html( strtr( $text, self::$vars ) ) . '</pre>';
	}

	private static function link_button( string $label, string $url ): void {
		echo '<p class="scm-noprint"><a class="button button-primary" href="' . esc_url( strtr( $url, self::$vars ) ) . '">' . esc_html( $label ) . '</a></p>';
	}

	private static function fig( string $name, string $caption, bool $mock = false, int $max = 900 ): void {
		++self::$fig;
		$file = SHIP_CONTENT_MCP_DIR . '/assets/help/' . $name . '.webp';
		$size = is_readable( $file ) ? @getimagesize( $file ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- 読めない画像は大きさなしで出す
		$dims = is_array( $size ) ? ' width="' . (int) $size[0] . '" height="' . (int) $size[1] . '"' : '';
		$url  = plugins_url( 'assets/help/' . $name . '.webp', SHIP_CONTENT_MCP_FILE );
		echo '<figure class="scm-fig" style="max-width:' . (int) $max . 'px"><img src="' . esc_url( $url ) . '"' . $dims . ' alt="' . esc_attr( $caption ) . '" loading="lazy" decoding="async">';
		echo '<figcaption>図' . (int) self::$fig . '　' . self::t( $caption ) . ( $mock ? '<span class="scm-mock">（画面のイメージ）</span>' : '' ) . '</figcaption></figure>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- t() でエスケープ済み
	}

	private static function style(): void {
		echo '<style>
.scm-help{max-width:960px;overflow-wrap:anywhere}.scm-help code,.scm-help td{word-break:break-all}
.scm-help-top{margin:14px 0 6px;display:flex;flex-wrap:wrap;gap:6px}
.scm-help-status{margin:10px 0 24px}.scm-help-status th{width:200px}
.scm-mark{display:inline-block;width:20px;height:20px;line-height:20px;text-align:center;border-radius:50%;color:#fff;font-weight:700;font-size:12px}
.scm-mark.ok{background:#00a32a}.scm-mark.ng{background:#d63638}
.scm-help-title{margin:28px 0 8px;padding:14px 16px;background:#fff;border-left:4px solid #b44a1e}
.scm-help-title h2{margin:0 0 4px;font-size:1.5em}.scm-help-title p{margin:0;color:#50575e}
.scm-h{display:flex;align-items:center;gap:10px;font-size:1.25em;margin:28px 0 10px;padding-bottom:6px;border-bottom:1px solid #dcdcde}
.scm-h span{display:inline-flex;align-items:center;justify-content:center;min-width:28px;height:28px;border-radius:50%;background:#b44a1e;color:#fff;font-size:14px}
.scm-h4{font-size:1.05em;margin:18px 0 6px}
.scm-help p,.scm-help li{font-size:14px;line-height:1.8}
.scm-ul{list-style:disc;margin-left:1.6em}.scm-ol{margin-left:1.8em}
.scm-table{margin:8px 0 14px}.scm-table td,.scm-table th{font-size:13px;line-height:1.6;vertical-align:top}
.scm-box{margin:12px 0;padding:10px 14px;border:1px solid #f0c27a;background:#fff8e5;border-radius:4px}
.scm-box-warn{border-color:#f0a8a8;background:#fcf0f1}.scm-box-plain{border-color:#dcdcde;background:#f6f7f7}
.scm-box p{margin:4px 0}.scm-box-title{font-weight:700}
.scm-pre{white-space:pre-wrap;word-break:break-all;background:#f6f7f7;border:1px solid #dcdcde;padding:10px 12px;font-size:12px;line-height:1.6}
.scm-fig{margin:12px auto 18px;text-align:center}.scm-fig img{max-width:100%;height:auto;border:1px solid #dcdcde;border-radius:4px;background:#fff}
.scm-fig figcaption{font-size:12px;color:#646970;margin-top:4px}.scm-mock{color:#b44a1e;margin-left:4px}
@media print{
#adminmenumain,#adminmenuback,#adminmenuwrap,#wpadminbar,#wpfooter,.nav-tab-wrapper,.wrap>h1,.notice,.update-nag,.scm-noprint{display:none!important}
#wpcontent,#wpbody-content{margin:0!important;padding:0!important}html.wp-toolbar{padding:0!important}
body.scm-print-pm .scm-help-admin,body.scm-print-pm .scm-help-status{display:none!important}
body.scm-print-admin .scm-help-pm{display:none!important}
.scm-fig,.scm-box,.scm-table tr{break-inside:avoid}.scm-h,.scm-h4{break-after:avoid}
.scm-help-pm{break-before:page}body.scm-print-pm .scm-help-pm{break-before:auto}
}
</style>';
	}
}
