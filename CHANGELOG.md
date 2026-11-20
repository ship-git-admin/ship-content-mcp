# 変更の記録

## 0.2.0-dev（F2：下書きの作成・修正）

- ツールを5つ足す：`search-posts`・`get-post`（ACF の値を保存されたままの形で返す、if_match 付き）・`validate-draft`（書かずに確かめる）・`create-draft`・`update-draft`。
- ACF の値の検証：いまの構造と照らす（型・選択肢・画像・関連・入れ子・レイアウト・行数）。入れ子も field key で指定。
- 作成：推定の構造で確かめ、印付きの下書きを作ってから実際の構造で判定し直す。失敗したら作った下書きを消す。
- 修正：版の印（if_match）と編集ロックで競合を止める。既存の値を消す変更は allow_removals がないと止める。修正の前の値を控え、失敗したら戻す。
- 二重送信の防止（idempotency_key、専用の表）。
- 投稿タイプの状態に「試験中」を足す。試験中に作った下書きには試験の印。`wp ship-content-mcp cleanup-tests --yes` で片付け。
- 契約：`draft_patch` に `schema_fingerprint`・`allow_removals`、`validate_draft` を追加。

## 0.1.0-dev（F1：読み取りと接続の基盤）

- 公式 MCP Adapter の上に、自作の MCP サーバー（`/wp-json/ship-content/mcp`）を作る。公式の既定サーバーは初期設定で止める。
- ツール：`list-post-types`・`get-creation-policy`・`get-acf-schema`（読み取りのみ）。
- 認証：端末の署名（Ed25519、応答にもサイトの署名）と Application Password。Cookie は不可。
- 権限：専用の権限 `ship_content_mcp_access` とロール「Content MCP Operator」。権限の強いユーザーは拒否。
- 管理画面：基本設定・制作方針（履歴つき、入力欄の参考表示）・端末の鍵・診断・記録。
- 中継プログラム（`bridge/`）：鍵の作成・接続の確認・中継。
- WP-CLI：`wp ship-content-mcp status|selftest|enable|disable|keys [list|add|revoke]`。
