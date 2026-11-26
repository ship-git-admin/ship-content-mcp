<?php
/**
 * 写真を1回だけ使える URL で受け取る（コマンドを実行できる AI 向け。curl で写真のファイルをそのまま送る）。
 *
 * 流れ：ツール get-upload-url で URL を発行 → AI が手元で curl を実行 → ここで受け取り、確かめてメディアに登録。
 * - URL の札（ticket）は 48桁の乱数で、30分で切れる。1回使ったら消す。サイトには札の SHA-256 だけを置く（transient）。
 * - 札を発行したユーザーとして登録する（ユーザーの権限は、受け取るときにもう一度確かめる）。
 * - 中身の確かめ方・メタデータの削除・重複の扱いは、分割転送と同じ（UploadService::store）。
 * - REST の入口は /wp-json/ship-content/upload/<札>。トークン・署名の認証は使わない（札が鍵）。
 *
 * @package ShipContentMcp
 */

namespace ShipContentMcp\Media;

use ShipContentMcp\Content\PostTypes;
use ShipContentMcp\Core\Consts;
use ShipContentMcp\Core\DomainError;
use ShipContentMcp\Core\Settings;
use ShipContentMcp\Security\AccessGate;
use ShipContentMcp\Security\AuditLog;
use ShipContentMcp\Security\RequestContext;

defined( 'ABSPATH' ) || exit;

final class UploadEndpoint {

	public const TTL    = 1800;
	private const PREFIX = 'ship_content_mcp_up_';

	public static function hooks(): void {
		add_action( 'rest_api_init', array( self::class, 'register' ) );
	}

	public static function register(): void {
		register_rest_route(
			Consts::NS,
			'/upload/(?P<ticket>[a-f0-9]{48})',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handle' ),
				'permission_callback' => '__return_true', // 札（ticket）で確かめる。
			)
		);
	}

	public static function url( string $ticket ): string {
		return self::as_requested( rest_url( Consts::NS . '/upload/' . $ticket ) );
	}

	/**
	 * AI がつないできたドメイン・http か https のまま返す（0.9.21）。
	 * サイトの URL（siteurl）とは別のドメインの入口（https の本番ドメインと、http の動作確認 URL など）でつないだとき、
	 * siteurl のドメインに https を付けた URL を返すと、証明書のないドメインになって写真を送れなかったため。
	 * ドメインは、この WordPress に届いたリクエストの Host（名前の形だけ確かめる）。返す相手は同じ AI なので、偽っても本人にしか効かない。
	 */
	public static function as_requested( string $url ): string {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( trim( (string) wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) : '';
		if ( '' === $host || ! preg_match( '/^[a-z0-9.-]+(:\d{1,5})?$/', $host ) ) {
			return $url;
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return $url;
		}
		$scheme = is_ssl() ? 'https' : 'http';
		return $scheme . '://' . $host . ( $parts['path'] ?? '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
	}

	/**
	 * アップロード用 URL を発行する（ツール get-upload-url から）。
	 *
	 * @param array<string,mixed> $in post_type, filename, title?, alt?, attach_to_post_id?, local_path?
	 * @return array{data:array<string,mixed>,warnings:list<string>}
	 * @throws DomainError
	 */
	public static function issue( array $in ): array {
		$post_type = (string) ( $in['post_type'] ?? '' );
		PostTypes::assert_writable( $post_type );
		$safe = sanitize_file_name( wp_basename( (string) ( $in['filename'] ?? '' ) ) );
		if ( '' === $safe || null === ImageSniff::mime_for_name( $safe ) ) {
			throw new DomainError( 'MEDIA-002', 'extension not allowed: ' . $safe, false, '送れるのは JPEG・PNG・WebP（.jpg・.jpeg・.png・.webp）だけです' );
		}
		$parent = UploadService::check_parent( (int) ( $in['attach_to_post_id'] ?? 0 ), $post_type );
		$ticket = bin2hex( random_bytes( 24 ) );
		set_transient(
			self::PREFIX . hash( 'sha256', $ticket ),
			array(
				'user_id'   => get_current_user_id(),
				'key_id'    => RequestContext::key_id(),
				'post_type' => $post_type,
				'filename'  => mb_substr( $safe, 0, 200 ),
				'title'     => isset( $in['title'] ) ? mb_substr( sanitize_text_field( (string) $in['title'] ), 0, 200 ) : '',
				'alt'       => isset( $in['alt'] ) ? mb_substr( sanitize_text_field( (string) $in['alt'] ), 0, 300 ) : '',
				'parent'    => $parent,
			),
			self::TTL
		);
		$url    = self::url( $ticket );
		$header = '';
		$auth   = isset( $_SERVER['HTTP_AUTHORIZATION'] ) && is_string( $_SERVER['HTTP_AUTHORIZATION'] ) ? trim( (string) $_SERVER['HTTP_AUTHORIZATION'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- 形を確かめて写すだけ
		if ( 1 === preg_match( '#^Basic [A-Za-z0-9+/=]{4,400}$#', $auth ) ) {
			$header = ' -H ' . escapeshellarg( 'Authorization: ' . $auth ); // サーバーの Basic 認証（公開前のサイト）を通るため。
		}
		// 手元の写真のパス（local_path）が付いていれば、そのまま実行できるコマンドにする（0.9.22。置き換え忘れで送れなかったため）。
		$local = isset( $in['local_path'] ) && is_string( $in['local_path'] ) ? trim( $in['local_path'] ) : '';
		if ( '' !== $local && ( strlen( $local ) > 1000 || false !== strpos( $local, "\0" ) || 1 !== preg_match( '/\.(jpe?g|png|webp)$/i', $local ) ) ) {
			$local = '';
		}
		$file_arg = '' !== $local ? '@' . escapeshellarg( $local ) : '@"<写真のファイルのパス>"';
		$command  = 'curl -sS -X POST -H ' . escapeshellarg( 'Content-Type: application/octet-stream' ) . $header . ' --data-binary ' . $file_arg . ' ' . escapeshellarg( $url );
		return array(
			'data'     => array(
				'upload_url' => $url,
				'method'     => 'POST',
				'command'    => $command,
				'expires_at' => gmdate( 'c', time() + self::TTL ),
				'max_mb'     => (int) Settings::get()['media_max_mb'],
				'how_to'     => ( '' !== $local ? 'command を、そのままシェルで1回だけ実行してください。' : 'command の <写真のファイルのパス> を、手元の写真のパスに置き換えて、シェルで1回だけ実行してください（次からは local_path に手元のパスを入れて呼ぶと、そのまま実行できる command が返ります）。' ) . '返事の JSON の data.attachment_id を、ACF の画像の欄や featured_media に使います（url_large は本文の img に）。URL は30分・1回だけ使えます。',
			),
			'warnings' => array( UploadService::PUBLIC_NOTICE ),
		);
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle( \WP_REST_Request $request ) {
		if ( ! Settings::enabled() ) {
			return self::error( 'MCP-001', 'MCP は止まっています', 503 );
		}
		$key = self::PREFIX . hash( 'sha256', (string) $request['ticket'] );
		$t   = get_transient( $key );
		if ( ! is_array( $t ) ) {
			return self::error( 'MEDIA-001', 'アップロード用 URL が使えません（期限切れか使用済みです。get-upload-url からやり直してください）', 404 );
		}
		delete_transient( $key );
		$user_id = (int) $t['user_id'];
		wp_set_current_user( $user_id );
		$log = array(
			'tool'      => 'ship-content/upload-url',
			'post_type' => (string) $t['post_type'],
			'user_id'   => $user_id,
			'key_id'    => (string) $t['key_id'],
			'auth'      => 'ticket',
		);
		if ( '' !== AccessGate::user_problem( $user_id ) ) {
			AuditLog::write( 'tool', 'denied', 'AUTH-001', 'upload url: user not allowed', $log );
			return self::error( 'AUTH-001', 'このユーザーは使えません', 403 );
		}
		$body = (string) $request->get_body();
		$max  = (int) Settings::get()['media_max_mb'] * 1024 * 1024;
		$tmp  = '';
		try {
			PostTypes::assert_writable( (string) $t['post_type'] );
			if ( '' === $body || strlen( $body ) > $max ) {
				throw new DomainError( 'MEDIA-002', 'size out of range: ' . strlen( $body ), false, '写真が空か、上限（' . (int) Settings::get()['media_max_mb'] . 'MB）を超えています' );
			}
			$mime = ImageSniff::sniff( substr( $body, 0, 16 ) );
			if ( null === $mime ) {
				throw new DomainError( 'MEDIA-002', 'not an allowed image', false, '送れるのは JPEG・PNG・WebP だけです（iPhone の HEIC は JPEG に変えてから）' );
			}
			$ext      = array(
				'image/jpeg' => 'jpg',
				'image/png'  => 'png',
				'image/webp' => 'webp',
			)[ $mime ];
			$filename = pathinfo( (string) $t['filename'], PATHINFO_FILENAME ) . '.' . $ext;
			$sha      = hash( 'sha256', $body );
			$existing = UploadService::existing( $sha );
			if ( $existing > 0 ) {
				AuditLog::write( 'tool', 'ok', '', 'upload url: already uploaded', $log + array( 'post_id' => $existing ) );
				return new \WP_REST_Response(
					array(
						'ok'       => true,
						'data'     => array( 'already_uploaded' => true ) + UploadService::attachment_info( $existing ),
						'warnings' => array( '同じ写真がすでに登録されているので、その添付 ID を返しました。' ),
					),
					200
				);
			}
			require_once ABSPATH . 'wp-admin/includes/file.php';
			$tmp = (string) wp_tempnam( $filename );
			if ( '' === $tmp || false === file_put_contents( $tmp, $body ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				throw new DomainError( 'SYS-001', 'could not write temp file', true );
			}
			$stored = ( new UploadService() )->store(
				$tmp,
				$filename,
				$mime,
				(string) $t['post_type'],
				UploadService::check_parent( (int) $t['parent'], (string) $t['post_type'] ),
				array_filter(
					array(
						'title' => (string) $t['title'],
						'alt'   => (string) $t['alt'],
					),
					static fn( $v ) => '' !== $v
				),
				$sha
			);
			$tmp = '';
			$meta = json_decode( (string) get_post_meta( $stored['id'], UploadService::META_UPLOADED, true ), true );
			if ( is_array( $meta ) ) {
				$meta['key_id'] = (string) $t['key_id'];
				$meta['via']    = 'upload_url';
				update_post_meta( $stored['id'], UploadService::META_UPLOADED, (string) wp_json_encode( $meta ) );
			}
			AuditLog::write( 'tool', 'ok', '', 'upload url', $log + array( 'post_id' => $stored['id'] ) );
			return new \WP_REST_Response(
				array(
					'ok'       => true,
					'data'     => $stored['data'],
					'warnings' => $stored['warnings'],
				),
				201
			);
		} catch ( DomainError $e ) {
			AuditLog::write( 'tool', 'error', $e->error_code(), 'upload url: ' . $e->admin_detail(), $log );
			return self::error( $e->error_code(), $e->getMessage(), 'SYS-001' === $e->error_code() ? 500 : 400 );
		} finally {
			if ( '' !== $tmp && file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
		}
	}

	private static function error( string $code, string $message, int $status ): \WP_Error {
		return new \WP_Error( 'ship_content_mcp_upload', $message . '（' . $code . '）', array( 'status' => $status ) );
	}
}
