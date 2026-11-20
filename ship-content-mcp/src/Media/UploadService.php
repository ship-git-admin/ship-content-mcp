<?php
/**
 * 写真の分割転送（prepare-upload → upload-chunk × N → finish-upload）。
 *
 * 安全の決まり：
 * - JPEG・PNG・WebP だけ。拡張子・申告の MIME・先頭の印・getimagesize・finfo がすべて合うこと。大きさは設定の上限まで。
 * - 分割は 512 KiB（最後だけ残り）。大きさとハッシュ（SHA-256）を確かめる。同じ番号に違う中身が来たら拒否、同じ中身なら受け付ける（送り直し）。
 * - 分割の途中は DB に base64 のまま置く（公開の場所に置かない）。2時間で期限切れ。1人の転送中は10件まで。
 * - 仕上げで全体のハッシュ・大きさを確かめ、位置情報などのメタデータを消してから、WordPress のメディアに登録する（media_handle_sideload）。
 * - URL からの取得はしない（SSRF を防ぐ）。ほかのユーザーの転送は見えない・使えない。
 * - 同じ写真（同じハッシュ）がすでに MCP で登録されていれば、その添付 ID を返す（同じ写真を2回登録しない）。
 * - メディアの画像は、下書きのままでも URL を知っていれば見られる（WordPress の仕組み）。
 *
 * @package ShipContentMcp
 */

namespace ShipContentMcp\Media;

use ShipContentMcp\Content\DraftService;
use ShipContentMcp\Content\PostTypes;
use ShipContentMcp\Core\DomainError;
use ShipContentMcp\Core\Installer;
use ShipContentMcp\Core\Settings;
use ShipContentMcp\Security\RequestContext;

defined( 'ABSPATH' ) || exit;

final class UploadService {

	public const TTL        = 7200;
	public const MAX_OPEN   = 10;
	public const MAX_PIXELS = 60000000;
	public const MAX_SIDE   = 15000;

	public const META_UPLOADED = '_ship_content_mcp_uploaded';
	public const META_SHA256   = '_ship_content_mcp_sha256';

	private const PURGE_OPTION = 'ship_content_mcp_upload_purged';

	public const PUBLIC_NOTICE = 'メディアに登録した写真は、記事が下書きのままでも、URL を知っていれば誰でも見られます（WordPress の仕組み）。公開してよい写真だけを登録してください。';

	/**
	 * @param array<string,mixed> $in post_type, filename, mime_type, size_bytes, sha256
	 * @return array{data:array<string,mixed>,warnings:list<string>}
	 * @throws DomainError
	 */
	public function prepare( array $in ): array {
		self::maybe_purge();
		$post_type = (string) ( $in['post_type'] ?? '' );
		PostTypes::assert_writable( $post_type );
		$name = (string) ( $in['filename'] ?? '' );
		$mime = (string) ( $in['mime_type'] ?? '' );
		$size = (int) ( $in['size_bytes'] ?? 0 );
		$sha  = strtolower( (string) ( $in['sha256'] ?? '' ) );
		$max  = (int) Settings::get()['media_max_mb'] * 1024 * 1024;
		$safe = sanitize_file_name( wp_basename( $name ) );
		$ext_mime = ImageSniff::mime_for_name( $safe );
		if ( '' === $safe || null === $ext_mime || $ext_mime !== $mime ) {
			throw new DomainError( 'MEDIA-002', 'extension/mime not allowed or mismatch: ' . $mime );
		}
		if ( $size <= 0 || $size > $max ) {
			throw new DomainError( 'MEDIA-002', 'size out of range: ' . $size, false, '写真の大きさが上限（' . (int) Settings::get()['media_max_mb'] . 'MB）を超えています' );
		}
		if ( 1 !== preg_match( '/^[0-9a-f]{64}$/', $sha ) ) {
			throw new DomainError( 'INPUT-001', 'bad sha256', false, 'sha256 はファイル全体の SHA-256（16進の小文字64文字）にしてください' );
		}
		$existing = self::find_existing( $sha );
		if ( $existing > 0 ) {
			return array(
				'data'     => array(
					'already_uploaded' => true,
					'attachment_id'    => $existing,
				),
				'warnings' => array( '同じ写真がすでに登録されているので、その添付 ID を返しました（転送は不要です）。' ),
			);
		}
		global $wpdb;
		$t    = Installer::table( 'uploads' );
		$user = get_current_user_id();
		$open = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE user_id = %d AND status = 'open' AND expires_at > %d", $user, time() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		if ( $open >= self::MAX_OPEN ) {
			throw new DomainError( 'MEDIA-001', 'too many open sessions', true, '転送中の写真が多すぎます。いまの転送を仕上げてから、もう一度お試しください' );
		}
		$id     = bin2hex( random_bytes( 16 ) );
		$chunks = ImageSniff::chunks_for( $size );
		$ok     = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$t,
			array(
				'session_id' => $id,
				'user_id'    => $user,
				'post_type'  => $post_type,
				'filename'   => mb_substr( $safe, 0, 200 ),
				'mime'       => $mime,
				'size'       => $size,
				'sha256'     => $sha,
				'chunk_size' => ImageSniff::CHUNK_SIZE,
				'chunks'     => $chunks,
				'status'     => 'open',
				'created_at' => time(),
				'expires_at' => time() + self::TTL,
			)
		);
		if ( ! $ok ) {
			throw new DomainError( 'SYS-001', 'upload session insert failed', true );
		}
		return array(
			'data'     => array(
				'already_uploaded'  => false,
				'upload_session_id' => $id,
				'chunk_size'        => ImageSniff::CHUNK_SIZE,
				'chunks'            => $chunks,
				'expires_at'        => gmdate( 'c', time() + self::TTL ),
			),
			'warnings' => array( self::PUBLIC_NOTICE ),
		);
	}

	/**
	 * @param array<string,mixed> $in upload_session_id, index, content_base64, chunk_sha256
	 * @return array{data:array<string,mixed>,warnings:list<string>}
	 * @throws DomainError
	 */
	public function chunk( array $in ): array {
		$s     = self::session( (string) ( $in['upload_session_id'] ?? '' ) );
		$index = (int) ( $in['index'] ?? -1 );
		$want  = ImageSniff::expected_length( (int) $s['size'], $index );
		if ( $want < 0 || (int) $s['chunks'] !== ImageSniff::chunks_for( (int) $s['size'] ) ) {
			throw new DomainError( 'MEDIA-001', 'index out of range: ' . $index, false, '分割の番号が範囲外です（0〜' . ( (int) $s['chunks'] - 1 ) . '）' );
		}
		$b64 = (string) ( $in['content_base64'] ?? '' );
		$raw = ImageSniff::decode_base64( $b64 );
		if ( null === $raw ) {
			throw new DomainError( 'MEDIA-001', 'bad base64', false, 'content_base64 が base64 として読めません' );
		}
		if ( strlen( $raw ) !== $want ) {
			throw new DomainError( 'MEDIA-001', 'chunk length ' . strlen( $raw ) . ' != ' . $want, false, '分割の大きさが違います（この番号は ' . $want . ' バイト）' );
		}
		$sha = strtolower( (string) ( $in['chunk_sha256'] ?? '' ) );
		if ( ! hash_equals( hash( 'sha256', $raw ), $sha ) ) {
			throw new DomainError( 'MEDIA-001', 'chunk hash mismatch', true, '分割の SHA-256 が合いません（届く途中で壊れた可能性があります。送り直してください）' );
		}
		if ( 0 === $index && ImageSniff::sniff( $raw ) !== $s['mime'] ) {
			throw new DomainError( 'MEDIA-002', 'magic bytes do not match declared mime' );
		}
		global $wpdb;
		$t        = Installer::table( 'chunks' );
		$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$t} (session_id, idx, sha256, data, created_at) VALUES (%s, %d, %s, %s, %d)", $s['session_id'], $index, $sha, $b64, time() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$warnings = array();
		if ( 1 !== $inserted ) {
			$prev = (string) $wpdb->get_var( $wpdb->prepare( "SELECT sha256 FROM {$t} WHERE session_id = %s AND idx = %d", $s['session_id'], $index ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			if ( '' === $prev ) {
				throw new DomainError( 'SYS-001', 'chunk insert failed', true );
			}
			if ( ! hash_equals( $prev, $sha ) ) {
				throw new DomainError( 'MEDIA-001', 'same index, different content', false, 'この番号には、別の中身がすでに届いています' );
			}
			$warnings[] = 'この番号はすでに届いています（同じ中身なので、そのまま受け付けました）。';
		}
		return array(
			'data'     => self::progress( $s ),
			'warnings' => $warnings,
		);
	}

	/**
	 * @param array<string,mixed> $in upload_session_id, title?, alt?, attach_to_post_id?
	 * @return array{data:array<string,mixed>,warnings:list<string>,log:array<string,mixed>}
	 * @throws DomainError
	 */
	public function finish( array $in ): array {
		global $wpdb;
		$s = self::session( (string) ( $in['upload_session_id'] ?? '' ), true );
		if ( 'done' === $s['status'] ) {
			$prev = json_decode( (string) $s['result'], true );
			return array(
				'data'     => is_array( $prev ) ? $prev + array( 'idempotent_replay' => true ) : array( 'attachment_id' => (int) $s['attachment_id'] ),
				'warnings' => array( 'この転送はすでに仕上げ済みです（同じ添付 ID を返しました）。' ),
				'log'      => array( 'post_id' => (int) $s['attachment_id'] ),
			);
		}
		$progress = self::progress( $s );
		if ( ! $progress['complete'] ) {
			throw new DomainError( 'MEDIA-001', 'chunks missing', true, 'まだ届いていない分割があります：' . implode( ', ', $progress['missing'] ) );
		}
		$parent = (int) ( $in['attach_to_post_id'] ?? 0 );
		if ( $parent > 0 ) {
			$p = get_post( $parent );
			if ( ! $p instanceof \WP_Post || 'draft' !== $p->post_status || $p->post_type !== $s['post_type'] || ! PostTypes::writable( $p->post_type ) ) {
				throw new DomainError( 'POST-001', 'attach_to_post_id not an editable draft', false, 'attach_to_post_id は、同じ投稿タイプの下書きにしてください' );
			}
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$tmp = wp_tempnam( (string) $s['filename'] );
		try {
			$fh = ( '' !== (string) $tmp ) ? fopen( $tmp, 'wb' ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			if ( false === $fh ) {
				throw new DomainError( 'SYS-001', 'could not open temp file', true );
			}
			$t = Installer::table( 'chunks' );
			for ( $i = 0; $i < (int) $s['chunks']; $i++ ) {
				$b64 = (string) $wpdb->get_var( $wpdb->prepare( "SELECT data FROM {$t} WHERE session_id = %s AND idx = %d", $s['session_id'], $i ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
				fwrite( $fh, (string) base64_decode( $b64, true ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			}
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			clearstatcache( true, $tmp );
			if ( filesize( $tmp ) !== (int) $s['size'] || ! hash_equals( (string) $s['sha256'], (string) hash_file( 'sha256', $tmp ) ) ) {
				throw new DomainError( 'MEDIA-001', 'whole file size/hash mismatch', false, '写真全体の大きさか SHA-256 が、最初に申告したものと合いません' );
			}
			$check = self::inspect( $tmp, (string) $s['mime'] );
			$strip = array(
				'gps_found' => false,
				'removed'   => false,
				'engine'    => '',
			);
			$warnings = array( self::PUBLIC_NOTICE );
			if ( Settings::get()['strip_metadata'] ) {
				$strip = MetadataStripper::strip( $tmp, (string) $s['mime'] );
			} elseif ( MetadataStripper::has_gps( $tmp, (string) $s['mime'] ) ) {
				$warnings[] = 'この写真には位置情報（GPS）が入っています（設定でメタデータを消さないことになっています）。';
			}
			$title = isset( $in['title'] ) ? mb_substr( sanitize_text_field( (string) $in['title'] ), 0, 200 ) : '';
			$id    = media_handle_sideload(
				array(
					'name'     => (string) $s['filename'],
					'tmp_name' => $tmp,
				),
				$parent,
				'' !== $title ? $title : null,
				array( 'post_author' => get_current_user_id() )
			);
			if ( is_wp_error( $id ) ) {
				throw new DomainError( 'MEDIA-001', 'media_handle_sideload: ' . $id->get_error_code(), false, '写真をメディアに登録できませんでした（' . $id->get_error_message() . '）' );
			}
			$tmp = '';
			$id  = (int) $id;
		} finally {
			if ( '' !== $tmp && file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
		}

		if ( isset( $in['alt'] ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', mb_substr( sanitize_text_field( (string) $in['alt'] ), 0, 300 ) );
		}
		update_post_meta(
			$id,
			self::META_UPLOADED,
			(string) wp_json_encode(
				array(
					'user_id'    => get_current_user_id(),
					'key_id'     => RequestContext::key_id(),
					'request_id' => RequestContext::request_id(),
					'at'         => gmdate( 'c' ),
				)
			)
		);
		update_post_meta( $id, self::META_SHA256, (string) $s['sha256'] );
		$test = PostTypes::testing( (string) $s['post_type'] );
		if ( $test ) {
			update_post_meta( $id, DraftService::META_TEST, '1' );
		}
		$meta = wp_get_attachment_metadata( $id );
		$data = array(
			'attachment_id'     => $id,
			'mime_type'         => (string) get_post_mime_type( $id ),
			'width'             => (int) ( $meta['width'] ?? $check['width'] ),
			'height'            => (int) ( $meta['height'] ?? $check['height'] ),
			'filesize'          => (int) filesize( (string) get_attached_file( $id ) ),
			'metadata_removed'  => $strip['removed'],
			'gps_removed'       => $strip['gps_found'] && $strip['removed'],
			'attached_to'       => $parent,
			'test_marked'       => $test,
			'admin_edit_url'    => admin_url( 'post.php?post=' . $id . '&action=edit' ),
			'idempotent_replay' => false,
		);
		if ( $strip['gps_found'] && $strip['removed'] ) {
			$warnings[] = '写真に入っていた位置情報（GPS）を消しました。';
		}
		$wpdb->update( Installer::table( 'uploads' ), array( 'status' => 'done', 'attachment_id' => $id, 'result' => (string) wp_json_encode( $data ) ), array( 'session_id' => $s['session_id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Installer::table( 'chunks' ), array( 'session_id' => $s['session_id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return array(
			'data'     => $data,
			'warnings' => $warnings,
			'log'      => array( 'post_id' => $id ),
		);
	}

	/**
	 * 中身が本当にその形式の画像か（getimagesize・finfo・先頭の印・大きさ）。
	 *
	 * @return array{width:int,height:int}
	 * @throws DomainError
	 */
	private static function inspect( string $path, string $mime ): array {
		$head = (string) file_get_contents( $path, false, null, 0, 16 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ImageSniff::sniff( $head ) !== $mime ) {
			throw new DomainError( 'MEDIA-002', 'magic bytes mismatch' );
		}
		$info = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! is_array( $info ) || ( $info['mime'] ?? '' ) !== $mime ) {
			throw new DomainError( 'MEDIA-002', 'getimagesize failed or mime mismatch' );
		}
		[ $w, $h ] = $info;
		if ( $w < 1 || $h < 1 || $w > self::MAX_SIDE || $h > self::MAX_SIDE || $w * $h > self::MAX_PIXELS ) {
			throw new DomainError( 'MEDIA-002', 'dimensions out of range: ' . $w . 'x' . $h, false, '写真の縦横の大きさが範囲外です（' . $w . '×' . $h . '）' );
		}
		if ( function_exists( 'finfo_open' ) ) {
			$f = finfo_open( FILEINFO_MIME_TYPE );
			$m = $f ? (string) finfo_file( $f, $path ) : '';
			if ( $f ) {
				finfo_close( $f );
			}
			if ( '' !== $m && $m !== $mime ) {
				throw new DomainError( 'MEDIA-002', 'finfo mime mismatch: ' . $m );
			}
		}
		return array(
			'width'  => (int) $w,
			'height' => (int) $h,
		);
	}

	/**
	 * いまのユーザーの転送（ほかのユーザーの転送は「ない」と同じに扱う）。
	 *
	 * @return array<string,mixed>
	 * @throws DomainError
	 */
	private static function session( string $id, bool $allow_done = false ): array {
		global $wpdb;
		if ( 1 !== preg_match( '/^[0-9a-f]{32}$/', $id ) ) {
			throw new DomainError( 'MEDIA-001', 'bad session id', false, 'upload_session_id が違います' );
		}
		$t   = Installer::table( 'uploads' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE session_id = %s AND user_id = %d", $id, get_current_user_id() ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		if ( ! is_array( $row ) ) {
			throw new DomainError( 'MEDIA-001', 'session not found', false, 'その転送は見つかりません（期限切れの可能性があります。prepare-upload からやり直してください）' );
		}
		if ( 'done' === $row['status'] && ! $allow_done ) {
			throw new DomainError( 'MEDIA-001', 'session already finished', false, 'この転送は仕上げ済みです' );
		}
		if ( 'open' === $row['status'] && (int) $row['expires_at'] < time() ) {
			throw new DomainError( 'MEDIA-001', 'session expired', false, 'この転送は期限切れです。prepare-upload からやり直してください' );
		}
		return $row;
	}

	/**
	 * @param array<string,mixed> $s
	 * @return array{received:int,chunks:int,complete:bool,missing:list<int>}
	 */
	private static function progress( array $s ): array {
		global $wpdb;
		$t    = Installer::table( 'chunks' );
		$have = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT idx FROM {$t} WHERE session_id = %s", $s['session_id'] ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$missing = array_values( array_diff( range( 0, (int) $s['chunks'] - 1 ), $have ) );
		return array(
			'received' => count( $have ),
			'chunks'   => (int) $s['chunks'],
			'complete' => array() === $missing,
			'missing'  => array_slice( $missing, 0, 20 ),
		);
	}

	private static function find_existing( string $sha ): int {
		$ids = get_posts(
			array(
				'post_type'        => 'attachment',
				'post_status'      => 'inherit',
				'meta_key'         => self::META_SHA256, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'       => $sha, // phpcs:ignore WordPress.DB.SlowDBQuery
				'fields'           => 'ids',
				'posts_per_page'   => 1,
				'suppress_filters' => true,
			)
		);
		return array() !== $ids ? (int) $ids[0] : 0;
	}

	/**
	 * 「試験中」の投稿タイプの、仕上げていない転送を消す（cleanup-tests から呼ぶ。試験のデータなので）。
	 *
	 * @param list<string> $post_types
	 */
	public static function purge_open_for( array $post_types ): int {
		global $wpdb;
		if ( array() === $post_types ) {
			return 0;
		}
		$u     = Installer::table( 'uploads' );
		$c     = Installer::table( 'chunks' );
		$holes = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$ids   = (array) $wpdb->get_col( $wpdb->prepare( "SELECT session_id FROM {$u} WHERE status = 'open' AND post_type IN ({$holes})", $post_types ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders
		foreach ( $ids as $id ) {
			$wpdb->delete( $c, array( 'session_id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->delete( $u, array( 'session_id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		return count( $ids );
	}

	/**
	 * 期限切れの転送と、古い仕上げ済みの記録を消す（リクエストのついでに。WP-Cron に頼らない）。
	 */
	public static function maybe_purge( bool $force = false ): int {
		global $wpdb;
		$last = (int) get_option( self::PURGE_OPTION, 0 );
		if ( ! $force && time() - $last < 600 ) {
			return 0;
		}
		update_option( self::PURGE_OPTION, time(), false );
		$u   = Installer::table( 'uploads' );
		$c   = Installer::table( 'chunks' );
		$ids = (array) $wpdb->get_col( $wpdb->prepare( "SELECT session_id FROM {$u} WHERE ( status = 'open' AND expires_at < %d ) OR ( status = 'done' AND created_at < %d )", time(), time() - DAY_IN_SECONDS ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		foreach ( $ids as $id ) {
			$wpdb->delete( $c, array( 'session_id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->delete( $u, array( 'session_id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		return count( $ids );
	}
}
