<?php
/**
 * 制作方針の出力の形（キットの policy_output.schema.json と照らす。Python の試験から呼ぶ）。
 *   php tests/php/policy_output_test.php > out.json
 */
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['opt'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['opt'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opt'][ $k ] = $v; return true; }
function sanitize_textarea_field( $s ) { return strip_tags( (string) $s ); }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
require __DIR__ . '/../../ship-content-mcp/src/Policy/Policies.php';
use ShipContentMcp\Policy\Policies;
$empty = Policies::for_output( 'works' );
Policies::save( '公開済み記事は変えない。', array( 'works' => "施工後の写真は基本PC2カラム。\r\n<b>写真1枚</b>なら1カラムでもよい。" ), 1 );
$saved = Policies::for_output( 'works' );
Policies::save( '公開済み記事は変えない。', array( 'works' => '変更後' ), 1 );
$p = Policies::get();
echo json_encode( array( 'empty' => $empty['data'], 'saved' => $saved['data'], 'revision' => $p['revision'], 'history0' => $p['history'][0]['text'] ?? null, 'history_len' => count( $p['history'] ) ), JSON_UNESCAPED_UNICODE );
