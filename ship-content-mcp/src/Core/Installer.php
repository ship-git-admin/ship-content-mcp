<?php
/**
 * 有効化・版上げ（何度実行しても同じ結果になるようにする）。
 *
 * - 表：端末の鍵・使い捨て番号（リプレイ防止）・操作の記録・二重送信の防止（idempotency）・写真の分割転送
 * - ロール：Content MCP Operator（read と専用の権限だけ）
 * - サイトの鍵（応答の署名用）
 * - 設定・制作方針は、すでにあれば変えない（更新で消さない）
 *
 * @package ShipContentMcp
 */

namespace ShipContentMcp\Core;

use ShipContentMcp\Security\SiteKey;

defined( 'ABSPATH' ) || exit;

final class Installer {

	public static function activate(): void {
		self::install();
	}

	public static function maybe_upgrade(): void {
		if ( (int) get_option( Consts::OPTION_DB_VERSION, 0 ) < Consts::DB_VERSION ) {
			self::install();
		}
	}

	public static function install(): void {
		self::create_tables();
		self::ensure_role();
		add_option( Settings::OPTION, Settings::defaults(), '', false );
		if ( function_exists( 'sodium_crypto_sign_keypair' ) ) {
			SiteKey::ensure();
		}
		update_option( Consts::OPTION_DB_VERSION, Consts::DB_VERSION, false );
	}

	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'ship_content_mcp_' . $name;
	}

	private static function create_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$keys    = self::table( 'keys' );
		$nonces  = self::table( 'nonces' );
		$log     = self::table( 'log' );
		$idem    = self::table( 'idem' );

		dbDelta(
			"CREATE TABLE {$keys} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  key_id varchar(40) NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  label varchar(100) NOT NULL DEFAULT '',
  public_key varchar(64) NOT NULL,
  created_at datetime NOT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  last_used_at datetime NULL DEFAULT NULL,
  revoked_at datetime NULL DEFAULT NULL,
  revoked_by bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY key_id (key_id),
  UNIQUE KEY public_key (public_key),
  KEY user_id (user_id)
) {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$nonces} (
  key_id varchar(40) NOT NULL,
  nonce varchar(80) NOT NULL,
  created_at int(10) unsigned NOT NULL,
  PRIMARY KEY  (key_id,nonce),
  KEY created_at (created_at)
) {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$log} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  request_id varchar(32) NOT NULL DEFAULT '',
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  auth varchar(20) NOT NULL DEFAULT '',
  key_id varchar(40) NOT NULL DEFAULT '',
  event varchar(40) NOT NULL DEFAULT '',
  tool varchar(100) NOT NULL DEFAULT '',
  post_type varchar(64) NOT NULL DEFAULT '',
  post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  result varchar(20) NOT NULL DEFAULT '',
  code varchar(40) NOT NULL DEFAULT '',
  detail varchar(255) NOT NULL DEFAULT '',
  ip varchar(45) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY user_id (user_id)
) {$charset};"
		);
		self::create_idem_table( $idem, $charset );
		self::create_upload_tables( $charset );
	}

	/**
	 * 写真の分割転送：転送のまとまり（セッション）と、届いた分割（base64 のまま。公開の場所には置かない）。
	 */
	private static function create_upload_tables( string $charset ): void {
		$uploads = self::table( 'uploads' );
		$chunks  = self::table( 'chunks' );
		dbDelta(
			"CREATE TABLE {$uploads} (
  session_id char(32) NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  post_type varchar(64) NOT NULL,
  filename varchar(200) NOT NULL,
  mime varchar(50) NOT NULL,
  size bigint(20) unsigned NOT NULL,
  sha256 char(64) NOT NULL,
  chunk_size int(10) unsigned NOT NULL,
  chunks int(10) unsigned NOT NULL,
  status varchar(10) NOT NULL,
  attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
  result longtext NULL,
  created_at int(10) unsigned NOT NULL,
  expires_at int(10) unsigned NOT NULL,
  PRIMARY KEY  (session_id),
  KEY user_id (user_id),
  KEY expires_at (expires_at)
) {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$chunks} (
  session_id char(32) NOT NULL,
  idx int(10) unsigned NOT NULL,
  sha256 char(64) NOT NULL,
  data longtext NOT NULL,
  created_at int(10) unsigned NOT NULL,
  PRIMARY KEY  (session_id,idx)
) {$charset};"
		);
	}

	private static function create_idem_table( string $idem, string $charset ): void {
		dbDelta(
			"CREATE TABLE {$idem} (
  user_id bigint(20) unsigned NOT NULL,
  idem_hash char(64) NOT NULL,
  op varchar(20) NOT NULL,
  payload_hash char(64) NOT NULL,
  status varchar(10) NOT NULL,
  post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  result longtext NULL,
  created_at int(10) unsigned NOT NULL,
  PRIMARY KEY  (user_id,idem_hash),
  KEY created_at (created_at)
) {$charset};"
		);
	}

	private static function ensure_role(): void {
		$role = get_role( Consts::ROLE );
		if ( ! $role ) {
			add_role(
				Consts::ROLE,
				Consts::ROLE_LABEL,
				array(
					'read'        => true,
					Consts::CAP => true,
				)
			);
			return;
		}
		// 既存のロールは、必要な2つの権限だけを確かめる（管理者が足したものは消さない。診断で警告する）。
		if ( ! $role->has_cap( 'read' ) ) {
			$role->add_cap( 'read' );
		}
		if ( ! $role->has_cap( Consts::CAP ) ) {
			$role->add_cap( Consts::CAP );
		}
	}
}
