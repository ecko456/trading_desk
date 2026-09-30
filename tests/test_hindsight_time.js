'use strict';

// Hindsight – čas: seance v New Yorku, zobrazení v Praze, letní čas obou zemí.
const assert = require('node:assert/strict');
const T = require('../static/hindsight/time.js');

const utc = text => Date.parse(text) / 1000;
const iso = ts => new Date(ts * 1000).toISOString().slice(0, 16);

// Posuny proti Intl pro každou hodinu 2020–2030 (včetně přechodových nocí).
function intlOffset(ts, zone) {
  const parts = Object.fromEntries(new Intl.DateTimeFormat('en-US', {
    timeZone: zone, hourCycle: 'h23', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit',
  }).formatToParts(new Date(ts * 1000)).map(part => [part.type, part.value]));
  const local = Date.UTC(+parts.year, +parts.month - 1, +parts.day, +parts.hour, +parts.minute) / 1000;
  return Math.round((local - ts) / 60);
}
for (let ts = utc('2020-01-01T00:00:00Z'); ts < utc('2031-01-01T00:00:00Z'); ts += 1800) {
  assert.equal(T.nyOffset(ts), intlOffset(ts, 'America/New_York'), `New York ${iso(ts)}`);
  assert.equal(T.pragueOffset(ts), intlOffset(ts, 'Europe/Prague'), `Praha ${iso(ts)}`);
}

// Seance v pražském čase, i v týdnech, kdy USA a Evropa mají jiný čas.
function prague(date) {
  return Object.fromEntries(T.sessions(date).map(session => [session.key, `${T.pragueTime(session.start)}-${T.pragueTime(session.end)}`]));
}
assert.deepEqual(prague('2026-03-06'), { asia: '00:00-09:00', eu: '09:00-15:30', ny: '15:30-22:15' }, 'oba zimní čas');
assert.deepEqual(prague('2026-03-10'), { asia: '23:00-08:00', eu: '08:00-14:30', ny: '14:30-21:15' }, 'USA letní, Evropa zimní');
assert.deepEqual(prague('2026-03-30'), { asia: '00:00-09:00', eu: '09:00-15:30', ny: '15:30-22:15' }, 'oba letní čas');
assert.deepEqual(prague('2026-10-27'), { asia: '23:00-08:00', eu: '08:00-14:30', ny: '14:30-21:15' }, 'Evropa už zimní, USA ještě letní');
assert.deepEqual(prague('2026-11-03'), { asia: '00:00-09:00', eu: '09:00-15:30', ny: '15:30-22:15' }, 'oba zimní čas');

// Den přechodu na letní čas v USA (neděle 8. 3. 2026): obchodní den 9. 3. začíná v 18:00 EDT.
assert.equal(iso(T.dayBounds('2026-03-09').start), '2026-03-08T22:00');
assert.equal(iso(T.sessions('2026-03-09')[2].start), '2026-03-09T13:30');
assert.equal(iso(T.dayBounds('2026-11-02').start), '2026-11-01T23:00');

// Obchodní den svíčky: od 18:00 New York patří k dalšímu dni.
assert.equal(T.tradeDate(utc('2026-09-27T22:00:00Z')), '2026-09-28', 'neděle 18:00 ET = pondělí');
assert.equal(T.tradeDate(utc('2026-09-28T20:55:00Z')), '2026-09-28');
assert.equal(T.tradeDate(utc('2026-09-28T22:00:00Z')), '2026-09-29');
assert.equal(T.tradeDate(utc('2026-09-25T20:55:00Z')), '2026-09-25', 'pátek');
assert.deepEqual(T.dayBounds('2026-09-28'), { start: utc('2026-09-27T22:00:00Z'), end: utc('2026-09-28T21:00:00Z') });

// Zobrazení v Praze: noc změny času má 02:30 dvakrát, ale časová osa zůstává v UTC.
assert.equal(T.pragueTime(utc('2026-10-25T00:30:00Z')), '02:30');
assert.equal(T.pragueTime(utc('2026-10-25T01:30:00Z')), '02:30');
assert.equal(T.dateLabel('2026-09-28'), 'po 28. 9.');
assert.equal(T.dateLabel('2026-09-28', true), 'pondělí 28. 9. 2026');
assert.equal(T.addDays('2026-12-31', 1), '2027-01-01');

console.log('hindsight time: ok');
