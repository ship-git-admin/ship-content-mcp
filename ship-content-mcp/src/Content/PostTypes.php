<?php
/**
 * 使ってよい投稿タイプ（管理者が選んだものだけ。毎回サーバー側で確かめる）。
 *
 * - 候補は「公開の投稿タイプで、管理画面があるもの」だけ。固定ページ・メディア・ACF の定義・JS/SCSS などの内部の投稿タイプは候補にしない。
 * - 状態：未検証 / 試験合格 / 稼働。書き込み（F2 以降）は未検証では許さない。
 *
 * @package ShipContentMcp
 */

namespace ShipContentMcp\Content;

use ShipContentMcp\Core\DomainError;
use ShipContentMcp\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class PostTypes {

	/** どんな設定でも使わせない投稿タイプ。 */
	public const DENY = array(
		'page',
		'attachment',
		'revision',
		'nav_menu_item',
		'custom_css',
		'customize_changeset',
		'oembed_cache',
		'user_request',
		'wp_block',
		'wp_template',
		'wp_template_part',
		'wp_global_styles',
		'wp_navigation',
		'wp_font_family',
		'wp_font_face',
		'acf-field-group',
		'acf-field',
		'acf-post-type',
		'acf-taxonomy',
		'acf-ui-options-page',
	);

	/** 分類の候補から外すもの。 */
	private const TAX_DENY = array( 'post_format', 'nav_menu', 'link_category', 'wp_theme', 'wp_template_part_area', 'wp_pattern_category' );

	/**
	 * 管理画面で選べる投稿タイプ。
	 *
	 * @return array<string,\WP_Post_Type>
	 */
	public static function candidates(): array {
		$out = array();
		foreach ( get_post_types( array( 'public' => true, 'show_ui' => true ), 'objects' ) as $name => $obj ) {
			if ( ! in_array( $name, self::DENY, true ) ) {
				$out[ $name ] = $obj;
			}
		}
		return $out;
	}

	/**
	 * いま使ってよい投稿タイプの名前。
	 *
	 * @return list<string>
	 */
	public static function allowed(): array {
		$candidates = self::candidates();
		$out        = array();
		foreach ( Settings::get()['post_types'] as $name => $row ) {
			if ( ! empty( $row['enabled'] ) && isset( $candidates[ $name ] ) ) {
				$out[] = (string) $name;
			}
		}
		return $out;
	}

	/**
	 * @throws DomainError 使えない投稿タイプのとき
	 */
	public static function assert_allowed( string $post_type ): void {
		if ( ! in_array( $post_type, self::allowed(), true ) ) {
			throw new DomainError( 'MCP-003', 'post_type not allowed' );
		}
	}

	/**
	 * @throws DomainError 書き込みが許可されていないとき
	 */
	public static function assert_writable( string $post_type ): void {
		self::assert_allowed( $post_type );
		if ( ! self::writable( $post_type ) ) {
			throw new DomainError( 'WRITE-001', 'post_type status is ' . self::status( $post_type ) );
		}
	}

	public static function writable( string $post_type ): bool {
		return in_array( self::status( $post_type ), Settings::WRITABLE_STATUSES, true );
	}

	/** 試験中の投稿タイプか（作った下書きに試験の印を付ける）。 */
	public static function testing( string $post_type ): bool {
		return 'testing' === self::status( $post_type );
	}

	public static function status( string $post_type ): string {
		return (string) ( Settings::get()['post_types'][ $post_type ]['status'] ?? 'unverified' );
	}

	/**
	 * その投稿タイプの分類（管理画面があるものだけ）。
	 *
	 * @return list<array<string,mixed>>
	 */
	public static function taxonomies( string $post_type ): array {
		$out = array();
		foreach ( get_object_taxonomies( $post_type, 'objects' ) as $name => $tax ) {
			if ( in_array( $name, self::TAX_DENY, true ) || ! $tax->show_ui ) {
				continue;
			}
			$out[] = array(
				'name'         => (string) $name,
				'label'        => (string) $tax->label,
				'hierarchical' => (bool) $tax->hierarchical,
			);
		}
		return $out;
	}

	/** list-post-types で返す語の数の上限（分類ごと）。 */
	public const TERMS_MAX = 300;

	/**
	 * 分類と、選べる語の一覧（list-post-types の返事。AI が分類を付けられるように。0.9.12）。足せるか（can_add_terms・add_under。0.9.13）。
	 *
	 * @return list<array<string,mixed>>
	 */
	public static function taxonomies_with_terms( string $post_type ): array {
		$out = array();
		foreach ( self::taxonomies( $post_type ) as $row ) {
			$tax   = get_taxonomy( $row['name'] );
			$terms = get_terms(
				array(
					'taxonomy'   => $row['name'],
					'hide_empty' => false,
					'number'     => self::TERMS_MAX + 1,
					'orderby'    => 'term_order',
				)
			);
			$list  = array();
			foreach ( is_array( $terms ) ? $terms : array() as $t ) {
				$list[] = array(
					'id'     => (int) $t->term_id,
					'name'   => (string) $t->name,
					'parent' => (int) $t->parent,
				);
			}
			$row['public'] = $tax ? (bool) $tax->public : false;
			$row['terms']  = array_slice( $list, 0, self::TERMS_MAX );
			// 合う語がないとき、AI が add-term で足せるか（0.9.13）。足せるなら、足してよい親（add_under。0 は一番上）。
			$row['can_add_terms'] = $row['public'] && TermService::enabled() && self::writable( $post_type );
			if ( $row['can_add_terms'] ) {
				$row['add_under'] = TermService::allowed_parents( $list, (bool) $row['hierarchical'] );
			}
			if ( count( $list ) > self::TERMS_MAX ) {
				$row['terms_truncated'] = true;
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * @return list<string>
	 */
	public static function taxonomy_names( string $post_type ): array {
		return array_column( self::taxonomies( $post_type ), 'name' );
	}
}
