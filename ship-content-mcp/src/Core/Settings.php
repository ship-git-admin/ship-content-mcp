<?php
/**
 * 基本設定（管理者だけが変える）。
 *
 * - enabled：MCP を動かすか。初期は停止（入れただけでは何も公開しない）。
 * - disable_default_server：公式 Adapter の既定サーバーを止める（初期は止める）。
 * - post_types：使ってよい投稿タイプと状態（unverified=未検証 / testing=試験中 / tested=試験合格 / active=稼働）。初期は works だけ・未検証。
 * - reject_privileged：一般の編集権限などを持つユーザーを MCP で使わせない（初期は拒否）。
 * - ip_allowlist：接続元の IP/CIDR（空なら制限しない）。REMOTE_ADDR だけを見る。
 * - auth_signature / auth_app_password：使ってよい認証の方法。
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
			'auth_signature'         => true,
			'auth_app_password'      => true,
			'media_max_mb'           => 20,
			'strip_metadata'         => true,
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
		foreach ( array( 'enabled', 'disable_default_server', 'reject_privileged', 'auth_signature', 'auth_app_password', 'strip_metadata' ) as $k ) {
			$out[ $k ] = array_key_exists( $k, $in ) ? self::bool( $in[ $k ] ) : $d[ $k ];
		}
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
