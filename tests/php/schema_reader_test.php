<?php
/**
 * ACF の構造を読む部品の試験（WordPress・ACF の関数は最小限の代わりを使う）。
 *
 *   php tests/php/schema_reader_test.php <合成fixture の JSON>
 *
 * fixture はキット付属の examples/acf_schema.synthetic.json（架空。実在のキーではない）。
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'ACF_VERSION', '6.8.11-test' );

// ---- WordPress・ACF の代わり --------------------------------------------------

class WP_Term {
	public int $term_id;
	public string $taxonomy;
	public string $slug;
	public function __construct( int $id, string $tax, string $slug ) {
		$this->term_id  = $id;
		$this->taxonomy = $tax;
		$this->slug     = $slug;
	}
}

$GLOBALS['fx_groups'] = array();
$GLOBALS['fx_posts']  = array();
$GLOBALS['fx_terms']  = array( 5 => new WP_Term( 5, 'sys_tag', 'old_layout' ) );
$GLOBALS['fx_last_screen'] = null;

function wp_json_encode( $v, $flags = 0 ) {
	return json_encode( $v, $flags );
}
function wp_strip_all_tags( $s ) {
	return trim( strip_tags( (string) $s ) );
}
function get_post( $id ) {
	return $GLOBALS['fx_posts'][ $id ] ?? null;
}
function get_term( $id, $tax ) {
	$t = $GLOBALS['fx_terms'][ $id ] ?? null;
	return ( $t && $t->taxonomy === $tax ) ? $t : null;
}
function get_object_taxonomies( $pt, $output ) {
	return array(
		'works_cat' => (object) array( 'label' => '施工カテゴリ', 'hierarchical' => true, 'show_ui' => true ),
		'sys_tag'   => (object) array( 'label' => 'システム', 'hierarchical' => false, 'show_ui' => true ),
	);
}
/** location の評価は ACF の考え方をまねる（post_taxonomy は post_id がないと一致なし）。 */
function fx_match( array $rule, array $screen ): bool {
	if ( 'post_type' === $rule['param'] ) {
		$r = ( $screen['post_type'] ?? '' ) === $rule['value'];
	} elseif ( 'post_taxonomy' === $rule['param'] ) {
		if ( ! isset( $screen['post_id'] ) ) {
			return false;
		}
		[ $tax, $slug ] = explode( ':', $rule['value'] );
		$ids = $screen['post_terms'][ $tax ] ?? array();
		$r   = false;
		foreach ( $GLOBALS['fx_terms'] as $t ) {
			if ( $t->taxonomy === $tax && $t->slug === $slug && in_array( $t->term_id, $ids, true ) ) {
				$r = true;
			}
		}
	} else {
		$r = false;
	}
	return '!=' === $rule['operator'] ? ! $r : $r;
}
function acf_get_field_groups( $filter = array() ) {
	if ( array() === $filter ) {
		return $GLOBALS['fx_groups'];
	}
	$GLOBALS['fx_last_screen'] = $filter;
	$out = array();
	foreach ( $GLOBALS['fx_groups'] as $g ) {
		foreach ( $g['location'] as $and ) {
			$all = true;
			foreach ( $and as $rule ) {
				$all = $all && fx_match( $rule, $filter );
			}
			if ( $all ) {
				$out[] = $g;
				break;
			}
		}
	}
	return $out;
}
function acf_get_fields( $key ) {
	foreach ( $GLOBALS['fx_groups'] as $g ) {
		if ( $g['key'] === $key ) {
			return $g['fields'];
		}
	}
	return array();
}
function get_option( $k, $d = false ) {
	return $d;
}

require __DIR__ . '/../../ship-content-mcp/src/Core/DomainError.php';
require __DIR__ . '/../../ship-content-mcp/src/Core/Settings.php';
require __DIR__ . '/../../ship-content-mcp/src/Content/PostTypes.php';
require __DIR__ . '/../../ship-content-mcp/src/ACF/SchemaReader.php';

use ShipContentMcp\ACF\SchemaReader;
use ShipContentMcp\Core\DomainError;

// ---- fixture ---------------------------------------------------------------

$fixture = json_decode( (string) file_get_contents( $argv[1] ?? '' ), true );
if ( ! is_array( $fixture ) ) {
	fwrite( STDERR, "fixture を読めません\n" );
	exit( 2 );
}
$build = static function ( array $fx ): array {
	$groups = array();
	foreach ( $fx['field_groups'] as $g ) {
		$g['location'] = array( $fx['location']['all'] );
		$g['active']   = true;
		$groups[]      = $g;
	}
	// 分類で切り替わる、もう1つのグループ（移設の版）。
	$groups[] = array(
		'key'      => 'group_EXAMPLE_FAKE_OLD',
		'title'    => '施工事例（架空・移設）',
		'active'   => true,
		'location' => array(
			array(
				array( 'param' => 'post_type', 'operator' => '==', 'value' => 'works' ),
				array( 'param' => 'post_taxonomy', 'operator' => '==', 'value' => 'sys_tag:old_layout' ),
			),
		),
		'fields'   => array( array( 'key' => 'field_EXAMPLE_FAKE_old', 'name' => 'old', 'label' => '旧本文', 'type' => 'wysiwyg' ) ),
	);
	return $groups;
};
$GLOBALS['fx_groups'] = $build( $fixture );
$GLOBALS['fx_posts']  = array(
	10 => (object) array( 'post_type' => 'works', 'post_status' => 'draft' ),
	11 => (object) array( 'post_type' => 'works', 'post_status' => 'private' ),
	12 => (object) array( 'post_type' => 'post', 'post_status' => 'draft' ),
);

$results = array();
$check   = static function ( string $name, bool $ok, string $note = '' ) use ( &$results ) {
	$results[] = array( $name, $ok, $note );
};

// 新しい下書き（分類なし）→ 架空の施工事例グループだけ。
$a = ( new SchemaReader() )->read( 'works' );
$titles = array_column( $a['data']['field_groups'], 'title' );
$check( '新しい下書きの推定：分類なしは通常版', array( '施工事例（架空）' ) === $titles, implode( ',', $titles ) );
$check( 'ACF に渡す画面：post_id=0・予定の分類', 0 === $GLOBALS['fx_last_screen']['post_id'] && array() === $GLOBALS['fx_last_screen']['post_terms']['sys_tag'] );
$check( '推定の注意が出る', (bool) preg_grep( '/推定/u', $a['warnings'] ) );
$check( '分類で変わることを知らせる', (bool) preg_grep( '/sys_tag:old_layout/u', $a['warnings'] ) && in_array( 'post_taxonomy != sys_tag:old_layout', $a['data']['location_dependencies'], true ) );

// 入れ子・レイアウト・選択肢。
$after = $a['data']['field_groups'][0]['fields'][1];
$check( 'Flexible Content のレイアウト', 'flexible_content' === $after['type'] && 'image_grid' === $after['layouts'][0]['name'] );
$grid = $after['layouts'][0]['sub_fields'][0];
$check( '選択肢は順番どおりの一覧', array( 'col1', 'col2', 'col3' ) === array_column( $grid['choices'], 'value' ) && '2カラム' === $grid['choices'][1]['label'] );
$check( '書き込みの対応予定', 'planned' === $grid['write_support'] && 'planned' === $after['write_support'] );
$check( '欄の数', 4 === $a['data']['field_count'], (string) $a['data']['field_count'] );

// 予定の分類に old_layout → 移設版。
$b = ( new SchemaReader() )->read( 'works', 0, array( array( 'taxonomy' => 'sys_tag', 'term_ids' => array( 5 ) ) ) );
$check( '予定の分類で切り替わる', array( '施工事例（架空・移設）' ) === array_column( $b['data']['field_groups'], 'title' ) );
$check( '分類が変われば指紋も変わる', $a['data']['schema_fingerprint'] !== $b['data']['schema_fingerprint'] );

// 指紋：ラベルを変えても同じ、キーを変えると変わる、選択肢を足すと変わる。
$fp     = $a['data']['schema_fingerprint'];
$again  = ( new SchemaReader() )->read( 'works' );
$check( '指紋は安定', $fp === $again['data']['schema_fingerprint'] );
$fx2 = $fixture;
$fx2['field_groups'][0]['fields'][0]['label']  = '施工の概要（言い換え）';
$fx2['field_groups'][0]['title']               = '別の題名';
$fx2['field_groups'][0]['fields'][1]['layouts'][0]['sub_fields'][0]['choices']['col2'] = 'PC 2列';
$GLOBALS['fx_groups'] = $build( $fx2 );
$check( 'ラベル・題名・選択肢の表示名を変えても指紋は同じ', $fp === ( new SchemaReader() )->read( 'works' )['data']['schema_fingerprint'] );
$fx3 = $fixture;
$fx3['field_groups'][0]['fields'][0]['key'] = 'field_EXAMPLE_FAKE_intro2';
$GLOBALS['fx_groups'] = $build( $fx3 );
$check( 'field key が変わると指紋が変わる', $fp !== ( new SchemaReader() )->read( 'works' )['data']['schema_fingerprint'] );
$fx4 = $fixture;
$fx4['field_groups'][0]['fields'][1]['layouts'][0]['sub_fields'][0]['choices']['col4'] = '4カラム';
$GLOBALS['fx_groups'] = $build( $fx4 );
$check( '選択肢が増えると指紋が変わる', $fp !== ( new SchemaReader() )->read( 'works' )['data']['schema_fingerprint'] );
$fx5 = $fixture;
unset( $fx5['field_groups'][0]['fields'][1]['layouts'][0]['sub_fields'][0]['choices']['col2'] );
$GLOBALS['fx_groups'] = $build( $fx5 );
$gone = ( new SchemaReader() )->read( 'works' );
$check( '2カラムの選択肢が消えたら、選択肢の一覧から消える（方針を当てはめられないことに AI が気づける）', array( 'col1', 'col3' ) === array_column( $gone['data']['field_groups'][0]['fields'][1]['layouts'][0]['sub_fields'][0]['choices'], 'value' ) );
$GLOBALS['fx_groups'] = $build( $fixture );

// 既存の投稿。
$c = ( new SchemaReader() )->read( 'works', 10 );
$check( '既存の下書き：mode=post', 'post' === $c['data']['context']['mode'] && 10 === $GLOBALS['fx_last_screen']['post_id'] );
$denied = static function ( callable $fn ): string {
	try {
		$fn();
		return '';
	} catch ( DomainError $e ) {
		return $e->error_code();
	}
};
$check( '非公開の投稿は読まない', 'POST-001' === $denied( fn() => ( new SchemaReader() )->read( 'works', 11 ) ) );
$check( '別の投稿タイプの投稿は読まない', 'POST-001' === $denied( fn() => ( new SchemaReader() )->read( 'works', 12 ) ) );
$check( 'ない投稿は読まない', 'POST-001' === $denied( fn() => ( new SchemaReader() )->read( 'works', 999 ) ) );
$check( '使えない分類は拒否', 'INPUT-001' === $denied( fn() => ( new SchemaReader() )->read( 'works', 0, array( array( 'taxonomy' => 'category', 'term_ids' => array( 1 ) ) ) ) ) );
$check( 'ない分類の項目は拒否', 'INPUT-001' === $denied( fn() => ( new SchemaReader() )->read( 'works', 0, array( array( 'taxonomy' => 'sys_tag', 'term_ids' => array( 999 ) ) ) ) ) );

// 返さないもの：値・書き出しそのもの。
$json = json_encode( $a['data'], JSON_UNESCAPED_UNICODE );
$check( '返す形に、ACF の値やグループの内部情報（ID・hide_on_screen など）を含めない', ! str_contains( $json, '"ID"' ) && ! str_contains( $json, 'hide_on_screen' ) && ! str_contains( $json, '"value":"架空' ) );

$fail = 0;
foreach ( $results as [ $name, $ok, $note ] ) {
	echo ( $ok ? '[OK] ' : '[NG] ' ), $name, ( '' !== $note ? "  ({$note})" : '' ), "\n";
	$fail += $ok ? 0 : 1;
}
echo count( $results ) - $fail, '/', count( $results ), " 合格\n";
exit( $fail > 0 ? 1 : 0 );
