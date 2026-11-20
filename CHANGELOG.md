# 変更の記録

## 0.1.0-dev（F1：読み取りと接続の基盤）

- 公式 MCP Adapter の上に、自作の MCP サーバー（`/wp-json/ship-content/mcp`）を作る。公式の既定サーバーは初期設定で止める。
- ツール：`list-post-types`・`get-creation-policy`・`get-acf-schema`（読み取りのみ）。
- 認証：端末の署名（Ed25519、応答にもサイトの署名）と Application Password。Cookie は不可。
- 権限：専用の権限 `ship_content_mcp_access` とロール「Content MCP Operator」。権限の強いユーザーは拒否。
- 管理画面：基本設定・制作方針（履歴つき、入力欄の参考表示）・端末の鍵・診断・記録。
- 中継プログラム（`bridge/`）：鍵の作成・接続の確認・中継。
- WP-CLI：`wp ship-content-mcp status|selftest|enable|disable|keys [list|add|revoke]`。
