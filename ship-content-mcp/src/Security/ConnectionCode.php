<?php
/**
 * 接続コード（管理者が発行し、PM が中継プログラムに読ませる1本の文字列）。
 *
 * - 端末の鍵（Ed25519）の組をサーバーで作り、公開鍵だけを登録する。秘密の種はコードに入れて1回だけ表示し、サーバーには残さない。
 * - コードには、接続に要る値（入口・鍵 ID・サイトの公開鍵・任意で Basic 認証）も入れる。PM は1本貼るだけでよい。
 * - 通信には種そのものは流れない（1回ごとの署名だけ）。HTTP の確認用 URL でも使える。
 * - 形：shipmcp1.<base64url(JSON)>。JSON のキーは docs/AUTH_SIGNATURE.md の「接続コード」。
 *
 * 引き換え：秘密がサーバーと管理者の手を通る（keygen の方式は、秘密が PM の端末から出ない）。コードは合鍵として DM で渡し、漏れたら止める。
 *
 * @package ShipContentMcp
 */

namespace ShipContentMcp\Security;

defined( 'ABSPATH' ) || exit;

final class ConnectionCode {

	public const PREFIX  = 'shipmcp1.';
	public const VERSION = 1;

	/**
	 * @param array<string,mixed> $fields
	 */
	public static function encode( array $fields ): string {
		$json = (string) json_encode( $fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return self::PREFIX . rtrim( strtr( base64_encode( $json ), '+/', '-_' ), '=' );
	}

	/**
	 * 文章の中から接続コードを探して読む（試験・確認用）。
	 *
	 * @return array<string,mixed>|null
	 */
	public static function decode( string $text ): ?array {
		if ( 1 !== preg_match( '/shipmcp1\.([A-Za-z0-9_-]+)/', $text, $m ) ) {
			return null;
		}
		$b64  = strtr( $m[1], '-_', '+/' );
		$b64 .= str_repeat( '=', ( 4 - strlen( $b64 ) % 4 ) % 4 );
		$json = base64_decode( $b64, true );
		if ( false === $json ) {
			return null;
		}
		$data = json_decode( $json, true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * 入口の URL から、短い英字の名前を作る（例：https://www.example.co.jp/… → example）。中継プログラムの設定・AI への登録の名前に使う。
	 */
	public static function default_name( string $url ): string {
		$host  = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$host  = (string) preg_replace( '/^www\./', '', $host );
		$parts = explode( '.', $host );
		$label = trim( (string) preg_replace( '/[^a-z0-9]+/', '-', $parts[0] ?? '' ), '-' );
		$label = substr( $label, 0, 40 );
		return '' === $label ? 'site' : $label;
	}

	/**
	 * 入口の URL を確かめる。管理者は、スキーム・ホスト・ポートだけ変えてよい（HTTPS の入口を渡すため）。パスとクエリは入口と同じであること。
	 *
	 * @return string 確かめた URL（違っていれば ''）
	 */
	public static function normalize_url( string $url, string $endpoint ): string {
		$url = trim( $url );
		$p   = wp_parse_url( $url );
		$e   = wp_parse_url( $endpoint );
		if ( ! is_array( $p ) || ! is_array( $e ) ) {
			return '';
		}
		if ( ! in_array( strtolower( (string) ( $p['scheme'] ?? '' ) ), array( 'http', 'https' ), true ) ) {
			return '';
		}
		if ( isset( $p['user'] ) || isset( $p['pass'] ) || isset( $p['fragment'] ) || '' === (string) ( $p['host'] ?? '' ) ) {
			return '';
		}
		if ( (string) ( $p['path'] ?? '' ) !== (string) ( $e['path'] ?? '' ) || (string) ( $p['query'] ?? '' ) !== (string) ( $e['query'] ?? '' ) ) {
			return '';
		}
		return $url;
	}

	/**
	 * トークンで直接つなぐための、AI ごとの設定（そのまま貼れる形）。WordPress に依存しない（試験からも使う）。
	 *
	 * @return array{prompt:string,claude_code:string,codex:string,json:string}
	 */
	public static function client_configs( string $name, string $url, string $token, string $basic_auth ): array {
		$headers = array( 'X-Ship-MCP-Token' => $token );
		if ( '' !== $basic_auth ) {
			$headers['Authorization'] = 'Basic ' . base64_encode( $basic_auth );
		}
		$q      = static fn( string $v ): string => "'" . str_replace( "'", "'\\''", $v ) . "'";
		$claude = 'claude mcp add --transport http --scope user ' . $q( $name ) . ' ' . $q( $url );
		$pairs  = array();
		foreach ( $headers as $k => $v ) {
			$claude .= ' --header ' . $q( $k . ': ' . $v );
			$pairs[] = json_encode( $k, JSON_UNESCAPED_SLASHES ) . ' = ' . json_encode( $v, JSON_UNESCAPED_SLASHES );
		}
		$codex = "cat >> ~/.codex/config.toml <<'EOF'\n\n[mcp_servers." . $name . "]\nurl = " . json_encode( $url, JSON_UNESCAPED_SLASHES ) . "\nhttp_headers = { " . implode( ', ', $pairs ) . " }\nEOF";
		$json  = (string) json_encode(
			array(
				'mcpServers' => array(
					$name => array(
						'type'    => 'http',
						'url'     => $url,
						'headers' => $headers,
					),
				),
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
		$lines = array();
		foreach ( $headers as $k => $v ) {
			$lines[] = '  ' . $k . ': ' . $v;
		}
		$prompt = "次の MCP サーバーを、あなた（このアプリ）の MCP の設定に登録してください。登録できたら、登録した内容（トークンは伏せて）と、使い始める方法を短く教えてください。\n\n"
			. '- 名前：' . $name . "\n"
			. "- 種類：HTTP（streamable HTTP）\n"
			. '- URL：' . $url . "\n"
			. "- 付けるヘッダー：\n" . implode( "\n", $lines ) . "\n\n"
			. "登録のしかたの例：\n"
			. "- Claude Code：claude mcp add --transport http --scope user <名前> <URL> --header \"<ヘッダー>\"（ヘッダーごとに --header。claude コマンドが見つからなければ、環境変数 CLAUDE_CODE_EXECPATH のものを使う）\n"
			. "- Codex：~/.codex/config.toml に [mcp_servers.<名前>] を足し、url と http_headers を書く\n"
			. "- そのほか：その AI の MCP の設定（mcpServers など）に、type http・url・headers で書く\n\n"
			. 'これは WordPress に記事の下書きを作るための接続です（公開・削除はできません）。X-Ship-MCP-Token は合鍵と同じなので、MCP の設定のほかには書き出さないでください。';
		return array(
			'prompt'      => $prompt,
			'claude_code' => $claude,
			'codex'       => $codex,
			'json'        => $json,
		);
	}

	public static function valid_basic_auth( string $v ): bool {
		return '' === $v || ( str_contains( $v, ':' ) && 1 !== preg_match( '/[\x00-\x1f]/', $v ) && strlen( $v ) <= 256 );
	}

	/**
	 * 発行する：鍵の組を作り、公開鍵を登録し、接続コードを返す（コードはここでしか手に入らない）。
	 *
	 * @return array{key_id:string, code:string, name:string, site:string, url:string}
	 * @throws \InvalidArgumentException 入力が違う・登録できないとき
	 */
	public static function issue( int $user_id, string $label, string $url, string $basic_auth, int $by ): array {
		if ( ! function_exists( 'sodium_crypto_sign_keypair' ) ) {
			throw new \InvalidArgumentException( 'このサーバーの PHP では鍵を作れません（sodium がありません）' );
		}
		$endpoint = \ShipContentMcp\MCP\Server::endpoint();
		$norm     = self::normalize_url( '' === trim( $url ) ? $endpoint : $url, $endpoint );
		if ( '' === $norm ) {
			throw new \InvalidArgumentException( '入口の URL が違います（http:// か https:// で始め、「' . (string) wp_parse_url( $endpoint, PHP_URL_PATH ) . '」の部分は変えないでください）' );
		}
		$basic_auth = trim( $basic_auth );
		if ( ! self::valid_basic_auth( $basic_auth ) ) {
			throw new \InvalidArgumentException( 'Basic 認証は「ID:パスワード」の形で入れてください' );
		}
		$server_key = SiteKey::public_b64();
		if ( '' === $server_key ) {
			throw new \InvalidArgumentException( 'サイトの鍵がありません（プラグインを一度無効にして有効にし直してください）' );
		}
		$pair   = sodium_crypto_sign_keypair();
		$secret = sodium_crypto_sign_secretkey( $pair );
		$public = sodium_crypto_sign_publickey( $pair );
		$seed   = substr( $secret, 0, 32 );
		$key_id = DeviceKeys::add( $user_id, '' === trim( $label ) ? '接続コード' : $label, base64_encode( $public ), $by );
		$site   = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$fields = array(
			'v'          => self::VERSION,
			'site'       => mb_substr( $site, 0, 100 ),
			'name'       => self::default_name( $norm ),
			'url'        => $norm,
			'key_id'     => $key_id,
			'seed'       => base64_encode( $seed ),
			'server_key' => $server_key,
		);
		if ( '' !== $basic_auth ) {
			$fields['basic_auth'] = $basic_auth;
		}
		$code = self::encode( $fields );
		sodium_memzero( $seed );
		sodium_memzero( $secret );
		sodium_memzero( $pair );
		return array(
			'key_id' => $key_id,
			'code'   => $code,
			'name'   => $fields['name'],
			'site'   => $fields['site'],
			'url'    => $norm,
		);
	}
}
