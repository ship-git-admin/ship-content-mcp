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
//
// 署名の決まりは docs/AUTH_SIGNATURE.md（WordPress 側の SignatureCodec と同じ）。

import { createHash, createPrivateKey, createPublicKey, generateKeyPairSync, randomBytes, sign, verify } from 'node:crypto';
import { closeSync, existsSync, mkdirSync, openSync, readFileSync, statSync, writeSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { createInterface } from 'node:readline';
import { fileURLToPath } from 'node:url';

export const VERSION = '0.1.0-dev';
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
  return { url, keyId: env.SHIP_MCP_KEY_ID, privateKey, serverKey, basic, timeoutMs };
}

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
      return JSON.stringify(out);
    }
    // REST のエラー（{code, message, data}）を JSON-RPC のエラーにする。
    if (!isRequest) return null;
    const text = typeof out?.message === 'string' ? out.message : 'サーバーが拒否しました';
    return rpcError(msg.id, -32003, `${text}（HTTP ${r.status}${out?.code ? '、' + out.code : ''}）`);
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
