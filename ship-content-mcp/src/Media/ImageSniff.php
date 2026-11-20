<?php
/**
 * 写真の形式の判定（WordPress に依存しない）。
 *
 * 受け付けるのは JPEG・PNG・WebP だけ（SVG・GIF・HEIC・ZIP などは受け付けない）。
 * 拡張子・申告の MIME・ファイルの先頭の印（magic bytes）がすべて合うときだけ通す。
 *
 * @package ShipContentMcp
 */

namespace ShipContentMcp\Media;

defined( 'ABSPATH' ) || exit;

final class ImageSniff {

	/** 拡張子 => MIME。 */
	public const EXT_MIME = array(
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
		'webp' => 'image/webp',
	);

	/** 分割の大きさ（バイト）。base64 で約 700KB（契約の上限 900,000 文字の内側）。 */
	public const CHUNK_SIZE = 524288;

	/**
	 * ファイルの先頭の印から MIME を判定する（分からなければ null）。
	 */
	public static function sniff( string $head ): ?string {
		if ( strlen( $head ) >= 3 && "\xFF\xD8\xFF" === substr( $head, 0, 3 ) ) {
			return 'image/jpeg';
		}
		if ( strlen( $head ) >= 8 && "\x89PNG\r\n\x1A\n" === substr( $head, 0, 8 ) ) {
			return 'image/png';
		}
		if ( strlen( $head ) >= 12 && 'RIFF' === substr( $head, 0, 4 ) && 'WEBP' === substr( $head, 8, 4 ) ) {
			return 'image/webp';
		}
		return null;
	}

	/**
	 * ファイル名の拡張子から MIME（受け付けない拡張子なら null）。
	 */
	public static function mime_for_name( string $filename ): ?string {
		$ext = strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) );
		return self::EXT_MIME[ $ext ] ?? null;
	}

	public static function chunks_for( int $size ): int {
		return max( 1, (int) ceil( $size / self::CHUNK_SIZE ) );
	}

	/**
	 * その番号の分割の、正しい大きさ。
	 */
	public static function expected_length( int $size, int $index ): int {
		$chunks = self::chunks_for( $size );
		if ( $index < 0 || $index >= $chunks ) {
			return -1;
		}
		return $index < $chunks - 1 ? self::CHUNK_SIZE : $size - self::CHUNK_SIZE * ( $chunks - 1 );
	}

	/**
	 * base64 を厳密に読む（空白・改行は許さない）。読めなければ null。
	 */
	public static function decode_base64( string $b64 ): ?string {
		if ( '' === $b64 || 1 !== preg_match( '#^[A-Za-z0-9+/]+={0,2}$#', $b64 ) || 0 !== strlen( $b64 ) % 4 ) {
			return null;
		}
		$raw = base64_decode( $b64, true );
		return false === $raw ? null : $raw;
	}
}
