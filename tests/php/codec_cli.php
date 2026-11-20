<?php
/**
 * 言語をまたいだ試験の相手（Node の試験から呼ぶ）。
 *
 *   php codec_cli.php verify-request  '<json: {pk, sig, method, host, uri, timestamp, nonce, keyId, sessionId, body}>'
 *   php codec_cli.php sign-response   '<json: {nonce, status, sessionId, body}>'   → {"pk":..., "sig":...}
 */

require __DIR__ . '/bootstrap.php';

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

fwrite( STDERR, "unknown mode\n" );
exit( 2 );
