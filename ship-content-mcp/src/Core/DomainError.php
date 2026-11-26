<?php
/**
 * 業務の失敗（指示者に見せる文と、管理者向けの補足を分ける）。
 *
 * コードは spec/07 の表（MCP-001 など）に合わせる。指示者向けの文に秘密・サーバーの詳細を入れない。
 *
 * @package ShipContentMcp
 */

namespace ShipContentMcp\Core;

defined( 'ABSPATH' ) || exit;

final class DomainError extends \RuntimeException {

	/** 指示者に表示する文（コードごとの決まった文）。 */
	public const MESSAGES = array(
		'MCP-001'      => '接続できません。サイト管理者へ確認してください',
		'MCP-002'      => '認証に失敗しました',
		'MCP-003'      => 'この投稿タイプは利用できません',
		'MCP-004'      => 'この投稿は下書きではありません',
		'ACF-000'      => 'このサイトでは入力欄（ACF）を使えません。サイト管理者へ確認してください',
		'ACF-001'      => '最新の入力構造が変わりました。再確認が必要です',
		'ACF-002'      => '保存データの形式に問題があります',
		'ACF-003'      => '入力欄の構造が、サイト管理者が確かめた後に変わりました。サイト管理者に確認を依頼してください',
		'MEDIA-001'    => '写真の転送に失敗しました',
		'MEDIA-002'    => 'この写真は受け付けられません（JPEG・PNG・WebP の画像で、大きさの上限以内のものだけ）',
		'MEDIA-003'    => '写真の URL から取り込めませんでした',
		'POLICY-002'   => '制作方針が変わったか、方針の版が付いていません。読み直してから送ってください',
		'TERM-001'     => 'このサイトでは、AI が分類の語を足すことは許可されていません',
		'TERM-002'     => '似た分類の語がすでにあります',
		'CONFLICT-001' => '他の編集があったため保存できません',
		'POLICY-001'   => '制作方針を現在の構造で適用できません',
		'AUTH-001'     => 'この操作は許可されていません',
		'INPUT-001'    => '指定の内容に問題があります',
		'POST-001'     => '指定の投稿が見つからないか、読み取りが許可されていません',
		'EDIT-001'     => '既存の内容を消す変更が含まれています。消してよい場合は allow_removals を true にしてください',
		'WRITE-001'    => 'この投稿タイプは、まだ書き込みが許可されていません（サイト管理者が状態を「試験中」以上にする必要があります）',
		'IDEM-001'     => '同じ idempotency_key で、違う内容が送られました。新しい idempotency_key を使ってください',
		'IDEM-002'     => '同じ依頼を処理しています。少し待ってから、同じ内容でもう一度送ってください',
		'SYS-001'      => 'サーバーで問題が起きました。時間をおいて、もう一度お試しください',
	);

	/**
	 * 誰が直すか（admin＝サイトの管理者・requester＝指示者・ai＝AI が自分で）と、次にすること。
	 * admin のときだけ、AI は指示者に「管理者に送る文」を作る（招待コード・トークン・パスワードは入れない）。
	 */
	public const FIX = array(
		'MCP-001'      => array( 'admin', 'サイトの管理者が「MCP を受け付ける」を有効にする必要があります。指示者に「管理者に送る文」を作ってください' ),
		'MCP-002'      => array( 'admin', '接続の期限切れか停止です。ブラウザで許可する接続なら一度ログインし直し、それでも同じなら管理者に新しい招待コード（またはトークン）を頼むよう指示者に伝えてください' ),
		'MCP-003'      => array( 'admin', 'この投稿タイプは管理者が許可していません。list-post-types で使えるものを確かめ、必要なら指示者から管理者に頼んでもらってください' ),
		'MCP-004'      => array( 'ai', '公開済みの記事は直せません。search-posts で下書きを探すか、新しい下書きを作ってください' ),
		'ACF-000'      => array( 'admin', 'サイトで ACF が使えません。指示者に「管理者に送る文」を作ってください' ),
		'ACF-001'      => array( 'ai', 'get-acf-schema で入力欄の構造を読み直してから、もう一度送ってください' ),
		'ACF-002'      => array( 'ai', 'error.details の欄を直して、もう一度送ってください' ),
		'ACF-003'      => array( 'admin', '管理者が入力欄の構造を確かめ直す必要があります。指示者に「管理者に送る文」を作ってください' ),
		'MEDIA-001'    => array( 'ai', '届いていない分割だけを送り直してください（prepare-upload の how_to_send のコマンド）。2回失敗したら、写真の URL があれば import-image-url、手元のファイルは get-upload-url に移ってください（list-post-types の meta.photos）' ),
		'MEDIA-002'    => array( 'requester', '写真の形式か大きさが合いません。JPEG・PNG・WebP に変えるか小さくするよう指示者に伝えてください（できるならあなたが変換してもよい）' ),
		'MEDIA-003'    => array( 'ai', '写真そのものの、誰でも開ける http・https の URL か確かめてください。ログインが要る URL や手元のファイルは get-upload-url（コマンドを実行できなければ prepare-upload から）で送ってください' ),
		'POLICY-002'   => array( 'ai', '今の制作方針を読み直し（list-post-types の data.creation_policy か get-creation-policy）、内容を方針に合わせてから、その version を policy_version に入れて送り直してください' ),
		'TERM-001'     => array( 'admin', '管理者が、AI に分類の語を足させない設定にしています。下書きはその語なしで保存し、足してほしい語（分類・親・名前）を書いた「管理者に送る文」を指示者に作ってください' ),
		'TERM-002'     => array( 'ai', 'error.details の similar_terms から合う語を使ってください。どれも違うときは、候補を指示者に見せて確かめてから、new_anyway を true にして送り直してください' ),
		'CONFLICT-001' => array( 'requester', '人が管理画面でこの記事を編集中か、読んだ後に変わりました。指示者に編集を閉じてもらってから、get-post で読み直して送ってください' ),
		'POLICY-001'   => array( 'admin', '制作方針がいまの入力欄に合いません。指示者に「管理者に送る文」を作ってください' ),
		'AUTH-001'     => array( 'admin', 'このユーザー・接続元では使えません。指示者に「管理者に送る文」を作ってください' ),
		'INPUT-001'    => array( 'ai', 'error.details を見て入力を直し、もう一度送ってください' ),
		'POST-001'     => array( 'ai', '投稿 ID と投稿タイプを確かめてください（search-posts で探せます）' ),
		'EDIT-001'     => array( 'requester', '既存の内容を消す変更です。消してよいか指示者に確かめ、よければ allow_removals を true にして送ってください' ),
		'WRITE-001'    => array( 'admin', 'この投稿タイプは、管理者がまだ書き込みを許可していません。指示者に「管理者に送る文」を作ってください' ),
		'IDEM-001'     => array( 'ai', '新しい idempotency_key を作って送ってください' ),
		'IDEM-002'     => array( 'ai', '少し待ってから、同じ内容でもう一度送ってください' ),
		'SYS-001'      => array( 'ai', '少し待ってからもう一度試してください。何度も続くときは、指示者に「管理者に送る文」を作ってください' ),
	);

	private string $error_code;
	private string $admin_detail;
	private bool $retryable;

	/** @var list<array<string,mixed>> どの欄の何が悪いか（指示者・AI に見せてよい内容だけ） */
	private array $details;

	/**
	 * @param list<array<string,mixed>> $details
	 */
	public function __construct( string $code, string $admin_detail = '', bool $retryable = false, string $message = '', array $details = array() ) {
		$this->error_code   = $code;
		$this->admin_detail = $admin_detail;
		$this->retryable    = $retryable;
		$this->details      = $details;
		parent::__construct( '' !== $message ? $message : ( self::MESSAGES[ $code ] ?? self::MESSAGES['SYS-001'] ) );
	}

	public function error_code(): string {
		return $this->error_code;
	}

	/** 誰が直すか：admin / pm / ai。 */
	public function who_fixes(): string {
		return self::FIX[ $this->error_code ][0] ?? 'ai';
	}

	public function next_step(): string {
		return self::FIX[ $this->error_code ][1] ?? self::FIX['SYS-001'][1];
	}

	public function admin_detail(): string {
		return $this->admin_detail;
	}

	public function retryable(): bool {
		return $this->retryable;
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function details(): array {
		return $this->details;
	}
}
