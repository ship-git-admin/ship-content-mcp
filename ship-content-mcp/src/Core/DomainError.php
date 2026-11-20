<?php
/**
 * 業務の失敗（PM に見せる文と、管理者向けの補足を分ける）。
 *
 * コードは spec/07 の表（MCP-001 など）に合わせる。PM 向けの文に秘密・サーバーの詳細を入れない。
 *
 * @package ShipContentMcp
 */

namespace ShipContentMcp\Core;

defined( 'ABSPATH' ) || exit;

final class DomainError extends \RuntimeException {

	/** PM に表示する文（コードごとの決まった文）。 */
	public const MESSAGES = array(
		'MCP-001'      => '接続できません。サイト管理者へ確認してください',
		'MCP-002'      => '認証に失敗しました',
		'MCP-003'      => 'この投稿タイプは利用できません',
		'MCP-004'      => 'この投稿は下書きではありません',
		'ACF-000'      => 'このサイトでは入力欄（ACF）を使えません。サイト管理者へ確認してください',
		'ACF-001'      => '最新の入力構造が変わりました。再確認が必要です',
		'ACF-002'      => '保存データの形式に問題があります',
		'MEDIA-001'    => '写真の転送に失敗しました',
		'CONFLICT-001' => '他の編集があったため保存できません',
		'POLICY-001'   => '制作方針を現在の構造で適用できません',
		'AUTH-001'     => 'この操作は許可されていません',
		'INPUT-001'    => '指定の内容に問題があります',
		'POST-001'     => '指定の投稿が見つからないか、読み取りが許可されていません',
		'SYS-001'      => 'サーバーで問題が起きました。時間をおいて、もう一度お試しください',
	);

	private string $error_code;
	private string $admin_detail;
	private bool $retryable;

	public function __construct( string $code, string $admin_detail = '', bool $retryable = false, string $message = '' ) {
		$this->error_code   = $code;
		$this->admin_detail = $admin_detail;
		$this->retryable    = $retryable;
		parent::__construct( '' !== $message ? $message : ( self::MESSAGES[ $code ] ?? self::MESSAGES['SYS-001'] ) );
	}

	public function error_code(): string {
		return $this->error_code;
	}

	public function admin_detail(): string {
		return $this->admin_detail;
	}

	public function retryable(): bool {
		return $this->retryable;
	}
}
