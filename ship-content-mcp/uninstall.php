<?php
/**
 * プラグインを削除したときの後片付け（公開時の片付けで使う）。
 *
 * 消すもの：このプラグインの表（トークン・鍵・使い捨て番号・記録・二重送信の防止・写真の転送）、設定・制作方針・サイトの鍵などの
 * オプション、アップロード用 URL の札（transient）、ロール「Content MCP Operator」。
 * 残すもの：作った下書き・写真（コンテンツなので）、MCP 用のユーザー（下書きの作成者のため。ロールはなくなるので何もできない）。
 *
 * @package ShipContentMcp
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

foreach ( array( 'keys', 'nonces', 'log', 'idem', 'uploads', 'chunks' ) as $ship_content_mcp_table ) {
	$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $wpdb->prefix . 'ship_content_mcp_' . $ship_content_mcp_table ) . '`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange
}

foreach ( array( 'ship_content_mcp_', '_transient_ship_content_mcp_', '_transient_timeout_ship_content_mcp_', 'external_updates-ship-content-mcp' ) as $ship_content_mcp_prefix ) {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $ship_content_mcp_prefix ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

remove_role( 'ship_content_operator' );
wp_cache_flush();
