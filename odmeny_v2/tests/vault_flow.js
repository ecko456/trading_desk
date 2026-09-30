'use strict';

/*
 * Celý šifrovaný tok proti skutečnému PHP serveru:
 * node vault_flow.js <base-url> <data-dir>
 * Spouští ho tests/test_odmeny.py.
 */
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const [base, dataDir] = process.argv.slice(2);
const realFetch = globalThis.fetch;
let cookie = '';
globalThis.fetch = async (url, options = {}) => {
  const response = await realFetch(new URL(url, base + '/'), { ...options, headers: { ...(options.headers || {}), ...(cookie ? { cookie } : {}) } });
  const set = response.headers.get('set-cookie');
  if (set) {
    const pair = set.split(';')[0];
    cookie = pair.endsWith('=') ? '' : pair;
  }
  return response;
};
const storage = new Map();
globalThis.localStorage = { getItem: key => (storage.has(key) ? storage.get(key) : null), setItem: (key, value) => storage.set(key, String(value)), removeItem: key => storage.delete(key) };
Object.defineProperty(globalThis, 'navigator', { value: { userAgent: 'Mozilla/5.0 (X11; Linux x86_64) Chrome/140 Safari/537.36' }, configurable: true });
require(path.join(__dirname, '..', 'static', 'vault.js'));
const V = globalThis.OdmVault;

const marker = 'Zaměstnanec-' + Math.random().toString(36).slice(2);
const state = { employees: { 'novak|jan': { first: 'Jan', last: marker } }, demo: false, filler: 'x'.repeat(5000) };

async function rejects(promise, status, pattern) {
  try {
    await promise;
  } catch (error) {
    if (status !== undefined) assert.strictEqual(error.status, status, `${error.status} ${error.message}`);
    if (pattern) assert.match(error.message, pattern);
    return error;
  }
  assert.fail('mělo selhat');
}

(async () => {
  // Klíč: 32 znaků, kontrolní bajt odhalí překlep.
  const key = await V.newAccessKey();
  assert.strictEqual(key.length, 32);
  assert.strictEqual(V.formatKey(key).split('-').length, 8);
  const parsed = await V.parseAccessKey(`odmeny:${V.formatKey(key).toLowerCase()}`);
  assert.strictEqual(parsed.key, key);
  const typo = key.slice(0, 5) + (key[5] === 'A' ? 'B' : 'A') + key.slice(6);
  await rejects(V.parseAccessKey(typo), undefined, /překlep/);
  await rejects(V.parseAccessKey(key.slice(0, 20)), undefined, /krátký/);

  // První spuštění: špatný kód, pak správný.
  let st = await V.api('state');
  assert.strictEqual(st.setup_required, true);
  await rejects(V.setup({ token: 'NESEDI-0000', label: 'Hlavní', key }), 401);
  const token = fs.readFileSync(path.join(dataDir, 'setup-token.txt'), 'utf8').trim();
  const created = await V.setup({ token, label: 'Hlavní', key });
  assert.match(created.cardId, /^[a-f0-9]{16}$/);
  assert.ok(!fs.existsSync(path.join(dataDir, 'setup-token.txt')), 'kód se po použití smaže');
  await rejects(V.setup({ token, label: 'Druhý pokus', key }), 409);

  // Uložení a konflikt.
  let loaded = await V.loadData();
  assert.strictEqual(loaded.state, null);
  const saved = await V.saveData(state, 0);
  assert.strictEqual(saved.rev, 1);
  await rejects(V.saveData({ jiny: true }, 0), 409);
  loaded = await V.loadData();
  assert.deepStrictEqual(loaded.state, state);

  // Server drží jen šifru: jméno ani výplň nejsou v databázi čitelné.
  const dump = execFileSync('php', ['-r', 'echo base64_encode(file_get_contents($argv[1]));', path.join(dataDir, 'odmeny.sqlite3')]).toString();
  const raw = Buffer.from(dump, 'base64');
  assert.ok(!raw.includes(Buffer.from(marker)), 'jméno v databázi čitelné');
  const versions = JSON.parse(execFileSync('php', ['-r', '$p=new PDO("sqlite:".$argv[1]);echo json_encode($p->query("SELECT blob FROM versions")->fetchAll(PDO::FETCH_COLUMN));', path.join(dataDir, 'odmeny.sqlite3')]).toString());
  versions.forEach(blob => {
    const bytes = Buffer.from(blob, 'base64');
    assert.ok(!bytes.includes(Buffer.from(marker)), 'jméno ve verzi čitelné');
    assert.ok(!bytes.includes(Buffer.from('xxxxxxxx')), 'výplň ve verzi čitelná');
  });

  // Zamčení: bez relace server nic nevydá, klíč v paměti zmizí.
  await V.lock();
  assert.strictEqual(V.session.dek, null);
  await rejects(V.api('data'), 401);

  // Přihlášení kartičkou.
  await V.loginWithKey(V.formatKey(key));
  assert.deepStrictEqual((await V.loadData()).state, state);

  // Zapamatované zařízení s PINem.
  await V.enrollDevice('482915');
  assert.ok(V.readDevice());
  await V.lock();
  for (let attempt = 1; attempt <= 3; attempt += 1) {
    const error = await rejects(V.unlockWithPin('000111'), 403);
    assert.strictEqual(error.data.remaining, 5 - attempt);
  }
  await V.unlockWithPin('482915');
  assert.deepStrictEqual((await V.loadData()).state, state);
  // Správný PIN vynuluje počítadlo; pět chyb zařízení smaže.
  await V.lock();
  for (let attempt = 1; attempt <= 4; attempt += 1) await rejects(V.unlockWithPin('999000'), 403);
  const gone = await rejects(V.unlockWithPin('999000'), 410);
  assert.ok(gone.data.forget);
  assert.strictEqual(V.readDevice(), null, 'zařízení se zapomene i v prohlížeči');

  // Druhá kartička otevře stejná data; zrušená přestane platit.
  await V.loginWithKey(key);
  const second = await V.createCard('Kolega');
  await V.lock();
  await V.loginWithKey(second.key);
  assert.deepStrictEqual((await V.loadData()).state, state);
  const cards = (await V.api('cards')).items;
  assert.strictEqual(cards.length, 2);

  // Záloha: otevře ji kterákoli kartička, cizí klíč ne.
  const backup = await V.backup(state);
  assert.strictEqual(backup.cards.length, 2);
  const opened = await V.openBackup(backup, key);
  assert.deepStrictEqual(opened.state, state);
  await rejects(V.openBackup(backup, await V.newAccessKey()), undefined, /nepatří/);
  const same = await V.openBackup(backup);
  assert.deepStrictEqual(same.state, state);

  const first = cards.find(card => card.label === 'Hlavní');
  await V.api('card', { method: 'DELETE', query: { id: first.id } });
  await V.lock();
  await rejects(V.loginWithKey(key), 401);
  await V.loginWithKey(second.key);
  // Poslední kartičku zrušit nejde.
  const secondId = (await V.api('cards')).items[0].id;
  await rejects(V.api('card', { method: 'DELETE', query: { id: secondId } }), 409);

  // Historie verzí a obnovení.
  const next = { ...state, extra: 1 };
  await V.saveData(next, (await V.loadData()).rev);
  const history = (await V.api('versions')).items;
  assert.ok(history.length >= 2);
  assert.deepStrictEqual(await V.loadVersion(history[history.length - 1].rev), state);

  console.log('vault flow ok');
})().catch(error => {
  console.error(error);
  process.exit(1);
});
