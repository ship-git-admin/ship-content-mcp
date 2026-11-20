<?php
/**
 * 下書きの検証（dry run）・作成・修正。
 *
 * 安全の決まり：
 * - 状態は常に下書き（post_status は受け取らない）。公開・予約・削除・ゴミ箱はしない。
 * - 書けるのは「試験中・試験合格・稼働」の投稿タイプだけ。試験中に作った下書きには試験の印。
 * - ACF は、いまの構造（その投稿で実際に効くグループ）と照らしてから update_field（ACF の公式 API）で書く。SQL は使わない。
 * - 作成：推定の構造で確かめる → 印付きの下書きを作る → 実際の下書きで判定し直す（違えば消して ACF-001）→ 書く → 読み戻す。
 *   途中で失敗したら、作った下書きを消す（自分で作ったものだけ）。
 * - 修正：下書きか・ほかの人が編集中でないか・版の印が合うかを確かめる → 消す変更は allow_removals がなければ止める
 *   → 前の値を控える → 書く（失敗したら控えから戻す）→ 読み戻す。指定しなかった欄は変えない。
 * - 同じ idempotency_key の送り直しは、前の結果を返す。
 * - WordPress と ACF の保存は1つのトランザクションにならない。途中で失敗したときは、控えから戻す（戻しきれないときは記録に残す）。
 *
 * @package ShipContentMcp
 */

namespace ShipContentMcp\Content;

use ShipContentMcp\ACF\SchemaReader;
use ShipContentMcp\ACF\Validator;
use ShipContentMcp\Core\DomainError;
use ShipContentMcp\Security\AuditLog;
use ShipContentMcp\Security\RequestContext;

defined( 'ABSPATH' ) || exit;

final class DraftService {

	public const META_CREATED   = '_ship_content_mcp_created';
	public const META_TEST      = '_ship_content_mcp_test';
	public const META_SNAPSHOTS = '_ship_content_mcp_snapshots';
	public const SNAPSHOTS_KEEP = 5;
	public const MAX_TITLE      = 300;
	public const MAX_CONTENT    = 1000000;

	/** @var list<array{path:string,code:string,message:string}> */
	private array $errors = array();

	/** @var list<string> */
	private array $warnings = array();

	// ---- 検証（書かない） ------------------------------------------------------

	/**
	 * @param array<string,mixed> $draft create のときは draft_create、update のときは draft_patch の形
	 * @return array{data:array<string,mixed>,warnings:list<string>}
	 */
	public function validate( string $operation, array $draft ): array {
		$this->errors   = array();
		$this->warnings = array();
		$data           = array( 'operation' => $operation );
		try {
			if ( 'create' === $operation ) {
				$plan = $this->plan_create( $draft, false );
			} elseif ( 'update' === $operation ) {
				$plan = $this->plan_update( $draft, false );
			} else {
				throw new DomainError( 'INPUT-001', 'unknown operation', false, 'operation は create か update にしてください' );
			}
			$data += array(
				'schema_fingerprint' => $plan['fingerprint'],
				'location'           => $plan['location'],
				'changed_fields'     => $plan['changed_fields'],
				'removals'           => $plan['removals'],
				'diff'               => $plan['diff'],
				'missing_required'   => $plan['missing_required'],
			);
		} catch ( DomainError $e ) {
			if ( array() === $e->details() ) {
				$this->errors[] = array(
					'path'    => '',
					'code'    => $e->error_code(),
					'message' => $e->getMessage(),
				);
			}
			foreach ( $e->details() as $d ) {
				$this->errors[] = $d;
			}
		}
		$data['valid']  = array() === $this->errors;
		$data['errors'] = $this->errors;
		return array(
			'data'     => $data,
			'warnings' => $this->warnings,
		);
	}

	// ---- 作成 ------------------------------------------------------------------

	/**
	 * @param array<string,mixed> $in draft_create の形
	 * @return array{data:array<string,mixed>,warnings:list<string>,log:array<string,mixed>}
	 * @throws DomainError
	 */
	public function create( array $in ): array {
		$this->errors   = array();
		$this->warnings = array();
		$user           = get_current_user_id();
		$key            = (string) ( $in['idempotency_key'] ?? '' );
		$payload        = $in;
		unset( $payload['idempotency_key'] );
		$post_type = (string) ( $in['post_type'] ?? '' );
		PostTypes::assert_writable( $post_type );
		$prev = Idempotency::claim( $user, $key, 'create', $payload );
		if ( null !== $prev ) {
			$prev['data']['idempotent_replay'] = true;
			return array(
				'data'     => $prev['data'] ?? array(),
				'warnings' => array_merge( (array) ( $prev['warnings'] ?? array() ), array( '同じ依頼なので、前に作った下書きの結果を返しました（新しくは作っていません）。' ) ),
				'log'      => array( 'post_id' => (int) ( $prev['data']['post_id'] ?? 0 ) ),
			);
		}
		$post_id = 0;
		try {
			$plan = $this->plan_create( $in, true );
			$meta = array(
				self::META_CREATED => (string) wp_json_encode(
					array(
						'user_id'    => $user,
						'key_id'     => RequestContext::key_id(),
						'request_id' => RequestContext::request_id(),
						'at'         => gmdate( 'c' ),
					)
				),
			);
			if ( PostTypes::testing( $post_type ) ) {
				$meta[ self::META_TEST ] = '1';
			}
			$post_id = wp_insert_post(
				array(
					'post_type'    => $post_type,
					'post_status'  => 'draft',
					'post_title'   => $plan['title'],
					'post_content' => $plan['content'] ?? '',
					'post_author'  => $user,
					'meta_input'   => $meta,
				),
				true
			);
			if ( is_wp_error( $post_id ) || ! $post_id ) {
				$post_id = 0;
				throw new DomainError( 'SYS-001', 'wp_insert_post failed', true );
			}
			$post_id = (int) $post_id;
			$this->apply_terms( $post_id, $plan['terms'] );

			// 実際の下書きで判定し直す。
			$real = ( new SchemaReader() )->read( $post_type, $post_id );
			if ( $real['data']['schema_fingerprint'] !== $plan['fingerprint'] ) {
				throw new DomainError( 'ACF-001', 'structure of the real draft differs from the estimate', false, '', array( array( 'path' => '', 'code' => 'schema_changed', 'message' => '実際の下書きでは、使われる入力欄が推定と違いました。get-acf-schema を読み直してください（schema_fingerprint：' . $real['data']['schema_fingerprint'] . '）' ) ) );
			}
			$validator = new Validator( $real['data']['field_groups'] );
			$values    = $validator->changes( $plan['acf_input'] );
			if ( array() !== $validator->errors() ) {
				throw new DomainError( 'ACF-002', 'validation failed on real draft', false, '', $validator->errors() );
			}
			$this->write_acf( $post_id, $values );
			if ( isset( $plan['featured_media'] ) ) {
				set_post_thumbnail( $post_id, (int) $plan['featured_media'] );
			}
			$this->assert_still_draft( $post_id );
			$readback = $this->readback( $post_id, $real['data']['field_groups'], $values );
			$post     = get_post( $post_id );
			$data     = array(
				'post_id'            => $post_id,
				'post_status'        => 'draft',
				'post_type'          => $post_type,
				'server_revision'    => PostReader::token( $post, $real['data']['field_groups'] ),
				'schema_fingerprint' => $real['data']['schema_fingerprint'],
				'changed_fields'     => $plan['changed_fields'],
				'readback'           => $readback,
				'missing_required'   => $validator->missing_required( PostReader::acf_values( $post_id, $real['data']['field_groups'] ) ),
				'test_marked'        => PostTypes::testing( $post_type ),
				'admin_edit_url'     => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
				'idempotent_replay'  => false,
			);
			$warnings = array_merge( $this->warnings, $validator->warnings() );
			if ( array() !== $data['missing_required'] ) {
				$warnings[] = '必須の欄が空です（下書きなので保存はしました）：' . implode( '、', array_slice( $data['missing_required'], 0, 20 ) );
			}
			if ( ! $readback['ok'] ) {
				$warnings[] = '保存した値の一部が、送った値と違います（読み戻しの結果を確かめてください）：' . implode( '、', array_slice( $readback['mismatches'], 0, 10 ) );
			}
			if ( $data['test_marked'] ) {
				$warnings[] = 'この投稿タイプは「試験中」なので、下書きに試験の印を付けました。';
			}
			Idempotency::complete(
				$user,
				$key,
				$post_id,
				array(
					'data'     => $data,
					'warnings' => $warnings,
				)
			);
			return array(
				'data'     => $data,
				'warnings' => array_values( array_unique( $warnings ) ),
				'log'      => array( 'post_id' => $post_id ),
			);
		} catch ( \Throwable $e ) {
			if ( $post_id > 0 ) {
				// 自分で作った下書きだけを消す（印を確かめてから）。
				if ( '' !== (string) get_post_meta( $post_id, self::META_CREATED, true ) && 'draft' === get_post_status( $post_id ) ) {
					wp_delete_post( $post_id, true );
				} else {
					AuditLog::write( 'tool', 'error', 'SYS-001', 'could not remove partial draft #' . $post_id, array( 'post_id' => $post_id ) );
				}
			}
			Idempotency::release( $user, $key );
			throw $e instanceof DomainError ? $e : new DomainError( 'SYS-001', get_class( $e ) . ': ' . mb_substr( $e->getMessage(), 0, 120 ), true );
		}
	}

	// ---- 修正 ------------------------------------------------------------------

	/**
	 * @param array<string,mixed> $in draft_patch の形
	 * @return array{data:array<string,mixed>,warnings:list<string>,log:array<string,mixed>}
	 * @throws DomainError
	 */
	public function update( array $in ): array {
		$this->errors   = array();
		$this->warnings = array();
		$user           = get_current_user_id();
		$key            = (string) ( $in['idempotency_key'] ?? '' );
		$payload        = $in;
		unset( $payload['idempotency_key'] );
		$post_id = (int) ( $in['post_id'] ?? 0 );
		$prev    = Idempotency::claim( $user, $key, 'update', $payload );
		if ( null !== $prev ) {
			$prev['data']['idempotent_replay'] = true;
			return array(
				'data'     => $prev['data'] ?? array(),
				'warnings' => array_merge( (array) ( $prev['warnings'] ?? array() ), array( '同じ依頼なので、前の修正の結果を返しました（もう一度は書いていません）。' ) ),
				'log'      => array( 'post_id' => $post_id ),
			);
		}
		$snapshot = null;
		try {
			$plan = $this->plan_update( $in, true );
			if ( array() !== $plan['removals'] && empty( $in['allow_removals'] ) ) {
				throw new DomainError(
					'EDIT-001',
					'removals without allow_removals',
					false,
					'',
					array_map(
						static fn( $r ) => array(
							'path'    => '',
							'code'    => 'removal',
							'message' => $r,
						),
						$plan['removals']
					)
				);
			}
			$snapshot = $this->snapshot( $post_id, $plan );
			$this->apply_update( $post_id, $plan );
			$this->assert_still_draft( $post_id );
			$groups   = $plan['groups_after'];
			$readback = $this->readback( $post_id, $groups, $plan['acf_values'] );
			$post     = get_post( $post_id );
			$data     = array(
				'post_id'            => $post_id,
				'post_status'        => 'draft',
				'post_type'          => $post->post_type,
				'server_revision'    => PostReader::token( $post, $groups ),
				'schema_fingerprint' => $plan['fingerprint'],
				'changed_fields'     => $plan['changed_fields'],
				'diff'               => $plan['diff'],
				'removals'           => $plan['removals'],
				'readback'           => $readback,
				'missing_required'   => $plan['missing_required'],
				'admin_edit_url'     => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
				'idempotent_replay'  => false,
			);
			$warnings = $this->warnings;
			if ( ! $readback['ok'] ) {
				$warnings[] = '保存した値の一部が、送った値と違います（読み戻しの結果を確かめてください）：' . implode( '、', array_slice( $readback['mismatches'], 0, 10 ) );
			}
			Idempotency::complete(
				$user,
				$key,
				$post_id,
				array(
					'data'     => $data,
					'warnings' => $warnings,
				)
			);
			return array(
				'data'     => $data,
				'warnings' => array_values( array_unique( $warnings ) ),
				'log'      => array( 'post_id' => $post_id ),
			);
		} catch ( \Throwable $e ) {
			if ( null !== $snapshot ) {
				// 書いている間に人が公開した場合は、公開済みの記事を書き換えないよう、戻さずに記録だけ残す。
				if ( 'draft' === get_post_status( $post_id ) ) {
					$this->restore( $post_id, $snapshot );
				} else {
					AuditLog::write( 'tool', 'error', 'SYS-001', 'not restored: status changed to ' . (string) get_post_status( $post_id ), array( 'post_id' => $post_id ) );
				}
			}
			Idempotency::release( $user, $key );
			throw $e instanceof DomainError ? $e : new DomainError( 'SYS-001', get_class( $e ) . ': ' . mb_substr( $e->getMessage(), 0, 120 ), true );
		}
	}

	// ---- 計画（検証と、書く内容の準備） -------------------------------------------

	/**
	 * @param array<string,mixed> $in
	 * @return array<string,mixed>
	 * @throws DomainError
	 */
	private function plan_create( array $in, bool $strict ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$post_type = (string) ( $in['post_type'] ?? '' );
		PostTypes::assert_writable( $post_type );
		$errors = array();
		$title  = $this->title( $in['title'] ?? null, 'title', $errors, true );
		$content = null;
		if ( array_key_exists( 'content', $in ) ) {
			$content = $this->content( $in['content'], 'content', $errors );
		}
		$featured = null;
		if ( isset( $in['featured_media'] ) ) {
			$featured = $this->attachment( $in['featured_media'], 'featured_media', $errors );
		}
		$terms     = $this->terms( $post_type, (array) ( $in['taxonomies'] ?? array() ), $errors );
		$acf_input = $this->acf_input( (array) ( $in['acf_changes'] ?? array() ), $errors );
		$this->fail_if( $errors, 'INPUT-001' );

		$term_list = array();
		foreach ( $terms as $tax => $ids ) {
			$term_list[] = array(
				'taxonomy' => $tax,
				'term_ids' => $ids,
			);
		}
		$est = ( new SchemaReader() )->read( $post_type, 0, $term_list );
		$fp  = $est['data']['schema_fingerprint'];
		if ( (string) ( $in['schema_fingerprint'] ?? '' ) !== $fp ) {
			throw new DomainError( 'ACF-001', 'schema_fingerprint mismatch', false, '', array( array( 'path' => 'schema_fingerprint', 'code' => 'schema_changed', 'message' => '入力欄の構造が、読んだときと違います。get-acf-schema を（予定の分類を付けて）読み直してください（いまの schema_fingerprint：' . $fp . '）' ) ) );
		}
		$validator = new Validator( $est['data']['field_groups'] );
		$values    = $validator->changes( $acf_input );
		$this->fail_if( $validator->errors(), 'ACF-002' );
		$this->warnings = array_merge( $this->warnings, $validator->warnings() );

		$changed = array_merge( array( 'post_title' ), null !== $content ? array( 'post_content' ) : array(), null !== $featured ? array( 'featured_media' ) : array(), array_map( static fn( $t ) => 'taxonomy:' . $t, array_keys( array_filter( $terms ) ) ), array_keys( $values ) );
		unset( $strict );
		return array(
			'title'            => $title,
			'content'          => $content,
			'featured_media'   => $featured,
			'terms'            => $terms,
			'acf_input'        => $acf_input,
			'acf_values'       => $values,
			'fingerprint'      => $fp,
			'location'         => $est['data']['context'],
			'changed_fields'   => $changed,
			'removals'         => array(),
			'diff'             => array(),
			'missing_required' => $validator->missing_required( $values ),
		);
	}

	/**
	 * @param array<string,mixed> $in
	 * @return array<string,mixed>
	 * @throws DomainError
	 */
	private function plan_update( array $in, bool $strict ): array {
		$post_type = (string) ( $in['post_type'] ?? '' );
		$post_id   = (int) ( $in['post_id'] ?? 0 );
		PostTypes::assert_writable( $post_type );
		$post = PostReader::post( $post_type, $post_id );
		if ( 'draft' !== $post->post_status ) {
			throw new DomainError( 'MCP-004', 'status is ' . $post->post_status );
		}
		if ( ! function_exists( 'wp_check_post_lock' ) ) {
			require_once ABSPATH . 'wp-admin/includes/post.php';
		}
		$lock = wp_check_post_lock( $post_id );
		if ( $lock ) {
			throw new DomainError( 'CONFLICT-001', 'edit lock by another user', true, '別の人が管理画面でこの下書きを編集中です。編集が終わってから、もう一度お試しください' );
		}

		$errors   = array();
		$title    = null;
		$content  = null;
		$featured = null;
		$term_in  = array();
		$acf_in   = array();
		$kinds    = array();
		foreach ( (array) ( $in['changes'] ?? array() ) as $i => $c ) {
			$c    = (array) $c;
			$kind = (string) ( $c['kind'] ?? '' );
			$path = 'changes[' . $i . ']';
			if ( in_array( $kind, array( 'post_title', 'post_content', 'featured_media' ), true ) && isset( $kinds[ $kind ] ) ) {
				$errors[] = array( 'path' => $path, 'code' => 'duplicate', 'message' => $kind . ' が2回指定されています' );
				continue;
			}
			$kinds[ $kind ] = true;
			$allowed_props  = array(
				'post_title'     => array( 'kind', 'value' ),
				'post_content'   => array( 'kind', 'value' ),
				'featured_media' => array( 'kind', 'attachment_id' ),
				'acf_field'      => array( 'kind', 'field_key', 'value' ),
				'taxonomy_terms' => array( 'kind', 'taxonomy', 'term_ids' ),
			);
			$extra = isset( $allowed_props[ $kind ] ) ? array_diff( array_keys( $c ), $allowed_props[ $kind ] ) : array();
			if ( array() !== $extra ) {
				$errors[] = array( 'path' => $path, 'code' => 'property', 'message' => $kind . ' には使えない項目があります：' . implode( ', ', $extra ) );
				continue;
			}
			switch ( $kind ) {
				case 'post_title':
					$title = $this->title( $c['value'] ?? null, $path, $errors, true );
					break;
				case 'post_content':
					$content = $this->content( $c['value'] ?? null, $path, $errors );
					break;
				case 'featured_media':
					$featured = $this->attachment( $c['attachment_id'] ?? null, $path, $errors );
					break;
				case 'taxonomy_terms':
					$term_in[] = $c;
					break;
				case 'acf_field':
					$acf_in[] = $c;
					break;
				default:
					$errors[] = array( 'path' => $path, 'code' => 'kind', 'message' => '知らない kind です：' . $kind );
			}
		}
		$terms_changed = $this->terms( $post_type, $term_in, $errors );
		$acf_input     = $this->acf_input( $acf_in, $errors );
		$this->fail_if( $errors, 'INPUT-001' );

		$reader = new SchemaReader();
		$now    = $reader->read( $post_type, $post_id );
		if ( isset( $in['schema_fingerprint'] ) && (string) $in['schema_fingerprint'] !== $now['data']['schema_fingerprint'] ) {
			throw new DomainError( 'ACF-001', 'schema_fingerprint mismatch', false, '', array( array( 'path' => 'schema_fingerprint', 'code' => 'schema_changed', 'message' => '入力欄の構造が、読んだときと違います。get-post と get-acf-schema を読み直してください（いまの schema_fingerprint：' . $now['data']['schema_fingerprint'] . '）' ) ) );
		}
		$token = PostReader::token( $post, $now['data']['field_groups'] );
		if ( (string) ( $in['if_match'] ?? '' ) !== $token ) {
			throw new DomainError( 'CONFLICT-001', 'if_match mismatch', true, '', array( array( 'path' => 'if_match', 'code' => 'conflict', 'message' => '読んだ後に、この下書きが変わりました。get-post で読み直してから、もう一度直してください（いまの if_match：' . $token . '）' ) ) );
		}

		// 分類を変えるときは、変えた後の構造で確かめる。
		$current_terms = array();
		foreach ( PostReader::summary( $post )['taxonomies'] as $row ) {
			$current_terms[ $row['taxonomy'] ] = $row['term_ids'];
		}
		$final_terms = array_replace( $current_terms, $terms_changed );
		$after       = array() === $terms_changed ? $now : $reader->read( $post_type, $post_id, array(), $final_terms );
		if ( $after['data']['schema_fingerprint'] !== $now['data']['schema_fingerprint'] ) {
			$this->warnings[] = '分類を変えると、使われる入力欄のグループが変わります（変えた後の構造で確かめました）。';
		}
		$validator = new Validator( $after['data']['field_groups'] );
		$values    = $validator->changes( $acf_input );
		$this->fail_if( $validator->errors(), 'ACF-002' );
		$this->warnings = array_merge( $this->warnings, $validator->warnings() );

		// 消す変更・差分。
		$old      = PostReader::acf_values( $post_id, $after['data']['field_groups'] );
		$removals = array();
		$diff     = array();
		foreach ( $values as $k => $v ) {
			$f        = (array) $validator->field( $k );
			$removals = array_merge( $removals, Diff::removals( $f, $old[ $k ] ?? null, $v, (string) ( $f['label'] ?? $k ) ) );
			$changed  = Diff::mismatches( $v, $old[ $k ] ?? null, $k );
			$diff[]   = array(
				'field'   => $k,
				'label'   => (string) ( $f['label'] ?? '' ),
				'before'  => Diff::brief( $old[ $k ] ?? null ),
				'after'   => Diff::brief( $v ),
				'changed' => array_slice( $changed, 0, 20 ),
			);
		}
		if ( null !== $title ) {
			$diff[] = array( 'field' => 'post_title', 'label' => '題名', 'before' => Diff::brief( $post->post_title ), 'after' => Diff::brief( $title ), 'changed' => $title === $post->post_title ? array() : array( 'post_title' ) );
		}
		if ( null !== $content ) {
			if ( '' !== trim( (string) $post->post_content ) && '' === trim( $content ) ) {
				$removals[] = '本文：いまの本文が空になります';
			}
			$diff[] = array( 'field' => 'post_content', 'label' => '本文', 'before' => Diff::brief( $post->post_content ), 'after' => Diff::brief( $content ), 'changed' => $content === $post->post_content ? array() : array( 'post_content' ) );
		}
		foreach ( $terms_changed as $tax => $ids ) {
			$gone = array_diff( $current_terms[ $tax ] ?? array(), $ids );
			if ( array() !== $gone ) {
				$removals[] = '分類 ' . $tax . '：' . count( $gone ) . ' 件外れます';
			}
		}
		if ( null !== $featured ) {
			$old_thumb = (int) get_post_thumbnail_id( $post_id );
			$diff[]    = array( 'field' => 'featured_media', 'label' => 'アイキャッチ', 'before' => (string) $old_thumb, 'after' => (string) $featured, 'changed' => $old_thumb === $featured ? array() : array( 'featured_media' ) );
		}
		$merged = array_replace( $old, $values );
		$fields = array_keys( $values );
		unset( $strict );
		return array(
			'post'             => $post,
			'title'            => $title,
			'content'          => $content,
			'featured_media'   => $featured,
			'terms'            => $terms_changed,
			'current_terms'    => $current_terms,
			'acf_values'       => $values,
			'old_values'       => array_intersect_key( $old, $values ),
			'groups_after'     => $after['data']['field_groups'],
			'fingerprint'      => $after['data']['schema_fingerprint'],
			'location'         => $after['data']['context'],
			'changed_fields'   => array_merge( null !== $title ? array( 'post_title' ) : array(), null !== $content ? array( 'post_content' ) : array(), null !== $featured ? array( 'featured_media' ) : array(), array_map( static fn( $t ) => 'taxonomy:' . $t, array_keys( $terms_changed ) ), $fields ),
			'removals'         => $removals,
			'diff'             => $diff,
			'missing_required' => $validator->missing_required( $merged ),
		);
	}

	// ---- 書く・戻す --------------------------------------------------------------

	/**
	 * @param array<string,mixed> $values
	 */
	private function write_acf( int $post_id, array $values ): void {
		foreach ( $values as $key => $value ) {
			update_field( $key, $value, $post_id );
		}
	}

	/**
	 * @param array<string,list<int>> $terms
	 */
	private function apply_terms( int $post_id, array $terms ): void {
		foreach ( $terms as $tax => $ids ) {
			$r = wp_set_object_terms( $post_id, $ids, $tax, false );
			if ( is_wp_error( $r ) ) {
				throw new DomainError( 'SYS-001', 'wp_set_object_terms failed: ' . $tax, true );
			}
		}
	}

	/**
	 * @param array<string,mixed> $plan
	 */
	private function apply_update( int $post_id, array $plan ): void {
		$postarr = array( 'ID' => $post_id );
		if ( null !== $plan['title'] ) {
			$postarr['post_title'] = $plan['title'];
		}
		if ( null !== $plan['content'] ) {
			$postarr['post_content'] = $plan['content'];
		}
		if ( count( $postarr ) > 1 ) {
			$r = wp_update_post( $postarr, true );
			if ( is_wp_error( $r ) ) {
				throw new DomainError( 'SYS-001', 'wp_update_post failed', true );
			}
		}
		$this->apply_terms( $post_id, $plan['terms'] );
		if ( null !== $plan['featured_media'] ) {
			set_post_thumbnail( $post_id, (int) $plan['featured_media'] );
		}
		$this->write_acf( $post_id, $plan['acf_values'] );
	}

	/**
	 * 修正の前の値を控える（投稿の meta に、新しい順で5件まで）。
	 *
	 * @param array<string,mixed> $plan
	 * @return array<string,mixed>
	 */
	private function snapshot( int $post_id, array $plan ): array {
		$post = $plan['post'];
		$snap = array(
			'at'         => gmdate( 'c' ),
			'user_id'    => get_current_user_id(),
			'request_id' => RequestContext::request_id(),
			'acf'        => $plan['old_values'],
		);
		if ( null !== $plan['title'] ) {
			$snap['post_title'] = $post->post_title;
		}
		if ( null !== $plan['content'] ) {
			$snap['post_content'] = $post->post_content;
		}
		if ( null !== $plan['featured_media'] ) {
			$snap['featured_media'] = (int) get_post_thumbnail_id( $post_id );
		}
		if ( array() !== $plan['terms'] ) {
			$snap['terms'] = array_intersect_key( $plan['current_terms'], $plan['terms'] );
		}
		$list = get_post_meta( $post_id, self::META_SNAPSHOTS, true );
		$list = is_array( $list ) ? $list : array();
		array_unshift( $list, $snap );
		update_post_meta( $post_id, self::META_SNAPSHOTS, array_slice( $list, 0, self::SNAPSHOTS_KEEP ) );
		return $snap;
	}

	/**
	 * 控えから戻す（途中で失敗したとき）。
	 *
	 * @param array<string,mixed> $snap
	 */
	private function restore( int $post_id, array $snap ): void {
		try {
			$postarr = array( 'ID' => $post_id );
			foreach ( array( 'post_title', 'post_content' ) as $k ) {
				if ( array_key_exists( $k, $snap ) ) {
					$postarr[ $k ] = $snap[ $k ];
				}
			}
			if ( count( $postarr ) > 1 ) {
				wp_update_post( $postarr );
			}
			foreach ( (array) ( $snap['terms'] ?? array() ) as $tax => $ids ) {
				wp_set_object_terms( $post_id, $ids, $tax, false );
			}
			if ( array_key_exists( 'featured_media', $snap ) ) {
				$snap['featured_media'] ? set_post_thumbnail( $post_id, (int) $snap['featured_media'] ) : delete_post_thumbnail( $post_id );
			}
			foreach ( (array) $snap['acf'] as $key => $value ) {
				update_field( $key, $value, $post_id );
			}
			AuditLog::write( 'tool', 'restored', 'SYS-001', 'restored from snapshot', array( 'post_id' => $post_id ) );
		} catch ( \Throwable $e ) {
			AuditLog::write( 'tool', 'error', 'SYS-001', 'restore failed: ' . mb_substr( $e->getMessage(), 0, 150 ), array( 'post_id' => $post_id ) );
		}
	}

	/**
	 * @throws DomainError
	 */
	private function assert_still_draft( int $post_id ): void {
		$status = get_post_status( $post_id );
		if ( 'draft' !== $status ) {
			throw new DomainError( 'MCP-004', 'status changed during write: ' . (string) $status );
		}
	}

	/**
	 * @param list<array<string,mixed>> $groups
	 * @param array<string,mixed>       $values
	 * @return array{ok:bool,mismatches:list<string>}
	 */
	private function readback( int $post_id, array $groups, array $values ): array {
		$saved      = PostReader::acf_values( $post_id, $groups );
		$mismatches = array();
		foreach ( $values as $k => $v ) {
			$mismatches = array_merge( $mismatches, Diff::mismatches( $v, $saved[ $k ] ?? null, $k ) );
		}
		return array(
			'ok'         => array() === $mismatches,
			'mismatches' => array_slice( $mismatches, 0, 50 ),
		);
	}

	// ---- 入力の部品 --------------------------------------------------------------

	/**
	 * @param mixed                                           $v
	 * @param list<array{path:string,code:string,message:string}> $errors
	 */
	private function title( $v, string $path, array &$errors, bool $required ): ?string {
		if ( ! is_string( $v ) ) {
			$errors[] = array( 'path' => $path, 'code' => 'type', 'message' => '題名を文字列で入れてください' );
			return null;
		}
		$t = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $v ) ) );
		if ( $required && '' === $t ) {
			$errors[] = array( 'path' => $path, 'code' => 'required', 'message' => '題名が空です' );
			return null;
		}
		if ( mb_strlen( $t ) > self::MAX_TITLE ) {
			$errors[] = array( 'path' => $path, 'code' => 'too_long', 'message' => '題名は ' . self::MAX_TITLE . ' 文字以内にしてください' );
			return null;
		}
		return $t;
	}

	/**
	 * @param mixed                                           $v
	 * @param list<array{path:string,code:string,message:string}> $errors
	 */
	private function content( $v, string $path, array &$errors ): ?string {
		if ( null === $v ) {
			return '';
		}
		if ( ! is_string( $v ) || strlen( $v ) > self::MAX_CONTENT ) {
			$errors[] = array( 'path' => $path, 'code' => 'type', 'message' => '本文を文字列（1MB まで）で入れてください' );
			return null;
		}
		return str_replace( array( "\r\n", "\r" ), "\n", $v );
	}

	/**
	 * @param mixed                                           $v
	 * @param list<array{path:string,code:string,message:string}> $errors
	 */
	private function attachment( $v, string $path, array &$errors ): ?int {
		$id = is_int( $v ) ? $v : ( is_string( $v ) && ctype_digit( $v ) ? (int) $v : 0 );
		if ( $id <= 0 || 'attachment' !== get_post_type( $id ) || ! wp_attachment_is_image( $id ) ) {
			$errors[] = array( 'path' => $path, 'code' => 'attachment', 'message' => 'その ID の画像がありません' );
			return null;
		}
		return $id;
	}

	/**
	 * @param list<mixed>                                     $rows taxonomy_terms の形の一覧
	 * @param list<array{path:string,code:string,message:string}> $errors
	 * @return array<string,list<int>>
	 */
	private function terms( string $post_type, array $rows, array &$errors ): array {
		$allowed = PostTypes::taxonomy_names( $post_type );
		$out     = array();
		foreach ( $rows as $i => $row ) {
			$row  = (array) $row;
			$tax  = (string) ( $row['taxonomy'] ?? '' );
			$path = 'taxonomies[' . $i . ']';
			if ( ! in_array( $tax, $allowed, true ) ) {
				$errors[] = array( 'path' => $path, 'code' => 'taxonomy', 'message' => 'この投稿タイプでは使えない分類です：' . $tax . '（使える分類：' . implode( ', ', $allowed ) . '）' );
				continue;
			}
			if ( isset( $out[ $tax ] ) ) {
				$errors[] = array( 'path' => $path, 'code' => 'duplicate', 'message' => '同じ分類が2回指定されています：' . $tax );
				continue;
			}
			$ids = array();
			foreach ( (array) ( $row['term_ids'] ?? array() ) as $id ) {
				$term = is_int( $id ) || ( is_string( $id ) && ctype_digit( $id ) ) ? get_term( (int) $id, $tax ) : null;
				if ( ! $term instanceof \WP_Term ) {
					$errors[] = array( 'path' => $path, 'code' => 'term', 'message' => 'その分類の項目がありません：' . ( is_scalar( $id ) ? (string) $id : '?' ) . '（新しい項目は作れません）' );
					continue 2;
				}
				$ids[] = (int) $term->term_id;
			}
			$out[ $tax ] = array_values( array_unique( $ids ) );
		}
		return $out;
	}

	/**
	 * @param list<mixed>                                     $rows acf_field の形の一覧
	 * @param list<array{path:string,code:string,message:string}> $errors
	 * @return list<array{field_key:string,value:mixed}>
	 */
	private function acf_input( array $rows, array &$errors ): array {
		$out = array();
		foreach ( $rows as $i => $row ) {
			$row = (array) $row;
			$key = (string) ( $row['field_key'] ?? '' );
			if ( ! preg_match( '/^field_[A-Za-z0-9_]+$/', $key ) ) {
				$errors[] = array( 'path' => 'acf[' . $i . ']', 'code' => 'field_key', 'message' => 'field_key の形が違います（field_ で始まる key）' );
				continue;
			}
			if ( ! array_key_exists( 'value', $row ) ) {
				$errors[] = array( 'path' => $key, 'code' => 'missing_value', 'message' => 'value がありません' );
				continue;
			}
			$out[] = array(
				'field_key' => $key,
				'value'     => $row['value'],
			);
		}
		return $out;
	}

	/**
	 * @param list<array{path:string,code:string,message:string}> $errors
	 * @throws DomainError
	 */
	private function fail_if( array $errors, string $code ): void {
		if ( array() !== $errors ) {
			throw new DomainError( $code, count( $errors ) . ' validation errors', false, '', $errors );
		}
	}
}
