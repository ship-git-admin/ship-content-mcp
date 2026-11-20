<?php
/**
 * 投稿の読み取り（get-post・search-posts）と版の印（if_match）。
 *
 * - 読めるのは、使える投稿タイプの「下書き・レビュー待ち・公開済み・予約」だけ。非公開・ゴミ箱は読まない。
 * - ACF の値は「保存されたままの形」（入れ子も field key がキー、flexible の行は acf_fc_layout 付き）で返す。
 *   そのまま直して update-draft に送り返せる。
 * - 版の印は、題名・本文・状態・分類・アイキャッチ・ACF の値から作る（ACF の値だけが変わっても更新日時は動かないため）。
 * - 公開 URL は返さない。管理画面の編集画面の URL だけ（ログインが要る）。
 *
 * @package ShipContentMcp
 */

namespace ShipContentMcp\Content;

use ShipContentMcp\ACF\SchemaReader;
use ShipContentMcp\Core\DomainError;

defined( 'ABSPATH' ) || exit;

final class PostReader {

	public const READABLE_STATUSES = array( 'draft', 'pending', 'publish', 'future' );

	/**
	 * @throws DomainError
	 */
	public static function post( string $post_type, int $post_id ): \WP_Post {
		PostTypes::assert_allowed( $post_type );
		$post = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! $post instanceof \WP_Post || $post->post_type !== $post_type || ! in_array( $post->post_status, self::READABLE_STATUSES, true ) ) {
			throw new DomainError( 'POST-001', 'post missing, other type, or not readable status' );
		}
		return $post;
	}

	/**
	 * @return array{data:array<string,mixed>,warnings:list<string>}
	 * @throws DomainError
	 */
	public static function get( string $post_type, int $post_id ): array {
		$post   = self::post( $post_type, $post_id );
		$schema = ( new SchemaReader() )->read( $post_type, $post_id );
		$values = self::acf_values( $post_id, $schema['data']['field_groups'] );
		$acf    = array();
		foreach ( $schema['data']['field_groups'] as $g ) {
			foreach ( $g['fields'] as $f ) {
				if ( 'presentational' === ( $f['write_support'] ?? '' ) ) {
					continue;
				}
				$acf[] = array(
					'field_key' => $f['key'],
					'name'      => $f['name'],
					'type'      => $f['type'],
					'value'     => $values[ $f['key'] ] ?? null,
				);
			}
		}
		$data = array(
			'post'               => self::summary( $post ),
			'content'            => (string) $post->post_content,
			'acf'                => $acf,
			'schema_fingerprint' => $schema['data']['schema_fingerprint'],
			'if_match'           => self::token( $post, $schema['data']['field_groups'] ),
			'editable'           => 'draft' === $post->post_status && PostTypes::writable( $post_type ),
		);
		$warnings = $schema['warnings'];
		if ( 'draft' !== $post->post_status ) {
			$warnings[] = 'この投稿は下書きではないので、修正できません（参考として読むだけ）。';
		}
		return array(
			'data'     => $data,
			'warnings' => $warnings,
		);
	}

	/**
	 * @return array{data:array<string,mixed>,warnings:list<string>}
	 * @throws DomainError
	 */
	public static function search( string $post_type, string $query, string $status, int $limit, int $page ): array {
		PostTypes::assert_allowed( $post_type );
		$statuses = 'any' === $status ? array( 'draft', 'publish' ) : array( $status );
		$q        = new \WP_Query(
			array(
				'post_type'           => $post_type,
				'post_status'         => $statuses,
				's'                   => $query,
				'posts_per_page'      => $limit,
				'paged'               => $page,
				'orderby'             => 'modified',
				'order'               => 'DESC',
				'ignore_sticky_posts' => true,
				'no_found_rows'       => false,
				'suppress_filters'    => true,
			)
		);
		$rows = array();
		foreach ( $q->posts as $p ) {
			$rows[] = self::summary( $p );
		}
		return array(
			'data'     => array(
				'posts' => $rows,
				'total' => (int) $q->found_posts,
				'page'  => $page,
				'pages' => (int) $q->max_num_pages,
			),
			'warnings' => array(),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function summary( \WP_Post $post ): array {
		$terms = array();
		foreach ( PostTypes::taxonomy_names( $post->post_type ) as $tax ) {
			$ids = wp_get_object_terms( $post->ID, $tax, array( 'fields' => 'ids' ) );
			$terms[] = array(
				'taxonomy' => $tax,
				'term_ids' => is_array( $ids ) ? array_values( array_map( 'intval', $ids ) ) : array(),
			);
		}
		return array(
			'id'             => (int) $post->ID,
			'post_type'      => $post->post_type,
			'status'         => $post->post_status,
			'title'          => (string) $post->post_title,
			'modified_gmt'   => (string) $post->post_modified_gmt,
			'featured_media' => (int) get_post_thumbnail_id( $post->ID ),
			'taxonomies'     => $terms,
			'made_by_mcp'    => '' !== (string) get_post_meta( $post->ID, '_ship_content_mcp_created', true ),
			'admin_edit_url' => admin_url( 'post.php?post=' . (int) $post->ID . '&action=edit' ),
		);
	}

	/**
	 * 上の階層の ACF の値（保存されたままの形）。
	 *
	 * @param list<array<string,mixed>> $groups
	 * @return array<string,mixed>
	 */
	public static function acf_values( int $post_id, array $groups ): array {
		$out = array();
		if ( ! function_exists( 'get_field' ) ) {
			return $out;
		}
		foreach ( $groups as $g ) {
			foreach ( (array) ( $g['fields'] ?? array() ) as $f ) {
				if ( 'presentational' === ( $f['write_support'] ?? '' ) ) {
					continue;
				}
				$out[ (string) $f['key'] ] = get_field( (string) $f['key'], $post_id, false );
			}
		}
		return $out;
	}

	/**
	 * 版の印。
	 *
	 * @param list<array<string,mixed>> $groups
	 */
	public static function token( \WP_Post $post, array $groups ): string {
		$fresh = get_post( $post->ID );
		$post  = $fresh instanceof \WP_Post ? $fresh : $post;
		$sum   = self::summary( $post );
		$parts = array(
			$post->post_status,
			$post->post_title,
			$post->post_content,
			$post->post_excerpt,
			$sum['featured_media'],
			$sum['taxonomies'],
			self::canonical( self::acf_values( $post->ID, $groups ) ),
		);
		return 'rev:' . substr( hash( 'sha256', (string) wp_json_encode( $parts ) ), 0, 32 );
	}

	/**
	 * 比べるための形（数と文字列の違い、null と空文字の違いをなくす）。
	 *
	 * @param mixed $v
	 * @return mixed
	 */
	public static function canonical( $v ) {
		if ( is_array( $v ) ) {
			$out = array();
			foreach ( $v as $k => $item ) {
				$out[ $k ] = self::canonical( $item );
			}
			return $out;
		}
		if ( null === $v || false === $v ) {
			return '';
		}
		if ( true === $v ) {
			return '1';
		}
		return (string) $v;
	}
}
