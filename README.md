# Ship Content MCP

社内の PM が、手元の AI エージェントから MCP で WordPress につなぎ、許可した投稿タイプの**下書き**を作る・直すための WordPress プラグインです。

- WordPress 公式の [MCP Adapter](https://github.com/WordPress/mcp-adapter)（別プラグイン）の上で動く、自作の MCP サーバーです。
- WordPress 側では AI を呼びません（AI の API キーを WordPress に置きません）。文章や写真の判断は、PM の AI エージェントが行います。
- 入力欄（ACF）の構造は、その場で読みます。事前のマッピングは要りません。
- 制作方針は、管理画面に自然な文章で書きます（サイト共通・投稿タイプ別）。
- 公開・予約・削除・ACF の定義の変更・テーマやプラグインの変更は、できません。

> いまは **F1（読み取りのみ）** です。下書きの作成・修正は次の段階（F2）で入ります。

## 必要なもの

- WordPress 6.9 以上（Abilities API）、PHP 8.1 以上（sodium）
- 公式 MCP Adapter（WordPress.org のプラグイン `mcp-adapter`）
- ACF（入力欄の構造を読むとき）
- PM の端末：Node.js 18 以上（中継プログラム）

## 入れ方（管理者）

1. 公式 MCP Adapter と、このプラグインを入れて有効にします。
2. 「設定 → Ship Content MCP → 基本設定」で、使う投稿タイプ（初期は `works`）を確かめて、「MCP を受け付ける」を有効にします。
3. 「ユーザー → 新規追加」で、PM ごとに権限グループ「Content MCP Operator」のユーザーを作ります（投稿の編集などの権限は付けません）。
4. 「制作方針」に、サイト共通と投稿タイプ別の方針を書きます。
5. 「診断」で、問題がないか確かめます。

REST を止めるコード（セキュリティの Snippet など）があるサイトでは、MCP の入口 `ship-content/` を通すようにしてください。

## PM の端末でつなぐ（署名方式）

```bash
node bridge/ship-content-mcp-bridge.mjs keygen --out ~/.ship-content-mcp/example.key
```

表示された「公開鍵」を、管理者に「端末の鍵」へ登録してもらいます。登録すると、MCP の入口・鍵 ID・サイトの公開鍵が表示されます。

AI エージェントの MCP の設定（例）：

```json
{
  "mcpServers": {
    "ship-content-example": {
      "command": "node",
      "args": ["/path/to/ship-content-mcp-bridge.mjs"],
      "env": {
        "SHIP_MCP_URL": "https://example.invalid/wp-json/ship-content/mcp",
        "SHIP_MCP_KEY_ID": "k0123456789abcdef",
        "SHIP_MCP_KEY_FILE": "/Users/you/.ship-content-mcp/example.key",
        "SHIP_MCP_SERVER_KEY": "（サイトの公開鍵）"
      }
    }
  }
}
```

サーバーの Basic 認証があるサイトでは `SHIP_MCP_BASIC_AUTH`（`ID:パスワード`）も足します。つながるかは `check` で試せます。

```bash
SHIP_MCP_URL=... SHIP_MCP_KEY_ID=... SHIP_MCP_KEY_FILE=... SHIP_MCP_SERVER_KEY=... node bridge/ship-content-mcp-bridge.mjs check
```

HTTPS のサイトでは、WordPress の Application Password でも接続できます（HTTP では使えません）。

## ツール（F1）

| ツール | すること |
|---|---|
| `ship-content-list-post-types` | 使える投稿タイプ・分類・状態 |
| `ship-content-get-creation-policy` | 制作方針（サイト共通・投稿タイプ別） |
| `ship-content-get-acf-schema` | 入力欄（ACF）の構造（field key・型・選択肢・レイアウト・入れ子・条件表示）と `schema_fingerprint` |

## 安全の決まり

- 認証：端末の署名（[docs/AUTH_SIGNATURE.md](docs/AUTH_SIGNATURE.md)）または Application Password。Cookie での利用は不可。
- ユーザー：`read` と専用の権限だけ。投稿の編集・公開・アップロードなどの権限を持つユーザーは拒否（初期設定）。
- ツールは自作サーバーの一覧にだけ載せ、コアの REST・公式の既定サーバーには出しません。
- 記録：接続・ツールの実行・認証の失敗を記録します（本文・画像・パスワードは記録しません）。
- 決定の理由は [docs/DECISIONS.md](docs/DECISIONS.md)。

## 試験

```bash
tools/test.sh
```

WordPress 上では `wp ship-content-mcp selftest`（読み取り中心）と `wp ship-content-mcp status`（診断）。

## ライセンス

GPL-2.0-or-later
