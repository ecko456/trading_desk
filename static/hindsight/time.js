'use strict';

/*
 * Hindsight – čas. Svíčky jsou v UTC, seance se definují v čase burzy (New York)
 * a zobrazují v pražském čase. Letní čas USA a Evropy se mění v jiných týdnech,
 * proto se posun počítá z pravidel obou zemí pro každý okamžik zvlášť.
 * Pravidla (od 2007 v USA, od 1996 v EU):
 *   USA: letní čas od 2. neděle v březnu 2:00 místního do 1. neděle v listopadu 2:00.
 *   EU:  letní čas od poslední neděle v březnu 1:00 UTC do poslední neděle v říjnu 1:00 UTC.
 * Obchodní den ES začíná v 18:00 New York předchozího dne (otevření Globexu).
 */
(function attachTime(root) {
  const DAY = 86400;
  const cache = new Map();

  /** Den v měsíci n-té neděle (n = -1 poslední). */
  function sunday(year, month, n) {
    if (n > 0) {
      const first = new Date(Date.UTC(year, month - 1, 1)).getUTCDay();
      return 1 + ((7 - first) % 7) + (n - 1) * 7;
    }
    const lastDay = new Date(Date.UTC(year, month, 0)).getUTCDate();
    const last = new Date(Date.UTC(year, month - 1, lastDay)).getUTCDay();
    return lastDay - last;
  }

  function transitions(year) {
    if (!cache.has(year)) {
      cache.set(year, {
        // 2:00 EST = 7:00 UTC; 2:00 EDT = 6:00 UTC
        nyStart: Date.UTC(year, 2, sunday(year, 3, 2), 7) / 1000,
        nyEnd: Date.UTC(year, 10, sunday(year, 11, 1), 6) / 1000,
        euStart: Date.UTC(year, 2, sunday(year, 3, -1), 1) / 1000,
        euEnd: Date.UTC(year, 9, sunday(year, 10, -1), 1) / 1000,
      });
    }
    return cache.get(year);
  }

  function yearOf(ts) { return new Date(ts * 1000).getUTCFullYear(); }

  /** Posun New Yorku proti UTC v minutách (-240 léto, -300 zima). */
  function nyOffset(ts) {
    const t = transitions(yearOf(ts));
    return ts >= t.nyStart && ts < t.nyEnd ? -240 : -300;
  }

  /** Posun Prahy proti UTC v minutách (120 léto, 60 zima). */
  function pragueOffset(ts) {
    const t = transitions(yearOf(ts));
    return ts >= t.euStart && ts < t.euEnd ? 120 : 60;
  }

  function pad(n) { return String(n).padStart(2, '0'); }

  function isoDate(ms) {
    const d = new Date(ms);
    return `${d.getUTCFullYear()}-${pad(d.getUTCMonth() + 1)}-${pad(d.getUTCDate())}`;
  }

  function addDays(date, days) {
    const [y, m, d] = date.split('-').map(Number);
    return isoDate(Date.UTC(y, m - 1, d + days));
  }

  /** Datum a minuta dne v New Yorku. */
  function nyParts(ts) {
    const local = (ts + nyOffset(ts) * 60) * 1000;
    const d = new Date(local);
    return { date: isoDate(local), minutes: d.getUTCHours() * 60 + d.getUTCMinutes(), weekday: d.getUTCDay() };
  }

  /** Datum a minuta dne v Praze. */
  function pragueParts(ts) {
    const local = (ts + pragueOffset(ts) * 60) * 1000;
    const d = new Date(local);
    return { date: isoDate(local), minutes: d.getUTCHours() * 60 + d.getUTCMinutes(), weekday: d.getUTCDay(), day: d.getUTCDate(), month: d.getUTCMonth() + 1, year: d.getUTCFullYear() };
  }

  /** Obchodní den svíčky: od 18:00 New York patří už k dalšímu dni. */
  function tradeDate(ts) {
    const p = nyParts(ts);
    return p.minutes >= 18 * 60 ? addDays(p.date, 1) : p.date;
  }

  /** Okamžik (UTC sekundy) pro datum a minutu dne v New Yorku. */
  function nyToUtc(date, minutes) {
    const [y, m, d] = date.split('-').map(Number);
    const guess = Date.UTC(y, m - 1, d, 0, minutes) / 1000;
    let ts = guess - nyOffset(guess) * 60;
    const fix = guess - nyOffset(ts) * 60;
    if (fix !== ts) ts = fix;
    return ts;
  }

  const DEFAULT_SESSIONS = [
    { key: 'asia', label: 'Asie', from: -6 * 60, to: 3 * 60 },
    { key: 'eu', label: 'EU', from: 3 * 60, to: 9 * 60 + 30 },
    { key: 'ny', label: 'NY', from: 9 * 60 + 30, to: 16 * 60 },
  ];

  /** Seance obchodního dne v UTC; minuty jsou vůči půlnoci dne v New Yorku (záporné = předchozí večer). */
  function sessions(date, list = DEFAULT_SESSIONS) {
    return list.map(s => ({
      key: s.key,
      label: s.label,
      start: s.from < 0 ? nyToUtc(addDays(date, -1), 24 * 60 + s.from) : nyToUtc(date, s.from),
      end: nyToUtc(date, s.to),
    }));
  }

  /** Obchodní den: od 18:00 předchozího dne do 17:00 (denní přestávka CME). */
  function dayBounds(date) {
    return { start: nyToUtc(addDays(date, -1), 18 * 60), end: nyToUtc(date, 17 * 60) };
  }

  const WEEKDAYS = ['ne', 'po', 'út', 'st', 'čt', 'pá', 'so'];
  const WEEKDAYS_LONG = ['neděle', 'pondělí', 'úterý', 'středa', 'čtvrtek', 'pátek', 'sobota'];

  function pragueTime(ts) {
    const p = pragueParts(ts);
    return `${pad(Math.floor(p.minutes / 60))}:${pad(p.minutes % 60)}`;
  }

  function dateLabel(date, long = false) {
    const [y, m, d] = date.split('-').map(Number);
    const wd = new Date(Date.UTC(y, m - 1, d)).getUTCDay();
    return long ? `${WEEKDAYS_LONG[wd]} ${d}. ${m}. ${y}` : `${WEEKDAYS[wd]} ${d}. ${m}.`;
  }

  const api = { DAY, DEFAULT_SESSIONS, nyOffset, pragueOffset, nyParts, pragueParts, tradeDate, nyToUtc, sessions, dayBounds, addDays, pragueTime, dateLabel, WEEKDAYS };
  if (typeof module === 'object' && module.exports) module.exports = api;
  root.HsTime = api;
})(typeof window !== 'undefined' ? window : globalThis);
