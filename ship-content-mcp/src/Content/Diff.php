<?php
/**
 * 変更の差分・消す変更の検出・読み戻しの比べ方。
 *
 * - 消す変更：いま値がある欄を空にする、repeater・flexible の行を減らす、選んだもの（関連・チェック）を減らす、
 *   flexible の行のレイアウトを変える（その行の中身が消える）。
 * - 入れ子で指定しなかった欄は、ACF が前の値を残すので、消す変更には数えない。
 * - 読み戻し：送った値に含まれる欄だけを比べる（ACF は指定しなかった欄も返すため）。数と文字列の違いは無視する。
 *
 * @package ShipContentMcp
 */

namespace ShipContentMcp\Content;

use ShipContentMcp\ACF\Validator;

defined( 'ABSPATH' ) || exit;

final class Diff {

	/**
	 * @param array<string,mixed> $field SchemaReader の欄
	 * @param mixed               $old   いまの値（保存されたままの形）
	 * @param mixed               $new   検証済みの新しい値
	 * @return list<string> 消える場所の説明
	 */
	public static function removals( array $field, $old, $new, string $path ): array {
		$type = (string) ( $field['type'] ?? '' );
		$out  = array();
		if ( 'group' === $type ) {
			$old  = is_array( $old ) ? $old : array();
			$subs = self::subs_by_key( (array) ( $field['sub_fields'] ?? array() ) );
			foreach ( (array) $new as $k => $v ) {
				if ( isset( $subs[ $k ] ) ) {
					$out = array_merge( $out, self::removals( $subs[ $k ], $old[ $k ] ?? null, $v, $path . '.' . $k ) );
				}
			}
			return $out;
		}
		if ( 'repeater' === $type || 'flexible_content' === $type ) {
			$old_rows = is_array( $old ) ? array_values( $old ) : array();
			$new_rows = is_array( $new ) ? array_values( $new ) : array();
			if ( count( $new_rows ) < count( $old_rows ) ) {
				$out[] = $path . '：行が ' . count( $old_rows ) . ' → ' . count( $new_rows ) . ' に減ります';
			}
			$layouts = array();
			foreach ( (array) ( $field['layouts'] ?? array() ) as $l ) {
				$layouts[ (string) $l['name'] ] = self::subs_by_key( (array) ( $l['sub_fields'] ?? array() ) );
			}
			$repeater_subs = self::subs_by_key( (array) ( $field['sub_fields'] ?? array() ) );
			foreach ( $new_rows as $i => $row ) {
				if ( ! isset( $old_rows[ $i ] ) || ! is_array( $old_rows[ $i ] ) || ! is_array( $row ) ) {
					continue;
				}
				$subs = $repeater_subs;
				if ( 'flexible_content' === $type ) {
					$before = (string) ( $old_rows[ $i ]['acf_fc_layout'] ?? '' );
					$after  = (string) ( $row['acf_fc_layout'] ?? '' );
					if ( $before !== $after ) {
						if ( self::has_data( $old_rows[ $i ] ) ) {
							$out[] = $path . '[' . $i . ']：レイアウトが ' . $before . ' → ' . $after . ' に変わり、前の中身が消えます';
						}
						continue;
					}
					$subs = $layouts[ $after ] ?? array();
				}
				foreach ( $row as $k => $v ) {
					if ( isset( $subs[ $k ] ) ) {
						$out = array_merge( $out, self::removals( $subs[ $k ], $old_rows[ $i ][ $k ] ?? null, $v, $path . '[' . $i . '].' . $k ) );
					}
				}
			}
			return $out;
		}
		if ( in_array( $type, array( 'relationship', 'checkbox' ), true ) || ( in_array( $type, array( 'select', 'post_object' ), true ) && ! empty( $field['multiple'] ) ) ) {
			$o = array_map( 'strval', is_array( $old ) ? $old : ( Validator::is_empty( $old ) ? array() : array( $old ) ) );
			$n = array_map( 'strval', is_array( $new ) ? $new : array() );
			$gone = array_diff( $o, $n );
			if ( array() !== $gone ) {
				$out[] = $path . '：選んでいたものが ' . count( $gone ) . ' 件外れます';
			}
			return $out;
		}
		if ( ! Validator::is_empty( $old ) && '0' !== (string) ( is_scalar( $old ) ? $old : '' ) && Validator::is_empty( $new ) ) {
			$out[] = $path . '：いまの値が空になります';
		}
		return $out;
	}

	/**
	 * 送った値（expected）に含まれる場所だけを、読み戻した値（actual）と比べる。
	 *
	 * @param mixed $expected
	 * @param mixed $actual
	 * @return list<string> 合わない場所
	 */
	public static function mismatches( $expected, $actual, string $path ): array {
		if ( is_array( $expected ) ) {
			if ( array() === $expected ) {
				return Validator::is_empty( $actual ) ? array() : array( $path );
			}
			if ( ! is_array( $actual ) ) {
				return array( $path );
			}
			if ( array_is_list( $expected ) ) {
				$actual = array_values( $actual );
				if ( count( $expected ) !== count( $actual ) ) {
					return array( $path . '（数が違う）' );
				}
			}
			$out = array();
			foreach ( $expected as $k => $v ) {
				$out = array_merge( $out, self::mismatches( $v, $actual[ $k ] ?? null, $path . ( is_int( $k ) ? '[' . $k . ']' : '.' . $k ) ) );
			}
			return $out;
		}
		$e = PostReader::canonical( $expected );
		$a = PostReader::canonical( $actual );
		if ( is_array( $a ) ) {
			return '' === $e && array() === $a ? array() : array( $path );
		}
		// true_false は空と 0 を同じに扱う。
		if ( ( '' === $e && '0' === $a ) || ( '0' === $e && '' === $a ) ) {
			return array();
		}
		return $e === $a ? array() : array( $path );
	}

	/**
	 * 差分の要約（AI・制作担当者に見せる。長い値は短くする）。
	 *
	 * @param mixed $v
	 */
	public static function brief( $v ): string {
		if ( is_array( $v ) ) {
			return array_is_list( $v ) ? count( $v ) . '件' : count( $v ) . '項目';
		}
		$s = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) PostReader::canonical( $v ) ) ) );
		return mb_strlen( $s ) > 60 ? mb_substr( $s, 0, 60 ) . '…' : $s;
	}

	/**
	 * @param mixed $row
	 */
	private static function has_data( $row ): bool {
		if ( ! is_array( $row ) ) {
			return ! Validator::is_empty( $row );
		}
		foreach ( $row as $k => $v ) {
			if ( 'acf_fc_layout' !== $k && self::has_data( $v ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param list<array<string,mixed>> $subs
	 * @return array<string,array<string,mixed>>
	 */
	private static function subs_by_key( array $subs ): array {
		$out = array();
		foreach ( $subs as $s ) {
			$out[ (string) $s['key'] ] = $s;
		}
		return $out;
	}
}
