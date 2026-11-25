<?php
/**
 * PHP 単体の試験：php tests/php/run.php
 */

require __DIR__ . '/bootstrap.php';

use ShipContentMcp\Admin\DesktopExtension;
use ShipContentMcp\Security\ConnectionCode;
use ShipContentMcp\Security\DeviceKeys;
use ShipContentMcp\Security\IpRules;
use ShipContentMcp\Security\OAuth;
use ShipContentMcp\Security\SignatureCodec;

$results = array();
$check   = static function ( string $name, bool $ok ) use ( &$results ) {
	$results[] = array( $name, $ok );
};

// 形の確認。
$check( 'key_id の形', SignatureCodec::valid_key_id( 'k0123456789abcdef' ) && ! SignatureCodec::valid_key_id( 'k0123' ) && ! SignatureCodec::valid_key_id( 'K0123456789ABCDEF' ) );
$check( 'timestamp の形', SignatureCodec::valid_timestamp( '1790000000' ) && ! SignatureCodec::valid_timestamp( '17900000001' ) && ! SignatureCodec::valid_timestamp( '-1' ) );
$check( 'nonce の形', SignatureCodec::valid_nonce( str_repeat( 'a', 22 ) ) && ! SignatureCodec::valid_nonce( str_repeat( 'a', 21 ) ) && ! SignatureCodec::valid_nonce( 'abc+/=' . str_repeat( 'a', 20 ) ) );
$check( 'session の形', SignatureCodec::valid_session_id( '' ) && SignatureCodec::valid_session_id( '0f8b7e2a-1c3d-4e5f-9a8b-7c6d5e4f3a2b' ) && ! SignatureCodec::valid_session_id( "a\nb" ) );

// 正規化された文字列。
$req = SignatureCodec::canonical_request( 'post', 'Example.Invalid:8080', '/wp-json/ship-content/mcp?x=1', '1790000000', 'nonce_nonce_nonce_nonc', 'k0123456789abcdef', 'sess', '{}' );
$check( 'リクエストの文字列', $req === "ship-content-mcp-req-v1\nPOST\nexample.invalid:8080\n/wp-json/ship-content/mcp?x=1\n1790000000\nnonce_nonce_nonce_nonc\nk0123456789abcdef\nsess\n" . hash( 'sha256', '{}' ) );
$res = SignatureCodec::canonical_response( 'nonce_nonce_nonce_nonc', 202, '', '' );
$check( '応答の文字列', $res === "ship-content-mcp-res-v1\nnonce_nonce_nonce_nonc\n202\n\n" . hash( 'sha256', '' ) );

// 署名。
$pair = sodium_crypto_sign_keypair();
$pk   = sodium_crypto_sign_publickey( $pair );
$sk   = sodium_crypto_sign_secretkey( $pair );
$sig  = (string) base64_decode( SignatureCodec::sign( $req, $sk ), true );
$check( '署名が通る', SignatureCodec::verify( $sig, $req, $pk ) );
$check( '1文字変えると通らない', ! SignatureCodec::verify( $sig, $req . ' ', $pk ) );
$other = sodium_crypto_sign_publickey( sodium_crypto_sign_keypair() );
$check( '別の鍵では通らない', ! SignatureCodec::verify( $sig, $req, $other ) );
$check( '壊れた署名で例外にならない', ! SignatureCodec::verify( str_repeat( "\0", 64 ), $req, $pk ) );
$check( '公開鍵の読み込み', null !== SignatureCodec::decode_public_key( base64_encode( $pk ) ) && null === SignatureCodec::decode_public_key( base64_encode( $pk . 'x' ) ) && null === SignatureCodec::decode_public_key( '!!' ) );
$check( '署名の読み込み', null !== SignatureCodec::decode_signature( base64_encode( $sig ) ) && null === SignatureCodec::decode_signature( base64_encode( 'short' ) ) );
$check( '時刻のずれ', SignatureCodec::fresh( '1790000000', 1790000300 ) && ! SignatureCodec::fresh( '1790000000', 1790000301 ) && SignatureCodec::fresh( '1790000300', 1790000000 ) );

// IP。
$check( 'IPv4 の CIDR', IpRules::allowed( '192.0.2.200', array( '192.0.2.128/25' ) ) && ! IpRules::allowed( '192.0.2.10', array( '192.0.2.128/25' ) ) );
$check( 'IPv4 の 1件', IpRules::allowed( '198.51.100.7', array( '198.51.100.7' ) ) && ! IpRules::allowed( '198.51.100.8', array( '198.51.100.7' ) ) );
$check( 'IPv6 の CIDR', IpRules::allowed( '2001:db8:abcd::1', array( '2001:db8::/32' ) ) && ! IpRules::allowed( '2001:db9::1', array( '2001:db8::/32' ) ) );
$check( 'v4 と v6 を混ぜない', ! IpRules::allowed( '::ffff:192.0.2.1', array( '192.0.2.0/24' ) ) );
$check( '空なら制限なし', IpRules::allowed( '203.0.113.1', array() ) );
$check( '不正な IP は拒否', ! IpRules::allowed( 'not-an-ip', array( '0.0.0.0/0' ) ) );
$check( '設定の形', IpRules::is_valid_entry( '10.0.0.0/8' ) && ! IpRules::is_valid_entry( '10.0.0.0/33' ) && ! IpRules::is_valid_entry( '10.0.0/8' ) && IpRules::is_valid_entry( '::1' ) && ! IpRules::is_valid_entry( '::1/129' ) );
$check( '/0 は全部', IpRules::allowed( '8.8.8.8', array( '0.0.0.0/0' ) ) );

// 接続コード（WordPress に依存しない部分）。
$fields = array(
	'v'          => 1,
	'site'       => '試験のサイト',
	'name'       => 'example',
	'url'        => 'https://example.invalid/wp-json/ship-content/mcp',
	'key_id'     => 'k0123456789abcdef',
	'seed'       => base64_encode( str_repeat( "\1", 32 ) ),
	'server_key' => base64_encode( $pk ),
	'basic_auth' => 'gate:p@ss/+=',
);
$code = ConnectionCode::encode( $fields );
$check( '接続コード：接頭辞と使える文字だけ', str_starts_with( $code, 'shipmcp1.' ) && 1 === preg_match( '/^shipmcp1\.[A-Za-z0-9_-]+$/', $code ) );
$check( '接続コード：読み戻すと同じ', ConnectionCode::decode( $code ) === $fields );
$check( '接続コード：文の中からも読める', ConnectionCode::decode( "例のサイトの接続コードです。\n" . $code . "\nよろしくお願いします" ) === $fields );
$check( '接続コード：ないとき・壊れたとき', null === ConnectionCode::decode( 'コードなし' ) && null === ConnectionCode::decode( 'shipmcp1.@@@' ) && null === ConnectionCode::decode( 'shipmcp1.' . rtrim( strtr( base64_encode( 'not json' ), '+/', '-_' ), '=' ) ) );
$check( '短い名前', 'example' === ConnectionCode::default_name( 'https://www.example.co.jp/wp-json/ship-content/mcp' ) && 'my-site-com' === ConnectionCode::default_name( 'http://my_site-com.check.example/x' ) && 'site' === ConnectionCode::default_name( 'not a url' ) );
$ep = 'http://stage.example.invalid/wp-json/ship-content/mcp';
$check( '入口：https・別ホストに変えてよい', 'https://www.example.invalid/wp-json/ship-content/mcp' === ConnectionCode::normalize_url( 'https://www.example.invalid/wp-json/ship-content/mcp', $ep ) && $ep === ConnectionCode::normalize_url( $ep, $ep ) );
$check( '入口：パス違い・ID 入り・別の方式は拒否', '' === ConnectionCode::normalize_url( 'https://www.example.invalid/wp-json/other', $ep ) && '' === ConnectionCode::normalize_url( 'https://u:p@www.example.invalid/wp-json/ship-content/mcp', $ep ) && '' === ConnectionCode::normalize_url( 'ftp://www.example.invalid/wp-json/ship-content/mcp', $ep ) && '' === ConnectionCode::normalize_url( 'https://www.example.invalid/wp-json/ship-content/mcp#x', $ep ) );
$check( 'Basic 認証の形', ConnectionCode::valid_basic_auth( '' ) && ConnectionCode::valid_basic_auth( 'id:pw' ) && ! ConnectionCode::valid_basic_auth( 'nocolon' ) && ! ConnectionCode::valid_basic_auth( "id:pw\nx" ) );

// Claude デスクトップの拡張機能（.mcpb）。トークンを貼るだけ。
$m = DesktopExtension::manifest( 'example', '例のサイト', 'https://example.invalid/wp-json/ship-content/mcp', 'gate:pw', '9.9.9' );
$check( '拡張機能：必須の項目', '0.3' === $m['manifest_version'] && 'ship-content-example' === $m['name'] && '9.9.9' === $m['version'] && '' !== $m['description'] && 'Ship Inc.' === $m['author']['name'] && 'node' === $m['server']['type'] );
$check( '拡張機能：トークンは設定の欄から（ファイルには入れない）', '${user_config.token}' === $m['server']['mcp_config']['env']['SHIP_MCP_TOKEN'] && true === $m['user_config']['token']['sensitive'] && true === $m['user_config']['token']['required'] && 0 === preg_match( '/smcp_k[0-9a-f]{16}_/', (string) json_encode( $m ) ) );
$check( '拡張機能：入口と Basic 認証は入れておく（なければ入れない）', 'https://example.invalid/wp-json/ship-content/mcp' === $m['server']['mcp_config']['env']['SHIP_MCP_URL'] && 'gate:pw' === $m['server']['mcp_config']['env']['SHIP_MCP_BASIC_AUTH'] && ! isset( DesktopExtension::manifest( 'x', '', 'https://x.invalid/wp-json/ship-content/mcp', '', '1' )['server']['mcp_config']['env']['SHIP_MCP_BASIC_AUTH'] ) );
$check( '拡張機能：入口のファイルと引数が合う', 'server/ship-content-mcp-bridge.mjs' === $m['server']['entry_point'] && array( '${__dirname}/server/ship-content-mcp-bridge.mjs' ) === $m['server']['mcp_config']['args'] );
if ( class_exists( 'ZipArchive' ) ) {
	$tmp = tempnam( sys_get_temp_dir(), 'mcpb' );
	DesktopExtension::build( $tmp, $m, __DIR__ . '/../../bridge/ship-content-mcp-bridge.mjs' );
	$z = new ZipArchive();
	$z->open( $tmp );
	$names = array();
	for ( $i = 0; $i < $z->numFiles; $i++ ) {
		$names[] = $z->getNameIndex( $i );
	}
	$same = $z->getFromName( 'server/ship-content-mcp-bridge.mjs' ) === file_get_contents( __DIR__ . '/../../bridge/ship-content-mcp-bridge.mjs' );
	$back = json_decode( (string) $z->getFromName( 'manifest.json' ), true );
	$z->close();
	unlink( $tmp );
	$check( '拡張機能：ZIP の中身は manifest と中継プログラムだけ', array( 'manifest.json', 'server/ship-content-mcp-bridge.mjs' ) === $names && $same && $back === $m );
}

// トークン。
$tok = 'smcp_k0123456789abcdef_' . str_repeat( 'A', 42 ) . 'b';
$check( 'トークンの形', null !== DeviceKeys::parse_token( $tok ) && 'k0123456789abcdef' === DeviceKeys::parse_token( $tok )['key_id'] && null === DeviceKeys::parse_token( 'smcp_k0123_abc' ) && null === DeviceKeys::parse_token( $tok . 'x' ) && null === DeviceKeys::parse_token( 'Bearer ' . $tok ) );
$check( 'トークンのハッシュ（tok: 付き・64文字以内・秘密が違えば違う）', str_starts_with( DeviceKeys::token_hash( 'abc' ), 'tok:' ) && strlen( DeviceKeys::token_hash( 'abc' ) ) <= 64 && DeviceKeys::token_hash( 'abc' ) !== DeviceKeys::token_hash( 'abd' ) && DeviceKeys::is_token( array( 'public_key' => DeviceKeys::token_hash( 'x' ) ) ) && ! DeviceKeys::is_token( array( 'public_key' => base64_encode( $pk ) ) ) );
$cfg = ConnectionCode::client_configs( 'ship-example', 'http://stage.example.invalid/wp-json/ship-content/mcp', $tok, "gate:p'w" );
$check( 'AI の設定：Claude Code の1行（トークン・Basic 認証・入口）', str_starts_with( $cfg['claude_code'], "claude mcp add --transport http --scope user 'ship-example' 'http://stage.example.invalid/wp-json/ship-content/mcp'" ) && str_contains( $cfg['claude_code'], "--header 'X-Ship-MCP-Token: " . $tok . "'" ) && str_contains( $cfg['claude_code'], "--header 'Authorization: Basic " . base64_encode( "gate:p'w" ) . "'" ) );
$check( 'AI の設定：Codex（config.toml に足す）', str_contains( $cfg['codex'], '[mcp_servers.ship-example]' ) && str_contains( $cfg['codex'], 'url = "http://stage.example.invalid/wp-json/ship-content/mcp"' ) && str_contains( $cfg['codex'], '"X-Ship-MCP-Token" = "' . $tok . '"' ) && str_starts_with( $cfg['codex'], "cat >> ~/.codex/config.toml <<'EOF'" ) );
$j = json_decode( $cfg['json'], true );
$check( 'AI の設定：mcpServers の形', 'http' === $j['mcpServers']['ship-example']['type'] && $tok === $j['mcpServers']['ship-example']['headers']['X-Ship-MCP-Token'] );
$cfg2 = ConnectionCode::client_configs( 'ship-x', 'https://x.invalid/wp-json/ship-content/mcp', $tok, '' );
$check( 'AI に貼る文：名前・URL・ヘッダー・登録のしかたが入る', str_contains( $cfg['prompt'], '名前：ship-example' ) && str_contains( $cfg['prompt'], 'URL：http://stage.example.invalid/wp-json/ship-content/mcp' ) && str_contains( $cfg['prompt'], 'X-Ship-MCP-Token: ' . $tok ) && str_contains( $cfg['prompt'], 'Authorization: Basic ' . base64_encode( "gate:p'w" ) ) && str_contains( $cfg['prompt'], 'claude mcp add --transport http' ) && str_contains( $cfg['prompt'], 'config.toml' ) );
$check( 'AI の設定：Basic 認証がなければ入れない', ! str_contains( $cfg2['claude_code'], 'Authorization' ) && ! str_contains( $cfg2['codex'], 'Authorization' ) && ! str_contains( $cfg2['prompt'], 'Authorization' ) );
$check( '同梱の中継プログラムが bridge/ と同じ', file_get_contents( __DIR__ . '/../../ship-content-mcp/assets/bridge/ship-content-mcp-bridge.mjs' ) === file_get_contents( __DIR__ . '/../../bridge/ship-content-mcp-bridge.mjs' ) );

// OAuth（ブラウザで許可する接続）。
$inv = OAuth::new_invite_code();
$check( 'OAuth：招待コードの形（4文字ずつ5組・見間違えやすい文字なし）', 1 === preg_match( '/^[A-HJ-NP-Z2-9]{4}(-[A-HJ-NP-Z2-9]{4}){4}$/', $inv ) );
$check( 'OAuth：招待コードは小文字・空白・全角のハイフンでも読める', str_replace( '-', '', $inv ) === OAuth::normalize_invite( ' ' . strtolower( str_replace( '-', '－', $inv ) ) . "\n" ) && '' === OAuth::normalize_invite( 'ABCD-EFGH' ) && '' === OAuth::normalize_invite( str_repeat( 'O', 20 ) ) );
$check( 'OAuth：招待コードのハッシュ（inv: 付き・64文字以内）', str_starts_with( OAuth::invite_hash( 'A' ), 'inv:' ) && strlen( OAuth::invite_hash( str_repeat( 'A', 20 ) ) ) <= 64 && OAuth::invite_hash( 'A' ) !== OAuth::invite_hash( 'B' ) );
$check(
	'OAuth：行の種類',
	'invite' === OAuth::kind( array( 'public_key' => 'inv:x' ) ) && 'oauth' === OAuth::kind( array( 'public_key' => 'oau:x' ) ) && 'token' === OAuth::kind( array( 'public_key' => 'tok:x' ) ) && 'key' === OAuth::kind( array( 'public_key' => base64_encode( $pk ) ) )
);
$check( 'OAuth：期限', OAuth::expired( array( 'expires_at' => '2000-01-01 00:00:00' ) ) && ! OAuth::expired( array( 'expires_at' => '2999-01-01 00:00:00' ) ) && ! OAuth::expired( array( 'expires_at' => null ) ) );
$ver = str_repeat( 'a', 43 );
$chl = rtrim( strtr( base64_encode( hash( 'sha256', $ver, true ) ), '+/', '-_' ), '=' );
$check( 'OAuth：PKCE（S256）', OAuth::pkce_ok( $ver, $chl ) && ! OAuth::pkce_ok( $ver . 'b', $chl ) && ! OAuth::pkce_ok( 'short', $chl ) && ! OAuth::pkce_ok( $ver, '' ) );
$check(
	'OAuth：戻り先（このパソコンの中・アプリ専用の形だけ。https は設定で許したときだけ）',
	OAuth::valid_redirect( 'http://127.0.0.1:53682/callback', false ) && OAuth::valid_redirect( 'http://localhost/callback', false ) && OAuth::valid_redirect( 'http://[::1]:8000/cb', false )
	&& OAuth::valid_redirect( 'cursor://anysphere.cursor-mcp/oauth/callback', false ) && OAuth::valid_redirect( 'com.example.app:/oauth', false )
	&& ! OAuth::valid_redirect( 'https://claude.ai/api/mcp/auth_callback', false ) && OAuth::valid_redirect( 'https://claude.ai/api/mcp/auth_callback', true )
	&& ! OAuth::valid_redirect( 'http://evil.example/cb', true ) && ! OAuth::valid_redirect( 'http://127.0.0.1.evil.example/cb', false ) && ! OAuth::valid_redirect( 'http://user@127.0.0.1/cb', false )
	&& ! OAuth::valid_redirect( 'javascript:alert(1)', true ) && ! OAuth::valid_redirect( 'data:text/html,x', true ) && ! OAuth::valid_redirect( 'file:///etc/passwd', true ) && ! OAuth::valid_redirect( 'http://127.0.0.1/cb#x', false ) && ! OAuth::valid_redirect( '', false )
);
$check(
	'OAuth：戻り先の照合（ループバックはポートを問わない・パスは同じ）',
	OAuth::redirect_matches( 'http://127.0.0.1:61000/callback', array( 'http://127.0.0.1:53682/callback' ) ) && ! OAuth::redirect_matches( 'http://127.0.0.1:61000/other', array( 'http://127.0.0.1:53682/callback' ) )
	&& ! OAuth::redirect_matches( 'http://localhost:61000/callback', array( 'http://127.0.0.1:53682/callback' ) ) && OAuth::redirect_matches( 'cursor://a/cb', array( 'cursor://a/cb' ) ) && ! OAuth::redirect_matches( 'cursor://a/cb2', array( 'cursor://a/cb' ) )
);
$pr = OAuth::pm_prompt( 'ship-example', 'https://x.invalid/wp-json/ship-content/mcp' );
$check( 'OAuth：AI に貼る文（名前・URL・OAuth・登録のしかた。秘密は入らない）', str_contains( $pr, '名前：ship-example' ) && str_contains( $pr, 'URL：https://x.invalid/wp-json/ship-content/mcp' ) && str_contains( $pr, 'OAuth' ) && str_contains( $pr, 'claude mcp login ship-example' ) && str_contains( $pr, 'codex mcp login ship-example' ) && ! str_contains( $pr, 'smcp' ) && ! str_contains( $pr, 'Authorization' ) );

// つながらないときの案内・誰が直すか。
$check(
	'つながらないとき（OAuth）：見分け方・管理者に送る文・秘密を入れない指示が入る',
	str_contains( $pr, 'https://x.invalid/.well-known/oauth-protected-resource' ) && str_contains( $pr, '管理者に送る文' ) && str_contains( $pr, 'who_fixes' ) && str_contains( $pr, '招待コード・トークン・パスワードは入れない' ) && str_contains( $pr, '管理者には送らせず' )
);
$check(
	'つながらないとき（トークン）：見分け方・管理者に送る文が入る',
	str_contains( $cfg['prompt'], '"method":"ping"' ) && str_contains( $cfg['prompt'], '401 で MCP-002' ) && str_contains( $cfg['prompt'], '管理者に送る文' ) && ! str_contains( $cfg['prompt'], '.well-known' )
);
$check(
	'切れたとき：前は使えていた場合の直し方と、管理画面・ブラウザで代わりをしない指示が入る（OAuth・トークン）',
	str_contains( $pr, '前は使えていたのに' ) && str_contains( $pr, '新しい招待コードを頼む' ) && str_contains( $pr, 'WordPress の管理画面・サイトへのログイン・ブラウザの操作で代わりをしない' )
	&& str_contains( $cfg['prompt'], '前は使えていたのに' ) && str_contains( $cfg['prompt'], 'WordPress の管理画面・サイトへのログイン・ブラウザの操作で代わりをしない' )
	&& str_contains( $pr, "\n5. ツールの返事が ok:false" ) && str_contains( $cfg['prompt'], "\n4. ツールの返事が ok:false" )
);
$oauth_src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/ship-content-mcp/src/Security/OAuth.php' );
$check( 'OAuth：アクセストークンは接続の期限まで（1時間で切らない）', str_contains( $oauth_src, '$access_to = $grant_exp;' ) && ! str_contains( $oauth_src, 'ACCESS_TTL' ) );
$fix_ok = true;
foreach ( array_keys( \ShipContentMcp\Core\DomainError::MESSAGES ) as $c ) {
	$f       = \ShipContentMcp\Core\DomainError::FIX[ $c ] ?? null;
	$fix_ok  = $fix_ok && is_array( $f ) && in_array( $f[0], array( 'admin', 'pm', 'ai' ), true ) && '' !== $f[1];
}
$e1 = new \ShipContentMcp\Core\DomainError( 'WRITE-001' );
$e2 = new \ShipContentMcp\Core\DomainError( 'ACF-001' );
$check( '誤りのコードすべてに「誰が直すか」と「次にすること」がある', $fix_ok && 'admin' === $e1->who_fixes() && 'ai' === $e2->who_fixes() && str_contains( $e1->next_step(), '管理者に送る文' ) );

// AI がツールを探さなくても写真まで進める（0.9.5）：サーバーの案内・最初の返事・写真のツールの説明。
$tool_short = array_map( static fn( $t ) => substr( $t, strlen( 'ship-content/' ) ), \ShipContentMcp\MCP\Abilities::TOOLS );
$ins        = \ShipContentMcp\MCP\Server::INSTRUCTIONS;
$check(
	'サーバーの案内に、全部のツールの名前・写真の登録の仕方（英語の言葉も）・代わりをしないことがある',
	array() === array_filter( $tool_short, static fn( $t ) => ! str_contains( $ins, $t ) ) && str_contains( $ins, 'photo / image' ) && str_contains( $ins, 'attachment_id' ) && str_contains( $ins, 'ブラウザで代わりをしない' ) && mb_strlen( $ins ) <= 600
);
$steps = implode( "\n", \ShipContentMcp\MCP\Abilities::STEPS );
$check( '最初のツール（list-post-types）の返事の手順に、写真の登録から保存までがある', str_contains( $steps, 'get-upload-url' ) && str_contains( $steps, 'prepare-upload → upload-chunk → finish-upload' ) && str_contains( $steps, 'validate-draft' ) && str_contains( $steps, 'create-draft' ) && str_contains( $steps, 'update-draft' ) && str_contains( \ShipContentMcp\MCP\Abilities::IMAGE_HINT, 'get-upload-url' ) );
$abil_src = (string) file_get_contents( __DIR__ . '/../../ship-content-mcp/src/MCP/Abilities.php' );
$check( '写真の4つのツールの説明に英語の言葉（Upload a photo / image）があり、ない名前（upload-local-image）を書かない', 4 === substr_count( $abil_src, "'Upload a photo / image" ) && ! str_contains( $abil_src, 'upload-local-image' ) && ! str_contains( $ins, 'upload-local-image' ) );

$check( 'ツールの印に openWorldHint=false（このサイトの中だけ。Codex が写真の分割で承認を待って止まらないように。0.9.6）', 1 === substr_count( $abil_src, "'openWorldHint' => false," ) );

// 使い方タブ：使う画像がすべてあり、文にサイト固有の名前がない。
$help_src = (string) file_get_contents( __DIR__ . '/../../ship-content-mcp/src/Admin/HelpPage.php' );
preg_match_all( "/self::fig\\( '([a-z0-9_]+)'/", $help_src, $hm );
$help_imgs = array_unique( $hm[1] );
$missing   = array_filter( $help_imgs, static fn( $n ) => ! is_file( __DIR__ . '/../../ship-content-mcp/assets/help/' . $n . '.webp' ) );
preg_match( "/IMAGES = array\\((.*?)\\);/s", $help_src, $im );
preg_match_all( "/'([a-z0-9_]+)'/", $im[1] ?? '', $listed );
$check( '使い方タブ：使う画像がすべて assets/help にあり、一覧（IMAGES）とそろっている', count( $help_imgs ) >= 15 && array() === $missing && array() === array_diff( $help_imgs, $listed[1] ) && array() === array_diff( $listed[1], $help_imgs ) );
$check( '使い方タブ：サイトの値は差し込みで出す（サイト固有の名前は tools/secret_scan.py で検査）', str_contains( $help_src, '{site}' ) && str_contains( $help_src, '{https}' ) && str_contains( $help_src, '{invite_days}' ) );

$fail = 0;
foreach ( $results as [ $name, $ok ] ) {
	echo ( $ok ? '[OK] ' : '[NG] ' ), $name, "\n";
	$fail += $ok ? 0 : 1;
}
echo count( $results ) - $fail, '/', count( $results ), " 合格\n";
exit( $fail > 0 ? 1 : 0 );
