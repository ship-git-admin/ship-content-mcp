<?php
/**
 * OAuth 2.1（MCP の標準の認可）：PM は AI に入口の URL を教えるだけ。AI がブラウザを開き、PM はサイトの画面に招待コードを入れて「許可」を押す。
 *
 * - 招待コード：管理者が発行する（1回だけ・初期 7日）。サイトには SHA-256 だけを置く（鍵の表の public_key に inv: を付けて）。
 * - 許可すると、その行が「OAuth の接続」になる（public_key は oau:、期限は初期 3か月）。止める・最後に使った日時は鍵の表のまま使う。
 * - AI の登録（Dynamic Client Registration）、認可コード（PKCE S256 だけ。10分・1回だけ）、アクセストークン（接続の期限まで）、
 *   更新トークン（接続の期限まで。使うたびに入れ替える）。トークン類は oauth_tokens の表に SHA-256 だけを置く。
 *   アクセストークンを接続の期限までにしたのは、トークンを延長しない AI のアプリ（Codex など）でも、許可した期間はつながるようにするため（0.9.4）。
 *   使うたびに接続（鍵の表の行）を確かめるので、管理者が止めればその場で使えなくなる。
 * - 戻り先は、このパソコンの中（127.0.0.1・localhost・[::1]）と、アプリ専用の形（cursor:// など）だけ。
 *   https の戻り先（Claude の Web 版など、AI の会社のサーバーからつなぐもの）は、設定で許したときだけ。
 *
 * @package ShipContentMcp
 */

namespace ShipContentMcp\Security;

use ShipContentMcp\Core\Installer;
use ShipContentMcp\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class OAuth {

	public const INVITE_MARK    = 'inv:';
	public const GRANT_MARK     = 'oau:';
	public const ACCESS_PREFIX  = 'smcpo_';
	public const REFRESH_PREFIX = 'smcpr_';
	public const CODE_TTL       = 600;

	/** 招待コードの文字（見間違えやすい I・O・0・1 を使わない。32文字）。 */
	public const INVITE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
	public const INVITE_LENGTH   = 20;

	/** 戻り先に使えないアプリ専用の形（ブラウザの中で動くもの・危ないもの）。 */
	private const BAD_SCHEMES = array( 'http', 'https', 'javascript', 'data', 'file', 'vbscript', 'about', 'blob', 'ftp', 'ws', 'wss', 'mailto', 'tel', 'sms', 'filesystem', 'view-source', 'chrome', 'intent' );

	// ---- 招待コード ------------------------------------------------------------

	public static function new_invite_code(): string {
		$raw  = random_bytes( self::INVITE_LENGTH );
		$code = '';
		for ( $i = 0; $i < self::INVITE_LENGTH; $i++ ) {
			$code .= self::INVITE_ALPHABET[ ord( $raw[ $i ] ) & 31 ];
		}
		return implode( '-', str_split( $code, 4 ) );
	}

	/** 入力された招待コードを比べる形にする（形が違えば ''）。 */
	public static function normalize_invite( string $input ): string {
		$s = strtoupper( (string) preg_replace( '/[\s\-‐－ー_]+/u', '', $input ) );
		if ( strlen( $s ) !== self::INVITE_LENGTH || strspn( $s, self::INVITE_ALPHABET ) !== self::INVITE_LENGTH ) {
			return '';
		}
		return $s;
	}

	public static function invite_hash( string $normalized ): string {
		return self::INVITE_MARK . base64_encode( hash( 'sha256', $normalized, true ) );
	}

	/** 鍵の表の行の種類：invite（招待コード）・oauth（OAuth の接続）・token（トークン）・key（端末の鍵）。 */
	public static function kind( array $row ): string {
		$pk = (string) ( $row['public_key'] ?? '' );
		if ( str_starts_with( $pk, self::INVITE_MARK ) ) {
			return 'invite';
		}
		if ( str_starts_with( $pk, self::GRANT_MARK ) ) {
			return 'oauth';
		}
		return str_starts_with( $pk, DeviceKeys::TOKEN_MARK ) ? 'token' : 'key';
	}

	public static function expired( array $row, ?int $now = null ): bool {
		$exp = (string) ( $row['expires_at'] ?? '' );
		if ( '' === $exp ) {
			return false;
		}
		return strtotime( $exp . ' UTC' ) <= ( $now ?? time() );
	}

	/**
	 * 招待コードを発行する（コードはここでしか手に入らない）。
	 *
	 * @return array{key_id:string,code:string,expires_at:string}
	 * @throws \InvalidArgumentException 登録できないとき
	 */
	public static function issue_invite( int $user_id, string $label, int $by, ?int $days = null ): array {
		global $wpdb;
		$days    = max( 1, min( 90, $days ?? (int) Settings::get()['oauth_invite_days'] ) );
		$code    = self::new_invite_code();
		$key_id  = 'k' . bin2hex( random_bytes( 8 ) );
		$expires = gmdate( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS );
		$ok      = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Installer::table( 'keys' ),
			array(
				'key_id'     => $key_id,
				'user_id'    => $user_id,
				'label'      => mb_substr( sanitize_text_field( '' === trim( $label ) ? '招待コード' : $label ), 0, 100 ),
				'public_key' => self::invite_hash( self::normalize_invite( $code ) ),
				'created_at' => current_time( 'mysql', true ),
				'created_by' => $by,
				'expires_at' => $expires,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%d', '%s' )
		);
		if ( ! $ok ) {
			throw new \InvalidArgumentException( '招待コードを登録できませんでした（表が古い可能性があります。プラグインを一度無効にして有効にし直してください）' );
		}
		return array(
			'key_id'     => $key_id,
			'code'       => $code,
			'expires_at' => $expires,
		);
	}

	/**
	 * 招待コードを使って、OAuth の接続にする（1回だけ。同時に2回押されても1回しか通らない）。
	 *
	 * @param array<string,mixed> $client
	 * @return array{key_id:string,user_id:int,expires:int}|string 成功、または失敗の理由
	 */
	public static function consume_invite( string $input, array $client ) {
		global $wpdb;
		$norm = self::normalize_invite( $input );
		if ( '' === $norm ) {
			return 'bad_format';
		}
		$t    = Installer::table( 'keys' );
		$hash = self::invite_hash( $norm );
		$row  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE public_key = %s", $hash ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		if ( ! is_array( $row ) ) {
			return false !== get_transient( self::used_key( $hash ) ) ? 'used' : 'unknown';
		}
		if ( ! empty( $row['revoked_at'] ) ) {
			return 'revoked';
		}
		if ( self::expired( $row ) ) {
			return 'expired';
		}
		$user_id = (int) $row['user_id'];
		if ( $user_id <= 0 || ! get_userdata( $user_id ) || '' !== AccessGate::user_problem( $user_id ) ) {
			return 'user_not_allowed';
		}
		$days    = max( 1, min( 365, (int) Settings::get()['oauth_grant_days'] ) );
		$expires = time() + $days * DAY_IN_SECONDS;
		$filler  = self::GRANT_MARK . base64_encode( hash( 'sha256', random_bytes( 32 ), true ) );
		$done    = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"UPDATE {$t} SET public_key = %s, expires_at = %s, client = %s WHERE key_id = %s AND public_key = %s AND revoked_at IS NULL", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$filler,
				gmdate( 'Y-m-d H:i:s', $expires ),
				mb_substr( (string) ( $client['client_name'] ?? '' ), 0, 100 ),
				(string) $row['key_id'],
				$hash
			)
		);
		if ( 1 !== (int) $done ) {
			return 'used';
		}
		set_transient( self::used_key( $hash ), 1, 90 * DAY_IN_SECONDS ); // 「使用済み」と案内するためだけ（コードそのものは残さない）。
		$wpdb->update( Installer::table( 'oauth_clients' ), array( 'used_at' => time() ), array( 'client_id' => (string) $client['client_id'] ), array( '%d' ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return array(
			'key_id'  => (string) $row['key_id'],
			'user_id' => $user_id,
			'expires' => $expires,
		);
	}

	// ---- AI（クライアント）の登録 -------------------------------------------------

	/** 戻り先がこのパソコンの中（ループバック）か。 */
	public static function is_loopback( string $uri ): bool {
		$p = wp_parse_url( $uri );
		if ( ! is_array( $p ) || 'http' !== strtolower( (string) ( $p['scheme'] ?? '' ) ) || isset( $p['user'] ) || isset( $p['pass'] ) || isset( $p['fragment'] ) ) {
			return false;
		}
		return in_array( strtolower( (string) ( $p['host'] ?? '' ) ), array( '127.0.0.1', 'localhost', '[::1]', '::1' ), true );
	}

	/** 戻り先として受け付けるか。 */
	public static function valid_redirect( string $uri, bool $allow_remote ): bool {
		if ( '' === $uri || strlen( $uri ) > 500 || 1 === preg_match( '/[\s<>"\'\\\\]/', $uri ) ) {
			return false;
		}
		if ( self::is_loopback( $uri ) ) {
			return true;
		}
		if ( 1 !== preg_match( '#^([a-zA-Z][a-zA-Z0-9+.\-]*):#', $uri, $m ) || str_contains( $uri, '#' ) ) {
			return false;
		}
		$scheme = strtolower( $m[1] );
		if ( 'https' === $scheme ) {
			$p = wp_parse_url( $uri );
			return $allow_remote && is_array( $p ) && '' !== (string) ( $p['host'] ?? '' ) && ! isset( $p['user'] ) && ! isset( $p['pass'] );
		}
		return ! in_array( $scheme, self::BAD_SCHEMES, true );
	}

	/** 認可のときの戻り先が、登録した戻り先と合うか（ループバックはポートを問わない：RFC 8252 7.3）。 */
	public static function redirect_matches( string $given, array $registered ): bool {
		foreach ( $registered as $r ) {
			$r = (string) $r;
			if ( $given === $r ) {
				return true;
			}
			if ( self::is_loopback( $given ) && self::is_loopback( $r ) ) {
				$a = wp_parse_url( $given );
				$b = wp_parse_url( $r );
				if ( strtolower( (string) $a['host'] ) === strtolower( (string) $b['host'] ) && (string) ( $a['path'] ?? '' ) === (string) ( $b['path'] ?? '' ) && (string) ( $a['query'] ?? '' ) === (string) ( $b['query'] ?? '' ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * AI を登録する（RFC 7591）。
	 *
	 * @param array<string,mixed> $meta
	 * @return array<string,mixed>|array{error:string,error_description:string}
	 */
	public static function register_client( array $meta ): array {
		global $wpdb;
		$allow_remote = (bool) Settings::get()['oauth_remote_clients'];
		$uris         = $meta['redirect_uris'] ?? null;
		if ( ! is_array( $uris ) || array() === $uris || count( $uris ) > 10 ) {
			return self::err( 'invalid_redirect_uri', 'redirect_uris が要ります（10個まで）' );
		}
		$clean = array();
		foreach ( $uris as $u ) {
			if ( ! is_string( $u ) || ! self::valid_redirect( $u, $allow_remote ) ) {
				return self::err( 'invalid_redirect_uri', $allow_remote ? '使えない戻り先です' : 'このサイトでは、このパソコンの中（127.0.0.1・localhost）かアプリ専用の戻り先だけを使えます' );
			}
			$clean[] = $u;
		}
		$grant_types = isset( $meta['grant_types'] ) && is_array( $meta['grant_types'] ) ? array_values( array_map( 'strval', $meta['grant_types'] ) ) : array( 'authorization_code', 'refresh_token' );
		if ( ! in_array( 'authorization_code', $grant_types, true ) ) {
			return self::err( 'invalid_client_metadata', 'authorization_code だけを使えます' );
		}
		$name      = mb_substr( trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', (string) ( $meta['client_name'] ?? '' ) ) ), 0, 100 );
		$client_id = 'c' . bin2hex( random_bytes( 12 ) );
		$now       = time();
		$ok        = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Installer::table( 'oauth_clients' ),
			array(
				'client_id'     => $client_id,
				'client_name'   => '' === $name ? '（名前なし）' : $name,
				'redirect_uris' => (string) wp_json_encode( array_values( array_unique( $clean ) ) ),
				'created_at'    => $now,
				'ip'            => IpRules::remote_addr(),
				'used_at'       => 0,
			),
			array( '%s', '%s', '%s', '%d', '%s', '%d' )
		);
		if ( ! $ok ) {
			return self::err( 'server_error', '登録できませんでした' );
		}
		return array(
			'client_id'                  => $client_id,
			'client_id_issued_at'        => $now,
			'client_name'                => '' === $name ? '（名前なし）' : $name,
			'redirect_uris'              => array_values( array_unique( $clean ) ),
			'grant_types'                => array( 'authorization_code', 'refresh_token' ),
			'response_types'             => array( 'code' ),
			'token_endpoint_auth_method' => 'none',
		);
	}

	/**
	 * @return array{client_id:string,client_name:string,redirect_uris:list<string>}|null
	 */
	public static function find_client( string $client_id ): ?array {
		global $wpdb;
		if ( 1 !== preg_match( '/^c[0-9a-f]{24}$/', $client_id ) ) {
			return null;
		}
		$t   = Installer::table( 'oauth_clients' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE client_id = %s", $client_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		if ( ! is_array( $row ) ) {
			return null;
		}
		$uris = json_decode( (string) $row['redirect_uris'], true );
		return array(
			'client_id'     => (string) $row['client_id'],
			'client_name'   => (string) $row['client_name'],
			'redirect_uris' => is_array( $uris ) ? array_values( array_map( 'strval', $uris ) ) : array(),
		);
	}

	// ---- 認可コード・トークン ------------------------------------------------------

	public static function pkce_ok( string $verifier, string $challenge ): bool {
		if ( 1 !== preg_match( '/^[A-Za-z0-9\-._~]{43,128}$/', $verifier ) || '' === $challenge ) {
			return false;
		}
		$calc = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
		return hash_equals( $challenge, $calc );
	}

	/**
	 * 認可コードを作る（10分・1回だけ）。
	 *
	 * @param array<string,string> $req redirect_uri, code_challenge, resource
	 */
	public static function new_code( string $key_id, string $client_id, array $req ): string {
		$code = self::random_secret();
		self::store( $code, 'code', $key_id, $client_id, time() + self::CODE_TTL, $req );
		return $code;
	}

	/**
	 * 認可コードをトークンに引き換える。
	 *
	 * @return array<string,mixed>|array{error:string,error_description:string}
	 */
	public static function exchange_code( string $code, string $client_id, string $redirect_uri, string $verifier ): array {
		global $wpdb;
		$row = self::find_token( $code, 'code' );
		if ( null === $row || (string) $row['client_id'] !== $client_id ) {
			return self::err( 'invalid_grant', '認可コードが違います' );
		}
		if ( (int) $row['used_at'] > 0 ) {
			// 同じコードの2回目：盗まれた可能性があるので、この接続を止める（RFC 6749 4.1.2）。
			DeviceKeys::revoke( (string) $row['key_id'], 0 );
			AuditLog::write( 'oauth', 'denied', 'MCP-002', 'authorization code reused: grant revoked', array( 'key_id' => (string) $row['key_id'], 'auth' => 'oauth' ) );
			return self::err( 'invalid_grant', '認可コードは使用済みです' );
		}
		if ( (int) $row['expires_at'] <= time() ) {
			return self::err( 'invalid_grant', '認可コードの期限が切れました' );
		}
		$data = json_decode( (string) $row['data'], true );
		$data = is_array( $data ) ? $data : array();
		if ( (string) ( $data['redirect_uri'] ?? '' ) !== $redirect_uri ) {
			return self::err( 'invalid_grant', 'redirect_uri が認可のときと違います' );
		}
		if ( ! self::pkce_ok( $verifier, (string) ( $data['code_challenge'] ?? '' ) ) ) {
			return self::err( 'invalid_grant', 'code_verifier が合いません' );
		}
		$t    = Installer::table( 'oauth_tokens' );
		$done = $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET used_at = %d WHERE token_hash = %s AND used_at = 0", time(), (string) $row['token_hash'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		if ( 1 !== (int) $done ) {
			return self::err( 'invalid_grant', '認可コードは使用済みです' );
		}
		return self::issue_tokens( (string) $row['key_id'], $client_id );
	}

	/**
	 * 更新トークンで、新しいアクセストークンと更新トークンを出す（古い更新トークンは使えなくなる）。
	 *
	 * @return array<string,mixed>|array{error:string,error_description:string}
	 */
	public static function refresh( string $refresh_token, string $client_id ): array {
		global $wpdb;
		$row = self::find_token( $refresh_token, 'refresh' );
		if ( null === $row || (string) $row['client_id'] !== $client_id || (int) $row['used_at'] > 0 || (int) $row['expires_at'] <= time() ) {
			return self::err( 'invalid_grant', '更新トークンが使えません（期限切れ・使用済み・別の AI のもの）' );
		}
		$t    = Installer::table( 'oauth_tokens' );
		$done = $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET used_at = %d WHERE token_hash = %s AND used_at = 0", time(), (string) $row['token_hash'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		if ( 1 !== (int) $done ) {
			return self::err( 'invalid_grant', '更新トークンは使用済みです' );
		}
		return self::issue_tokens( (string) $row['key_id'], $client_id );
	}

	/**
	 * @return array<string,mixed>|array{error:string,error_description:string}
	 */
	private static function issue_tokens( string $key_id, string $client_id ): array {
		$grant = DeviceKeys::find( $key_id );
		$why   = self::grant_problem( $grant );
		if ( '' !== $why ) {
			return self::err( 'invalid_grant', 'この接続は使えません（' . $why . '）。管理者から新しい招待コードをもらってください' );
		}
		$grant_exp = (int) strtotime( (string) $grant['expires_at'] . ' UTC' );
		$access    = self::ACCESS_PREFIX . self::random_secret();
		$refresh   = self::REFRESH_PREFIX . self::random_secret();
		$access_to = $grant_exp; // 接続の期限まで（延長しない AI のため。止めればその場で使えなくなる）。
		self::store( $access, 'access', $key_id, $client_id, $access_to );
		self::store( $refresh, 'refresh', $key_id, $client_id, $grant_exp );
		return array(
			'access_token'  => $access,
			'token_type'    => 'Bearer',
			'expires_in'    => max( 1, $access_to - time() ),
			'refresh_token' => $refresh,
		);
	}

	/**
	 * アクセストークンを確かめる（MCP の入口で使う）。
	 *
	 * @return array{user_id:int,key_id:string}|string 成功、または失敗の理由
	 */
	public static function verify_access( string $token ) {
		if ( 1 !== preg_match( '/^smcpo_[A-Za-z0-9_-]{43}$/', $token ) ) {
			return 'bad_format';
		}
		$row = self::find_token( $token, 'access' );
		if ( null === $row ) {
			return 'unknown_token';
		}
		if ( (int) $row['used_at'] > 0 ) {
			return 'revoked';
		}
		if ( (int) $row['expires_at'] <= time() ) {
			return 'expired';
		}
		$grant = DeviceKeys::find( (string) $row['key_id'] );
		$why   = self::grant_problem( $grant );
		if ( '' !== $why ) {
			return $why;
		}
		$user_id = (int) $grant['user_id'];
		if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
			return 'user_missing';
		}
		DeviceKeys::touch( (string) $row['key_id'] );
		return array(
			'user_id' => $user_id,
			'key_id'  => (string) $row['key_id'],
		);
	}

	/** トークンを取り消す（RFC 7009。知らないトークンでも成功として扱う）。 */
	public static function revoke_token( string $token ): void {
		global $wpdb;
		$t = Installer::table( 'oauth_tokens' );
		$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET used_at = %d WHERE token_hash = %s AND kind IN ('access','refresh') AND used_at = 0", time(), hash( 'sha256', $token ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	/** 接続（鍵の表の行）が使えない理由（使えるなら ''）。 */
	public static function grant_problem( ?array $grant ): string {
		if ( null === $grant || 'oauth' !== self::kind( $grant ) ) {
			return 'unknown_grant';
		}
		if ( ! empty( $grant['revoked_at'] ) ) {
			return 'revoked';
		}
		if ( self::expired( $grant ) ) {
			return 'grant_expired';
		}
		return '';
	}

	/** 期限の切れたトークン・使われなかった AI の登録を消す（ときどき呼ぶ）。 */
	public static function cleanup(): void {
		global $wpdb;
		$now = time();
		$t   = Installer::table( 'oauth_tokens' );
		$c   = Installer::table( 'oauth_clients' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE expires_at < %d LIMIT 500", $now - DAY_IN_SECONDS ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$c} WHERE used_at = 0 AND created_at < %d LIMIT 500", $now - DAY_IN_SECONDS ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	// ---- PM に渡す文 ------------------------------------------------------------

	/** AI に貼る文（秘密は入れない。招待コードは PM がブラウザの画面に入れる）。 */
	public static function pm_prompt( string $name, string $url ): string {
		return implode(
			"\n",
			array_merge(
				array(
				'次の MCP サーバーを、あなた（このアプリ）の MCP の設定に登録して、つながるまで案内してください。',
				'- 名前：' . $name,
				'- 種類：HTTP（streamable HTTP）',
				'- URL：' . $url,
				'- 認証：OAuth（ヘッダーは付けない）。登録したら、ログイン（認証）を始めてください。ブラウザに「接続の許可」の画面が出たら、私が招待コードを入れて許可します。招待コードは私が持っているので、聞かないでください（会話に貼らないため）。',
				'',
				'登録のしかたの例：',
				'- Claude Code：claude mcp add --transport http --scope user ' . $name . ' ' . $url . ' で登録する（claude が PATH にないときは、環境変数 CLAUDE_CODE_EXECPATH のパスを使う）。ログイン（claude mcp login）は対話できる端末が要り、あなた（AI）からは始められないので、私に「Claude Code を開き直して /mcp と打ち、' . $name . ' を選んで認証（Authenticate）を押す。/mcp が使えないときは、ターミナルで claude mcp login ' . $name . ' を実行する」と案内する',
				'- Codex：codex mcp add ' . $name . ' --url ' . $url . ' で登録する（登録するとログインが始まり、ブラウザが開く。始まらなければ codex mcp login ' . $name . '）',
				'- ほかのアプリ：そのアプリの「MCP サーバー（リモート・HTTP）を追加」の手順で URL を登録し、認証（OAuth）を始める',
				'',
				'つながったら、使える投稿タイプを見せてください（新しい会話にしないと使えないアプリなら、そう教えてください）。',
				),
				self::troubleshooting( $url, true )
			)
		);
	}

	/**
	 * つながらないときに、AI が原因を見分けて、管理者に送るか PM が直すかを案内するための文（秘密は入れない）。
	 *
	 * @param bool $oauth true：ブラウザで許可する接続、false：トークン
	 * @return list<string>
	 */
	public static function troubleshooting( string $url, bool $oauth ): array {
		$p      = wp_parse_url( $url );
		$origin = ( is_array( $p ) ? (string) ( $p['scheme'] ?? 'https' ) . '://' . (string) ( $p['host'] ?? '' ) . ( isset( $p['port'] ) ? ':' . $p['port'] : '' ) : '' );
		$lines  = array( '', 'うまくつながらないとき（登録・ログイン・最初の確認のどこかで止まったとき）は、次の順で原因を見分けてください。' );
		if ( $oauth ) {
			$lines[] = '1. あなたがコマンド（curl など）で ' . $origin . '/.well-known/oauth-protected-resource を開き、HTTP の状態を見る。';
			$lines[] = '   - 200：サイトの準備はできている（2. へ）';
			$lines[] = '   - 401 で WWW-Authenticate が Basic：管理者の設定（サーバーの Basic 認証に、AI が呼ぶ道の例外がない）';
			$lines[] = '   - 404：管理者の設定（MCP か「ブラウザで許可してつなぐ」が止まっている、またはプラグインが入っていない）';
			$lines[] = '   - つながらない（名前が引けない・時間切れ）：まず PM のネットワークと URL の写し間違い。ほかのサイトは開けるのに、ここだけ開けないなら管理者';
			$lines[] = '2. ブラウザの「接続の許可」の画面に出た言葉で見分ける（PM に聞く）。';
			$lines[] = '   - 「招待コードが違います」「間違いが続いた」：PM（届いたコードをそのまま貼り直す。続けて間違えたら15分待つ）';
			$lines[] = '   - 「使用済み」「期限が切れています」「止められています」「このユーザーは、いま MCP を使えません」「ブラウザで許可する接続を使っていません」：管理者（新しい招待コード・設定）';
			$lines[] = '   - 「AI の登録が見つかりません」「戻り先が登録と違います」：あなた（ログインをやり直す）';
			$lines[] = '   - ブラウザで ID とパスワードを聞かれて通れない：PM（サイトの確認用の ID とパスワード。分からなければ管理者）';
			$lines[] = '3. 許可できたのにツールが見えない：PM（新しい会話にする・アプリを開き直す）。それでも Needs authentication のままなら 1. からやり直す。';
			$lines[] = '4. 前は使えていたのに、ツールが見えなくなった・「認証が必要（Needs authentication・401・MCP-002）」で断られるようになった：接続が切れている（許可の期限切れ・管理者が止めた・アプリがトークンを延長できなかった）。PM にログインのやり直しを案内する（Claude Code は /mcp → 登録した名前 → 認証。Codex は codex mcp login 登録した名前）。許可の画面では新しい招待コードが要るので、PM が持っていなければ「管理者に送る文」で新しい招待コードを頼む。';
		} else {
			$lines[] = '1. あなたがコマンド（curl など）で、上の URL に上のヘッダーを付けて POST し（本文は {"jsonrpc":"2.0","id":1,"method":"ping"}、Content-Type と Accept は application/json）、HTTP の状態と返事を見る。ヘッダーの値は画面に出さない。';
			$lines[] = '   - 200：サイトの準備はできている（2. へ）';
			$lines[] = '   - 401 で WWW-Authenticate が Basic：管理者（Basic 認証の値が違う、または入っていない）';
			$lines[] = '   - 401 で MCP-002：管理者（トークンが止められた・違う。PM が文を書き換えていないなら、新しいトークンが要る）';
			$lines[] = '   - 404 か 503（MCP-001）：管理者の設定（MCP が止まっている、プラグインがない、URL が違う）';
			$lines[] = '   - つながらない（名前が引けない・時間切れ）：まず PM のネットワークと URL の写し間違い。ほかのサイトは開けるのに、ここだけ開けないなら管理者';
			$lines[] = '2. 状態は 200 なのにツールが見えない：PM（新しい会話にする・アプリを開き直す）。';
			$lines[] = '3. 前は使えていたのに、ツールが見えなくなった・401 で断られるようになった：1. で確かめる。401 で MCP-002 なら、トークンが止められている（管理者。「管理者に送る文」で新しいトークンを頼む）。';
		}
		$lines[] = ( $oauth ? '5' : '4' ) . '. ツールの返事が ok:false のときは、error.who_fixes（admin＝管理者・pm＝PM・ai＝あなた）と error.next_step に従う。';
		$lines[] = '';
		$lines[] = 'ツールが使えないあいだも、WordPress の管理画面・サイトへのログイン・ブラウザの操作で代わりをしないでください（写真のアップロードも同じです）。下書きと写真は、この MCP サーバーのツールだけで扱います（写真は get-upload-url、コマンドを実行できなければ prepare-upload → upload-chunk → finish-upload。ツールが最初は見えないアプリでは、ツールの一覧を ship-content や upload で探してください）。PM に WordPress の ID・パスワードを聞く必要もありません。つながりを直してから続けてください。';
		$lines[] = '原因が管理者のときだけ、PM に「管理者に送る文」を作って見せ、それをコピーして管理者に送るよう伝えてください。文には、サイトの入口の URL・いつ・何をしたか・出た言葉やコード・確かめた結果（上の状態など）・request_id（あれば）・使っている AI の名前を入れます。招待コード・トークン・パスワードは入れないでください。';
		$lines[] = '原因が PM の側（パソコン・アプリ・入力の写し間違いなど）のときは、管理者には送らせず、何をどう直すかを短く伝えてください。どちらか分からないときは決めつけず、確かめた結果を PM に見せて、もう一度一緒に確かめてください。';
		return $lines;
	}

	// ---- 内部 -----------------------------------------------------------------

	private static function used_key( string $invite_hash ): string {
		return 'ship_content_mcp_inv_used_' . substr( hash( 'sha256', $invite_hash ), 0, 32 );
	}

	private static function random_secret(): string {
		return rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
	}

	/**
	 * @param array<string,string> $data
	 */
	private static function store( string $raw, string $kind, string $key_id, string $client_id, int $expires, array $data = array() ): void {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Installer::table( 'oauth_tokens' ),
			array(
				'token_hash' => hash( 'sha256', $raw ),
				'kind'       => $kind,
				'key_id'     => $key_id,
				'client_id'  => $client_id,
				'data'       => array() === $data ? null : (string) wp_json_encode( $data ),
				'created_at' => time(),
				'expires_at' => $expires,
				'used_at'    => 0,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d' )
		);
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private static function find_token( string $raw, string $kind ): ?array {
		global $wpdb;
		if ( '' === $raw || strlen( $raw ) > 200 ) {
			return null;
		}
		$t   = Installer::table( 'oauth_tokens' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE token_hash = %s AND kind = %s", hash( 'sha256', $raw ), $kind ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return is_array( $row ) ? $row : null;
	}

	/**
	 * @return array{error:string,error_description:string}
	 */
	private static function err( string $code, string $desc ): array {
		return array(
			'error'             => $code,
			'error_description' => $desc,
		);
	}
}
