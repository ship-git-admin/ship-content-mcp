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

use ShipContentMcp\ACF\Conditions;
use ShipContentMcp\ACF\SchemaReader;
use ShipContentMcp\ACF\Validator;
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
			'view'               => self::view( $schema['data']['field_groups'], $values ),
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
	 * 見た目の組み立て（0.9.20）：画面に出る値だけを、欄の名前（ラベル）で並べる。
	 * 条件（conditional_logic）で表示されない欄・空の欄は入れない。画像・関連は #ID、選択肢は「ラベル（値）」。
	 * 見本・参考にする記事の組み立てと書き方を読むためのもの（保存には acf の形を使う）。
	 *
	 * @param list<array<string,mixed>> $groups
	 * @param array<string,mixed>       $values field key => 保存されたままの値
	 * @return array<string,mixed>
	 */
	public static function view( array $groups, array $values ): array {
		$fields = array();
		foreach ( $groups as $g ) {
			foreach ( (array) ( $g['fields'] ?? array() ) as $f ) {
				if ( 'presentational' !== ( $f['write_support'] ?? '' ) ) {
					$fields[] = $f;
				}
			}
		}
		return self::view_row( $fields, $values, array() );
	}

	/**
	 * @param list<array<string,mixed>> $fields
	 * @param array<string,mixed>       $row        field key => 値
	 * @param array<string,mixed>       $parent_ctx 上の階層の値（条件に使う）
	 * @return array<string,mixed>
	 */
	private static function view_row( array $fields, array $row, array $parent_ctx ): array {
		$ctx = $row + $parent_ctx;
		$out = array();
		foreach ( $fields as $f ) {
			if ( ! Conditions::visible( $f, $ctx ) ) {
				continue;
			}
			$v = self::view_value( $f, $row[ (string) ( $f['key'] ?? '' ) ] ?? null, $ctx );
			if ( null === $v ) {
				continue;
			}
			$label = (string) ( $f['label'] ?? '' );
			if ( '' === $label ) {
				$label = (string) ( $f['name'] ?? '' );
			}
			if ( array_key_exists( $label, $out ) ) {
				$label .= '（' . (string) ( $f['name'] ?? '' ) . '）';
			}
			$out[ $label ] = $v;
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $f
	 * @param mixed               $v
	 * @param array<string,mixed> $ctx
	 * @return mixed 出さないときは null
	 */
	private static function view_value( array $f, $v, array $ctx ) {
		switch ( (string) ( $f['type'] ?? '' ) ) {
			case 'group':
				if ( ! is_array( $v ) ) {
					return null;
				}
				$o = self::view_row( (array) ( $f['sub_fields'] ?? array() ), $v, $ctx );
				return array() === $o ? null : $o;
			case 'repeater':
				if ( ! is_array( $v ) ) {
					return null;
				}
				$rows = array();
				foreach ( $v as $r ) {
					if ( is_array( $r ) ) {
						$o = self::view_row( (array) ( $f['sub_fields'] ?? array() ), $r, $ctx );
						if ( array() !== $o ) {
							$rows[] = $o;
						}
					}
				}
				return array() === $rows ? null : $rows;
			case 'flexible_content':
				if ( ! is_array( $v ) ) {
					return null;
				}
				$layouts = array();
				foreach ( (array) ( $f['layouts'] ?? array() ) as $l ) {
					$layouts[ (string) ( $l['name'] ?? '' ) ] = $l;
				}
				$rows = array();
				foreach ( $v as $r ) {
					if ( ! is_array( $r ) ) {
						continue;
					}
					$name   = (string) ( $r['acf_fc_layout'] ?? '' );
					$l      = $layouts[ $name ] ?? null;
					$label  = null !== $l ? (string) ( $l['label'] ?? '' ) : '';
					$rows[] = array( 'ブロック' => '' !== $label ? $label . '（' . $name . '）' : $name )
						+ ( null !== $l ? self::view_row( (array) ( $l['sub_fields'] ?? array() ), $r, $ctx ) : array() );
				}
				return array() === $rows ? null : $rows;
			case 'true_false':
				return ( is_scalar( $v ) && '1' === (string) $v ) ? 'はい' : null;
			case 'radio':
			case 'select':
			case 'button_group':
			case 'checkbox':
				if ( Validator::is_empty( $v ) ) {
					return null;
				}
				$label = Conditions::choice_label( $f, $v );
				$raw   = is_array( $v ) ? implode( ',', array_map( static fn( $x ) => is_scalar( $x ) ? (string) $x : '', $v ) ) : ( is_scalar( $v ) ? (string) $v : '' );
				return $label === $raw ? $label : $label . '（' . $raw . '）';
			case 'image':
			case 'file':
				$id = is_array( $v ) ? (int) ( $v['ID'] ?? ( $v['id'] ?? 0 ) ) : ( is_scalar( $v ) ? (int) $v : 0 );
				return $id > 0 ? '#' . $id : null;
			case 'gallery':
			case 'relationship':
			case 'post_object':
			case 'taxonomy':
			case 'user':
				$list = is_array( $v ) ? $v : ( Validator::is_empty( $v ) ? array() : array( $v ) );
				$ids  = array();
				foreach ( $list as $x ) {
					$id = is_array( $x ) ? (int) ( $x['ID'] ?? ( $x['id'] ?? 0 ) ) : ( is_scalar( $x ) ? (int) $x : 0 );
					if ( $id > 0 ) {
						$ids[] = '#' . $id;
					}
				}
				return array() === $ids ? null : implode( ' ', $ids );
			default:
				if ( is_array( $v ) ) {
					return Validator::is_empty( $v ) ? null : $v;
				}
				if ( ! is_scalar( $v ) ) {
					return null;
				}
				$s = trim( str_replace( "\r\n", "\n", (string) $v ) );
				return '' === $s ? null : $s;
		}
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
