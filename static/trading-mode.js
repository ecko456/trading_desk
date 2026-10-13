/*
 * Trading mode: přepínač nahoře. Zapnutí projde třemi kroky (zdroj tepu, rychlý test,
 * dvě minuty klidového měření) a pak sleduje tep po celou seanci. Upozornění na vysoký
 * tep se zapnou až po 15 ohodnocených obchodech s tepem (do té doby se model učí).
 * Server: lib/heart.php, výpočty a Bluetooth: static/heartrate.js.
 */

const TM_TEST = window.TD_HEART_TEST || {};
const TM_CALM_SECONDS = TM_TEST.calmSeconds || 120;
const TM_BIN_SECONDS = TM_TEST.binSeconds || 5;
const TM_UPLOAD_MS = TM_TEST.uploadMs || 20000;
const TM_REFRESH_MS = 120000;

const tm = {
  state: null,
  session: null,
  sensor: null,
  binner: null,
  bins: [],
  pending: [],
  lastReading: null,
  baseline: null,
  source: 'ble',
  step: 'source',
  checkId: null,
  calm: null,
  lastAlert: null,
  alertId: null,
  alertKind: '',
  detailId: null,
  uploading: false,
  timers: [],
};

const TM_STRENGTH = {
  strong: 'Silná souvislost',
  medium: 'Prokazatelná souvislost',
  weak: 'Naznačuje se',
  none: 'Zatím bez souvislosti',
};
const TM_SOURCE_LABEL = { ble: 'Snímač', watch: 'Apple Watch', none: 'Bez tepu', import: 'Ze souboru' };
const TM_BAND = { green: 'Zelená', amber: 'Oranžová', red: 'Červená' };

function tmClock(ts) {
  return new Date(ts * 1000).toLocaleTimeString('cs-CZ', { hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Prague' });
}
function tmDuration(minutes) {
  const value = Math.max(0, Math.round(minutes));
  return value >= 60 ? `${Math.floor(value / 60)} h ${String(value % 60).padStart(2, '0')} min` : `${value} min`;
}
function tmSigned(value, digits = 0) {
  if (value === null || value === undefined || Number.isNaN(Number(value))) return '—';
  const number = Number(value);
  const text = Math.abs(number).toFixed(digits).replace('.', ',');
  if (Number(text.replace(',', '.')) === 0) return `±${text}`;
  return `${number > 0 ? '+' : '−'}${text}`;
}
function tmNumber(value, digits = 0) {
  return value === null || value === undefined ? '—' : Number(value).toFixed(digits).replace('.', ',');
}
function tmPlural(count, one, few, many) {
  return count === 1 ? one : count >= 2 && count <= 4 ? few : many;
}

/* ---------------------------------------------------------------- stav ze serveru */

async function tmRefresh() {
  try {
    tm.state = await api('heart_state');
  } catch (error) {
    return;
  }
  if (tm.state.imported?.stored) toast(`Doplnil jsem tep z Apple Watch (${tm.state.imported.stored} ${tmPlural(tm.state.imported.stored, 'hodnota', 'hodnoty', 'hodnot')}).`);
  const active = tm.state.active;
  if (active && (!tm.session || tm.session.id !== active.id)) {
    tm.session = active;
    tm.source = active.source;
    if (active.baseline_bpm != null) tm.baseline = { bpm: active.baseline_bpm, rmssd: active.baseline_rmssd };
    tmStartLoops();
    if (active.source === 'ble' && (!tm.sensor || tm.sensor.state === 'off')) tmReconnectQuietly();
  } else if (active) {
    tm.session = active;
    if (active.baseline_bpm != null && !tm.baseline) tm.baseline = { bpm: active.baseline_bpm, rmssd: active.baseline_rmssd };
  } else if (!active && tm.session && !tm.calm) {
    tmStopLoops();
    tm.session = null;
  }
  tmRenderToggle();
  tmRenderPsyche();
}

/* ---------------------------------------------------------------- snímač */

function tmEnsureSensor() {
  if (tm.sensor || !window.TDHeartSensor) return tm.sensor;
  tm.sensor = new TDHeartSensor();
  tm.binner = TDHeartMath.createBinner(TM_BIN_SECONDS);
  tm.sensor.addEventListener('reading', event => tmOnReading(event.detail));
  tm.sensor.addEventListener('status', () => { tmRenderSensorState(); tmRenderToggle(); tmRenderPanel(); });
  return tm.sensor;
}

async function tmConnectSensor() {
  const sensor = tmEnsureSensor();
  if (!sensor) return;
  const button = $('#tmConnect');
  if (button) button.disabled = true;
  try {
    await sensor.connect();
    if (tm.session && tm.session.source !== 'ble') {
      tm.session = (await api('heart_session', { method: 'POST', body: { op: 'source', id: tm.session.id, source: 'ble', device: sensor.name } })).session;
      tm.source = 'ble';
    }
  } catch (error) {
    if (error && error.name === 'NotFoundError') toast('Výběr snímače byl zrušen. Zkontroluj, že snímač vysílá (pás je navlhčený a na těle, aplikace v iPhonu běží).', 'error');
    else toast(error.message || 'Snímač se nepodařilo připojit.', 'error');
  } finally {
    if (button) button.disabled = false;
    tmRenderSensorState();
    tmUpdateStartButtons();
  }
}

async function tmReconnectQuietly() {
  const sensor = tmEnsureSensor();
  if (!sensor) return;
  try { await sensor.reconnectKnown(); } catch (error) { /* klepnutí na Znovu připojit */ }
  tmRenderToggle();
}

function tmOnReading(reading) {
  tm.lastReading = reading;
  const closed = tm.binner.push(reading.at, reading.bpm, reading.rr || []);
  closed.forEach(bin => {
    tm.bins.push(bin);
    tm.pending.push([bin.ts, bin.bpm, bin.rmssd]);
  });
  const horizon = Date.now() / 1000 - 3600;
  if (tm.bins.length > 800) tm.bins = tm.bins.filter(bin => bin.ts >= horizon);
  if (tm.pending.length > 2000) tm.pending = tm.pending.slice(-2000);
  if (closed.length) tmCheckAlert();
  tmRenderLive();
}

function tmRenderSensorState() {
  const box = $('#tmSensorState');
  if (!box) return;
  const sensor = tm.sensor;
  const state = sensor ? sensor.state : 'off';
  const labels = { off: 'Nepřipojeno', connecting: 'Připojuji…', connected: 'Připojeno', lost: 'Spojení ztraceno, zkouším znovu' };
  const bpm = tm.lastReading && state === 'connected' ? ` · ${tm.lastReading.bpm} tepů/min` : '';
  box.className = `tm-sensor-state is-${state}`;
  box.innerHTML = `<span class="status-dot"></span><span>${escapeHtml(labels[state] || state)}${state !== 'off' ? ` · ${escapeHtml(sensor.name)}` : ''}${escapeHtml(bpm)}${sensor?.battery != null ? ` · baterie ${sensor.battery} %` : ''}</span>`;
}

/* ---------------------------------------------------------------- odesílání a smyčky */

async function tmUpload(keepalive = false) {
  if (!tm.session || !tm.pending.length || tm.uploading) return;
  const batch = tm.pending.slice(0, 1500);
  tm.uploading = true;
  try {
    if (keepalive) {
      fetch('api.php?action=heart_samples', { method: 'POST', keepalive: true, headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ session_id: tm.session.id, samples: batch }) });
      tm.pending = tm.pending.slice(batch.length);
      return;
    }
    await api('heart_samples', { method: 'POST', body: { session_id: tm.session.id, samples: batch } });
    tm.pending = tm.pending.slice(batch.length);
  } catch (error) {
    /* další pokus při příštím odeslání */
  } finally {
    tm.uploading = false;
  }
}

function tmStartLoops() {
  tmStopLoops();
  tm.timers.push(setInterval(() => tmUpload(), TM_UPLOAD_MS));
  tm.timers.push(setInterval(() => { tmRefreshModelQuietly(); }, TM_REFRESH_MS));
  tm.timers.push(setInterval(() => { tmRenderToggle(); tmRenderPanel(); }, 15000));
  tmHindsightTabs(true);
}

function tmStopLoops() {
  tm.timers.forEach(clearInterval);
  tm.timers = [];
  tmHindsightTabs(false);
}

async function tmRefreshModelQuietly() {
  try {
    const state = await api('heart_state');
    tm.state = state;
  } catch (error) { /* nic */ }
}

/** Během seance se Hindsight otevírá v novém panelu, ať spojení se snímačem nezanikne. */
function tmHindsightTabs(active) {
  document.querySelectorAll('a.nav-item[href="hindsight.php"]').forEach(link => {
    if (active) { link.target = '_blank'; link.rel = 'noopener'; } else { link.removeAttribute('target'); link.removeAttribute('rel'); }
  });
}

/* ---------------------------------------------------------------- přepínač a panel */

function tmLiveDelta() {
  const latest = tm.bins[tm.bins.length - 1];
  const bpm = tm.lastReading && Date.now() - tm.lastReading.at < 15000 ? tm.lastReading.bpm : latest && Date.now() / 1000 - latest.ts < 30 ? latest.bpm : null;
  return { bpm, delta: bpm != null && tm.baseline?.bpm != null ? Math.round((bpm - tm.baseline.bpm) * 10) / 10 : null };
}

function tmRenderToggle() {
  const button = $('#tmToggle');
  if (!button) return;
  const on = !!tm.session;
  const sensorState = tm.sensor?.state || 'off';
  const live = tmLiveDelta();
  button.classList.toggle('is-on', on);
  button.classList.toggle('is-lost', on && tm.source === 'ble' && sensorState !== 'connected');
  button.classList.toggle('is-high', on && live.delta != null && tm.state?.model?.threshold != null && live.delta >= tm.state.model.threshold);
  button.setAttribute('aria-pressed', on ? 'true' : 'false');
  let text = 'OFF';
  if (on) {
    if (tm.source === 'ble') text = live.bpm != null ? `${Math.round(live.bpm)}${live.delta != null ? ` · ${tmSigned(live.delta)}` : ''}` : sensorState === 'connected' ? 'ON' : 'snímač?';
    else text = tm.source === 'watch' ? 'ON · hodinky' : 'ON';
  }
  $('#tmState').textContent = text;
  const heart = $('#tmHeart');
  if (heart) heart.style.animationDuration = live.bpm ? `${Math.max(0.3, 60 / live.bpm).toFixed(2)}s` : '';
}

function tmRenderLive() {
  tmRenderToggle();
  if (!$('#tmPanel').hidden) tmRenderPanel();
  if (tm.calm && $('#tmCalmBpm')) $('#tmCalmBpm').textContent = tm.lastReading ? `tep ${tm.lastReading.bpm}` : 'tep —';
  if (tm.step === 'source') tmRenderSensorState();
}

function tmRenderPanel() {
  const panel = $('#tmPanel');
  if (!panel || panel.hidden || !tm.session) return;
  const model = tm.state?.model;
  const live = tmLiveDelta();
  const sensorState = tm.sensor?.state || 'off';
  const minutes = (Date.now() / 1000 - tm.session.started_ts) / 60;
  const learning = !model || !model.ready;
  panel.innerHTML = `
    <div class="tm-panel-head"><div><p class="eyebrow">Trading mode běží</p><strong>${escapeHtml(tmDuration(minutes))}</strong></div><span class="tm-badge is-${learning ? 'learn' : 'ready'}">${learning ? `Učím se ${model ? model.trades : 0}/${model ? model.needed : 15}` : 'Upozornění zapnutá'}</span></div>
    <dl class="tm-figures">
      <div><dt>Teď</dt><dd>${live.bpm != null ? `${Math.round(live.bpm)} <small>tepů/min</small>` : tm.source === 'ble' ? '—' : '<small>po seanci</small>'}</dd></div>
      <div><dt>Klid</dt><dd>${tm.baseline?.bpm != null ? `${tmNumber(tm.baseline.bpm)} <small>tepů/min</small>` : '—'}</dd></div>
      <div><dt>Odchylka</dt><dd>${live.delta != null ? tmSigned(live.delta) : '—'}</dd></div>
      <div><dt>Hranice</dt><dd>${model?.ready ? tmSigned(model.threshold) : '<small>po 15 obchodech</small>'}</dd></div>
    </dl>
    <p class="tm-panel-note">${escapeHtml(tm.source === 'ble'
      ? (sensorState === 'connected' ? `Snímač ${tm.sensor.name}${tm.sensor.battery != null ? `, baterie ${tm.sensor.battery} %` : ''}.` : 'Snímač není připojený. Tep se nezapisuje.')
      : tm.source === 'watch' ? 'Tep doplní Zkratka z Apple Watch po seanci. Měj na hodinkách zapnuté cvičení.' : 'Seance bez tepu: zapisuje se jen rychlý test a čas.')}</p>
    ${learning && model ? `<p class="tm-panel-note">${escapeHtml(model.text)}</p>` : ''}
    <div class="tm-panel-actions">
      ${tm.source === 'ble' && sensorState !== 'connected' ? '<button class="button button-primary" type="button" data-tm="reconnect">Znovu připojit snímač</button>' : ''}
      ${tm.source !== 'ble' && window.TDHeartSensor?.supported() ? '<button class="button button-ghost" type="button" data-tm="reconnect">Připojit snímač</button>' : ''}
      <button class="button button-ghost" type="button" data-tm="breath">Dýchání 2 min</button>
      <button class="button button-ghost" type="button" data-tm="detail">Průběh seance</button>
      <button class="button button-ghost danger" type="button" data-tm="end">Ukončit seanci</button>
    </div>`;
}

function tmTogglePanel(open) {
  const panel = $('#tmPanel');
  const show = open ?? panel.hidden;
  panel.hidden = !show;
  if (show) tmRenderPanel();
}

/* ---------------------------------------------------------------- zapnutí: tři kroky */

function tmOpenStart() {
  tm.step = 'source';
  tm.checkId = null;
  let saved = '';
  try { saved = localStorage.getItem('td.heart.source') || ''; } catch (error) { saved = ''; }
  const ble = !!(window.TDHeartSensor && TDHeartSensor.supported());
  tm.source = ['ble', 'watch', 'none'].includes(saved) && (saved !== 'ble' || ble) ? saved : ble ? 'ble' : 'watch';
  document.querySelectorAll('input[name="tmSource"]').forEach(input => {
    input.checked = input.value === tm.source;
    if (input.value === 'ble') {
      input.disabled = !ble;
      input.closest('.tm-source').classList.toggle('is-disabled', !ble);
    }
  });
  tmShowStep('source');
  $('#tmStartDialog').showModal();
}

function tmShowStep(step) {
  tm.step = step;
  document.querySelectorAll('[data-tm-step]').forEach(section => { section.hidden = section.dataset.tmStep !== step; });
  const order = ['source', 'test', 'calm'];
  document.querySelectorAll('#tmSteps li').forEach(item => {
    const index = order.indexOf(item.dataset.step);
    item.classList.toggle('is-current', item.dataset.step === step);
    item.classList.toggle('is-done', index < order.indexOf(step));
  });
  if (step === 'source') tmRenderSource();
  if (step === 'test') tmRenderTest();
  if (step === 'calm') tmRenderCalm();
  tmUpdateStartButtons();
}

function tmRenderSource() {
  const ble = !!(window.TDHeartSensor && TDHeartSensor.supported());
  $('#tmSensorBox').hidden = tm.source !== 'ble';
  const note = $('#tmSourceNote');
  let text = '';
  if (!ble) text = 'Tenhle prohlížeč Bluetooth neumí. Na Windows otevři Trading Desk v Chromu nebo Edge, pak půjde připojit snímač.';
  else if (tm.source === 'ble') text = 'Apple Watch: tep do prohlížeče pošle aplikace v iPhonu, která ho vysílá jako Bluetooth snímač (dělají to aplikace pro trenažéry a Zwift). Hrudní pás funguje rovnou, jen ho navlhči.';
  if (tm.source === 'watch' && !tm.state?.link?.link) text = 'Ještě nemáš odkaz pro Zkratku. Nastavíš ho v Psychice, Tep a obchody, Apple Watch. Seanci můžeš spustit i bez něj a tep poslat později.';
  note.textContent = text;
  note.hidden = !text;
  if (tm.source === 'ble') { tmEnsureSensor(); tmRenderSensorState(); }
}

function tmRenderTest() {
  const check = tm.state?.today_check;
  const box = $('#tmTestBox');
  if (tm.checkId && check && Number(check.id) === Number(tm.checkId)) {
    box.innerHTML = `<div class="tm-test-result is-${escapeHtml(check.band)}"><strong>${escapeHtml(TM_BAND[check.band] || check.band)}</strong><span>Skóre ${check.score} z ${check.max_score}. Test je hotový, pokračuj klidovým měřením.</span></div>`;
  } else if (check) {
    box.innerHTML = `<div class="tm-test-result is-${escapeHtml(check.band)}"><strong>Dnešní test: ${escapeHtml(TM_BAND[check.band] || check.band)}</strong><span>Skóre ${check.score} z ${check.max_score}. Můžeš ho použít, nebo udělat nový.</span></div>
      <button class="button button-ghost" type="button" data-tm="test">Udělat test znovu</button>`;
  } else {
    box.innerHTML = `<p class="tm-note">Minuta otázek před seancí. Výsledek se uloží k seanci a později ukáže, jak spolu souvisí stav před obchodováním, tep a chyby.</p>
      <button class="button button-primary" type="button" data-tm="test">Spustit rychlý test</button>`;
  }
}

function tmRenderCalm() {
  const fill = $('#tmRingFill');
  fill.style.strokeDashoffset = '100';
  $('#tmCalmCount').textContent = tmCountdown(TM_CALM_SECONDS);
  $('#tmCalmBpm').textContent = tm.lastReading ? `tep ${tm.lastReading.bpm}` : tm.source === 'ble' ? 'tep —' : (tm.source === 'watch' ? 'hodinky měří' : '');
  $('#tmCalmText').textContent = tm.source === 'none'
    ? 'Bez tepu se klid neměří. Seanci rovnou spusť.'
    : 'Seď v klidu, dýchej normálně a nemluv. Z těchhle dvou minut vznikne tvůj dnešní klidový tep.';
}

function tmCountdown(seconds) {
  const value = Math.max(0, Math.ceil(seconds));
  return `${Math.floor(value / 60)}:${String(value % 60).padStart(2, '0')}`;
}

function tmUpdateStartButtons() {
  const next = $('#tmNext');
  const skip = $('#tmSkip');
  const back = $('#tmBack');
  back.hidden = tm.step === 'source' || !!tm.calm;
  skip.hidden = true;
  next.disabled = false;
  if (tm.step === 'source') {
    next.textContent = 'Pokračovat';
    next.disabled = tm.source === 'ble' && tm.sensor?.state !== 'connected';
  }
  if (tm.step === 'test') {
    const hasCheck = !!tm.state?.today_check;
    next.textContent = tm.checkId ? 'Pokračovat' : hasCheck ? 'Použít dnešní test' : 'Pokračovat bez testu';
    if (!hasCheck && !tm.checkId) { next.classList.replace('button-primary', 'button-ghost'); } else { next.classList.replace('button-ghost', 'button-primary'); }
  } else {
    next.classList.replace('button-ghost', 'button-primary');
  }
  if (tm.step === 'calm') {
    if (tm.calm && !tm.calm.done) { next.textContent = 'Měřím…'; next.disabled = true; }
    else if (tm.calm && tm.calm.done) { next.textContent = 'Hotovo'; }
    else if (tm.source === 'none') { next.textContent = 'Spustit seanci'; }
    else { next.textContent = 'Začít klidové měření'; skip.hidden = false; skip.textContent = 'Bez měření'; }
  }
}

async function tmNext() {
  if (tm.step === 'source') {
    try { localStorage.setItem('td.heart.source', tm.source); } catch (error) { /* bez úložiště */ }
    tmShowStep('test');
    return;
  }
  if (tm.step === 'test') {
    if (!tm.checkId && tm.state?.today_check) tm.checkId = tm.state.today_check.id;
    tmShowStep('calm');
    return;
  }
  if (tm.step === 'calm') {
    if (tm.calm?.done) { tmFinishStart(); return; }
    if (tm.source === 'none') { if (await tmBeginSession()) tmFinishStart(); return; }
    if (await tmBeginSession()) tmStartCalm();
  }
}

async function tmSkipCalm() {
  if (await tmBeginSession()) tmFinishStart();
}

/** Seance začíná klidovým měřením; tep z předchozích kroků se dopíše (server bere 10 min zpět). */
async function tmBeginSession() {
  if (tm.session) return true;
  try {
    const result = await api('heart_session', { method: 'POST', body: { op: 'start', source: tm.source, device: tm.source === 'ble' ? tm.sensor?.name || '' : tm.source === 'watch' ? 'Apple Watch' : '', check_id: tm.checkId } });
    tm.session = result.session;
    tm.baseline = null;
    tm.lastAlert = null;
    tmStartLoops();
    tmUpload();
    tmRenderToggle();
    return true;
  } catch (error) {
    toast(error.message, 'error');
    return false;
  }
}

function tmStartCalm() {
  const from = Math.floor(Date.now() / 1000);
  tm.calm = { from, to: from + TM_CALM_SECONDS, done: false };
  tmUpdateStartButtons();
  const fill = $('#tmRingFill');
  const tick = () => {
    if (!tm.calm) return;
    const now = Date.now() / 1000;
    const left = tm.calm.to - now;
    $('#tmCalmCount').textContent = tmCountdown(left);
    fill.style.strokeDashoffset = String(Math.max(0, 100 * left / TM_CALM_SECONDS));
    if (left <= 0) { tmEndCalm(); return; }
    tm.calm.timer = setTimeout(tick, 250);
  };
  tick();
}

async function tmEndCalm() {
  const calm = tm.calm;
  if (!calm || calm.done) return;
  calm.done = true;
  clearTimeout(calm.timer);
  if (tm.binner) tm.binner.flush().forEach(bin => { tm.bins.push(bin); tm.pending.push([bin.ts, bin.bpm, bin.rmssd]); });
  await tmUpload();
  try {
    const result = await api('heart_session', { method: 'POST', body: { op: 'baseline', id: tm.session.id, from: calm.from, to: Math.floor(Date.now() / 1000) } });
    tm.session = result.session;
  } catch (error) { /* klid se dopočítá ze seance */ }
  const local = TDHeartMath.baselineOf(tm.bins, calm.from, calm.to + 1);
  tm.baseline = tm.session.baseline_bpm != null ? { bpm: tm.session.baseline_bpm, rmssd: tm.session.baseline_rmssd } : local;
  $('#tmCalmCount').textContent = '0:00';
  $('#tmCalmText').textContent = tm.baseline?.bpm != null
    ? `Tvůj dnešní klidový tep je ${tmNumber(tm.baseline.bpm)} tepů za minutu${tm.baseline.rmssd ? ` a variabilita ${tmNumber(tm.baseline.rmssd)} ms` : ''}. Od něj se počítají odchylky.`
    : tm.source === 'watch' ? 'Hotovo. Klidový tep se spočítá, až Zkratka pošle tep z hodinek.' : 'Klidový tep se nepodařilo změřit (málo dat). Spočítám ho z nejklidnější části seance.';
  tmUpdateStartButtons();
}

function tmFinishStart() {
  if (tm.calm) clearTimeout(tm.calm.timer);
  tm.calm = null;
  $('#tmStartDialog').close();
  const model = tm.state?.model;
  toast(model?.ready ? 'Trading mode běží. Upozorním tě, když tep vyskočí nad tvoji hranici.' : `Trading mode běží. ${model ? model.text : ''}`);
  tmRefresh();
}

/* ---------------------------------------------------------------- upozornění */

async function tmCheckAlert() {
  if (!tm.session || !tm.baseline || tm.calm) return;
  const model = tm.state?.model;
  const now = Date.now() / 1000;
  const alert = TDHeartMath.detectAlert({ bins: tm.bins, baseline: tm.baseline, model, now, lastAlert: tm.lastAlert, binSeconds: TM_BIN_SECONDS });
  if (!alert) return;
  tm.lastAlert = now;
  tmShowAlert(alert, model);
  try {
    const saved = await api('heart_alert', { method: 'POST', body: { session_id: tm.session.id, ts: Math.floor(now), kind: alert.kind, delta: alert.delta, bpm: alert.bpm } });
    tm.alertId = saved.id;
  } catch (error) { tm.alertId = null; }
}

function tmShowAlert(alert, model) {
  const titles = { high: `Tep ${tmSigned(alert.delta)} nad klidem`, spike: 'Tep vyskočil', hrv: 'Napětí roste' };
  const sustain = model.sustain || 90;
  const lead = {
    high: `Tep drží ${tmSigned(alert.delta)} nad tvým dnešním klidem už ${sustain === 90 ? 'minutu a půl' : `${sustain} s`}.`,
    spike: `Tep během půl minuty vyskočil o ${tmNumber(alert.jump)} na ${Math.round(alert.bpm)}.`,
    hrv: `Variabilita tepu spadla na ${Math.round((alert.ratio || 0) * 100)} % tvé klidové hodnoty.`,
  }[alert.kind];
  const evidence = model.basis === 'errors' ? model.text : `Je to výš než u 80 % tvých vstupů.`;
  $('#tmAlertTitle').textContent = titles[alert.kind];
  $('#tmAlertText').textContent = `${lead} ${evidence} Než otevřeš další obchod, dej si dvě minuty dýchání.`;
  tm.alertKind = alert.kind;
  $('#tmAlert').hidden = false;
  $('#tmAlert').classList.add('is-in');
  $('#tmToggle').classList.add('is-alert');
}

async function tmAlertAction(action) {
  $('#tmAlert').hidden = true;
  $('#tmAlert').classList.remove('is-in');
  $('#tmToggle').classList.remove('is-alert');
  if (tm.alertId) {
    try { await api('heart_alert', { method: 'POST', body: { id: tm.alertId, action } }); } catch (error) { /* nic */ }
  }
  if (action === 'breath') tmBreath('Tep je nad tvojí hranicí. Delší výdech ho stáhne dolů dřív, než se dostane do dalšího obchodu.');
}

function tmBreath(reason = '') {
  if (window.openBreathing) window.openBreathing({ pattern: 'calm', minutes: 2, eyebrow: 'Trading mode', reason });
}

/* ---------------------------------------------------------------- ukončení */

async function tmEnd() {
  if (!tm.session) return;
  if (!confirm('Ukončit trading mode? Tep se přestane zapisovat a ukážu ti souhrn seance.')) return;
  tmTogglePanel(false);
  if (tm.binner) tm.binner.flush().forEach(bin => tm.pending.push([bin.ts, bin.bpm, bin.rmssd]));
  await tmUpload();
  const id = tm.session.id;
  try {
    await api('heart_session', { method: 'POST', body: { op: 'end', id } });
  } catch (error) { toast(error.message, 'error'); return; }
  tmStopLoops();
  if (tm.sensor) tm.sensor.disconnect();
  tm.session = null;
  tm.baseline = null;
  tm.bins = [];
  tm.pending = [];
  tm.lastReading = null;
  $('#tmAlert').hidden = true;
  await tmRefresh();
  tmOpenSession(id, true);
}

/* ---------------------------------------------------------------- souhrn seance */

async function tmOpenSession(id, justEnded = false) {
  try {
    const detail = await api('heart_session', { query: { id } });
    tm.detailId = id;
    $('#tmSessionEyebrow').textContent = justEnded ? 'Seance skončila' : 'Seance';
    $('#tmSessionTitle').textContent = `${detail.session.label} · ${tmDuration(detail.session.minutes)}`;
    $('#tmSessionBody').innerHTML = tmSessionHtml(detail);
    tmDrawChart($('#tmChart'), detail);
    $('#tmSessionDialog').showModal();
  } catch (error) { toast(error.message, 'error'); }
}

function tmSessionHtml(detail) {
  const s = detail.session;
  const model = tm.state?.model;
  const errors = detail.trades.filter(trade => trade.error === true).length;
  const highMinutes = detail.segments.reduce((sum, seg) => sum + (seg.to - seg.from) / 60, 0);
  const tiles = [
    ['Klid', s.baseline_bpm != null ? `${tmNumber(s.baseline_bpm)}` : '—', s.baseline_kind === 'measured' ? 'z klidového měření' : s.baseline_kind === 'auto' ? 'nejklidnější část seance' : 'zatím bez tepu'],
    ['Průměr / maximum', s.avg_bpm != null ? `${tmNumber(s.avg_bpm)} / ${tmNumber(s.max_bpm)}` : '—', 'tepů za minutu'],
    ['Nad hranicí', detail.above_share != null ? `${Math.round(detail.above_share * 100)} %` : '—', `hranice ${tmSigned(detail.threshold)}${detail.threshold_basis === 'default' ? ', výchozí' : ''}`],
    ['Obchody', `${detail.trades.length}`, detail.trades.length ? `${errors} ${tmPlural(errors, 'chybný', 'chybné', 'chybných')}` : 'v čase seance'],
  ];
  const check = detail.check ? `<span class="tm-chip is-${escapeHtml(detail.check.band)}">Rychlý test: ${escapeHtml(TM_BAND[detail.check.band] || detail.check.band)} (${detail.check.score} z ${detail.check.max_score})</span>` : '';
  const source = `<span class="tm-chip">${escapeHtml(TM_SOURCE_LABEL[s.source] || s.source)}${s.device && s.device !== 'Apple Watch' ? ` · ${escapeHtml(s.device)}` : ''}</span>`;
  const alerts = detail.alerts.length ? `<span class="tm-chip">${detail.alerts.length} ${tmPlural(detail.alerts.length, 'upozornění', 'upozornění', 'upozornění')}${detail.alerts.some(a => a.action === 'breath') ? ', dýchání ' + detail.alerts.filter(a => a.action === 'breath').length + '×' : ''}</span>` : '';
  const noData = !s.samples
    ? `<div class="empty-state compact">${s.source === 'watch' ? 'Tep z hodinek tu zatím není. Po seanci spusť Zkratku v iPhonu, tep se doplní při dalším otevření Trading Desku.' : 'V téhle seanci se tep nezapsal.'}</div>`
    : '';
  const segments = detail.segments.length
    ? `<div class="tm-segments"><p class="eyebrow">Kdy byl čas na dýchání</p><ul>${detail.segments.map(seg => `<li><strong>${tmClock(seg.from)}–${tmClock(seg.to)}</strong> tep až ${tmSigned(seg.peak)} nad klidem, ${tmDuration((seg.to - seg.from) / 60)}</li>`).join('')}</ul><p class="tm-note">Celkem ${tmDuration(highMinutes)} nad hranicí. Když se ti tep zvedne na tak dlouho, je to dobrá chvíle na dvě minuty dýchání, než otevřeš další obchod.</p></div>`
    : s.samples ? '<p class="tm-note">Tep nedržel nad hranicí déle než dvě minuty. Klidná seance.</p>' : '';
  const trades = detail.trades.length ? `<div class="table-wrap"><table class="tm-trades"><thead><tr><th>Vstup</th><th>Trh</th><th>Směr</th><th class="num">R</th><th class="num">Tep před vstupem</th><th class="num">Od klidu</th><th class="num">Max v obchodu</th><th>Hodnocení</th></tr></thead><tbody>${detail.trades.map(trade => `<tr class="${trade.error ? 'is-error' : ''}"><td>${tmClock(trade.entry_ts)}</td><td><strong>${escapeHtml(trade.market)}</strong></td><td>${trade.direction === 'long' ? 'Long' : 'Short'}</td><td class="num ${Number(trade.result_r) >= 0 ? 'value-positive' : 'value-negative'}">${trade.result_r == null ? '—' : tmSigned(trade.result_r, 2) + 'R'}</td><td class="num">${trade.pre_bpm == null ? '—' : tmNumber(trade.pre_bpm)}</td><td class="num">${trade.delta == null ? '—' : tmSigned(trade.delta)}</td><td class="num">${trade.peak_delta == null ? '—' : tmSigned(trade.peak_delta)}</td><td>${trade.error === true ? '<span class="tm-mark is-error" aria-hidden="true">✕</span> chyba' : trade.error === false ? '<span class="tm-mark" aria-hidden="true">○</span> podle plánu' : 'neohodnoceno'}</td></tr>`).join('')}</tbody></table></div>` : '';
  const missing = detail.missing_times ? `<p class="tm-note">${detail.missing_times} ${tmPlural(detail.missing_times, 'obchod', 'obchody', 'obchodů')} z toho dne nemá čas vstupu, a tak se s tepem nespárují. Doplň čas v deníku obchodů.</p>` : '';
  const learn = model && !model.ready ? `<p class="tm-note">${escapeHtml(model.text)}</p>` : '';
  return `<div class="tm-session-meta">${source}${check}${alerts}</div>
    <div class="tm-tiles">${tiles.map(([label, value, note]) => `<div><span>${escapeHtml(label)}</span><strong>${escapeHtml(value)}</strong><small>${escapeHtml(note)}</small></div>`).join('')}</div>
    ${noData || `<figure class="tm-chart-wrap"><figcaption class="tm-legend"><span><i class="tm-legend-line"></i>Tep</span><span><i class="tm-legend-ref"></i>Klid a hranice</span><span><i class="tm-legend-error">✕</i>Chybný obchod</span><span><i class="tm-legend-clean">○</i>Obchod podle plánu</span>${detail.alerts.length ? '<span><i class="tm-legend-alert">▲</i>Upozornění</span>' : ''}</figcaption><div class="tm-chart" id="tmChart"></div></figure>`}
    ${segments}${trades}${missing}${learn}`;
}

/** Graf tepu seance: jedna osa (tepy za minutu), čára tepu, klid a hranice, obchody a upozornění. */
function tmDrawChart(box, detail) {
  if (!box || !detail.samples.length) return;
  const W = 960; const H = 280; const L = 46; const R = 92; const T = 18; const B = 30;
  const samples = detail.samples;
  const s = detail.session;
  const start = Math.min(s.started_ts, samples[0][0]);
  const end = Math.max(s.ended_ts || samples[samples.length - 1][0], samples[samples.length - 1][0]);
  const span = Math.max(60, end - start);
  const base = s.baseline_bpm;
  const limit = base != null ? base + detail.threshold : null;
  const values = samples.map(item => item[1]);
  let low = Math.min(...values, base ?? Infinity);
  let high = Math.max(...values, limit ?? -Infinity);
  low = Math.floor((low - 4) / 5) * 5;
  high = Math.ceil((high + 4) / 5) * 5;
  const x = ts => L + (ts - start) / span * (W - L - R);
  const y = bpm => T + (high - bpm) / (high - low) * (H - T - B);
  const parts = [];
  // mřížka a popisky osy
  const step = (high - low) > 60 ? 20 : 10;
  for (let v = Math.ceil(low / step) * step; v <= high; v += step) parts.push(`<line class="tm-grid" x1="${L}" x2="${W - R}" y1="${y(v)}" y2="${y(v)}"/><text class="tm-axis" x="${L - 8}" y="${y(v) + 4}" text-anchor="end">${v}</text>`);
  // Popisky času: krátká seance s vteřinami, delší jen hodiny a minuty, bez opakování.
  const ticks = Math.min(6, Math.max(1, Math.round(span / 1800)));
  const label = ts => (span < 600 ? new Date(ts * 1000).toLocaleTimeString('cs-CZ', { hour: '2-digit', minute: '2-digit', second: '2-digit', timeZone: 'Europe/Prague' }) : tmClock(ts));
  let previousLabel = '';
  for (let i = 0; i <= ticks; i += 1) {
    const ts = start + span * i / ticks;
    const text = label(ts);
    if (text === previousLabel) continue;
    previousLabel = text;
    parts.push(`<text class="tm-axis" x="${x(ts)}" y="${H - 8}" text-anchor="${i === 0 ? 'start' : i === ticks ? 'end' : 'middle'}">${text}</text>`);
  }
  // úseky nad hranicí
  detail.segments.forEach(seg => parts.push(`<rect class="tm-seg" x="${x(seg.from)}" y="${T}" width="${Math.max(2, x(seg.to) - x(seg.from))}" height="${H - T - B}" rx="4"/>`));
  // klid a hranice
  if (base != null) {
    parts.push(`<line class="tm-ref" x1="${L}" x2="${W - R}" y1="${y(base)}" y2="${y(base)}"/><text class="tm-ref-label" x="${W - R + 8}" y="${y(base) + 4}">klid ${tmNumber(base)}</text>`);
    parts.push(`<line class="tm-ref is-limit" x1="${L}" x2="${W - R}" y1="${y(limit)}" y2="${y(limit)}"/><text class="tm-ref-label" x="${W - R + 8}" y="${y(limit) + 4}">hranice ${tmSigned(detail.threshold)}</text>`);
  }
  // čára tepu, přerušená tam, kde chybí data déle než minutu
  let path = '';
  samples.forEach((item, index) => {
    const gap = index === 0 || item[0] - samples[index - 1][0] > 60;
    path += `${gap ? 'M' : 'L'}${x(item[0]).toFixed(1)},${y(item[1]).toFixed(1)}`;
  });
  parts.push(`<path class="tm-line" d="${path}"/>`);
  // obchody na čáře tepu
  const at = ts => {
    let best = samples[0];
    for (const item of samples) { if (Math.abs(item[0] - ts) < Math.abs(best[0] - ts)) best = item; }
    return best;
  };
  detail.trades.forEach((trade, index) => {
    const point = at(trade.entry_ts);
    const cx = x(trade.entry_ts); const cy = y(point[1]);
    parts.push(trade.error
      ? `<g class="tm-trade is-error" data-trade="${index}"><circle class="tm-halo" cx="${cx}" cy="${cy}" r="8"/><path d="M${cx - 5},${cy - 5}L${cx + 5},${cy + 5}M${cx + 5},${cy - 5}L${cx - 5},${cy + 5}"/></g>`
      : `<g class="tm-trade" data-trade="${index}"><circle class="tm-halo" cx="${cx}" cy="${cy}" r="8"/><circle cx="${cx}" cy="${cy}" r="5"/></g>`);
  });
  detail.alerts.forEach(alert => {
    const cx = x(alert.ts);
    parts.push(`<path class="tm-alert-mark" d="M${cx},${T + 2}l6,10h-12z"/>`);
  });
  parts.push(`<line class="tm-cross" id="tmCross" x1="0" x2="0" y1="${T}" y2="${H - B}" visibility="hidden"/><circle class="tm-cross-dot" id="tmCrossDot" r="4" visibility="hidden"/>`);
  parts.push(`<rect class="tm-hit" x="${L}" y="${T}" width="${W - L - R}" height="${H - T - B}"/>`);
  box.innerHTML = `<svg viewBox="0 0 ${W} ${H}" role="img" aria-label="Tep během seance od ${tmClock(start)} do ${tmClock(end)}">${parts.join('')}</svg><div class="tm-tip" id="tmTip" hidden></div>`;
  const svg = box.querySelector('svg');
  const tip = box.querySelector('#tmTip');
  const cross = box.querySelector('#tmCross');
  const dot = box.querySelector('#tmCrossDot');
  const show = (clientX, clientY) => {
    const rect = svg.getBoundingClientRect();
    const px = (clientX - rect.left) / rect.width * W;
    const ts = start + (px - L) / (W - L - R) * span;
    const point = at(ts);
    const trade = detail.trades.find(item => Math.abs(x(item.entry_ts) - px) < 9 && Math.abs(y(at(item.entry_ts)[1]) - (clientY - rect.top) / rect.height * H) < 14);
    cross.setAttribute('x1', x(point[0])); cross.setAttribute('x2', x(point[0])); cross.setAttribute('visibility', 'visible');
    dot.setAttribute('cx', x(point[0])); dot.setAttribute('cy', y(point[1])); dot.setAttribute('visibility', 'visible');
    const delta = base != null ? ` · ${tmSigned(point[1] - base)} od klidu` : '';
    tip.innerHTML = trade
      ? `<strong>${tmClock(trade.entry_ts)} · ${escapeHtml(trade.market)} ${trade.direction === 'long' ? 'long' : 'short'}</strong><span>${trade.result_r == null ? '' : tmSigned(trade.result_r, 2) + 'R · '}${trade.error === true ? 'chyba' : trade.error === false ? 'podle plánu' : 'neohodnoceno'}</span><span>Tep před vstupem ${tmNumber(trade.pre_bpm)}${trade.delta != null ? ` (${tmSigned(trade.delta)})` : ''}</span>`
      : `<strong>${tmClock(point[0])}</strong><span>${tmNumber(point[1])} tepů/min${delta}</span>${point[2] ? `<span>Variabilita ${tmNumber(point[2])} ms</span>` : ''}`;
    tip.hidden = false;
    const left = Math.min(rect.width - tip.offsetWidth - 4, Math.max(4, clientX - rect.left + 12));
    tip.style.left = `${left}px`;
    tip.style.top = `${Math.max(4, y(point[1]) / H * rect.height - tip.offsetHeight - 10)}px`;
  };
  svg.addEventListener('pointermove', event => show(event.clientX, event.clientY));
  svg.addEventListener('pointerleave', () => { tip.hidden = true; cross.setAttribute('visibility', 'hidden'); dot.setAttribute('visibility', 'hidden'); });
}

/* ---------------------------------------------------------------- Psychika: model a seance */

function tmRenderPsyche() {
  const box = $('#heartModel');
  if (!box || !tm.state) return;
  const model = tm.state.model;
  const progress = Math.min(100, Math.round(100 * model.trades / model.needed));
  let html = '';
  if (!model.ready) {
    html = `<div class="heart-learn"><div class="heart-learn-head"><strong>Učím se tvoje reakce</strong><span>${model.trades} z ${model.needed} obchodů s tepem</span></div>
      <div class="heart-progress" role="progressbar" aria-valuemin="0" aria-valuemax="${model.needed}" aria-valuenow="${model.trades}"><i data-progress="${progress}"></i></div>
      <p>${escapeHtml(model.text)} Do té doby se tep jen zapisuje a vyhodnocuje zpětně.${model.unlabeled ? ` ${model.unlabeled} ${tmPlural(model.unlabeled, 'obchod s tepem nemá', 'obchody s tepem nemají', 'obchodů s tepem nemá')} vyplněné Dodržen plán ani Hodnocení, a tak se nepočítá.` : ''}</p></div>`;
  } else {
    html = `<div class="heart-result is-${escapeHtml(model.strength)}"><div class="heart-result-head"><span class="tm-badge is-${model.strength === 'none' ? 'learn' : 'ready'}">${escapeHtml(TM_STRENGTH[model.strength] || '')}</span><strong>Tvoje hranice: ${tmSigned(model.threshold)} tepů nad klidem</strong></div>
      <p>${escapeHtml(model.text)}${model.p != null && model.basis === 'errors' ? ` Pravděpodobnost, že je to náhoda: ${model.p < 0.001 ? 'pod 0,1 %' : `${(model.p * 100).toFixed(1).replace('.', ',')} %`}.` : ''}</p>
      ${model.after_loss ? `<p>Po ztrátě (do 30 minut): chyba v ${model.after_loss.errors} z ${model.after_loss.n} obchodů, tep průměrně ${tmSigned(model.after_loss.avg_delta)} nad klidem.</p>` : ''}
      <p class="tm-note">Upozornění během seance jsou zapnutá. Ozvou se, když tep drží nad hranicí půldruhé minuty, nebo když prudce vyskočí.</p></div>`;
  }
  const buckets = model.buckets.filter(bucket => bucket.n).length ? `<figure class="heart-buckets"><figcaption>Chybovost podle tepu před vstupem (od klidu)</figcaption><div id="heartBuckets"></div></figure>` : '';
  box.className = 'heart-model';
  box.innerHTML = html + buckets;
  const bar = box.querySelector('.heart-progress i');
  if (bar) bar.style.width = `${progress}%`;
  if (buckets) tmDrawBuckets($('#heartBuckets'), model.buckets);
  tmRenderSessions();
}

/** Sloupce: podíl chybných obchodů v pásmech odchylky tepu. Jedna barva, popisky pod osou. */
function tmDrawBuckets(box, buckets) {
  const W = 640; const H = 190; const L = 40; const T = 12; const B = 46; const gap = 14;
  const width = (W - L - 8 - gap * (buckets.length - 1)) / buckets.length;
  const y = rate => T + (1 - rate) * (H - T - B);
  const parts = [];
  [0, 0.5, 1].forEach(rate => parts.push(`<line class="tm-grid" x1="${L}" x2="${W - 8}" y1="${y(rate)}" y2="${y(rate)}"/><text class="tm-axis" x="${L - 8}" y="${y(rate) + 4}" text-anchor="end">${Math.round(rate * 100)} %</text>`));
  buckets.forEach((bucket, index) => {
    const bx = L + index * (width + gap);
    if (bucket.n) {
      const top = y(bucket.rate);
      const height = Math.max(2, H - B - top);
      parts.push(`<path class="heart-bar" data-bucket="${index}" d="M${bx},${H - B}V${top + 4}q0,-4 4,-4h${width - 8}q4,0 4,4V${H - B}z" ${height < 6 ? 'data-flat="1"' : ''}/>`);
    } else {
      parts.push(`<rect class="heart-bar-empty" x="${bx}" y="${H - B - 2}" width="${width}" height="2"/>`);
    }
    parts.push(`<text class="tm-axis" x="${bx + width / 2}" y="${H - B + 16}" text-anchor="middle">${escapeHtml(bucket.label)}</text><text class="tm-axis is-muted" x="${bx + width / 2}" y="${H - B + 31}" text-anchor="middle">${bucket.n ? `${bucket.errors} z ${bucket.n}` : 'nic'}</text>`);
    parts.push(`<rect class="tm-hit" data-bucket="${index}" x="${bx - gap / 2}" y="${T}" width="${width + gap}" height="${H - T - B + 34}"/>`);
  });
  box.innerHTML = `<svg viewBox="0 0 ${W} ${H}" role="img" aria-label="Podíl chybných obchodů podle odchylky tepu od klidu">${parts.join('')}</svg><div class="tm-tip" hidden></div>`;
  const svg = box.querySelector('svg');
  const tip = box.querySelector('.tm-tip');
  svg.addEventListener('pointermove', event => {
    const target = event.target.closest('[data-bucket]');
    if (!target) { tip.hidden = true; return; }
    const bucket = buckets[Number(target.dataset.bucket)];
    tip.innerHTML = `<strong>${escapeHtml(bucket.label)} tepů</strong><span>${bucket.n} ${tmPlural(bucket.n, 'obchod', 'obchody', 'obchodů')}, ${bucket.errors} ${tmPlural(bucket.errors, 'chybný', 'chybné', 'chybných')}${bucket.n ? ` (${Math.round(bucket.rate * 100)} %)` : ''}</span>`;
    tip.hidden = false;
    const rect = svg.getBoundingClientRect();
    tip.style.left = `${Math.min(rect.width - tip.offsetWidth - 4, Math.max(4, event.clientX - rect.left + 12))}px`;
    tip.style.top = '4px';
  });
  svg.addEventListener('pointerleave', () => { tip.hidden = true; });
}

function tmRenderSessions() {
  const box = $('#heartSessions');
  if (!box || !tm.state) return;
  const sessions = tm.state.sessions || [];
  box.innerHTML = sessions.length
    ? `<p class="eyebrow">Seance</p><div class="table-wrap"><table class="tm-sessions"><thead><tr><th>Začátek</th><th>Délka</th><th>Tep z</th><th class="num">Klid</th><th class="num">Průměr / max</th><th class="num">Obchody</th><th class="num">Upozornění</th><th></th></tr></thead><tbody>${sessions.map(s => `<tr><td><strong>${escapeHtml(s.label)}</strong>${s.active ? ' <span class="tm-badge is-ready">běží</span>' : ''}</td><td>${escapeHtml(tmDuration(s.minutes))}</td><td>${escapeHtml(TM_SOURCE_LABEL[s.source] || s.source)}</td><td class="num">${s.baseline_bpm == null ? '—' : tmNumber(s.baseline_bpm)}</td><td class="num">${s.avg_bpm == null ? '—' : `${tmNumber(s.avg_bpm)} / ${tmNumber(s.max_bpm)}`}</td><td class="num">${s.trades}${s.errors ? ` <span class="value-negative">(${s.errors} ✕)</span>` : ''}</td><td class="num">${s.alerts || '—'}</td><td><button class="mini-button" type="button" data-tm-session="${s.id}">Průběh</button></td></tr>`).join('')}</tbody></table></div>`
    : '<div class="empty-state compact">Zatím žádná seance. Zapni Trading mode nahoře, nebo nahraj tep ze souboru.</div>';
}

/* ---------------------------------------------------------------- Apple Watch */

function tmWatchUrl(token) {
  return new URL(`hr.php?t=${encodeURIComponent(token)}`, location.href).href;
}

function tmRenderWatch(linkState) {
  const link = linkState?.link;
  const status = $('#tmWatchStatus');
  const fmt = value => value ? new Date(value).toLocaleString('cs-CZ', { day: 'numeric', month: 'numeric', hour: '2-digit', minute: '2-digit' }) : 'zatím nic';
  status.innerHTML = link
    ? `<div><span>Odkaz</span><strong class="is-live">Platí</strong></div><div><span>Naposledy přijato</span><strong>${escapeHtml(fmt(link.received_at))}</strong></div>`
    : `<div><span>Odkaz</span><strong>${linkState?.available === false ? 'Server neumí šifrovat (chybí PHP sodium)' : 'Zatím není'}</strong></div><div><span>Tep z hodinek</span><strong>po vytvoření odkazu</strong></div>`;
  $('#tmWatchLinkBox').hidden = !link;
  if (link) $('#tmWatchUrl').value = tmWatchUrl(link.token);
  $('#tmWatchCreate').hidden = !!link || linkState?.available === false;
  $('#tmWatchRegenerate').hidden = !link;
  $('#tmWatchRemove').hidden = !link;
}

async function tmOpenWatch() {
  if (!tm.state) await tmRefresh();
  tmRenderWatch(tm.state?.link);
  $('#tmWatchDialog').showModal();
}

async function tmWatchLink(method) {
  if (method === 'POST' && $('#tmWatchLinkBox').hidden === false && !confirm('Vydat nový odkaz? Starý přestane platit a ve Zkratce ho musíš vyměnit.')) return;
  if (method === 'DELETE' && !confirm('Zrušit odkaz? Zkratka pak tep neodešle.')) return;
  try {
    const result = await api('heart_link', { method });
    tm.state.link = result;
    tmRenderWatch(result);
  } catch (error) { toast(error.message, 'error'); }
}

async function tmUploadFile(file) {
  if (!file) return;
  const form = new FormData();
  form.append('file', file);
  try {
    const result = await api('heart_upload', { method: 'POST', body: form });
    toast(`Nahráno ${result.stored} ${tmPlural(result.stored, 'hodnota', 'hodnoty', 'hodnot')} tepu${result.skipped ? `, ${result.skipped} ${tmPlural(result.skipped, 'řádek', 'řádky', 'řádků')} jsem přeskočil` : ''}.`);
    await tmRefresh();
  } catch (error) { toast(error.message, 'error'); }
}

/* ---------------------------------------------------------------- události */

function bindTradingMode() {
  if (!$('#tmToggle')) return;
  $('#tmToggle').addEventListener('click', event => {
    event.stopPropagation();
    if (tm.session) tmTogglePanel();
    else tmOpenStart();
  });
  document.addEventListener('click', event => {
    if (!$('#tmPanel').hidden && !event.target.closest('.tm-wrap')) tmTogglePanel(false);
  });
  $('#tmPanel').addEventListener('click', event => {
    const action = event.target.closest('[data-tm]')?.dataset.tm;
    if (action === 'reconnect') { tmSourceForPanel(); }
    if (action === 'breath') { tmTogglePanel(false); tmBreath(); }
    if (action === 'detail') { tmTogglePanel(false); tmOpenSession(tm.session.id); }
    if (action === 'end') tmEnd();
  });
  document.querySelectorAll('input[name="tmSource"]').forEach(input => input.addEventListener('change', () => {
    tm.source = input.value;
    tmRenderSource();
    tmUpdateStartButtons();
  }));
  $('#tmConnect').addEventListener('click', tmConnectSensor);
  $('#tmNext').addEventListener('click', tmNext);
  $('#tmSkip').addEventListener('click', tmSkipCalm);
  $('#tmBack').addEventListener('click', () => tmShowStep(tm.step === 'calm' ? 'test' : 'source'));
  $('#tmTestBox').addEventListener('click', event => {
    if (event.target.closest('[data-tm="test"]') && typeof openPsychCheck === 'function') openPsychCheck();
  });
  $('#tmStartDialog').addEventListener('close', () => {
    if (tm.calm && !tm.calm.done) {
      // Zavřené během měření: seance běží dál, klid se dopočítá z nejklidnější části.
      clearTimeout(tm.calm.timer);
      tm.calm = null;
      if (tm.session) toast('Trading mode běží bez klidového měření. Klid spočítám z nejklidnější části seance.');
    } else if (tm.calm) {
      tm.calm = null;
    }
    if (!tm.session && tm.sensor && tm.sensor.state !== 'off') tm.sensor.disconnect();
    tmRenderToggle();
  });
  document.addEventListener('td:psych-done', event => {
    if (!$('#tmStartDialog').open) return;
    tm.checkId = event.detail?.id || null;
    if (tm.state && event.detail) tm.state.today_check = { id: event.detail.id, check_date: event.detail.check_date, band: event.detail.band, score: event.detail.score, max_score: event.detail.max_score };
    tmShowStep('test');
  });
  $('#tmAlertBreath').addEventListener('click', () => tmAlertAction('breath'));
  $('#tmAlertDismiss').addEventListener('click', () => tmAlertAction('dismiss'));
  $('#tmSessionDelete').addEventListener('click', async () => {
    if (!tm.detailId || !confirm('Smazat tuhle seanci i s naměřeným tepem? Obchody zůstanou.')) return;
    try {
      await api('heart_session', { method: 'DELETE', query: { id: tm.detailId } });
      $('#tmSessionDialog').close();
      if (tm.session && tm.session.id === tm.detailId) { tmStopLoops(); tm.session = null; }
      await tmRefresh();
    } catch (error) { toast(error.message, 'error'); }
  });
  $('#heartStart').addEventListener('click', () => (tm.session ? tmTogglePanel(true) : tmOpenStart()));
  $('#heartWatch').addEventListener('click', tmOpenWatch);
  $('#heartFile').addEventListener('change', event => { const file = event.target.files[0]; event.target.value = ''; tmUploadFile(file); });
  $('#heartSessions').addEventListener('click', event => {
    const button = event.target.closest('[data-tm-session]');
    if (button) tmOpenSession(Number(button.dataset.tmSession));
  });
  $('#tmWatchCreate').addEventListener('click', () => tmWatchLink('POST'));
  $('#tmWatchRegenerate').addEventListener('click', () => tmWatchLink('POST'));
  $('#tmWatchRemove').addEventListener('click', () => tmWatchLink('DELETE'));
  $('#tmWatchCopy').addEventListener('click', async () => {
    try { await navigator.clipboard.writeText($('#tmWatchUrl').value); toast('Odkaz je zkopírovaný.'); } catch (error) { $('#tmWatchUrl').select(); }
  });
  document.addEventListener('td:view', event => { if (event.detail === 'psyche') tmRefresh(); });
  window.addEventListener('pagehide', () => { if (tm.binner) tm.binner.flush().forEach(bin => tm.pending.push([bin.ts, bin.bpm, bin.rmssd])); tmUpload(true); });
  tmRefresh();
}

/** Z panelu: připojit nebo znovu připojit snímač (klepnutí dovolí okno výběru). */
async function tmSourceForPanel() {
  const sensor = tmEnsureSensor();
  if (!sensor) return;
  if (sensor.device && sensor.state !== 'connected') {
    try { await sensor.attach(); tmRenderPanel(); return; } catch (error) { /* vyber znovu */ }
  }
  await tmConnectSensor();
  tmRenderPanel();
}
