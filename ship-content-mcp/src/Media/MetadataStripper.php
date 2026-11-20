<?php
/**
 * 写真の位置情報（GPS）などのメタデータを消す。
 *
 * - 先に EXIF の向き（Orientation）を画像そのものに反映してから、メタデータを消す（消すと向きが崩れるため）。
 * - 色の情報（ICC プロファイル）は残す（消すと色が変わることがある）。
 * - Imagick があれば Imagick、なければ GD で作り直す（GD は保存し直すとメタデータが残らない）。
 * - 消した後にもう一度確かめ、位置情報が残っていたら失敗にする（登録しない）。
 *
 * @package ShipContentMcp
 */

namespace ShipContentMcp\Media;

use ShipContentMcp\Core\DomainError;

defined( 'ABSPATH' ) || exit;

final class MetadataStripper {

	/**
	 * @return array{gps_found:bool,removed:bool,engine:string}
	 * @throws DomainError
	 */
	public static function strip( string $path, string $mime ): array {
		$gps_before = self::has_gps( $path, $mime );
		$engine     = '';
		if ( class_exists( '\\Imagick' ) ) {
			try {
				$im = new \Imagick( $path );
				if ( method_exists( $im, 'autoOrient' ) ) {
					$im->autoOrient();
				}
				$im->setImageOrientation( \Imagick::ORIENTATION_TOPLEFT );
				$icc = '';
				try {
					$profiles = $im->getImageProfiles( 'icc', true );
					$icc      = (string) ( $profiles['icc'] ?? '' );
				} catch ( \Throwable $e ) {
					$icc = '';
				}
				$im->stripImage();
				if ( '' !== $icc ) {
					$im->profileImage( 'icc', $icc );
				}
				if ( 'image/jpeg' === $mime ) {
					$im->setImageCompressionQuality( 90 );
				}
				$im->writeImage( $path );
				$im->clear();
				$engine = 'imagick';
			} catch ( \Throwable $e ) {
				$engine = '';
			}
		}
		if ( '' === $engine ) {
			$engine = self::strip_gd( $path, $mime ) ? 'gd' : '';
		}
		if ( '' === $engine ) {
			throw new DomainError( 'MEDIA-001', 'could not re-encode image to remove metadata', false, '写真の位置情報などを消せませんでした（画像を読み直せません）' );
		}
		clearstatcache( true, $path );
		if ( self::has_gps( $path, $mime ) ) {
			throw new DomainError( 'MEDIA-001', 'gps still present after strip', false, '写真の位置情報を消せませんでした。登録していません' );
		}
		return array(
			'gps_found' => $gps_before,
			'removed'   => true,
			'engine'    => $engine,
		);
	}

	public static function has_gps( string $path, string $mime ): bool {
		if ( 'image/jpeg' === $mime && function_exists( 'exif_read_data' ) ) {
			$e = @exif_read_data( $path, null, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- 壊れた EXIF は「なし」とみなす
			if ( is_array( $e ) && ! empty( $e['GPS'] ) ) {
				return true;
			}
		}
		if ( class_exists( '\\Imagick' ) ) {
			try {
				$im    = new \Imagick();
				$im->pingImage( $path );
				$props = $im->getImageProperties( 'exif:GPS*', true );
				$im->clear();
				return array() !== (array) $props;
			} catch ( \Throwable $e ) {
				return false;
			}
		}
		return false;
	}

	private static function strip_gd( string $path, string $mime ): bool {
		$img = null;
		if ( 'image/jpeg' === $mime && function_exists( 'imagecreatefromjpeg' ) ) {
			$img = @imagecreatefromjpeg( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( $img && function_exists( 'exif_read_data' ) ) {
				$e = @exif_read_data( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$o = is_array( $e ) ? (int) ( $e['Orientation'] ?? 1 ) : 1;
				$img = self::orient_gd( $img, $o );
			}
		} elseif ( 'image/png' === $mime && function_exists( 'imagecreatefrompng' ) ) {
			$img = @imagecreatefrompng( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( $img ) {
				imagesavealpha( $img, true );
			}
		} elseif ( 'image/webp' === $mime && function_exists( 'imagecreatefromwebp' ) ) {
			$img = @imagecreatefromwebp( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		if ( ! $img ) {
			return false;
		}
		$ok = match ( $mime ) {
			'image/jpeg' => imagejpeg( $img, $path, 90 ),
			'image/png'  => imagepng( $img, $path ),
			'image/webp' => imagewebp( $img, $path, 90 ),
			default      => false,
		};
		imagedestroy( $img );
		return (bool) $ok;
	}

	/**
	 * @param \GdImage $img
	 * @return \GdImage
	 */
	private static function orient_gd( $img, int $o ) {
		if ( in_array( $o, array( 2, 4, 5, 7 ), true ) ) {
			imageflip( $img, IMG_FLIP_HORIZONTAL );
		}
		$angle = match ( $o ) {
			3, 4 => 180,
			5, 6 => -90,
			7, 8 => 90,
			default => 0,
		};
		if ( 0 !== $angle ) {
			$rotated = imagerotate( $img, $angle, 0 );
			if ( $rotated ) {
				imagedestroy( $img );
				$img = $rotated;
			}
		}
		return $img;
	}
}
