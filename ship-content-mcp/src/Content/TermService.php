<?php
/**
 * 分類の語を足す（add-term。0.9.13）。
 *
 * - 管理者が基本設定の「AI が分類の語を足せる」（allow_add_terms、初期は入）を切にしたら足さない（TERM-001）。
 * - 書き込める投稿タイプの、公開される分類だけ（システムタグなど public=false の分類には足さない）。
 * - 親子のある分類では、親（その分類の語）の下にも、一番上（見出し）にも足せる。親子のない分類は一番上に足す（0.9.14 で、親と数の制限を外した）。
 * - 同じ親の下に同じ名前（全角・半角・大文字小文字・空白の違いは同じとみなす）があれば、作らずにそれを返す。
 * - 似た名前（片方がもう片方を含む・別の親の下に同じ名前）があれば、作らずに候補を返す（TERM-002）。
 *   PM に確かめてから new_anyway=true で足せる。
 * - 足した語には印（いつ・誰が）を付ける。試験中の投稿タイプなら試験の印も付け、cleanup-tests で片付ける（記事が付いていないものだけ）。
 * - WordPress の権限（manage_categories）は見ない。足してよいかは管理者の設定で決める（MCP のユーザーは権限の弱いユーザーのため）。
 *
 * @package ShipContentMcp
 */

namespace ShipContentMcp\Content;

use ShipContentMcp\Core\DomainError;
use ShipContentMcp\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class TermService {

	/** 足した時刻（UNIX 秒）。 */
	public const META_AT = '_ship_content_mcp_term_added_at';

	/** 足したユーザー。 */
	public const META_BY = '_ship_content_mcp_term_added_by';

	/** 試験の印（DraftService::META_TEST と同じ名前）。 */
	public const META_TEST = '_ship_content_mcp_test';

	/** 語の名前の長さの上限。 */
	public const NAME_MAX = 40;

	/**
	 * 名前を比べるための形（全角の英数字・空白は半角に、半角カナは全角に、空白は除き、小文字に）。
	 */
	public static function key( string $name ): string {
		$s = function_exists( 'mb_convert_kana' ) ? mb_convert_kana( $name, 'asKV', 'UTF-8' ) : $name;
		$s = (string) preg_replace( '/\s+/u', '', $s );
		return mb_strtolower( $s, 'UTF-8' );
	}

	/**
	 * 名前を整える（前後の空白を除き、続いた空白は1つに）。使えない名前なら INPUT-001。
	 *
	 * @throws DomainError 空・長すぎる・記号（< > " & や制御文字）を含むとき
	 */
	public static function clean_name( string $name ): string {
		$n = trim( (string) preg_replace( '/[\s\x{3000}]+/u', ' ', $name ) );
		if ( '' === $n || mb_strlen( $n ) > self::NAME_MAX || 1 === preg_match( '/[<>"&\x00-\x1F\x7F]/u', $n ) ) {
			throw new DomainError(
				'INPUT-001',
				'bad term name',
				false,
				'語の名前は1〜' . self::NAME_MAX . '文字で、< > " & は使えません',
				array(
					array(
						'path'    => 'name',
						'code'    => 'bad_name',
						'message' => '語の名前は1〜' . self::NAME_MAX . '文字で、< > " & は使えません',
					),
				)
			);
		}
		return $n;
	}

	/**
	 * 親として使えるか：一番上（0）か、その分類の語。親子のない分類は一番上だけ。
	 *
	 * @param list<array{id:int,name:string,parent:int}> $terms
	 */
	public static function valid_parent( array $terms, bool $hierarchical, int $parent ): bool {
		if ( 0 === $parent ) {
			return true;
		}
		return $hierarchical && in_array( $parent, array_map( 'intval', array_column( $terms, 'id' ) ), true );
	}

	/**
	 * 同じ親の下の同じ名前（same）と、似た名前（similar：片方がもう片方を含む・別の親の下に同じ名前）。
	 *
	 * @param list<array{id:int,name:string,parent:int}> $terms
	 * @return array{same:array{id:int,name:string,parent:int}|null,similar:list<array{id:int,name:string,parent:int}>}
	 */
	public static function find_similar( array $terms, string $name, int $parent ): array {
		$k       = self::key( $name );
		$same    = null;
		$similar = array();
		foreach ( $terms as $t ) {
			$tk = self::key( (string) $t['name'] );
			if ( $tk === $k ) {
				if ( (int) $t['parent'] === $parent ) {
					$same = $t;
				} else {
					$similar[] = $t;
				}
				continue;
			}
			if ( mb_strlen( $tk ) >= 2 && mb_strlen( $k ) >= 2 && ( str_contains( $tk, $k ) || str_contains( $k, $tk ) ) ) {
				$similar[] = $t;
			}
		}
		return array(
			'same'    => $same,
			'similar' => array_slice( $similar, 0, 10 ),
		);
	}

	/**
	 * その分類の語（id・名前・親）。
	 *
	 * @return list<array{id:int,name:string,parent:int}>
	 */
	public static function rows( string $taxonomy ): array {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => 2000,
			)
		);
		$out   = array();
		foreach ( is_array( $terms ) ? $terms : array() as $t ) {
			$out[] = array(
				'id'     => (int) $t->term_id,
				'name'   => (string) $t->name,
				'parent' => (int) $t->parent,
			);
		}
		return $out;
	}

	/** AI が語を足せる設定か。 */
	public static function enabled(): bool {
		return ! empty( Settings::get()['allow_add_terms'] );
	}

	/**
	 * 語を足す。
	 *
	 * @param array<string,mixed> $in post_type・taxonomy・name・parent・slug・new_anyway
	 * @return array<string,mixed> Abilities::run に返す形（data・warnings・meta・log）
	 * @throws DomainError 足せないとき
	 */
	public static function add( array $in ): array {
		$post_type = (string) ( $in['post_type'] ?? '' );
		PostTypes::assert_writable( $post_type );
		if ( ! self::enabled() ) {
			throw new DomainError( 'TERM-001', 'allow_add_terms is off' );
		}
		$taxonomy = (string) ( $in['taxonomy'] ?? '' );
		$tax      = get_taxonomy( $taxonomy );
		if ( ! $tax || ! in_array( $taxonomy, PostTypes::taxonomy_names( $post_type ), true ) ) {
			throw new DomainError( 'INPUT-001', 'taxonomy not allowed for post type', false, 'この投稿タイプでは使えない分類です（list-post-types の taxonomies の name を使ってください）', array( self::detail( 'taxonomy', 'taxonomy', 'この投稿タイプでは使えない分類です' ) ) );
		}
		if ( ! $tax->public ) {
			throw new DomainError( 'INPUT-001', 'taxonomy not public', false, '公開されない分類（システムタグなど）には、語を足せません（サイト管理者が管理する分類です）', array( self::detail( 'taxonomy', 'not_public', '公開されない分類には、語を足せません' ) ) );
		}
		$name   = self::clean_name( (string) ( $in['name'] ?? '' ) );
		$parent = (int) ( $in['parent'] ?? 0 );
		$slug   = (string) ( $in['slug'] ?? '' );
		$terms  = self::rows( $taxonomy );

		if ( ! self::valid_parent( $terms, (bool) $tax->hierarchical, $parent ) ) {
			$msg = $tax->hierarchical ? '親の語が見つかりません。parent は、この分類の語の id（list-post-types の terms の id）にするか、一番上に足すなら 0 にしてください' : 'この分類には親子がありません。parent は 0 にするか、省いてください';
			throw new DomainError( 'INPUT-001', 'parent not found: ' . $parent, false, $msg, array( self::detail( 'parent', 'parent', $msg ) ) );
		}

		$found = self::find_similar( $terms, $name, $parent );
		if ( null !== $found['same'] ) {
			return self::result( $taxonomy, (int) $found['same']['id'], false, array( '同じ親の下に同じ名前の語があったので、それを返しました（新しくは作っていません）。' ) );
		}
		if ( array() !== $found['similar'] && empty( $in['new_anyway'] ) ) {
			throw new DomainError(
				'TERM-002',
				'similar terms exist',
				false,
				'',
				array(
					array(
						'path'          => 'name',
						'code'          => 'similar',
						'message'       => '似た語があります。合う語があればそれを使ってください。どれも違うときは、PM に確かめてから new_anyway を true にして送り直してください',
						'similar_terms' => $found['similar'],
					),
				)
			);
		}
		if ( '' !== $slug && get_term_by( 'slug', $slug, $taxonomy ) ) {
			throw new DomainError( 'INPUT-001', 'slug in use', false, 'この slug は、ほかの語で使われています。別の slug にするか、slug を省いてください', array( self::detail( 'slug', 'slug_in_use', 'この slug は、ほかの語で使われています' ) ) );
		}
		$args = array( 'parent' => $parent );
		if ( '' !== $slug ) {
			$args['slug'] = $slug;
		}
		$r = wp_insert_term( $name, $taxonomy, $args );
		if ( is_wp_error( $r ) ) {
			$existing = (int) $r->get_error_data( 'term_exists' );
			if ( $existing > 0 ) {
				return self::result( $taxonomy, $existing, false, array( '同じ語がすでにあったので、それを返しました（新しくは作っていません）。' ) );
			}
			throw new DomainError( 'INPUT-001', 'wp_insert_term: ' . $r->get_error_code(), false, '語を足せませんでした：' . wp_strip_all_tags( $r->get_error_message() ) );
		}
		$id = (int) $r['term_id'];
		update_term_meta( $id, self::META_AT, time() );
		update_term_meta( $id, self::META_BY, get_current_user_id() );
		if ( PostTypes::testing( $post_type ) ) {
			update_term_meta( $id, self::META_TEST, '1' );
		}
		return self::result( $taxonomy, $id, true, array( '分類「' . (string) $tax->label . '」に、新しい語「' . $name . '」を足しました。PM への報告に、足した語を書いてください（テーマによっては、記事が公開される前でもサイトの絞り込みに出ます）。' ) );
	}

	/**
	 * @param list<string> $warnings
	 * @return array<string,mixed>
	 */
	private static function result( string $taxonomy, int $id, bool $created, array $warnings ): array {
		$t = get_term( $id, $taxonomy );
		return array(
			'data'     => array(
				'created' => $created,
				'term'    => array(
					'id'       => $id,
					'name'     => $t instanceof \WP_Term ? (string) $t->name : '',
					'slug'     => $t instanceof \WP_Term ? (string) $t->slug : '',
					'parent'   => $t instanceof \WP_Term ? (int) $t->parent : 0,
					'taxonomy' => $taxonomy,
				),
			),
			'warnings' => $warnings,
			'meta'     => array(
				'next' => 'この id を、create-draft の taxonomies（kind=taxonomy_terms）か、update-draft の changes（kind=taxonomy_terms）の term_ids に入れてください。update-draft では、その分類に付ける語をすべて並べます（足した語だけにすると、ほかの語が外れます）。',
			),
			'log'      => array( 'detail' => ( $created ? 'term added ' : 'term exists ' ) . $taxonomy . ' #' . $id ),
		);
	}

	/**
	 * @return array<string,string>
	 */
	private static function detail( string $path, string $code, string $message ): array {
		return array(
			'path'    => $path,
			'code'    => $code,
			'message' => $message,
		);
	}

	/**
	 * MCP で足した語（新しい順。管理画面の一覧用）。
	 *
	 * @return list<\WP_Term>
	 */
	public static function recent( int $limit = 20 ): array {
		$terms = get_terms(
			array(
				'taxonomy'   => array_values( get_taxonomies() ),
				'hide_empty' => false,
				'number'     => $limit,
				'meta_key'   => self::META_AT, // phpcs:ignore WordPress.DB.SlowDBQuery
				'orderby'    => 'meta_value_num',
				'order'      => 'DESC',
			)
		);
		return is_array( $terms ) ? array_values( array_filter( $terms, static fn( $t ) => $t instanceof \WP_Term ) ) : array();
	}

	/**
	 * 試験中に MCP で足した語のうち、記事が付いていないもの（cleanup-tests で消す）。
	 *
	 * @return list<\WP_Term>
	 */
	public static function test_terms_without_posts(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => array_values( get_taxonomies() ),
				'hide_empty' => false,
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'   => self::META_TEST,
						'value' => '1',
					),
					array(
						'key'     => self::META_AT,
						'compare' => 'EXISTS',
					),
				),
			)
		);
		$out   = array();
		foreach ( is_array( $terms ) ? $terms : array() as $t ) {
			if ( $t instanceof \WP_Term && array() === (array) get_objects_in_term( $t->term_id, $t->taxonomy ) ) {
				$out[] = $t;
			}
		}
		return $out;
	}
}
