# リリースと更新・巻き戻しの手順

## 前提
- 配布は GitHub の **公開** リポジトリのリリースに添付した ZIP（`ship-content-mcp-X.Y.Z.zip`）だけ。各サイトのプラグインは同梱の Plugin Update Checker（v5.7）で12時間ごとにリリースを確認し、WordPress の「更新」画面に表示する。
- PM 用の中継プログラム（`ship-content-mcp-bridge.mjs`）も同じリリースに添付する（版はプラグインと同じ）。
- GitHub が自動で作るソース一式の ZIP は使わない（試験のファイルを含むため。プラグインは添付の ZIP がないリリースを無視する）。
- 認証トークンは使わない（各サイトに秘密を置かない）。リポジトリが非公開の間は、各サイトに更新は表示されない。
- **リポジトリの公開・リリースの公開・顧客サイトへの導入は、それぞれ人の事前承認を得てから行う。**

## 版を出す
1. 版を X.Y.Z にそろえる：`ship-content-mcp/ship-content-mcp.php` のヘッダー `Version` と定数 `SHIP_CONTENT_MCP_VERSION`、`readme.txt` の `Stable tag`、`bridge/ship-content-mcp-bridge.mjs` の `VERSION`。`CHANGELOG.md` と `readme.txt` の Changelog を書く。
2. 手元で検査する。
   - `tools/test.sh`
   - `python3 tools/secret_scan.py` と `python3 tools/secret_scan.py --history`
   - `python3 tools/build_release.py --expect-tag vX.Y.Z`
3. ステージングで自己テスト（`wp ship-content-mcp selftest`）と、中継プログラムからの接続・下書きの作成・プレビュー。
4. タグ `vX.Y.Z` を push すると、CI が同じ検査をして **下書き（draft）** のリリースに ZIP・manifest・SHA-256・中継プログラムを添付する。
5. 人が内容（SHA-256・変更履歴）を確認して **Publish release**。ここで初めて各サイトに更新が表示される。

## 各サイトでの更新
- 管理画面「プラグイン」または「ダッシュボード > 更新」で「今すぐ更新」（1クリック）。WP-CLI なら `wp plugin update ship-content-mcp`。
- 更新しても、設定・制作方針・端末の鍵・確かめた構造・記録（オプションと独自の表）は残る。表の変更が必要な版では、読み込み時に自動で更新する（`Installer::maybe_upgrade`、DB の版）。
- 更新後に「設定 → Ship Content MCP → 診断」を開き、赤（問題あり）がないことを確かめる。自己テスト（`wp ship-content-mcp selftest`）も通す。
- 中継プログラムに新しい版があるときは、PM に差し替えてもらう（リリースの `ship-content-mcp-bridge.mjs`）。

## 失敗したとき
- ZIP の取得・展開に失敗した場合、WordPress は元の版のまま（または自動で元に戻す）。プラグインは前の版で動き続ける。
- 原因（ネットワーク・ZIP の破損・権限）を確かめて、もう一度更新する。

## 巻き戻し（前の版に戻す）
1. GitHub のリリース一覧から、前の版の `ship-content-mcp-X.Y.Z.zip` を取得する（SHA-256 を確認）。
2. 管理画面「プラグイン > 新規追加 > プラグインのアップロード」でその ZIP を選び、「現在のものをアップロードしたもので置き換える」。WP-CLI なら `wp plugin install ship-content-mcp-X.Y.Z.zip --force`。
3. データ（設定・方針・鍵・記録・下書き）は残る。新しい版で表の形が変わっていた場合は、前の版が使わない列・表は無視される（列や表を消さない作りにする）。
4. 全サイトで問題がある版は、GitHub でそのリリースを Draft に戻し（各サイトの「最新」から外す）、修正版を出す。
5. 急ぐときは、各サイトで「MCP を受け付ける」を外す（入口がなくなる）。

## 更新の確認を止める・取得元を変える
- 止める：`wp-config.php` で `define( 'SHIP_CONTENT_MCP_DISABLE_UPDATES', true );`
- 取得元を変える（社内の別リポジトリなど）：`define( 'SHIP_CONTENT_MCP_UPDATE_REPO', 'https://github.com/<owner>/<repo>/' );`
