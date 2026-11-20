<?php
/**
 * 写真の部品の試験（形式の判定・分割の大きさ・base64・位置情報の削除）。
 *   php tests/php/media_test.php
 * 試験画像は架空（tests/fixtures/synthetic_*）。手元に Imagick がなければ GD で消す。
 */

define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/../../ship-content-mcp/src/Core/DomainError.php';
require __DIR__ . '/../../ship-content-mcp/src/Media/ImageSniff.php';
require __DIR__ . '/../../ship-content-mcp/src/Media/MetadataStripper.php';

use ShipContentMcp\Media\ImageSniff;
use ShipContentMcp\Media\MetadataStripper;

$results = array();
$check   = static function ( string $name, bool $ok, string $note = '' ) use ( &$results ) {
	$results[] = array( $name, $ok, $note );
};
$fx = __DIR__ . '/../fixtures/';

// 形式の判定。
$check( 'JPEG の印', 'image/jpeg' === ImageSniff::sniff( (string) file_get_contents( $fx . 'synthetic_gps_orient6.jpg' ) ) );
$check( 'PNG の印', 'image/png' === ImageSniff::sniff( (string) file_get_contents( $fx . 'synthetic.png' ) ) );
$check( 'WebP の印', 'image/webp' === ImageSniff::sniff( (string) file_get_contents( $fx . 'synthetic.webp' ) ) );
$check( '画像でないものは null', null === ImageSniff::sniff( (string) file_get_contents( $fx . 'not_image.jpg' ) ) && null === ImageSniff::sniff( '<svg xmlns="http://www.w3.org/2000/svg"/>' ) && null === ImageSniff::sniff( "GIF89a\x01\x00" ) && null === ImageSniff::sniff( "PK\x03\x04" ) );
$check( '拡張子から MIME', 'image/jpeg' === ImageSniff::mime_for_name( 'A.JPG' ) && 'image/jpeg' === ImageSniff::mime_for_name( 'a.jpeg' ) && 'image/webp' === ImageSniff::mime_for_name( 'a.webp' ) );
$check( '受け付けない拡張子', null === ImageSniff::mime_for_name( 'a.svg' ) && null === ImageSniff::mime_for_name( 'a.gif' ) && null === ImageSniff::mime_for_name( 'a.php' ) && null === ImageSniff::mime_for_name( 'a.jpg.php' ) && null === ImageSniff::mime_for_name( 'noext' ) );

// 分割の大きさ。
$c = ImageSniff::CHUNK_SIZE;
$check( '分割の数', 1 === ImageSniff::chunks_for( 1 ) && 1 === ImageSniff::chunks_for( $c ) && 2 === ImageSniff::chunks_for( $c + 1 ) && 40 === ImageSniff::chunks_for( 20 * 1024 * 1024 ) );
$check( '各分割の大きさ', $c === ImageSniff::expected_length( $c + 10, 0 ) && 10 === ImageSniff::expected_length( $c + 10, 1 ) && -1 === ImageSniff::expected_length( $c + 10, 2 ) && -1 === ImageSniff::expected_length( 10, -1 ) );
$check( '分割の base64 は契約の上限（900,000 文字）以内', strlen( base64_encode( str_repeat( 'x', $c ) ) ) <= 900000 );

// base64。
$check( 'base64：正しい', 'abc' === ImageSniff::decode_base64( base64_encode( 'abc' ) ) );
$check( 'base64：改行・空白・記号は不可', null === ImageSniff::decode_base64( "YWJj\n" ) && null === ImageSniff::decode_base64( 'YW Jj' ) && null === ImageSniff::decode_base64( 'YW*j' ) && null === ImageSniff::decode_base64( '' ) && null === ImageSniff::decode_base64( 'YWJ' ) );

// 位置情報の削除（作業用の写しで）。
$tmp = sys_get_temp_dir() . '/scm_media_test_' . bin2hex( random_bytes( 4 ) ) . '.jpg';
copy( $fx . 'synthetic_gps_orient6.jpg', $tmp );
$before = getimagesize( $tmp );
$check( '試験画像に位置情報がある', MetadataStripper::has_gps( $tmp, 'image/jpeg' ) );
$r     = MetadataStripper::strip( $tmp, 'image/jpeg' );
$after = getimagesize( $tmp );
$exif  = @exif_read_data( $tmp, null, true );
$check( '位置情報を消した', $r['gps_found'] && $r['removed'] && ! MetadataStripper::has_gps( $tmp, 'image/jpeg' ), $r['engine'] );
$check( 'カメラの情報も消えた', ! is_array( $exif ) || empty( $exif['IFD0']['Make'] ) );
$check( '向き（Orientation 6）を写真に反映した（40×20 → 20×40）', 40 === $before[0] && 20 === $before[1] && 20 === $after[0] && 40 === $after[1], $after[0] . 'x' . $after[1] );
@unlink( $tmp );
$tmp2 = sys_get_temp_dir() . '/scm_media_test_' . bin2hex( random_bytes( 4 ) ) . '.png';
copy( $fx . 'synthetic.png', $tmp2 );
$r2 = MetadataStripper::strip( $tmp2, 'image/png' );
$check( 'PNG も作り直せる（透明を残す）', $r2['removed'] && 'image/png' === getimagesize( $tmp2 )['mime'] );
@unlink( $tmp2 );

$fail = 0;
foreach ( $results as [ $name, $ok, $note ] ) {
	echo ( $ok ? '[OK] ' : '[NG] ' ), $name, ( '' !== $note ? "  ({$note})" : '' ), "\n";
	$fail += $ok ? 0 : 1;
}
echo count( $results ) - $fail, '/', count( $results ), " 合格\n";
exit( $fail > 0 ? 1 : 0 );
