<?php
/**
 * 言語をまたいだ試験の相手（Node の試験から呼ぶ）。
 *
 *   php codec_cli.php verify-request  '<json: {pk, sig, method, host, uri, timestamp, nonce, keyId, sessionId, body}>'
 *   php codec_cli.php sign-response   '<json: {nonce, status, sessionId, body}>'   → {"pk":..., "sig":...}
 *   php codec_cli.php make-code       '<json: {url, basic_auth?}>'                    → {"code":..., "pk":...}（接続コード。鍵の組はここで作る）
 */

require __DIR__ . '/bootstrap.php';

use ShipContentMcp\Security\ConnectionCode;
use ShipContentMcp\Security\SignatureCodec;

$mode = $argv[1] ?? '';
$in   = json_decode( $argv[2] ?? '{}', true );
if ( ! is_array( $in ) ) {
	fwrite( STDERR, "bad json\n" );
	exit( 2 );
}

if ( 'verify-request' === $mode ) {
	$pk  = SignatureCodec::decode_public_key( (string) $in['pk'] );
	$sig = SignatureCodec::decode_signature( (string) $in['sig'] );
	$msg = SignatureCodec::canonical_request( $in['method'], $in['host'], $in['uri'], $in['timestamp'], $in['nonce'], $in['keyId'], $in['sessionId'], $in['body'] );
	echo ( null !== $pk && null !== $sig && SignatureCodec::verify( $sig, $msg, $pk ) ) ? 'OK' : 'NG';
	exit( 0 );
}

if ( 'sign-response' === $mode ) {
	$pair = sodium_crypto_sign_keypair();
	$msg  = SignatureCodec::canonical_response( $in['nonce'], (int) $in['status'], $in['sessionId'], $in['body'] );
	echo json_encode(
		array(
			'pk'  => base64_encode( sodium_crypto_sign_publickey( $pair ) ),
			'sig' => SignatureCodec::sign( $msg, sodium_crypto_sign_secretkey( $pair ) ),
		)
	);
	exit( 0 );
}

if ( 'make-code' === $mode ) {
	$pair   = sodium_crypto_sign_keypair();
	$fields = array(
		'v'          => 1,
		'site'       => '試験のサイト',
		'name'       => ConnectionCode::default_name( (string) $in['url'] ),
		'url'        => (string) $in['url'],
		'key_id'     => 'k0123456789abcdef',
		'seed'       => base64_encode( substr( sodium_crypto_sign_secretkey( $pair ), 0, 32 ) ),
		'server_key' => base64_encode( sodium_crypto_sign_publickey( sodium_crypto_sign_keypair() ) ),
	);
	if ( isset( $in['basic_auth'] ) ) {
		$fields['basic_auth'] = (string) $in['basic_auth'];
	}
	echo json_encode(
		array(
			'code' => ConnectionCode::encode( $fields ),
			'pk'   => base64_encode( sodium_crypto_sign_publickey( $pair ) ),
		)
	);
	exit( 0 );
}

fwrite( STDERR, "unknown mode\n" );
exit( 2 );
