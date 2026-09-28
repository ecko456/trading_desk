'use strict';

const $ = (selector, root = document) => root.querySelector(selector);
const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];

const state = {
  plans: [],
  trades: [],
  strategies: [],
  accounts: [],
  audits: [],
  currentPlan: null,
  currentScreenshots: [],
  currentStrategy: null,
  stats: null,
  auditIntervalDays: 30,
  calendarMonth: null,
  calendarDays: [],
  psychQuestions: [],
  weeklyContext: null,
  biasInherited: false,
  readinessData: null,
  workspace: null,
  dnAnalysis: null,
};

const viewLabels = {
  dashboard: ['Přehled', 'Trading journal'],
  plan: ['Náhled trhu', 'Denní náhled'],
  journal: ['Deník obchodů', 'Realizovaná exekuce'],
  archive: ['Historie náhledů', 'Plán proti realitě'],
  strategies: ['Strategie', 'Co skutečně funguje'],
  calendar: ['Kalendář', 'Měsíc v kostce'],
  psyche: ['Psychika a disciplína', 'Proč k chybě došlo'],
  accounts: ['Účty a Money audit', 'Kontrola evidence'],
  backup: ['Záloha a export', 'Tvůj deník v jednom souboru'],
  wall: ['Nástěnka', 'Komunita'],
  admin: ['Členové', 'Správa'],
  profile: ['Profil', 'Můj účet'],
  settings: ['Nastavení', 'Můj desk'],
};

const strategyStyleLabels = { trend: 'Trendový', reversal: 'Reversal', both: 'Trendový i reversal' };
const eventKindLabels = { news: 'Red news', holiday: 'Svátek', note: 'Poznámka' };
const bandLabels = { green: 'ZELENÁ — obchoduj podle plánu', amber: 'ORANŽOVÁ — opatrně', red: 'ČERVENÁ — dnes neobchoduj' };
const monthNames = ['leden', 'únor', 'březen', 'duben', 'květen', 'červen', 'červenec', 'srpen', 'září', 'říjen', 'listopad', 'prosinec'];

const marketPointValue = { ES: 50, MES: 5, NQ: 20, MNQ: 2, GC: 100, MGC: 10, CL: 1000, MCL: 100, '6E': 125000 };
const defaultMarkets = ['ES', 'MES', 'NQ', 'MNQ', 'GC', 'MGC', 'CL', 'MCL', '6E'];
const defaultSessions = ['Intraday', 'Hybrid Intraday'];

function today() {
  return new Intl.DateTimeFormat('sv-SE', { timeZone: 'Europe/Prague' }).format(new Date());
}

function escapeHtml(value) {
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}

function displayNumber(value, digits = 2) {
  const number = Number(value);
  return Number.isFinite(number) ? number.toLocaleString('cs-CZ', { minimumFractionDigits: digits, maximumFractionDigits: digits }) : '—';
}

function displayMoney(value) {
  const number = Number(value);
  return Number.isFinite(number) ? number.toLocaleString('cs-CZ', { style: 'currency', currency: 'USD', maximumFractionDigits: 0 }) : '—';
}

function directionLabel(value) {
  if (value === 'long') return 'Long';
  if (value === 'short') return 'Short';
  return 'Balance';
}

function tradeTypeValue(value) {
  const raw = String(value ?? '').trim();
  if (raw === 'hybrid_intraday' || raw === 'SWING') return 'hybrid_intraday';
  if (raw === '' || raw.toLocaleLowerCase('cs') === 'intraday') return 'intraday';
  return raw;
}

function tradeTypeLabel(value) {
  const normalized = tradeTypeValue(value);
  if (normalized === 'hybrid_intraday') return 'Hybrid Intraday';
  if (normalized === 'intraday') return 'Intraday';
  return normalized;
}

function toast(message, type = 'ok') {
  const element = $('#toast');
  const dialogs = $$('dialog[open]');
  const host = dialogs.length ? dialogs[dialogs.length - 1] : document.body;
  if (element.parentElement !== host) host.append(element);
  element.textContent = message;
  element.className = `toast is-visible${type === 'error' ? ' is-error' : ''}${dialogs.length ? ' in-dialog' : ''}`;
  clearTimeout(toast.timer);
  toast.timer = setTimeout(() => { element.className = 'toast'; }, 3200);
}

async function api(action, options = {}) {
  const query = options.query ? `&${new URLSearchParams(options.query)}` : '';
  const response = await fetch(`api.php?action=${encodeURIComponent(action)}${query}`, {
    method: options.method || 'GET',
    headers: options.body && !(options.body instanceof FormData) ? { 'Content-Type': 'application/json' } : undefined,
    body: options.body instanceof FormData ? options.body : options.body ? JSON.stringify(options.body) : undefined,
  });
  const type = response.headers.get('content-type') || '';
  const payload = type.includes('application/json') ? await response.json() : { error: await response.text() };
  if (response.status === 401 && payload.auth) {
    // Relace vypršela nebo ji správce ukončil: zpět na přihlášení.
    location.replace('./');
    throw new Error(payload.error);
  }
  if (!response.ok) throw new Error(payload.error || 'Server operaci nedokončil.');
  return payload;
}

const topbarActions = {
  dashboard: ['globalMarket', 'quickTrade', 'quickPlan'],
  archive: ['quickPlan'],
  calendar: ['quickTrade', 'quickPlan'],
  strategies: ['quickTrade'],
  psyche: ['quickTrade'],
};

function activateView(name) {
  $$('.view').forEach(view => view.classList.toggle('is-active', view.id === `view-${name}`));
  $$('.nav-item').forEach(item => item.classList.toggle('is-active', item.dataset.view === name));
  const [title, eyebrow] = viewLabels[name] || viewLabels.dashboard;
  $('#viewTitle').textContent = title;
  $('#viewEyebrow').textContent = name === 'plan' ? (planType() === 'weekly' ? 'Týdenní náhled' : 'Denní náhled') : eyebrow;
  const visible = topbarActions[name] || [];
  ['globalMarket', 'quickTrade', 'quickPlan'].forEach(id => { $(`#${id}`).hidden = !visible.includes(id); });
  closeQuickPlanMenu();
  closeSidebar();
  window.scrollTo({ top: 0, behavior: 'smooth' });
  if (name === 'dashboard') refreshDashboard();
  if (name === 'plan') { applySessionLocks(); schedulePlanRefresh(); }
  if (name === 'journal') refreshTrades();
  if (name === 'archive') refreshPlans();
  if (name === 'accounts') refreshAccounts();
  if (name === 'strategies') refreshStrategyStats();
  if (name === 'calendar') refreshCalendar();
  if (name === 'psyche') { refreshProfile(); refreshDiscipline(); refreshPsychLatest(); refreshCalibration(); }
  if (name === 'wall') { refreshWall(); refreshMembers(); }
  if (name === 'admin') refreshAdmin();
  if (name === 'settings') renderSettings();
}

function closeQuickPlanMenu() {
  $('#quickPlanMenu').hidden = true;
  $('#quickPlan').setAttribute('aria-expanded', 'false');
}

function openSidebar() {
  $('#sidebar').classList.add('is-open');
  $('#sidebarScrim').hidden = false;
}

function closeSidebar() {
  $('#sidebar').classList.remove('is-open');
  $('#sidebarScrim').hidden = true;
}

function currentTheme() {
  return document.documentElement.dataset.theme === 'light' ? 'light' : 'dark';
}

function setTheme(theme) {
  document.documentElement.dataset.theme = theme;
  try { localStorage.setItem('td-theme', theme); } catch (error) { /* vzhled se jen nezapamatuje */ }
  $('meta[name="theme-color"]').setAttribute('content', theme === 'light' ? '#f4f0e7' : '#07080b');
}

function formObject(form) {
  return Object.fromEntries(new FormData(form).entries());
}

function setFormValues(form, values) {
  Object.entries(values || {}).forEach(([name, value]) => {
    const controls = $$(`[name="${CSS.escape(name)}"]`, form);
    controls.forEach(control => {
      if (control.type === 'radio' || control.type === 'checkbox') control.checked = String(control.value) === String(value);
      else control.value = value ?? '';
    });
  });
}

function collectRows(containerSelector, rowSelector, fields) {
  return $$(rowSelector, $(containerSelector)).map(row => Object.fromEntries(fields.map(field => [field, $(`[name="${field}"]`, row)?.value?.trim() ?? ''])));
}

const optionTag = (value, label, current) => `<option value="${value}"${String(current ?? '') === value ? ' selected' : ''}>${label}</option>`;

// ---------------------------------------------------------------------------
// Přizpůsobené prostředí: co trader v náhledu a u obchodu vidí. Nastavení je
// v jeho deníku; bez něj se chová jako dřív (všechno zapnuté).
// ---------------------------------------------------------------------------
function prefs() {
  return state.workspace?.prefs || { method: 'both', hidden: { plan: [], trade: [], modules: [] }, tokens_extra: [], markets: [], defaults: {}, dn: {} };
}

function isHiddenEl(key) {
  const group = key.startsWith('trade.') ? 'trade' : 'plan';
  return (prefs().hidden?.[group] || []).includes(key);
}

function moduleOn(key) {
  return !(prefs().hidden?.modules || []).includes(key);
}

function activeFields(scope) {
  return (state.workspace?.fields || []).filter(field => field.scope === scope && !field.archived);
}

function marketList() {
  const markets = prefs().markets || [];
  return markets.length ? markets : defaultMarkets.map(symbol => ({ symbol, point_value: marketPointValue[symbol] ?? null, rth: RTH_OPEN[symbol] || '09:30' }));
}

function pointValueFor(market) {
  const symbol = String(market ?? '').trim().toUpperCase();
  const own = marketList().find(item => item.symbol === symbol);
  return own ? own.point_value : (marketPointValue[symbol] ?? null);
}

function rthOpenFor(market) {
  const symbol = String(market ?? '').trim().toUpperCase();
  return marketList().find(item => item.symbol === symbol)?.rth || RTH_OPEN[symbol] || '09:30';
}

/** Ovládací prvek vlastního pole; jméno cf_<id> ho odliší od běžných polí. */
function customFieldControl(field, value) {
  const name = `cf_${field.id}`;
  const label = escapeHtml(field.label);
  const help = field.help ? `<small class="field-hint">${escapeHtml(field.help)}</small>` : '';
  const radio = (optionValue, text, tone, checked) => `<label class="${tone}"><input type="radio" name="${name}" value="${optionValue}"${checked ? ' checked' : ''}><span>${text}</span></label>`;
  if (field.kind === 'bool') {
    return `<div class="custom-field"><span class="field-label">${label}</span><div class="tri-switch compact" role="radiogroup" aria-label="${label}">${radio('1', 'Ano', 'is-long', value === true)}${radio('0', 'Ne', 'is-short', value === false)}</div>${help}</div>`;
  }
  if (field.kind === 'rating') {
    return `<div class="custom-field"><span class="field-label">${label}</span><div class="tri-switch compact" role="radiogroup" aria-label="${label}">${[1, 2, 3, 4, 5].map(number => radio(String(number), String(number), 'is-neutral', Number(value) === number)).join('')}</div>${help}</div>`;
  }
  if (field.kind === 'select') {
    return `<label class="custom-field">${label}<select name="${name}"><option value="">—</option>${field.options.map(option => `<option value="${escapeHtml(option)}"${option === value ? ' selected' : ''}>${escapeHtml(option)}</option>`).join('')}</select>${help}</label>`;
  }
  if (field.kind === 'number') {
    return `<label class="custom-field">${label}<input type="number" step="any" name="${name}" value="${escapeHtml(value ?? '')}">${help}</label>`;
  }
  if (field.kind === 'textarea') {
    return `<label class="custom-field span-all">${label}<textarea name="${name}" rows="2">${escapeHtml(value ?? '')}</textarea>${help}</label>`;
  }
  return `<label class="custom-field">${label}<input name="${name}" maxlength="300" value="${escapeHtml(value ?? '')}">${help}</label>`;
}

/** Hodnoty vlastních polí z formuláře do tvaru pro server: { id: hodnota }. */
function extractCustomValues(data, scope) {
  const custom = {};
  activeFields(scope).forEach(field => {
    const raw = data[`cf_${field.id}`];
    delete data[`cf_${field.id}`];
    const text = String(raw ?? '').trim();
    if (field.kind === 'bool') custom[field.id] = text === '1' ? true : text === '0' ? false : null;
    else custom[field.id] = text === '' ? null : text;
  });
  Object.keys(data).filter(key => key.startsWith('cf_')).forEach(key => delete data[key]);
  return custom;
}

function currentCustomValues(container) {
  const values = {};
  $$('[name^="cf_"]', container).forEach(control => {
    const id = control.name.slice(3);
    if (control.type === 'radio') {
      if (control.checked) values[id] = control.value === '1' ? true : control.value === '0' ? false : control.value;
    } else if (control.value !== '') values[id] = control.value;
  });
  return values;
}

function renderPlanCustomFields(values = currentCustomValues($('#planCustomFields'))) {
  const fields = activeFields('plan');
  $('#planCustomFields').innerHTML = fields.map(field => customFieldControl(field, values?.[field.id])).join('');
}

function renderTradeCustomFields(values = {}) {
  const fields = activeFields('trade');
  $('#tradeCustomSection').hidden = !fields.length;
  $('#tradeCustomFields').innerHTML = fields.map(field => customFieldControl(field, values?.[field.id])).join('');
}

/** Skryje části náhledu, ve kterých nezbyl žádný zapnutý prvek, i s krokem v navigaci. */
function syncPlanSections() {
  $$('#planForm .plan-section[data-section]').forEach(section => {
    const key = section.dataset.section;
    let hidden;
    if (key === 'custom') hidden = !activeFields('plan').length;
    else if (section.dataset.el) hidden = isHiddenEl(section.dataset.el);
    else {
      const parts = $$('[data-el]', section).filter(element => !element.closest('.plan-row'));
      hidden = key !== 'zones' && parts.length > 0 && parts.every(element => isHiddenEl(element.dataset.el));
    }
    section.hidden = hidden;
    const step = $(`#planSteps [data-step="${key}"]`);
    if (step) step.hidden = hidden;
  });
}

const BRAND_TAGLINES = { mp: 'Market Profile journal', dn: 'DiNapoli journal', both: 'Market Profile · DiNapoli' };

function applyWorkspace() {
  document.body.dataset.method = prefs().method;
  const tagline = $('#brandTagline');
  if (tagline) tagline.textContent = BRAND_TAGLINES[prefs().method] || 'Trading journal';
  $$('[data-el]').forEach(element => { element.hidden = isHiddenEl(element.dataset.el); });
  $$('[data-el-group]').forEach(element => { element.hidden = element.dataset.elGroup.split(',').every(isHiddenEl); });
  $$('[data-module]').forEach(element => { element.hidden = !moduleOn(element.dataset.module); });
  $$('[data-module-group]').forEach(element => { element.hidden = element.dataset.moduleGroup.split(',').every(key => !moduleOn(key)); });
  // Zóny se překreslí s nabídkou štítků podle metodiky; rozepsané hodnoty zůstanou.
  renderZones(collectRows('#zoneList', '.zone-row', zoneFields));
  // Nabídka timeframů u DiNapoli levelů se bere z nastavení tolerance.
  if ($('#dnLevelList .dn-level-row')) renderDnLevels(dnLevels());
  renderPlanCustomFields();
  syncPlanSections();
  $('#tvIncludeDnControl').hidden = isHiddenEl('dn.swings');
  const dnTab = $('#dnSettingsTab');
  if (dnTab) dnTab.hidden = prefs().method === 'mp';
  renderDataLists();
  renderTradeHead();
  renderTradeTable();
  renderReadiness();
  updateAuditBanner();
  if ($('#planDate').value) applySessionLocks();
  schedulePlanRefresh();
}

async function loadWorkspace() {
  try {
    state.workspace = await api('workspace');
  } catch (error) {
    state.workspace = null;
  }
  applyWorkspace();
}

// ---------------------------------------------------------------------------
// Náhled trhu: kalendář session. Všechno se počítá v pražském čase, RTH podle
// času v New Yorku, takže sedí i v týdnech, kdy se USA a Evropa liší v letním čase.
// ---------------------------------------------------------------------------
const PRAGUE = 'Europe/Prague';
const NEW_YORK = 'America/New_York';
const EU_OPEN = '09:00';
const RTH_OPEN = { ES: '09:30', MES: '09:30', NQ: '09:30', MNQ: '09:30', YM: '09:30', MYM: '09:30', RTY: '09:30', M2K: '09:30', CL: '09:00', MCL: '09:00', GC: '08:20', MGC: '08:20', SI: '08:25', '6E': '08:20', ZN: '08:20', ZB: '08:20' };

function zoneOffsetMinutes(instant, timeZone) {
  const parts = new Intl.DateTimeFormat('en-US', { timeZone, hourCycle: 'h23', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit' }).formatToParts(instant);
  const get = type => Number(parts.find(part => part.type === type).value);
  return (Date.UTC(get('year'), get('month') - 1, get('day'), get('hour'), get('minute'), get('second')) - instant.getTime()) / 60000;
}

function zonedInstant(date, time, timeZone) {
  const [year, month, day] = date.split('-').map(Number);
  const [hour, minute] = time.split(':').map(Number);
  const guess = Date.UTC(year, month - 1, day, hour, minute);
  const offset = zoneOffsetMinutes(new Date(guess), timeZone);
  let instant = guess - offset * 60000;
  const corrected = zoneOffsetMinutes(new Date(instant), timeZone);
  if (corrected !== offset) instant = guess - corrected * 60000;
  return new Date(instant);
}

function addDays(date, days) {
  const [year, month, day] = date.split('-').map(Number);
  return new Date(Date.UTC(year, month - 1, day + days)).toISOString().slice(0, 10);
}

function weekdayOf(date) {
  const [year, month, day] = date.split('-').map(Number);
  return new Date(Date.UTC(year, month - 1, day)).getUTCDay();
}

function mondayOf(date) {
  const day = weekdayOf(date);
  return addDays(date, day === 0 ? -6 : 1 - day);
}

function isoWeek(date) {
  const [year, month, day] = date.split('-').map(Number);
  const target = new Date(Date.UTC(year, month - 1, day));
  const weekday = target.getUTCDay() || 7;
  target.setUTCDate(target.getUTCDate() + 4 - weekday);
  const yearStart = new Date(Date.UTC(target.getUTCFullYear(), 0, 1));
  return Math.ceil(((target - yearStart) / 86400000 + 1) / 7);
}

function sessionSchedule(sessionDate, market) {
  const rth = zonedInstant(sessionDate, rthOpenFor(market), NEW_YORK);
  return {
    globex: zonedInstant(addDays(sessionDate, -1), '18:00', NEW_YORK),
    eu: zonedInstant(sessionDate, EU_OPEN, PRAGUE),
    rth,
    ib: new Date(rth.getTime() + 60 * 60000),
    close: zonedInstant(sessionDate, '16:00', NEW_YORK),
  };
}

/** Na jakou session se náhled tvoří: o víkendu a po zavírce na další obchodní den. */
function nextSessionDate(now = new Date()) {
  const date = today();
  const day = weekdayOf(date);
  if (day === 6) return addDays(date, 2);
  if (day === 0) return addDays(date, 1);
  if (now >= zonedInstant(date, '16:00', NEW_YORK)) return addDays(date, day === 5 ? 3 : 1);
  return date;
}

function defaultPlanDate(type, now = new Date()) {
  const next = nextSessionDate(now);
  return type === 'weekly' ? mondayOf(next) : next;
}

function gateTimeLabel(instant, sessionDate) {
  const localDate = new Intl.DateTimeFormat('sv-SE', { timeZone: PRAGUE }).format(instant);
  const clock = new Intl.DateTimeFormat('cs-CZ', { timeZone: PRAGUE, hour: '2-digit', minute: '2-digit' }).format(instant);
  if (localDate === sessionDate) return clock;
  return `${new Intl.DateTimeFormat('cs-CZ', { timeZone: PRAGUE, weekday: 'short' }).format(instant)} ${clock}`;
}

function prettyDate(date, withWeekday = true) {
  const [year, month, day] = date.split('-').map(Number);
  const weekday = new Intl.DateTimeFormat('cs-CZ', { weekday: 'long', timeZone: 'UTC' }).format(new Date(Date.UTC(year, month - 1, day, 12)));
  return `${withWeekday ? `${weekday} ` : ''}${day}. ${month}.`;
}

function weekLabel(monday) {
  const friday = addDays(monday, 4);
  return `Týden ${isoWeek(monday)} · ${prettyDate(monday, false)} – ${prettyDate(friday, false)} ${friday.slice(0, 4)}`;
}

function planType() {
  return $('#planTypeWeekly').checked ? 'weekly' : 'daily';
}

function sessionDay() {
  const date = $('#planDate').value || today();
  return planType() === 'weekly' ? mondayOf(date) : date;
}

function applySessionLocks() {
  const day = sessionDay();
  const schedule = sessionSchedule(day, $('#planMarket').value);
  const now = new Date();
  $$('#planForm [data-gate]').forEach(label => {
    const at = schedule[label.dataset.gate];
    const locked = now < at;
    label.classList.toggle('is-locked', locked);
    $$('select, input', label).forEach(control => { control.disabled = locked; });
    const note = $('[data-gate-note]', label);
    if (note) note.textContent = locked ? `od ${gateTimeLabel(at, day)}` : gateTimeLabel(at, day);
  });
  renderTiming(schedule, day, now);
  return schedule;
}

function renderTiming(schedule, day, now) {
  const weekly = planType() === 'weekly';
  const current = today();
  const weekend = [0, 6].includes(weekdayOf(current));
  let eyebrow;
  let text;
  if (now < schedule.globex) {
    eyebrow = 'Příprava před otevřením';
    text = `${weekend ? 'Je víkend a trh je zavřený. ' : ''}${weekly ? 'Týden ještě nezačal.' : 'Trh pro tento den ještě neotevřel.'} Teď je čas na bias, profil, reference a zóny. Pole o otevření jsou zamčená a odemknou se sama, jakmile nastane jejich čas.`;
  } else if (now < schedule.eu) {
    eyebrow = 'Běží Globex';
    text = `Overnight běží. Otevření Globexu už můžeš doplnit, EU otevře v ${gateTimeLabel(schedule.eu, day)}.`;
  } else if (now < schedule.rth) {
    eyebrow = 'Evropská seance';
    text = `EU je otevřená. RTH otevře v ${gateTimeLabel(schedule.rth, day)}.`;
  } else if (now < schedule.ib) {
    eyebrow = 'RTH běží';
    text = `Initial Balance se tvoří do ${gateTimeLabel(schedule.ib, day)}. Typ otevření už můžeš doplnit.`;
  } else if (day >= current || (weekly && addDays(day, 4) >= current)) {
    eyebrow = weekly ? 'Týden běží' : 'Session běží';
    text = 'Všechna pole jsou odemčená. Porovnej plán s tím, co trh opravdu dělá.';
  } else {
    eyebrow = 'Zpětný náhled';
    text = 'Session už proběhla, všechna pole jsou odemčená.';
  }
  if (weekly) text += ' U týdenního náhledu se časy otevření vztahují k pondělí.';

  const phases = [['globex', 'Globex'], ['eu', 'EU open'], ['rth', 'RTH open'], ['ib', 'Initial Balance']];
  const nextKey = phases.find(([key]) => now < schedule[key])?.[0];
  $('#timingEyebrow').textContent = eyebrow;
  $('#timingTitle').textContent = weekly ? weekLabel(day) : `Náhled na ${prettyDate(day)}`;
  $('#timingText').textContent = text;
  $('#timingSteps').innerHTML = phases.map(([key, label]) => {
    const done = now >= schedule[key];
    return `<li class="${done ? 'is-done' : key === nextKey ? 'is-next' : ''}"><strong>${label}</strong><span>${gateTimeLabel(schedule[key], day)}</span></li>`;
  }).join('');
  $('#planTiming').dataset.phase = now < schedule.globex ? 'before' : now < schedule.ib ? 'live' : 'open';
}

// ---------------------------------------------------------------------------
// Týdenní kontext: value minulého týdne a týdenní bias pro denní náhled.
// ---------------------------------------------------------------------------
const weeklyContextCache = new Map();

function formNumber(name) {
  return numberOrNull($(`#planForm [name="${name}"]`)?.value);
}

function checkedValue(name) {
  return $(`#planForm [name="${name}"]:checked`)?.value || '';
}

function contextFromForm() {
  return {
    source: 'self',
    plan_id: Number($('#planId').value) || null,
    week_start: sessionDay(),
    vah: formNumber('ref_vah'),
    val: formNumber('ref_val'),
    poc: formNumber('ref_poc'),
    high: formNumber('ref_high'),
    low: formNumber('ref_low'),
    pa_monthly: checkedValue('pa_monthly'),
    pa_weekly: checkedValue('pa_weekly'),
    mp_weekly: checkedValue('mp_weekly'),
  };
}

async function refreshWeeklyContext() {
  const request = ++refreshWeeklyContext.sequence;
  if (planType() === 'weekly') {
    state.weeklyContext = contextFromForm();
    schedulePlanRefresh();
    return;
  }
  const date = $('#planDate').value;
  const market = $('#planMarket').value.trim().toUpperCase();
  if (!date || !market) {
    state.weeklyContext = null;
    schedulePlanRefresh();
    return;
  }
  const session = $('#planSession').value;
  const key = `${mondayOf(date)}|${market}|${session}`;
  try {
    const context = weeklyContextCache.get(key) || await api('weekly_context', { query: { date, market, session } });
    weeklyContextCache.set(key, context);
    if (request !== refreshWeeklyContext.sequence) return;
    state.weeklyContext = context;
    inheritWeeklyBias(context);
  } catch (error) {
    if (request === refreshWeeklyContext.sequence) state.weeklyContext = null;
  }
  schedulePlanRefresh();
}
refreshWeeklyContext.sequence = 0;

/** Nový denní náhled převezme Weekly bias z týdenního náhledu, ale jen do prázdných polí. */
function inheritWeeklyBias(context) {
  if (!context || context.source !== 'weekly' || $('#planId').value || state.biasInherited) return;
  const taken = [];
  [['pa_monthly', 'PA Monthly'], ['pa_weekly', 'PA Weekly'], ['mp_weekly', 'MP/VP Weekly']].forEach(([name, label]) => {
    const value = context[name];
    if (!value || checkedValue(name)) return;
    const radio = $(`#planForm [name="${name}"][value="${CSS.escape(value)}"]`);
    if (radio) { radio.checked = true; taken.push(label); }
  });
  [['pa_weekly_note', 'pa_weekly_note'], ['mp_weekly_note', 'mp_weekly_note']].forEach(([name, key]) => {
    const input = $(`#planForm [name="${name}"]`);
    if (input && !input.value.trim() && context[key]) input.value = context[key];
  });
  state.biasInherited = true;
  if (taken.length) $('#biasInheritHint').textContent = `Převzato z týdenního náhledu: ${taken.join(', ')}. Můžeš to přepsat.`;
}

function renderWeeklyContext() {
  const root = $('#weeklyContext');
  const context = state.weeklyContext;
  const weekly = planType() === 'weekly';
  const prices = context && context.vah !== null && context.vah !== undefined && context.val !== null && context.val !== undefined
    ? `<span class="context-prices"><b>VAH</b> ${displayPrice(context.vah)}${context.poc !== null && context.poc !== undefined ? ` <b>POC</b> ${displayPrice(context.poc)}` : ''} <b>VAL</b> ${displayPrice(context.val)}</span>`
    : '';
  if (weekly) {
    root.className = `weekly-context${prices ? ' is-ready' : ''}`;
    root.innerHTML = prices
      ? `<div><strong>Zóny se porovnávají s value minulého týdne</strong>${prices}</div><small>Hodnoty bereš z kroku 2. Denní náhledy tohoto týdne si je převezmou samy.</small>`
      : '<div><strong>Chybí value minulého týdne</strong></div><small>Doplň v kroku 2 VAH a VAL minulého týdne a u každé zóny se ukáže, kde vůči value leží.</small>';
    return;
  }
  if (!context || context.source === 'missing') {
    root.className = 'weekly-context is-missing';
    root.innerHTML = '<div><strong>Pro tento týden a trh není týdenní náhled</strong></div><small>Bez něj nejde u zón ukázat polohu vůči value minulého týdne.</small><button class="mini-button" type="button" data-new-plan="weekly">Vytvořit týdenní náhled</button>';
    return;
  }
  root.className = `weekly-context${prices ? ' is-ready' : ' is-missing'}`;
  root.innerHTML = prices
    ? `<div><strong>Value minulého týdne z týdenního náhledu</strong>${prices}</div><small>${escapeHtml(weekLabel(context.week_start))}</small><button class="mini-button" type="button" data-load-plan="${Number(context.plan_id)}">Otevřít týdenní</button>`
    : `<div><strong>Týdenní náhled nemá VAH a VAL</strong></div><small>Doplň je v týdenním náhledu v kroku 2.</small><button class="mini-button" type="button" data-load-plan="${Number(context.plan_id)}">Otevřít týdenní</button>`;
}

function displayPrice(value) {
  const number = Number(value);
  return Number.isFinite(number) ? number.toLocaleString('cs-CZ', { maximumFractionDigits: 4 }) : '—';
}

/** Stejná pravidla jako zone_value_context v bootstrap.php. */
function vaContext(lowValue, highValue, context) {
  let low = numberOrNull(lowValue);
  let high = numberOrNull(highValue);
  const vah = numberOrNull(context?.vah);
  const val = numberOrNull(context?.val);
  if ((low === null && high === null) || vah === null || val === null || vah <= val) return null;
  low ??= high;
  high ??= low;
  if (low > high) [low, high] = [high, low];
  const near = Math.max(high - low, (vah - val) * 0.05);
  const suffix = 'předchozího týdne';
  if (low <= val && high >= vah) return { key: 'span', text: `Přes celou value ${suffix}` };
  if (low <= val && high >= val) return { key: 'at_val', text: `Ve VAL ${suffix}` };
  if (low <= vah && high >= vah) return { key: 'at_vah', text: `Ve VAH ${suffix}` };
  if (high < val) return val - high <= near ? { key: 'near_val_below', text: `V oblasti VAL ${suffix}, těsně pod ní` } : { key: 'below_val', text: `Pod VAL ${suffix}` };
  if (low > vah) return low - vah <= near ? { key: 'near_vah_above', text: `V oblasti VAH ${suffix}, těsně nad ní` } : { key: 'above_vah', text: `Nad VAH ${suffix}` };
  if (low - val <= near) return { key: 'near_val_inside', text: `V oblasti VAL ${suffix}, těsně nad ní uvnitř value` };
  if (vah - high <= near) return { key: 'near_vah_inside', text: `V oblasti VAH ${suffix}, těsně pod ní uvnitř value` };
  const poc = numberOrNull(context?.poc);
  const detail = poc === null ? '' : ((low + high) / 2 >= poc ? ', nad POC' : ', pod POC');
  return { key: 'inside', text: `Uvnitř value ${suffix}${detail}` };
}

// ---------------------------------------------------------------------------
// Řádky náhledu: zóny, levely, reference a scénáře.
// ---------------------------------------------------------------------------
const MP_TOKENS = ['VAH', 'VAL', 'POC', 'nPOC', 'SP', 'Poor high', 'Poor low', 'Excess', 'LVN', 'HVN', 'IB', 'Weekly VA', 'Monthly VA', 'Composite'];
const DN_TOKENS = ['F3', 'F5', 'F7', 'COP', 'OP', 'XOP', 'Konfluence', 'Shoda', 'Naked', 'Revisited', '3x3', 'Fib node'];

/** Štítky zdroje zóny podle metodiky tradera a jeho vlastních štítků. */
function zoneTokens() {
  const method = prefs().method;
  const base = method === 'mp' ? MP_TOKENS : method === 'dn' ? DN_TOKENS : [...MP_TOKENS, ...DN_TOKENS];
  return [...new Set([...base, ...(prefs().tokens_extra || [])])];
}
const zoneStatusLabels = { planned: 'Čeká', active: 'Aktivní', hit: 'Zasažena', invalid: 'Neplatná' };
const refKinds = { single_print: 'Single prints', poor_high: 'Poor high', poor_low: 'Poor low', naked_poc: 'Naked POC', gap: 'Gap', excess: 'Excess / tail', lvn: 'LVN', other: 'Jiná reference' };
const refTokens = { single_print: 'SP', poor_high: 'Poor high', poor_low: 'Poor low', naked_poc: 'nPOC', gap: 'Gap', excess: 'Excess', lvn: 'LVN', other: '' };
const zoneFields = ['name', 'direction', 'price_low', 'price_high', 'priority', 'source', 'invalidation', 'trigger', 'stop_loss', 'tp1', 'tp2', 'rr', 'status', 'long_entry', 'long_skip', 'short_entry', 'short_skip', 'valid_to', 'zone_type', 'note'];
const zoneTypeLabels = { support: 'Support', resistance: 'Resistance', vpoc: 'VPOC', other: 'Jiná' };

/** Platnost zóny v Hindsightu: '' = jen den náhledu, 'open' = dokud ji neukončím, datum = do data. */
function zoneValidityFields(zone) {
  const validTo = String(zone.valid_to ?? '');
  const mode = validTo === 'open' ? 'open' : /^\d{4}-\d{2}-\d{2}$/.test(validTo) ? 'date' : '';
  return `<div class="zone-grid-secondary zone-hindsight">
      <label>Platnost zóny<select name="valid_mode">${optionTag('', 'Jen tento den', mode)}${optionTag('open', 'Dokud ji neukončím', mode)}${optionTag('date', 'Do data', mode)}</select></label>
      <label>Do data<input type="date" name="valid_until" value="${mode === 'date' ? escapeHtml(validTo) : ''}"${mode === 'date' ? '' : ' disabled'}></label>
      <input type="hidden" name="valid_to" value="${escapeHtml(validTo)}">
      <label>Typ zóny<select name="zone_type">${optionTag('', 'Podle směru', zone.zone_type)}${Object.entries(zoneTypeLabels).map(([value, label]) => optionTag(value, label, zone.zone_type)).join('')}</select></label>
      <label class="span-3">Poznámka k zóně<input name="note" value="${escapeHtml(zone.note)}" placeholder="Vidíš ji i v Hindsightu"></label>
    </div>`;
}

function syncZoneValidity(row) {
  const mode = $('[name="valid_mode"]', row).value;
  const until = $('[name="valid_until"]', row);
  until.disabled = mode !== 'date';
  if (mode === 'date' && !until.value) until.value = $('#planDate')?.value || '';
  $('[name="valid_to"]', row).value = mode === 'date' ? until.value : mode;
}

function sourceTokens(value) {
  return String(value ?? '').split(',').map(token => token.trim()).filter(Boolean);
}

function zoneTemplate(zone = {}, index = 0) {
  const direction = ['long', 'short', 'both'].includes(zone.direction) ? zone.direction : '';
  const tokens = sourceTokens(zone.source);
  const legacy = String(zone.trigger ?? '').trim();
  const dirButton = (value, label) => `<button type="button" class="dir-${value}" data-dir="${value}" aria-pressed="${direction === value}">${label}</button>`;
  const condition = (side, label) => `<div class="cond cond-${side}">
      <p class="cond-title">${label}</p>
      <label>Co se musí splnit, abych vstoupil<textarea name="${side}_entry" rows="2" placeholder="${side === 'long' ? 'Odmítnutí nižších cen, návrat nad VAL, BOS nahoru…' : 'Odmítnutí vyšších cen, návrat pod VAH, BOS dolů…'}">${escapeHtml(zone[`${side}_entry`])}</textarea></label>
      <label>Kdy obchod neberu<textarea name="${side}_skip" rows="2" placeholder="Akceptace za zónou, red news, třetí test, proti vyššímu TF…">${escapeHtml(zone[`${side}_skip`])}</textarea></label>
    </div>`;
  return `<article class="zone-row plan-row" data-direction="${direction}">
    <div class="row-head">
      <strong><span class="row-number">${index + 1}</span><span data-row-title>${escapeHtml(zone.name || `Zóna ${index + 1}`)}</span></strong>
      <span class="zone-badges"><span class="va-badge" data-va-context hidden></span><span class="zone-metrics" data-zone-metrics></span></span>
      <button class="remove-row" type="button" data-remove-zone>Odebrat</button>
    </div>
    <div class="zone-grid">
      <label class="span-2">Název<input name="name" value="${escapeHtml(zone.name)}" placeholder="A zóna, weekly VAL"></label>
      <label>Spodní hranice<input type="number" step="any" name="price_low" value="${escapeHtml(zone.price_low)}"></label>
      <label>Horní hranice<input type="number" step="any" name="price_high" value="${escapeHtml(zone.price_high)}"></label>
      <label>Priorita<select name="priority">${optionTag('A', 'A · primární', zone.priority)}${optionTag('B', 'B · sekundární', zone.priority)}${optionTag('C', 'C · nižší', zone.priority)}</select></label>
      <label>Stav<select name="status">${Object.entries(zoneStatusLabels).map(([value, label]) => optionTag(value, label, zone.status || 'planned')).join('')}</select></label>
    </div>
    <div class="dir-field">
      <span class="field-label">Co tu budu obchodovat</span>
      <div class="dir-switch" role="group" aria-label="Směr obchodu v zóně">${dirButton('long', 'Long')}${dirButton('short', 'Short')}${dirButton('both', 'Long i short')}</div>
      <input type="hidden" name="direction" value="${direction}">
      <small class="dir-hint">Vyber směr a zobrazí se podmínky vstupu.</small>
    </div>
    <div class="conditions" data-el="zones.conditions"${isHiddenEl('zones.conditions') ? ' hidden' : ''}>${condition('long', 'Long')}${condition('short', 'Short')}</div>
    ${legacy ? `<label class="row-extra legacy-trigger">Původní trigger (starší záznam)<input name="trigger" value="${escapeHtml(legacy)}"></label>` : '<input type="hidden" name="trigger" value="">'}
    <div class="token-field" data-el="zones.tokens"${isHiddenEl('zones.tokens') ? ' hidden' : ''}>
      <span class="field-label">Zdroj a konfluence</span>
      <div class="chip-row">${zoneTokens().map(token => `<button type="button" class="chip${tokens.includes(token) ? ' is-on' : ''}" data-token="${escapeHtml(token)}">${escapeHtml(token)}</button>`).join('')}</div>
      <input name="source" value="${escapeHtml(zone.source)}" placeholder="Vyber výše nebo dopiš vlastní, oddělené čárkou">
    </div>
    <div class="zone-grid-secondary" data-el="zones.targets"${isHiddenEl('zones.targets') ? ' hidden' : ''}>
      <label class="span-2">Invalidace zóny<input name="invalidation" value="${escapeHtml(zone.invalidation)}" placeholder="Kde přestává zóna platit"></label>
      <label>SL<input type="number" step="any" name="stop_loss" value="${escapeHtml(zone.stop_loss)}"></label>
      <label>TP1<input type="number" step="any" name="tp1" value="${escapeHtml(zone.tp1)}"></label>
      <label>TP2<input type="number" step="any" name="tp2" value="${escapeHtml(zone.tp2)}"></label>
      <label>Min. RR<input type="number" step="0.1" name="rr" value="${escapeHtml(zone.rr)}"></label>
    </div>
    ${zoneValidityFields(zone)}
  </article>`;
}

function ideaTemplate(idea = {}, index = 0) {
  return `<article class="idea-row plan-row">
    <div class="row-head"><strong><span class="row-number">${index + 1}</span><span data-row-title>${escapeHtml(idea.name || `Scénář ${index + 1}`)}</span></strong><button class="remove-row" type="button" data-remove-idea>Odebrat</button></div>
    <div class="idea-grid">
      <label class="span-2">Název setupu<input name="name" value="${escapeHtml(idea.name)}" placeholder="Rejection / continuation"></label>
      <label>Směr<select name="direction">${optionTag('long', 'Long', idea.direction)}${optionTag('short', 'Short', idea.direction)}</select></label>
      <label>Zóna<input name="zone_name" list="zoneNameOptions" value="${escapeHtml(idea.zone_name)}" placeholder="Vyber zónu"></label>
      <label>Vstup<input type="number" step="any" name="entry_price" value="${escapeHtml(idea.entry_price)}"></label>
      <label>SL<input type="number" step="any" name="stop_loss" value="${escapeHtml(idea.stop_loss)}"></label>
      <label>TP1<input type="number" step="any" name="tp1" value="${escapeHtml(idea.tp1)}"></label>
      <label>RR<input type="number" step="0.1" name="rr" value="${escapeHtml(idea.rr)}"></label>
    </div>
    <div class="idea-grid-secondary">
      <label class="span-2">Trigger<input name="trigger" value="${escapeHtml(idea.trigger)}" placeholder="Akceptace / odmítnutí / BOS…"></label>
      <label>TP2<input type="number" step="any" name="tp2" value="${escapeHtml(idea.tp2)}"></label>
      <label>Finální TP<input type="number" step="any" name="final_tp" value="${escapeHtml(idea.final_tp)}"></label>
      <label>Stav<select name="status">${optionTag('waiting', 'Čeká', idea.status)}${optionTag('active', 'Aktivní', idea.status)}${optionTag('invalid', 'Neplatný', idea.status)}${optionTag('executed', 'Realizovaný', idea.status)}</select></label>
      <label class="span-3">Poznámka<input name="notes" value="${escapeHtml(idea.notes)}"></label>
    </div>
  </article>`;
}

function levelTemplate(level = {}, index = 0) {
  return `<article class="level-row plan-row" data-kind="${escapeHtml(level.kind || '')}">
    <div class="level-grid">
      <label class="span-2">Název<input name="name" value="${escapeHtml(level.name)}" placeholder="PW VAH, PDH, VPOC…"></label>
      <label>Cena<input type="number" step="any" name="price" value="${escapeHtml(level.price)}"></label>
      <label>Charakter<select name="kind">${optionTag('', '—', level.kind)}${optionTag('support', 'Support', level.kind)}${optionTag('resistance', 'Rezistence', level.kind)}${optionTag('pivot', 'Pivot / obojí', level.kind)}</select></label>
      <label>Čára<select name="line_style">${optionTag('solid', 'Plná', level.line_style || 'solid')}${optionTag('dashed', 'Čárkovaná', level.line_style)}${optionTag('dotted', 'Tečkovaná', level.line_style)}</select></label>
      <label class="span-2">Zdroj / shoda<input name="source" value="${escapeHtml(level.source)}" placeholder="VP, MP, DiNapoli…"></label>
      <label class="span-3">Poznámka<input name="note" value="${escapeHtml(level.note)}" placeholder="Co od úrovně čekáš…"></label>
      <button class="remove-row" type="button" data-remove-level aria-label="Odebrat level">Odebrat</button>
    </div>
  </article>`;
}

function refTemplate(ref = {}) {
  const kind = refKinds[ref.kind] ? ref.kind : 'other';
  return `<article class="ref-row plan-row" data-kind="${kind}">
    <label>Typ<select name="kind">${Object.entries(refKinds).map(([value, label]) => optionTag(value, label, kind)).join('')}</select></label>
    <label>Cena / od<input type="number" step="any" name="price_low" value="${escapeHtml(ref.price_low)}"></label>
    <label>Do<input type="number" step="any" name="price_high" value="${escapeHtml(ref.price_high)}" placeholder="u pásma"></label>
    <label>Stav<select name="status">${optionTag('open', 'Na dojetí', ref.status || 'open')}${optionTag('filled', 'Dojeto', ref.status)}</select></label>
    <label class="grow">Poznámka<input name="note" value="${escapeHtml(ref.note)}" placeholder="Odkud je, kdy vznikla…"></label>
    <div class="ref-tools"><span class="ref-side" data-ref-side></span><button class="mini-button" type="button" data-ref-to-zone>Udělat zónu</button><button class="remove-row" type="button" data-remove-ref>Odebrat</button></div>
  </article>`;
}

function renderLevels(levels = []) {
  $('#levelList').innerHTML = levels.length
    ? levels.map(levelTemplate).join('')
    : '<div class="empty-state compact">Zatím žádný level. Přidej horizontální úrovně, nebo přenes hodnoty profilu z kroku 2.</div>';
}

function renderZones(zones = []) {
  $('#zoneList').innerHTML = zones.length
    ? zones.map(zoneTemplate).join('')
    : '<div class="empty-state compact">Zatím žádná zóna. Přidej lokaci a vyber, jestli na ní budeš obchodovat long, short, nebo obojí.</div>';
}

function renderIdeas(ideas = []) {
  $('#ideaList').innerHTML = ideas.length
    ? ideas.map(ideaTemplate).join('')
    : '<div class="empty-state compact">Zatím žádný scénář. Přidej potenciální obchody a jejich TP.</div>';
}

function renderRefs(refs = []) {
  $('#refList').innerHTML = refs.length
    ? refs.map(refTemplate).join('')
    : '<div class="empty-state compact">Žádná otevřená reference. Klikni na typ výše, když v profilu vidíš single prints, poor high, poor low nebo naked POC.</div>';
}

function appendRow(listSelector, html) {
  $(`${listSelector} .empty-state`)?.remove();
  $(listSelector).insertAdjacentHTML('beforeend', html);
  return $(listSelector).lastElementChild;
}

function renumberRows() {
  [['.zone-row', 'Zóna'], ['.idea-row', 'Scénář']].forEach(([selector, fallback]) => {
    $$(selector).forEach((row, index) => {
      $('.row-number', row).textContent = index + 1;
      $('[data-row-title]', row).textContent = $('[name="name"]', row).value || `${fallback} ${index + 1}`;
    });
  });
}

function updateZoneNameOptions() {
  const names = [...new Set($$('.zone-row [name="name"]').map(input => input.value.trim()).filter(Boolean))];
  $('#zoneNameOptions').innerHTML = names.map(name => `<option value="${escapeHtml(name)}"></option>`).join('');
}

/** Zóny, kde je vybraný směr, ale chybí podmínka vstupu nebo kdy obchod nebrat. */
function missingZoneConditions(zones) {
  const missing = [];
  zones.forEach((zone, index) => {
    const sides = zone.direction === 'both' ? ['long', 'short'] : ['long', 'short'].filter(side => side === zone.direction);
    sides.forEach(side => {
      const gaps = [];
      if (!String(zone[`${side}_entry`] ?? '').trim()) gaps.push('vstup');
      if (!String(zone[`${side}_skip`] ?? '').trim()) gaps.push('kdy neberu');
      if (gaps.length) missing.push(`${zone.name || `Zóna ${index + 1}`} (${side}: ${gaps.join(', ')})`);
    });
  });
  return missing;
}

function updateZoneBadges() {
  $$('.zone-row').forEach(row => {
    const rowDirection = $('[name="direction"]', row).value;
    ['long', 'short'].forEach(side => {
      const active = rowDirection === side || rowDirection === 'both';
      ['entry', 'skip'].forEach(kind => {
        const field = $(`[name="${side}_${kind}"]`, row);
        field.classList.toggle('is-missing', active && !field.value.trim());
      });
    });
    const low = numberOrNull($('[name="price_low"]', row).value);
    const high = numberOrNull($('[name="price_high"]', row).value);
    const direction = $('[name="direction"]', row).value;
    const parts = [];
    if (low !== null && high !== null) parts.push(`šířka ${displayPrice(Math.abs(high - low))} b`);
    const stop = numberOrNull($('[name="stop_loss"]', row).value);
    const target = numberOrNull($('[name="tp1"]', row).value);
    const rrInput = $('[name="rr"]', row);
    rrInput.placeholder = '';
    if ((direction === 'long' || direction === 'short') && low !== null && high !== null && stop !== null && target !== null) {
      // Vstup na první hraně, kterou cena do zóny přijde: long shora, short zdola.
      const entry = direction === 'long' ? Math.max(low, high) : Math.min(low, high);
      const risk = Math.abs(entry - stop);
      if (risk > 0) {
        const rr = Math.abs(target - entry) / risk;
        parts.push(`RR k TP1 ${displayNumber(rr, 1)}`);
        rrInput.placeholder = rr.toFixed(1);
      }
    }
    $('[data-zone-metrics]', row).textContent = parts.join(' · ');
    const context = vaContext(low, high, state.weeklyContext);
    const badge = $('[data-va-context]', row);
    badge.hidden = !context || isHiddenEl('zones.context');
    badge.textContent = context?.text || '';
    badge.dataset.tone = context?.key || '';
  });
}

function updateRefSides() {
  const close = formNumber('ref_close');
  $$('.ref-row').forEach(row => {
    const low = numberOrNull($('[name="price_low"]', row).value);
    const high = numberOrNull($('[name="price_high"]', row).value);
    const side = $('[data-ref-side]', row);
    row.dataset.kind = $('[name="kind"]', row).value;
    if (close === null || (low === null && high === null)) { side.textContent = ''; return; }
    const middle = low !== null && high !== null ? (low + high) / 2 : (low ?? high);
    const diff = middle - close;
    side.textContent = diff === 0 ? 'na close' : `${diff > 0 ? '↑' : '↓'} ${displayPrice(Math.abs(diff))} b ${diff > 0 ? 'nad' : 'pod'} close`;
    side.dataset.side = diff > 0 ? 'up' : 'down';
  });
}

function toggleSourceToken(row, token) {
  const input = $('[name="source"]', row);
  const tokens = sourceTokens(input.value);
  const next = tokens.includes(token) ? tokens.filter(item => item !== token) : [...tokens, token];
  input.value = next.join(', ');
  syncTokenChips(row);
}

function syncTokenChips(row) {
  const tokens = sourceTokens($('[name="source"]', row).value);
  $$('[data-token]', row).forEach(chip => chip.classList.toggle('is-on', tokens.includes(chip.dataset.token)));
}

function setZoneDirection(row, value) {
  $('[name="direction"]', row).value = value;
  row.dataset.direction = value;
  $$('[data-dir]', row).forEach(button => button.setAttribute('aria-pressed', String(button.dataset.dir === value)));
}

function refToZone(refRow) {
  const kind = $('[name="kind"]', refRow).value;
  const low = $('[name="price_low"]', refRow).value;
  const high = $('[name="price_high"]', refRow).value;
  const zone = {
    name: `${refKinds[kind] || 'Reference'} ${low || high}`.trim(),
    price_low: low,
    price_high: high,
    source: refTokens[kind] || '',
  };
  const row = appendRow('#zoneList', zoneTemplate(zone, $$('.zone-row').length));
  renumberRows();
  schedulePlanRefresh();
  row.scrollIntoView({ behavior: 'smooth', block: 'center' });
  $('[name="price_high"]', row).focus({ preventScroll: true });
  toast('Zóna je založená z reference. Doplň druhou hranici a směr.');
}

function refPricesToLevels() {
  const prefix = planType() === 'weekly' ? 'PW' : 'PD';
  const map = [['ref_high', 'High', 'resistance'], ['ref_vah', 'VAH', 'resistance'], ['ref_poc', 'POC', 'pivot'], ['ref_val', 'VAL', 'support'], ['ref_low', 'Low', 'support']];
  let added = 0;
  let updated = 0;
  map.forEach(([name, label, kind]) => {
    const price = formNumber(name);
    if (price === null) return;
    const title = `${prefix} ${label}`;
    const existing = $$('.level-row').find(row => $('[name="name"]', row).value.trim() === title);
    if (existing) {
      $('[name="price"]', existing).value = price;
      updated += 1;
      return;
    }
    appendRow('#levelList', levelTemplate({ name: title, price, kind, source: 'Market Profile', line_style: 'dashed' }, $$('.level-row').length));
    added += 1;
  });
  if (!added && !updated) { toast('Nejdřív vyplň v kroku 2 aspoň jednu hodnotu profilu.', 'error'); return; }
  schedulePlanRefresh();
  toast(`Levely: ${added} přidáno${updated ? `, ${updated} aktualizováno` : ''}.`);
}

// ---------------------------------------------------------------------------
// DiNapoli: levely zadané traderem a místa, kde se kryjí. Výpočet je ve
// static/dinapoli.js a stejný na serveru (lib/workspace.php).
// ---------------------------------------------------------------------------
const DN_RETRACEMENT_KINDS = ['F3', 'F5', 'F7'];
const DN_EXPANSION_KINDS = ['COP', 'OP', 'XOP'];
const DN_TYPE_LABELS = { confluence: 'Konfluence', agreement: 'Shoda' };
const DN_STATUS_LABELS = { naked: 'Naked', revisited: 'Revisited' };

function dnTimeframes() {
  const list = prefs().dn?.timeframes;
  return Array.isArray(list) && list.length ? list : (state.workspace?.registry?.dn_default_timeframes || [{ tf: 'H1', confluence: 5, agreement: 5 }]);
}

function dnTimeframeOptions(current) {
  const list = dnTimeframes().map(item => String(item.tf));
  const value = current === undefined || current === null ? list[0] : String(current);
  const options = list.map(tf => `<option value="${escapeHtml(tf)}"${tf === value ? ' selected' : ''}>${escapeHtml(tf)}</option>`);
  if (!list.includes(value)) options.unshift(`<option value="${escapeHtml(value)}" selected>${value === '' ? 'Bez TF' : `${escapeHtml(value)} · mimo nastavení`}</option>`);
  return options.join('');
}

function dnLevelTemplate(level = {}) {
  const kind = [...DN_RETRACEMENT_KINDS, ...DN_EXPANSION_KINDS].includes(level.kind) ? level.kind : 'F5';
  const status = level.status === 'revisited' ? 'revisited' : 'naked';
  const group = DN_EXPANSION_KINDS.includes(kind) ? 'expansion' : 'retracement';
  return `<article class="dn-level-row plan-row" data-group="${group}" data-status="${status}">
    <label><span class="dn-lbl">Timeframe</span><select name="timeframe" aria-label="Timeframe">${dnTimeframeOptions(level.timeframe)}</select></label>
    <label><span class="dn-lbl">Level</span><select name="kind" aria-label="Level"><optgroup label="Retracement">${DN_RETRACEMENT_KINDS.map(item => optionTag(item, item, kind)).join('')}</optgroup><optgroup label="Expanze">${DN_EXPANSION_KINDS.map(item => optionTag(item, item, kind)).join('')}</optgroup></select></label>
    <label><span class="dn-lbl">Stav</span><select name="status" aria-label="Stav">${optionTag('naked', 'Naked', status)}${optionTag('revisited', 'Revisited', status)}</select></label>
    <label><span class="dn-lbl">Cena</span><input type="number" step="any" name="price" value="${escapeHtml(level.price)}" aria-label="Cena levelu" placeholder="Hladina"></label>
    <label class="grow"><span class="dn-lbl">Poznámka</span><input name="note" maxlength="300" value="${escapeHtml(level.note)}" aria-label="Poznámka" placeholder="Swing, odkud level je…"></label>
    <span class="dn-match" data-dn-match></span>
    <button class="remove-row" type="button" data-remove-dn aria-label="Odebrat level">×</button>
  </article>`;
}

function dnLevelHead() {
  return '<div class="dn-level-head" aria-hidden="true"><span>Timeframe</span><span>Level</span><span>Stav</span><span>Cena</span><span>Poznámka</span><span></span><span></span></div>';
}

function renderDnLevels(levels = []) {
  $('#dnLevelList').innerHTML = levels.length
    ? dnLevelHead() + levels.map(dnLevelTemplate).join('')
    : '<div class="empty-state compact">Přidej levely z grafu: timeframe, F3, F5, F7 nebo expanzi COP, OP, XOP, jestli je naked nebo revisited, a hladinu. Konfluenci a shodu aplikace najde sama.</div>';
}

function addDnLevel(template = {}) {
  if (!$('#dnLevelList .dn-level-head')) {
    $('#dnLevelList').innerHTML = dnLevelHead();
  }
  const last = $$('#dnLevelList .dn-level-row').pop();
  const level = { timeframe: last ? $('[name="timeframe"]', last).value : undefined, kind: last ? $('[name="kind"]', last).value : 'F5', status: 'naked', ...template };
  const row = appendRow('#dnLevelList', dnLevelTemplate(level));
  $('[name="price"]', row).focus();
  schedulePlanRefresh();
  return row;
}

function dnLevels() {
  return collectRows('#dnLevelList', '.dn-level-row', ['timeframe', 'kind', 'status', 'price', 'note']);
}

function dnRange(low, high) {
  return low === high ? displayPrice(low) : `${displayPrice(low)} – ${displayPrice(high)}`;
}

function dnMemberText(member) {
  return `${member.kind} ${displayPrice(member.price)}${member.status === 'revisited' ? ' (revisited)' : ''}`;
}

function updateDnAnalysis() {
  if (!window.DiNapoli) return;
  const analysis = window.DiNapoli.analyze(dnLevels(), dnTimeframes());
  state.dnAnalysis = analysis;
  const matches = new Map();
  analysis.clusters.forEach(cluster => cluster.members.forEach(member => {
    if (!matches.has(member.index)) matches.set(member.index, new Set());
    matches.get(member.index).add(cluster.type);
  }));
  $$('#dnLevelList .dn-level-row').forEach((row, index) => {
    row.dataset.group = window.DiNapoli.LEVEL_KINDS[$('[name="kind"]', row).value] || '';
    row.dataset.status = $('[name="status"]', row).value;
    const types = [...(matches.get(index) || [])];
    row.dataset.match = types.length > 1 ? 'both' : types[0] || '';
    $('[data-dn-match]', row).textContent = types.map(type => DN_TYPE_LABELS[type]).join(' + ');
  });

  const clusters = analysis.clusters;
  $('#dnClusters').innerHTML = clusters.length
    ? `<p class="subhead">Konfluence a shoda<small>Tolerance podle timeframu z Nastavení</small></p>${clusters.map((cluster, index) => `<article class="dn-cluster${cluster.type === 'agreement' ? ' is-agreement' : ''}${cluster.revisited ? ' has-revisited' : ''}">
        <div><span class="dn-cluster-type">${escapeHtml(DN_TYPE_LABELS[cluster.type])} · ${escapeHtml(cluster.timeframe || 'bez TF')}</span><strong>${escapeHtml(dnRange(cluster.low, cluster.high))}</strong><small>${escapeHtml(cluster.members.map(dnMemberText).join(' · '))} · tolerance ${escapeHtml(displayPrice(cluster.tolerance))} b</small></div>
        <button class="button button-small" type="button" data-dn-cluster="${index}">Udělat zónu</button>
      </article>`).join('')}`
    : (analysis.levels.length > 1 ? '<p class="section-hint">Levely se zatím nekryjí. Konfluence vzniká ze dvou F5 levelů, shoda z expanze u retracementu, vždy na stejném timeframu a do tolerance z Nastavení.</p>' : '');
  const count = analysis.levels.length;
  const confluences = clusters.filter(cluster => cluster.type === 'confluence').length;
  const agreements = clusters.length - confluences;
  $('#dnSummary').textContent = count
    ? [`${count} ${count === 1 ? 'level' : count < 5 ? 'levely' : 'levelů'}`, confluences ? `${confluences} ${confluences < 5 ? 'konfluence' : 'konfluencí'}` : '', agreements ? `${agreements} ${agreements === 1 ? 'shoda' : agreements < 5 ? 'shody' : 'shod'}` : ''].filter(Boolean).join(' · ')
    : 'Levely, konfluence a shoda';
}

function dnLevelName(level) {
  return `${level.timeframe ? `${level.timeframe} ` : ''}${level.kind}${level.status === 'revisited' ? ' R' : ''}`;
}

function dnLevelsToKeyLevels() {
  const levels = state.dnAnalysis?.levels || [];
  if (!levels.length) { toast('Nejdřív zadej DiNapoli levely s cenou.', 'error'); return; }
  let added = 0;
  let updated = 0;
  levels.forEach(level => {
    const title = dnLevelName(level);
    const existing = $$('.level-row').find(item => $('[name="name"]', item).value.trim() === title && $('[name="source"]', item).value.trim() === 'DiNapoli');
    if (existing) {
      $('[name="price"]', existing).value = level.price;
      updated += 1;
      return;
    }
    appendRow('#levelList', levelTemplate({ name: title, price: level.price, kind: '', source: 'DiNapoli', line_style: level.group === 'retracement' ? 'dashed' : 'dotted', note: level.note }, $$('.level-row').length));
    added += 1;
  });
  schedulePlanRefresh();
  toast(`Klíčové levely: ${added} přidáno${updated ? `, ${updated} aktualizováno` : ''}.`);
}

function dnClusterPad(cluster) {
  return cluster.low === cluster.high ? Math.max((cluster.tolerance || 0) / 2, 0) : 0;
}

function dnClusterToZone(cluster) {
  const pad = dnClusterPad(cluster);
  const low = cluster.low - pad;
  const high = cluster.high + pad;
  const label = DN_TYPE_LABELS[cluster.type];
  const kinds = [...new Set(cluster.members.map(member => member.kind))];
  const zone = {
    name: `${label}${cluster.timeframe ? ` ${cluster.timeframe}` : ''} ${displayPrice((low + high) / 2)}`,
    price_low: Math.round(low * 1e4) / 1e4,
    price_high: Math.round(high * 1e4) / 1e4,
    source: [label, ...kinds, cluster.revisited ? 'Revisited' : 'Naked'].join(', '),
  };
  const row = appendRow('#zoneList', zoneTemplate(zone, $$('.zone-row').length));
  renumberRows();
  schedulePlanRefresh();
  row.scrollIntoView({ behavior: 'smooth', block: 'center' });
  toast(`Zóna je založená z ${cluster.type === 'agreement' ? 'shody' : 'konfluence'}. Vyber směr a podmínky vstupu.`);
}

function syncDnPatternChips() {
  const selected = sourceTokens($('#dnPatterns').value);
  $$('#dnPatternChips [data-dn-pattern]').forEach(chip => chip.classList.toggle('is-on', selected.includes(chip.dataset.dnPattern)));
}

/** Věty do pracovního závěru z DiNapoli části. */
function dnConclusion(data) {
  if (isHiddenEl('dn.trend') && isHiddenEl('dn.swings') && isHiddenEl('dn.patterns')) return [];
  const parts = [];
  const fast = data.dn_dma_3x3;
  const slow = data.dn_dma_25x5;
  if (fast && slow) {
    if (fast === 'above' && slow === 'above') parts.push('Cena je nad 3x3 i 25x5 DMA: trend nahoru, hledej long z Fibonacci supportů.');
    else if (fast === 'below' && slow === 'below') parts.push('Cena je pod 3x3 i 25x5 DMA: trend dolů, hledej short z Fibonacci rezistencí.');
    else parts.push('3x3 a 25x5 DMA se neshodují: trh je v přechodu, obchoduj hlavně od konfluence a shody.');
  } else if (fast) {
    parts.push(fast === 'above' ? 'Cena je nad 3x3 DMA.' : 'Cena je pod 3x3 DMA.');
  }
  if (data.dn_thrust === 'up') parts.push('Běží thrust nahoru; první návrat k F3 nebo F5 bývá nejlepší místo pro long.');
  if (data.dn_thrust === 'down') parts.push('Běží thrust dolů; první návrat k F3 nebo F5 bývá nejlepší místo pro short.');
  const clusters = state.dnAnalysis?.clusters || [];
  if (clusters.length && !isHiddenEl('dn.swings')) {
    parts.push(`Nejsilnější DiNapoli místa: ${clusters.slice(0, 3).map(cluster => `${DN_TYPE_LABELS[cluster.type].toLowerCase()} ${cluster.timeframe ? `${cluster.timeframe} ` : ''}${cluster.low === cluster.high ? displayPrice(cluster.low) : `${displayPrice(cluster.low)}–${displayPrice(cluster.high)}`}${cluster.revisited ? ' (s revisited levelem)' : ''}`).join(', ')}.`);
  }
  const patterns = sourceTokens(data.dn_patterns);
  if (patterns.length && !isHiddenEl('dn.patterns')) parts.push(`Sleduješ vzory: ${patterns.join(', ')}.`);
  return parts;
}

function bindDnEvents() {
  $('#addDnLevel').addEventListener('click', () => addDnLevel());
  $('#dnToLevels').addEventListener('click', dnLevelsToKeyLevels);
  $('#dnLevelList').addEventListener('click', event => {
    const row = event.target.closest('.dn-level-row');
    if (!row || !event.target.closest('[data-remove-dn]')) return;
    row.remove();
    if (!$$('#dnLevelList .dn-level-row').length) renderDnLevels([]);
    schedulePlanRefresh();
  });
  // Enter v ceně posledního levelu přidá další řádek se stejným timeframem a typem.
  $('#dnLevelList').addEventListener('keydown', event => {
    if (event.key !== 'Enter' || event.target.name !== 'price') return;
    event.preventDefault();
    const row = event.target.closest('.dn-level-row');
    if (row === $$('#dnLevelList .dn-level-row').pop()) addDnLevel();
    else $('[name="price"]', row.nextElementSibling)?.focus();
  });
  $('#dnClusters').addEventListener('click', event => {
    const button = event.target.closest('[data-dn-cluster]');
    const cluster = button ? state.dnAnalysis?.clusters?.[Number(button.dataset.dnCluster)] : null;
    if (cluster) dnClusterToZone(cluster);
  });
  $('#dnPatternChips').addEventListener('click', event => {
    const chip = event.target.closest('[data-dn-pattern]');
    if (!chip) return;
    const selected = sourceTokens($('#dnPatterns').value);
    const next = selected.includes(chip.dataset.dnPattern) ? selected.filter(item => item !== chip.dataset.dnPattern) : [...selected, chip.dataset.dnPattern];
    $('#dnPatterns').value = next.join(', ');
    syncDnPatternChips();
    schedulePlanRefresh();
  });
}

// ---------------------------------------------------------------------------
// Hodnoty formuláře náhledu. FormData vynechává zamčená pole a řádky zón mají
// stejná jména jako pole náhledu (status), proto se čte ručně.
// ---------------------------------------------------------------------------
function planFieldValues(form = $('#planForm')) {
  const data = {};
  [...form.elements].forEach(control => {
    if (!control.name || control.closest('.plan-row') || ['file', 'button', 'submit'].includes(control.type)) return;
    if (control.type === 'radio' || control.type === 'checkbox') {
      if (control.checked) data[control.name] = control.value;
      else if (!(control.name in data)) data[control.name] = '';
      return;
    }
    data[control.name] = control.value;
  });
  return data;
}

function deriveCloseLocation() {
  const close = formNumber('ref_close');
  const vah = formNumber('ref_vah');
  const val = formNumber('ref_val');
  const poc = formNumber('ref_poc');
  if (close === null || vah === null || val === null || vah <= val) return;
  let location = null;
  if (close > vah) location = 'above';
  else if (close < val) location = 'below';
  else if (poc !== null) {
    if (Math.abs(close - poc) <= (vah - val) * 0.05) location = 'poc';
    else location = close > poc ? 'upper' : 'lower';
  }
  if (!location) return;
  const radio = $(`#planForm [name="previous_close"][value="${location}"]`);
  if (radio && !radio.checked) {
    radio.checked = true;
    $('#closeLadderHint').textContent = 'Doplněno automaticky z Close, VAH, POC a VAL. Kliknutím to můžeš změnit.';
  }
}

const shapeNotes = {
  p: 'P profil ukazuje přijetí vyšších cen, často jako short covering; bez navazujících kupců hrozí návrat do spodní části.',
  b: 'b profil ukazuje přijetí nižších cen, často jako likvidaci longů; bez nových prodejců se trh často vrací nahoru.',
  d: 'D profil je vyvážená aukce; hrany value jsou pro obchod důležitější než její střed.',
  double: 'Dvojitá distribuce: klíčovou referencí je oblast mezi oběma distribucemi.',
  trend: 'Protažený trendový profil ukazuje iniciativu jedné strany; proti ní neobchoduj bez zřetelného selhání.',
};
const closeNotes = {
  above: 'Close nad VAH znamená přijetí cen nad value.',
  upper: 'Close v horní polovině value drží kupce ve výhodě.',
  poc: 'Close na POC je rovnováha, směr určí až otevření.',
  lower: 'Close ve spodní polovině value drží prodejce ve výhodě.',
  below: 'Close pod VAL znamená přijetí cen pod value.',
};
const biasWord = { long: 'long', short: 'short', balance: 'balance' };

function biasAlignment(data) {
  const frames = [['PA M', data.pa_monthly], ['PA W', data.pa_weekly], ['PA D', data.pa_daily], ['MP W', data.mp_weekly], ['MP D', data.mp_daily]].filter(([, value]) => value);
  const counts = { long: 0, short: 0, balance: 0 };
  frames.forEach(([, value]) => { counts[value] += 1; });
  return { frames, counts };
}

function updateAlignment() {
  const { frames, counts } = biasAlignment(planFieldValues());
  const element = $('#paAlignment');
  element.className = 'alignment';
  if (frames.length < 2) {
    element.textContent = 'Doplň aspoň dva timeframy';
    return;
  }
  const leader = Object.entries(counts).sort((a, b) => b[1] - a[1])[0];
  if (leader[1] === frames.length) {
    element.textContent = `Souhra: vše ${biasWord[leader[0]]}`;
    element.classList.add(`is-${leader[0]}`, 'is-aligned');
  } else {
    element.textContent = `Smíšené: ${counts.long} long · ${counts.short} short · ${counts.balance} balance`;
    element.classList.add('is-mixed');
  }
}

function buildConclusion() {
  const data = planFieldValues();
  const parts = [];
  const { frames, counts } = biasAlignment(data);
  if (frames.length >= 2) {
    if (counts.long === frames.length) parts.push('Price action i profil ukazují na všech vyplněných timeframech long; obchody proti tomu potřebují mimořádný důvod.');
    else if (counts.short === frames.length) parts.push('Price action i profil ukazují na všech vyplněných timeframech short; obchody proti tomu potřebují mimořádný důvod.');
    else if (counts.long && counts.short) parts.push('Timeframy si odporují; menší risk a obchody ve směru vyššího timeframu.');
    else parts.push('Část timeframů je v balance; výhodu mají obchody od hran range, ne uprostřed.');
  }

  if (data.value_area === 'rising' && data.vpoc === 'rising') parts.push('Value i POC migrují výš, preferovaný je long scénář.');
  else if (data.value_area === 'falling' && data.vpoc === 'falling') parts.push('Value i POC migrují níž, preferovaný je short scénář.');
  else if (data.value_area || data.vpoc) parts.push('Migrace value a POC není v souladu; směrová výhoda je slabší.');

  if (shapeNotes[data.profile_shape]) parts.push(shapeNotes[data.profile_shape]);
  if (closeNotes[data.previous_close]) parts.push(closeNotes[data.previous_close]);
  if (data.auction === 'imbalance') parts.push('Aukce má iniciativní charakter; protisměr vyžaduje zřetelné selhání iniciativy.');
  if (data.auction === 'balance') parts.push('Aukce rotuje; větší význam mají hrany balance než její střed.');
  if (planType() === 'daily') {
    if (data.weekly_position === 'above') parts.push('Cena je nad týdenní VAH; ta může při návratu fungovat jako support.');
    if (data.weekly_position === 'below') parts.push('Cena je pod týdenní VAL; ta může při návratu fungovat jako rezistence.');
    if (data.weekly_position === 'inside') parts.push('Cena je uvnitř týdenní value mezi VAL a VAH.');
  }

  const signs = [data.single_print, data.tail].filter(Boolean);
  const buySigns = signs.filter(sign => sign === 'buy').length;
  const sellSigns = signs.filter(sign => sign === 'sell').length;
  if (buySigns) parts.push('Single prints nebo excess dole podporují kupce, dokud zůstanou respektované.');
  if (sellSigns) parts.push('Single prints nebo excess nahoře podporují prodejce, dokud zůstanou respektované.');
  if ((data.bias === 'long' && sellSigns) || (data.bias === 'short' && buySigns)) parts.push('Profil obsahuje argument proti zvolenému biasu; vyžaduj silnější potvrzení.');

  const open = collectRows('#refList', '.ref-row', ['kind', 'status', 'price_low']).filter(ref => ref.status !== 'filled' && ref.price_low);
  if (open.length) {
    const grouped = open.reduce((acc, ref) => { acc[refKinds[ref.kind] || ref.kind] = (acc[refKinds[ref.kind] || ref.kind] || 0) + 1; return acc; }, {});
    parts.push(`Na dojetí zůstává: ${Object.entries(grouped).map(([name, count]) => (count > 1 ? `${count}× ${name}` : name)).join(', ')}.`);
  }
  parts.push(...dnConclusion(data));
  if (data.initial_balance === 'small') parts.push('Menší IB nechává prostor pro range extension.');
  if (String(data.initial_balance || '').startsWith('large')) parts.push('Velká IB mohla spotřebovat velkou část denního rozpětí; sleduj přechod do rotace.');

  if (!parts.length) parts.push(prefs().method === 'dn' ? 'Doplň bias a trend podle DMA.' : 'Doplň bias, profil a migraci value.');
  $('#workingConclusion').textContent = parts.join(' ');
}

function planSectionState() {
  const data = planFieldValues();
  const filled = keys => keys.filter(key => String(data[key] ?? '').trim()).length;
  const zones = collectRows('#zoneList', '.zone-row', zoneFields);
  const conditionsOn = !isHiddenEl('zones.conditions');
  const readyZones = zones.filter(zone => zone.price_low && zone.price_high && zone.direction && (!conditionsOn || !missingZoneConditions([zone]).length));
  const gated = $$('#planForm [data-gate]').filter(label => !label.hidden);
  const open = gated.filter(label => !label.classList.contains('is-locked'));
  const weekly = planType() === 'weekly';
  const biasKeys = [
    ...(isHiddenEl('bias.pa') ? [] : weekly ? ['pa_monthly', 'pa_weekly'] : ['pa_monthly', 'pa_weekly', 'pa_daily']),
    ...(isHiddenEl('bias.mp') ? [] : weekly ? ['mp_weekly'] : ['mp_weekly', 'mp_daily']),
  ];
  const average = values => (values.length ? values.reduce((sum, value) => sum + value, 0) / values.length : null);
  const profile = [];
  if (!isHiddenEl('profile.shape')) profile.push(data.profile_shape ? 1 : 0);
  if (!isHiddenEl('profile.close')) profile.push(data.previous_close ? 1 : 0);
  if (!isHiddenEl('profile.values')) profile.push(data.ref_vah && data.ref_val ? 1 : 0);
  if (!isHiddenEl('profile.auction')) profile.push(filled(['value_area', 'vpoc', 'auction']) / 3);
  const dn = [];
  if (!isHiddenEl('dn.trend')) dn.push(Math.min(1, filled(['dn_dma_3x3', 'dn_dma_7x5', 'dn_dma_25x5', 'dn_thrust']) / 2));
  if (!isHiddenEl('dn.swings')) dn.push((state.dnAnalysis?.levels || []).length ? 1 : 0);
  const customFields = activeFields('plan');
  const result = {
    structure: biasKeys.length ? filled(biasKeys) / biasKeys.length : null,
    profile: average(profile),
    refs: $$('.ref-row').length ? 1 : null,
    dinapoli: average(dn),
    open: open.length ? open.filter(label => $('select', label).value).length / open.length : null,
    zones: zones.length ? (readyZones.length ? Math.min(1, 0.5 + readyZones.length / zones.length / 2) : 0.25) : 0,
    ideas: $$('.idea-row').length ? 1 : 0,
    custom: customFields.length ? customFields.filter(field => String(data[`cf_${field.id}`] ?? '').trim() !== '').length / customFields.length : null,
    bias: ((data.bias && data.bias !== 'neutral') || data.bias_description.trim() ? 0.5 : 0) + (data.bias_invalidation.trim() || data.no_trade_conditions.trim() ? 0.5 : 0),
  };
  // Skryté části se do připravenosti nepočítají.
  Object.keys(result).forEach(key => {
    if ($(`#planForm [data-section="${key}"]`)?.hidden) result[key] = null;
  });
  return result;
}

function updatePlanProgress() {
  const sections = planSectionState();
  const counted = Object.values(sections).filter(value => value !== null);
  const percent = Math.round((counted.reduce((sum, value) => sum + value, 0) / counted.length) * 100);
  $('#planProgressBar').style.width = `${percent}%`;
  $('#planProgressText').textContent = `${percent} %`;
  $$('#planSteps [data-step]').forEach(button => {
    const value = sections[button.dataset.step];
    const locked = button.dataset.step === 'open' && value === null;
    button.classList.toggle('is-locked', locked);
    button.classList.toggle('is-done', value === 1);
    button.classList.toggle('is-partial', value !== null && value > 0 && value < 1);
  });
}

function schedulePlanRefresh() {
  clearTimeout(schedulePlanRefresh.timer);
  schedulePlanRefresh.timer = setTimeout(refreshPlanInsights, 90);
}

function refreshPlanInsights() {
  updateDnAnalysis();
  if (planType() === 'weekly') state.weeklyContext = contextFromForm();
  renderWeeklyContext();
  updateAlignment();
  buildConclusion();
  updateZoneBadges();
  updateRefSides();
  updateZoneNameOptions();
  renderPriceMap();
  updatePlanProgress();
}

function onPlanTypeChange() {
  const weekly = planType() === 'weekly';
  if (weekly && $('#planDate').value) $('#planDate').value = mondayOf($('#planDate').value);
  $('#planDateLabel').textContent = weekly ? 'Týden od (pondělí)' : 'Den session';
  $('#profileHeading').textContent = weekly ? 'Profil minulého týdne' : 'Profil předchozího dne';
  $('#refPricesTitle').textContent = weekly ? 'Hodnoty minulého týdne' : 'Hodnoty předchozího dne';
  $('#openHeading').textContent = weekly ? 'Otevření týdne (pondělí)' : 'Otevření a první hodina';
  $('#biasHeading').textContent = weekly ? 'Bias na týden' : 'Bias na den';
  $$('[data-daily-only]').forEach(element => { element.hidden = weekly; });
  $$('#planForm [name="pa_daily"], #planForm [name="mp_daily"]').forEach(input => input.closest('.pa-row').classList.toggle('is-muted', weekly));
  $('#view-plan').dataset.planType = weekly ? 'weekly' : 'daily';
  if ($('#view-plan').classList.contains('is-active')) $('#viewEyebrow').textContent = weekly ? 'Týdenní náhled' : 'Denní náhled';
  applySessionLocks();
  refreshWeeklyContext();
}

// ---------------------------------------------------------------------------
// Mapa ceny: všechny ceny náhledu na jedné svislé ose.
// ---------------------------------------------------------------------------
function niceStep(raw) {
  const power = 10 ** Math.floor(Math.log10(raw));
  const unit = raw / power;
  return (unit < 1.5 ? 1 : unit < 3 ? 2 : unit < 7 ? 5 : 10) * power;
}

function renderPriceMap() {
  const root = $('#priceMap');
  const weekly = planType() === 'weekly';
  const prefix = weekly ? 'PW' : 'PD';
  const items = [];
  const vah = formNumber('ref_vah');
  const val = formNumber('ref_val');
  if (vah !== null && val !== null && vah > val) items.push({ type: 'band', cls: 'pm-va-band', low: val, high: vah });
  [['ref_high', 'High', 'pm-extreme'], ['ref_vah', 'VAH', 'pm-va'], ['ref_poc', 'POC', 'pm-poc'], ['ref_val', 'VAL', 'pm-va'], ['ref_low', 'Low', 'pm-extreme']].forEach(([name, label, cls]) => {
    const price = formNumber(name);
    if (price !== null) items.push({ type: 'line', cls, price, label: `${prefix} ${label}` });
  });
  const close = formNumber('ref_close');
  if (close !== null) items.push({ type: 'marker', cls: 'pm-close', price: close, label: `${prefix} close` });
  const context = state.weeklyContext;
  if (!weekly && context?.vah != null && context?.val != null) {
    items.push({ type: 'band', cls: 'pm-weekly-band', low: Number(context.val), high: Number(context.vah) });
    items.push({ type: 'line', cls: 'pm-weekly', price: Number(context.vah), label: 'PW VAH' });
    items.push({ type: 'line', cls: 'pm-weekly', price: Number(context.val), label: 'PW VAL' });
  }
  collectRows('#zoneList', '.zone-row', ['name', 'direction', 'price_low', 'price_high']).forEach((zone, index) => {
    const low = numberOrNull(zone.price_low);
    const high = numberOrNull(zone.price_high);
    if (low === null && high === null) return;
    const a = low ?? high;
    const b = high ?? low;
    items.push({ type: 'zone', cls: `pm-zone is-${zone.direction || 'none'}`, low: Math.min(a, b), high: Math.max(a, b), label: zone.name || `Zóna ${index + 1}` });
  });
  collectRows('#levelList', '.level-row', ['name', 'price', 'kind']).forEach(level => {
    const price = numberOrNull(level.price);
    if (price !== null && !items.some(item => item.label === level.name && item.price === price)) items.push({ type: 'line', cls: `pm-level is-${level.kind || 'pivot'}`, price, label: level.name || 'Level' });
  });
  collectRows('#refList', '.ref-row', ['kind', 'price_low', 'price_high', 'status']).forEach(ref => {
    if (ref.status === 'filled') return;
    const low = numberOrNull(ref.price_low);
    const high = numberOrNull(ref.price_high);
    if (low === null && high === null) return;
    const label = refKinds[ref.kind] || 'Reference';
    if (low !== null && high !== null && low !== high) items.push({ type: 'band', cls: 'pm-ref-band', low: Math.min(low, high), high: Math.max(low, high), label });
    else items.push({ type: 'line', cls: 'pm-ref', price: low ?? high, label });
  });

  if (!isHiddenEl('dn.swings')) {
    (state.dnAnalysis?.clusters || []).forEach(cluster => {
      const pad = dnClusterPad(cluster);
      items.push({ type: 'band', cls: 'pm-cluster', low: cluster.low - pad, high: cluster.high + pad, label: `${DN_TYPE_LABELS[cluster.type]} ${cluster.timeframe}`.trim() });
    });
    (state.dnAnalysis?.levels || []).forEach(level => {
      items.push({ type: 'line', cls: level.group === 'retracement' ? 'pm-fib' : 'pm-objective', price: level.price, label: dnLevelName(level) });
    });
  }
  const prices = items.flatMap(item => (item.type === 'zone' || item.type === 'band' ? [item.low, item.high] : [item.price]));
  if (!prices.length) {
    root.innerHTML = '<div class="empty-state compact">Zadej ceny profilu, zón nebo levelů a uvidíš je tady na jedné ose.</div>';
    return;
  }
  let min = Math.min(...prices);
  let max = Math.max(...prices);
  if (min === max) { min -= 1; max += 1; }
  const pad = (max - min) * 0.08;
  min -= pad;
  max += pad;
  const width = 320;
  const height = 460;
  const top = 14;
  const bottom = height - 14;
  const plotLeft = 52;
  const plotRight = 184;
  const labelX = 196;
  const y = price => top + ((max - price) / (max - min)) * (bottom - top);
  const step = niceStep((max - min) / 6);
  const digits = step < 1 ? 2 : 0;
  const ticks = [];
  for (let tick = Math.ceil(min / step) * step; tick <= max; tick += step) ticks.push(tick);

  const shapes = [];
  const labels = [];
  items.forEach(item => {
    if (item.type === 'band') {
      shapes.push(`<rect class="${item.cls}" x="${plotLeft}" y="${y(item.high).toFixed(1)}" width="${plotRight - plotLeft}" height="${Math.max(1.5, y(item.low) - y(item.high)).toFixed(1)}"/>`);
      if (item.label) labels.push({ y: y((item.low + item.high) / 2), text: item.label, cls: item.cls });
    } else if (item.type === 'zone') {
      shapes.push(`<rect class="${item.cls}" x="${plotLeft + 14}" y="${y(item.high).toFixed(1)}" width="${plotRight - plotLeft - 28}" height="${Math.max(3, y(item.low) - y(item.high)).toFixed(1)}" rx="3"/>`);
      labels.push({ y: y((item.low + item.high) / 2), text: item.label, cls: item.cls });
    } else if (item.type === 'marker') {
      const markerY = y(item.price);
      shapes.push(`<path class="${item.cls}" d="M${plotRight - 2} ${markerY.toFixed(1)} l8 -5 v10 z"/><line class="${item.cls}" x1="${plotLeft}" x2="${plotRight}" y1="${markerY.toFixed(1)}" y2="${markerY.toFixed(1)}"/>`);
      labels.push({ y: markerY, text: item.label, cls: item.cls });
    } else {
      const lineY = y(item.price).toFixed(1);
      shapes.push(`<line class="${item.cls}" x1="${plotLeft}" x2="${plotRight}" y1="${lineY}" y2="${lineY}"/>`);
      labels.push({ y: Number(lineY), text: item.label, cls: item.cls });
    }
  });

  labels.sort((a, b) => a.y - b.y);
  const gap = 14;
  labels.forEach((label, index) => { label.ly = index === 0 ? Math.max(top + 4, label.y) : Math.max(label.y, labels[index - 1].ly + gap); });
  for (let index = labels.length - 1; index >= 0; index -= 1) {
    const limit = index === labels.length - 1 ? bottom - 2 : labels[index + 1].ly - gap;
    if (labels[index].ly > limit) labels[index].ly = limit;
  }
  const trim = text => (text.length > 17 ? `${text.slice(0, 16)}…` : text);

  root.innerHTML = `<svg viewBox="0 0 ${width} ${height}" role="img" aria-label="Mapa cen náhledu">
    ${ticks.map(tick => `<line class="pm-grid" x1="${plotLeft}" x2="${plotRight}" y1="${y(tick).toFixed(1)}" y2="${y(tick).toFixed(1)}"/><text class="pm-tick" x="${plotLeft - 6}" y="${(y(tick) + 3.5).toFixed(1)}" text-anchor="end">${escapeHtml(tick.toLocaleString('cs-CZ', { minimumFractionDigits: digits, maximumFractionDigits: digits }))}</text>`).join('')}
    ${shapes.join('')}
    ${labels.map(label => `<path class="pm-leader" d="M${plotRight} ${label.y.toFixed(1)} L${labelX - 4} ${label.ly.toFixed(1)}"/><text class="pm-label ${label.cls}" x="${labelX}" y="${(label.ly + 3.5).toFixed(1)}">${escapeHtml(trim(label.text))}</text>`).join('')}
  </svg>`;
}

// ---------------------------------------------------------------------------
// Uložení, načtení a export náhledu.
// ---------------------------------------------------------------------------
function serializePlan() {
  const data = planFieldValues();
  data.id = data.id ? Number(data.id) : null;
  data.plan_type = planType();
  data.bias = data.bias || 'neutral';
  data.zones = collectRows('#zoneList', '.zone-row', zoneFields);
  data.levels = collectRows('#levelList', '.level-row', ['name', 'price', 'kind', 'source', 'line_style', 'note']);
  data.ideas = collectRows('#ideaList', '.idea-row', ['name', 'direction', 'zone_name', 'trigger', 'entry_price', 'stop_loss', 'tp1', 'tp2', 'final_tp', 'rr', 'status', 'notes']);
  data.refs = collectRows('#refList', '.ref-row', ['kind', 'price_low', 'price_high', 'status', 'note']);
  data.dn_levels = dnLevels();
  data.custom = extractCustomValues(data, 'plan');
  return data;
}

async function savePlan({ quiet = false } = {}) {
  const payload = serializePlan();
  if (!payload.plan_date || !payload.market) throw new Error('Doplň datum a trh.');
  // Každý obchodovaný směr musí mít definici vstupu i toho, kdy obchod nebrat.
  // Rozpracovaný náhled se uloží i bez ní, připravený ne.
  const missing = isHiddenEl('zones.conditions') ? [] : missingZoneConditions(payload.zones);
  if (missing.length && payload.status === 'ready') {
    updateZoneBadges();
    throw new Error(`Připravený náhled potřebuje u každého směru podmínky. Chybí: ${missing.join('; ')}.`);
  }
  const plan = await api('plan', { method: 'POST', body: payload });
  state.currentPlan = plan;
  state.currentScreenshots = plan.screenshots || [];
  $('#planId').value = plan.id;
  if (plan.plan_type === 'weekly') weeklyContextCache.clear();
  if (plan.plan_date !== $('#planDate').value) $('#planDate').value = plan.plan_date;
  state.weeklyContext = plan.weekly_context || state.weeklyContext;
  renderScreenshots();
  schedulePlanRefresh();
  if (!quiet) {
    const saved = plan.plan_type === 'weekly' ? 'Týdenní náhled je uložený.' : 'Denní náhled je uložený.';
    toast(missing.length ? `${saved} U ${missing.length === 1 ? 'jedné zóny' : `${missing.length} směrů`} ještě chybí podmínky, jsou zvýrazněné.` : saved);
  }
  await Promise.all([refreshPlans(), refreshDashboard()]);
  return plan;
}

function downloadPlanPdf(id) {
  const link = document.createElement('a');
  link.href = `pdf.php?id=${encodeURIComponent(id)}`;
  link.setAttribute('download', '');
  document.body.appendChild(link);
  link.click();
  link.remove();
}

async function exportCurrentPlanPdf() {
  const button = $('#exportPlanPdf');
  const originalLabel = button.innerHTML;
  button.disabled = true;
  button.textContent = 'Připravuji PDF…';
  try {
    const plan = await savePlan({ quiet: true });
    downloadPlanPdf(plan.id);
    toast('Náhled je uložený a PDF se stahuje.');
  } finally {
    button.disabled = false;
    button.innerHTML = originalLabel;
  }
}

function tradingViewSettings() {
  return {
    market: $('#planMarket').value || 'ES',
    date: $('#planDate').value || today(),
    showLabels: $('#tvShowLabels').checked,
    includeSource: $('#tvIncludeSource').checked,
    extendMode: $('#tvExtendMode').value,
    fillTransparency: $('#tvFillTransparency').value,
  };
}

const REF_PINE_COLOR = 'color.rgb(167, 139, 250)';
const PROFILE_PINE_COLOR = 'color.rgb(122, 162, 255)';

const DN_RETRACEMENT_PINE_COLOR = 'color.rgb(63, 211, 161)';
const DN_OBJECTIVE_PINE_COLOR = 'color.rgb(214, 179, 111)';

function tradingViewItems() {
  const zones = collectRows('#zoneList', '.zone-row', ['name', 'direction', 'price_low', 'price_high', 'source']);
  const levels = collectRows('#levelList', '.level-row', ['name', 'price', 'kind', 'source', 'line_style']);
  if ($('#tvIncludeDn').checked && !isHiddenEl('dn.swings') && state.dnAnalysis) {
    state.dnAnalysis.clusters.forEach(cluster => {
      const pad = dnClusterPad(cluster);
      const name = `${DN_TYPE_LABELS[cluster.type]} ${cluster.timeframe}`.trim();
      zones.push({ name, direction: '', price_low: cluster.low - pad, price_high: cluster.high + pad, source: cluster.members.map(member => `${member.kind} ${member.price}`).join(' + '), pine_color: DN_OBJECTIVE_PINE_COLOR });
    });
    // Levely už přenesené do klíčových levelů se neopakují.
    const present = new Set(levels.map(level => `${String(level.name).trim()}|${Number(level.price)}`));
    state.dnAnalysis.levels.filter(level => !present.has(`${dnLevelName(level)}|${level.price}`)).forEach(level => {
      levels.push({ name: dnLevelName(level), price: level.price, kind: '', source: 'DiNapoli', line_style: level.group === 'retracement' ? 'dashed' : 'dotted', pine_color: level.group === 'retracement' ? DN_RETRACEMENT_PINE_COLOR : DN_OBJECTIVE_PINE_COLOR });
    });
  }
  if (!$('#tvIncludeRefs').checked || isHiddenEl('profile.values')) return { zones, levels };
  const names = new Set(levels.map(level => level.name.trim()));
  const prefix = planType() === 'weekly' ? 'PW' : 'PD';
  [['ref_high', 'High'], ['ref_vah', 'VAH'], ['ref_poc', 'POC'], ['ref_val', 'VAL'], ['ref_low', 'Low']].forEach(([name, label]) => {
    const price = formNumber(name);
    const title = `${prefix} ${label}`;
    if (price !== null && !names.has(title)) levels.push({ name: title, price, kind: 'pivot', source: 'Market Profile', line_style: 'dashed', pine_color: PROFILE_PINE_COLOR });
  });
  const context = state.weeklyContext;
  if (planType() === 'daily' && context?.vah != null && context?.val != null) {
    [['PW VAH', context.vah], ['PW VAL', context.val]].forEach(([title, price]) => {
      if (!names.has(title)) levels.push({ name: title, price, kind: 'pivot', source: 'Týdenní náhled', line_style: 'dashed', pine_color: PROFILE_PINE_COLOR });
    });
  }
  collectRows('#refList', '.ref-row', ['kind', 'price_low', 'price_high', 'status', 'note']).forEach(ref => {
    if (ref.status === 'filled') return;
    const label = refKinds[ref.kind] || 'Reference';
    const low = numberOrNull(ref.price_low);
    const high = numberOrNull(ref.price_high);
    if (low !== null && high !== null && low !== high) zones.push({ name: label, direction: '', price_low: low, price_high: high, source: ref.note, pine_color: REF_PINE_COLOR });
    else if (low !== null || high !== null) levels.push({ name: label, price: low ?? high, kind: '', source: ref.note, line_style: 'dotted', pine_color: REF_PINE_COLOR });
  });
  return { zones, levels };
}

function refreshTradingViewExport() {
  const status = $('#tvExportStatus');
  const copyButton = $('#copyTradingViewZones');
  const downloadButton = $('#downloadTradingViewZones');
  $('#tvFillTransparencyValue').value = $('#tvFillTransparency').value;

  if (!window.TradingViewZones) {
    status.textContent = 'Generátor TradingView není dostupný. Obnov stránku po nasazení aktualizace.';
    status.classList.add('is-warning');
    copyButton.disabled = true;
    downloadButton.disabled = true;
    return null;
  }

  const { zones, levels } = tradingViewItems();
  const result = window.TradingViewZones.generate(zones, { ...tradingViewSettings(), levels });
  $('#tvPineCode').value = result.code;
  $('#tvExportMarket').textContent = $('#planMarket').value || 'ES';
  $('#tvExportCount').textContent = String(result.exported);
  $('#tvExportLevelCount').textContent = String(result.exportedLevels || 0);
  copyButton.disabled = !result.code;
  downloadButton.disabled = !result.code;

  if (!result.exported && !result.exportedLevels) {
    status.textContent = 'Nelze vytvořit skript: doplň u zóny rozdílnou spodní a horní hranici, nebo přidej level s cenou.';
    status.classList.add('is-warning');
  } else {
    const parts = [];
    if (result.exported) parts.push(`${result.exported} zón`);
    if (result.exportedLevels) parts.push(`${result.exportedLevels} levelů`);
    const skipped = result.skipped.length ? ` Přeskočeno bez platné ceny nebo rozpětí: ${result.skipped.join(', ')}.` : '';
    status.textContent = `Připraveno ${parts.join(' a ')} pro ${$('#planMarket').value || 'ES'}.${skipped}`;
    status.classList.toggle('is-warning', result.skipped.length > 0);
  }
  return result;
}

function openTradingViewExport() {
  refreshTradingViewExport();
  $('#tradingViewDialog').showModal();
}

async function copyTradingViewCode() {
  const textarea = $('#tvPineCode');
  if (!textarea.value) return;
  try {
    await navigator.clipboard.writeText(textarea.value);
  } catch (error) {
    textarea.focus();
    textarea.select();
    if (!document.execCommand('copy')) throw error;
  }
  toast('Pine Script je zkopírovaný. Vlož jej do TradingView Pine Editoru.');
}

function downloadTradingViewCode() {
  const code = $('#tvPineCode').value;
  if (!code) return;
  const market = ($('#planMarket').value || 'market').replace(/[^a-z0-9_-]/gi, '');
  const date = ($('#planDate').value || today()).replace(/[^0-9-]/g, '');
  const file = new Blob([code], { type: 'text/plain;charset=utf-8' });
  const url = URL.createObjectURL(file);
  const link = document.createElement('a');
  link.href = url;
  link.download = `trading-zones-${market}-${date}.pine`;
  document.body.appendChild(link);
  link.click();
  link.remove();
  setTimeout(() => URL.revokeObjectURL(url), 0);
  toast('Pine Script byl stažen jako .pine soubor.');
}

function setPlanType(type) {
  $(type === 'weekly' ? '#planTypeWeekly' : '#planTypeDaily').checked = true;
}

function resetPlan({ type = planType(), date = null, market = null } = {}) {
  const lastMarket = $('#planMarket').value;
  $('#planForm').reset();
  setPlanType(type);
  $('#planId').value = '';
  $('#planDate').value = date || defaultPlanDate(type);
  $('#planMarket').value = market || $('#globalMarket').value || lastMarket || prefs().defaults?.market || marketList()[0]?.symbol || 'ES';
  $('#planSession').value = tradeTypeLabel(null);
  $('#closeLegacyOption').hidden = true;
  $('#closeLadderHint').textContent = 'Vyplň Close, VAH, POC a VAL a poloha se doplní sama.';
  $('#biasInheritHint').textContent = 'Klikni znovu na vybranou volbu a zrušíš ji. Denní náhled si Weekly bias sám převezme z týdenního náhledu.';
  state.currentPlan = null;
  state.currentScreenshots = [];
  state.biasInherited = false;
  state.weeklyContext = null;
  renderZones([]);
  renderIdeas([]);
  renderLevels([]);
  renderRefs([]);
  renderDnLevels([]);
  syncDnPatternChips();
  renderPlanCustomFields({});
  renderScreenshots();
  onPlanTypeChange();
  refreshPlanInsights();
}

function startNewPlan(type) {
  resetPlan({ type });
  activateView('plan');
}

async function loadPlan(id) {
  const plan = await api('plan', { query: { id } });
  $('#planForm').reset();
  setPlanType(plan.plan_type === 'weekly' ? 'weekly' : 'daily');
  state.currentPlan = plan;
  state.currentScreenshots = plan.screenshots || [];
  state.biasInherited = true;
  state.weeklyContext = plan.weekly_context || null;
  setFormValues($('#planForm'), { ...plan, session: tradeTypeLabel(plan.session) });
  $('#planId').value = plan.id;
  $('#closeLegacyOption').hidden = plan.previous_close !== 'inside';
  $('#closeLadderHint').textContent = 'Vyplň Close, VAH, POC a VAL a poloha se doplní sama.';
  $('#biasInheritHint').textContent = 'Klikni znovu na vybranou volbu a zrušíš ji.';
  renderZones(plan.zones || []);
  renderIdeas(plan.ideas || []);
  renderLevels(plan.levels || []);
  renderRefs(plan.refs || []);
  renderDnLevels(plan.dn_levels || []);
  syncDnPatternChips();
  renderPlanCustomFields(plan.custom || {});
  renderScreenshots();
  activateView('plan');
  onPlanTypeChange();
  refreshPlanInsights();
}

async function uploadScreenshots(files) {
  if (!files.length) return;
  let plan = state.currentPlan;
  if (!plan?.id) plan = await savePlan({ quiet: true });
  for (const file of files) {
    const form = new FormData();
    form.append('file', file);
    form.append('plan_id', plan.id);
    form.append('role', 'plan');
    await api('upload', { method: 'POST', body: form });
  }
  const refreshed = await api('plan', { query: { id: plan.id } });
  state.currentPlan = refreshed;
  state.currentScreenshots = refreshed.screenshots || [];
  renderScreenshots();
  toast(files.length === 1 ? 'Screenshot je uložený.' : `Uloženo ${files.length} screenshotů.`);
}

function renderScreenshots() {
  const root = $('#planScreenshots');
  root.innerHTML = state.currentScreenshots.map(screenshot => `<figure class="screenshot" data-screenshot="${escapeHtml(screenshot.id)}"><img src="file.php?id=${encodeURIComponent(screenshot.id)}" alt="${escapeHtml(screenshot.caption || screenshot.original_name)}"><button type="button" data-delete-screenshot="${escapeHtml(screenshot.id)}" aria-label="Odstranit screenshot">×</button></figure>`).join('');
}

function renderEquity(series = []) {
  const root = $('#equityChart');
  if (!series.length) {
    root.innerHTML = '<div class="empty-state">Po zapsání obchodů se zde zobrazí kumulativní R.</div>';
    $('#equityCaption').textContent = 'Bez obchodů';
    return;
  }
  const cumulative = [];
  let total = 0;
  series.forEach(item => { total += Number(item.result_r || 0); cumulative.push(total); });
  const min = Math.min(0, ...cumulative);
  const max = Math.max(0, ...cumulative);
  const range = max - min || 1;
  const width = 800;
  const height = 250;
  const points = cumulative.map((value, index) => {
    const x = cumulative.length === 1 ? width / 2 : (index / (cumulative.length - 1)) * width;
    const y = 18 + ((max - value) / range) * (height - 36);
    return `${x.toFixed(1)},${y.toFixed(1)}`;
  });
  const baseline = 18 + ((max - 0) / range) * (height - 36);
  const fillPoints = `0,${height} ${points.join(' ')} ${width},${height}`;
  root.innerHTML = `<svg viewBox="0 0 ${width} ${height}" preserveAspectRatio="none" role="img"><defs><linearGradient id="equityFill" x1="0" y1="0" x2="0" y2="1"><stop offset="0" class="equity-stop-top"/><stop offset="1" class="equity-stop-bottom"/></linearGradient></defs><line class="grid-line" x1="0" y1="${baseline}" x2="${width}" y2="${baseline}"/><polygon class="equity-fill" points="${fillPoints}"/><polyline class="equity-line" points="${points.join(' ')}"/></svg>`;
  $('#equityCaption').textContent = `${series.length} obchodů · ${signedR(total)}`;
}

function renderRecentTrades(trades = []) {
  $('#recentTrades').innerHTML = trades.slice(0, 7).map(trade => `<tr><td>${escapeHtml(trade.trade_date)}</td><td><strong>${escapeHtml(trade.market)}</strong></td><td>${escapeHtml(trade.strategy || '—')}</td><td class="direction-${escapeHtml(trade.direction)}">${directionLabel(trade.direction)}</td><td class="${Number(trade.result_r) >= 0 ? 'value-positive' : 'value-negative'}">${displayNumber(trade.result_r)}R</td><td>${trade.followed_plan === 1 || trade.followed_plan === '1' ? 'Ano' : trade.followed_plan === 0 || trade.followed_plan === '0' ? 'Ne' : '—'}</td></tr>`).join('') || '<tr><td colspan="6" class="muted">Zatím nejsou zapsané žádné obchody.</td></tr>';
}

function biasChip(label, value) {
  if (!value) return '';
  const words = { long: 'Long', short: 'Short', balance: 'Balance' };
  return `<span class="bias-chip is-${escapeHtml(value)}"><em>${escapeHtml(label)}</em>${escapeHtml(words[value] || value)}</span>`;
}

const planStatusLabels = { draft: 'Rozpracovaný', ready: 'Připravený', completed: 'Dokončený', archived: 'Archivovaný' };

function prepCard(plan, type) {
  const weekly = type === 'weekly';
  if (!plan) {
    return `<article class="prep-card is-missing">
      <header><span class="badge">${weekly ? 'Týden' : 'Den'}</span><strong>${weekly ? 'Týdenní náhled chybí' : 'Denní náhled chybí'}</strong></header>
      <p class="muted">${weekly ? 'Bez týdenního náhledu nemají denní zóny s čím porovnat týdenní value.' : 'Převeď týdenní zóny na konkrétní session a doplň otevření.'}</p>
      <button class="button button-small" type="button" data-new-plan="${type}">Vytvořit ${weekly ? 'týdenní' : 'denní'} náhled</button>
    </article>`;
  }
  const chips = weekly
    ? [biasChip('PA W', plan.pa_weekly), biasChip('MP W', plan.mp_weekly), biasChip('PA M', plan.pa_monthly)]
    : [biasChip('PA W', plan.pa_weekly), biasChip('PA D', plan.pa_daily), biasChip('MP W', plan.mp_weekly), biasChip('MP D', plan.mp_daily)];
  return `<article class="prep-card">
    <header><span class="badge">${weekly ? escapeHtml(`Týden ${isoWeek(plan.plan_date)}`) : escapeHtml(prettyDate(plan.plan_date))}</span><strong>${escapeHtml(plan.market)} · <span class="direction-${escapeHtml(plan.bias)}">${directionLabel(plan.bias)}</span></strong><span class="muted">${escapeHtml(planStatusLabels[plan.status] || plan.status)}</span></header>
    <div class="bias-chips">${chips.join('') || '<span class="muted">Bias zatím nevyplněný</span>'}</div>
    <p>${escapeHtml(plan.bias_description || 'Bez popisu trhu.')}</p>
    <footer><span>${Number(plan.zone_count || 0)} zón</span><span>${Number(plan.open_ref_count || 0)} referencí na dojetí</span><span>${Number(plan.trade_count || 0)} obchodů</span><button class="mini-button" type="button" data-load-plan="${plan.id}">Otevřít</button></footer>
  </article>`;
}

function renderTodayPlan(plans = []) {
  const session = nextSessionDate();
  const monday = mondayOf(session);
  const weekly = plans.find(item => item.plan_type === 'weekly' && item.plan_date === monday);
  const daily = plans.find(item => item.plan_type !== 'weekly' && item.plan_date === session);
  const root = $('#todayPlan');
  root.className = 'prep-cards';
  root.innerHTML = prepCard(weekly, 'weekly') + prepCard(daily, 'daily');
}

function renderReadiness(data = state.readinessData) {
  if (!data) return;
  state.readinessData = data;
  const session = nextSessionDate();
  const monday = mondayOf(session);
  const weekly = data.plans.find(item => item.plan_type === 'weekly' && item.plan_date === monday);
  const daily = data.plans.find(item => item.plan_type !== 'weekly' && item.plan_date === session);
  const check = data.checks.find(item => item.check_date === today());
  const events = (data.days.find(day => day.date === session)?.events || []).filter(event => event.kind !== 'note');
  const due = state.accounts.filter(account => account.audit_due);
  const soon = state.accounts.filter(account => account.audit_soon);

  const items = [
    {
      tone: weekly ? 'done' : 'todo', title: 'Týdenní náhled',
      detail: weekly ? `${weekly.market} · ${Number(weekly.zone_count || 0)} zón` : weekLabel(monday),
      action: weekly ? `<button class="mini-button" type="button" data-load-plan="${weekly.id}">Otevřít</button>` : '<button class="mini-button" type="button" data-new-plan="weekly">Vytvořit</button>',
    },
    {
      tone: daily ? 'done' : 'todo', title: 'Denní náhled',
      detail: daily ? `${daily.market} · ${Number(daily.zone_count || 0)} zón` : prettyDate(session),
      action: daily ? `<button class="mini-button" type="button" data-load-plan="${daily.id}">Otevřít</button>` : '<button class="mini-button" type="button" data-new-plan="daily">Vytvořit</button>',
    },
    {
      tone: check ? (check.band === 'green' ? 'done' : check.band === 'amber' ? 'warn' : 'bad') : 'todo', title: 'Test psychiky',
      detail: check ? (bandLabels[check.band] || check.band).split('—')[0].trim() : 'Dnes ještě neudělaný',
      action: check ? '<button class="mini-button" type="button" data-open-view="psyche">Detail</button>' : '<button class="mini-button" type="button" data-start-psych>Spustit</button>',
    },
    {
      tone: events.length ? 'warn' : 'info', title: 'Red news a svátky',
      detail: events.length ? events.slice(0, 3).map(event => `${event.time_label ? `${event.time_label} ` : ''}${event.title}`).join(', ') : 'V kalendáři nic zapsaného',
      action: '<button class="mini-button" type="button" data-open-view="calendar">Kalendář</button>',
    },
    {
      tone: due.length ? 'bad' : soon.length ? 'warn' : state.accounts.length ? 'done' : 'info', title: 'Money audit',
      detail: due.length ? `Čeká: ${due.map(account => account.name).join(', ')}` : soon.length ? `Brzy: ${soon.map(account => account.next_audit_date).join(', ')}` : state.accounts.length ? 'Evidence v termínu' : 'Zatím žádný účet',
      action: '<button class="mini-button" type="button" data-open-view="accounts">Účty</button>',
    },
  ];
  // Moduly, které trader skryl, se v připravenosti neukazují.
  const modules = [null, null, 'psyche', 'calendar', 'accounts'];
  const visible = items.filter((item, index) => !modules[index] || moduleOn(modules[index]));
  const coreItems = visible.filter((item, index) => items.indexOf(item) < 3);
  const core = coreItems.filter(item => item.tone === 'done').length;
  $('#readinessTitle').textContent = `Připravenost na ${prettyDate(session)}`;
  $('#readinessScore').textContent = `${core} ze ${coreItems.length} hotovo`;
  $('#readinessScore').className = `readiness-score${core === coreItems.length ? ' is-done' : ''}`;
  $('#readinessGrid').dataset.count = String(visible.length);
  $('#readinessGrid').innerHTML = visible.map(item => `<article class="ready-item is-${item.tone}">
    <i aria-hidden="true"></i>
    <div><strong>${escapeHtml(item.title)}</strong><p>${escapeHtml(item.detail)}</p></div>
    ${item.action}
  </article>`).join('');
}

async function refreshDashboard() {
  const market = $('#globalMarket').value;
  const session = nextSessionDate();
  try {
    const [stats, plans, trades, checks, calendar] = await Promise.all([
      api('stats', { query: market ? { market } : {} }),
      api('plans', { query: { limit: 100, ...(market ? { market } : {}) } }),
      api('trades', { query: { limit: 20, ...(market ? { market } : {}) } }),
      api('psych_checks'),
      api('calendar', { query: { month: session.slice(0, 7) } }),
    ]);
    // Přehled má vlastní, kratší výběry. Nesmí přepsat state.trades ani state.plans,
    // ze kterých se vykresluje Deník a Historie náhledů.
    const dashboardPlans = plans.items || [];
    const dashboardTrades = trades.items || [];
    state.stats = stats;
    const summary = stats.summary || {};
    const tradeCount = Number(summary.trades || 0);
    $('#metricR').textContent = signedR(summary.total_r || 0);
    $('#metricR').className = Number(summary.total_r) >= 0 ? 'value-positive' : 'value-negative';
    $('#metricTrades').textContent = `${tradeCount} obchodů`;
    $('#metricAvg').textContent = tradeCount ? signedR(summary.avg_r) : '—';
    $('#metricPf').textContent = summary.profit_factor == null ? '—' : displayNumber(summary.profit_factor);
    $('#metricUsd').textContent = displayMoney(summary.total_usd || 0);
    $('#metricPlan').textContent = summary.plan_adherence == null ? '—' : `${displayNumber(summary.plan_adherence, 0)} %`;
    renderEquity(stats.series || []);
    renderTodayPlan(dashboardPlans);
    renderRecentTrades(dashboardTrades);
    renderReadiness({ plans: dashboardPlans, checks: checks.items || [], days: calendar.days || [] });
  } catch (error) {
    toast(error.message, 'error');
  }
}

function signedR(value) {
  const number = numberOrNull(value);
  if (number === null) return '—';
  return `${number > 0 ? '+' : ''}${displayNumber(number)}R`;
}

// Barvy řad jsou v CSS (--series-1 až 8, ověřené pro barvoslepé v obou režimech).
// Pořadí je pevné; devátá a další strategie se sečtou do řady Ostatní, nikdy se barvy necyklí.
const MAX_CHART_SERIES = 8;

function foldChartSeries(series) {
  if (series.length <= MAX_CHART_SERIES) return series.map((item, index) => ({ ...item, slot: String(index + 1) }));
  const kept = series.slice(0, MAX_CHART_SERIES - 1).map((item, index) => ({ ...item, slot: String(index + 1) }));
  const rest = series.slice(MAX_CHART_SERIES - 1);
  const points = rest[0].points.map((_, index) => {
    const values = rest.map(item => item.points[index]).filter(point => point !== null);
    return values.length ? values.reduce((sum, value) => sum + value, 0) : null;
  });
  const total = rest.reduce((sum, item) => sum + Number(item.total_r || 0), 0);
  return [...kept, { name: `Ostatní (${rest.length})`, points, total_r: total, slot: 'other' }];
}

function renderStrategyChart(chart) {
  const root = $('#strategyChart');
  const legend = $('#strategyLegend');
  const dates = chart?.dates || [];
  const visible = (chart?.series || []).filter(item => item.points.some(point => point !== null));
  const series = foldChartSeries(visible);

  if (dates.length < 2 || !series.length) {
    root.innerHTML = '<div class="empty-state">Až budou obchody aspoň ze dvou dnů, uvidíš tady vývoj každé strategie v čase.</div>';
    legend.innerHTML = '';
    $('#strategyChartCaption').textContent = 'Bez dat';
    return;
  }

  const values = series.flatMap(item => item.points.filter(point => point !== null));
  const min = Math.min(0, ...values);
  const max = Math.max(0, ...values);
  const range = max - min || 1;
  const width = 900;
  const height = 280;
  const padTop = 16;
  const padBottom = 26;
  const plot = height - padTop - padBottom;
  const toX = index => (dates.length === 1 ? width / 2 : (index / (dates.length - 1)) * width);
  const toY = value => padTop + ((max - value) / range) * plot;
  const zeroY = toY(0);

  const paths = series.map(item => {
    const segments = [];
    let current = [];
    item.points.forEach((point, index) => {
      if (point === null) {
        if (current.length) segments.push(current);
        current = [];
        return;
      }
      current.push(`${toX(index).toFixed(1)},${toY(point).toFixed(1)}`);
    });
    if (current.length) segments.push(current);
    const line = segments.map(segment => segment.length === 1
      ? `<circle cx="${segment[0].split(',')[0]}" cy="${segment[0].split(',')[1]}" r="4" data-series="${item.slot}"/>`
      : `<polyline points="${segment.join(' ')}" fill="none" data-series="${item.slot}" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke"/>`).join('');
    return line;
  }).join('');

  const labelStep = Math.max(1, Math.ceil(dates.length / 6));
  const labels = dates.map((date, index) => index % labelStep === 0 || index === dates.length - 1
    ? `<text class="axis-label" x="${toX(index).toFixed(1)}" y="${height - 8}" text-anchor="${index === 0 ? 'start' : index === dates.length - 1 ? 'end' : 'middle'}">${escapeHtml(date.slice(5))}</text>`
    : '').join('');

  root.innerHTML = `<svg viewBox="0 0 ${width} ${height}" preserveAspectRatio="none" role="img">
    <line class="grid-line" x1="0" y1="${zeroY.toFixed(1)}" x2="${width}" y2="${zeroY.toFixed(1)}"/>
    ${paths}${labels}
  </svg>`;

  legend.innerHTML = series.map(item => `<span class="legend-item"><i data-series="${item.slot}"></i>${escapeHtml(item.name)} <strong class="${item.total_r >= 0 ? 'value-positive' : 'value-negative'}">${signedR(item.total_r)}</strong></span>`).join('');
  $('#strategyChartCaption').textContent = `${visible.length} strategií · ${dates.length} obchodních dnů`;
}

function renderCustomStats(items) {
  const surface = $('#customStatsSurface');
  const useful = items.filter(item => item.rows.length);
  surface.hidden = !activeFields('trade').some(field => ['bool', 'select', 'rating', 'number'].includes(field.kind));
  if (surface.hidden) return;
  if (!useful.length) {
    $('#customFieldStats').innerHTML = '<div class="empty-state compact">Až budeš u obchodů vyplňovat svá pole, uvidíš tady, při jaké hodnotě ti trh platí nejvíc.</div>';
    return;
  }
  $('#customFieldStats').innerHTML = useful.map(item => {
    const max = Math.max(...item.rows.map(row => Math.abs(row.avg_r)), 0.01);
    const best = item.rows.filter(row => row.trades >= 3).sort((a, b) => b.avg_r - a.avg_r)[0];
    return `<article class="custom-stat">
      <header><strong>${escapeHtml(item.field.label)}</strong><span class="muted">${item.samples} obchodů${best && item.rows.length > 1 ? ` · nejlépe „${escapeHtml(best.value)}“ ${signedR(best.avg_r)}` : ''}</span></header>
      <div class="custom-stat-rows">${item.rows.map(row => `<div class="custom-stat-row">
        <span class="value">${escapeHtml(row.value)}</span>
        <span class="bar"><i class="${row.avg_r >= 0 ? 'is-positive' : 'is-negative'}" data-width="${Math.round(Math.abs(row.avg_r) / max * 50)}"></i></span>
        <strong class="${row.avg_r >= 0 ? 'value-positive' : 'value-negative'}">${signedR(row.avg_r)}</strong>
        <small>${row.trades} obch. · celkem ${signedR(row.total_r)}${row.profit_factor != null ? ` · PF ${displayNumber(row.profit_factor)}` : ''}${row.plan_adherence != null ? ` · plán ${row.plan_adherence} %` : ''}</small>
      </div>`).join('')}</div>
    </article>`;
  }).join('');
  $$('#customFieldStats .bar i').forEach(bar => {
    bar.style.width = `${bar.dataset.width}%`;
    if (bar.classList.contains('is-negative')) bar.style.marginLeft = `${50 - Number(bar.dataset.width)}%`;
    else bar.style.marginLeft = '50%';
  });
}

async function refreshStrategyStats() {
  api('custom_field_stats').then(result => renderCustomStats(result.items || [])).catch(() => { $('#customStatsSurface').hidden = true; });
  try {
    const result = await api('strategy_stats');
    renderStrategyChart(result.chart);
    const items = result.items || [];
    const named = items.filter(item => Number(item.strategy_id) > 0);
    const orphans = items.filter(item => Number(item.strategy_id) === 0).reduce((sum, item) => sum + Number(item.trades), 0);

    $('#strategyCount').textContent = String(named.length);
    $('#strategyOrphans').textContent = String(orphans);
    const ranked = items.filter(item => Number(item.rated_trades) > 0);
    const best = ranked[0];
    const worst = ranked[ranked.length - 1];
    $('#strategyBest').textContent = best ? best.name : '—';
    $('#strategyBestDetail').textContent = best ? `${signedR(best.total_r)} z ${best.trades} obchodů` : 'zatím bez dat';
    $('#strategyWorst').textContent = worst && worst !== best ? worst.name : '—';
    $('#strategyWorstDetail').textContent = worst && worst !== best ? `${signedR(worst.total_r)} z ${worst.trades} obchodů` : 'zatím bez dat';

    $('#strategyTable').innerHTML = items.map(item => {
      const totalR = Number(item.total_r);
      const adherence = numberOrNull(item.plan_adherence);
      const execution = numberOrNull(item.avg_execution);
      const factor = numberOrNull(item.profit_factor);
      return `<tr>
        <td><strong>${escapeHtml(item.name)}</strong></td>
        <td>${escapeHtml(item.timeframe || '—')}</td>
        <td>${escapeHtml(strategyStyleLabels[item.style] || '—')}</td>
        <td>${escapeHtml(item.trades)}</td>
        <td class="${totalR >= 0 ? 'value-positive' : 'value-negative'}">${signedR(item.total_r)}</td>
        <td>${item.expectancy_r === null ? '—' : signedR(item.expectancy_r)}</td>
        <td>${factor === null ? '—' : displayNumber(factor)}</td>
        <td>${adherence === null ? '—' : `${displayNumber(adherence, 0)} %`}</td>
        <td>${execution === null ? '—' : `${displayNumber(execution, 1)} / 5`}</td>
        <td>${escapeHtml(item.last_trade || '—')}</td>
        <td><button class="mini-button" type="button" data-strategy-trades="${escapeHtml(item.name)}">Obchody</button>${Number(item.strategy_id) > 0 ? shareButton('strategy', item.strategy_id) : ''}</td>
      </tr>`;
    }).join('') || '<tr><td colspan="11" class="muted">Zatím nejsou zapsané žádné obchody.</td></tr>';
  } catch (error) { toast(error.message, 'error'); }
}

function monthLabel(month) {
  const [year, index] = month.split('-');
  return `${monthNames[Number(index) - 1]} ${year}`;
}

function shiftMonth(month, delta) {
  const [year, index] = month.split('-').map(Number);
  const date = new Date(Date.UTC(year, index - 1 + delta, 1));
  return `${date.getUTCFullYear()}-${String(date.getUTCMonth() + 1).padStart(2, '0')}`;
}

async function refreshCalendar(month = null) {
  state.calendarMonth = month || state.calendarMonth || today().slice(0, 7);
  try {
    const result = await api('calendar', { query: { month: state.calendarMonth } });
    state.calendarMonth = result.month;
    state.calendarDays = result.days || [];
    renderCalendar(result);
  } catch (error) { toast(error.message, 'error'); }
}

function renderCalendar(result) {
  const month = result.month;
  $('#calendarLabel').textContent = monthLabel(month);
  const summary = result.summary || {};
  const totalR = Number(summary.total_r || 0);
  $('#calendarSummary').innerHTML = `<span>Obchodních dní <strong>${Number(summary.trading_days || 0)}</strong></span><span>Obchodů <strong>${Number(summary.trades || 0)}</strong></span><span>Výsledek <strong class="${totalR >= 0 ? 'value-positive' : 'value-negative'}">${signedR(totalR)}</strong></span><span>${displayMoney(summary.total_usd || 0)}</span>`;

  const byDate = new Map(state.calendarDays.map(day => [day.date, day]));
  const [year, index] = month.split('-').map(Number);
  const first = new Date(Date.UTC(year, index - 1, 1));
  const daysInMonth = new Date(Date.UTC(year, index, 0)).getUTCDate();
  const leading = (first.getUTCDay() + 6) % 7;

  const cells = [];
  for (let i = 0; i < leading; i += 1) cells.push('<div class="calendar-cell is-empty"></div>');
  for (let day = 1; day <= daysInMonth; day += 1) {
    const date = `${month}-${String(day).padStart(2, '0')}`;
    const info = byDate.get(date);
    const isToday = date === today();
    const classes = ['calendar-cell'];
    if (isToday) classes.push('is-today');
    if (info?.broken_rules) classes.push('is-broken');
    if (info && Number(info.trades) > 0) classes.push(Number(info.total_r) >= 0 ? 'is-win' : 'is-loss');

    const marks = [];
    if (info?.plan_id && info.plan_type !== 'weekly') marks.push('<span class="calendar-mark is-plan" title="Denní náhled">N</span>');
    if (info?.weekly_plan_id) marks.push('<span class="calendar-mark is-week" title="Týdenní náhled">T</span>');
    if (info?.psych_band) marks.push(`<span class="calendar-mark is-${escapeHtml(info.psych_band)}" title="Rychlý test psychiky">P</span>`);
    if (info?.broken_rules) marks.push('<span class="calendar-mark is-broken" title="Porušená pravidla">!</span>');

    const events = (info?.events || []).slice(0, 3).map(event =>
      `<em class="calendar-event is-${escapeHtml(event.kind)}">${escapeHtml(event.time_label ? `${event.time_label} ` : '')}${escapeHtml(event.title)}</em>`
    ).join('');

    cells.push(`<button class="${classes.join(' ')}" type="button" data-calendar-day="${date}">
      <span class="calendar-day">${day}${marks.join('')}</span>
      ${info && Number(info.trades) > 0 ? `<strong class="${Number(info.total_r) >= 0 ? 'value-positive' : 'value-negative'}">${signedR(info.total_r)}</strong><small>${Number(info.trades)} obch.</small>` : ''}
      ${events}
    </button>`);
  }
  $('#calendarGrid').innerHTML = cells.join('');
}

function openDayDialog(date) {
  const info = state.calendarDays.find(day => day.date === date) || { date, trades: 0, events: [] };
  const form = $('#dayForm');
  form.reset();
  form.elements.event_date.value = date;
  $('#dayDialogTitle').textContent = date;

  const parts = [];
  if (Number(info.trades) > 0) {
    parts.push(`<div><dt>Obchodů</dt><dd>${Number(info.trades)}</dd></div>`);
    parts.push(`<div><dt>Výsledek</dt><dd class="${Number(info.total_r) >= 0 ? 'value-positive' : 'value-negative'}">${signedR(info.total_r)}</dd></div>`);
    parts.push(`<div><dt>V penězích</dt><dd>${displayMoney(info.total_usd)}</dd></div>`);
  }
  if (info.broken_rules) parts.push(`<div><dt>Porušená pravidla</dt><dd class="value-negative">${Number(info.broken_rules)}×</dd></div>`);
  if (info.psych_band) parts.push(`<div><dt>Rychlý test</dt><dd>${escapeHtml(bandLabels[info.psych_band] || info.psych_band)}</dd></div>`);
  $('#dayOverview').innerHTML = parts.length
    ? `<dl class="account-figures">${parts.join('')}</dl>`
    : '<div class="empty-state compact">K tomuhle dni zatím nic není.</div>';

  $('#dayEvents').innerHTML = (info.events || []).map(event =>
    `<div class="day-event is-${escapeHtml(event.kind)}"><div><strong>${escapeHtml(event.time_label ? `${event.time_label} · ` : '')}${escapeHtml(event.title)}</strong><p>${escapeHtml(eventKindLabels[event.kind] || event.kind)}${event.notes ? ` · ${escapeHtml(event.notes)}` : ''}</p></div><button class="mini-button danger" type="button" data-delete-event="${event.id}">Smazat</button></div>`
  ).join('');

  const planButton = $('#dayOpenPlan');
  planButton.hidden = !info.plan_id;
  planButton.dataset.planId = info.plan_id || '';
  $('#dayDialog').showModal();
}

function correlationVerdict(value, direction) {
  if (value === null || value === undefined) return 'zatím nelze spočítat';
  const strength = Math.abs(value);
  const expected = direction === 'negative' ? value < 0 : value > 0;
  if (strength < 0.2) return 'prakticky žádná souvislost';
  if (!expected) return 'souvislost jde proti očekávání';
  if (strength < 0.4) return 'slabá souvislost v očekávaném směru';
  if (strength < 0.6) return 'středně silná souvislost';
  return 'silná souvislost';
}

async function refreshCalibration() {
  const root = $('#calibrationBody');
  try {
    const data = await api('psych_calibration');
    $('#calibrationSample').textContent = `${data.traded_days} obchodních dnů s testem`;

    const bandRows = ['green', 'amber', 'red'].map(band => {
      const stats = data.bands[band] || {};
      return `<tr>
        <td>${escapeHtml((bandLabels[band] || band).split('—')[0].trim())}</td>
        <td>${stats.traded_days ?? 0}</td>
        <td class="${Number(stats.avg_r) >= 0 ? 'value-positive' : 'value-negative'}">${stats.avg_r === null || stats.avg_r === undefined ? '—' : signedR(stats.avg_r)}</td>
        <td>${stats.broken_share === null || stats.broken_share === undefined ? '—' : `${stats.broken_share} %`}</td>
      </tr>`;
    }).join('');

    const suggestion = data.suggested
      ? `<div class="calibration-suggestion">
           <div>
             <p class="eyebrow">NÁVRH HRANIC</p>
             <p>Oranžová od <strong>${data.current.amber} %</strong> na <strong>${data.suggested.amber} %</strong>, červená z <strong>${data.current.red} %</strong> na <strong>${data.suggested.red} %</strong>.</p>
             ${data.quality ? `<small>Kvalita rozdělení (Youdenovo J): oranžová ${data.quality.amber_j}${data.quality.red_j === null ? '' : `, červená ${data.quality.red_j}`}. Nula je náhoda, jedna dokonalé rozdělení.</small>` : ''}
           </div>
           <button class="button button-primary" type="button" id="applyCalibration" data-amber="${data.suggested.amber}" data-red="${data.suggested.red}">Použít návrh</button>
         </div>`
      : '';

    root.outerHTML = `<div id="calibrationBody">
      <div class="table-wrap"><table><thead><tr><th>Pásmo</th><th>Dnů s obchody</th><th>Průměr R na den</th><th>Dnů s porušením</th></tr></thead><tbody>${bandRows}</tbody></table></div>
      <dl class="account-figures">
        <div><dt>Skóre vs. výsledek dne</dt><dd>${data.correlation_r === null ? '—' : data.correlation_r} · ${escapeHtml(correlationVerdict(data.correlation_r, 'negative'))}</dd></div>
        <div><dt>Skóre vs. porušení pravidel</dt><dd>${data.correlation_broken === null ? '—' : data.correlation_broken} · ${escapeHtml(correlationVerdict(data.correlation_broken, 'positive'))}</dd></div>
        <div><dt>Platné hranice</dt><dd>oranžová ${data.current.amber} %, červená ${data.current.red} %</dd></div>
      </dl>
      ${suggestion}
      ${data.note ? `<p class="psych-hint">${escapeHtml(data.note)}</p>` : ''}
      <p class="psych-hint">Čekáme, že vyšší skóre znamená horší den. U prvního řádku tedy záporné číslo, u druhého kladné. Dokud tam nic není, test o tobě zatím nic neprokázal a jede na výchozích hranicích.</p>
    </div>`;

    $('#applyCalibration')?.addEventListener('click', async event => {
      const button = event.currentTarget;
      try {
        await api('psych_thresholds', { method: 'POST', body: { thresholds: { amber: Number(button.dataset.amber), red: Number(button.dataset.red) } } });
        toast('Hranice jsou zkalibrované na tvých datech.');
        await refreshCalibration();
      } catch (error) { toast(error.message, 'error'); }
    });
  } catch (error) {
    root.outerHTML = '<div id="calibrationBody" class="empty-state compact">Kalibraci se nepodařilo načíst.</div>';
    toast(error.message, 'error');
  }
}

async function refreshDiscipline() {
  try {
    const result = await api('discipline');
    const days = result.days || [];
    $('#disciplineSummary').textContent = days.length
      ? `${days.length} dnů s porušením`
      : 'Zatím bez porušení';
    $('#disciplineDays').innerHTML = days.map(day =>
      `<button class="discipline-day" type="button" data-discipline-day="${escapeHtml(day.trade_date)}"><strong>${escapeHtml(day.trade_date)}</strong><span>${Number(day.broken)}× mimo pravidla</span><em class="${Number(day.total_r) >= 0 ? 'value-positive' : 'value-negative'}">${signedR(day.total_r)}</em></button>`
    ).join('') || '<div class="empty-state compact">Žádný den s porušenými pravidly. Drž to tak.</div>';

    const cleanAvg = numberOrNull(result.clean_avg_r);
    const brokenAvg = numberOrNull(result.broken_avg_r);
    const emotions = result.emotions || [];
    $('#disciplineEmotions').outerHTML = `<div id="disciplineEmotions">${
      emotions.length
        ? `<dl class="account-figures">
             <div><dt>Obchodů podle plánu</dt><dd>${result.clean_trades} · ${cleanAvg === null ? '—' : signedR(cleanAvg)} průměr</dd></div>
             <div><dt>Obchodů mimo pravidla</dt><dd>${result.broken_trades} · ${brokenAvg === null ? '—' : signedR(brokenAvg)} průměr</dd></div>
           </dl>
           <div class="emotion-bars">${emotions.map(item => {
             const total = item.broken + item.clean;
             const share = total > 0 ? Math.round((item.broken / total) * 100) : 0;
             return `<div class="emotion-bar"><span>${escapeHtml(item.label)}</span><div><i data-share="${share}"></i></div><em>${item.broken}× u porušení · ${share} % všech výskytů</em></div>`;
           }).join('')}</div>`
        : '<div class="empty-state compact">Zatím není co porovnávat.</div>'
    }</div>`;
    $$('#disciplineEmotions .emotion-bar i').forEach(bar => { bar.style.width = `${bar.dataset.share}%`; });
  } catch (error) { toast(error.message, 'error'); }
}

async function showDisciplineDay(date) {
  try {
    const analysis = await api('discipline_day', { query: { date } });
    const patterns = analysis.patterns || [];
    const questions = analysis.questions || [];
    $('#disciplineDetail').innerHTML = `
      <div class="section-heading"><div><p class="eyebrow">${escapeHtml(analysis.date)}</p><h2>${analysis.offenders}× mimo pravidla · den ${signedR(analysis.day_r)}</h2></div>${analysis.plan ? `<button class="text-button" type="button" data-open-plan="${analysis.plan.id}">Otevřít náhled</button>` : ''}</div>
      ${patterns.length ? `<div class="pattern-list">${patterns.map(item => `<article><strong>${escapeHtml(item.title)}</strong><p>${escapeHtml(item.detail)}</p></article>`).join('')}</div>` : '<div class="empty-state compact">Žádný zjevný vzorec. Projdi si poznámky u obchodů.</div>'}
      <div class="reflection">
        <p class="eyebrow">OTÁZKY NA SEBE</p>
        <ol>${questions.map(item => `<li>${escapeHtml(item)}</li>`).join('')}</ol>
        <p class="muted">Odpovědi si zapiš přímo k obchodu do pole <em>Chyba / odchylka</em> a <em>Poznámka a poučení</em>. Za měsíc ti tenhle rozbor ukáže, jestli se vzorec opakuje.</p>
      </div>
      <div class="table-wrap"><table><thead><tr><th>Trh</th><th>Setup</th><th>Směr</th><th>R</th><th>Plán</th><th>Exekuce</th><th>Chyba</th></tr></thead><tbody>${
        (analysis.trades || []).map(trade => `<tr class="${trade.followed_plan === 0 || Number(trade.execution_rating) >= 4 ? 'is-broken-row' : ''}"><td>${escapeHtml(trade.market)}</td><td>${escapeHtml(trade.strategy || '—')}</td><td>${directionLabel(trade.direction)}</td><td class="${Number(trade.result_r) >= 0 ? 'value-positive' : 'value-negative'}">${signedR(trade.result_r)}</td><td>${trade.followed_plan === 1 ? 'Ano' : trade.followed_plan === 0 ? 'Ne' : '—'}</td><td>${trade.execution_rating ?? '—'}</td><td>${escapeHtml(trade.mistake || '—')}</td></tr>`).join('')
      }</tbody></table></div>`;
  } catch (error) { toast(error.message, 'error'); }
}

// Čas na otázku vychází z počtu slov, aby zbyl prostor na přečtení i pro pomalejšího
// čtenáře, ale ne na přemýšlení. 0,6 s na slovo odpovídá zhruba 100 slovům za minutu.
const PSYCH_BASE_SECONDS = 6;
const PSYCH_SECONDS_PER_WORD = 0.6;
const PSYCH_MIN_SECONDS = 12;
const PSYCH_MAX_SECONDS = 45;

const psychRun = { questions: [], index: 0, answers: {}, timeout: null, ticker: null };

function psychSeconds(question) {
  const words = `${question.question} ${question.options.join(' ')}`.trim().split(/\s+/).filter(Boolean).length;
  return Math.min(PSYCH_MAX_SECONDS, Math.max(PSYCH_MIN_SECONDS, Math.round(PSYCH_BASE_SECONDS + words * PSYCH_SECONDS_PER_WORD)));
}

function clearPsychTimers() {
  clearTimeout(psychRun.timeout);
  clearInterval(psychRun.ticker);
  psychRun.timeout = null;
  psychRun.ticker = null;
}

function showPsychQuestion() {
  clearPsychTimers();
  const question = psychRun.questions[psychRun.index];
  if (!question) { finishPsychCheck(); return; }

  const seconds = psychSeconds(question);
  $('#psychProgress').textContent = `Otázka ${psychRun.index + 1} z ${psychRun.questions.length}`;
  $('#psychQuestionText').textContent = question.question;
  $('#psychOptions').innerHTML = question.options
    .map((option, value) => `<button class="psych-option" type="button" data-value="${value}"><span>${value + 1}</span>${escapeHtml(option)}</button>`)
    .join('');

  const bar = $('#psychTimerBar');
  bar.style.transition = 'none';
  bar.style.width = '100%';
  bar.style.backgroundColor = 'var(--accent)';
  void bar.offsetWidth;
  bar.style.transition = `width ${seconds}s linear, background-color ${seconds}s linear`;
  bar.style.width = '0%';
  bar.style.backgroundColor = 'var(--red)';

  let remaining = seconds;
  $('#psychSeconds').textContent = `${remaining} s`;
  psychRun.ticker = setInterval(() => {
    remaining -= 1;
    $('#psychSeconds').textContent = `${Math.max(0, remaining)} s`;
  }, 1000);
  psychRun.timeout = setTimeout(() => answerPsych(null), seconds * 1000);
}

function answerPsych(value) {
  const question = psychRun.questions[psychRun.index];
  if (!question) return;
  clearPsychTimers();
  if (value !== null) {
    psychRun.answers[question.key] = value;
  }
  psychRun.index += 1;
  showPsychQuestion();
}

async function finishPsychCheck() {
  clearPsychTimers();
  const answers = psychRun.answers;
  psychRun.questions = [];
  try {
    const check = await api('psych_check', { method: 'POST', body: { check_date: today(), answers } });
    $('#psychDialog').close();
    renderPsychResult(check);
    await refreshPsychLatest();
  } catch (error) { toast(error.message, 'error'); }
}

async function openPsychCheck() {
  try {
    if (!state.psychQuestions.length) {
      const result = await api('psych_questions');
      state.psychQuestions = result.items || [];
    }
    psychRun.questions = state.psychQuestions;
    psychRun.index = 0;
    psychRun.answers = {};
    $('#psychDialog').showModal();
    showPsychQuestion();
  } catch (error) { toast(error.message, 'error'); }
}

const profileRun = { questions: [], index: 0, answers: {} };

function showProfileQuestion() {
  const question = profileRun.questions[profileRun.index];
  if (!question) { finishProfile(); return; }
  $('#profileProgress').textContent = `Otázka ${profileRun.index + 1} z ${profileRun.questions.length}`;
  $('#profileQuestionText').textContent = question.question;
  const current = profileRun.answers[question.key];
  $('#profileOptions').innerHTML = question.options
    .map((option, value) => `<button class="psych-option${current === value ? ' is-selected' : ''}" type="button" data-value="${value}"><span>${value + 1}</span>${escapeHtml(option)}</button>`)
    .join('');
  $('#profileBack').disabled = profileRun.index === 0;
}

async function finishProfile() {
  try {
    const existing = await api('psych_profile');
    const rules = existing.profile?.rules || existing.default_rules;
    const saved = await api('psych_profile', { method: 'POST', body: { answers: profileRun.answers, rules } });
    $('#profileDialog').close();
    renderProfileSummary(saved);
    toast('Profil je uložený. Rychlý test se teď zaměří na tvoje slabá místa.');
    state.psychQuestions = [];
  } catch (error) { toast(error.message, 'error'); }
}

async function openProfileTest() {
  try {
    const result = await api('psych_profile');
    profileRun.questions = result.questions || [];
    profileRun.answers = result.profile?.answers || {};
    profileRun.index = 0;
    $('#profileDialog').showModal();
    showProfileQuestion();
  } catch (error) { toast(error.message, 'error'); }
}

function renderProfileSummary(profile) {
  const root = $('#profileSummary');
  if (!profile || !profile.dimensions) {
    root.outerHTML = '<div id="profileSummary" class="empty-state compact">Profil zatím není vyplněný. Šestnáct otázek odhalí, kde máš silné a kde slabé místo, a rychlý test se pak zaměří přesně na ně.</div>';
    return;
  }
  const dimensions = Object.values(profile.dimensions);
  const weak = dimensions.filter(item => item.level === 'weak');
  const strong = dimensions.filter(item => item.level === 'strong');
  const reality = profile.reality || { findings: [], note: '' };

  root.outerHTML = `<div id="profileSummary">
    <div class="profile-grid">
      ${dimensions.map(item => `<article class="profile-dimension is-${escapeHtml(item.level)}">
        <div><strong>${escapeHtml(item.label)}</strong><em>${item.share} %</em></div>
        <div class="profile-bar"><i data-share="${item.share}"></i></div>
        <p>${escapeHtml(item.detail)}</p>
        <small>${escapeHtml(item.source)}</small>
      </article>`).join('')}
    </div>
    <div class="reflection">
      <p class="eyebrow">CO Z TOHO PLYNE</p>
      <p class="muted">${weak.length ? `Rizikové oblasti: <strong>${weak.map(item => escapeHtml(item.label)).join(', ')}</strong>. Rychlý test jim dává vyšší váhu a přidává na ně cílené otázky.` : 'Žádná oblast nevyšla jako výrazně riziková. Rychlý test běží v základní podobě.'}${strong.length ? ` Silné stránky: ${strong.map(item => escapeHtml(item.label)).join(', ')}.` : ''}</p>
    </div>
    <div class="reflection">
      <p class="eyebrow">OVĚŘENÍ PROTI TVÝM OBCHODŮM</p>
      ${reality.note ? `<p class="muted">${escapeHtml(reality.note)}</p>` : ''}
      ${reality.findings?.length ? `<ul>${reality.findings.map(item => `<li class="${item.agrees ? '' : 'value-negative'}">${escapeHtml(item.text)}${item.agrees ? '' : ' <strong>Tvoje odpověď v profilu tomuhle neodpovídá.</strong>'}</li>`).join('')}</ul>` : ''}
      <p class="muted">Tohle je jediná část profilu spočítaná z dat, ne ze sebehodnocení. Rozpory ber vážně, data nemají důvod ti lichotit.</p>
    </div>
  </div>`;
  $$('#profileSummary .profile-bar i').forEach(bar => { bar.style.width = `${bar.dataset.share}%`; });
}

async function refreshProfile() {
  try {
    const result = await api('psych_profile');
    renderProfileSummary(result.profile);
  } catch (error) { toast(error.message, 'error'); }
}

const ruleBandLabels = { green: 'Zelená — stav v pořádku', amber: 'Oranžová — stav není ideální', red: 'Červená — špatné nastavení hlavy' };
const ruleFields = [
  ['no_trading', 'Dnes neobchoduju vůbec'],
  ['only_a_setups', 'Jen A+ setupy'],
  ['single_account', 'Jen jeden účet'],
  ['no_news', 'Žádné obchody kolem red news'],
  ['stop_after_break', 'Po prvním porušení pravidel končím'],
];

async function openRulesDialog() {
  try {
    const result = await api('psych_profile');
    const rules = result.profile?.rules || result.default_rules;
    $('#rulesEditor').innerHTML = ['green', 'amber', 'red'].map(band => `
      <fieldset class="rules-band is-${band}" data-band="${band}">
        <legend>${escapeHtml(ruleBandLabels[band])}</legend>
        <div class="field-grid two">
          <label>Max. obchodů za den<input type="number" min="0" max="50" data-rule="max_trades" value="${Number(rules[band].max_trades)}" placeholder="0 = bez limitu"></label>
          <label>Risk na obchod (% obvyklého)<input type="number" min="0" max="100" data-rule="risk_percent" value="${Number(rules[band].risk_percent)}"></label>
        </div>
        <div class="checkbox-grid">
          ${ruleFields.map(([key, label]) => `<label><input type="checkbox" data-rule="${key}"${rules[band][key] ? ' checked' : ''}><span>${escapeHtml(label)}</span></label>`).join('')}
        </div>
        <label>Vlastní pravidlo<input data-rule="note" value="${escapeHtml(rules[band].note || '')}" placeholder="Např. jen dopolední session"></label>
      </fieldset>`).join('');
    $('#rulesDialog').showModal();
  } catch (error) { toast(error.message, 'error'); }
}

async function saveRules() {
  const rules = {};
  $$('#rulesEditor .rules-band').forEach(block => {
    const band = block.dataset.band;
    rules[band] = {};
    $$('[data-rule]', block).forEach(field => {
      rules[band][field.dataset.rule] = field.type === 'checkbox' ? field.checked : field.value;
    });
  });
  try {
    const saved = await api('psych_rules', { method: 'POST', body: { rules } });
    $('#rulesDialog').close();
    renderProfileSummary(saved);
    toast('Pravidla jsou uložená. Test ti je ukáže podle výsledku.');
  } catch (error) { toast(error.message, 'error'); }
}

function renderPsychResult(check) {
  const warnings = check.warnings_list || JSON.parse(check.warnings || '[]');
  const rules = check.rules_lines || [];
  $('#psychResultBadge').textContent = bandLabels[check.band] || 'VÝSLEDEK';
  $('#psychResultBody').innerHTML = `
    <div class="audit-verdict ${check.band === 'green' ? 'is-ok' : 'is-warning'}">${escapeHtml(check.verdict)}</div>
    ${rules.length ? `<div class="rules-result is-${escapeHtml(check.band)}"><p class="eyebrow">TVOJE PRAVIDLA PRO DNEŠEK</p><ul>${rules.map(item => `<li>${escapeHtml(item)}</li>`).join('')}</ul></div>` : ''}
    <dl class="account-figures">
      <div><dt>Skóre</dt><dd>${check.score} z ${check.max_score}</dd></div>
      <div><dt>Datum</dt><dd>${escapeHtml(check.check_date)}</dd></div>
      <div><dt>Stav</dt><dd>${escapeHtml((bandLabels[check.band] || '').split('—')[0].trim())}</dd></div>
    </dl>
    ${warnings.length ? `<div class="reflection"><p class="eyebrow">NA CO SI DÁT DNES POZOR</p><ul>${warnings.map(item => `<li>${escapeHtml(item)}</li>`).join('')}</ul></div>` : ''}`;
  $('#psychResultDialog').showModal();
}

async function refreshPsychLatest() {
  try {
    const result = await api('psych_checks');
    const checks = result.items || [];
    const latest = checks[0];
    if (!latest) {
      $('#psychLatest').outerHTML = '<div id="psychLatest" class="empty-state compact">Test zatím nebyl vyplněný. Zabere minutu a řekne ti, jestli dnes obchodovat.</div>';
      return;
    }
    const isToday = latest.check_date === today();
    $('#psychLatest').outerHTML = `<div id="psychLatest" class="psych-latest is-${escapeHtml(latest.band)}">
      <div><strong>${escapeHtml(bandLabels[latest.band] || latest.band)}</strong><p>${escapeHtml(latest.check_date)}${isToday ? ' · dnes' : ' · starší než dnešek, udělej si nový'} · skóre ${latest.score} z ${latest.max_score}</p></div>
      <button class="mini-button" type="button" id="showLastPsych">Detail</button>
    </div>`;
    $('#showLastPsych').addEventListener('click', () => renderPsychResult(latest));
  } catch (error) { toast(error.message, 'error'); }
}

function numberOrNull(value) {
  if (value === '' || value === null || value === undefined) return null;
  const number = Number(value);
  return Number.isFinite(number) ? number : null;
}

function roundedFieldValue(value, digits = 2) {
  const number = numberOrNull(value);
  return number === null ? '' : number.toFixed(digits);
}

function displayAmount(value, currency = 'USD') {
  const number = Number(value);
  if (!Number.isFinite(number)) return '—';
  try {
    return number.toLocaleString('cs-CZ', { style: 'currency', currency: currency || 'USD', maximumFractionDigits: 0 });
  } catch (error) {
    // Starší účet s neplatným kódem měny nesmí shodit celý přehled účtů.
    return `${number.toLocaleString('cs-CZ', { maximumFractionDigits: 0 })} ${currency}`;
  }
}

function ensureOption(select, value, label) {
  const text = String(value ?? '');
  if (text === '' || [...select.options].some(option => option.value === text)) return;
  const anchor = [...select.options].find(option => option.value === '__new');
  select.insertBefore(new Option(label || text, text), anchor || null);
}

function updateStrategyEditButton() {
  const value = $('#tradeStrategy').value;
  $('#editTradeStrategy').disabled = !value || value === '__new';
}

function renderStrategyOptions(selected = null) {
  const select = $('#tradeStrategy');
  const current = selected === null ? select.value : selected;
  select.innerHTML = `<option value="">—</option>${state.strategies.map(item => `<option value="${item.id}">${escapeHtml(item.name)}</option>`).join('')}<option value="__new">+ Přidat strategii / setup</option>`;
  select.value = current && current !== '__new' ? current : '';
  select.dataset.previous = select.value;
  updateStrategyEditButton();
}

async function refreshStrategies() {
  try {
    const result = await api('strategies');
    state.strategies = result.items || [];
    renderStrategyOptions();
  } catch (error) { toast(error.message, 'error'); }
}

function renderStrategyGallery() {
  const shots = state.currentStrategy?.screenshots || [];
  $('#strategyGallery').innerHTML = shots.map(shot => `<figure class="screenshot" data-strategy-screenshot="${escapeHtml(shot.id)}"><img src="file.php?id=${encodeURIComponent(shot.id)}" alt="${escapeHtml(shot.caption || shot.original_name)}"><button type="button" data-delete-strategy-screenshot="${escapeHtml(shot.id)}" aria-label="Odstranit screenshot">×</button></figure>`).join('');
}

async function openStrategyDialog(id = null) {
  const form = $('#strategyForm');
  form.reset();
  $('#strategyScreenshots').value = '';
  $('#strategyScreenshotNames').textContent = 'Vlož ukázkové grafy setupu.';
  state.currentStrategy = null;
  if (id) {
    try {
      state.currentStrategy = await api('strategy', { query: { id } });
      setFormValues(form, state.currentStrategy);
    } catch (error) { toast(error.message, 'error'); return; }
  }
  $('#strategyDialogTitle').textContent = state.currentStrategy ? 'Upravit strategii / setup' : 'Přidat strategii / setup';
  $('#deleteStrategy').hidden = !state.currentStrategy;
  $('#shareStrategy').hidden = !state.currentStrategy;
  renderStrategyGallery();
  $('#strategyDialog').showModal();
}

async function saveStrategyForm() {
  const payload = formObject($('#strategyForm'));
  payload.id = payload.id ? Number(payload.id) : null;
  const strategy = await api('strategy', { method: 'POST', body: payload });
  const files = [...$('#strategyScreenshots').files];
  for (const file of files) {
    const upload = new FormData();
    upload.append('file', file);
    upload.append('strategy_id', strategy.id);
    upload.append('role', 'strategy');
    await api('upload', { method: 'POST', body: upload });
  }
  await refreshStrategies();
  renderStrategyOptions(String(strategy.id));
  $('#strategyDialog').close();
  toast(files.length ? `Strategie a ${files.length} screenshotů jsou uložené.` : 'Strategie je uložená.');
  await refreshTrades();
}

async function deleteCurrentStrategy() {
  const id = state.currentStrategy?.id;
  if (!id) return;
  const used = Number(state.currentStrategy.trade_count || 0);
  const question = used > 0
    ? `Smazat strategii? ${used} obchodů si ponechá název, ale ztratí na ni odkaz.`
    : 'Smazat tuto strategii včetně jejích screenshotů?';
  if (!confirm(question)) return;
  await api('strategy', { method: 'DELETE', query: { id } });
  $('#strategyDialog').close();
  toast('Strategie byla smazána.');
  await refreshStrategies();
  await refreshTrades();
}

function accountLabel(account) {
  return account.broker ? `${account.name} · ${account.broker}` : account.name;
}

function renderAccountOptions() {
  const options = state.accounts.map(item => `<option value="${item.id}">${escapeHtml(accountLabel(item))}</option>`).join('');
  const tradeSelect = $('#tradeAccount');
  const tradeCurrent = tradeSelect.value;
  tradeSelect.innerHTML = `<option value="">—</option>${options}`;
  tradeSelect.value = tradeCurrent;
  const auditSelect = $('#auditAccount');
  const auditCurrent = auditSelect.value;
  auditSelect.innerHTML = options;
  auditSelect.value = auditCurrent;
  const filter = $('#auditAccountFilter');
  const filterCurrent = filter.value;
  filter.innerHTML = `<option value="">Všechny</option>${options}`;
  filter.value = filterCurrent;
}

function renderAccounts() {
  $('#accountList').innerHTML = state.accounts.map(account => {
    const currency = account.currency || 'USD';
    const days = Number(account.days_to_audit) || 0;
    const badge = account.audit_due
      ? '<span class="badge badge-due">Money audit</span>'
      : account.audit_soon
        ? `<span class="badge badge-soon">${days === 0 ? 'Audit dnes' : `Audit za ${days} ${dayWord(days)}`}</span>`
        : `<span class="badge">Další audit ${escapeHtml(account.next_audit_date)}</span>`;
    const removable = Number(account.trades_count) === 0 && !account.last_audit;
    return `<article class="account-card${account.audit_due ? ' is-due' : account.audit_soon ? ' is-soon' : ''}">
      <div class="account-head"><div><strong>${escapeHtml(account.name)}</strong><p>${escapeHtml(account.broker || 'Bez brokera')} · evidence od ${escapeHtml(account.opened_at)}</p></div>${badge}</div>
      <dl class="account-figures">
        <div><dt>Vstupní stav</dt><dd>${displayAmount(account.starting_balance, currency)}</dd></div>
        <div><dt>Podle deníku</dt><dd>${displayAmount(account.expected_balance, currency)}</dd></div>
        <div><dt>Risk na den</dt><dd>${account.daily_risk ? displayAmount(account.daily_risk, currency) : '—'}</dd></div>
        <div><dt>Obchodů</dt><dd>${escapeHtml(account.trades_count)}</dd></div>
        <div><dt>Celkem R</dt><dd>${displayNumber(account.net_r)}R</dd></div>
        <div><dt>Poslední audit</dt><dd>${account.last_audit ? escapeHtml(account.last_audit.audit_date) : 'zatím žádný'}</dd></div>
        <div><dt>Další audit</dt><dd>${escapeHtml(account.next_audit_date)}</dd></div>
      </dl>
      <div class="account-actions"><button class="mini-button" type="button" data-audit-account="${account.id}">Money audit</button>${removable ? `<button class="mini-button danger" type="button" data-delete-account="${account.id}">Smazat</button>` : ''}</div>
    </article>`;
  }).join('') || '<div class="empty-state">Zatím není přidaný žádný obchodní účet. Přidej ho i s aktuálním stavem konta a riskem na den.</div>';
}

function dayWord(count) {
  if (count === 1) return 'den';
  return count >= 2 && count <= 4 ? 'dny' : 'dní';
}

function updateAuditBanner() {
  const due = state.accounts.filter(account => account.audit_due);
  const soon = state.accounts.filter(account => account.audit_soon);
  const banner = $('#auditBanner');
  const flag = $('#navAuditFlag');

  banner.hidden = !moduleOn('accounts') || (due.length === 0 && soon.length === 0);
  flag.hidden = banner.hidden;
  banner.classList.toggle('is-soon', due.length === 0 && soon.length > 0);
  flag.classList.toggle('is-soon', due.length === 0 && soon.length > 0);
  if (banner.hidden) return;

  if (due.length) {
    const detail = due.map(account => {
      const days = Number(account.days_since_audit) || 0;
      return `${account.name} (${days} ${dayWord(days)} ${account.audited_before ? 'od poslední kontroly' : 'od založení účtu'})`;
    }).join(', ');
    $('#auditBannerTitle').textContent = 'Money audit čeká';
    flag.textContent = 'Money audit';
    $('#auditBannerAction').textContent = 'Spustit audit';
    $('#auditBannerText').textContent = `Nahraj screenshot stavu konta a systém ověří, jestli máš zapsané všechny obchody: ${detail}.`;
    return;
  }

  const nearest = soon.reduce((best, account) => (Number(account.days_to_audit) < Number(best.days_to_audit) ? account : best), soon[0]);
  const days = Number(nearest.days_to_audit) || 0;
  const detail = soon.map(account => `${account.name} ${account.next_audit_date}`).join(', ');
  $('#auditBannerTitle').textContent = days === 0 ? 'Money audit je na řadě dnes' : `Money audit za ${days} ${dayWord(days)}`;
  flag.textContent = days === 0 ? 'Audit dnes' : `Audit za ${days} ${dayWord(days)}`;
  $('#auditBannerAction').textContent = 'Udělat teď';
  $('#auditBannerText').textContent = `Připrav si screenshot stavu konta. Termín: ${detail}.`;
}

function renderAuditTable() {
  $('#auditTable').innerHTML = state.audits.map(audit => {
    const currency = audit.currency || 'USD';
    const withinTolerance = Math.abs(Number(audit.difference)) <= Number(audit.tolerance);
    return `<tr>
      <td>${escapeHtml(audit.audit_date)}</td>
      <td>${escapeHtml(audit.account_name)}</td>
      <td>${displayAmount(audit.reported_balance, currency)}</td>
      <td>${displayAmount(audit.expected_balance, currency)}</td>
      <td class="${withinTolerance ? 'value-positive' : 'value-negative'}">${displayAmount(audit.difference, currency)}</td>
      <td>${audit.status === 'ok' ? 'Sedí' : 'Nesedí'}</td>
      <td class="${Number(audit.month_r) >= 0 ? 'value-positive' : 'value-negative'}">${displayNumber(audit.month_r)}R</td>
      <td><button class="mini-button" type="button" data-show-audit="${audit.id}">Detail</button></td>
    </tr>`;
  }).join('') || '<tr><td colspan="8" class="muted">Zatím nebyl proveden žádný Money audit.</td></tr>';
}

async function refreshAudits() {
  try {
    const accountId = $('#auditAccountFilter').value;
    const result = await api('audits', accountId ? { query: { account_id: accountId } } : {});
    state.audits = result.items || [];
    renderAuditTable();
  } catch (error) { toast(error.message, 'error'); }
}

async function refreshAccounts() {
  try {
    const result = await api('accounts');
    state.accounts = result.items || [];
    state.auditIntervalDays = Number(result.interval_days) || 30;
    state.auditWarningDays = Number(result.warning_days) || 3;
    $('#auditIntervalNote').textContent = `Audit je každých ${state.auditIntervalDays} dní, upozornění ${state.auditWarningDays} ${dayWord(state.auditWarningDays)} předem.`;
    renderAccountOptions();
    renderAccounts();
    updateAuditBanner();
    renderReadiness();
    await refreshAudits();
  } catch (error) { toast(error.message, 'error'); }
}

function openAccountDialog() {
  const form = $('#accountForm');
  form.reset();
  form.elements.opened_at.value = today();
  form.elements.currency.value = 'USD';
  $('#accountDialog').showModal();
}

async function saveAccountForm() {
  const payload = formObject($('#accountForm'));
  await api('account', { method: 'POST', body: payload });
  $('#accountDialog').close();
  toast('Účet je uložený. Upravit ho už nelze.');
  await refreshAccounts();
}

function openAuditDialog(accountId = '') {
  if (!state.accounts.length) {
    toast('Nejdřív přidej obchodní účet.', 'error');
    return;
  }
  const form = $('#auditForm');
  form.reset();
  $('#auditScreenshot').value = '';
  $('#auditScreenshotName').textContent = 'Povinný doklad. Nahraj obrazovku účtu nebo výpis od brokera.';
  renderAccountOptions();
  form.elements.audit_date.value = today();
  const preferred = accountId || state.accounts.find(account => account.audit_due)?.id || state.accounts[0].id;
  form.elements.account_id.value = String(preferred);
  $('#auditDialog').showModal();
}

function showAuditResult(audit) {
  const ok = audit.status === 'ok';
  const currency = audit.account?.currency || audit.currency || 'USD';
  const adherence = numberOrNull(audit.plan_adherence);
  const rating = numberOrNull(audit.execution_rating);
  $('#auditResultBadge').textContent = ok ? 'EVIDENCE SEDÍ' : 'EVIDENCE NESEDÍ';
  $('#auditResultBody').innerHTML = `<div class="audit-verdict ${ok ? 'is-ok' : 'is-warning'}">${escapeHtml(audit.verdict)}</div>
    <dl class="account-figures">
      <div><dt>Nahlášený stav</dt><dd>${displayAmount(audit.reported_balance, currency)}</dd></div>
      <div><dt>Podle deníku</dt><dd>${displayAmount(audit.expected_balance, currency)}</dd></div>
      <div><dt>Rozdíl</dt><dd>${displayAmount(audit.difference, currency)}</dd></div>
      <div><dt>Tolerance</dt><dd>${displayAmount(audit.tolerance, currency)}</dd></div>
      <div><dt>Dodržení plánu</dt><dd>${adherence === null ? '—' : `${displayNumber(adherence, 0)} %`}</dd></div>
      <div><dt>Hodnocení exekuce</dt><dd>${rating === null ? '—' : `${displayNumber(rating, 1)} z 5`}</dd></div>
      <div><dt>Výsledek měsíce</dt><dd>${displayNumber(audit.month_r)}R</dd></div>
      <div><dt>Měsíc v penězích</dt><dd>${displayAmount(audit.month_usd, currency)}</dd></div>
    </dl>`;
  $('#auditResultDialog').showModal();
}

async function submitAudit() {
  const file = $('#auditScreenshot').files[0];
  if (!file) {
    toast('Money audit vyžaduje screenshot stavu konta.', 'error');
    return;
  }
  const payload = formObject($('#auditForm'));
  payload.account_id = Number(payload.account_id);
  const audit = await api('audit', { method: 'POST', body: payload });
  const upload = new FormData();
  upload.append('file', file);
  upload.append('audit_id', audit.id);
  upload.append('role', 'audit');
  try {
    await api('upload', { method: 'POST', body: upload });
  } catch (error) {
    toast(`Audit je uložený, ale screenshot se nenahrál: ${error.message}`, 'error');
  }
  $('#auditDialog').close();
  await refreshAccounts();
  showAuditResult(audit);
}

function computeTrade(data) {
  const entry = numberOrNull(data.entry_price);
  const exit = numberOrNull(data.exit_price);
  const stop = numberOrNull(data.stop_loss);
  const risk = numberOrNull(data.risk_amount);
  const fees = numberOrNull(data.fees) ?? 0;
  const pointValue = pointValueFor(data.market);

  if (entry === null || stop === null || entry === stop) return { error: 'Doplň entry a stop loss, ať jde spočítat výsledek.' };
  if (risk === null || risk <= 0) return { error: 'Doplň risk na trade v dolarech; z něj se počítá celý výsledek i R.' };

  // Riziko na bod pohybu. Hodnota bodu se v P&L i v R vykrátí, proto se nezadává.
  const riskPerPoint = risk / Math.abs(entry - stop);
  const contracts = pointValue ? riskPerPoint / pointValue : null;
  if (exit === null) return { risk, riskPerPoint, contracts, fees, gross: null, net: null, r: null };

  const direction = data.direction === 'short' ? -1 : 1;
  const gross = (exit - entry) * direction * riskPerPoint;
  const net = gross - fees;
  return { risk, riskPerPoint, contracts, fees, gross, net, r: net / risk };
}

function updateTradeCalcHint() {
  const hint = $('#tradeCalcHint');
  const result = computeTrade(formObject($('#tradeForm')));
  if (result.error) {
    hint.textContent = result.error;
    return;
  }
  const parts = [`1R = <strong>${displayMoney(result.risk)}</strong>`, `${displayMoney(result.riskPerPoint)} na bod`];
  if (result.contracts !== null) {
    parts.unshift(`Velikost pozice ≈ <strong>${displayNumber(result.contracts)}</strong> kontraktu`);
  }
  if (result.net !== null) {
    parts.push(`hrubě ${displayMoney(result.gross)}`, `poplatky ${displayMoney(result.fees)}`, `čistě <strong>${displayMoney(result.net)}</strong>`, `výsledek <strong>${displayNumber(result.r)}R</strong>`);
  }
  hint.innerHTML = parts.join(' · ');
}

function applyTradeResult() {
  const form = $('#tradeForm');
  const result = computeTrade(formObject(form));
  if (result.error) {
    toast(result.error, 'error');
    return;
  }
  if (result.net === null) {
    toast('Doplň exit, jinak nejde dopočítat výsledek obchodu.', 'error');
    return;
  }
  form.elements.result_usd.value = result.net.toFixed(2);
  form.elements.result_r.value = result.r.toFixed(2);
  updateTradeCalcHint();
  toast(`Doplněno ${displayNumber(result.r)}R a ${displayMoney(result.net)}.`);
}

function selectedEmotions() {
  return $$('#tradeEmotions input[data-emotion]:checked').map(input => input.value);
}

function setEmotions(value) {
  const tokens = String(value ?? '').split(',').map(item => item.trim()).filter(Boolean);
  $$('#tradeEmotions [data-custom-emotion]').forEach(label => label.remove());
  const known = $$('#tradeEmotions input[data-emotion]');
  known.forEach(input => { input.checked = tokens.includes(input.value); });
  const values = known.map(input => input.value);
  tokens.filter(token => !values.includes(token)).forEach(token => {
    const label = document.createElement('label');
    label.dataset.customEmotion = '';
    const input = document.createElement('input');
    input.type = 'checkbox';
    input.dataset.emotion = '';
    input.value = token;
    input.checked = true;
    input.defaultChecked = true;
    const caption = document.createElement('span');
    caption.textContent = token;
    label.append(input, caption);
    $('#tradeEmotions').append(label);
  });
}

function renderDataLists() {
  // Vlastní trhy první v pořadí z nastavení, pak ostatní použité v deníku.
  const own = marketList().map(item => item.symbol);
  const used = [...state.trades.map(item => item.market), ...state.plans.map(item => item.market)].map(value => String(value ?? '').trim()).filter(Boolean);
  const markets = [...new Set([...own, ...used.filter(value => !own.includes(value)).sort()])];
  $('#marketOptions').innerHTML = markets.map(item => `<option value="${escapeHtml(item)}"></option>`).join('');

  const sessions = [...new Set([...defaultSessions, ...state.trades.map(item => tradeTypeLabel(item.session)), ...state.plans.map(item => tradeTypeLabel(item.session))].filter(Boolean))];
  $('#sessionOptions').innerHTML = sessions.map(item => `<option value="${escapeHtml(item)}"></option>`).join('');

  [['#globalMarket', 'Všechny trhy'], ['#tradeMarketFilter', 'Všechny'], ['#planMarketFilter', 'Všechny']].forEach(([selector, label]) => {
    const select = $(selector);
    const current = select.value;
    select.innerHTML = `<option value="">${label}</option>${markets.map(item => `<option value="${escapeHtml(item)}">${escapeHtml(item)}</option>`).join('')}`;
    select.value = current;
  });
}

function filteredTrades() {
  const market = $('#tradeMarketFilter').value;
  const search = $('#tradeSearch').value.trim().toLocaleLowerCase('cs');
  return state.trades.filter(trade => (!market || trade.market === market) && (!search || `${trade.strategy} ${trade.notes} ${trade.mistake}`.toLocaleLowerCase('cs').includes(search)));
}

function tableFields() {
  return activeFields('trade').filter(field => field.in_table);
}

function customCell(field, trade) {
  let values = {};
  try { values = JSON.parse(trade.custom || '{}') || {}; } catch (error) { values = {}; }
  const value = values[field.id];
  if (value === undefined || value === null || value === '') return '<td class="muted">—</td>';
  if (field.kind === 'bool') return `<td class="${value ? 'value-positive' : 'value-negative'}">${value ? 'Ano' : 'Ne'}</td>`;
  if (field.kind === 'rating') return `<td>${escapeHtml(value)} / 5</td>`;
  return `<td>${escapeHtml(value)}</td>`;
}

function renderTradeHead() {
  const strategy = isHiddenEl('trade.strategy') ? '' : '<th>Setup</th>';
  const plan = isHiddenEl('trade.followed') ? '' : '<th>Plán</th>';
  $('#tradeHead').innerHTML = `<tr><th>Datum</th><th>Trh</th>${strategy}<th>Směr</th><th class="num">Entry / Exit</th><th class="num">R</th><th class="num">P&amp;L</th>${plan}${tableFields().map(field => `<th>${escapeHtml(field.label)}</th>`).join('')}<th></th></tr>`;
}

function renderTradeTable() {
  const rows = filteredTrades();
  const fields = tableFields();
  const strategyOn = !isHiddenEl('trade.strategy');
  const planOn = !isHiddenEl('trade.followed');
  const columns = 7 + (strategyOn ? 1 : 0) + (planOn ? 1 : 0) + fields.length;
  $('#tradeTable').innerHTML = rows.map(trade => `<tr><td>${escapeHtml(trade.trade_date)}</td><td><strong>${escapeHtml(trade.market)}</strong></td>${strategyOn ? `<td>${escapeHtml(trade.strategy || '—')}</td>` : ''}<td class="direction-${escapeHtml(trade.direction)}">${directionLabel(trade.direction)}</td><td>${displayNumber(trade.entry_price)} / ${displayNumber(trade.exit_price)}</td><td class="${Number(trade.result_r) >= 0 ? 'value-positive' : 'value-negative'}">${displayNumber(trade.result_r)}R</td><td class="${Number(trade.result_usd) >= 0 ? 'value-positive' : 'value-negative'}">${displayMoney(trade.result_usd)}</td>${planOn ? `<td>${trade.followed_plan === 1 || trade.followed_plan === '1' ? 'Ano' : trade.followed_plan === 0 || trade.followed_plan === '0' ? 'Ne' : '—'}</td>` : ''}${fields.map(field => customCell(field, trade)).join('')}<td>${sharedMark('trade', trade.id)}<button class="mini-button" type="button" data-edit-trade="${trade.id}">Upravit</button>${shareButton('trade', trade.id)}<button class="mini-button danger" type="button" data-delete-trade="${trade.id}">Smazat</button></td></tr>`).join('') || `<tr><td colspan="${columns}" class="muted">Žádné obchody odpovídající filtru.</td></tr>`;
}

async function refreshTrades() {
  const request = ++refreshTrades.sequence;
  try {
    const result = await api('trades', { query: { limit: 1000 } });
    if (request !== refreshTrades.sequence) return;
    state.trades = result.items || [];
    renderDataLists();
    renderTradeTable();
  } catch (error) {
    toast(error.message, 'error');
  }
}
refreshTrades.sequence = 0;

async function openTrade(trade = null) {
  const form = $('#tradeForm');
  form.reset();
  $('#tradeScreenshots').value = '';
  $('#tradeScreenshotNames').textContent = 'Volitelně přidej entry, exit nebo výsledný graf.';
  renderStrategyOptions('');
  renderAccountOptions();
  form.elements.plan_id.value = trade?.plan_id || state.currentPlan?.id || '';
  const defaults = prefs().defaults || {};
  const defaultAccount = defaults.account_id && state.accounts.some(account => account.id === defaults.account_id) ? String(defaults.account_id) : '';
  form.elements.account_id.value = defaultAccount || (state.accounts.length === 1 ? String(state.accounts[0].id) : '');
  if (!trade && defaults.risk) form.elements.risk_amount.value = defaults.risk;
  if (trade) {
    setFormValues(form, trade);
    form.elements.strategy_id.value = trade.strategy_id ? String(trade.strategy_id) : '';
    form.elements.account_id.value = trade.account_id ? String(trade.account_id) : '';
  }
  form.elements.trade_date.value = trade?.trade_date || today();
  form.elements.market.value = trade?.market || $('#globalMarket').value || state.currentPlan?.market || defaults.market || marketList()[0]?.symbol || 'ES';
  form.elements.session.value = trade ? tradeTypeLabel(trade.session) : (defaults.session || tradeTypeLabel(null));
  form.elements.fees.value = trade?.fees ?? defaults.fees ?? 0;
  let customValues = {};
  try { customValues = JSON.parse(trade?.custom || '{}') || {}; } catch (error) { customValues = {}; }
  renderTradeCustomFields(customValues);
  form.elements.result_r.value = roundedFieldValue(trade?.result_r);
  form.elements.result_usd.value = roundedFieldValue(trade?.result_usd);
  setEmotions(trade?.emotion);
  $('#tradeStrategy').dataset.previous = form.elements.strategy_id.value;
  updateStrategyEditButton();
  $('#tradeDialogTitle').textContent = trade ? 'Upravit obchod' : 'Přidat obchod';
  updateTradeCalcHint();
  $('#tradeDialog').showModal();
}

async function editTrade(id) {
  try {
    const trade = await api('trade', { query: { id } });
    openTrade(trade);
  } catch (error) { toast(error.message, 'error'); }
}

async function saveTrade() {
  const payload = formObject($('#tradeForm'));
  payload.id = payload.id ? Number(payload.id) : null;
  payload.plan_id = payload.plan_id ? Number(payload.plan_id) : null;
  payload.strategy_id = payload.strategy_id && payload.strategy_id !== '__new' ? Number(payload.strategy_id) : null;
  payload.account_id = payload.account_id ? Number(payload.account_id) : null;
  payload.emotion = selectedEmotions().join(',');
  payload.custom = extractCustomValues(payload, 'trade');
  try {
    const trade = await api('trade', { method: 'POST', body: payload });
    const files = [...$('#tradeScreenshots').files];
    for (const file of files) {
      const upload = new FormData();
      upload.append('file', file);
      upload.append('trade_id', trade.id);
      upload.append('role', 'after');
      await api('upload', { method: 'POST', body: upload });
    }
    $('#tradeDialog').close();
    toast(files.length ? `Obchod a ${files.length} screenshotů jsou uložené.` : 'Obchod je uložený v deníku.');
    await Promise.all([refreshTrades(), refreshDashboard(), refreshPlans(), refreshAccounts()]);
  } catch (error) { toast(error.message, 'error'); }
}

function renderArchive() {
  const market = $('#planMarketFilter').value;
  const type = $('#planTypeFilter').value;
  const plans = state.plans.filter(plan => (!market || plan.market === market) && (!type || (plan.plan_type || 'daily') === type));
  $('#planArchive').innerHTML = plans.map(plan => {
    const weekly = plan.plan_type === 'weekly';
    const chips = [biasChip('PA W', plan.pa_weekly), biasChip(weekly ? 'MP W' : 'PA D', weekly ? plan.mp_weekly : plan.pa_daily), weekly ? '' : biasChip('MP D', plan.mp_daily)].join('');
    return `<article class="archive-row" data-load-plan="${plan.id}">
      <div class="archive-date"><span class="type-tag is-${weekly ? 'weekly' : 'daily'}">${weekly ? 'Týden' : 'Den'}</span><strong>${escapeHtml(weekly ? `Týden ${isoWeek(plan.plan_date)}` : prettyDate(plan.plan_date))}</strong><p>${escapeHtml(plan.plan_date)} · ${escapeHtml(tradeTypeLabel(plan.session))}</p></div>
      <strong class="archive-market">${escapeHtml(plan.market)}</strong>
      <span class="badge direction-${escapeHtml(plan.bias)}">${directionLabel(plan.bias)}</span>
      <div class="archive-summary"><div class="bias-chips">${chips}</div><p>${escapeHtml(plan.bias_description || 'Bez popisu trhu')}</p></div>
      <div class="archive-actions">${sharedMark('plan', plan.id)}<span class="muted">${Number(plan.zone_count || 0)} zón</span><button class="mini-button" type="button" data-export-plan-pdf="${plan.id}">PDF</button>${shareButton('plan', plan.id)}<button class="mini-button" type="button" data-load-plan="${plan.id}">Otevřít</button><button class="mini-button danger" type="button" data-delete-plan="${plan.id}">Smazat</button></div>
    </article>`;
  }).join('') || '<div class="empty-state">Zatím nejsou uložené žádné náhledy. Začni týdenním náhledem o víkendu a denním ráno před session.</div>';
}

async function refreshPlans() {
  const request = ++refreshPlans.sequence;
  try {
    const result = await api('plans', { query: { limit: 250 } });
    if (request !== refreshPlans.sequence) return;
    state.plans = result.items || [];
    renderDataLists();
    renderArchive();
  } catch (error) { toast(error.message, 'error'); }
}
refreshPlans.sequence = 0;

async function checkHealth() {
  try {
    const result = await api('health');
    $('#serverDot').className = 'status-dot is-online';
    $('#serverStatus').textContent = result.database === 'ok' ? 'Server a databáze online' : 'Server online';
  } catch (error) {
    $('#serverDot').className = 'status-dot is-offline';
    $('#serverStatus').textContent = 'Server není dostupný';
  }
}

/** Druhé kliknutí na vybranou volbu ji zruší, ať jde bias nebo tvar profilu vrátit na nevyplněno. */
function enableRadioToggle(root) {
  root.addEventListener('pointerdown', event => {
    const input = $('input[type="radio"]', event.target.closest('label') || document.createElement('span'));
    if (input) input.dataset.wasChecked = input.checked ? '1' : '';
  }, true);
  root.addEventListener('click', event => {
    const input = event.target.closest('input[type="radio"]');
    if (!input || !input.closest('.tri-switch, .shape-options, .ladder')) return;
    if (input.dataset.wasChecked === '1') {
      input.checked = false;
      input.dispatchEvent(new Event('change', { bubbles: true }));
    }
    input.dataset.wasChecked = '';
  });
}

function bindPlanEvents() {
  const form = $('#planForm');
  enableRadioToggle(form);
  enableRadioToggle($('#tradeForm'));
  // Lišta náhledu se může zalomit do dvou řádků; kroky pod ní se podle toho posunou.
  if ('ResizeObserver' in window) {
    const toolbar = $('.plan-toolbar');
    new ResizeObserver(() => {
      if (toolbar.offsetHeight) document.documentElement.style.setProperty('--toolbar-h', `${toolbar.offsetHeight}px`);
    }).observe(toolbar);
  }
  form.addEventListener('submit', async event => { event.preventDefault(); try { await savePlan(); } catch (error) { toast(error.message, 'error'); } });
  form.addEventListener('input', event => {
    if (event.target.matches('.zone-row [name="name"], .idea-row [name="name"]')) renumberRows();
    if (event.target.matches('[data-ref-price]')) deriveCloseLocation();
    if (event.target.matches('.zone-row [name="source"]')) syncTokenChips(event.target.closest('.zone-row'));
    schedulePlanRefresh();
  });
  form.addEventListener('change', event => {
    if (event.target.matches('[name="plan_type"]')) onPlanTypeChange();
    if (event.target.matches('#planDate')) {
      if (planType() === 'weekly' && event.target.value) event.target.value = mondayOf(event.target.value);
      applySessionLocks();
      refreshWeeklyContext();
    }
    if (event.target.matches('#planMarket, #planSession')) { applySessionLocks(); refreshWeeklyContext(); }
    if (event.target.matches('.level-row [name="kind"]')) event.target.closest('.level-row').dataset.kind = event.target.value;
    if (event.target.matches('.zone-row [name="valid_mode"], .zone-row [name="valid_until"]')) syncZoneValidity(event.target.closest('.zone-row'));
    schedulePlanRefresh();
  });

  $('#planSteps').addEventListener('click', event => {
    const button = event.target.closest('[data-step]');
    if (!button) return;
    $(`#planForm [data-section="${button.dataset.step}"]`)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });

  $('#addZone').addEventListener('click', () => {
    const row = appendRow('#zoneList', zoneTemplate({}, $$('.zone-row').length));
    $('[name="name"]', row).focus();
    schedulePlanRefresh();
  });
  $('#addIdea').addEventListener('click', () => {
    const row = appendRow('#ideaList', ideaTemplate({}, $$('.idea-row').length));
    $('[name="name"]', row).focus();
    schedulePlanRefresh();
  });
  $('#addLevel').addEventListener('click', () => {
    const row = appendRow('#levelList', levelTemplate({}, $$('.level-row').length));
    $('[name="name"]', row).focus();
    schedulePlanRefresh();
  });
  $('#refQuickAdd').addEventListener('click', event => {
    const button = event.target.closest('[data-add-ref]');
    if (!button) return;
    const row = appendRow('#refList', refTemplate({ kind: button.dataset.addRef }));
    $('[name="price_low"]', row).focus();
    schedulePlanRefresh();
  });
  $('#refPricesToLevels').addEventListener('click', refPricesToLevels);

  $('#zoneList').addEventListener('click', event => {
    const row = event.target.closest('.zone-row');
    if (!row) return;
    if (event.target.closest('[data-remove-zone]')) {
      row.remove();
      if (!$$('.zone-row').length) renderZones([]);
      renumberRows();
      schedulePlanRefresh();
      return;
    }
    const dirButton = event.target.closest('[data-dir]');
    if (dirButton) {
      const value = $('[name="direction"]', row).value === dirButton.dataset.dir ? '' : dirButton.dataset.dir;
      setZoneDirection(row, value);
      if (value) $(`.cond-${value === 'short' ? 'short' : 'long'} textarea`, row)?.focus();
      schedulePlanRefresh();
      return;
    }
    const chip = event.target.closest('[data-token]');
    if (chip) {
      toggleSourceToken(row, chip.dataset.token);
      schedulePlanRefresh();
    }
  });
  $('#ideaList').addEventListener('click', event => {
    if (!event.target.closest('[data-remove-idea]')) return;
    event.target.closest('.idea-row').remove();
    if (!$$('.idea-row').length) renderIdeas([]);
    renumberRows();
    schedulePlanRefresh();
  });
  $('#levelList').addEventListener('click', event => {
    if (!event.target.closest('[data-remove-level]')) return;
    event.target.closest('.level-row').remove();
    if (!$$('.level-row').length) renderLevels([]);
    schedulePlanRefresh();
  });
  $('#refList').addEventListener('click', event => {
    const row = event.target.closest('.ref-row');
    if (!row) return;
    if (event.target.closest('[data-remove-ref]')) {
      row.remove();
      if (!$$('.ref-row').length) renderRefs([]);
      schedulePlanRefresh();
      return;
    }
    if (event.target.closest('[data-ref-to-zone]')) refToZone(row);
  });

  $('#newPlan').addEventListener('click', () => resetPlan({ type: planType() }));
  $('#exportPlanPdf').addEventListener('click', async () => {
    try { await exportCurrentPlanPdf(); } catch (error) { toast(error.message, 'error'); }
  });
  $('#exportTradingViewZones').addEventListener('click', openTradingViewExport);
  $('#closeTradingViewDialog').addEventListener('click', () => $('#tradingViewDialog').close());
  $('#regenerateTradingViewZones').addEventListener('click', refreshTradingViewExport);
  $('#copyTradingViewZones').addEventListener('click', async () => {
    try { await copyTradingViewCode(); } catch (error) { toast('Kód se nepodařilo zkopírovat.', 'error'); }
  });
  $('#downloadTradingViewZones').addEventListener('click', downloadTradingViewCode);
  ['tvShowLabels', 'tvIncludeSource', 'tvIncludeRefs', 'tvIncludeDn', 'tvExtendMode'].forEach(id => $(`#${id}`).addEventListener('change', refreshTradingViewExport));
  $('#tvFillTransparency').addEventListener('input', refreshTradingViewExport);
  $('#tradingViewDialog').addEventListener('click', event => { if (event.target === $('#tradingViewDialog')) $('#tradingViewDialog').close(); });

  const dropzone = $('#planDropzone');
  const fileInput = $('#planScreenshot');
  fileInput.addEventListener('change', async () => {
    try { await uploadScreenshots([...fileInput.files]); fileInput.value = ''; } catch (error) { toast(error.message, 'error'); }
  });
  ['dragenter', 'dragover'].forEach(type => dropzone.addEventListener(type, event => { event.preventDefault(); dropzone.classList.add('is-dragging'); }));
  ['dragleave', 'drop'].forEach(type => dropzone.addEventListener(type, event => { event.preventDefault(); dropzone.classList.remove('is-dragging'); }));
  dropzone.addEventListener('drop', async event => {
    try { await uploadScreenshots([...event.dataTransfer.files].filter(file => file.type.startsWith('image/'))); } catch (error) { toast(error.message, 'error'); }
  });
  $('#planScreenshots').addEventListener('click', async event => {
    const deleteButton = event.target.closest('[data-delete-screenshot]');
    if (deleteButton) {
      event.stopPropagation();
      if (!confirm('Odstranit tento screenshot?')) return;
      try {
        await api('upload', { method: 'DELETE', query: { id: deleteButton.dataset.deleteScreenshot } });
        state.currentScreenshots = state.currentScreenshots.filter(item => item.id !== deleteButton.dataset.deleteScreenshot);
        renderScreenshots();
        toast('Screenshot byl odstraněn.');
      } catch (error) { toast(error.message, 'error'); }
      return;
    }
    const figure = event.target.closest('[data-screenshot]');
    if (figure) openLightbox(figure.dataset.screenshot);
  });
}

function bindEvents() {
  $$('.nav-item').forEach(item => item.addEventListener('click', () => activateView(item.dataset.view)));
  $('#mobileMenu').addEventListener('click', () => ($('#sidebar').classList.contains('is-open') ? closeSidebar() : openSidebar()));
  $('#sidebarScrim').addEventListener('click', closeSidebar);
  $('#themeToggle').addEventListener('click', () => setTheme(currentTheme() === 'light' ? 'dark' : 'light'));
  $('#quickPlan').addEventListener('click', event => {
    event.stopPropagation();
    const menu = $('#quickPlanMenu');
    menu.hidden = !menu.hidden;
    $('#quickPlan').setAttribute('aria-expanded', String(!menu.hidden));
  });
  $('#quickTrade').addEventListener('click', () => openTrade());
  $('#globalMarket').addEventListener('change', refreshDashboard);
  document.addEventListener('keydown', event => { if (event.key === 'Escape') { closeQuickPlanMenu(); closeSidebar(); } });
  bindPlanEvents();

  document.addEventListener('click', async event => {
    if (!event.target.closest('.menu-wrap')) closeQuickPlanMenu();
    const newPlan = event.target.closest('[data-new-plan]');
    if (newPlan) {
      event.preventDefault();
      startNewPlan(newPlan.dataset.newPlan);
      return;
    }
    const openView = event.target.closest('[data-open-view]');
    if (openView) {
      event.preventDefault();
      activateView(openView.dataset.openView);
      return;
    }
    if (event.target.closest('[data-start-psych]')) {
      event.preventDefault();
      openPsychCheck();
      return;
    }
    const exportPdf = event.target.closest('[data-export-plan-pdf]');
    if (exportPdf) {
      event.preventDefault();
      event.stopPropagation();
      downloadPlanPdf(exportPdf.dataset.exportPlanPdf);
      toast('PDF se stahuje.');
      return;
    }
    const deletePlan = event.target.closest('[data-delete-plan]');
    if (deletePlan) {
      event.preventDefault(); event.stopPropagation();
      if (!confirm('Smazat náhled včetně jeho screenshotů? Obchody zůstanou v deníku.')) return;
      try { await api('plan', { method: 'DELETE', query: { id: deletePlan.dataset.deletePlan } }); weeklyContextCache.clear(); toast('Náhled byl smazán.'); await Promise.all([refreshPlans(), refreshDashboard()]); } catch (error) { toast(error.message, 'error'); }
      return;
    }
    const load = event.target.closest('[data-load-plan]');
    if (load) {
      event.preventDefault();
      try { await loadPlan(load.dataset.loadPlan); } catch (error) { toast(error.message, 'error'); }
    }
  });

  $('#tradeMarketFilter').addEventListener('change', renderTradeTable);
  $('#tradeSearch').addEventListener('input', renderTradeTable);
  $('#planMarketFilter').addEventListener('change', renderArchive);
  $('#addTrade').addEventListener('click', () => openTrade());
  $('#tradeScreenshots').addEventListener('change', event => {
    const names = [...event.target.files].map(file => file.name);
    $('#tradeScreenshotNames').textContent = names.length ? names.join(' · ') : 'Volitelně přidej entry, exit nebo výsledný graf.';
  });
  $('#tradeForm').addEventListener('submit', event => { event.preventDefault(); if (event.submitter?.value === 'cancel') { $('#tradeDialog').close(); return; } saveTrade(); });
  $('#tradeForm').addEventListener('input', updateTradeCalcHint);
  $('#tradeForm').addEventListener('change', updateTradeCalcHint);

  $('#calcTradeResult').addEventListener('click', applyTradeResult);
  $('#tradeMarket').addEventListener('input', updateTradeCalcHint);

  const strategySelect = $('#tradeStrategy');
  strategySelect.addEventListener('change', () => {
    updateStrategyEditButton();
    if (strategySelect.value !== '__new') {
      strategySelect.dataset.previous = strategySelect.value;
      return;
    }
    strategySelect.value = strategySelect.dataset.previous || '';
    updateStrategyEditButton();
    openStrategyDialog();
  });
  $('#editTradeStrategy').addEventListener('click', () => {
    const id = strategySelect.value;
    if (!id || id === '__new') { toast('Nejdřív vyber strategii, kterou chceš upravit.', 'error'); return; }
    openStrategyDialog(id);
  });
  $('#strategyScreenshots').addEventListener('change', event => {
    const names = [...event.target.files].map(file => file.name);
    $('#strategyScreenshotNames').textContent = names.length ? names.join(' · ') : 'Vlož ukázkové grafy setupu.';
  });
  $('#strategyForm').addEventListener('submit', async event => {
    event.preventDefault();
    if (event.submitter?.value === 'cancel') { $('#strategyDialog').close(); return; }
    try { await saveStrategyForm(); } catch (error) { toast(error.message, 'error'); }
  });
  $('#deleteStrategy').addEventListener('click', async () => {
    try { await deleteCurrentStrategy(); } catch (error) { toast(error.message, 'error'); }
  });
  $('#strategyGallery').addEventListener('click', async event => {
    const deleteButton = event.target.closest('[data-delete-strategy-screenshot]');
    if (deleteButton) {
      event.stopPropagation();
      if (!confirm('Odstranit tento screenshot?')) return;
      try {
        await api('upload', { method: 'DELETE', query: { id: deleteButton.dataset.deleteStrategyScreenshot } });
        state.currentStrategy.screenshots = (state.currentStrategy.screenshots || []).filter(item => item.id !== deleteButton.dataset.deleteStrategyScreenshot);
        renderStrategyGallery();
        toast('Screenshot byl odstraněn.');
      } catch (error) { toast(error.message, 'error'); }
      return;
    }
    const figure = event.target.closest('[data-strategy-screenshot]');
    if (figure) openLightbox(figure.dataset.strategyScreenshot, state.currentStrategy?.screenshots || []);
  });

  $('#strategyTable').addEventListener('click', event => {
    const button = event.target.closest('[data-strategy-trades]');
    if (!button) return;
    $('#tradeSearch').value = button.dataset.strategyTrades === 'Bez strategie' ? '' : button.dataset.strategyTrades;
    activateView('journal');
    renderTradeTable();
  });

  $('#calendarPrev').addEventListener('click', () => refreshCalendar(shiftMonth(state.calendarMonth, -1)));
  $('#calendarNext').addEventListener('click', () => refreshCalendar(shiftMonth(state.calendarMonth, 1)));
  $('#calendarToday').addEventListener('click', () => refreshCalendar(today().slice(0, 7)));
  $('#calendarGrid').addEventListener('click', event => {
    const cell = event.target.closest('[data-calendar-day]');
    if (cell) openDayDialog(cell.dataset.calendarDay);
  });
  $('#dayOpenPlan').addEventListener('click', async () => {
    const id = $('#dayOpenPlan').dataset.planId;
    if (!id) return;
    $('#dayDialog').close();
    try { await loadPlan(id); } catch (error) { toast(error.message, 'error'); }
  });
  $('#dayEvents').addEventListener('click', async event => {
    const button = event.target.closest('[data-delete-event]');
    if (!button) return;
    try {
      await api('calendar_event', { method: 'DELETE', query: { id: button.dataset.deleteEvent } });
      await refreshCalendar();
      openDayDialog($('#dayForm').elements.event_date.value);
      toast('Událost byla smazána.');
    } catch (error) { toast(error.message, 'error'); }
  });
  $('#dayForm').addEventListener('submit', async event => {
    event.preventDefault();
    if (event.submitter?.value === 'cancel') { $('#dayDialog').close(); return; }
    const payload = formObject($('#dayForm'));
    if (!payload.title.trim()) { toast('Doplň název události.', 'error'); return; }
    try {
      await api('calendar_event', { method: 'POST', body: payload });
      await refreshCalendar();
      openDayDialog(payload.event_date);
      toast('Událost je přidaná ke dni.');
    } catch (error) { toast(error.message, 'error'); }
  });

  $('#startPsychProfile').addEventListener('click', openProfileTest);
  $('#profileOptions').addEventListener('click', event => {
    const button = event.target.closest('[data-value]');
    if (!button) return;
    const question = profileRun.questions[profileRun.index];
    profileRun.answers[question.key] = Number(button.dataset.value);
    profileRun.index += 1;
    showProfileQuestion();
  });
  $('#profileBack').addEventListener('click', () => {
    if (profileRun.index === 0) return;
    profileRun.index -= 1;
    showProfileQuestion();
  });
  $('#profileForm').addEventListener('submit', event => {
    event.preventDefault();
    if (event.submitter?.value === 'cancel') $('#profileDialog').close();
  });
  $('#editPsychRules').addEventListener('click', openRulesDialog);
  $('#rulesForm').addEventListener('submit', event => {
    event.preventDefault();
    if (event.submitter?.value === 'cancel') { $('#rulesDialog').close(); return; }
    saveRules();
  });

  $('#startPsychCheck').addEventListener('click', openPsychCheck);
  $('#psychForm').addEventListener('submit', event => {
    event.preventDefault();
    if (event.submitter?.value === 'cancel') $('#psychDialog').close();
  });
  $('#psychOptions').addEventListener('click', event => {
    const button = event.target.closest('[data-value]');
    if (button) answerPsych(Number(button.dataset.value));
  });
  $('#psychDialog').addEventListener('close', clearPsychTimers);
  $('#psychDialog').addEventListener('keydown', event => {
    if (!/^[1-4]$/.test(event.key)) return;
    const button = $(`#psychOptions [data-value="${Number(event.key) - 1}"]`);
    if (button) { event.preventDefault(); answerPsych(Number(button.dataset.value)); }
  });

  $('#disciplineDays').addEventListener('click', event => {
    const button = event.target.closest('[data-discipline-day]');
    if (button) showDisciplineDay(button.dataset.disciplineDay);
  });
  $('#disciplineDetail').addEventListener('click', async event => {
    const button = event.target.closest('[data-open-plan]');
    if (!button) return;
    try { await loadPlan(button.dataset.openPlan); } catch (error) { toast(error.message, 'error'); }
  });

  $('#addAccount').addEventListener('click', openAccountDialog);
  $('#accountForm').addEventListener('submit', async event => {
    event.preventDefault();
    if (event.submitter?.value === 'cancel') { $('#accountDialog').close(); return; }
    try { await saveAccountForm(); } catch (error) { toast(error.message, 'error'); }
  });
  $('#openAudit').addEventListener('click', () => openAuditDialog());
  $('#auditBannerAction').addEventListener('click', () => { activateView('accounts'); openAuditDialog(); });
  $('#auditAccountFilter').addEventListener('change', refreshAudits);
  $('#auditScreenshot').addEventListener('change', event => {
    const file = event.target.files[0];
    $('#auditScreenshotName').textContent = file ? file.name : 'Povinný doklad. Nahraj obrazovku účtu nebo výpis od brokera.';
  });
  $('#auditForm').addEventListener('submit', async event => {
    event.preventDefault();
    if (event.submitter?.value === 'cancel') { $('#auditDialog').close(); return; }
    try { await submitAudit(); } catch (error) { toast(error.message, 'error'); }
  });
  $('#accountList').addEventListener('click', async event => {
    const auditButton = event.target.closest('[data-audit-account]');
    if (auditButton) { openAuditDialog(auditButton.dataset.auditAccount); return; }
    const deleteButton = event.target.closest('[data-delete-account]');
    if (deleteButton && confirm('Smazat tento účet? Jde to jen dokud na něm není žádný obchod ani audit.')) {
      try { await api('account', { method: 'DELETE', query: { id: deleteButton.dataset.deleteAccount } }); toast('Účet byl smazán.'); await refreshAccounts(); } catch (error) { toast(error.message, 'error'); }
    }
  });
  $('#auditTable').addEventListener('click', async event => {
    const detail = event.target.closest('[data-show-audit]');
    if (!detail) return;
    try {
      const audit = await api('audit', { query: { id: detail.dataset.showAudit } });
      showAuditResult(audit);
    } catch (error) { toast(error.message, 'error'); }
  });
  $('#tradeTable').addEventListener('click', async event => {
    const edit = event.target.closest('[data-edit-trade]');
    if (edit) editTrade(edit.dataset.editTrade);
    const remove = event.target.closest('[data-delete-trade]');
    if (remove && confirm('Smazat tento obchod a jeho screenshoty?')) {
      try { await api('trade', { method: 'DELETE', query: { id: remove.dataset.deleteTrade } }); toast('Obchod byl smazán.'); await Promise.all([refreshTrades(), refreshDashboard(), refreshPlans()]); } catch (error) { toast(error.message, 'error'); }
    }
  });

  $('#closeLightbox').addEventListener('click', () => $('#lightbox').close());
  $('#lightbox').addEventListener('click', event => { if (event.target === $('#lightbox')) $('#lightbox').close(); });
}

function openLightbox(id, source = state.currentScreenshots) {
  const screenshot = source.find(item => item.id === id);
  if (!screenshot) return;
  $('#lightboxImage').src = `file.php?id=${encodeURIComponent(id)}`;
  $('#lightboxCaption').textContent = screenshot.caption || screenshot.original_name;
  $('#lightbox').showModal();
}

async function init() {
  bindEvents();
  bindWallEvents();
  bindMemberEvents();
  bindDnEvents();
  bindSettingsEvents();
  applyHues(document);
  await loadWorkspace();
  resetPlan({ type: 'daily' });
  // Pole otevření se odemykají podle času, i když necháš náhled otevřený.
  setInterval(() => {
    if ($('#view-plan').classList.contains('is-active')) { applySessionLocks(); updatePlanProgress(); }
  }, 30000);
  await checkHealth();
  await refreshShares();
  await Promise.all([refreshDashboard(), refreshPlans(), refreshTrades(), refreshStrategies(), refreshAccounts(), refreshMe()]);
  if (state.workspace && !prefs().onboarded) openOnboarding();
}

init();
