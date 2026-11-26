<?php
/**
 * ACF の「条件で表示する」（conditional_logic）を、保存された値で確かめる（0.9.20）。
 *
 * - 条件の欄は、同じ行（group の中身・repeater の行・flexible の行）か、その上の階層にある欄。
 * - 条件の欄が見つからない・分からない演算子は「満たしている」とみなす（分からないものを隠さない）。
 *
 * @package ShipContentMcp
 */

namespace ShipContentMcp\ACF;

defined( 'ABSPATH' ) || exit;

final class Conditions {

	/**
	 * その欄が画面（編集画面）に出るか。
	 *
	 * @param array<string,mixed> $field SchemaReader の欄（conditional_logic は [[{field,operator,value}]]）
	 * @param array<string,mixed> $ctx   field key => 保存された値（同じ行と上の階層）
	 */
	public static function visible( array $field, array $ctx ): bool {
		$logic = $field['conditional_logic'] ?? null;
		if ( ! is_array( $logic ) || array() === $logic ) {
			return true;
		}
		foreach ( $logic as $and ) {
			$ok = true;
			foreach ( (array) $and as $rule ) {
				if ( ! self::rule( (array) $rule, $ctx ) ) {
					$ok = false;
					break;
				}
			}
			if ( $ok ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * どの条件で隠れているか（「表示形式」が「サブタイトル」のため、など）。分からなければ空。
	 *
	 * @param array<string,mixed>                $field
	 * @param array<string,mixed>                $ctx
	 * @param array<string,array<string,mixed>>  $siblings field key => 欄（ラベル・選択肢を引くため）
	 */
	public static function why_hidden( array $field, array $ctx, array $siblings ): string {
		$logic = $field['conditional_logic'] ?? null;
		if ( ! is_array( $logic ) ) {
			return '';
		}
		foreach ( $logic as $and ) {
			foreach ( (array) $and as $rule ) {
				$key = (string) ( $rule['field'] ?? '' );
				if ( '' === $key || ! array_key_exists( $key, $ctx ) || ! isset( $siblings[ $key ] ) ) {
					continue;
				}
				$s = $siblings[ $key ];
				return '「' . (string) ( $s['label'] ?? $key ) . '」が「' . self::choice_label( $s, $ctx[ $key ] ) . '」のため';
			}
		}
		return '';
	}

	/**
	 * 選択肢の値をラベルにする（ラベルがなければ値のまま）。
	 *
	 * @param array<string,mixed> $field
	 * @param mixed               $v
	 */
	public static function choice_label( array $field, $v ): string {
		$vals = is_array( $v ) ? $v : array( $v );
		$out  = array();
		foreach ( $vals as $one ) {
			$one   = is_scalar( $one ) ? (string) $one : '';
			$label = $one;
			foreach ( (array) ( $field['choices'] ?? array() ) as $c ) {
				if ( (string) ( $c['value'] ?? '' ) === $one ) {
					$label = (string) ( $c['label'] ?? $one );
					break;
				}
			}
			if ( '' !== $one ) {
				$out[] = $label;
			}
		}
		return implode( '・', $out );
	}

	/**
	 * @param array<string,mixed> $rule
	 * @param array<string,mixed> $ctx
	 */
	private static function rule( array $rule, array $ctx ): bool {
		$key = (string) ( $rule['field'] ?? '' );
		if ( '' === $key || ! array_key_exists( $key, $ctx ) ) {
			return true;
		}
		$v      = $ctx[ $key ];
		$want   = (string) ( $rule['value'] ?? '' );
		$vals   = is_array( $v ) ? array_map( static fn( $x ) => is_scalar( $x ) ? (string) $x : '', $v ) : array( is_scalar( $v ) ? (string) $v : '' );
		$empty  = array() === array_filter( $vals, static fn( $x ) => '' !== $x && '0' !== $x );
		$joined = implode( ',', $vals );
		switch ( (string) ( $rule['operator'] ?? '' ) ) {
			case '==':
				return in_array( $want, $vals, true );
			case '!=':
				return ! in_array( $want, $vals, true );
			case '==empty':
				return $empty;
			case '!=empty':
				return ! $empty;
			case '==contains':
				return '' !== $want && false !== strpos( $joined, $want );
			case '!=contains':
				return '' === $want || false === strpos( $joined, $want );
			default:
				return true;
		}
	}
}
