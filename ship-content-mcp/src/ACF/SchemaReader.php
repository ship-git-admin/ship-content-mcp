<?php
/**
 * ACF の構造をその場で読む（事前のマッピングを持たない。field key・レイアウトをコードに埋め込まない）。
 *
 * - 既存の投稿：acf_get_field_groups( post_id ) で、実際に効くグループを取る。
 * - 新しい下書き：ACF は投稿 ID がないと「分類の条件」を一致なしと判断するため、
 *   「ID なしの仮の投稿（post_id=0）＋予定の分類」で判定する。結果は推定（new_post_estimate）。
 *   保存の前には、実際の下書きで判定し直す（F2）。
 * - 返すのは、key・name・label・type・必須・選択肢・レイアウト・入れ子・条件表示・戻り値の形など。
 *   ACF の書き出しそのもの・値・管理者の情報は返さない。
 * - schema_fingerprint：構造が変わったことの検出用（ラベル・説明文は含めない）。
 *
 * @package ShipContentMcp
 */

namespace ShipContentMcp\ACF;

use ShipContentMcp\Content\PostTypes;
use ShipContentMcp\Core\DomainError;

defined( 'ABSPATH' ) || exit;

final class SchemaReader {

	/** F2 で書き込みに対応する予定の型（実機で試験してから supported にする）。 */
	public const WRITE_PLANNED = array( 'text', 'textarea', 'number', 'email', 'url', 'wysiwyg', 'select', 'radio', 'checkbox', 'button_group', 'true_false', 'image', 'relationship', 'post_object', 'group', 'repeater', 'flexible_content' );

	/** 見た目だけの型（値を持たない）。 */
	public const PRESENTATIONAL = array( 'tab', 'accordion', 'message' );

	/** 投稿タイプ以外で、どのグループが効くかを変える location の条件。 */
	private const DEPENDENT_PARAMS = array( 'post_taxonomy', 'post_category', 'post_format', 'post_status', 'post_template', 'page_template', 'post', 'current_user', 'current_user_role', 'post_level', 'page_type', 'page_parent' );

	private const MAX_DEPTH  = 12;
	private const MAX_FIELDS = 3000;

	private int $count = 0;

	/** @var array<string,int> */
	private array $unsupported = array();

	/**
	 * @param list<array{taxonomy:string,term_ids:list<int>}> $terms 新しい下書きのときの予定の分類
	 * @return array{data:array<string,mixed>,warnings:list<string>}
	 * @throws DomainError
	 */
	public function read( string $post_type, int $post_id = 0, array $terms = array() ): array {
		if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) ) {
			throw new DomainError( 'ACF-000', 'acf not active' );
		}
		$warnings = array();
		if ( $post_id > 0 ) {
			$post = get_post( $post_id );
			if ( ! $post || $post->post_type !== $post_type || ! in_array( $post->post_status, array( 'draft', 'pending', 'publish', 'future' ), true ) ) {
				throw new DomainError( 'POST-001', 'post missing, other type, or not readable status' );
			}
			$screen  = array(
				'post_id'   => $post_id,
				'post_type' => $post_type,
			);
			$context = array(
				'mode'    => 'post',
				'post_id' => $post_id,
				'status'  => $post->post_status,
			);
		} else {
			$by_tax = $this->terms_by_taxonomy( $post_type, $terms );
			$screen = array(
				'post_id'    => 0,
				'post_type'  => $post_type,
				'post_terms' => $by_tax,
			);
			$context    = array(
				'mode'  => 'new_post_estimate',
				'terms' => $terms,
			);
			$warnings[] = '新しい下書きの場合の推定です（投稿 ID がないため）。保存の前に、実際の下書きで入力欄を判定し直します。';
		}

		$groups = array();
		foreach ( (array) acf_get_field_groups( $screen ) as $group ) {
			$groups[] = $this->group( $group );
		}
		$dependencies = $this->dependencies( $post_type );
		if ( array() !== $dependencies ) {
			$warnings[] = '分類などの条件で、使われる入力欄のグループが変わります：' . implode( '、', $dependencies );
		}
		if ( array() !== $this->unsupported ) {
			$warnings[] = 'まだ書き込みに対応していない型があります：' . implode( '、', array_keys( $this->unsupported ) );
		}
		if ( array() === $groups ) {
			$warnings[] = 'この条件で使われる入力欄（ACF）のグループはありません。';
		}

		$data = array(
			'post_type'             => $post_type,
			'context'               => $context,
			'acf_version'           => defined( 'ACF_VERSION' ) ? (string) ACF_VERSION : '',
			'schema_fingerprint'    => self::fingerprint( $groups ),
			'field_groups'          => $groups,
			'location_dependencies' => $dependencies,
			'field_count'           => $this->count,
		);
		return array(
			'data'     => $data,
			'warnings' => $warnings,
		);
	}

	/**
	 * 構造の指紋（ラベル・説明文・選択肢の表示名は含めない）。
	 *
	 * @param list<array<string,mixed>> $groups
	 */
	public static function fingerprint( array $groups ): string {
		return 'sha256:' . hash( 'sha256', (string) wp_json_encode( self::normalize( $groups ) ) );
	}

	/**
	 * @param mixed $v
	 * @return mixed
	 */
	private static function normalize( $v ) {
		if ( ! is_array( $v ) ) {
			return $v;
		}
		$out = array();
		foreach ( $v as $k => $item ) {
			if ( in_array( $k, array( 'label', 'title', 'instructions', 'message', 'write_support', 'location_rules_summary' ), true ) ) {
				continue;
			}
			if ( 'choices' === $k && is_array( $item ) ) {
				$out[ $k ] = array_column( $item, 'value' );
				continue;
			}
			$out[ $k ] = self::normalize( $item );
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $group
	 * @return array<string,mixed>
	 */
	private function group( array $group ): array {
		$fields = array();
		foreach ( (array) acf_get_fields( $group['key'] ) as $field ) {
			$fields[] = $this->field( $field, 0 );
		}
		return array(
			'key'                    => (string) $group['key'],
			'title'                  => (string) ( $group['title'] ?? '' ),
			'location'               => $this->location( (array) ( $group['location'] ?? array() ) ),
			'location_rules_summary' => $this->location_summary( (array) ( $group['location'] ?? array() ) ),
			'fields'                 => $fields,
		);
	}

	/**
	 * @param array<string,mixed> $f
	 * @return array<string,mixed>
	 */
	private function field( array $f, int $depth ): array {
		++$this->count;
		if ( $depth > self::MAX_DEPTH || $this->count > self::MAX_FIELDS ) {
			throw new DomainError( 'ACF-002', 'schema too deep or too large' );
		}
		$type = (string) ( $f['type'] ?? '' );
		$out  = array(
			'key'      => (string) ( $f['key'] ?? '' ),
			'name'     => (string) ( $f['name'] ?? '' ),
			'label'    => $this->text( $f['label'] ?? '', 200 ),
			'type'     => $type,
			'required' => ! empty( $f['required'] ),
		);
		$instructions = $this->text( $f['instructions'] ?? '', 300 );
		if ( '' !== $instructions ) {
			$out['instructions'] = $instructions;
		}
		if ( in_array( $type, self::PRESENTATIONAL, true ) ) {
			$out['write_support'] = 'presentational';
			if ( 'message' === $type ) {
				$out['message'] = $this->text( $f['message'] ?? '', 300 );
			}
		} elseif ( in_array( $type, self::WRITE_PLANNED, true ) ) {
			$out['write_support'] = 'planned';
		} else {
			$out['write_support']       = 'unsupported';
			$this->unsupported[ $type ] = 1;
		}

		// 型ごとの設定（検証に効くものだけ）。
		foreach ( array( 'maxlength', 'rows', 'new_lines', 'min', 'max', 'step', 'allow_null', 'multiple', 'return_format', 'ui', 'layout', 'tabs', 'toolbar', 'media_upload', 'mime_types', 'min_width', 'min_height', 'max_width', 'max_height', 'min_size', 'max_size', 'library', 'field_type', 'add_term', 'save_terms', 'load_terms', 'other_choice', 'allow_custom', 'display_format', 'first_day', 'button_label', 'pagination' ) as $k ) {
			if ( array_key_exists( $k, $f ) && ( is_scalar( $f[ $k ] ) || null === $f[ $k ] ) && '' !== $f[ $k ] ) {
				$out[ $k ] = $f[ $k ];
			}
		}
		foreach ( array( 'post_type', 'taxonomy', 'filters', 'role' ) as $k ) {
			if ( isset( $f[ $k ] ) && ( is_array( $f[ $k ] ) || is_string( $f[ $k ] ) ) && array() !== $f[ $k ] && '' !== $f[ $k ] ) {
				$out[ $k ] = is_array( $f[ $k ] ) ? array_values( array_map( 'strval', $f[ $k ] ) ) : (string) $f[ $k ];
			}
		}
		if ( isset( $f['choices'] ) && is_array( $f['choices'] ) ) {
			$out['choices'] = array();
			foreach ( $f['choices'] as $value => $label ) {
				$out['choices'][] = array(
					'value' => (string) $value,
					'label' => $this->text( $label, 200 ),
				);
			}
		}
		if ( isset( $f['default_value'] ) && ( is_scalar( $f['default_value'] ) || is_array( $f['default_value'] ) ) && '' !== $f['default_value'] && array() !== $f['default_value'] ) {
			$out['default_value'] = is_array( $f['default_value'] ) ? array_values( array_map( 'strval', $f['default_value'] ) ) : $f['default_value'];
		}
		$logic = $this->conditional_logic( $f['conditional_logic'] ?? null );
		if ( null !== $logic ) {
			$out['conditional_logic'] = $logic;
		}
		if ( ! empty( $f['sub_fields'] ) && is_array( $f['sub_fields'] ) ) {
			$out['sub_fields'] = array();
			foreach ( $f['sub_fields'] as $sub ) {
				$out['sub_fields'][] = $this->field( (array) $sub, $depth + 1 );
			}
		}
		if ( 'flexible_content' === $type && ! empty( $f['layouts'] ) && is_array( $f['layouts'] ) ) {
			$out['layouts'] = array();
			foreach ( $f['layouts'] as $layout ) {
				$layout = (array) $layout;
				$subs   = array();
				foreach ( (array) ( $layout['sub_fields'] ?? array() ) as $sub ) {
					$subs[] = $this->field( (array) $sub, $depth + 1 );
				}
				$row = array(
					'key'        => (string) ( $layout['key'] ?? '' ),
					'name'       => (string) ( $layout['name'] ?? '' ),
					'label'      => $this->text( $layout['label'] ?? '', 200 ),
					'display'    => (string) ( $layout['display'] ?? '' ),
					'sub_fields' => $subs,
				);
				foreach ( array( 'min', 'max' ) as $k ) {
					if ( isset( $layout[ $k ] ) && '' !== $layout[ $k ] ) {
						$row[ $k ] = $layout[ $k ];
					}
				}
				$out['layouts'][] = $row;
			}
		}
		return $out;
	}

	/**
	 * @param mixed $logic
	 * @return list<list<array{field:string,operator:string,value:string}>>|null
	 */
	private function conditional_logic( $logic ): ?array {
		if ( ! is_array( $logic ) || array() === $logic ) {
			return null;
		}
		$out = array();
		foreach ( $logic as $or ) {
			$and = array();
			foreach ( (array) $or as $rule ) {
				$rule  = (array) $rule;
				$and[] = array(
					'field'    => (string) ( $rule['field'] ?? '' ),
					'operator' => (string) ( $rule['operator'] ?? '' ),
					'value'    => is_scalar( $rule['value'] ?? '' ) ? (string) ( $rule['value'] ?? '' ) : '',
				);
			}
			if ( array() !== $and ) {
				$out[] = $and;
			}
		}
		return array() === $out ? null : $out;
	}

	/**
	 * @param array<int,array<int,array<string,mixed>>> $location
	 * @return list<list<array{param:string,operator:string,value:string}>>
	 */
	private function location( array $location ): array {
		$out = array();
		foreach ( $location as $or ) {
			$and = array();
			foreach ( (array) $or as $rule ) {
				$rule  = (array) $rule;
				$and[] = array(
					'param'    => (string) ( $rule['param'] ?? '' ),
					'operator' => (string) ( $rule['operator'] ?? '' ),
					'value'    => is_scalar( $rule['value'] ?? '' ) ? (string) ( $rule['value'] ?? '' ) : '',
				);
			}
			$out[] = $and;
		}
		return $out;
	}

	/**
	 * @param array<int,array<int,array<string,mixed>>> $location
	 * @return list<string>
	 */
	private function location_summary( array $location ): array {
		$out = array();
		foreach ( $this->location( $location ) as $and ) {
			$out[] = implode( ' AND ', array_map( static fn( $r ) => $r['param'] . ' ' . $r['operator'] . ' ' . $r['value'], $and ) );
		}
		return $out;
	}

	/**
	 * この投稿タイプで、分類などの条件によって効くグループが変わる箇所。
	 *
	 * @return list<string>
	 */
	private function dependencies( string $post_type ): array {
		$out = array();
		foreach ( (array) acf_get_field_groups() as $group ) {
			if ( empty( $group['active'] ) ) {
				continue;
			}
			foreach ( $this->location( (array) ( $group['location'] ?? array() ) ) as $and ) {
				$mentions = false;
				$others   = array();
				foreach ( $and as $r ) {
					if ( 'post_type' === $r['param'] && '==' === $r['operator'] && $r['value'] === $post_type ) {
						$mentions = true;
					} elseif ( in_array( $r['param'], self::DEPENDENT_PARAMS, true ) ) {
						$others[] = $r['param'] . ' ' . $r['operator'] . ' ' . $r['value'];
					}
				}
				if ( $mentions ) {
					foreach ( $others as $o ) {
						$out[ $o ] = 1;
					}
				}
			}
		}
		return array_keys( $out );
	}

	/**
	 * @param list<array{taxonomy:string,term_ids:list<int>}> $terms
	 * @return array<string,list<int>>
	 * @throws DomainError
	 */
	private function terms_by_taxonomy( string $post_type, array $terms ): array {
		$allowed = PostTypes::taxonomy_names( $post_type );
		$out     = array();
		foreach ( $allowed as $tax ) {
			$out[ $tax ] = array();
		}
		foreach ( $terms as $row ) {
			$tax = (string) ( $row['taxonomy'] ?? '' );
			if ( ! in_array( $tax, $allowed, true ) ) {
				throw new DomainError( 'INPUT-001', 'taxonomy not allowed for post type', false, 'この投稿タイプでは使えない分類が指定されています' );
			}
			foreach ( (array) ( $row['term_ids'] ?? array() ) as $term_id ) {
				$term = get_term( (int) $term_id, $tax );
				if ( ! $term instanceof \WP_Term ) {
					throw new DomainError( 'INPUT-001', 'term not found', false, '指定の分類の項目が見つかりません' );
				}
				$out[ $tax ][] = (int) $term->term_id;
			}
		}
		return $out;
	}

	/**
	 * @param mixed $v
	 */
	private function text( $v, int $max ): string {
		if ( ! is_scalar( $v ) ) {
			return '';
		}
		$s = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( html_entity_decode( (string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ) );
		return mb_substr( $s, 0, $max );
	}
}
