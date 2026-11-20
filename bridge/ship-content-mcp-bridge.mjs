#!/usr/bin/env node
// Ship Content MCP 中継プログラム（PM の端末で動く。Node.js 18 以上。外部のパッケージは使わない）
//
// AI エージェントからは、ふつうの MCP サーバー（標準入出力）に見える。
// 受け取った MCP のメッセージに端末の鍵で署名して WordPress へ送り、返事の署名（サイトの鍵）を確かめてから AI に渡す。
// パスワードを通信に乗せないので、HTTP の動作確認URLや、サーバーの Basic 認証のあるサイトでも使える。
//
// 使い方：
//   node ship-content-mcp-bridge.mjs keygen --out <秘密鍵のファイル>   … 鍵を作る（公開鍵を表示）
//   node ship-content-mcp-bridge.mjs pubkey --key <秘密鍵のファイル>   … 公開鍵をもう一度表示
//   node ship-content-mcp-bridge.mjs check                             … つながるか試す（ツールの一覧を表示）
//   node ship-content-mcp-bridge.mjs                                   … 中継（AI エージェントの設定から起動する）
//
// 設定（環境変数）：
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

import { createHash, createPrivateKey, createPublicKey, generateKeyPairSync, randomBytes, sign, verify } from 'node:crypto';
import { closeSync, existsSync, mkdirSync, openSync, readFileSync, statSync, writeSync } from 'node:fs';
import { delimiter, dirname, isAbsolute, relative, resolve, basename, extname } from 'node:path';
import { homedir } from 'node:os';
import { realpathSync } from 'node:fs';
import { createInterface } from 'node:readline';
import { fileURLToPath } from 'node:url';

export const VERSION = '0.5.1';
export const REQ_PREFIX = 'ship-content-mcp-req-v1';
export const RES_PREFIX = 'ship-content-mcp-res-v1';
const SPKI_ED25519_PREFIX = Buffer.from('302a300506032b6570032100', 'hex');
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

export function loadConfig(env = process.env) {
  const need = ['SHIP_MCP_URL', 'SHIP_MCP_KEY_ID', 'SHIP_MCP_KEY_FILE', 'SHIP_MCP_SERVER_KEY'];
  const missing = need.filter((k) => !env[k]);
  if (missing.length) throw new Error(`設定が足りません：${missing.join(', ')}`);
  const url = new URL(env.SHIP_MCP_URL);
  if (url.protocol !== 'https:' && url.protocol !== 'http:') throw new Error('SHIP_MCP_URL は http:// か https:// で始めてください');
  if (url.username || url.password) throw new Error('SHIP_MCP_URL に ID・パスワードを入れないでください（SHIP_MCP_BASIC_AUTH を使う）');
  if (!/^k[0-9a-f]{16}$/.test(env.SHIP_MCP_KEY_ID)) throw new Error('SHIP_MCP_KEY_ID の形が違います');
  const keyFile = resolve(env.SHIP_MCP_KEY_FILE);
  const st = statSync(keyFile);
  if (process.platform !== 'win32' && (st.mode & 0o077) !== 0) {
    log(`注意：秘密鍵のファイルが、ほかのユーザーからも読めます（chmod 600 ${keyFile} を勧めます）`);
  }
  const privateKey = createPrivateKey(readFileSync(keyFile));
  if (privateKey.asymmetricKeyType !== 'ed25519') throw new Error('秘密鍵が Ed25519 ではありません（keygen で作った鍵を使ってください）');
  const serverRaw = Buffer.from(env.SHIP_MCP_SERVER_KEY.trim(), 'base64');
  const serverKey = publicKeyFromRaw(serverRaw);
  let basic = '';
  if (env.SHIP_MCP_BASIC_AUTH) {
    if (!env.SHIP_MCP_BASIC_AUTH.includes(':')) throw new Error('SHIP_MCP_BASIC_AUTH は「ID:パスワード」の形にしてください');
    basic = 'Basic ' + Buffer.from(env.SHIP_MCP_BASIC_AUTH, 'utf8').toString('base64');
  }
  const timeoutMs = Math.max(5000, Math.min(600000, Number(env.SHIP_MCP_TIMEOUT_MS || 60000) || 60000));
  if (url.protocol === 'http:') {
    log('注意：HTTP で接続します。パスワードは送りませんが、中身（文章・写真）は暗号化されません。公開予定の内容だけを扱ってください。');
  }
  const uploadRoots = (env.SHIP_MCP_UPLOAD_DIRS ? env.SHIP_MCP_UPLOAD_DIRS.split(delimiter) : [homedir()])
    .filter(Boolean)
    .map((d) => { try { return realpathSync(resolve(expandHome(d))); } catch { return null; } })
    .filter(Boolean);
  return { url, keyId: env.SHIP_MCP_KEY_ID, privateKey, serverKey, basic, timeoutMs, uploadRoots };
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
    const signature = signRequest(this.cfg.privateKey, {
      method, host: url.host, uri, timestamp, nonce, keyId: this.cfg.keyId, sessionId: this.sessionId, body,
    });
    const headers = {
      Accept: 'application/json, text/event-stream',
      'X-Ship-MCP-Key': this.cfg.keyId,
      'X-Ship-MCP-Timestamp': timestamp,
      'X-Ship-MCP-Nonce': nonce,
      'X-Ship-MCP-Signature': signature,
      'User-Agent': `ship-content-mcp-bridge/${VERSION}`,
    };
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
    const signed = verifyResponse(this.cfg.serverKey, { nonce, status: res.status, sessionId: resSession, body: resBody }, res.headers.get('x-ship-mcp-response-signature'));
    if (!signed) {
      return { ok: false, status: res.status, message: unsignedMessage(res.status, res.headers.has('x-ship-mcp-response-signature')) };
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

async function check() {
  const bridge = new Bridge(loadConfig());
  const init = await bridge.handleLine(JSON.stringify({
    jsonrpc: '2.0', id: 1, method: 'initialize',
    params: { protocolVersion: PROTOCOL_VERSION, capabilities: {}, clientInfo: { name: 'ship-content-mcp-bridge-check', version: VERSION } },
  }));
  const initObj = JSON.parse(init);
  if (initObj.error) throw new Error(`初期化できません：${initObj.error.message}`);
  process.stdout.write(`接続できました：${initObj.result?.serverInfo?.name ?? '?'} ${initObj.result?.serverInfo?.version ?? ''}（MCP ${initObj.result?.protocolVersion ?? '?'}）\n`);
  await bridge.handleLine(JSON.stringify({ jsonrpc: '2.0', method: 'notifications/initialized' }));
  const list = JSON.parse(await bridge.handleLine(JSON.stringify({ jsonrpc: '2.0', id: 2, method: 'tools/list', params: {} })));
  if (list.error) throw new Error(`ツールの一覧を取れません：${list.error.message}`);
  for (const t of list.result?.tools ?? []) process.stdout.write(`- ${t.name}\n`);
  await bridge.close();
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
