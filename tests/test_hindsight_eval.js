'use strict';

// Hindsight – pravidla vyhodnocení zón, biasu a potenciálních obchodů.
const assert = require('node:assert/strict');
const E = require('../static/hindsight/evaluate.js');

const cfg = E.config({});
let clock = 1790000000;
/** Svíčky z [open, high, low, close]; čas po 5 minutách. */
const bars = rows => rows.map(([open, high, low, close]) => ({ time: (clock += 300), open, high, low, close }));
const support = { price_low: 100, price_high: 102, type: 'support' };

assert.deepEqual(E.config({ bounce: '-5', breakBy: 'x', window: 'rth', sameBar: 'target', neutralBand: 3 }), { window: 'rth', bounce: 0.25, breakBy: 4, includeLater: false, neutralBand: 3, sameBar: 'target' });

// Shora do zóny, odraz o 8 bodů od horního okraje = držela.
let day = bars([[110, 111, 106, 107], [107, 107, 101, 103], [103, 106, 102.5, 105], [105, 110.5, 104, 110]]);
let result = E.zoneDay(day, 0, day.length - 1, support, cfg, null);
assert.equal(result.result, 'held');
assert.equal(result.side, 'above');
assert.equal(result.touch, 1);
assert.equal(result.decide, 3);

// Close pod zónou o víc než 4 body dřív než odraz = proražená.
day = bars([[110, 111, 106, 107], [107, 107, 101, 103], [103, 104, 95, 95.5], [95.5, 111, 95, 110]]);
result = E.zoneDay(day, 0, day.length - 1, support, cfg, null);
assert.equal(result.result, 'broken');
assert.equal(result.decide, 2);

// Close jen 3 body pod zónou průraz není; odraz ve stejné svíčce jako průraz = držela (maximum je před close).
day = bars([[110, 111, 106, 107], [107, 107, 101, 103], [103, 104, 97, 97.5], [97.5, 110, 94, 95]]);
assert.equal(E.zoneDay(day, 0, day.length - 1, support, cfg, null).result, 'held');

// Svíčka doteku se počítá jen svým close: průraz hned při doteku.
day = bars([[110, 111, 106, 107], [107, 115, 90, 95]]);
assert.equal(E.zoneDay(day, 0, day.length - 1, support, cfg, null).result, 'broken');

// Dotek bez rozhodnutí a nezasažená zóna.
day = bars([[110, 111, 106, 107], [107, 107, 101, 103], [103, 105, 102, 104]]);
assert.equal(E.zoneDay(day, 0, day.length - 1, support, cfg, null).result, 'undecided');
day = bars([[110, 111, 106, 107], [107, 109, 104, 108]]);
assert.equal(E.zoneDay(day, 0, day.length - 1, support, cfg, null).result, 'untouched');

// Zdola do supportu: zóna se chová jako resistance (odmítnutí dolů = držela).
day = bars([[95, 96, 94, 95], [95, 101, 95, 99], [99, 99.5, 91, 92]]);
result = E.zoneDay(day, 0, day.length - 1, support, cfg, null);
assert.deepEqual([result.side, result.result], ['below', 'held']);

// Jen RTH: dotek před RTH se nepočítá.
day = bars([[110, 111, 106, 107], [107, 107, 101, 103], [103, 111, 103, 110], [110, 111, 108, 109], [109, 109, 101, 101.5], [101.5, 101.5, 94, 95]]);
const rth = { start: day[3].time, end: day[5].time + 300 };
assert.equal(E.zoneDay(day, 0, day.length - 1, support, cfg, null).result, 'held');
assert.equal(E.zoneDay(day, 0, day.length - 1, support, E.config({ window: 'rth' }), rth).result, 'broken');

// Vlastní hranice odrazu a průrazu.
day = bars([[110, 111, 106, 107], [107, 107, 101, 103], [103, 106, 102.5, 105]]);
assert.equal(E.zoneDay(day, 0, day.length - 1, support, E.config({ bounce: 4 }), null).result, 'held');

// Bias proti RTH.
assert.deepEqual(E.biasDay({ open: 100, close: 110, complete: true }, 'long', cfg), { result: 'right', move: 10 });
assert.equal(E.biasDay({ open: 100, close: 110, complete: true }, 'short', cfg).result, 'wrong');
assert.equal(E.biasDay({ open: 100, close: 100, complete: true }, 'long', cfg).result, 'wrong');
assert.equal(E.biasDay({ open: 100, close: 110, complete: false }, 'long', cfg), null, 'neúplné RTH');
assert.equal(E.biasDay({ open: 100, close: 103, complete: true }, 'neutral', cfg), null, 'neutral se bez pásma nehodnotí');
assert.equal(E.biasDay({ open: 100, close: 103, complete: true }, 'neutral', E.config({ neutralBand: 5 })).result, 'right');
assert.equal(E.biasDay({ open: 100, close: 108, complete: true }, 'neutral', E.config({ neutralBand: 5 })).result, 'wrong');

// Potenciální obchod: vstup, pak cíl dřív než stop.
const long = { direction: 'long', entry: 100, stop: 96, target: 108 };
day = bars([[103, 104, 101, 102], [102, 102, 99.5, 101], [101, 105, 100, 104], [104, 109, 103, 108]]);
result = E.idea(day, 0, day.length - 1, long, cfg);
assert.deepEqual([result.state, result.fill, result.end, result.r, result.points], ['tp', 1, 3, 2, 8]);
// Stop i cíl v jedné svíčce: podle pravidla.
day = bars([[100, 101, 99, 100], [100, 109, 95, 100]]);
assert.deepEqual([E.idea(day, 0, 1, long, cfg).state, E.idea(day, 0, 1, long, cfg).ambiguous], ['sl', true]);
assert.equal(E.idea(day, 0, 1, long, E.config({ sameBar: 'target' })).state, 'tp');
// Vstup nezasažen a konec dne.
day = bars([[103, 105, 101, 104]]);
assert.equal(E.idea(day, 0, 0, long, cfg).state, 'unfilled');
day = bars([[100, 101, 99, 100], [100, 103, 99, 102]]);
result = E.idea(day, 0, 1, long, cfg);
assert.deepEqual([result.state, result.points, result.r], ['open', 2, 0.5]);
// Short.
day = bars([[100, 100.5, 99, 99.5], [99.5, 99.5, 91, 92]]);
assert.equal(E.idea(day, 0, 1, { direction: 'short', entry: 100, stop: 104, target: 92 }, cfg).state, 'tp');

// Souhrn.
const summary = E.summarize({
  zones: [{ type: 'support', result: 'held' }, { type: 'support', result: 'broken' }, { type: 'resistance', result: 'held' }, { type: 'resistance', result: 'untouched' }],
  bias: [{ bias: 'long', result: 'right' }, { bias: 'short', result: 'wrong', changed: true }, { bias: '', result: null }, { bias: 'neutral', result: null }],
  ideas: [
    { outcome: 'missed', state: 'tp', r: 2, points: 16 },
    { outcome: 'skipped', state: 'sl', r: -1, points: -8 },
    { outcome: 'taken', state: 'tp', r: 2, points: 12, tradeR: 1.5 },
    { outcome: '', state: 'unfilled' },
  ],
  trades: [{ result_r: 1.5, result_usd: 600 }, { result_r: -1, result_usd: -400 }, { result_r: null, result_usd: null }],
});
assert.equal(summary.zones.holdRate, 2 / 3);
assert.deepEqual([summary.zones.touched, summary.zones.byType.support.held, summary.zones.byType.resistance.untouched], [3, 1, 1]);
assert.deepEqual([summary.bias.scored, summary.bias.right, summary.bias.rate, summary.bias.none, summary.bias.changed, summary.bias.neutral.days], [2, 1, 0.5, 1, 1, 1]);
assert.deepEqual(summary.ideas.left, { r: 2, points: 16, count: 1 });
assert.deepEqual(summary.ideas.saved, { r: 1, count: 1 });
assert.deepEqual(summary.ideas.taken, { potential: 2, realized: 1.5, linked: 1 });
assert.deepEqual([summary.ideas.tp, summary.ideas.sl, summary.ideas.unfilled, summary.ideas.byOutcome.missed.tp], [2, 1, 1, 1]);
assert.deepEqual(summary.trades, { count: 3, r: 0.5, usd: 200, withR: 2 });

console.log('hindsight eval: ok');
