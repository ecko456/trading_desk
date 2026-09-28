'use strict';

/*
 * DiNapoli levely zadané traderem a místa, kde se kryjí.
 *
 * Level má timeframe, typ (retracement F3, F5, F7 nebo expanze COP, OP, XOP),
 * stav (naked / revisited) a cenu. Porovnávají se jen levely stejného timeframu:
 *   konfluence = dva F5 levely do tolerance konfluence,
 *   shoda      = expanze u retracementu do tolerance shody.
 * Tolerance v bodech má každý timeframe v nastavení zvlášť pro oba druhy.
 * Stejný výpočet má server v lib/workspace.php (dn_analysis); test je porovnává.
 */
(function attachDiNapoli(root, factory) {
  const api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  if (root) root.DiNapoli = api;
})(typeof window !== 'undefined' ? window : globalThis, function createDiNapoli() {
  const LEVEL_KINDS = { F3: 'retracement', F5: 'retracement', F7: 'retracement', COP: 'expansion', OP: 'expansion', XOP: 'expansion' };
  const DEFAULT_TOLERANCE = 5;
  const TYPES = ['confluence', 'agreement'];

  function toNumber(value) {
    if (value === null || value === undefined || String(value).trim() === '') return null;
    const number = Number(value);
    return Number.isFinite(number) ? number : null;
  }

  function toleranceFor(timeframes, timeframe, type) {
    const row = (timeframes || []).find(item => String(item?.tf ?? '') === timeframe);
    const value = row ? toNumber(row[type]) : null;
    return value !== null && value >= 0 ? value : DEFAULT_TOLERANCE;
  }

  /** Platné levely: známý typ a cena. index ukazuje na pořadí ve vstupu. */
  function cleanLevels(rows) {
    const levels = [];
    (rows || []).forEach((row, index) => {
      const kind = String(row?.kind ?? '').trim().toUpperCase();
      const price = toNumber(row?.price);
      if (!LEVEL_KINDS[kind] || price === null) return;
      levels.push({
        index,
        timeframe: String(row.timeframe ?? '').trim(),
        kind,
        group: LEVEL_KINDS[kind],
        status: row.status === 'revisited' ? 'revisited' : 'naked',
        price,
        note: String(row.note ?? '').trim(),
      });
    });
    return levels;
  }

  function qualifies(type, a, b) {
    return type === 'confluence' ? a.kind === 'F5' && b.kind === 'F5' : a.group !== b.group;
  }

  function near(a, b, tolerance) {
    return Math.abs(a - b) <= tolerance + 1e-9 * Math.max(1, Math.abs(a), Math.abs(b));
  }

  function analyze(rows, timeframes) {
    const levels = cleanLevels(rows);
    const byTimeframe = new Map();
    levels.forEach((level, position) => {
      if (!byTimeframe.has(level.timeframe)) byTimeframe.set(level.timeframe, []);
      byTimeframe.get(level.timeframe).push(position);
    });

    const clusters = [];
    byTimeframe.forEach((positions, timeframe) => {
      TYPES.forEach(type => {
        const tolerance = toleranceFor(timeframes, timeframe, type);
        const parent = new Map(positions.map(position => [position, position]));
        const find = x => {
          while (parent.get(x) !== x) {
            parent.set(x, parent.get(parent.get(x)));
            x = parent.get(x);
          }
          return x;
        };
        const linked = new Set();
        positions.forEach((first, i) => positions.slice(i + 1).forEach(second => {
          const a = levels[first];
          const b = levels[second];
          if (!qualifies(type, a, b) || !near(a.price, b.price, tolerance)) return;
          parent.set(find(first), find(second));
          linked.add(first);
          linked.add(second);
        }));
        const groups = new Map();
        positions.filter(position => linked.has(position)).forEach(position => {
          const rootPosition = find(position);
          if (!groups.has(rootPosition)) groups.set(rootPosition, []);
          groups.get(rootPosition).push(levels[position]);
        });
        groups.forEach(members => {
          members.sort((x, y) => x.price - y.price || x.index - y.index);
          const prices = members.map(member => member.price);
          clusters.push({
            type,
            timeframe,
            low: Math.min(...prices),
            high: Math.max(...prices),
            tolerance,
            revisited: members.some(member => member.status === 'revisited'),
            members: members.map(member => ({ index: member.index, kind: member.kind, status: member.status, price: member.price })),
          });
        });
      });
    });

    clusters.sort((x, y) => y.high - x.high || y.low - x.low
      || (x.type < y.type ? -1 : x.type > y.type ? 1 : 0)
      || (x.timeframe < y.timeframe ? -1 : x.timeframe > y.timeframe ? 1 : 0)
      || x.members[0].index - y.members[0].index);
    return { levels, clusters };
  }

  return { LEVEL_KINDS, DEFAULT_TOLERANCE, TYPES, analyze, toleranceFor, cleanLevels };
});
