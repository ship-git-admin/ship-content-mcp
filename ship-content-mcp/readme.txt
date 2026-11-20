=== Ship Content MCP ===
Contributors: shipinc
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.3.0-dev
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

許可した投稿タイプの下書きを、社内 PM の AI エージェントから MCP で作成・修正するためのプラグインです。

== Description ==

WordPress 公式の MCP Adapter（別プラグイン）の上で動く、自作の MCP サーバーです。
WordPress 側では AI を呼びません。公開・予約・削除・ACF の定義の変更はできません。

読み取り（投稿タイプ・制作方針・入力欄の構造・記事）、下書きの検証・作成・修正、写真の分割転送のツールがあります。

== Changelog ==

= 0.3.0-dev =
* 写真の分割転送（位置情報などを消して登録）、制作方針の画面の仕上げ。

= 0.2.0-dev =
* 下書きの検証・作成・修正、記事の検索・読み取り、ACF の値の検証、二重送信の防止、修正前の控え。

= 0.1.0-dev =
* 自作の MCP サーバー、読み取りのツール3つ、端末の署名による認証、管理画面（基本設定・制作方針・端末の鍵・診断・記録）。
