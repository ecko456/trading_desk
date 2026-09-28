'use strict';

/*
 * Výpočty v private/core.js proti původní aplikaci (hodnoceni-operatoru.html):
 * na datech, kde se opravy neprojeví, musí vyjít totéž. Pak testy samotných oprav.
 * node odmeny/tests/test_core.js
 */
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const ROOT = path.join(__dirname, '..');
const ORIGINAL = path.join(ROOT, '..', 'hodnoceni-operatoru.html');

/** Objekt, který snese jakékoli volání a čtení – zastoupí DOM původní aplikace. */
function stub() {
  const fn = function () { return proxy; };
  const proxy = new Proxy(fn, {
    get(target, key) {
      if (key === Symbol.toPrimitive) return () => '';
      if (key === 'length') return 0;
      return proxy;
    },
    set() { return true; },
    apply() { return proxy; },
    construct() { return proxy; },
  });
  return proxy;
}

function context(extra = {}) {
  return vm.createContext({
    console, crypto: globalThis.crypto, TextDecoder, TextEncoder, Intl, Date, Math, JSON,
    setTimeout, clearTimeout, ...extra,
  });
}

function loadCore() {
  const ctx = context();
  vm.runInContext(fs.readFileSync(path.join(ROOT, 'private', 'core.js'), 'utf8'), ctx, { filename: 'core.js' });
  return ctx;
}

function loadOriginal() {
  const html = fs.readFileSync(ORIGINAL, 'utf8');
  const code = [...html.matchAll(/<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/g)].map(m => m[1]).join('\n');
  const dom = stub();
  const ctx = context({
    document: dom, window: dom, navigator: dom, location: dom, alert() {}, confirm: () => true, prompt: () => null,
    localStorage: { getItem: () => null, setItem() {}, removeItem() {} },
  });
  vm.runInContext(code, ctx, { filename: 'hodnoceni-operatoru.html' });
  return ctx;
}

const run = (ctx, code) => vm.runInContext(code, ctx);

/** Stav bez rozdílů, které opravy záměrně mění: kódy jen ASCII a nejvýš jedna procentní sankce na člověka a měsíc. */
function comparableState(core) {
  const state = JSON.parse(run(core, 'JSON.stringify(demoData())'));
  delete state._demoIssue;
  const ascii = value => (typeof value === 'string' ? value.normalize('NFD').replace(/[̀-ͯ]/g, '') : value);
  Object.values(state.periods).forEach(p => {
    Object.values(p.rows).forEach(r => { r.c = r.c.map(ascii); });
    p.codes = Object.fromEntries(Object.entries(p.codes || {}).map(([c, n]) => [ascii(c), n]));
  });
  state.settings.excused = ascii(state.settings.excused);
  const seen = new Set();
  state.sanctions = state.sanctions.filter(s => {
    if (s.pct == null) return true;
    const id = `${s.key}|${s.period}`;
    if (seen.has(id)) return false;
    seen.add(id);
    return true;
  });
  return state;
}

function summary(ctx, state) {
  ctx.__state = JSON.stringify(state);
  return JSON.parse(run(ctx, `
    S = JSON.parse(__state); migrate(S); _prodIdx = null;
    JSON.stringify(allRows().map(r => ({
      key: r.e.key, total: r.total, narok: r.narok, max: r.max, base: r.L.base, level: r.L.n,
      att: r.attEff, pen: r.pen, pct: r.pctSum, legacy: r.legacy, adj: r.adj, kafe: !!r.kafe, lost: !!r.lost, capped: !!r.capped,
      days: r.att && r.att.found ? r.att.workDays : null, hours: r.att && r.att.found ? r.att.totalHours : null,
      wk: r.att && r.att.found ? r.att.wkDays : null, prod: r.prod ? r.prod.total : null,
    })))`));
}

const tests = [];
const test = (name, fn) => tests.push([name, fn]);

test('výpočty sedí s původní aplikací', () => {
  if (!fs.existsSync(ORIGINAL)) return 'přeskočeno (chybí hodnoceni-operatoru.html)';
  const core = loadCore();
  const original = loadOriginal();
  const state = comparableState(core);
  assert.ok(Object.keys(state.employees).length >= 5, 'ukázková data mají lidi');
  const expected = summary(original, state);
  const actual = summary(core, state);
  assert.ok(expected.some(r => r.pen > 0) && expected.some(r => r.att !== 0) && expected.some(r => r.kafe), 'data pokrývají sankce, docházku i Kafe');
  assert.deepStrictEqual(actual, expected);
  // Jiný měsíc a ruční úpravy: pořád totéž.
  state.adjust[state.current] = { [Object.keys(state.employees)[0]]: 2, [Object.keys(state.employees)[1]]: -1 };
  state.settings.absenceCutoff = 3;
  assert.deepStrictEqual(summary(core, state), summary(original, state));
  return null;
});

test('import docházky dává stejné období jako původní aplikace', () => {
  if (!fs.existsSync(ORIGINAL)) return 'přeskočeno (chybí hodnoceni-operatoru.html)';
  const core = loadCore();
  const original = loadOriginal();
  const head = ['Jméno', 'Příjmení', '', '', '', ...Array.from({ length: 30 }, (_, i) => `${i + 1}.9.2026`)];
  const rows = [['Jan', 'Novák', 1, 'A', '', ...Array.from({ length: 30 }, (_, i) => (i % 7 === 5 || i % 7 === 6 ? '' : i === 9 ? 'D' : '7,5'))],
    ['Eva', 'Malá', 2, 'A', '', ...Array.from({ length: 30 }, (_, i) => (i % 7 === 5 ? '8' : i % 7 === 6 ? '' : '6'))],
    ['Celkem', '', '', '', '', ...Array(30).fill(100)]];
  const aoa = JSON.stringify([head, ...rows]);
  const build = ctx => { ctx.__aoa = aoa; return JSON.parse(run(ctx, 'JSON.stringify((({ok,err,period,warn}) => ({ok,err,warn,period: period && {...period, at: 0}}))(buildPeriod(JSON.parse(__aoa), "t.csv")))')); };
  const expected = build(original);
  assert.ok(expected.ok, expected.err);
  assert.deepStrictEqual(build(core), expected);
  return null;
});

test('OČR i OCR znamená totéž (oprava diakritiky)', () => {
  const core = loadCore();
  run(core, 'S = blank(); S.settings.excused = "OČR, D";');
  const aoa = [['Jméno', 'Příjmení', '', '', '', ...Array.from({ length: 30 }, (_, i) => `${i + 1}.9.2026`)],
    ['Jan', 'Novák', '', '', '', ...Array.from({ length: 30 }, (_, i) => (i === 0 ? 'OCR' : i === 1 ? 'očr' : i === 2 ? 'X' : '7,5'))]];
  core.__aoa = JSON.stringify(aoa);
  const cells = JSON.parse(run(core, `
    const res = buildPeriod(JSON.parse(__aoa), "t.csv");
    S.periods[res.period.id] = res.period; S.current = res.period.id;
    JSON.stringify(attendance(res.period, Object.keys(res.period.rows)[0]).cells.slice(0, 3).map(c => c.isEx))`));
  assert.deepStrictEqual(cells, [true, true, false]);
  return null;
});

test('každá sankce strhne aspoň jeden tabák', () => {
  const core = loadCore();
  assert.strictEqual(run(core, 'sanDeduct(10, 20, 2)'), 2, 'dvě sankce po 10 % z 10 = aspoň 2');
  assert.strictEqual(run(core, 'sanDeduct(10, 10, 1)'), 1);
  assert.strictEqual(run(core, 'sanDeduct(10, 100, 2)'), 10, 'nikdy víc než nárok');
  assert.strictEqual(run(core, 'sanDeduct(0, 30, 1)'), 0, 'z nuly není co strhnout');
  assert.strictEqual(run(core, 'sanDeduct(8, 30, 1)'), 2, '30 % z 8 = 2,4 → 2');
  return null;
});

test('sanitizeState zahodí podvržená data', () => {
  const core = loadCore();
  core.__evil = JSON.stringify({
    positions: [{ id: '"><img src=x onerror=alert(1)>', name: 'Zlá', levels: [] }, { id: 'p_ok', name: 'x'.repeat(500), max: '9999', levels: [{ name: 'A', tabaky: '1e9' }, {}, {}, {}], penalty: [{ at: -5, t: 'abc' }] }],
    employees: { '__proto__': { first: 'X', last: 'Y' }, 'novak|jan': { first: 'Jan', last: 'Novák', positionId: '"><img', level: 99 }, 'bad': 'text' },
    periods: { '2026-09': { days: ['2026-09-01', '<b>', '2025-01-01'], rows: { 'novak|jan': { first: 'Jan', last: 'Novák', h: ['99', 'x'], c: [null, '<script>'] } } }, 'zlé': {} },
    sanctions: [{ key: 'novak|jan', period: '2026-09', pct: 70, name: 'x' }, { key: 'novak|jan', period: '"><', pct: 10 }, 'x'],
    adjust: { '2026-09': { 'novak|jan': 1e9, '__proto__': 5 }, '<x>': { a: 1 } },
    issued: { '2026-09': { 'novak|jan': { at: 'x', tabaky: -5, kafe: 'ano' } } },
    settings: { shift: 1e9, hiddenCols: ['name', 'days', '<x>'], lockMinutes: 0 },
    current: '"><script>',
  });
  const out = JSON.parse(run(core, 'JSON.stringify(sanitizeState(JSON.parse(__evil)))'));
  assert.deepStrictEqual(out.positions.map(p => p.id), ['p_ok']);
  assert.strictEqual(out.positions[0].name.length, 80);
  assert.strictEqual(out.positions[0].max, 200);
  assert.strictEqual(out.positions[0].levels[0].tabaky, 100);
  assert.deepStrictEqual(out.positions[0].penalty, [{ at: 1, t: 0 }]);
  assert.deepStrictEqual(Object.keys(out.employees), ['novak|jan']);
  assert.strictEqual(out.employees['novak|jan'].positionId, null);
  assert.strictEqual(out.employees['novak|jan'].level, null);
  assert.deepStrictEqual(Object.keys(out.periods), ['2026-09']);
  assert.deepStrictEqual(out.periods['2026-09'].days, ['2026-09-01']);
  assert.deepStrictEqual(out.periods['2026-09'].rows['novak|jan'].h, [24]);
  assert.strictEqual(out.sanctions.length, 1);
  assert.strictEqual(out.adjust['2026-09']['novak|jan'], 100);
  assert.ok(!Object.prototype.hasOwnProperty.call(out.adjust['2026-09'], '__proto__'));
  assert.ok(!('<x>' in out.adjust));
  assert.deepStrictEqual(out.issued['2026-09']['novak|jan'], { at: 0, tabaky: 0, kafe: true });
  assert.strictEqual(out.settings.shift, 24);
  assert.deepStrictEqual(out.settings.hiddenCols, ['days']);
  assert.strictEqual(out.settings.lockMinutes, 1);
  assert.strictEqual(out.current, '2026-09');
  assert.strictEqual(run(core, '({}).first'), undefined, 'prototyp zůstal čistý');
  return null;
});

test('CSV v UTF-8 i Windows-1250', () => {
  const core = loadCore();
  core.__utf = new TextEncoder().encode('Jméno;Šťastný;OČR');
  core.__bom = new Uint8Array([0xef, 0xbb, 0xbf, ...new TextEncoder().encode('Žluťoučký')]);
  core.__cp = new Uint8Array([0x8a, 0x9d, 0x61, 0x73, 0x74, 0x6e, 0xfd]); // "Šťastný" ve Windows-1250
  assert.strictEqual(run(core, 'decodeText(__utf.buffer)'), 'Jméno;Šťastný;OČR');
  assert.strictEqual(run(core, 'decodeText(__bom.buffer)'), 'Žluťoučký');
  assert.strictEqual(run(core, 'decodeText(__cp.buffer)'), 'Šťastný');
  return null;
});

test('export nespustí vzorec v Excelu', () => {
  const core = loadCore();
  assert.strictEqual(run(core, 'safeCell("=HYPERLINK(\\"http://x\\")")'), '\'=HYPERLINK("http://x")');
  assert.strictEqual(run(core, 'safeCell("+420")'), '\'+420');
  assert.strictEqual(run(core, 'safeCell("-5")'), '\'-5');
  assert.strictEqual(run(core, 'safeCell("@SUM(A1)")'), '\'@SUM(A1)');
  assert.strictEqual(run(core, 'safeCell("Novák")'), 'Novák');
  assert.strictEqual(run(core, 'safeCell(-5)'), -5);
  return null;
});

let failed = 0;
for (const [name, fn] of tests) {
  try {
    const note = fn();
    console.log(`ok   ${name}${note ? ` – ${note}` : ''}`);
  } catch (error) {
    failed += 1;
    console.log(`FAIL ${name}\n${error.stack}`);
  }
}
if (failed) process.exit(1);
console.log('core ok');
