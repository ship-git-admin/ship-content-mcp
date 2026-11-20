# 決定の記録

| ID | 決めたこと | 理由 |
|---|---|---|
| D-001 | プラグインのスラッグは `ship-content-mcp`、Ability の名前空間・MCP のサーバー ID は `ship-content`。入口は `/wp-json/ship-content/mcp` | 社内標準の名前。ツール名は公式 Adapter の決まりで `ship-content-list-post-types` のようになる |
| D-002 | 認証は「端末の署名（Ed25519）」と「Application Password」の2つ。署名は `Authorization` を使わず、応答にもサイトの鍵で署名する | HTTP の動作確認URL・サーバーの Basic 認証のあるサイトでも、パスワードを通信に乗せずに使うため（docs/AUTH_SIGNATURE.md） |
| D-003 | ツール（Ability）は `meta.public`・`show_in_rest` を付けず、`meta.mcp.public=false`。自作サーバーの一覧にだけ書く | 公式 Adapter 0.7.2 は、自作サーバーの一覧に書いた Ability を公開の印なしで載せる（ソースで確認）。WordPress 7.1 は `meta.public` を REST の公開にも使う。既定サーバーにもコアの REST にも出さない |
| D-004 | 公式の既定サーバーは、初期設定で止める | 既定サーバーは、公開の印の付いたほかの Ability（コアのものを含む）を、`read` を持つ人に出す |
| D-005 | 入れた直後は MCP を受け付けない（管理者が有効にする） | 入れただけで入口が開かないようにする |
| D-006 | 投稿の編集・公開・アップロード・設定変更などの権限を持つユーザーは、MCP で使えない（初期設定） | 同じ認証で標準の REST から記事を書き換えられないようにする。PM には `read` と専用の権限だけのロールを使う |
| D-007 | ツールは、REST の振り分けが MCP の入口のとき（または WP-CLI）だけ動く。権限は接続のとき・ツールごと・処理の中の3回確かめる | ほかの経路（コアの REST、既定サーバーなど）から呼ばれても動かないようにする |
| D-008 | 新しい下書きの ACF の構造は「ID なしの仮の投稿（post_id=0）＋予定の分類」で判定し、推定として返す | ACF は投稿 ID がないと分類の条件を「一致なし」と判断し、`post_taxonomy != …` のグループまで外してしまう（ACF のソースで確認）。保存の前には実際の下書きで判定し直す（F2） |
| D-009 | `schema_fingerprint` は、ラベル・説明文・選択肢の表示名を含めない | 表示の言い換えでは、保存を止めないため。key・型・選択肢の値・入れ子・location が変われば変わる |
| D-010 | 失敗は、公式 Adapter のエラーの印（isError）付きの文章として返し、その文章に `{ok:false, error:{code, message, admin_detail, retryable}, request_id}` の JSON を入れる | Adapter は WP_Error の文だけを AI に返すため。admin_detail には IP・鍵・パスワードを入れない |
| D-011 | WP-Cron に頼らない（使い捨て番号・記録の片付けは、リクエストのついでに行う） | Basic 認証のあるサイトでは WP-Cron が動かないことがある |
| D-012 | 制作方針の出力は `contracts/policy_output.schema.json` の形にし、更新日時などは `meta` に入れる。版は「保存して変わった回数 + 1」 | 契約の Schema は追加の項目を許さないため |
| D-013 | 固定ページ・メディア・ACF の定義・内部の投稿タイプ（公開でないもの）は、設定にかかわらず使わせない | サイトの構造や、コードを作る投稿タイプ（JS/SCSS など）を AI から変えさせない |
| D-014 | 接続元の IP 制限は任意（初期は制限なし）。使うときは REMOTE_ADDR だけを見る | PM の接続元は変わりやすい。署名・端末ごとの停止・低権限で守る。X-Forwarded-For は偽れるので見ない |
