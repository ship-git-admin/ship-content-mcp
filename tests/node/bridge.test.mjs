// 中継プログラムの試験：node --test tests/node/
// - PHP（SignatureCodec）と署名を確かめ合う（言語をまたぐ）
// - 模擬サーバー相手に、初期化・セッション・ツール一覧・改ざん・署名なし・使い回し・終了を確かめる

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { execFileSync, spawnSync } from 'node:child_process';
import { generateKeyPairSync, randomBytes, sign as edSign, verify as edVerify } from 'node:crypto';
import { mkdtempSync, statSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  Bridge, canonicalRequest, canonicalResponse, loadConfig, publicKeyFromRaw, rawPublicKey, signRequest, verifyResponse,
} from '../../bridge/ship-content-mcp-bridge.mjs';

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

function startMock({ mode = 'normal' } = {}) {
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
      if (msg.method === 'tools/list') return reply(200, { jsonrpc: '2.0', id: msg.id, result: { tools: [{ name: 'ship-content-list-post-types' }] } });
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
    assert.deepEqual(r2.result.tools.map((t) => t.name), ['ship-content-list-post-types']);
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
