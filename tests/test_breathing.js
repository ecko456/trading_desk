'use strict';

// Dechové cvičení: časování fází, naplnění kruhu a plán zvuku.
const assert = require('node:assert/strict');
const B = require('../static/breathing.js');
const Sound = require('../static/soundscapes.js');

const near = (a, b, message) => assert.ok(Math.abs(a - b) < 1e-9, `${message}: ${a} ≠ ${b}`);

// 4–8: nádech 4 s, výdech 8 s, 5 dechů za minutu.
const calm = B.plan(B.phasesFor('calm'), 5);
assert.equal(calm.cycle, 12);
assert.equal(calm.cycles, 25);
assert.equal(calm.total, 300);
assert.equal(calm.startLevel, 0, 'začíná prázdný a nadechuje se');
assert.equal(B.state(calm, 0).kind, 'inhale');
assert.equal(B.state(calm, 0).remaining, 4);
assert.equal(B.state(calm, 3.2).remaining, 1);
near(B.state(calm, 2).level, 0.5, 'v půlce nádechu je kruh v půlce');
assert.equal(B.state(calm, 4).kind, 'exhale');
assert.equal(B.state(calm, 4).remaining, 8);
near(B.state(calm, 4).level, 1, 'výdech začíná z plného kruhu');
assert.equal(B.state(calm, 12).cycle, 2);
assert.equal(B.state(calm, 300).done, true);

// Box 5–5–5–5 v pořadí, jak si ho uživatel zadal: výdech, zadržení, nádech, zadržení.
const box = B.plan(B.phasesFor('box'), 3);
assert.deepEqual(box.phases.map(phase => phase.kind), ['exhale', 'hold', 'inhale', 'hold']);
assert.equal(box.cycle, 20);
assert.equal(box.cycles, 9);
assert.equal(box.startLevel, 1, 'začíná výdechem, tedy z plného kruhu');
near(B.state(box, 7).level, 0, 'zadržení po výdechu: kruh stojí malý');
near(B.state(box, 9.9).level, 0, 'pořád stojí');
near(B.state(box, 17).level, 1, 'zadržení po nádechu: kruh stojí velký');
assert.equal(B.state(box, 6).kind, 'hold');
assert.equal(B.state(box, 6).remaining, 4);

// Pohyb je plynulý: na začátku a na konci fáze se kruh skoro nehýbe.
assert.ok(B.state(calm, 0.1).level < 0.01);
assert.ok(B.state(calm, 3.9).level > 0.99);

// Před začátkem: příprava, kruh stojí ve výchozí velikosti.
assert.deepEqual([B.state(box, -2.5).lead, B.state(box, -2.5).remaining, B.state(box, -2.5).level], [true, 3, 1]);

// Vlastní rytmus: nulové fáze se vynechají, nádech a výdech mají aspoň 2 s.
const custom = B.phasesFor('custom', { inhale: 4, hold_in: 7, exhale: 8, hold_out: 0 });
assert.deepEqual(custom, [['inhale', 4], ['hold', 7], ['exhale', 8]]);
assert.deepEqual(B.phasesFor('custom', { inhale: 0, exhale: 1 }), [['inhale', 2], ['exhale', 2]]);
assert.deepEqual(B.phasesFor('custom', { inhale: 30, hold_in: 99, exhale: 40, hold_out: -5 }), [['inhale', 10], ['hold', 10], ['exhale', 12]], 'meze jako na serveru');
assert.equal(B.plan(custom, 1).cycles, 3, 'minuta po 19 s = 3 celé dechy');
assert.equal(B.plan(B.phasesFor('calm'), 1).cycles, 5);

// Plán zvuku: začátky fází v okně a konec cvičení, nic dvakrát.
const events = [];
for (let t = -3; t < calm.total + 3; t += 0.25) events.push(...B.boundaries(calm, t, t + 0.25));
assert.equal(events.filter(event => event.kind === 'inhale').length, 25);
assert.equal(events.filter(event => event.kind === 'exhale').length, 25);
assert.deepEqual(events.filter(event => event.kind === 'end').map(event => event.t), [300]);
assert.deepEqual(B.boundaries(box, 0, 21).map(event => `${event.t}:${event.kind}`), ['0:exhale', '5:hold', '10:inhale', '15:hold', '20:exhale']);

assert.equal(B.clock(300), '5:00');
assert.equal(B.clock(61.2), '1:02');

// Kulisy: pět variant, deterministický generátor.
assert.deepEqual(Sound.KEYS, ['ocean', 'drone', 'bowls', 'rain', 'chimes']);
const a = Sound.random(7); const b = Sound.random(7);
assert.deepEqual([a(), a(), a()], [b(), b(), b()]);

console.log('breathing ok');
