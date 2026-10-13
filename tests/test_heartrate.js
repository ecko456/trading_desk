'use strict';

// Tep ze snímače: čtení zprávy Heart Rate Measurement, RMSSD, průměry po 5 s a upozornění.
const assert = require('node:assert/strict');
const H = require('../static/heartrate.js');

const view = bytes => new DataView(Uint8Array.from(bytes).buffer);

// 8bitový tep bez dalších údajů (Apple Watch přes aplikaci posílá obvykle jen tohle).
assert.deepEqual(H.parseMeasurement(view([0x00, 72])), { bpm: 72, contact: null, rr: [] });
// 16bitový tep, kontakt se kůží, energie a dva R-R intervaly (1/1024 s → ms).
assert.deepEqual(H.parseMeasurement(view([0x1f, 0x48, 0x00, 0x10, 0x00, 0x00, 0x04, 0x33, 0x03])), { bpm: 72, contact: true, rr: [1000, 800] });
assert.equal(H.parseMeasurement(view([0x04, 65])).contact, false, 'kontakt podporovaný, ale pás nesedí');

// RMSSD: střídání 800/850 ms → rozdíl 50 ms, artefakty pryč, málo dat = nic.
const alternating = Array.from({ length: 20 }, (_, i) => (i % 2 ? 850 : 800));
assert.equal(H.rmssd(alternating), 50);
assert.equal(H.rmssd([...alternating, 3000, 100]), 50, 'nesmyslné intervaly se vynechají');
assert.equal(H.rmssd([800, 820, 810]), null);

// Průměry po 5 s: okno se uzavře prvním čtením dalšího okna.
const binner = H.createBinner(5);
const t0 = 1_760_000_000_000;
assert.deepEqual(binner.push(t0, 70), []);
binner.push(t0 + 1000, 72);
binner.push(t0 + 2000, 300); // nesmysl se nepočítá
const closed = binner.push(t0 + 5000, 80);
assert.deepEqual(closed, [{ ts: t0 / 1000, bpm: 71, rmssd: null }]);
assert.deepEqual(binner.flush(), [{ ts: t0 / 1000 + 5, bpm: 80, rmssd: null }]);

// Klid z okna klidového měření.
const calm = Array.from({ length: 24 }, (_, i) => ({ ts: 1000 + 5 * i, bpm: 64 + (i % 3), rmssd: 40 }));
assert.deepEqual(H.baselineOf(calm, 1000, 1115), { bpm: 65, rmssd: 40 });
assert.equal(H.baselineOf(calm.slice(0, 4), 1000, 1115), null, 'málo vzorků');

// Upozornění: jen s naučeným modelem, tep nad hranicí 90 s, pak klid na 10 minut.
const model = { ready: true, threshold: 12, sustain: 90, cooldown: 600 };
const baseline = { bpm: 65, rmssd: 40 };
const series = (from, count, bpm, rmssd = 40) => Array.from({ length: count }, (_, i) => ({ ts: from + 5 * i, bpm: typeof bpm === 'function' ? bpm(i) : bpm, rmssd }));
const high = [...series(0, 40, 66), ...series(200, 20, 80)];
const now = 295;
assert.equal(H.detectAlert({ bins: high, baseline, model: { ...model, ready: false }, now }), null, 'během učení nic');
assert.deepEqual(H.detectAlert({ bins: high, baseline, model, now }), { kind: 'high', delta: 15, bpm: 80 });
assert.equal(H.detectAlert({ bins: high, baseline, model, now, lastAlert: now - 100 }), null, 'po upozornění klid');
assert.equal(H.detectAlert({ bins: high, baseline, model, now: now + 120 }), null, 'bez čerstvých dat nic');
const short = [...series(0, 50, 66), ...series(250, 6, 80)];
assert.equal(H.detectAlert({ bins: short, baseline, model, now: 280 })?.kind, 'spike', 'krátký skok přes hranici');
const mild = [...series(0, 50, 66), ...series(250, 6, 74)];
assert.equal(H.detectAlert({ bins: mild, baseline, model, now: 280 }), null, 'skok pod hranicí');
const lowHrv = series(0, 60, 68, 18);
assert.equal(H.detectAlert({ bins: lowHrv, baseline, model: { ...model, hrv_ratio: 0.6 }, now: 295 })?.kind, 'hrv');
assert.equal(H.detectAlert({ bins: lowHrv, baseline, model, now: 295 }), null, 'bez naučené HRV hranice nic');

console.log('heartrate ok');
