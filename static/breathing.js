/*
 * Dechové cvičení před seancí: kruh se při nádechu zvětšuje, při zadržení stojí a při
 * výdechu se zmenšuje. Uprostřed běží odpočet fáze, pod kruhem zbývající čas.
 *
 * Časování je čistá funkce (TDBreath.plan, state, boundaries), testuje ho
 * tests/test_breathing.js. Zvuk obstarává static/soundscapes.js (TDSound).
 */
(function (root) {
  'use strict';

  const PHASE_LABELS = { inhale: 'Nádech', hold: 'Zadrž', exhale: 'Výdech' };

  const PATTERNS = {
    calm: {
      label: 'Zklidnění 4–8',
      hint: 'Nádech 4 s, výdech 8 s. Delší výdech zpomalí tep a zklidní.',
      phases: [['inhale', 4], ['exhale', 8]],
    },
    box: {
      label: 'Box 5–5–5–5',
      hint: 'Výdech 5 s, zadržení 5 s, nádech 5 s, zadržení 5 s. Srovná tempo a soustředění.',
      phases: [['exhale', 5], ['hold', 5], ['inhale', 5], ['hold', 5]],
    },
    custom: {
      label: 'Vlastní',
      hint: 'Nádech, zadržení, výdech a zadržení podle sebe.',
      phases: null,
    },
  };

  function phasesFor(key, custom) {
    if (key !== 'custom') return (PATTERNS[key] || PATTERNS.calm).phases;
    // Stejné meze jako na serveru (normalize_breath_preferences).
    const value = (name, fallback, min, max) => Math.min(max, Math.max(min, Math.round(Number(custom?.[name] ?? fallback)) || 0));
    return [
      ['inhale', value('inhale', 4, 2, 10)],
      ['hold', value('hold_in', 0, 0, 10)],
      ['exhale', value('exhale', 6, 2, 12)],
      ['hold', value('hold_out', 0, 0, 10)],
    ].filter(([, seconds]) => seconds > 0);
  }

  /**
   * Plán cvičení: fáze s naplněním „plic“ na začátku a na konci (0 = prázdné, 1 = plné),
   * počet cyklů a celková délka. Cvičení končí vždy celým cyklem.
   */
  function plan(phases, minutes) {
    const clean = phases.filter(([, seconds]) => seconds > 0);
    const cycle = clean.reduce((sum, [, seconds]) => sum + seconds, 0);
    const lastMove = [...clean].reverse().find(([kind]) => kind !== 'hold');
    let level = lastMove && lastMove[0] === 'inhale' ? 1 : 0;
    let offset = 0;
    const items = clean.map(([kind, seconds]) => {
      const from = level;
      const to = kind === 'inhale' ? 1 : kind === 'exhale' ? 0 : level;
      level = to;
      const item = { kind, seconds, from, to, offset };
      offset += seconds;
      return item;
    });
    const cycles = Math.max(1, Math.round((minutes * 60) / cycle));
    return { phases: items, cycle, cycles, total: cycles * cycle, startLevel: items[0].from, endLevel: items[items.length - 1].to };
  }

  // Plynulý začátek i konec pohybu, aby kruh nezačínal ani nekončil škubnutím.
  const ease = progress => 0.5 - 0.5 * Math.cos(Math.PI * Math.min(1, Math.max(0, progress)));

  /** Stav v čase t (sekundy od začátku). Před začátkem a po konci kruh stojí. */
  function state(breathPlan, t) {
    if (t < 0) return { done: false, lead: true, level: breathPlan.startLevel, remaining: Math.ceil(-t), total: breathPlan.total };
    if (t >= breathPlan.total) return { done: true, level: breathPlan.endLevel, cycle: breathPlan.cycles, total: breathPlan.total, left: 0 };
    const cycle = Math.floor(t / breathPlan.cycle);
    const within = t - cycle * breathPlan.cycle;
    const index = breathPlan.phases.findIndex(phase => within < phase.offset + phase.seconds);
    const phase = breathPlan.phases[index];
    const elapsed = within - phase.offset;
    const progress = elapsed / phase.seconds;
    return {
      done: false,
      lead: false,
      cycle: cycle + 1,
      index,
      kind: phase.kind,
      progress,
      remaining: Math.max(1, Math.ceil(phase.seconds - elapsed - 1e-9)),
      level: phase.from + (phase.to - phase.from) * ease(progress),
      left: breathPlan.total - t,
      total: breathPlan.total,
    };
  }

  /** Začátky fází v intervalu [from, to) a konec cvičení; podle nich se plánuje zvuk. */
  function boundaries(breathPlan, from, to) {
    const events = [];
    const firstCycle = Math.max(0, Math.floor(from / breathPlan.cycle) - 1);
    for (let cycle = firstCycle; cycle < breathPlan.cycles; cycle += 1) {
      const start = cycle * breathPlan.cycle;
      if (start >= to) break;
      breathPlan.phases.forEach(phase => {
        const at = start + phase.offset;
        if (at >= from && at < to) events.push({ t: at, kind: phase.kind, seconds: phase.seconds });
      });
    }
    if (breathPlan.total >= from && breathPlan.total < to) events.push({ t: breathPlan.total, kind: 'end', seconds: 0 });
    return events;
  }

  function clock(seconds) {
    const whole = Math.max(0, Math.ceil(seconds));
    return `${Math.floor(whole / 60)}:${String(whole % 60).padStart(2, '0')}`;
  }

  function plural(count, one, few, many) {
    return count === 1 ? one : count >= 2 && count <= 4 ? few : many;
  }

  const api = { PATTERNS, PHASE_LABELS, phasesFor, plan, state, boundaries, clock, ease };
  if (typeof module === 'object' && module.exports) module.exports = api;
  root.TDBreath = api;

  if (typeof document === 'undefined') return;

  /* ---------------------------------------------------------------- rozhraní */

  const LEAD_IN = 3;
  const LOOKAHEAD = 2.5;
  const MINUTES = [1, 2, 3, 5, 10];
  const ui = {
    settings: null,
    recommendation: null,
    session: null,
    audio: null,
    preview: null,
  };
  const el = id => document.getElementById(id);

  function defaults() {
    return { pattern: 'calm', minutes: 3, music: 'ocean', volume: 60, cues: true, custom: { inhale: 4, hold_in: 0, exhale: 6, hold_out: 0 } };
  }

  /**
   * Otevře cvičení. `options` přepíše uložené nastavení jen pro tohle spuštění
   * (třeba po rychlém testu s oranžovou: 4–8 na 3 minuty).
   */
  async function openBreathing(options = {}) {
    let personality = null;
    try { personality = (await root.api('personality')).personality; } catch (error) { personality = null; }
    const saved = personality?.preferences || {};
    const recommendation = personality?.recommendation || { pattern: 'calm', music: 'ocean' };
    const base = defaults();
    // Ukládá se jen to, co člověk sám změní. Zbytek dál sleduje doporučení
    // (to se mění s osobností) nebo jednorázovou volbu po rychlém testu.
    ui.saved = saved;
    ui.touched = new Set();
    ui.recommendation = recommendation;
    ui.settings = {
      pattern: options.pattern || saved.pattern || recommendation.pattern || base.pattern,
      minutes: options.minutes || saved.minutes || base.minutes,
      music: options.music || saved.music || recommendation.music || base.music,
      volume: saved.volume ?? base.volume,
      cues: saved.cues ?? base.cues,
      custom: { ...base.custom, ...(saved.custom || {}) },
    };
    el('breathEyebrow').textContent = options.eyebrow || 'Před seancí';
    const reason = options.reason || [recommendation.pattern_reason, recommendation.music_reason].filter(Boolean).join(' ');
    el('breathReason').textContent = reason;
    el('breathReason').hidden = !reason;
    showScreen('setup');
    renderSetup();
    el('breathDialog').showModal();
  }

  function showScreen(name) {
    el('breathSetup').hidden = name !== 'setup';
    el('breathStage').hidden = name !== 'stage';
    el('breathDone').hidden = name !== 'done';
    el('breathDialog').classList.toggle('is-running', name === 'stage');
  }

  function recommendedTag(on) {
    return on ? '<em class="breath-tag">Doporučeno</em>' : '';
  }

  function renderSetup() {
    const settings = ui.settings;
    const sounds = root.TDSound?.SOUNDSCAPES || {};
    el('breathPatterns').innerHTML = Object.entries(PATTERNS).map(([key, pattern]) => `
      <button type="button" class="breath-option${settings.pattern === key ? ' is-on' : ''}" data-breath-pattern="${key}" aria-pressed="${settings.pattern === key}">
        <strong>${pattern.label}${recommendedTag(ui.recommendation?.pattern === key)}</strong><span>${pattern.hint}</span>
      </button>`).join('');
    el('breathCustom').hidden = settings.pattern !== 'custom';
    ['inhale', 'hold_in', 'exhale', 'hold_out'].forEach(name => { el(`breathCustom_${name}`).value = settings.custom[name]; });
    el('breathMinutes').innerHTML = MINUTES.map(minutes => `<button type="button" class="chip${settings.minutes === minutes ? ' is-on' : ''}" data-breath-minutes="${minutes}">${minutes} min</button>`).join('');
    el('breathSounds').innerHTML = Object.entries(sounds).map(([key, sound]) => `
      <button type="button" class="breath-sound${settings.music === key ? ' is-on' : ''}" data-breath-music="${key}" aria-pressed="${settings.music === key}">
        <i class="breath-sound-icon is-${key}"></i><strong>${sound.label}${recommendedTag(ui.recommendation?.music === key)}</strong><span>${sound.note}</span>
      </button>`).join('') + `
      <button type="button" class="breath-sound${settings.music === 'off' ? ' is-on' : ''}" data-breath-music="off" aria-pressed="${settings.music === 'off'}">
        <i class="breath-sound-icon is-off"></i><strong>Bez hudby</strong><span>Jen tóny při změně fáze, pokud je máš zapnuté</span>
      </button>`;
    el('breathVolume').value = settings.volume;
    el('breathCues').checked = settings.cues;
    updateTotal();
  }

  function currentPlan() {
    return plan(phasesFor(ui.settings.pattern, ui.settings.custom), ui.settings.minutes);
  }

  function updateTotal() {
    const breathPlan = currentPlan();
    el('breathTotal').textContent = `${clock(breathPlan.total)} · ${breathPlan.cycles} ${plural(breathPlan.cycles, 'dech', 'dechy', 'dechů')} po ${breathPlan.cycle} s`;
  }

  /* ---------------------------------------------------------------- zvuk */

  function audioContext() {
    const Context = root.AudioContext || root.webkitAudioContext;
    if (!Context || !root.TDSound) return null;
    if (!ui.audio) {
      const ctx = new Context();
      const master = ctx.createGain();
      master.gain.value = 0;
      const limiter = ctx.createDynamicsCompressor();
      limiter.threshold.value = -10;
      limiter.ratio.value = 6;
      master.connect(limiter).connect(ctx.destination);
      ui.audio = { ctx, master, scape: null };
    }
    if (ui.audio.ctx.state === 'suspended') ui.audio.ctx.resume();
    return ui.audio;
  }

  function volumeGain(volume) {
    return Math.pow(Math.max(0, Math.min(100, volume)) / 100, 2) * 1.4;
  }

  function startMusic(key, volume, fadeSeconds) {
    stopMusic(0.3);
    const audio = audioContext();
    if (!audio) return null;
    const { ctx, master } = audio;
    const now = ctx.currentTime;
    master.gain.cancelScheduledValues(now);
    master.gain.setTargetAtTime(volumeGain(volume), now + 0.05, fadeSeconds / 3);
    if (key && key !== 'off') {
      const bus = ctx.createGain();
      bus.connect(master);
      const scape = root.TDSound.createSoundscape(ctx, key, bus, Math.floor(Math.random() * 1e9));
      scape.bus = bus;
      scape.start(now + 0.05);
      scape.schedule(now + LOOKAHEAD);
      audio.scape = scape;
    }
    startTimer();
    return audio;
  }

  function startTimer() {
    if (!ui.timer) ui.timer = setInterval(tick, 250);
  }

  function stopTimer() {
    clearInterval(ui.timer);
    ui.timer = null;
  }

  function stopMusic(fadeSeconds) {
    const audio = ui.audio;
    if (!audio) return;
    const { ctx, scape } = audio;
    const now = ctx.currentTime;
    if (scape) {
      scape.bus.gain.setTargetAtTime(0, now, Math.max(0.05, fadeSeconds / 4));
      scape.stop(now + fadeSeconds + 0.1);
      setTimeout(() => scape.bus.disconnect(), (fadeSeconds + 0.3) * 1000);
      audio.scape = null;
    }
  }

  function setVolume(volume) {
    if (!ui.audio) return;
    const { ctx, master } = ui.audio;
    master.gain.setTargetAtTime(volumeGain(volume), ctx.currentTime, 0.1);
  }

  function playPreview(key) {
    clearTimeout(ui.preview);
    if (key === 'off') { stopMusic(0.6); return; }
    startMusic(key, ui.settings.volume, 0.8);
    ui.preview = setTimeout(() => { if (!ui.session) { stopMusic(1.5); stopTimer(); } }, 9000);
  }

  /* ---------------------------------------------------------------- průběh */

  function elapsed(session) {
    const now = session.pausedAt ?? performance.now();
    return (now - session.startedAt - session.pausedTotal) / 1000 - LEAD_IN;
  }

  function startSession() {
    clearTimeout(ui.preview);
    const settings = ui.settings;
    const breathPlan = currentPlan();
    ui.session = { plan: breathPlan, startedAt: performance.now(), pausedTotal: 0, pausedAt: null, scheduledUntil: -LEAD_IN, finished: false, frame: 0 };
    if (settings.music !== 'off' || settings.cues) startMusic(settings.music, settings.volume, LEAD_IN);
    else stopMusic(0.3);
    startTimer();
    requestWakeLock();
    el('breathPause').textContent = 'Pauza';
    showScreen('stage');
    saveSettings();
    frame();
  }

  function saveSettings() {
    if (!ui.touched.size) return;
    const preferences = { ...ui.saved };
    ui.touched.forEach(key => { preferences[key] = ui.settings[key]; });
    ui.saved = preferences;
    ui.touched.clear();
    // Karta dýchání v Psychice ukáže novou volbu.
    root.api('personality', { method: 'POST', body: { preferences } })
      .then(() => { if (typeof root.refreshPersonality === 'function') root.refreshPersonality(); })
      .catch(() => {});
  }

  // Plánuje zvuk dopředu podle času cvičení, takže sedí i při přepnutí do jiné záložky
  // (časovač tam běží dál, jen pomaleji; proto rezerva LOOKAHEAD).
  function tick() {
    const audio = ui.audio;
    const session = ui.session;
    if (session && !session.finished && session.pausedAt === null) {
      const now = elapsed(session);
      const horizon = now + LOOKAHEAD;
      if (audio) {
        boundaries(session.plan, session.scheduledUntil, horizon).forEach(event => {
          const at = audio.ctx.currentTime + Math.max(0, event.t - now);
          if (event.kind !== 'end' && audio.scape) audio.scape.breath(event.kind, event.seconds, at);
          if (ui.settings.cues) root.TDSound.playCue(audio.ctx, audio.master, event.kind, at);
        });
      }
      session.scheduledUntil = horizon;
      if (now >= session.plan.total) finishSession();
    }
    if (audio?.scape && audio.ctx.state === 'running') audio.scape.schedule(audio.ctx.currentTime + LOOKAHEAD);
  }

  function frame() {
    const session = ui.session;
    if (!session || session.finished) return;
    const now = elapsed(session);
    const current = state(session.plan, now);
    render(current);
    if (current.done) { finishSession(); return; }
    session.frame = requestAnimationFrame(frame);
  }

  const RING = 2 * Math.PI * 96;

  function render(current) {
    const circle = el('breathCircle');
    circle.style.transform = `scale(${(0.38 + 0.62 * current.level).toFixed(4)})`;
    const stage = el('breathStage');
    const kind = current.lead ? 'lead' : current.done ? 'done' : current.kind;
    if (stage.dataset.phase !== kind) {
      stage.dataset.phase = kind;
      el('breathPhase').textContent = current.lead ? 'Připrav se' : current.done ? 'Hotovo' : PHASE_LABELS[current.kind];
    }
    el('breathCount').textContent = current.lead ? String(current.remaining) : current.done ? '' : String(current.remaining);
    const progress = current.lead || current.done ? 0 : current.progress;
    el('breathRing').style.strokeDashoffset = (RING * (1 - progress)).toFixed(2);
    const left = current.lead ? current.total : current.left;
    const cycle = current.lead ? 0 : current.cycle;
    el('breathMeta').textContent = current.done ? '' : `zbývá ${clock(left)} · dech ${Math.max(1, cycle)} z ${ui.session.plan.cycles}`;
  }

  function finishSession() {
    const session = ui.session;
    if (!session || session.finished) return;
    session.finished = true;
    cancelAnimationFrame(session.frame);
    render(state(session.plan, session.plan.total));
    stopMusic(4);
    setTimeout(() => { if (ui.session === session) stopTimer(); }, 4500);
    releaseWakeLock();
    el('breathDoneText').textContent = `${clock(session.plan.total)} a ${session.plan.cycles} ${plural(session.plan.cycles, 'dech', 'dechy', 'dechů')}. Teď otevři graf a obchoduj jen svůj plán.`;
    setTimeout(() => { if (ui.session === session) showScreen('done'); }, 1800);
  }

  function togglePause() {
    const session = ui.session;
    if (!session || session.finished) return;
    if (session.pausedAt === null) {
      session.pausedAt = performance.now();
      cancelAnimationFrame(session.frame);
      ui.audio?.ctx.suspend();
      el('breathPause').textContent = 'Pokračovat';
      el('breathStage').classList.add('is-paused');
    } else {
      session.pausedTotal += performance.now() - session.pausedAt;
      session.pausedAt = null;
      ui.audio?.ctx.resume();
      el('breathPause').textContent = 'Pauza';
      el('breathStage').classList.remove('is-paused');
      frame();
    }
  }

  function endSession() {
    const session = ui.session;
    if (session) {
      session.finished = true;
      cancelAnimationFrame(session.frame);
    }
    stopTimer();
    ui.session = null;
    clearTimeout(ui.preview);
    if (ui.audio?.ctx.state === 'suspended') ui.audio.ctx.resume();
    stopMusic(1);
    releaseWakeLock();
  }

  let wakeLock = null;
  async function requestWakeLock() {
    try { wakeLock = await navigator.wakeLock?.request('screen'); } catch (error) { wakeLock = null; }
  }
  function releaseWakeLock() {
    try { wakeLock?.release(); } catch (error) { /* nic */ }
    wakeLock = null;
  }

  function bindBreathing() {
    const dialog = el('breathDialog');
    if (!dialog) return;
    dialog.addEventListener('click', event => {
      const pattern = event.target.closest('[data-breath-pattern]');
      if (pattern) { ui.settings.pattern = pattern.dataset.breathPattern; ui.touched.add('pattern'); renderSetup(); return; }
      const minutes = event.target.closest('[data-breath-minutes]');
      if (minutes) { ui.settings.minutes = Number(minutes.dataset.breathMinutes); ui.touched.add('minutes'); renderSetup(); return; }
      const music = event.target.closest('[data-breath-music]');
      if (music) { ui.settings.music = music.dataset.breathMusic; ui.touched.add('music'); renderSetup(); playPreview(ui.settings.music); return; }
      if (event.target.closest('[data-breath-close]')) dialog.close();
    });
    ['inhale', 'hold_in', 'exhale', 'hold_out'].forEach(name => {
      el(`breathCustom_${name}`).addEventListener('input', event => {
        ui.settings.custom[name] = Number(event.target.value);
        ui.touched.add('custom');
        updateTotal();
      });
    });
    el('breathVolume').addEventListener('input', event => { ui.settings.volume = Number(event.target.value); ui.touched.add('volume'); setVolume(ui.settings.volume); });
    el('breathCues').addEventListener('change', event => { ui.settings.cues = event.target.checked; ui.touched.add('cues'); });
    el('breathStart').addEventListener('click', startSession);
    el('breathAgain').addEventListener('click', () => { endSession(); showScreen('setup'); renderSetup(); });
    el('breathPause').addEventListener('click', togglePause);
    el('breathStop').addEventListener('click', () => { endSession(); showScreen('setup'); renderSetup(); });
    dialog.addEventListener('keydown', event => {
      if (event.key === ' ' && !el('breathStage').hidden && event.target.tagName !== 'BUTTON') { event.preventDefault(); togglePause(); }
    });
    dialog.addEventListener('close', endSession);
  }

  root.openBreathing = openBreathing;
  root.bindBreathing = bindBreathing;
})(typeof window !== 'undefined' ? window : globalThis);
