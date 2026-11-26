<?php
/**
 * 名前の決まり（1か所にまとめる）。
 *
 * @package ShipContentMcp
 */

namespace ShipContentMcp\Core;

defined( 'ABSPATH' ) || exit;

final class Consts {

	/** Ability の名前空間・カテゴリ・REST の名前空間。 */
	public const NS = 'ship-content';

	/** MCP サーバーの ID。 */
	public const SERVER_ID = 'ship-content';

	/** REST のルート（名前空間の下）。 */
	public const ROUTE = 'mcp';

	/** REST の完全なルート。 */
	public const REST_ROUTE = '/ship-content/mcp';

	/** MCP を使ってよい人の権限。 */
	public const CAP = 'ship_content_mcp_access';

	/** 制作担当者用の低権限ロール。 */
	public const ROLE = 'ship_content_operator';

	public const ROLE_LABEL = 'Content MCP Operator';

	/** DB の版（表の作り直しが要るときに上げる）。 */
	public const DB_VERSION = 5;

	public const OPTION_DB_VERSION = 'ship_content_mcp_db_version';
}
