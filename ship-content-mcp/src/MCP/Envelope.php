<?php
/**
 * 戻り値の共通の形（spec/05）。
 *
 * - 成功：{ ok:true, data:{...}, request_id, warnings:[...], meta:{...} }
 * - 失敗：{ ok:false, error:{ code, message, admin_detail, retryable }, request_id }
 *   MCP では「エラーの印（isError）付きの文章」として返る（公式 Adapter は WP_Error の文だけを返すため、文に上の JSON を入れる）。
 * - 秘密・サーバーの詳細を入れない（admin_detail も、IP・鍵・パスワードを含めない短い理由だけ）。
 *
 * @package ShipContentMcp
 */

namespace ShipContentMcp\MCP;

use ShipContentMcp\Core\DomainError;
use ShipContentMcp\Security\RequestContext;

defined( 'ABSPATH' ) || exit;

final class Envelope {

	/**
	 * @param array<string,mixed> $data
	 * @param list<string>        $warnings
	 * @param array<string,mixed> $meta
	 * @return array<string,mixed>
	 */
	public static function ok( array $data, array $warnings = array(), array $meta = array() ): array {
		$out = array(
			'ok'         => true,
			'data'       => array() === $data ? new \stdClass() : $data,
			'request_id' => RequestContext::request_id(),
			'warnings'   => array_values( array_map( 'strval', $warnings ) ),
		);
		if ( array() !== $meta ) {
			$out['meta'] = $meta;
		}
		return $out;
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function error( DomainError $e ): array {
		return array(
			'ok'         => false,
			'error'      => array(
				'code'         => $e->error_code(),
				'message'      => $e->getMessage(),
				'admin_detail' => $e->admin_detail(),
				'retryable'    => $e->retryable(),
			),
			'request_id' => RequestContext::request_id(),
		);
	}

	public static function wp_error( DomainError $e ): \WP_Error {
		return new \WP_Error( 'ship_content_mcp_' . strtolower( str_replace( '-', '_', $e->error_code() ) ), (string) wp_json_encode( self::error( $e ), JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * 出力の Schema（成功の形）。
	 *
	 * @return array<string,mixed>
	 */
	public static function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'ok'         => array( 'type' => 'boolean' ),
				'data'       => array( 'type' => 'object' ),
				'request_id' => array( 'type' => 'string' ),
				'warnings'   => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'meta'       => array( 'type' => 'object' ),
			),
			'required'   => array( 'ok', 'data', 'request_id', 'warnings' ),
		);
	}
}
