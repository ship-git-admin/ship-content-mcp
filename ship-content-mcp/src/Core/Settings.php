<?php
/**
 * 基本設定（管理者だけが変える）。
 *
 * - enabled：MCP を動かすか。初期は停止（入れただけでは何も公開しない）。
 * - disable_default_server：公式 Adapter の既定サーバーを止める（初期は止める）。
 * - post_types：使ってよい投稿タイプと状態（unverified=未検証 / testing=試験中 / tested=試験合格 / active=稼働）。初期は works だけ・未検証。
 * - reject_privileged：一般の編集権限などを持つユーザーを MCP で使わせない（初期は拒否）。
 * - ip_allowlist：接続元の IP/CIDR（空なら制限しない）。REMOTE_ADDR だけを見る。
 * - auth_token：管理画面で発行したトークンでの接続（いちばん簡単。初期は使う）。token_http：トークンを HTTP でも受け付ける（公開前の確認用 URL のため。初期は受け付ける。公開時は MCP ごと外す）。
 * - auth_oauth：ブラウザで許可する接続（OAuth。招待コード）。oauth_invite_days：招待コードの期限（日、初期 7）。oauth_grant_days：許可の期限（日、初期 90）。
 *   oauth_remote_clients：戻り先が https の AI（Claude の Web 版など、AI の会社のサーバーからつなぐもの）も許す（初期は許さない）。
 * - auth_signature / auth_app_password：使ってよい認証の方法。
 * - structure_strict：入力欄の構造が、管理者が確かめた後に変わったら書き込みを止める（初期は止めずに知らせる。0.9.9）。
 * - allow_add_terms：記事に合う分類の語がないとき、AI が add-term で語を足せる（初期は入。親の下にだけ。0.9.13）。
 * - media_max_mb：写真1枚の上限（MB、1〜100）。strip_metadata：写真の位置情報などのメタデータを消す（初期は消す）。
 *
 * @package ShipContentMcp
 */

namespace ShipContentMcp\Core;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPTION = 'ship_content_mcp_settings';

	/** 投稿タイプの状態。書き込めるのは testing・tested・active。testing で作った下書きには試験の印が付く。 */
	public const STATUSES = array(
		'unverified' => '未検証（読み取りのみ）',
		'testing'    => '試験中（書き込みの試験。作った下書きに試験の印）',
		'tested'     => '試験合格',
		'active'     => '稼働',
	);

	public const WRITABLE_STATUSES = array( 'testing', 'tested', 'active' );

	private static ?array $cache = null;

	/**
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'enabled'                => false,
			'disable_default_server' => true,
			'post_types'             => array(
				'works' => array(
					'enabled' => true,
					'status'  => 'unverified',
				),
			),
			'reject_privileged'      => true,
			'ip_allowlist'           => array(),
			'auth_oauth'             => true,
			'oauth_invite_days'      => 7,
			'oauth_grant_days'       => 90,
			'oauth_remote_clients'   => false,
			'auth_token'             => true,
			'token_http'             => true,
			'auth_signature'         => true,
			'auth_app_password'      => true,
			'media_max_mb'           => 20,
			'strip_metadata'         => true,
			'structure_strict'       => false,
			'allow_add_terms'        => true,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function get(): array {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, array() );
			self::$cache = self::sanitize( is_array( $stored ) ? $stored : array(), true );
		}
		return self::$cache;
	}

	/**
	 * @param array<string,mixed> $in
	 * @return array<string,mixed>
	 */
	public static function save( array $in ): array {
		$clean = self::sanitize( $in, false );
		update_option( self::OPTION, $clean, false );
		self::$cache = null;
		return $clean;
	}

	public static function flush(): void {
		self::$cache = null;
	}

	/**
	 * 知らない値は初期値に戻す。
	 *
	 * @param array<string,mixed> $in
	 * @param bool                $use_default_types 投稿タイプの指定がないとき、初期値（works）を使うか
	 * @return array<string,mixed>
	 */
	public static function sanitize( array $in, bool $use_default_types ): array {
		$d   = self::defaults();
		$out = $d;
		foreach ( array( 'enabled', 'disable_default_server', 'reject_privileged', 'auth_oauth', 'oauth_remote_clients', 'auth_token', 'token_http', 'auth_signature', 'auth_app_password', 'strip_metadata', 'structure_strict', 'allow_add_terms' ) as $k ) {
			$out[ $k ] = array_key_exists( $k, $in ) ? self::bool( $in[ $k ] ) : $d[ $k ];
		}
		$out['oauth_invite_days'] = isset( $in['oauth_invite_days'] ) && is_numeric( $in['oauth_invite_days'] ) ? max( 1, min( 90, (int) $in['oauth_invite_days'] ) ) : $d['oauth_invite_days'];
		$out['oauth_grant_days']  = isset( $in['oauth_grant_days'] ) && is_numeric( $in['oauth_grant_days'] ) ? max( 1, min( 365, (int) $in['oauth_grant_days'] ) ) : $d['oauth_grant_days'];
		$out['media_max_mb'] = isset( $in['media_max_mb'] ) && is_numeric( $in['media_max_mb'] ) ? max( 1, min( 100, (int) $in['media_max_mb'] ) ) : $d['media_max_mb'];
		if ( array_key_exists( 'post_types', $in ) && is_array( $in['post_types'] ) ) {
			$out['post_types'] = array();
			foreach ( $in['post_types'] as $name => $row ) {
				$name = sanitize_key( (string) $name );
				if ( '' === $name || ! is_array( $row ) ) {
					continue;
				}
				$status                     = (string) ( $row['status'] ?? 'unverified' );
				$out['post_types'][ $name ] = array(
					'enabled' => self::bool( $row['enabled'] ?? false ),
					'status'  => isset( self::STATUSES[ $status ] ) ? $status : 'unverified',
				);
				// 管理者が確かめた構造（グループ key => 指紋）。試験合格・稼働のとき、これと違う構造には書かない。
				$verified = $row['verified'] ?? null;
				if ( is_array( $verified ) && is_array( $verified['groups'] ?? null ) ) {
					$groups = array();
					foreach ( $verified['groups'] as $key => $hash ) {
						if ( 1 === preg_match( '/^group_[A-Za-z0-9_]+$/', (string) $key ) && 1 === preg_match( '/^sha256:[0-9a-f]{64}$/', (string) $hash ) ) {
							$groups[ (string) $key ] = (string) $hash;
						}
					}
					$out['post_types'][ $name ]['verified'] = array(
						'groups' => $groups,
						'at'     => (int) ( $verified['at'] ?? 0 ),
						'by'     => (int) ( $verified['by'] ?? 0 ),
					);
				}
			}
		} elseif ( ! $use_default_types ) {
			$out['post_types'] = array();
		}
		$out['ip_allowlist'] = array();
		foreach ( (array) ( $in['ip_allowlist'] ?? array() ) as $entry ) {
			$entry = trim( (string) $entry );
			if ( '' !== $entry && \ShipContentMcp\Security\IpRules::is_valid_entry( $entry ) ) {
				$out['ip_allowlist'][] = $entry;
			}
		}
		$out['ip_allowlist'] = array_values( array_unique( array_slice( $out['ip_allowlist'], 0, 100 ) ) );
		return $out;
	}

	public static function enabled(): bool {
		return (bool) self::get()['enabled'];
	}

	private static function bool( mixed $v ): bool {
		return true === $v || 1 === $v || '1' === $v || 'on' === $v || 'true' === $v;
	}
}
