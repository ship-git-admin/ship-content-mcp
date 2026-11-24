# Ship Content MCP

社内の PM が、手元の AI エージェントから MCP で WordPress につなぎ、許可した投稿タイプの**下書き**を作る・直すための WordPress プラグインです。

- WordPress 公式の [MCP Adapter](https://github.com/WordPress/mcp-adapter)（別プラグイン）の上で動く、自作の MCP サーバーです。
- WordPress 側では AI を呼びません（AI の API キーを WordPress に置きません）。文章や写真の判断は、PM の AI エージェントが行います。
- 入力欄（ACF）の構造は、その場で読みます。事前のマッピングは要りません。
- 制作方針は、管理画面に自然な文章で書きます（サイト共通・投稿タイプ別）。
- 公開・予約・削除・ACF の定義の変更・テーマやプラグインの変更は、できません。

> 版 0.7.0。PM の試運用の前の段階です。

## 必要なもの

- WordPress 6.9 以上（Abilities API）、PHP 8.1 以上（sodium）
- 公式 MCP Adapter（WordPress.org のプラグイン `mcp-adapter`）
- ACF（入力欄の構造を読むとき）
- PM の端末：Node.js 18 以上（中継プログラム）。パソコンに詳しくない PM は、接続コードと AI に貼る文で、Node.js から登録まで AI に任せられる（[docs/PM_GUIDE.md](docs/PM_GUIDE.md) の 0.）

## 入れ方（管理者）

1. 公式 MCP Adapter と、このプラグインを入れて有効にします。
2. 「設定 → Ship Content MCP → 基本設定」で、使う投稿タイプ（初期は `works`）を確かめて、「MCP を受け付ける」を有効にします。
3. 「ユーザー → 新規追加」で、PM ごとに権限グループ「Content MCP Operator」のユーザーを作ります（投稿の編集などの権限は付けません）。
4. 「制作方針」に、サイト共通と投稿タイプ別の方針を書きます。
5. 「診断」で、問題がないか確かめます。

REST を止めるコード（セキュリティの Snippet など）があるサイトでは、MCP の入口 `ship-content/` を通すようにしてください。

## PM の端末でつなぐ（署名方式）

おすすめは「接続コード」です。管理者が「端末の鍵」で発行し、PM はコードをコピーしてから次を実行します（AI に任せる手順は [docs/PM_GUIDE.md](docs/PM_GUIDE.md) の 0.）。

```bash
node bridge/ship-content-mcp-bridge.mjs setup --clipboard --register
```

設定のファイル（`~/.ship-content-mcp/<名前>.json`、権限 600）を作り、つながるかを確かめ、Claude Code に登録します。

Claude デスクトップアプリ（チャット）では、管理画面からダウンロードしたこのサイト用の拡張機能（`.mcpb`）を開き、接続コードを貼るだけです（Node.js 不要）。ほかの AI には `setup --clipboard --print-config` で表示される設定を貼ります。

鍵を自分の端末で作る方式（keygen）も使えます。

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

## ツール

| ツール | すること |
|---|---|
| `ship-content-list-post-types` | 使える投稿タイプ・分類・状態・書き込めるか |
| `ship-content-get-creation-policy` | 制作方針（サイト共通・投稿タイプ別） |
| `ship-content-get-acf-schema` | 入力欄（ACF）の構造（field key・型・選択肢・レイアウト・入れ子・条件表示）と `schema_fingerprint` |
| `ship-content-search-posts` | 記事を探す（公開済み・下書き） |
| `ship-content-get-post` | 記事を読む（ACF の値は保存されたままの形。`if_match` 付き） |
| `ship-content-validate-draft` | 保存の前に確かめる（書かない。誤り・消える内容・差分） |
| `ship-content-create-draft` | 下書きを作る（状態は必ず下書き） |
| `ship-content-update-draft` | 下書きを直す（指定した部分だけ。消す変更は `allow_removals` が要る） |

| `ship-content-prepare-upload`・`ship-content-upload-chunk`・`ship-content-finish-upload` | 写真の分割転送（JPEG・PNG・WebP。位置情報などは消してから登録） |

中継プログラムは、ローカルのツール `ship-content-upload-local-image` も AI に見せます。AI は写真のファイルのパスを渡すだけで、読み込み・分割・送信は中継プログラムが行います。送れるのは `SHIP_MCP_UPLOAD_DIRS`（既定はホームフォルダ）の中の、隠しフォルダでない画像だけです。**メディアの写真は、記事が下書きのままでも URL を知っていれば見られます。** 公開してよい写真だけを登録してください。

ACF の値は、上の階層も入れ子も field key で指定します。flexible content の行は `acf_fc_layout` にレイアウトの name を入れます。

投稿タイプの状態：「未検証」は読み取りだけ、「試験中」は書き込みの試験（作った下書きに試験の印）、「試験合格」「稼働」で書き込めます。

## 手順書

- PM 向け：[docs/PM_GUIDE.md](docs/PM_GUIDE.md)（鍵の作り方・AI エージェントへの登録・頼み方・写真・困ったとき）
- 管理者向け：[docs/ADMIN_GUIDE.md](docs/ADMIN_GUIDE.md)（入れ方・投稿タイプの状態・構造の確認・PM のユーザーと鍵・制作方針の書き方・片付け）

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

管理用の WP-CLI：`wp ship-content-mcp enable|disable`、`wp ship-content-mcp keys list|add|revoke`（`add` は `--for-user=<ID> --label=<端末名> --public-key=<公開鍵>`）、`wp ship-content-mcp cleanup-tests [--yes]`（試験の下書きの片付け）。

## 配布・更新

- 配布は GitHub のリリースに添付した `ship-content-mcp-X.Y.Z.zip`（管理画面の「プラグイン > 新規追加 > アップロード」でも入れられる）。入れた後は、WordPress の「更新」画面に新しい版が出る（同梱の Plugin Update Checker）。
- PM 用の中継プログラム `ship-content-mcp-bridge.mjs` も、同じリリースに添付する。
- リリース・巻き戻しの手順は [docs/RELEASE.md](docs/RELEASE.md)、脆弱性の報告は [SECURITY.md](SECURITY.md)。

## ライセンス

GPL-2.0-or-later（同梱の Plugin Update Checker などは MIT。`ship-content-mcp/THIRD_PARTY_NOTICES.txt`）
