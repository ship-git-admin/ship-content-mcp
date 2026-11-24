# 変更の記録

## 0.8.0（トークン：いちばん簡単なつなぎ方）

- トークン：管理画面の「接続」タブ（または `wp ship-content-mcp keys token`）で発行し、Claude Code・Codex・ほかの AI の MCP の設定に入れるだけでつながる（`X-Ship-MCP-Token` ヘッダー、または `Authorization: Bearer`）。発行するとき、そのまま貼れるコマンド（Claude Code の1行、Codex の config.toml、mcpServers の JSON）を出す。MCP 用のユーザーもその場で作れる。サイトにはハッシュだけを置き、トークンは1回だけ表示する。HTTP でも受け付ける（設定で切れる）。デモサイトで発行すれば、コピーしたサイトでも同じトークンが使える。
- 写真：ツール `get-upload-url` で1回だけ使える URL（30分）を発行し、AI が curl で写真を送る（`/wp-json/ship-content/upload/<札>`）。中身の確かめ方・メタデータの削除・重複の扱いは分割転送と同じ。
- 公開の前の片付け：「すべて止めて、MCP を止める」（`wp ship-content-mcp prelaunch`）。プラグインを削除すると、このプラグインの表・設定・ロールも消える（uninstall.php）。
- Claude デスクトップの拡張機能は、トークンを貼るだけに（入口と Basic 認証はダウンロードのときに入れておく）。中継プログラムは `SHIP_MCP_TOKEN` でトークンを使える。
- 管理画面のタブ「端末の鍵」を「接続」に。接続コード・端末の公開鍵は「ほかのつなぎ方」に下げた。自己テストに T36・T37。

## 0.7.0（Claude デスクトップの拡張機能・ほかの AI）

- Claude デスクトップアプリ（チャット）用の拡張機能（MCPB、`.mcpb`）を、管理画面の「端末の鍵」からサイトごとにダウンロードできる。PM はファイルを開き、設定の欄に接続コードを貼るだけ（Node.js もターミナルも要らない）。名前はサイトごと（`ship-content-<名前>`）なので、複数のサイトを並べて入れられる。秘密は入れない（接続コードは Claude デスクトップの設定の欄に保存される）。別のサイトの接続コードを貼ったら止める。
- 中継プログラム：接続コードを環境変数 `SHIP_MCP_CODE` で直接受け取れる。設定の欄が空のときに残る `${…}` は無視する。`setup --print-config` で、ほかの AI（`mcpServers` の形の設定を使うもの）に貼る設定を表示する。
- プラグインに中継プログラムを同梱する（`assets/bridge/`。拡張機能の中身。bridge/ と同じであることを試験・配布の道具で確かめる）。
- 手順書：PM 向けを、Claude デスクトップ（チャット）・Claude Code・ほかの AI の3通りに。

## 0.6.0（接続コード）

- 接続コード：管理者が管理画面の「端末の鍵」（または `wp ship-content-mcp keys issue`）で発行し、PM に渡す。PM はコピーして、決まった一文を Claude に貼るだけで設定できる（鍵づくり・公開鍵の往復・値の書き写しが要らない）。サーバーには公開鍵だけを残し、コードは1回だけ表示する。同じフォームの再送では発行しない。入口は https に変えて渡せる。Basic 認証も入れられる（サーバーには保存しない）。
- 中継プログラム：`setup`（クリップボード・ファイル・標準入力から接続コードを読み、設定のファイルを 600 で作り、接続を確かめ、`--register` で Claude Code に登録）。設定は `SHIP_MCP_CONFIG` 1つで足りる（個別の環境変数があれば優先）。
- リリースに `ship-content-mcp-bridge.mjs.sha256` を添付する（ダウンロードした中継プログラムを照合できる）。
- 手順書：PM 向けは「接続コードをコピーして一文を貼る」に。keygen の方式も残す。自己テストに T35（接続コード）。

## 0.5.1

- `.gitignore` に Python の一時フォルダ（`__pycache__/`）を足す（CI の配布の記録で「作業中の変更あり」と出ていたため）。機能の変更はなし。WordPress の「更新」画面からの更新の試験を兼ねる。

## 0.5.0（F5：最初の公開版）

- GitHub の公開リリースからの更新（同梱の Plugin Update Checker v5.7。公開済みの最新リリースに添付した ZIP だけを使う。トークンは使わない）。
- 配布用 ZIP を作る道具（同じ入力から同じ ZIP、版の一致・同梱ライブラリの指紋・秘密・構文の検査、manifest と SHA-256）。中継プログラムもリリースに添付する。
- CI（PHP 8.1・8.4 の構文、手元の試験、Node 18 の中継プログラム、秘密の検査（作業ツリー・全履歴）、配布 ZIP の予行演習）と、タグで下書きのリリースを作る流れ。
- SECURITY.md・docs/RELEASE.md・THIRD_PARTY_NOTICES.txt・license.txt。
- 登録済みの写真を返すときも URL・大きさを返す。本文の script・style も中身ごと消す。

## 0.4.0-dev（F4：ほかの投稿タイプ・確かめた構造・診断・手順書）

- 「試験合格」「稼働」にしたときの ACF の構造を記録し、その後に構造が変わったら書き込みを止める（ACF-003）。管理画面で「確かめ直した」を記録できる。
- 診断の各項目に「対処」。確かめた構造の項目を追加。
- WP-CLI：`post-type <name> <status> [--reverify]`。自己テストに「管理者の REST を妨げない」「構造の記録が安定」を追加。
- 手順書：PM 向け（docs/PM_GUIDE.md）・管理者向け（docs/ADMIN_GUIDE.md）。MCP の設定の雛形（bridge/mcp-config.example.json）。

## 0.3.0-dev（F3：写真の転送・制作方針の画面）

- ツールを3つ足す：`prepare-upload`・`upload-chunk`・`finish-upload`（写真の分割転送。JPEG・PNG・WebP だけ）。
- 位置情報などのメタデータを消してから登録する（向きは写真に反映。初期は有効）。同じ写真は二度登録しない。
- 中継プログラムにローカルのツール `ship-content-upload-local-image` を足す（AI はパスを渡すだけ）。
- 管理画面：写真の上限・メタデータを消す設定・メディアの公開範囲の注意。制作方針の履歴に保存した人、AI が読む内容の確認。
- `cleanup-tests` で、試験中に登録した写真と期限切れの転送も片付ける。DB の版 3。

## 0.2.0-dev（F2：下書きの作成・修正）

- ツールを5つ足す：`search-posts`・`get-post`（ACF の値を保存されたままの形で返す、if_match 付き）・`validate-draft`（書かずに確かめる）・`create-draft`・`update-draft`。
- ACF の値の検証：いまの構造と照らす（型・選択肢・画像・関連・入れ子・レイアウト・行数）。入れ子も field key で指定。
- 作成：推定の構造で確かめ、印付きの下書きを作ってから実際の構造で判定し直す。失敗したら作った下書きを消す。
- 修正：版の印（if_match）と編集ロックで競合を止める。既存の値を消す変更は allow_removals がないと止める。修正の前の値を控え、失敗したら戻す。
- 二重送信の防止（idempotency_key、専用の表）。
- 投稿タイプの状態に「試験中」を足す。試験中に作った下書きには試験の印。`wp ship-content-mcp cleanup-tests --yes` で片付け。
- 契約：`draft_patch` に `schema_fingerprint`・`allow_removals`、`validate_draft` を追加。
- 書いた後に ACF の値を最新のリビジョンへ写す（プレビューで空に見えないように）。
- script・style は中身ごと消す。

## 0.1.0-dev（F1：読み取りと接続の基盤）

- 公式 MCP Adapter の上に、自作の MCP サーバー（`/wp-json/ship-content/mcp`）を作る。公式の既定サーバーは初期設定で止める。
- ツール：`list-post-types`・`get-creation-policy`・`get-acf-schema`（読み取りのみ）。
- 認証：端末の署名（Ed25519、応答にもサイトの署名）と Application Password。Cookie は不可。
- 権限：専用の権限 `ship_content_mcp_access` とロール「Content MCP Operator」。権限の強いユーザーは拒否。
- 管理画面：基本設定・制作方針（履歴つき、入力欄の参考表示）・端末の鍵・診断・記録。
- 中継プログラム（`bridge/`）：鍵の作成・接続の確認・中継。
- WP-CLI：`wp ship-content-mcp status|selftest|enable|disable|keys [list|add|revoke]`。
