<?php
/**
 * ACF の値の検証（Validator）と差分（Diff）の試験。WordPress の関数は最小限の代わりを使う。
 *   php tests/php/validator_test.php
 * 入力欄の構造は架空（実在のサイトのものではない）。
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['fx_posts'] = array(
	100 => (object) array( 'ID' => 100, 'post_type' => 'voice', 'post_status' => 'publish' ),
	101 => (object) array( 'ID' => 101, 'post_type' => 'voice', 'post_status' => 'draft' ),
	102 => (object) array( 'ID' => 102, 'post_type' => 'works', 'post_status' => 'publish' ),
	103 => (object) array( 'ID' => 103, 'post_type' => 'voice', 'post_status' => 'publish' ),
	104 => (object) array( 'ID' => 104, 'post_type' => 'voice', 'post_status' => 'publish' ),
	200 => (object) array( 'ID' => 200, 'post_type' => 'attachment', 'post_status' => 'inherit', 'file' => 'a.jpg', 'image' => true ),
	201 => (object) array( 'ID' => 201, 'post_type' => 'attachment', 'post_status' => 'inherit', 'file' => 'b.pdf', 'image' => false ),
	202 => (object) array( 'ID' => 202, 'post_type' => 'attachment', 'post_status' => 'inherit', 'file' => 'c.webp', 'image' => true ),
);
function get_post( $id ) {
	return $GLOBALS['fx_posts'][ (int) $id ] ?? null;
}
function wp_attachment_is_image( $id ) {
	return ! empty( $GLOBALS['fx_posts'][ (int) $id ]->image );
}
function get_attached_file( $id ) {
	return '/uploads/' . ( $GLOBALS['fx_posts'][ (int) $id ]->file ?? '' );
}
function wp_kses_post( $s ) {
	// 本物の kses と同じく、許されないタグはタグだけを消し、中の文字は残す（代わりの最小限）。
	$s = preg_replace( '#</?(script|style)\b[^>]*>#i', '', (string) $s );
	return preg_replace( '/\son\w+="[^"]*"/i', '', $s );
}
function is_email( $s ) {
	return false !== filter_var( $s, FILTER_VALIDATE_EMAIL );
}
function esc_url_raw( $url, $protocols = null ) {
	return preg_match( '#^(https?)://#i', (string) $url ) ? (string) $url : '';
}
function wp_strip_all_tags( $s ) {
	return trim( strip_tags( (string) $s ) );
}

require __DIR__ . '/../../ship-content-mcp/src/Core/DomainError.php';
require __DIR__ . '/../../ship-content-mcp/src/ACF/Conditions.php';
require __DIR__ . '/../../ship-content-mcp/src/ACF/Validator.php';
require __DIR__ . '/../../ship-content-mcp/src/Content/PostReader.php';
require __DIR__ . '/../../ship-content-mcp/src/Content/Diff.php';

use ShipContentMcp\ACF\Conditions;
use ShipContentMcp\ACF\Validator;
use ShipContentMcp\Content\PostReader;
use ShipContentMcp\Content\Diff;

$p  = static fn( string $key, string $type, array $extra = array() ) => array_merge(
	array(
		'key'           => $key,
		'name'          => str_replace( 'field_', '', $key ),
		'label'         => 'ラベル ' . $key,
		'type'          => $type,
		'required'      => false,
		'write_support' => in_array( $type, array( 'tab', 'accordion', 'message' ), true ) ? 'presentational' : ( in_array( $type, array( 'date_picker' ), true ) ? 'unsupported' : 'planned' ),
	),
	$extra
);
$ch = static fn( array $values ) => array_map( static fn( $v ) => array( 'value' => $v, 'label' => strtoupper( $v ) ), $values );

$groups = array(
	array(
		'key'    => 'group_FAKE',
		'title'  => '架空',
		'fields' => array(
			$p( 'field_t', 'text', array( 'maxlength' => 10, 'required' => true ) ),
			$p( 'field_ta', 'textarea' ),
			$p( 'field_w', 'wysiwyg' ),
			$p( 'field_sel', 'select', array( 'choices' => $ch( array( 'col1', 'col2' ) ) ) ),
			$p( 'field_selm', 'select', array( 'multiple' => 1, 'choices' => $ch( array( 'a', 'b', 'c' ) ) ) ),
			$p( 'field_cb', 'checkbox', array( 'choices' => $ch( array( 'x', 'y' ) ) ) ),
			$p( 'field_r', 'radio', array( 'choices' => $ch( array( '1', '2', '3' ) ) ) ),
			$p( 'field_tf', 'true_false' ),
			$p( 'field_n', 'number', array( 'min' => 0, 'max' => 100 ) ),
			$p( 'field_u', 'url' ),
			$p( 'field_e', 'email' ),
			$p( 'field_img', 'image', array( 'mime_types' => 'jpg, png' ) ),
			$p( 'field_rel', 'relationship', array( 'post_type' => array( 'voice' ), 'max' => 2 ) ),
			$p( 'field_g', 'group', array( 'sub_fields' => array( $p( 'field_g_a', 'text' ), $p( 'field_g_b', 'select', array( 'choices' => $ch( array( 'p', 'q' ) ) ) ) ) ) ),
			$p( 'field_rep', 'repeater', array( 'max' => 3, 'sub_fields' => array( $p( 'field_rep_a', 'text' ), $p( 'field_rep_img', 'image' ) ) ) ),
			$p(
				'field_fx',
				'flexible_content',
				array(
					'layouts' => array(
						array( 'key' => 'layout_1', 'name' => 'L1', 'label' => '文章', 'sub_fields' => array( $p( 'field_fx_a', 'text' ) ) ),
						array( 'key' => 'layout_2', 'name' => 'L2', 'label' => '写真', 'max' => 1, 'sub_fields' => array( $p( 'field_fx_g', 'group', array( 'sub_fields' => array( $p( 'field_fx_g_x', 'text' ) ) ) ) ) ),
					),
				)
			),
			$p( 'field_tab', 'tab' ),
			$p( 'field_dp', 'date_picker' ),
			$p( 'field_cond', 'text', array( 'conditional_logic' => array( array( array( 'field' => 'field_tf', 'operator' => '==', 'value' => '1' ) ) ) ) ),
		),
	),
);

$results = array();
$check   = static function ( string $name, bool $ok, string $note = '' ) use ( &$results ) {
	$results[] = array( $name, $ok, $note );
};
$run     = static function ( array $changes ) use ( $groups ): array {
	$v   = new Validator( $groups );
	$out = $v->changes( $changes );
	return array( $out, $v->errors(), $v->warnings(), $v );
};
$codes   = static fn( array $errors ) => implode( ',', array_column( $errors, 'code' ) );
$c       = static fn( string $key, $value ) => array( 'field_key' => $key, 'value' => $value );

// 1. 正しい値（型がそろう）。
[ $out, $err ] = $run(
	array(
		$c( 'field_t', '外壁塗装' ),
		$c( 'field_ta', "1行目\r\n2行目 A&B" ),
		$c( 'field_sel', 'col2' ),
		$c( 'field_selm', array( 'a', 'c', 'a' ) ),
		$c( 'field_cb', array() ),
		$c( 'field_r', '2' ),
		$c( 'field_tf', true ),
		$c( 'field_n', '42' ),
		$c( 'field_u', 'https://example.invalid/a?b=1' ),
		$c( 'field_e', 'pm@example.invalid' ),
		$c( 'field_img', '200' ),
		$c( 'field_rel', array( 100, '103' ) ),
		$c( 'field_g', array( 'field_g_a' => '中', 'field_g_b' => 'q' ) ),
		$c( 'field_rep', array( array( 'field_rep_a' => '1行目', 'field_rep_img' => 202 ), array( 'field_rep_a' => '2行目' ) ) ),
		$c( 'field_fx', array( array( 'acf_fc_layout' => 'L1', 'field_fx_a' => '文' ), array( 'acf_fc_layout' => 'L2', 'field_fx_g' => array( 'field_fx_g_x' => 'x' ) ) ) ),
	)
);
$check( '正しい値は通る', array() === $err, $codes( $err ) );
$check( '改行をそろえ、& は書き換えない（text・textarea）', "1行目\n2行目 A&B" === $out['field_ta'] );
$check( '選択肢（複数）の重複を除く', array( 'a', 'c' ) === $out['field_selm'] );
$check( 'true_false は 1/0', 1 === $out['field_tf'] );
$check( '数は数にする', 42 === $out['field_n'] );
$check( '画像・関連は数の ID', 200 === $out['field_img'] && array( 100, 103 ) === $out['field_rel'] );
$check( 'flexible の行に acf_fc_layout が残る', 'L2' === $out['field_fx'][1]['acf_fc_layout'] && 'x' === $out['field_fx'][1]['field_fx_g']['field_fx_g_x'] );

// 2〜. 誤り。
$cases = array(
	array( '知らない欄', array( $c( 'field_nope', 'x' ) ), 'unknown_field' ),
	array( '同じ欄を2回', array( $c( 'field_t', 'a' ), $c( 'field_t', 'b' ) ), 'duplicate' ),
	array( '文字数の上限', array( $c( 'field_t', '12345678901' ) ), 'too_long' ),
	array( '選択肢にない値', array( $c( 'field_sel', 'col3' ) ), 'choice' ),
	array( '選択肢の表示名は不可（value だけ）', array( $c( 'field_sel', 'COL1' ) ), 'choice' ),
	array( 'radio は空にできない（allow_null なし）', array( $c( 'field_r', null ) ), 'choice' ),
	array( 'true_false に文字', array( $c( 'field_tf', 'yes' ) ), 'type' ),
	array( '数の範囲', array( $c( 'field_n', 101 ) ), 'number_range' ),
	array( '数でない', array( $c( 'field_n', '1e' ) ), 'type' ),
	array( 'javascript: の URL', array( $c( 'field_u', 'javascript:alert(1)' ) ), 'invalid_url' ),
	array( 'メールの形', array( $c( 'field_e', 'pm@' ) ), 'invalid_email' ),
	array( '画像に URL', array( $c( 'field_img', 'https://example.invalid/a.jpg' ) ), 'type' ),
	array( '画像でない添付', array( $c( 'field_img', 201 ) ), 'attachment' ),
	array( '使えない画像の形式（webp）', array( $c( 'field_img', 202 ) ), 'attachment' ),
	array( 'ない画像', array( $c( 'field_img', 999 ) ), 'attachment' ),
	array( '関連：下書きは不可', array( $c( 'field_rel', array( 101 ) ) ), 'relation' ),
	array( '関連：違う投稿タイプ', array( $c( 'field_rel', array( 102 ) ) ), 'relation' ),
	array( '関連：件数の上限', array( $c( 'field_rel', array( 100, 103, 104 ) ) ), 'too_many' ),
	array( 'group：name で指定', array( $c( 'field_g', array( 'g_a' => 'x' ) ) ), 'unknown_sub_field' ),
	array( 'group：配列', array( $c( 'field_g', array( 'x' ) ) ), 'type' ),
	array( 'repeater：行数の上限', array( $c( 'field_rep', array( array(), array(), array(), array() ) ) ), 'too_many' ),
	array( 'repeater：行が配列', array( $c( 'field_rep', array( array( 'x' ) ) ) ), 'type' ),
	array( 'repeater：中の画像が不正', array( $c( 'field_rep', array( array( 'field_rep_img' => 201 ) ) ) ), 'attachment' ),
	array( 'flexible：ないレイアウト', array( $c( 'field_fx', array( array( 'acf_fc_layout' => 'L9' ) ) ) ), 'layout' ),
	array( 'flexible：レイアウトの上限', array( $c( 'field_fx', array( array( 'acf_fc_layout' => 'L2' ), array( 'acf_fc_layout' => 'L2' ) ) ) ), 'too_many' ),
	array( 'flexible：別のレイアウトの欄', array( $c( 'field_fx', array( array( 'acf_fc_layout' => 'L1', 'field_fx_g' => array() ) ) ) ), 'unknown_sub_field' ),
	array( 'flexible：入れ子の奥の誤り', array( $c( 'field_fx', array( array( 'acf_fc_layout' => 'L2', 'field_fx_g' => array( 'field_nope' => 1 ) ) ) ) ), 'unknown_sub_field' ),
	array( '見た目だけの欄', array( $c( 'field_tab', 'x' ) ), 'presentational' ),
	array( '未対応の型', array( $c( 'field_dp', '20261120' ) ), 'unsupported_type' ),
	array( 'value がない', array( array( 'field_key' => 'field_t' ) ), 'missing_value' ),
);
foreach ( $cases as [ $name, $changes, $expect ] ) {
	[ $out, $err ] = $run( $changes );
	$check( '拒否：' . $name, str_contains( $codes( $err ), $expect ), $codes( $err ) );
}
// 誤りの場所（path）。
[ , $err ] = $run( array( $c( 'field_fx', array( array( 'acf_fc_layout' => 'L1' ), array( 'acf_fc_layout' => 'L2', 'field_fx_g' => array( 'field_nope' => 1 ) ) ) ) ) );
$check( '誤りの場所が分かる', 'field_fx[1].field_fx_g.field_nope' === ( $err[0]['path'] ?? '' ), $err[0]['path'] ?? '' );
$check( '入れ子の欄の候補を教える', str_contains( $err[0]['message'] ?? '', 'field_fx_g_x' ) );

// 無害化・警告。
[ $out, $err, $warn ] = $run( array( $c( 'field_w', '<p onclick="x()">本文</p><script>alert(1)</script>' ), $c( 'field_cond', '条件の欄' ) ) );
$check( 'wysiwyg のスクリプトを消す', '<p>本文</p>' === $out['field_w'], $out['field_w'] ?? '' );
[ $out2 ] = $run( array( $c( 'field_ta', "前<script type=\"text/javascript\">alert(1)</script >後<STYLE>p{}</STYLE>" ) ) );
$check( 'script・style は中身ごと消す（文字を残さない）', '前後' === $out2['field_ta'], $out2['field_ta'] ?? '' );
$check( '消したことを警告', (bool) preg_grep( '/取り除きました/u', $warn ) );
$check( '条件の欄を送らないときは警告しない（分からないものは隠さない）', ! preg_grep( '/画面に出ない/u', $warn ) );
[ , , $warn ] = $run( array( $c( 'field_tf', 0 ), $c( 'field_cond', '条件の欄' ) ) );
$check( '条件を満たさない欄に書いたら警告（0.9.20）', (bool) preg_grep( '/画面に出ない欄.*ラベル field_cond/u', $warn ), implode( ' / ', $warn ) );
[ , , $warn ] = $run( array( $c( 'field_tf', 1 ), $c( 'field_cond', '条件の欄' ) ) );
$check( '条件を満たす欄は警告しない（0.9.20）', ! preg_grep( '/画面に出ない/u', $warn ), implode( ' / ', $warn ) );

// 条件表示（0.9.20）：表示形式で出る欄が変わる行。
$mode   = $p( 'field_m', 'radio', array( 'label' => '表示形式', 'choices' => array( array( 'value' => '01', 'label' => '見出し+本文' ), array( 'value' => '02', 'label' => '見出し' ), array( 'value' => '03', 'label' => '本文' ) ) ) );
$catch  = $p( 'field_mc', 'text', array( 'label' => '見出し', 'conditional_logic' => array( array( array( 'field' => 'field_m', 'operator' => '==', 'value' => '02' ) ), array( array( 'field' => 'field_m', 'operator' => '==', 'value' => '01' ) ) ) ) );
$body   = $p( 'field_mt', 'textarea', array( 'label' => '本文', 'conditional_logic' => array( array( array( 'field' => 'field_m', 'operator' => '==', 'value' => '03' ) ), array( array( 'field' => 'field_m', 'operator' => '==', 'value' => '01' ) ) ) ) );
$check( '条件：02 なら本文は出ない', ! Conditions::visible( $body, array( 'field_m' => '02' ) ) && Conditions::visible( $catch, array( 'field_m' => '02' ) ) );
$check( '条件：01 なら両方出る', Conditions::visible( $body, array( 'field_m' => '01' ) ) && Conditions::visible( $catch, array( 'field_m' => '01' ) ) );
$check( '条件：条件の欄がなければ出す', Conditions::visible( $body, array() ) );
$check( '条件：!=・==empty・!=empty', Conditions::visible( $p( 'x', 'text', array( 'conditional_logic' => array( array( array( 'field' => 'k', 'operator' => '!=', 'value' => 'a' ) ) ) ) ), array( 'k' => 'b' ) )
	&& Conditions::visible( $p( 'x', 'text', array( 'conditional_logic' => array( array( array( 'field' => 'k', 'operator' => '==empty', 'value' => '' ) ) ) ) ), array( 'k' => '' ) )
	&& ! Conditions::visible( $p( 'x', 'text', array( 'conditional_logic' => array( array( array( 'field' => 'k', 'operator' => '!=empty', 'value' => '' ) ) ) ) ), array( 'k' => '' ) ) );
$fxg = array( array( 'key' => 'group_V', 'title' => '架空', 'fields' => array(
	$p( 'field_vt', 'text', array( 'label' => '題' ) ),
	$p( 'field_vx', 'flexible_content', array( 'label' => '本文', 'layouts' => array(
		array( 'key' => 'l1', 'name' => 'Sub', 'label' => '見出し/本文', 'sub_fields' => array( $mode, $catch, $body ) ),
		array( 'key' => 'l2', 'name' => 'Pic', 'label' => '写真', 'sub_fields' => array( $p( 'field_pi', 'image', array( 'label' => '画像' ) ), $p( 'field_pc', 'text', array( 'label' => '説明' ) ), $p( 'field_pt', 'text', array( 'label' => 'タイトル' ) ), $p( 'field_pp', 'true_false', array( 'label' => '拡大' ) ) ) ),
	) ) ),
) ) );
$view = PostReader::view( $fxg, array(
	'field_vt' => ' 題名 ',
	'field_vx' => array(
		array( 'acf_fc_layout' => 'Sub', 'field_m' => '02', 'field_mc' => '高圧洗浄', 'field_mt' => '隠れている古い本文' ),
		array( 'acf_fc_layout' => 'Pic', 'field_pi' => '200', 'field_pc' => '下塗り', 'field_pt' => '', 'field_pp' => '0' ),
		array( 'acf_fc_layout' => 'Sub', 'field_m' => '03', 'field_mc' => '隠れている見出し', 'field_mt' => "本文の1行目\r\n2行目" ),
	),
) );
$check( '見た目：ラベルで並べる', '題名' === ( $view['題'] ?? '' ) && isset( $view['本文'] ), json_encode( $view, JSON_UNESCAPED_UNICODE ) );
$check( '見た目：ブロックの名前と選択肢のラベル（値）', '見出し/本文（Sub）' === ( $view['本文'][0]['ブロック'] ?? '' ) && '見出し（02）' === ( $view['本文'][0]['表示形式'] ?? '' ) );
$check( '見た目：条件で隠れた値は出さない', ! isset( $view['本文'][0]['本文'] ) && ! isset( $view['本文'][2]['見出し'] ) && "本文の1行目\n2行目" === ( $view['本文'][2]['本文'] ?? '' ), json_encode( $view, JSON_UNESCAPED_UNICODE ) );
$check( '見た目：画像は #ID、空の欄・いいえは出さない', '#200' === ( $view['本文'][1]['画像'] ?? '' ) && '下塗り' === ( $view['本文'][1]['説明'] ?? '' ) && ! isset( $view['本文'][1]['タイトル'] ) && ! isset( $view['本文'][1]['拡大'] ) );
$v2 = new Validator( $fxg );
$v2->changes( array( array( 'field_key' => 'field_vx', 'value' => array( array( 'acf_fc_layout' => 'Sub', 'field_m' => '02', 'field_mc' => '高圧洗浄', 'field_mt' => '見えない本文' ) ) ) ) );
$check( '行の中：表示形式で隠れる欄に書いたら、理由つきで警告', (bool) preg_grep( '/画面に出ない欄に値を入れています（「表示形式」が「見出し」のため.*：本文$/u', $v2->warnings() ), implode( ' / ', $v2->warnings() ) );
$v3 = new Validator( $fxg );
$v3->changes( array( array( 'field_key' => 'field_vx', 'value' => array( array( 'acf_fc_layout' => 'Sub', 'field_m' => '01', 'field_mc' => '高圧洗浄', 'field_mt' => '見える本文' ) ) ) ) );
$check( '行の中：表示される欄は警告しない', ! preg_grep( '/画面に出ない/u', $v3->warnings() ) );

// 必須。
[ , , , $v ] = $run( array() );
$check( '必須の欄が空なら教える', array( 'ラベル field_t' ) === $v->missing_required( array( 'field_t' => '' ) ) && array() === $v->missing_required( array( 'field_t' => 'x' ) ) );

// ---- Diff ------------------------------------------------------------------
$fields = array_column( $groups[0]['fields'], null, 'key' );
$rm     = static fn( string $k, $old, $new ) => Diff::removals( $fields[ $k ], $old, $new, $k );
$check( '消す：repeater の行が減る', 1 === count( $rm( 'field_rep', array( array( 'field_rep_a' => 'a' ), array( 'field_rep_a' => 'b' ) ), array( array( 'field_rep_a' => 'a' ) ) ) ) );
$check( '消さない：行の中で書かなかった欄', array() === $rm( 'field_rep', array( array( 'field_rep_a' => 'a', 'field_rep_img' => '200' ) ), array( array( 'field_rep_a' => 'b' ) ) ) );
$check( '消す：行の中の欄を空にする', 1 === count( $rm( 'field_rep', array( array( 'field_rep_a' => 'a' ) ), array( array( 'field_rep_a' => '' ) ) ) ) );
$check( '消す：flexible のレイアウトが変わる（中身あり）', 1 === count( $rm( 'field_fx', array( array( 'acf_fc_layout' => 'L1', 'field_fx_a' => '文' ) ), array( array( 'acf_fc_layout' => 'L2' ) ) ) ) );
$check( '消さない：空の行のレイアウトを変える', array() === $rm( 'field_fx', array( array( 'acf_fc_layout' => 'L1', 'field_fx_a' => '' ) ), array( array( 'acf_fc_layout' => 'L2' ) ) ) );
$check( '消す：文字を空にする', 1 === count( $rm( 'field_t', 'あり', '' ) ) );
$check( '消さない：空のままか、値を変えるだけ', array() === $rm( 'field_t', '', 'x' ) && array() === $rm( 'field_t', 'a', 'b' ) );
$check( '消す：関連が外れる', 1 === count( $rm( 'field_rel', array( '100', '103' ), array( 100 ) ) ) );
$check( '消さない：関連を足す', array() === $rm( 'field_rel', array( '100' ), array( 100, 103 ) ) );
$check( '消す：group の中を空にする', 1 === count( $rm( 'field_g', array( 'field_g_a' => 'x', 'field_g_b' => 'p' ), array( 'field_g_a' => '' ) ) ) );
$check( '消さない：true_false を 0 にする（値の変更で、消すことではない）', array() === $rm( 'field_tf', '1', 0 ) );

// 読み戻しの比べ方。
$check( '比べる：数と文字の違いは無視', array() === Diff::mismatches( array( 200, 1 ), array( '200', '1' ), 'x' ) );
$check( '比べる：送った欄だけ（ACF が返す、ほかの欄は無視）', array() === Diff::mismatches( array( array( 'field_rep_a' => 'a' ) ), array( array( 'field_rep_a' => 'a', 'field_rep_img' => '' ) ), 'r' ) );
$check( '比べる：違う値を見つける', array( 'r[0].field_rep_a' ) === Diff::mismatches( array( array( 'field_rep_a' => 'a' ) ), array( array( 'field_rep_a' => 'b' ) ), 'r' ) );
$check( '比べる：行の数の違い', 1 === count( Diff::mismatches( array( array(), array() ), array( array() ), 'r' ) ) );
$check( '比べる：空と 0（true_false）', array() === Diff::mismatches( 0, '', 'tf' ) );
$check( '要約は短く', '3件' === Diff::brief( array( 1, 2, 3 ) ) && mb_strlen( Diff::brief( str_repeat( 'あ', 100 ) ) ) <= 61 );

$fail = 0;
foreach ( $results as [ $name, $ok, $note ] ) {
	echo ( $ok ? '[OK] ' : '[NG] ' ), $name, ( '' !== $note && ! $ok ? "  ({$note})" : '' ), "\n";
	$fail += $ok ? 0 : 1;
}
echo count( $results ) - $fail, '/', count( $results ), " 合格\n";
exit( $fail > 0 ? 1 : 0 );
