#!/usr/bin/env node
// Ship Content MCP 中継プログラム（PM の端末で動く。Node.js 18 以上。外部のパッケージは使わない）
//
// AI エージェントからは、ふつうの MCP サーバー（標準入出力）に見える。
// 受け取った MCP のメッセージに端末の鍵で署名して WordPress へ送り、返事の署名（サイトの鍵）を確かめてから AI に渡す。
// パスワードを通信に乗せないので、HTTP の動作確認URLや、サーバーの Basic 認証のあるサイトでも使える。
//
// 使い方：
//   node ship-content-mcp-bridge.mjs setup --clipboard [--register]   … 管理者が発行した接続コードで設定する（おすすめ）
//        接続コードはクリップボード（--clipboard）・ファイル（--code-file <ファイル>）・標準入力から読む。
//        設定を ~/.ship-content-mcp/<名前>.json（600）に保存し、つながるか確かめる。
//        --register を付けると、Claude Code に「ship-content-<名前>」として登録する（--scope user。--scope で変えられる）。
//        --print-config を付けると、ほかの AI（Cursor・Gemini CLI など、mcpServers の形の設定を使うもの）に貼る設定を表示する。
//   Claude デスクトップアプリ（チャット）では、拡張機能 ship-content-mcp.mcpb を入れて、接続コードを貼るだけでよい（Node.js 不要）。
//   node ship-content-mcp-bridge.mjs keygen --out <秘密鍵のファイル>   … 鍵を作る（公開鍵を表示。秘密が端末から出ない方式）
//   node ship-content-mcp-bridge.mjs pubkey --key <秘密鍵のファイル>   … 公開鍵をもう一度表示
//   node ship-content-mcp-bridge.mjs check                             … つながるか試す（ツールの一覧を表示）
//   node ship-content-mcp-bridge.mjs                                   … 中継（AI エージェントの設定から起動する）
//
// 設定（環境変数）：
//   SHIP_MCP_TOKEN        管理画面で発行したトークン（smcp_…）。SHIP_MCP_URL と組みで使う（署名の鍵は要らない。Claude デスクトップの拡張機能が使う）
//   SHIP_MCP_CODE         接続コードそのもの（Claude デスクトップの拡張機能が、設定の欄から渡す。これ1つで足りる）
//   SHIP_MCP_CONFIG       setup が作った設定のファイル（これ1つで足りる。下の環境変数があれば、そちらを優先）
//   SHIP_MCP_URL          MCP の入口（管理画面の「端末の鍵」に表示）
//   SHIP_MCP_KEY_ID       端末の鍵 ID（管理画面で登録したときに表示）
//   SHIP_MCP_KEY_FILE     秘密鍵のファイル（keygen で作ったもの）
//   SHIP_MCP_SERVER_KEY   サイトの公開鍵（管理画面に表示）
//   SHIP_MCP_BASIC_AUTH   サーバーの Basic 認証（ある場合だけ。ID:パスワード）
//   SHIP_MCP_TIMEOUT_MS   1回の通信の待ち時間（既定 60000）
//   SHIP_MCP_UPLOAD_DIRS  写真を送ってよいフォルダ（区切りは : 。既定はホームフォルダ。隠しフォルダの中は不可）
//
// 写真：AI エージェントには、ローカルのツール ship-content-upload-local-image を見せる。AI はファイルのパスを渡すだけで、
// 読み込み・分割・送信（prepare-upload → upload-chunk × N → finish-upload）はこのプログラムが行う。
//
// 署名の決まりは docs/AUTH_SIGNATURE.md（WordPress 側の SignatureCodec と同じ）。

import { execFileSync } from 'node:child_process';
import { createHash, createPrivateKey, createPublicKey, generateKeyPairSync, randomBytes, sign, verify } from 'node:crypto';
import { chmodSync, closeSync, existsSync, mkdirSync, openSync, readFileSync, renameSync, statSync, writeFileSync, writeSync } from 'node:fs';
import { delimiter, dirname, isAbsolute, join, relative, resolve, basename, extname } from 'node:path';
import { homedir } from 'node:os';
import { realpathSync } from 'node:fs';
import { createInterface } from 'node:readline';
import { fileURLToPath } from 'node:url';

export const VERSION = '0.9.14';
export const REQ_PREFIX = 'ship-content-mcp-req-v1';
export const RES_PREFIX = 'ship-content-mcp-res-v1';
export const CODE_PREFIX = 'shipmcp1.';
const SPKI_ED25519_PREFIX = Buffer.from('302a300506032b6570032100', 'hex');
const PKCS8_ED25519_PREFIX = Buffer.from('302e020100300506032b657004220420', 'hex');
const PROTOCOL_VERSION = '2025-11-25';

// ---- 署名の部品（試験からも使う） ------------------------------------------

export function sha256hex(buf) {
  return createHash('sha256').update(buf).digest('hex');
}

export function canonicalRequest({ method, host, uri, timestamp, nonce, keyId, sessionId, body }) {
  return [REQ_PREFIX, method.toUpperCase(), host.toLowerCase(), uri, timestamp, nonce, keyId, sessionId || '', sha256hex(body)].join('\n');
}

export function canonicalResponse({ nonce, status, sessionId, body }) {
  return [RES_PREFIX, nonce, String(status), sessionId || '', sha256hex(body)].join('\n');
}

export function rawPublicKey(keyObject) {
  const der = keyObject.export({ type: 'spki', format: 'der' });
  return der.subarray(der.length - 32);
}

export function publicKeyFromRaw(raw) {
  if (raw.length !== 32) throw new Error('公開鍵の長さが違います（32バイトの base64 を指定してください）');
  return createPublicKey({ key: Buffer.concat([SPKI_ED25519_PREFIX, raw]), format: 'der', type: 'spki' });
}

export function fingerprint(raw) {
  return sha256hex(raw).slice(0, 16);
}

export function privateKeyFromSeed(seed) {
  if (seed.length !== 32) throw new Error('鍵の種の長さが違います（32バイト）');
  return createPrivateKey({ key: Buffer.concat([PKCS8_ED25519_PREFIX, seed]), format: 'der', type: 'pkcs8' });
}

// ---- 接続コード（管理者が発行。docs/AUTH_SIGNATURE.md） ------------------------

export function nameSlug(v) {
  return String(v || '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 40) || 'site';
}

// 文章の中から接続コードを探して読む（Slack の文ごとコピーしても読める）。値はすべて確かめてから返す。
export function decodeConnectionCode(text) {
  const m = /shipmcp1\.([A-Za-z0-9_-]+)/.exec(String(text || ''));
  if (!m) throw new Error('接続コードが見つかりません（管理者から届いた「shipmcp1.」で始まる文字列をコピーしてから、もう一度実行してください）');
  let data;
  try {
    data = JSON.parse(Buffer.from(m[1], 'base64url').toString('utf8'));
  } catch {
    throw new Error('接続コードが壊れています（途中で切れていないか確かめて、もう一度コピーしてください）');
  }
  if (!data || typeof data !== 'object' || data.v !== 1) throw new Error('この中継プログラムでは読めない接続コードです（中継プログラムを最新にしてください）');
  let url;
  try { url = new URL(String(data.url || '')); } catch { throw new Error('接続コードの入口の URL が違います（管理者に発行し直してもらってください）'); }
  if ((url.protocol !== 'https:' && url.protocol !== 'http:') || url.username || url.password) throw new Error('接続コードの入口の URL が違います（管理者に発行し直してもらってください）');
  if (!/^k[0-9a-f]{16}$/.test(String(data.key_id || ''))) throw new Error('接続コードの鍵 ID が違います（管理者に発行し直してもらってください）');
  const seed = Buffer.from(String(data.seed || ''), 'base64');
  if (seed.length !== 32) throw new Error('接続コードの鍵が壊れています（管理者に発行し直してもらってください）');
  publicKeyFromRaw(Buffer.from(String(data.server_key || ''), 'base64'));
  if (data.basic_auth !== undefined && (typeof data.basic_auth !== 'string' || !data.basic_auth.includes(':'))) throw new Error('接続コードの Basic 認証の形が違います（管理者に発行し直してもらってください）');
  return {
    site: String(data.site || ''),
    name: nameSlug(data.name || url.hostname.replace(/^www\./, '').split('.')[0]),
    url: String(data.url),
    keyId: String(data.key_id),
    seed,
    serverKey: String(data.server_key),
    basicAuth: data.basic_auth || '',
  };
}

export function signRequest(privateKey, fields) {
  return sign(null, Buffer.from(canonicalRequest(fields), 'utf8'), privateKey).toString('base64');
}

export function verifyResponse(serverKey, fields, signatureB64) {
  if (!signatureB64) return false;
  const sig = Buffer.from(signatureB64, 'base64');
  if (sig.length !== 64) return false;
  try {
    return verify(null, Buffer.from(canonicalResponse(fields), 'utf8'), serverKey, sig);
  } catch {
    return false;
  }
}

// ---- 設定 ------------------------------------------------------------------

function log(msg) {
  process.stderr.write(`[ship-content-mcp-bridge] ${msg}\n`);
}

function warnIfOpen(file, what) {
  const st = statSync(file);
  if (process.platform !== 'win32' && (st.mode & 0o077) !== 0) {
    log(`注意：${what}が、ほかのユーザーからも読めます（chmod 600 ${file} を勧めます）`);
  }
}

// setup が作った設定のファイル（SHIP_MCP_CONFIG）を読む。
export function readConfigFile(file) {
  warnIfOpen(file, '設定のファイル');
  let data;
  try { data = JSON.parse(readFileSync(file, 'utf8')); } catch { throw new Error(`設定のファイルを読めません：${file}（setup をやり直してください）`); }
  if (!data || typeof data !== 'object') throw new Error(`設定のファイルの形が違います：${file}`);
  return data;
}

// 空の値と、置き換えられずに残った「${…}」（拡張機能の設定の欄が空のとき）は、設定なしとみなす。
function envValue(env, k) {
  const v = env[k];
  return typeof v === 'string' && v.trim() !== '' && !v.trim().startsWith('${') ? v : '';
}

export function loadConfig(env = process.env) {
  const configPath = envValue(env, 'SHIP_MCP_CONFIG');
  const file = configPath ? readConfigFile(resolve(expandHome(configPath))) : {};
  const codeText = envValue(env, 'SHIP_MCP_CODE');
  const code = codeText ? decodeConnectionCode(codeText) : null;
  const v = {
    url: envValue(env, 'SHIP_MCP_URL') || code?.url || file.url || '',
    keyId: envValue(env, 'SHIP_MCP_KEY_ID') || code?.keyId || file.key_id || '',
    serverKey: envValue(env, 'SHIP_MCP_SERVER_KEY') || code?.serverKey || file.server_key || '',
    basic: envValue(env, 'SHIP_MCP_BASIC_AUTH') || code?.basicAuth || file.basic_auth || '',
    uploadDirs: envValue(env, 'SHIP_MCP_UPLOAD_DIRS') || (Array.isArray(file.upload_dirs) ? file.upload_dirs.join(delimiter) : ''),
  };
  const keyFilePath = envValue(env, 'SHIP_MCP_KEY_FILE');
  // トークン（管理画面で発行したもの）を使うときは、署名の鍵は要らない。
  const tokenText = envValue(env, 'SHIP_MCP_TOKEN').trim();
  const tokenMatch = tokenText ? /^smcp_(k[0-9a-f]{16})_[A-Za-z0-9_-]{43}$/.exec(tokenText) : null;
  if (tokenText && !tokenMatch) throw new Error('トークンの形が違います（管理画面で発行した「smcp_」で始まる文字列を、そのまま入れてください）');
  const missing = [];
  if (!v.url) missing.push('SHIP_MCP_URL');
  if (!tokenMatch) {
    if (!v.keyId) missing.push('SHIP_MCP_KEY_ID');
    if (!keyFilePath && !code && !file.private_key) missing.push('SHIP_MCP_KEY_FILE');
    if (!v.serverKey) missing.push('SHIP_MCP_SERVER_KEY');
  }
  if (missing.length) throw new Error(`設定が足りません：${missing.join(', ')}（接続コードを使うときは、拡張機能の「接続コード」の欄か SHIP_MCP_CODE・SHIP_MCP_CONFIG を確かめてください）`);
  const url = new URL(v.url);
  if (url.protocol !== 'https:' && url.protocol !== 'http:') throw new Error('SHIP_MCP_URL は http:// か https:// で始めてください');
  if (url.username || url.password) throw new Error('SHIP_MCP_URL に ID・パスワードを入れないでください（SHIP_MCP_BASIC_AUTH を使う）');
  let basic = '';
  if (v.basic) {
    if (!v.basic.includes(':')) throw new Error('SHIP_MCP_BASIC_AUTH は「ID:パスワード」の形にしてください');
    basic = 'Basic ' + Buffer.from(v.basic, 'utf8').toString('base64');
  }
  const timeoutMs = Math.max(5000, Math.min(600000, Number(envValue(env, 'SHIP_MCP_TIMEOUT_MS') || 60000) || 60000));
  const uploadRoots = (v.uploadDirs ? v.uploadDirs.split(delimiter) : [homedir()])
    .filter(Boolean)
    .map((d) => { try { return realpathSync(resolve(expandHome(d))); } catch { return null; } })
    .filter(Boolean);
  if (tokenMatch) {
    return { url, keyId: tokenMatch[1], token: tokenText, privateKey: null, serverKey: null, basic, timeoutMs, uploadRoots };
  }
  if (!/^k[0-9a-f]{16}$/.test(v.keyId)) throw new Error('SHIP_MCP_KEY_ID の形が違います');
  let privateKey;
  if (keyFilePath) {
    const keyFile = resolve(expandHome(keyFilePath));
    warnIfOpen(keyFile, '秘密鍵のファイル');
    privateKey = createPrivateKey(readFileSync(keyFile));
  } else if (code) {
    privateKey = privateKeyFromSeed(code.seed);
  } else {
    privateKey = createPrivateKey(String(file.private_key));
  }
  if (privateKey.asymmetricKeyType !== 'ed25519') throw new Error('秘密鍵が Ed25519 ではありません（keygen で作った鍵か、接続コードを使ってください）');
  const serverRaw = Buffer.from(String(v.serverKey).trim(), 'base64');
  const serverKey = publicKeyFromRaw(serverRaw);
  const expect = envValue(env, 'SHIP_MCP_EXPECT_SERVER_KEY');
  if (expect && Buffer.from(expect.trim(), 'base64').toString('base64') !== serverRaw.toString('base64')) {
    throw new Error('この拡張機能は、別のサイト用です（このサイトの接続コードを貼ってください。サイトごとに拡張機能が分かれています）');
  }
  if (url.protocol === 'http:') {
    log('注意：HTTP で接続します。パスワードは送りませんが、中身（文章・写真）は暗号化されません。公開予定の内容だけを扱ってください。');
  }
  return { url, keyId: v.keyId, privateKey, serverKey, basic, timeoutMs, uploadRoots };
}

// ---- 写真（ローカルのファイル） ---------------------------------------------------

export const LOCAL_UPLOAD_TOOL = 'ship-content-upload-local-image';
const LOCAL_MAX_BYTES = 100 * 1024 * 1024;
const EXT_MIME = { jpg: 'image/jpeg', jpeg: 'image/jpeg', png: 'image/png', webp: 'image/webp' };

function expandHome(p) {
  return p === '~' || p.startsWith('~/') ? homedir() + p.slice(1) : p;
}

export function sniffImage(buf) {
  if (buf.length >= 3 && buf[0] === 0xff && buf[1] === 0xd8 && buf[2] === 0xff) return 'image/jpeg';
  if (buf.length >= 8 && buf.subarray(0, 8).equals(Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]))) return 'image/png';
  if (buf.length >= 12 && buf.toString('latin1', 0, 4) === 'RIFF' && buf.toString('latin1', 8, 12) === 'WEBP') return 'image/webp';
  return null;
}

// 送ってよいファイルか（許されたフォルダの中・隠しフォルダでない・画像）。読み込んだ中身と MIME を返す。
export function checkLocalImage(path, roots) {
  if (typeof path !== 'string' || !path.trim()) throw new Error('path（写真のファイルのパス）を入れてください');
  let real;
  try { real = realpathSync(resolve(expandHome(path.trim()))); } catch { throw new Error('ファイルが見つかりません'); }
  const root = roots.find((r) => { const rel = relative(r, real); return rel && !rel.startsWith('..') && !isAbsolute(rel); });
  if (!root) throw new Error('このフォルダの写真は送れません（SHIP_MCP_UPLOAD_DIRS で許されたフォルダの中だけ）');
  if (relative(root, real).split(/[\\/]/).some((seg) => seg.startsWith('.'))) throw new Error('隠しフォルダ・隠しファイルの中身は送れません');
  const st = statSync(real);
  if (!st.isFile()) throw new Error('ファイルではありません');
  if (st.size <= 0 || st.size > LOCAL_MAX_BYTES) throw new Error('ファイルの大きさが範囲外です');
  const buf = readFileSync(real);
  const mime = sniffImage(buf);
  const extMime = EXT_MIME[extname(real).slice(1).toLowerCase()];
  if (!mime) throw new Error('JPEG・PNG・WebP の画像ではありません');
  if (extMime !== mime) throw new Error(`拡張子と中身の形式が合いません（中身は ${mime}）`);
  return { real, buf, mime, name: basename(real) };
}

const LOCAL_TOOL_DEF = {
  name: LOCAL_UPLOAD_TOOL,
  title: '手元の写真を登録する',
  description: 'PM のパソコンにある写真（JPEG・PNG・WebP）を、WordPress のメディアに登録する。path にファイルのパスを渡すだけでよい（読み込み・分割・送信はこの中継プログラムが行う。AI が写真の中身を読む必要はない）。位置情報などは WordPress 側で消す。返った attachment_id を ACF の画像の欄や featured_media に、url_large を本文（post_content）の img に使う。メディアの写真は、記事が下書きのままでも URL を知っていれば見られるので、公開してよい写真だけにする。',
  inputSchema: {
    type: 'object',
    properties: {
      post_type: { type: 'string', description: '使う投稿タイプ（list-post-types の name）' },
      path: { type: 'string', description: '写真のファイルのパス（~ 可）' },
      title: { type: 'string', maxLength: 200 },
      alt: { type: 'string', maxLength: 300, description: '代替テキスト（写真の内容を短く。お客様の名前・住所は入れない）' },
      attach_to_post_id: { type: 'integer', minimum: 1, description: '任意。同じ投稿タイプの下書きに添付するとき' },
    },
    required: ['post_type', 'path'],
    additionalProperties: false,
  },
  annotations: { title: '手元の写真を登録する', readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false },
};

// ---- 送受信 ----------------------------------------------------------------

export class Bridge {
  constructor(config, fetchImpl = globalThis.fetch) {
    this.cfg = config;
    this.fetch = fetchImpl;
    this.sessionId = '';
    this.protocolVersion = '';
  }

  async send(method, bodyText) {
    const body = Buffer.from(bodyText ?? '', 'utf8');
    const url = this.cfg.url;
    const timestamp = String(Math.floor(Date.now() / 1000));
    const nonce = randomBytes(18).toString('base64url');
    const uri = url.pathname + url.search;
    const headers = {
      Accept: 'application/json, text/event-stream',
      'User-Agent': `ship-content-mcp-bridge/${VERSION}`,
    };
    if (this.cfg.token) {
      headers['X-Ship-MCP-Token'] = this.cfg.token;
    } else {
      headers['X-Ship-MCP-Key'] = this.cfg.keyId;
      headers['X-Ship-MCP-Timestamp'] = timestamp;
      headers['X-Ship-MCP-Nonce'] = nonce;
      headers['X-Ship-MCP-Signature'] = signRequest(this.cfg.privateKey, {
        method, host: url.host, uri, timestamp, nonce, keyId: this.cfg.keyId, sessionId: this.sessionId, body,
      });
    }
    if (method !== 'DELETE') headers['Content-Type'] = 'application/json';
    if (this.sessionId) headers['Mcp-Session-Id'] = this.sessionId;
    if (this.protocolVersion) headers['MCP-Protocol-Version'] = this.protocolVersion;
    if (this.cfg.basic) headers.Authorization = this.cfg.basic;

    let res;
    try {
      res = await this.fetch(url, {
        method, headers, body: method === 'DELETE' ? undefined : body, redirect: 'manual', signal: AbortSignal.timeout(this.cfg.timeoutMs),
      });
    } catch (e) {
      return { ok: false, message: `サイトにつながりません（${e?.name === 'TimeoutError' ? '時間切れ' : e?.message || e}）` };
    }
    const resBody = Buffer.from(await res.arrayBuffer());
    const resSession = res.headers.get('mcp-session-id') || '';
    if (this.cfg.token) {
      // トークンのときは、返事に署名はない。WordPress の JSON でない返事（サーバーの Basic 認証・転送など）だけを分かる言葉にする。
      const json = (res.headers.get('content-type') || '').includes('json');
      if ((res.status >= 300 && res.status < 400) || ((res.status === 401 || res.status === 404) && !json)) {
        return { ok: false, status: res.status, message: unsignedMessage(res.status, false) };
      }
    } else {
      const signed = verifyResponse(this.cfg.serverKey, { nonce, status: res.status, sessionId: resSession, body: resBody }, res.headers.get('x-ship-mcp-response-signature'));
      if (!signed) {
        return { ok: false, status: res.status, message: unsignedMessage(res.status, res.headers.has('x-ship-mcp-response-signature')) };
      }
    }
    if (resSession) this.sessionId = resSession;
    return { ok: true, status: res.status, body: resBody };
  }

  // MCP のメッセージ1つ（JSON の1行）を送り、AI に返す行（なければ null）を返す。
  async handleLine(line) {
    let msg;
    try {
      msg = JSON.parse(line);
    } catch {
      return rpcError(null, -32700, 'JSON として読めません');
    }
    const isRequest = msg && typeof msg === 'object' && typeof msg.method === 'string' && msg.id !== undefined && msg.id !== null;
    if (isRequest && msg.method === 'tools/call' && msg.params?.name === LOCAL_UPLOAD_TOOL) {
      return JSON.stringify({ jsonrpc: '2.0', id: msg.id, result: await this.localUpload(msg.params.arguments || {}) });
    }
    const r = await this.send('POST', JSON.stringify(msg));
    if (!r.ok) return isRequest ? rpcError(msg.id, -32001, r.message) : (log(r.message), null);
    if (r.status === 202 || r.body.length === 0) return null;
    let out;
    try {
      out = JSON.parse(r.body.toString('utf8'));
    } catch {
      return isRequest ? rpcError(msg.id, -32002, `サーバーの返事が JSON ではありません（HTTP ${r.status}）`) : null;
    }
    if (out && typeof out === 'object' && out.jsonrpc === '2.0') {
      if (isRequest && msg.method === 'initialize' && out.result?.protocolVersion) {
        this.protocolVersion = String(out.result.protocolVersion);
      }
      // サーバーが写真の転送に対応していれば、ローカルの写真ツールを一覧に足す。
      if (isRequest && msg.method === 'tools/list' && Array.isArray(out.result?.tools) && out.result.tools.some((t) => t.name === 'ship-content-prepare-upload')) {
        out.result.tools.push(LOCAL_TOOL_DEF);
      }
      return JSON.stringify(out);
    }
    // REST のエラー（{code, message, data}）を JSON-RPC のエラーにする。
    if (!isRequest) return null;
    const text = typeof out?.message === 'string' ? out.message : 'サーバーが拒否しました';
    return rpcError(msg.id, -32003, `${text}（HTTP ${r.status}${out?.code ? '、' + out.code : ''}）`);
  }

  // サーバーのツールを、この中継プログラムから呼ぶ（写真の転送用）。
  async callServerTool(name, args) {
    this.seq = (this.seq || 0) + 1;
    const r = await this.send('POST', JSON.stringify({ jsonrpc: '2.0', id: `bridge-${this.seq}`, method: 'tools/call', params: { name, arguments: args } }));
    if (!r.ok) return { ok: false, message: r.message };
    let out;
    try { out = JSON.parse(r.body.toString('utf8')); } catch { return { ok: false, message: `返事が JSON ではありません（HTTP ${r.status}）` }; }
    if (out.error) return { ok: false, message: out.error.message || 'エラー' };
    const res = out.result || {};
    const text = (res.content || []).map((c) => c.text || '').join('');
    if (res.isError) return { ok: false, message: text || 'エラー' };
    let body = res.structuredContent;
    if (!body) { try { body = JSON.parse(text); } catch { body = null; } }
    return { ok: true, body };
  }

  async localUpload(args) {
    const fail = (message) => ({ isError: true, content: [{ type: 'text', text: JSON.stringify({ ok: false, error: { code: 'MEDIA-001', message } }) }] });
    const allowed = ['post_type', 'path', 'title', 'alt', 'attach_to_post_id'];
    const extra = Object.keys(args).filter((k) => !allowed.includes(k));
    if (extra.length) return fail(`使えない項目があります：${extra.join(', ')}`);
    if (typeof args.post_type !== 'string' || !args.post_type) return fail('post_type を入れてください');
    let file;
    try { file = checkLocalImage(args.path, this.cfg.uploadRoots || []); } catch (e) { return fail(e.message); }
    const sha = createHash('sha256').update(file.buf).digest('hex');
    const prep = await this.callServerTool('ship-content-prepare-upload', { post_type: args.post_type, filename: file.name, mime_type: file.mime, size_bytes: file.buf.length, sha256: sha });
    if (!prep.ok) return fail(prep.message);
    const warnings = [...(prep.body?.warnings || [])];
    let data = prep.body?.data || {};
    if (!data.already_uploaded) {
      const sid = data.upload_session_id;
      const size = Number(data.chunk_size);
      const count = Number(data.chunks);
      if (!sid || !(size > 0) || count !== Math.ceil(file.buf.length / size)) return fail('サーバーの返事（分割の大きさ）が合いません');
      for (let i = 0; i < count; i++) {
        const part = file.buf.subarray(i * size, Math.min(file.buf.length, (i + 1) * size));
        const args2 = { upload_session_id: sid, index: i, content_base64: part.toString('base64'), chunk_sha256: createHash('sha256').update(part).digest('hex') };
        let r = await this.callServerTool('ship-content-upload-chunk', args2);
        if (!r.ok) r = await this.callServerTool('ship-content-upload-chunk', args2); // 1回だけ送り直す（同じ中身なら受け付けられる）
        if (!r.ok) return fail(`分割 ${i + 1}/${count} を送れませんでした：${r.message}`);
      }
      const fin = { upload_session_id: sid };
      for (const k of ['title', 'alt', 'attach_to_post_id']) if (args[k] !== undefined) fin[k] = args[k];
      const done = await this.callServerTool('ship-content-finish-upload', fin);
      if (!done.ok) return fail(done.message);
      data = done.body?.data || {};
      warnings.push(...(done.body?.warnings || []));
    }
    const body = { ok: true, data: { ...data, file: file.name, bytes: file.buf.length }, warnings: [...new Set(warnings)] };
    return { content: [{ type: 'text', text: JSON.stringify(body) }], structuredContent: body };
  }

  async close() {
    if (!this.sessionId) return;
    const r = await this.send('DELETE', '');
    if (!r.ok) log(`終了の連絡ができませんでした：${r.message}`);
    this.sessionId = '';
  }
}

function unsignedMessage(status, hadSignature) {
  if (hadSignature) return `サーバーの返事の署名が合いません（HTTP ${status}）。SHIP_MCP_SERVER_KEY を確かめてください。通信の途中で書き換えられた可能性もあります。`;
  if (status >= 300 && status < 400) return `転送（リダイレクト）には従いません（HTTP ${status}）。SHIP_MCP_URL を確かめてください。`;
  if (status === 401) return 'サーバーの Basic 認証で止まった可能性があります（HTTP 401、署名なし）。SHIP_MCP_BASIC_AUTH を確かめてください。';
  if (status === 404) return 'MCP の入口が見つかりません（HTTP 404、署名なし）。プラグインの「MCP を受け付ける」と SHIP_MCP_URL を確かめてください。';
  return `サーバーの返事に署名がありません（HTTP ${status}）。プラグインが有効か、URL が正しいかを確かめてください。`;
}

function rpcError(id, code, message) {
  return JSON.stringify({ jsonrpc: '2.0', id: id ?? null, error: { code, message } });
}

// ---- コマンド ----------------------------------------------------------------

function argValue(args, name) {
  const i = args.indexOf(name);
  return i >= 0 ? args[i + 1] : undefined;
}

function printPublicKey(privateKey) {
  const raw = rawPublicKey(createPublicKey(privateKey));
  process.stdout.write(`公開鍵（管理画面の「端末の鍵」に貼る）：\n${raw.toString('base64')}\n指紋：${fingerprint(raw)}\n`);
}

function keygen(args) {
  const out = argValue(args, '--out');
  if (!out) throw new Error('--out で秘密鍵の保存先を指定してください（例：~/.ship-content-mcp/サイト名.key）');
  const file = resolve(out);
  if (existsSync(file)) throw new Error(`すでにファイルがあります：${file}（上書きしません）`);
  mkdirSync(dirname(file), { recursive: true, mode: 0o700 });
  const { privateKey } = generateKeyPairSync('ed25519');
  const fd = openSync(file, 'wx', 0o600);
  writeSync(fd, privateKey.export({ type: 'pkcs8', format: 'pem' }));
  closeSync(fd);
  process.stdout.write(`秘密鍵を保存しました：${file}\n（このファイルは人に渡さないでください。パスワード管理ソフトにも控えを。）\n`);
  printPublicKey(privateKey);
}

function pubkey(args) {
  const keyFile = argValue(args, '--key');
  if (!keyFile) throw new Error('--key で秘密鍵のファイルを指定してください');
  printPublicKey(createPrivateKey(readFileSync(resolve(keyFile))));
}

// つながるか確かめる（初期化 → ツールの一覧 → 終了）。
async function probe(env) {
  const bridge = new Bridge(loadConfig(env));
  try {
    const init = await bridge.handleLine(JSON.stringify({
      jsonrpc: '2.0', id: 1, method: 'initialize',
      params: { protocolVersion: PROTOCOL_VERSION, capabilities: {}, clientInfo: { name: 'ship-content-mcp-bridge-check', version: VERSION } },
    }));
    const initObj = JSON.parse(init);
    if (initObj.error) throw new Error(`初期化できません：${initObj.error.message}`);
    await bridge.handleLine(JSON.stringify({ jsonrpc: '2.0', method: 'notifications/initialized' }));
    const list = JSON.parse(await bridge.handleLine(JSON.stringify({ jsonrpc: '2.0', id: 2, method: 'tools/list', params: {} })));
    if (list.error) throw new Error(`ツールの一覧を取れません：${list.error.message}`);
    return { server: initObj.result?.serverInfo ?? {}, protocol: initObj.result?.protocolVersion ?? '?', tools: (list.result?.tools ?? []).map((t) => t.name) };
  } finally {
    await bridge.close();
  }
}

async function check() {
  const r = await probe(process.env);
  process.stdout.write(`接続できました：${r.server.name ?? '?'} ${r.server.version ?? ''}（MCP ${r.protocol}）\n`);
  for (const t of r.tools) process.stdout.write(`- ${t}\n`);
}

function readClipboard() {
  const tries = process.platform === 'darwin' ? [['pbpaste', []]]
    : process.platform === 'win32' ? [['powershell.exe', ['-NoProfile', '-Command', 'Get-Clipboard -Raw']]]
      : [['wl-paste', ['--no-newline']], ['xclip', ['-selection', 'clipboard', '-o']], ['xsel', ['--clipboard', '--output']]];
  for (const [cmd, a] of tries) {
    try {
      return execFileSync(cmd, a, { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'], timeout: 10000, maxBuffer: 1 << 20 });
    } catch { /* 次を試す */ }
  }
  throw new Error('クリップボードを読めませんでした（接続コードをファイルに保存して、--code-file <ファイル> で指定してください）');
}

function findClaude() {
  const candidates = [process.env.CLAUDE_CODE_EXECPATH, 'claude'].filter(Boolean);
  for (const c of candidates) {
    try {
      execFileSync(c, ['--version'], { stdio: 'ignore', timeout: 30000 });
      return c;
    } catch { /* 次を試す */ }
  }
  return null;
}

function shellQuote(s) {
  return /^[A-Za-z0-9_@%+=:,./-]+$/.test(s) ? s : `'${s.replace(/'/g, `'\\''`)}'`;
}

// 接続コードで設定する：設定のファイルを作り（秘密を含むので 600）、つながるか確かめ、必要なら Claude Code に登録する。
// 接続コードの中身（鍵の種・Basic 認証）は画面に出さない。
async function setup(args) {
  let text;
  if (args.includes('--clipboard')) text = readClipboard();
  else if (argValue(args, '--code-file')) text = readFileSync(resolve(expandHome(argValue(args, '--code-file'))), 'utf8');
  else if (!process.stdin.isTTY) text = readFileSync(0, 'utf8');
  else throw new Error('接続コードの読み方を指定してください（--clipboard か --code-file <ファイル>）');
  const code = decodeConnectionCode(text);
  text = '';
  const name = nameSlug(argValue(args, '--name') || code.name);
  const dir = resolve(expandHome(argValue(args, '--dir') || '~/.ship-content-mcp'));
  mkdirSync(dir, { recursive: true, mode: 0o700 });
  const file = join(dir, `${name}.json`);
  const config = {
    site: code.site,
    url: code.url,
    key_id: code.keyId,
    server_key: code.serverKey,
    private_key: privateKeyFromSeed(code.seed).export({ type: 'pkcs8', format: 'pem' }),
  };
  if (code.basicAuth) config.basic_auth = code.basicAuth;
  const up = argValue(args, '--upload-dirs');
  if (up) config.upload_dirs = up.split(delimiter).filter(Boolean).map((d) => resolve(expandHome(d)));
  const replaced = existsSync(file);
  const tmp = `${file}.${process.pid}.tmp`;
  writeFileSync(tmp, JSON.stringify(config, null, 2) + '\n', { mode: 0o600 });
  renameSync(tmp, file);
  if (process.platform !== 'win32') chmodSync(file, 0o600);
  const out = (s) => process.stdout.write(s + '\n');
  out(`設定を${replaced ? '置き換えました' : '保存しました'}：${file}（秘密を含むので、人に渡さないでください）`);
  out(`サイト：${code.site || '（名前なし）'}　入口：${code.url}　鍵 ID：${code.keyId}　Basic 認証：${code.basicAuth ? 'あり' : 'なし'}`);

  const r = await probe({ SHIP_MCP_CONFIG: file });
  out(`接続できました（使える機能 ${r.tools.length} 個）。`);

  const server = `ship-content-${name}`;
  const scope = argValue(args, '--scope') || 'user';
  if (!['user', 'project', 'local'].includes(scope)) throw new Error('--scope は user・project・local のどれかにしてください');
  const addArgs = ['mcp', 'add', server, '--scope', scope, '-e', `SHIP_MCP_CONFIG=${file}`, '--', process.execPath, fileURLToPath(import.meta.url)];
  const manual = `claude ${addArgs.map(shellQuote).join(' ')}`;
  if (args.includes('--print-config')) {
    out('ほかの AI（mcpServers の形の設定を使うもの）に貼る設定：');
    out(JSON.stringify({ mcpServers: { [server]: { command: process.execPath, args: [fileURLToPath(import.meta.url)], env: { SHIP_MCP_CONFIG: file } } } }, null, 2));
  }
  if (args.includes('--register')) {
    const claude = findClaude();
    if (!claude) {
      out('Claude Code のコマンドが見つからないので、登録はしていません。次のコマンドで登録してください：');
      out(manual);
      return;
    }
    try { execFileSync(claude, ['mcp', 'remove', server, '--scope', scope], { stdio: 'ignore', timeout: 30000 }); } catch { /* なければよい */ }
    try {
      execFileSync(claude, addArgs, { stdio: ['ignore', 'pipe', 'pipe'], timeout: 30000 });
    } catch (e) {
      throw new Error(`Claude Code に登録できませんでした：${String(e.stderr || e.message || e).trim()}\n手で登録するとき：${manual}`);
    }
    out(`Claude Code に「${server}」として登録しました（${scope}）。`);
    out(`次から：Claude で新しいセッションを開き、「${code.site || 'このサイト'}で使える投稿タイプを教えて」と聞いてください。`);
  } else if (!args.includes('--print-config')) {
    out('Claude Code に登録するコマンド：');
    out(manual);
  }
}

async function serve() {
  const bridge = new Bridge(loadConfig());
  const rl = createInterface({ input: process.stdin, crlfDelay: Infinity });
  let chain = Promise.resolve();
  rl.on('line', (line) => {
    if (!line.trim()) return;
    chain = chain.then(async () => {
      const out = await bridge.handleLine(line);
      if (out !== null) process.stdout.write(out + '\n');
    }).catch((e) => log(`処理できませんでした：${e?.message || e}`));
  });
  rl.on('close', () => {
    chain.then(() => bridge.close()).finally(() => process.exit(0));
  });
}

async function main() {
  const [cmd, ...args] = process.argv.slice(2);
  try {
    if (cmd === 'setup') return await setup(args);
    if (cmd === 'keygen') return keygen(args);
    if (cmd === 'pubkey') return pubkey(args);
    if (cmd === 'check') return await check();
    if (cmd === '--version') return process.stdout.write(VERSION + '\n');
    if (cmd && cmd !== 'serve') throw new Error(`知らないコマンドです：${cmd}`);
    return await serve();
  } catch (e) {
    log(e?.message || String(e));
    process.exit(1);
  }
}

const isMain = process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url);
if (isMain) main();
