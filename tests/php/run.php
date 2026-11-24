<?php
/**
 * PHP 単体の試験：php tests/php/run.php
 */

require __DIR__ . '/bootstrap.php';

use ShipContentMcp\Admin\DesktopExtension;
use ShipContentMcp\Security\ConnectionCode;
use ShipContentMcp\Security\IpRules;
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

// Claude デスクトップの拡張機能（.mcpb）。
$m = DesktopExtension::manifest( 'example', '例のサイト', base64_encode( $pk ), '9.9.9' );
$check( '拡張機能：必須の項目', '0.3' === $m['manifest_version'] && 'ship-content-example' === $m['name'] && '9.9.9' === $m['version'] && '' !== $m['description'] && 'Ship Inc.' === $m['author']['name'] && 'node' === $m['server']['type'] );
$check( '拡張機能：接続コードは設定の欄から（秘密は入れない）', '${user_config.connection_code}' === $m['server']['mcp_config']['env']['SHIP_MCP_CODE'] && true === $m['user_config']['connection_code']['sensitive'] && true === $m['user_config']['connection_code']['required'] && false === strpos( (string) json_encode( $m ), 'seed' ) );
$check( '拡張機能：別のサイトのコードを止める印', base64_encode( $pk ) === $m['server']['mcp_config']['env']['SHIP_MCP_EXPECT_SERVER_KEY'] );
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
$check( '同梱の中継プログラムが bridge/ と同じ', file_get_contents( __DIR__ . '/../../ship-content-mcp/assets/bridge/ship-content-mcp-bridge.mjs' ) === file_get_contents( __DIR__ . '/../../bridge/ship-content-mcp-bridge.mjs' ) );

$fail = 0;
foreach ( $results as [ $name, $ok ] ) {
	echo ( $ok ? '[OK] ' : '[NG] ' ), $name, "\n";
	$fail += $ok ? 0 : 1;
}
echo count( $results ) - $fail, '/', count( $results ), " 合格\n";
exit( $fail > 0 ? 1 : 0 );
