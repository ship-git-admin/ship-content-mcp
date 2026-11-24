// 中継プログラムの試験：node --test tests/node/
// - PHP（SignatureCodec）と署名を確かめ合う（言語をまたぐ）
// - 模擬サーバー相手に、初期化・セッション・ツール一覧・改ざん・署名なし・使い回し・終了を確かめる

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { execFileSync, spawn, spawnSync } from 'node:child_process';
import { createPublicKey, generateKeyPairSync, randomBytes, sign as edSign, verify as edVerify } from 'node:crypto';
import { mkdtempSync, readFileSync, statSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  Bridge, canonicalRequest, canonicalResponse, loadConfig, publicKeyFromRaw, rawPublicKey, signRequest, verifyResponse, checkLocalImage, sniffImage, LOCAL_UPLOAD_TOOL,
  decodeConnectionCode, privateKeyFromSeed,
} from '../../bridge/ship-content-mcp-bridge.mjs';
import { createHash } from 'node:crypto';
import { copyFileSync, mkdirSync as mkdirSync2, realpathSync } from 'node:fs';

const here = dirname(fileURLToPath(import.meta.url));
const codecCli = join(here, '..', 'php', 'codec_cli.php');
const bridgeFile = join(here, '..', '..', 'bridge', 'ship-content-mcp-bridge.mjs');
const php = process.env.PHP_BIN || 'php';

// ---- 言語をまたぐ ------------------------------------------------------------

test('Node の署名を PHP が確かめる（改ざんは NG）', () => {
  const { privateKey, publicKey } = generateKeyPairSync('ed25519');
  const fields = {
    method: 'POST', host: 'Example.Invalid:8080', uri: '/wp-json/ship-content/mcp?a=%E3%81%82', timestamp: '1790000000',
    nonce: randomBytes(18).toString('base64url'), keyId: 'k0123456789abcdef', sessionId: 'sess-1', body: Buffer.from('{"jsonrpc":"2.0","method":"x","params":{"t":"施工事例"}}'),
  };
  const sig = signRequest(privateKey, fields);
  const payload = { ...fields, body: fields.body.toString('utf8'), pk: rawPublicKey(publicKey).toString('base64'), sig };
  assert.equal(execFileSync(php, [codecCli, 'verify-request', JSON.stringify(payload)]).toString(), 'OK');
  const tampered = { ...payload, body: payload.body.replace('施工', '施行') };
  assert.equal(execFileSync(php, [codecCli, 'verify-request', JSON.stringify(tampered)]).toString(), 'NG');
  const otherUri = { ...payload, uri: '/wp-json/wp/v2/posts' };
  assert.equal(execFileSync(php, [codecCli, 'verify-request', JSON.stringify(otherUri)]).toString(), 'NG');
});

test('PHP の応答の署名を Node が確かめる（改ざんは NG）', () => {
  const fields = { nonce: randomBytes(18).toString('base64url'), status: 200, sessionId: 'abc', body: '{"jsonrpc":"2.0","id":1,"result":{}}' };
  const out = JSON.parse(execFileSync(php, [codecCli, 'sign-response', JSON.stringify(fields)]).toString());
  const key = publicKeyFromRaw(Buffer.from(out.pk, 'base64'));
  assert.ok(verifyResponse(key, { ...fields, body: Buffer.from(fields.body) }, out.sig));
  assert.ok(!verifyResponse(key, { ...fields, status: 201, body: Buffer.from(fields.body) }, out.sig));
  assert.ok(!verifyResponse(key, { ...fields, sessionId: 'evil', body: Buffer.from(fields.body) }, out.sig));
  assert.ok(!verifyResponse(key, { ...fields, body: Buffer.from(fields.body + ' ') }, out.sig));
  assert.ok(!verifyResponse(key, { ...fields, body: Buffer.from(fields.body) }, ''));
});

// ---- 模擬サーバー ------------------------------------------------------------

function startMock({ mode = 'normal', chunkSize = 300, existing = '' } = {}) {
  const uploads = new Map();
  const device = generateKeyPairSync('ed25519');
  const site = generateKeyPairSync('ed25519');
  const keyId = 'k' + randomBytes(8).toString('hex');
  const seen = new Set();
  const log = [];
  const sessions = new Set();
  const server = createServer((req, res) => {
    const chunks = [];
    req.on('data', (c) => chunks.push(c));
    req.on('end', () => {
      const body = Buffer.concat(chunks);
      const h = (n) => req.headers[n.toLowerCase()] || '';
      const nonce = h('X-Ship-MCP-Nonce');
      log.push({ method: req.method, auth: h('Authorization'), session: h('Mcp-Session-Id'), body: body.toString() });
      const reply = (status, obj, extra = {}) => {
        const text = obj === null ? '' : JSON.stringify(obj);
        const sessionId = extra['Mcp-Session-Id'] || '';
        let sig = edSign(null, Buffer.from(canonicalResponse({ nonce, status, sessionId, body: Buffer.from(text) })), site.privateKey).toString('base64');
        let sent = text;
        if (mode === 'tamper') sent = text.replace('Mock', 'Evil');
        if (mode === 'wrongkey') sig = edSign(null, Buffer.from('x'), generateKeyPairSync('ed25519').privateKey).toString('base64');
        const headers = { 'Content-Type': 'application/json', ...extra };
        if (mode !== 'unsigned') headers['X-Ship-MCP-Response-Signature'] = sig;
        res.writeHead(status, headers);
        res.end(sent);
      };
      if (mode === 'basic401') {
        res.writeHead(401, { 'WWW-Authenticate': 'Basic realm="x"' });
        return res.end('<html>401</html>');
      }
      // 署名を確かめる（WordPress 側と同じ決まり）。
      const fields = {
        method: req.method, host: h('Host'), uri: req.url, timestamp: h('X-Ship-MCP-Timestamp'), nonce, keyId: h('X-Ship-MCP-Key'), sessionId: h('Mcp-Session-Id'), body,
      };
      const ok = fields.keyId === keyId && edVerify(null, Buffer.from(canonicalRequest(fields)), device.publicKey, Buffer.from(h('X-Ship-MCP-Signature'), 'base64'));
      if (!ok) return reply(401, { code: 'ship_content_mcp_auth', message: '認証に失敗しました（MCP-002）', data: { status: 401 } });
      if (seen.has(nonce)) return reply(401, { code: 'ship_content_mcp_auth', message: 'replay', data: { status: 401 } });
      seen.add(nonce);
      if (req.method === 'DELETE') {
        sessions.delete(fields.sessionId);
        return reply(200, null);
      }
      const msg = JSON.parse(body.toString());
      if (msg.method === 'initialize') {
        const sid = 'sess-' + randomBytes(4).toString('hex');
        sessions.add(sid);
        return reply(200, { jsonrpc: '2.0', id: msg.id, result: { protocolVersion: msg.params.protocolVersion, serverInfo: { name: 'Mock', version: '1' }, capabilities: { tools: {} } } }, { 'Mcp-Session-Id': sid });
      }
      if (!sessions.has(fields.sessionId)) return reply(400, { jsonrpc: '2.0', id: msg.id ?? null, error: { code: -32600, message: 'Missing Mcp-Session-Id header' } });
      if (msg.id === undefined) return reply(202, null);
      if (msg.method === 'tools/list') return reply(200, { jsonrpc: '2.0', id: msg.id, result: { tools: [{ name: 'ship-content-list-post-types' }, { name: 'ship-content-prepare-upload' }] } });
      if (msg.method === 'tools/call') {
        const ok = (data) => reply(200, { jsonrpc: '2.0', id: msg.id, result: { content: [{ type: 'text', text: JSON.stringify({ ok: true, data }) }], structuredContent: { ok: true, data, warnings: ['公開の注意'] } } });
        const ng = (text) => reply(200, { jsonrpc: '2.0', id: msg.id, result: { isError: true, content: [{ type: 'text', text }] } });
        const a = msg.params.arguments;
        if (msg.params.name === 'ship-content-prepare-upload') {
          if (existing && a.sha256 === existing) return ok({ already_uploaded: true, attachment_id: 777 });
          const id = randomBytes(16).toString('hex');
          uploads.set(id, { a, parts: [] });
          return ok({ already_uploaded: false, upload_session_id: id, chunk_size: chunkSize, chunks: Math.ceil(a.size_bytes / chunkSize) });
        }
        if (msg.params.name === 'ship-content-upload-chunk') {
          const u = uploads.get(a.upload_session_id);
          const raw = Buffer.from(a.content_base64, 'base64');
          if (!u || createHash('sha256').update(raw).digest('hex') !== a.chunk_sha256) return ng('{"ok":false,"error":{"code":"MEDIA-001"}}');
          u.parts[a.index] = raw;
          return ok({ received: u.parts.filter(Boolean).length });
        }
        if (msg.params.name === 'ship-content-finish-upload') {
          const u = uploads.get(a.upload_session_id);
          const all = Buffer.concat(u.parts);
          if (createHash('sha256').update(all).digest('hex') !== u.a.sha256) return ng('{"ok":false,"error":{"code":"MEDIA-001","message":"hash"}}');
          return ok({ attachment_id: 4242, chunks_received: u.parts.length, title: a.title || '' });
        }
      }
      return reply(200, { jsonrpc: '2.0', id: msg.id, error: { code: -32601, message: 'Method not found' } });
    });
  });
  return new Promise((resolveStart) => {
    server.listen(0, '127.0.0.1', () => {
      const { port } = server.address();
      const cfg = {
        url: new URL(`http://127.0.0.1:${port}/wp-json/ship-content/mcp`),
        keyId, privateKey: device.privateKey, serverKey: site.publicKey, basic: 'Basic ' + Buffer.from('gate:pw').toString('base64'), timeoutMs: 5000,
      };
      resolveStart({ cfg, log, close: () => new Promise((r) => server.close(r)), sessions });
    });
  });
}

const init = (id = 1) => JSON.stringify({ jsonrpc: '2.0', id, method: 'initialize', params: { protocolVersion: '2025-11-25', capabilities: {}, clientInfo: { name: 't', version: '1' } } });

test('初期化・セッション・ツール一覧・通知・終了', async () => {
  const m = await startMock();
  try {
    const b = new Bridge(m.cfg);
    const r1 = JSON.parse(await b.handleLine(init()));
    assert.equal(r1.result.serverInfo.name, 'Mock');
    assert.match(b.sessionId, /^sess-/);
    assert.equal(b.protocolVersion, '2025-11-25');
    assert.equal(await b.handleLine(JSON.stringify({ jsonrpc: '2.0', method: 'notifications/initialized' })), null);
    const r2 = JSON.parse(await b.handleLine(JSON.stringify({ jsonrpc: '2.0', id: 2, method: 'tools/list', params: {} })));
    assert.deepEqual(r2.result.tools.map((t) => t.name), ['ship-content-list-post-types', 'ship-content-prepare-upload', LOCAL_UPLOAD_TOOL]);
    await b.close();
    assert.equal(m.sessions.size, 0);
    assert.equal(m.log.at(-1).method, 'DELETE');
    // Basic 認証（サイトの入口）は Authorization で送り、署名は別のヘッダー。
    assert.ok(m.log.every((l) => l.auth.startsWith('Basic ')));
  } finally {
    await m.close();
  }
});

test('返事の改ざんは AI に渡さない', async () => {
  const m = await startMock({ mode: 'tamper' });
  try {
    const out = JSON.parse(await new Bridge(m.cfg).handleLine(init()));
    assert.equal(out.error.code, -32001);
    assert.match(out.error.message, /署名が合いません/);
  } finally {
    await m.close();
  }
});

test('別のサイトの鍵の署名は通さない', async () => {
  const m = await startMock({ mode: 'wrongkey' });
  try {
    const out = JSON.parse(await new Bridge(m.cfg).handleLine(init()));
    assert.match(out.error.message, /署名が合いません/);
  } finally {
    await m.close();
  }
});

test('署名のない返事（サーバーの Basic 認証の 401）は分かる言葉で返す', async () => {
  const m = await startMock({ mode: 'basic401' });
  try {
    const out = JSON.parse(await new Bridge(m.cfg).handleLine(init()));
    assert.match(out.error.message, /Basic 認証/);
  } finally {
    await m.close();
  }
});

test('端末の鍵が違うと、サーバーは署名付きで拒否し、中継はそれを伝える', async () => {
  const m = await startMock();
  try {
    const wrong = { ...m.cfg, privateKey: generateKeyPairSync('ed25519').privateKey };
    const out = JSON.parse(await new Bridge(wrong).handleLine(init()));
    assert.equal(out.error.code, -32003);
    assert.match(out.error.message, /MCP-002/);
  } finally {
    await m.close();
  }
});

test('同じ署名付きリクエストを送り直すと拒否される（使い回し）', async () => {
  const m = await startMock();
  try {
    const b = new Bridge(m.cfg);
    await b.handleLine(init());
    // 同じ nonce で2回送るため、send を直接まねる。
    const body = Buffer.from(JSON.stringify({ jsonrpc: '2.0', id: 3, method: 'tools/list' }));
    const url = m.cfg.url;
    const ts = String(Math.floor(Date.now() / 1000));
    const nonce = randomBytes(18).toString('base64url');
    const sig = signRequest(m.cfg.privateKey, { method: 'POST', host: url.host, uri: url.pathname, timestamp: ts, nonce, keyId: m.cfg.keyId, sessionId: b.sessionId, body });
    const headers = { 'Content-Type': 'application/json', 'X-Ship-MCP-Key': m.cfg.keyId, 'X-Ship-MCP-Timestamp': ts, 'X-Ship-MCP-Nonce': nonce, 'X-Ship-MCP-Signature': sig, 'Mcp-Session-Id': b.sessionId };
    const first = await fetch(url, { method: 'POST', headers, body });
    const second = await fetch(url, { method: 'POST', headers, body });
    assert.equal(first.status, 200);
    assert.equal(second.status, 401);
  } finally {
    await m.close();
  }
});

// ---- 設定と鍵 --------------------------------------------------------------

test('設定の誤りを止める（URL に ID・パスワード、鍵 ID の形）', () => {
  const dir = mkdtempSync(join(tmpdir(), 'scm-'));
  const key = join(dir, 'k.key');
  const { privateKey, publicKey } = generateKeyPairSync('ed25519');
  writeFileSync(key, privateKey.export({ type: 'pkcs8', format: 'pem' }), { mode: 0o600 });
  const base = {
    SHIP_MCP_URL: 'https://example.invalid/wp-json/ship-content/mcp', SHIP_MCP_KEY_ID: 'k0123456789abcdef', SHIP_MCP_KEY_FILE: key, SHIP_MCP_SERVER_KEY: rawPublicKey(publicKey).toString('base64'),
  };
  assert.ok(loadConfig(base).privateKey);
  assert.throws(() => loadConfig({ ...base, SHIP_MCP_URL: 'https://u:p@example.invalid/x' }), /ID・パスワード/);
  assert.throws(() => loadConfig({ ...base, SHIP_MCP_KEY_ID: 'abc' }), /形が違います/);
  assert.throws(() => loadConfig({ ...base, SHIP_MCP_SERVER_KEY: 'AAAA' }), /長さ/);
  assert.throws(() => loadConfig({ ...base, SHIP_MCP_URL: 'ftp://example.invalid/' }), /http/);
  assert.throws(() => loadConfig({ ...base, SHIP_MCP_BASIC_AUTH: 'nocolon' }), /ID:パスワード/);
  assert.throws(() => loadConfig({ SHIP_MCP_URL: base.SHIP_MCP_URL }), /足りません/);
});

test('keygen：600 で保存し、公開鍵を表示し、上書きしない', () => {
  const dir = mkdtempSync(join(tmpdir(), 'scm-'));
  const file = join(dir, 'sub', 'site.key');
  const out = execFileSync(process.execPath, [bridgeFile, 'keygen', '--out', file]).toString();
  assert.match(out, /公開鍵/);
  const pub = out.split('\n').find((l) => /^[A-Za-z0-9+/]{43}=$/.test(l));
  assert.ok(pub, '公開鍵（base64 44文字）が表示される');
  assert.equal(statSync(file).mode & 0o777, 0o600);
  assert.doesNotMatch(out, /PRIVATE KEY/);
  const again = spawnSync(process.execPath, [bridgeFile, 'keygen', '--out', file]);
  assert.notEqual(again.status, 0);
  assert.match(again.stderr.toString(), /上書きしません/);
  const shown = execFileSync(process.execPath, [bridgeFile, 'pubkey', '--key', file]).toString();
  assert.ok(shown.includes(pub));
});

// ---- 手元の写真 ------------------------------------------------------------

const fixtures = join(here, '..', 'fixtures');

test('写真の判定：画像の印と拡張子', () => {
  assert.equal(sniffImage(readFileSync(join(fixtures, 'synthetic_gps_orient6.jpg'))), 'image/jpeg');
  assert.equal(sniffImage(readFileSync(join(fixtures, 'not_image.jpg'))), null);
  const dir = realpathSync(mkdtempSync(join(tmpdir(), 'scm-img-')));
  copyFileSync(join(fixtures, 'synthetic.png'), join(dir, 'p.png'));
  copyFileSync(join(fixtures, 'synthetic.png'), join(dir, 'renamed.jpg'));
  copyFileSync(join(fixtures, 'not_image.jpg'), join(dir, 'text.jpg'));
  mkdirSync2(join(dir, '.secret'));
  copyFileSync(join(fixtures, 'synthetic.png'), join(dir, '.secret', 'in-hidden.png'));
  assert.equal(checkLocalImage(join(dir, 'p.png'), [dir]).mime, 'image/png');
  assert.throws(() => checkLocalImage(join(dir, 'renamed.jpg'), [dir]), /拡張子と中身/);
  assert.throws(() => checkLocalImage(join(dir, 'text.jpg'), [dir]), /画像ではありません/);
  assert.throws(() => checkLocalImage(join(dir, '.secret', 'in-hidden.png'), [dir]), /隠し/);
  assert.throws(() => checkLocalImage(join(fixtures, 'synthetic.png'), [dir]), /このフォルダの写真は送れません/);
  assert.throws(() => checkLocalImage(join(dir, 'nothing.png'), [dir]), /見つかりません/);
  assert.throws(() => checkLocalImage(join(dir, '..', 'escape.png'), [dir]), /見つかりません|送れません/);
});

test('手元の写真を分割して送り、添付 ID を返す（ツール一覧にも出る）', async () => {
  const m = await startMock({ chunkSize: 300 });
  try {
    const cfg = { ...m.cfg, uploadRoots: [realpathSync(fixtures)] };
    const b = new Bridge(cfg);
    await b.handleLine(init());
    const list = JSON.parse(await b.handleLine(JSON.stringify({ jsonrpc: '2.0', id: 2, method: 'tools/list', params: {} })));
    assert.ok(list.result.tools.some((t) => t.name === LOCAL_UPLOAD_TOOL));
    const out = JSON.parse(await b.handleLine(JSON.stringify({ jsonrpc: '2.0', id: 3, method: 'tools/call', params: { name: LOCAL_UPLOAD_TOOL, arguments: { post_type: 'works', path: join(fixtures, 'synthetic_gps_orient6.jpg'), title: '架空' } } })));
    assert.equal(out.result.isError, undefined);
    assert.equal(out.result.structuredContent.data.attachment_id, 4242);
    assert.equal(out.result.structuredContent.data.chunks_received, Math.ceil(statSync(join(fixtures, 'synthetic_gps_orient6.jpg')).size / 300));
    assert.ok(out.result.structuredContent.warnings.includes('公開の注意'));
  } finally {
    await m.close();
  }
});

test('同じ写真が登録済みなら、送らずに添付 ID を返す', async () => {
  const buf = readFileSync(join(fixtures, 'synthetic.png'));
  const m = await startMock({ existing: createHash('sha256').update(buf).digest('hex') });
  try {
    const b = new Bridge({ ...m.cfg, uploadRoots: [realpathSync(fixtures)] });
    await b.handleLine(init());
    const out = JSON.parse(await b.handleLine(JSON.stringify({ jsonrpc: '2.0', id: 3, method: 'tools/call', params: { name: LOCAL_UPLOAD_TOOL, arguments: { post_type: 'works', path: join(fixtures, 'synthetic.png') } } })));
    assert.equal(out.result.structuredContent.data.attachment_id, 777);
    assert.equal(out.result.structuredContent.data.already_uploaded, true);
  } finally {
    await m.close();
  }
});

test('手元の写真ツール：画像でない・使えない項目は送らない', async () => {
  const m = await startMock();
  try {
    const b = new Bridge({ ...m.cfg, uploadRoots: [realpathSync(fixtures)] });
    await b.handleLine(init());
    const call = async (args) => JSON.parse(await b.handleLine(JSON.stringify({ jsonrpc: '2.0', id: 9, method: 'tools/call', params: { name: LOCAL_UPLOAD_TOOL, arguments: args } }))).result;
    const r1 = await call({ post_type: 'works', path: join(fixtures, 'not_image.jpg') });
    assert.equal(r1.isError, true);
    assert.match(r1.content[0].text, /画像ではありません/);
    const r2 = await call({ post_type: 'works', path: join(fixtures, 'synthetic.png'), url: 'https://example.invalid/a.png' });
    assert.equal(r2.isError, true);
    assert.match(r2.content[0].text, /使えない項目/);
    assert.ok(!m.log.some((l) => l.body.includes('prepare-upload')), '拒否したときはサーバーに送らない');
  } finally {
    await m.close();
  }
});

// ---- 接続コード ----------------------------------------------------------------

function runAsync(args, opts = {}) {
  return new Promise((done) => {
    const child = spawn(process.execPath, [bridgeFile, ...args], { ...opts, stdio: ['ignore', 'pipe', 'pipe'] });
    let stdout = '', stderr = '';
    child.stdout.on('data', (d) => { stdout += d; });
    child.stderr.on('data', (d) => { stderr += d; });
    child.on('close', (status) => done({ status, stdout, stderr }));
  });
}

test('接続コード：PHP が作ったコードを読み、種から同じ公開鍵ができ、その署名を PHP が通す', () => {
  const made = JSON.parse(execFileSync(php, [codecCli, 'make-code', JSON.stringify({ url: 'http://stage.example.invalid/wp-json/ship-content/mcp', basic_auth: 'gate:p@ss' })]).toString());
  const c = decodeConnectionCode(`例のサイトの接続コードです。\n${made.code}\nよろしくお願いします`);
  assert.equal(c.url, 'http://stage.example.invalid/wp-json/ship-content/mcp');
  assert.equal(c.keyId, 'k0123456789abcdef');
  assert.equal(c.basicAuth, 'gate:p@ss');
  assert.equal(c.name, 'stage');
  const key = privateKeyFromSeed(c.seed);
  assert.equal(rawPublicKey(createPublicKey(key)).toString('base64'), made.pk);
  const fields = { method: 'POST', host: 'stage.example.invalid', uri: '/wp-json/ship-content/mcp', timestamp: '1790000000', nonce: 'nonce_nonce_nonce_nonc', keyId: c.keyId, sessionId: '', body: Buffer.from('{}') };
  const sig = signRequest(key, fields);
  const res = execFileSync(php, [codecCli, 'verify-request', JSON.stringify({ ...fields, body: '{}', pk: made.pk, sig })]).toString();
  assert.equal(res, 'OK');
});

test('接続コード：ない・壊れた・新しすぎる・値が違うものは読まない', () => {
  const enc = (o) => 'shipmcp1.' + Buffer.from(JSON.stringify(o)).toString('base64url');
  const good = { v: 1, url: 'https://example.invalid/wp-json/ship-content/mcp', key_id: 'k0123456789abcdef', seed: Buffer.alloc(32, 1).toString('base64'), server_key: rawPublicKey(generateKeyPairSync('ed25519').publicKey).toString('base64') };
  assert.ok(decodeConnectionCode(enc(good)));
  assert.throws(() => decodeConnectionCode('コードがありません'), /見つかりません/);
  assert.throws(() => decodeConnectionCode('shipmcp1.' + Buffer.from('not json').toString('base64url')), /壊れて/);
  assert.throws(() => decodeConnectionCode(enc({ ...good, v: 2 })), /最新/);
  assert.throws(() => decodeConnectionCode(enc({ ...good, url: 'ftp://example.invalid/' })), /URL/);
  assert.throws(() => decodeConnectionCode(enc({ ...good, url: 'https://u:p@example.invalid/x' })), /URL/);
  assert.throws(() => decodeConnectionCode(enc({ ...good, key_id: 'abc' })), /鍵 ID/);
  assert.throws(() => decodeConnectionCode(enc({ ...good, seed: 'AAAA' })), /鍵が壊れて/);
  assert.throws(() => decodeConnectionCode(enc({ ...good, server_key: 'AAAA' })), /長さ/);
  assert.throws(() => decodeConnectionCode(enc({ ...good, basic_auth: 'nocolon' })), /Basic/);
});

test('接続コード：setup が設定を 600 で作ってつながり、秘密を画面に出さない', async () => {
  const m = await startMock();
  try {
    const der = m.cfg.privateKey.export({ type: 'pkcs8', format: 'der' });
    const seed = der.subarray(der.length - 32).toString('base64');
    const fields = { v: 1, site: '試験のサイト', name: 'Test Site', url: m.cfg.url.toString(), key_id: m.cfg.keyId, seed, server_key: rawPublicKey(m.cfg.serverKey).toString('base64'), basic_auth: 'gate:pw' };
    const code = 'shipmcp1.' + Buffer.from(JSON.stringify(fields)).toString('base64url');
    const dir = mkdtempSync(join(tmpdir(), 'scm-'));
    const msg = join(dir, 'msg.txt');
    writeFileSync(msg, `試験のサイトの接続コードです。\n${code}\n`);
    const conf = join(dir, 'conf');
    const r = await runAsync(['setup', '--code-file', msg, '--dir', conf]);
    assert.equal(r.status, 0, r.stderr);
    assert.match(r.stdout, /接続できました（使える機能 3 個）/);
    assert.match(r.stdout, /Basic 認証：あり/);
    assert.match(r.stdout, /claude mcp add ship-content-test-site --scope user -e /);
    for (const secret of [seed, 'gate:pw', 'PRIVATE KEY', code]) assert.ok(!r.stdout.includes(secret) && !r.stderr.includes(secret), `画面に出さない：${secret.slice(0, 12)}`);
    const file = join(conf, 'test-site.json');
    assert.equal(statSync(file).mode & 0o777, 0o600);
    const saved = JSON.parse(readFileSync(file, 'utf8'));
    assert.equal(saved.key_id, m.cfg.keyId);
    assert.match(saved.private_key, /PRIVATE KEY/);
    const cfg = loadConfig({ SHIP_MCP_CONFIG: file });
    assert.equal(cfg.keyId, m.cfg.keyId);
    assert.equal(cfg.basic, 'Basic ' + Buffer.from('gate:pw').toString('base64'));
    assert.equal(loadConfig({ SHIP_MCP_CONFIG: file, SHIP_MCP_KEY_ID: 'kffffffffffffffff' }).keyId, 'kffffffffffffffff', '環境変数を優先');
    assert.ok(m.log.every((l) => l.auth === 'Basic ' + Buffer.from('gate:pw').toString('base64')), 'Basic 認証を付けて送る');
    const again = await runAsync(['setup', '--code-file', msg, '--dir', conf]);
    assert.equal(again.status, 0, again.stderr);
    assert.match(again.stdout, /置き換えました/);
    const none = await runAsync(['setup', '--code-file', join(dir, 'nothing.txt'), '--dir', conf]);
    assert.notEqual(none.status, 0);
  } finally {
    await m.close();
  }
});

test('接続コード：SHIP_MCP_CODE だけで設定でき、空の欄の「${…}」は無視し、別のサイトのコードは止める', () => {
  const site = generateKeyPairSync('ed25519');
  const siteB64 = rawPublicKey(site.publicKey).toString('base64');
  const fields = { v: 1, site: '例', name: 'example', url: 'https://example.invalid/wp-json/ship-content/mcp', key_id: 'k0123456789abcdef', seed: Buffer.alloc(32, 7).toString('base64'), server_key: siteB64, basic_auth: 'gate:pw' };
  const code = '例のサイトの接続コードです。\n' + 'shipmcp1.' + Buffer.from(JSON.stringify(fields)).toString('base64url');
  const cfg = loadConfig({ SHIP_MCP_CODE: code, SHIP_MCP_UPLOAD_DIRS: '${user_config.photo_dir}', SHIP_MCP_EXPECT_SERVER_KEY: siteB64 });
  assert.equal(cfg.keyId, 'k0123456789abcdef');
  assert.equal(cfg.url.toString(), 'https://example.invalid/wp-json/ship-content/mcp');
  assert.equal(cfg.basic, 'Basic ' + Buffer.from('gate:pw').toString('base64'));
  assert.equal(rawPublicKey(createPublicKey(cfg.privateKey)).toString('base64'), rawPublicKey(createPublicKey(privateKeyFromSeed(Buffer.alloc(32, 7)))).toString('base64'));
  assert.ok(cfg.uploadRoots.length === 1, '欄が空なら既定（ホーム）');
  const other = rawPublicKey(generateKeyPairSync('ed25519').publicKey).toString('base64');
  assert.throws(() => loadConfig({ SHIP_MCP_CODE: code, SHIP_MCP_EXPECT_SERVER_KEY: other }), /別のサイト用/);
  assert.throws(() => loadConfig({ SHIP_MCP_CODE: '${user_config.connection_code}' }), /足りません/);
});

test('トークン：署名せずにトークンのヘッダーで送り、Basic 認証も付け、署名のない返事を受け取る', async () => {
  const token = 'smcp_k0123456789abcdef_' + 'A'.repeat(42) + 'b';
  const seen = [];
  const server = createServer((req, res) => {
    const chunks = [];
    req.on('data', (c) => chunks.push(c));
    req.on('end', () => {
      seen.push({ token: req.headers['x-ship-mcp-token'], sig: req.headers['x-ship-mcp-signature'], auth: req.headers.authorization });
      if (req.headers['x-ship-mcp-token'] !== token) {
        res.writeHead(401, { 'Content-Type': 'application/json' });
        return res.end(JSON.stringify({ code: 'ship_content_mcp_auth', message: '認証に失敗しました（MCP-002）', data: { status: 401 } }));
      }
      const msg = JSON.parse(Buffer.concat(chunks).toString());
      res.writeHead(200, { 'Content-Type': 'application/json', 'Mcp-Session-Id': 'sess-1' });
      res.end(JSON.stringify({ jsonrpc: '2.0', id: msg.id, result: { protocolVersion: '2025-11-25', serverInfo: { name: 'Mock', version: '1' }, capabilities: {} } }));
    });
  });
  await new Promise((r) => server.listen(0, '127.0.0.1', r));
  try {
    const url = `http://127.0.0.1:${server.address().port}/wp-json/ship-content/mcp`;
    const cfg = loadConfig({ SHIP_MCP_URL: url, SHIP_MCP_TOKEN: token, SHIP_MCP_BASIC_AUTH: ['gate', 'pw'].join(':'), SHIP_MCP_UPLOAD_DIRS: '${user_config.photo_dir}' });
    assert.equal(cfg.token, token);
    assert.equal(cfg.keyId, 'k0123456789abcdef');
    const b = new Bridge(cfg);
    const r = JSON.parse(await b.handleLine(init()));
    assert.equal(r.result.serverInfo.name, 'Mock');
    assert.equal(seen[0].token, token);
    assert.equal(seen[0].sig, undefined, '署名のヘッダーは送らない');
    assert.equal(seen[0].auth, 'Basic ' + Buffer.from('gate:pw').toString('base64'));
    const bad = new Bridge(loadConfig({ SHIP_MCP_URL: url, SHIP_MCP_TOKEN: 'smcp_k0123456789abcdef_' + 'B'.repeat(43) }));
    const e = JSON.parse(await bad.handleLine(init(2)));
    assert.match(JSON.stringify(e), /MCP-002/);
    assert.throws(() => loadConfig({ SHIP_MCP_URL: url, SHIP_MCP_TOKEN: 'abc' }), /トークンの形/);
    assert.throws(() => loadConfig({ SHIP_MCP_TOKEN: token }), /足りません/);
  } finally {
    await new Promise((r) => server.close(r));
  }
});
