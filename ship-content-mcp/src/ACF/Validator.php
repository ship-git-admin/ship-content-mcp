<?php
/**
 * ACF の値の検証（いまの構造＝SchemaReader の結果と照らす。AI の出した値を信用しない）。
 *
 * - 上の階層は field key で指定する。その投稿で使われるグループの欄だけ受け付ける。
 * - 入れ子（group・repeater の行・flexible content の行）も field key で指定する（名前は受け付けない）。
 * - flexible content の行は acf_fc_layout にレイアウトの name を入れる。
 * - 選択肢は、いまある値だけ。画像は実在する画像の添付 ID。関連する記事は公開済みで、欄が許す投稿タイプだけ。
 * - 文字列は wp_kses_post で無害化する（スクリプトなどを消す）。
 * - 見た目だけの欄（tab など）・まだ対応していない型は受け付けない。
 * - 必須の欄が空でも、下書きなので止めない（警告にする）。
 *
 * @package ShipContentMcp
 */

namespace ShipContentMcp\ACF;

defined( 'ABSPATH' ) || exit;

final class Validator {

	public const MAX_STRING = 200000;
	private const MAX_NODES = 20000;

	/** @var array<string,array<string,mixed>> */
	private array $top = array();

	/** @var list<array{path:string,code:string,message:string}> */
	private array $errors = array();

	/** @var list<string> */
	private array $warnings = array();

	private int $nodes = 0;

	/**
	 * @param list<array<string,mixed>> $groups SchemaReader の field_groups
	 */
	public function __construct( array $groups ) {
		foreach ( $groups as $g ) {
			foreach ( (array) ( $g['fields'] ?? array() ) as $f ) {
				$this->top[ (string) $f['key'] ] = $f;
			}
		}
	}

	/**
	 * @return list<array{path:string,code:string,message:string}>
	 */
	public function errors(): array {
		return $this->errors;
	}

	/**
	 * @return list<string>
	 */
	public function warnings(): array {
		return array_values( array_unique( $this->warnings ) );
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function field( string $key ): ?array {
		return $this->top[ $key ] ?? null;
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	public function top_fields(): array {
		return $this->top;
	}

	/**
	 * 変更の一覧を確かめて、保存できる形にする。
	 *
	 * @param list<array{field_key:string,value:mixed}> $changes
	 * @return array<string,mixed> field key => 保存する値（エラーがあれば一部だけ）
	 */
	public function changes( array $changes ): array {
		$out  = array();
		$seen = array();
		foreach ( $changes as $i => $c ) {
			$key  = (string) ( $c['field_key'] ?? '' );
			$path = '' !== $key ? $key : 'acf_changes[' . $i . ']';
			if ( ! array_key_exists( 'value', (array) $c ) ) {
				$this->err( $path, 'missing_value', 'value がありません' );
				continue;
			}
			if ( isset( $seen[ $key ] ) ) {
				$this->err( $path, 'duplicate', '同じ欄が2回指定されています' );
				continue;
			}
			$seen[ $key ] = true;
			$f = $this->top[ $key ] ?? null;
			if ( null === $f ) {
				$this->err( $path, 'unknown_field', 'この投稿で使われる入力欄ではありません（get-acf-schema を読み直してください）' );
				continue;
			}
			$v = $this->value( $f, $c['value'], $path );
			if ( null !== $v ) {
				$out[ $key ] = $v;
			}
		}
		$ctx = array();
		foreach ( $changes as $c ) {
			if ( isset( $c['field_key'] ) && array_key_exists( 'value', (array) $c ) ) {
				$ctx[ (string) $c['field_key'] ] = $c['value'];
			}
		}
		foreach ( $ctx as $key => $v ) {
			if ( isset( $this->top[ $key ] ) ) {
				$this->warn_hidden( $this->top[ $key ], $v, $ctx, $this->top );
			}
		}
		return $out;
	}

	/**
	 * 必須なのに値がない上の階層の欄（下書きなので警告に使う）。
	 *
	 * @param array<string,mixed> $values field key => 値（いまの値と変更を合わせたもの）
	 * @return list<string> 欄のラベル
	 */
	public function missing_required( array $values ): array {
		$out = array();
		foreach ( $this->top as $key => $f ) {
			if ( ! empty( $f['required'] ) && self::is_empty( $values[ $key ] ?? null ) ) {
				$out[] = (string) ( $f['label'] ?? $key );
			}
		}
		return $out;
	}

	/**
	 * @param mixed $v
	 */
	public static function is_empty( $v ): bool {
		return null === $v || '' === $v || false === $v || array() === $v;
	}

	/**
	 * @param array<string,mixed> $f
	 * @param mixed               $v
	 * @return mixed 保存する値（エラーのときは null）
	 */
	public function value( array $f, $v, string $path ) {
		if ( ++$this->nodes > self::MAX_NODES ) {
			$this->err( $path, 'too_large', '値が大きすぎます' );
			return null;
		}
		$type    = (string) ( $f['type'] ?? '' );
		$support = (string) ( $f['write_support'] ?? '' );
		if ( 'presentational' === $support ) {
			$this->err( $path, 'presentational', '見た目だけの欄（' . $type . '）には値を入れられません' );
			return null;
		}
		if ( 'planned' !== $support ) {
			$this->err( $path, 'unsupported_type', 'この型（' . $type . '）は、まだ書き込みに対応していません' );
			return null;
		}
		switch ( $type ) {
			case 'text':
			case 'textarea':
			case 'wysiwyg':
				return $this->string_value( $f, $v, $path );
			case 'email':
				$s = $this->plain( $v, $path );
				if ( null !== $s && '' !== $s && ! is_email( $s ) ) {
					$this->err( $path, 'invalid_email', 'メールアドレスの形ではありません' );
					return null;
				}
				return $s;
			case 'url':
				$s = $this->plain( $v, $path );
				if ( null !== $s && '' !== $s ) {
					$clean = esc_url_raw( $s, array( 'http', 'https' ) );
					if ( '' === $clean || ! preg_match( '#^https?://#i', $clean ) ) {
						$this->err( $path, 'invalid_url', 'http:// か https:// で始まる URL を入れてください' );
						return null;
					}
					return $clean;
				}
				return $s;
			case 'number':
				return $this->number_value( $f, $v, $path );
			case 'select':
				return $this->choice_value( $f, $v, $path, ! empty( $f['multiple'] ) );
			case 'checkbox':
				return $this->choice_value( $f, $v, $path, true );
			case 'radio':
			case 'button_group':
				return $this->choice_value( $f, $v, $path, false );
			case 'true_false':
				if ( true === $v || 1 === $v || '1' === $v ) {
					return 1;
				}
				if ( false === $v || 0 === $v || '0' === $v || null === $v || '' === $v ) {
					return 0;
				}
				$this->err( $path, 'type', 'true か false を入れてください' );
				return null;
			case 'image':
				return $this->image_value( $f, $v, $path );
			case 'relationship':
				return $this->relation_value( $f, $v, $path, true );
			case 'post_object':
				return $this->relation_value( $f, $v, $path, ! empty( $f['multiple'] ) );
			case 'group':
				return $this->group_value( $f, $v, $path );
			case 'repeater':
				return $this->repeater_value( $f, $v, $path );
			case 'flexible_content':
				return $this->flexible_value( $f, $v, $path );
		}
		$this->err( $path, 'unsupported_type', 'この型（' . $type . '）は、まだ書き込みに対応していません' );
		return null;
	}

	// ---- 型ごと ----------------------------------------------------------------

	/**
	 * @param array<string,mixed> $f
	 * @param mixed               $v
	 */
	private function string_value( array $f, $v, string $path ): ?string {
		$s = $this->plain( $v, $path );
		if ( null === $s ) {
			return null;
		}
		$max = (int) ( $f['maxlength'] ?? 0 );
		if ( $max > 0 && mb_strlen( $s ) > $max ) {
			$this->err( $path, 'too_long', $max . '文字以内にしてください（いま ' . mb_strlen( $s ) . '文字）' );
			return null;
		}
		// HTML を含むときだけ無害化する（text・textarea の「&」などを書き換えないため）。wysiwyg は常に無害化する。
		if ( 'wysiwyg' !== ( $f['type'] ?? '' ) && ! str_contains( $s, '<' ) ) {
			return $s;
		}
		// script・style は中身ごと消す（wp_kses_post はタグだけを消し、中の文字を本文に残すため）。
		$clean = wp_kses_post( (string) preg_replace( '#<(script|style)\b[^>]*>.*?</\1\s*>#is', '', $s ) );
		if ( substr_count( $clean, '<' ) < substr_count( $s, '<' ) ) {
			$this->warnings[] = '使えない HTML（スクリプトなど）を取り除きました：' . (string) ( $f['label'] ?? $path );
		}
		return $clean;
	}

	/**
	 * @param mixed $v
	 */
	private function plain( $v, string $path ): ?string {
		if ( null === $v ) {
			return '';
		}
		if ( is_int( $v ) || is_float( $v ) ) {
			$v = (string) $v;
		}
		if ( ! is_string( $v ) ) {
			$this->err( $path, 'type', '文字列を入れてください' );
			return null;
		}
		if ( strlen( $v ) > self::MAX_STRING ) {
			$this->err( $path, 'too_long', '長すぎます' );
			return null;
		}
		return str_replace( array( "\r\n", "\r" ), "\n", $v );
	}

	/**
	 * @param array<string,mixed> $f
	 * @param mixed               $v
	 * @return int|float|string|null
	 */
	private function number_value( array $f, $v, string $path ) {
		if ( null === $v || '' === $v ) {
			return '';
		}
		if ( ! is_int( $v ) && ! is_float( $v ) && ! ( is_string( $v ) && is_numeric( $v ) ) ) {
			$this->err( $path, 'type', '数を入れてください' );
			return null;
		}
		$n = 0 + $v;
		if ( isset( $f['min'] ) && is_numeric( $f['min'] ) && $n < 0 + $f['min'] ) {
			$this->err( $path, 'number_range', $f['min'] . ' 以上にしてください' );
			return null;
		}
		if ( isset( $f['max'] ) && is_numeric( $f['max'] ) && $n > 0 + $f['max'] ) {
			$this->err( $path, 'number_range', $f['max'] . ' 以下にしてください' );
			return null;
		}
		return $n;
	}

	/**
	 * @param array<string,mixed> $f
	 * @param mixed               $v
	 * @return string|list<string>|null
	 */
	private function choice_value( array $f, $v, string $path, bool $multiple ) {
		$allowed = array_column( (array) ( $f['choices'] ?? array() ), 'value' );
		if ( $multiple ) {
			if ( null === $v || '' === $v ) {
				return array();
			}
			if ( ! is_array( $v ) || ! array_is_list( $v ) ) {
				$this->err( $path, 'type', '選択肢の値の配列を入れてください' );
				return null;
			}
			$out = array();
			foreach ( $v as $item ) {
				$item = is_int( $item ) ? (string) $item : $item;
				if ( ! is_string( $item ) || ! in_array( $item, $allowed, true ) ) {
					$this->err( $path, 'choice', 'いまの選択肢にない値です：' . ( is_scalar( $item ) ? (string) $item : '?' ) . '（選べる値：' . implode( ', ', array_slice( $allowed, 0, 20 ) ) . '）' );
					return null;
				}
				$out[] = $item;
			}
			return array_values( array_unique( $out ) );
		}
		if ( null === $v || '' === $v ) {
			if ( ! empty( $f['allow_null'] ) || 'radio' !== ( $f['type'] ?? '' ) ) {
				return '';
			}
		}
		$v = is_int( $v ) ? (string) $v : $v;
		if ( ! is_string( $v ) || ! in_array( $v, $allowed, true ) ) {
			$this->err( $path, 'choice', 'いまの選択肢にない値です：' . ( is_scalar( $v ) ? (string) $v : '?' ) . '（選べる値：' . implode( ', ', array_slice( $allowed, 0, 20 ) ) . '）' );
			return null;
		}
		return $v;
	}

	/**
	 * @param array<string,mixed> $f
	 * @param mixed               $v
	 * @return int|string|null
	 */
	private function image_value( array $f, $v, string $path ) {
		if ( null === $v || '' === $v || 0 === $v || '0' === $v ) {
			return '';
		}
		if ( ! is_int( $v ) && ! ( is_string( $v ) && ctype_digit( $v ) ) ) {
			$this->err( $path, 'type', '画像の添付 ID（数）を入れてください（URL は受け付けません）。新しい写真は get-upload-url で登録し、返った attachment_id を入れる' );
			return null;
		}
		$id   = (int) $v;
		$post = get_post( $id );
		if ( ! $post || 'attachment' !== $post->post_type || ! wp_attachment_is_image( $id ) ) {
			$this->err( $path, 'attachment', 'その ID の画像がありません：' . $id . '（新しい写真は get-upload-url で登録し、返った attachment_id を入れる）' );
			return null;
		}
		$types = trim( (string) ( $f['mime_types'] ?? '' ) );
		if ( '' !== $types ) {
			$ext = strtolower( (string) pathinfo( (string) get_attached_file( $id ), PATHINFO_EXTENSION ) );
			$ok  = array_map( static fn( $t ) => strtolower( trim( $t, " .\t" ) ), explode( ',', $types ) );
			if ( ! in_array( $ext, $ok, true ) ) {
				$this->err( $path, 'attachment', 'この欄で使えない画像の形式です（' . $ext . '）' );
				return null;
			}
		}
		return $id;
	}

	/**
	 * @param array<string,mixed> $f
	 * @param mixed               $v
	 * @return int|string|list<int>|null
	 */
	private function relation_value( array $f, $v, string $path, bool $multiple ) {
		if ( null === $v || '' === $v || ( ! $multiple && ( 0 === $v || '0' === $v ) ) ) {
			return $multiple ? array() : '';
		}
		$ids = $multiple ? $v : array( $v );
		if ( ! is_array( $ids ) || ! array_is_list( $ids ) ) {
			$this->err( $path, 'type', '投稿 ID の配列を入れてください' );
			return null;
		}
		$types = array_filter( array_map( 'strval', (array) ( $f['post_type'] ?? array() ) ) );
		$out   = array();
		foreach ( $ids as $id ) {
			if ( ! is_int( $id ) && ! ( is_string( $id ) && ctype_digit( $id ) ) ) {
				$this->err( $path, 'type', '投稿 ID（数）を入れてください' );
				return null;
			}
			$post = get_post( (int) $id );
			if ( ! $post || 'publish' !== $post->post_status || ( array() !== $types && ! in_array( $post->post_type, $types, true ) ) ) {
				$this->err( $path, 'relation', '関連付けられない投稿です：' . (int) $id . '（公開済みで、この欄が許す投稿タイプだけ）' );
				return null;
			}
			$out[] = (int) $id;
		}
		$out = array_values( array_unique( $out ) );
		if ( $multiple ) {
			if ( isset( $f['max'] ) && (int) $f['max'] > 0 && count( $out ) > (int) $f['max'] ) {
				$this->err( $path, 'too_many', (int) $f['max'] . '件までです' );
				return null;
			}
			if ( isset( $f['min'] ) && (int) $f['min'] > 0 && count( $out ) < (int) $f['min'] ) {
				$this->err( $path, 'too_few', (int) $f['min'] . '件以上が必要です' );
				return null;
			}
			return $out;
		}
		return $out[0];
	}

	/**
	 * @param array<string,mixed> $f
	 * @param mixed               $v
	 * @return array<string,mixed>|null
	 */
	private function group_value( array $f, $v, string $path ): ?array {
		if ( null === $v || array() === $v ) {
			return array();
		}
		if ( ! is_array( $v ) || array_is_list( $v ) ) {
			$this->err( $path, 'type', 'field key をキーにしたオブジェクトを入れてください' );
			return null;
		}
		return $this->row( (array) ( $f['sub_fields'] ?? array() ), $v, $path );
	}

	/**
	 * @param array<string,mixed> $f
	 * @param mixed               $v
	 * @return list<array<string,mixed>>|null
	 */
	private function repeater_value( array $f, $v, string $path ): ?array {
		if ( null === $v || '' === $v ) {
			$v = array();
		}
		if ( ! is_array( $v ) || ! array_is_list( $v ) ) {
			$this->err( $path, 'type', '行の配列を入れてください' );
			return null;
		}
		if ( ! $this->count_ok( $f, count( $v ), $path ) ) {
			return null;
		}
		$out = array();
		foreach ( $v as $i => $r ) {
			if ( ! is_array( $r ) || ( array() !== $r && array_is_list( $r ) ) ) {
				$this->err( $path . '[' . $i . ']', 'type', '行は field key をキーにしたオブジェクトにしてください' );
				return null;
			}
			$row = $this->row( (array) ( $f['sub_fields'] ?? array() ), $r, $path . '[' . $i . ']' );
			if ( null === $row ) {
				return null;
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $f
	 * @param mixed               $v
	 * @return list<array<string,mixed>>|null
	 */
	private function flexible_value( array $f, $v, string $path ): ?array {
		if ( null === $v || '' === $v ) {
			$v = array();
		}
		if ( ! is_array( $v ) || ! array_is_list( $v ) ) {
			$this->err( $path, 'type', '行の配列を入れてください' );
			return null;
		}
		if ( ! $this->count_ok( $f, count( $v ), $path ) ) {
			return null;
		}
		$layouts = array();
		foreach ( (array) ( $f['layouts'] ?? array() ) as $l ) {
			$layouts[ (string) $l['name'] ] = $l;
		}
		$used = array();
		$out  = array();
		foreach ( $v as $i => $r ) {
			$p = $path . '[' . $i . ']';
			if ( ! is_array( $r ) || array_is_list( $r ) ) {
				$this->err( $p, 'type', '行は acf_fc_layout と field key をキーにしたオブジェクトにしてください' );
				return null;
			}
			$name = (string) ( $r['acf_fc_layout'] ?? '' );
			if ( ! isset( $layouts[ $name ] ) ) {
				$this->err( $p, 'layout', 'いまのレイアウトにない名前です：' . $name . '（使える名前：' . implode( ', ', array_keys( $layouts ) ) . '）' );
				return null;
			}
			unset( $r['acf_fc_layout'] );
			$row = $this->row( (array) ( $layouts[ $name ]['sub_fields'] ?? array() ), $r, $p );
			if ( null === $row ) {
				return null;
			}
			$used[ $name ] = ( $used[ $name ] ?? 0 ) + 1;
			$out[]         = array( 'acf_fc_layout' => $name ) + $row;
		}
		foreach ( $layouts as $name => $l ) {
			$n = $used[ $name ] ?? 0;
			if ( isset( $l['max'] ) && (int) $l['max'] > 0 && $n > (int) $l['max'] ) {
				$this->err( $path, 'too_many', 'レイアウト ' . $name . ' は ' . (int) $l['max'] . '行までです' );
				return null;
			}
			if ( isset( $l['min'] ) && (int) $l['min'] > 0 && $n < (int) $l['min'] ) {
				$this->err( $path, 'too_few', 'レイアウト ' . $name . ' は ' . (int) $l['min'] . '行以上が必要です' );
				return null;
			}
		}
		return $out;
	}

	/**
	 * 1行（group の中身・repeater の行・flexible の行）。
	 *
	 * @param list<array<string,mixed>> $subs
	 * @param array<string,mixed>       $r
	 * @return array<string,mixed>|null
	 */
	private function row( array $subs, array $r, string $path ): ?array {
		$by_key = array();
		foreach ( $subs as $s ) {
			$by_key[ (string) $s['key'] ] = $s;
		}
		$out = array();
		foreach ( $r as $k => $v ) {
			$k = (string) $k;
			if ( ! isset( $by_key[ $k ] ) ) {
				$hint = array_slice( array_map( static fn( $s ) => $s['key'] . '（' . $s['name'] . '）', $subs ), 0, 12 );
				$this->err( $path . '.' . $k, 'unknown_sub_field', 'この中にない欄です。入れ子の欄は field key で指定してください（使える欄：' . implode( ', ', $hint ) . '）' );
				return null;
			}
			$this->warn_hidden( $by_key[ $k ], $v, $r, $by_key );
			$val = $this->value( $by_key[ $k ], $v, $path . '.' . $k );
			if ( null === $val && $this->has_errors_under( $path . '.' . $k ) ) {
				return null;
			}
			$out[ $k ] = $val;
		}
		return $out;
	}

	/**
	 * 条件（表示形式など）で画面に出ない欄に値を入れていたら知らせる（0.9.20。保存は止めない）。
	 *
	 * @param array<string,mixed>               $f
	 * @param mixed                             $v
	 * @param array<string,mixed>               $ctx      同じ行の値（field key => 値）
	 * @param array<string,array<string,mixed>> $siblings 同じ行の欄（field key => 欄）
	 */
	private function warn_hidden( array $f, $v, array $ctx, array $siblings ): void {
		if ( empty( $f['conditional_logic'] ) || self::is_empty( $v ) || Conditions::visible( $f, $ctx ) ) {
			return;
		}
		$why = Conditions::why_hidden( $f, $ctx, $siblings );
		$this->warnings[] = '画面に出ない欄に値を入れています（' . ( '' !== $why ? $why : '表示の条件を満たしていないため' ) . '。出したいなら条件の欄を変え、出さないなら空にしてください）：' . (string) ( $f['label'] ?? '' );
	}

	/**
	 * @param array<string,mixed> $f
	 */
	private function count_ok( array $f, int $n, string $path ): bool {
		if ( isset( $f['max'] ) && (int) $f['max'] > 0 && $n > (int) $f['max'] ) {
			$this->err( $path, 'too_many', (int) $f['max'] . '行までです（いま ' . $n . '行）' );
			return false;
		}
		if ( isset( $f['min'] ) && (int) $f['min'] > 0 && $n < (int) $f['min'] ) {
			$this->err( $path, 'too_few', (int) $f['min'] . '行以上が必要です（いま ' . $n . '行）' );
			return false;
		}
		return true;
	}

	private function err( string $path, string $code, string $message ): void {
		if ( count( $this->errors ) < 100 ) {
			$this->errors[] = compact( 'path', 'code', 'message' );
		}
	}

	private function has_errors_under( string $path ): bool {
		foreach ( $this->errors as $e ) {
			if ( $e['path'] === $path || str_starts_with( $e['path'], $path . '.' ) || str_starts_with( $e['path'], $path . '[' ) ) {
				return true;
			}
		}
		return false;
	}
}
