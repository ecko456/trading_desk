/* Obchodní plán: formulář po částech, účty a strategie z deníku, verze a PDF. */

const tp = { plan: null, versions: [], options: null, data: null, dirty: false, loaded: false, saving: false };

const TP_DAY_KEYS = [1, 2, 3, 4, 5, 6, 7];
const TP_SECTIONS = ['goals', 'time', 'risk', 'bias', 'zones', 'strategies', 'routine', 'psychology', 'review', 'commitment'];

function tpEmptyData() {
  return { markets: [], windows: [], accounts: [], strategies: [], bias: { timeframes: [] }, zones: { sources: [] } };
}

function tpGet(object, path) {
  return path.split('.').reduce((value, key) => (value && typeof value === 'object' ? value[key] : undefined), object);
}

function tpSet(object, path, value) {
  const keys = path.split('.');
  const last = keys.pop();
  const target = keys.reduce((value, key) => {
    if (!value[key] || typeof value[key] !== 'object') value[key] = {};
    return value[key];
  }, object);
  target[last] = value;
}

function tpFilled(value) {
  if (Array.isArray(value)) return value.length > 0;
  return value !== null && value !== undefined && String(value).trim() !== '';
}

function tpOptions(group, selected) {
  const options = tp.options?.[group] || {};
  return Object.entries(options).map(([key, label]) => `<option value="${escapeHtml(key)}"${String(selected) === key ? ' selected' : ''}>${escapeHtml(label)}</option>`).join('');
}

function tpNumber(value) {
  return value === null || value === undefined ? '' : String(value);
}

/* ---------------------------------------------------------------- načtení a vyplnění */

async function refreshTradePlan({ force = false } = {}) {
  if (!$('#tpForm')) return;
  // Rozepsané změny se při přepnutí záložky nepřepíšou; jen se překreslí účty a strategie.
  if (tp.loaded && tp.dirty && !force) {
    renderTpLists();
    return;
  }
  try {
    const result = await api('trading_plan');
    tp.options = result.options;
    tp.versions = result.versions || [];
    tp.plan = result.plan;
    fillTradePlan(result.plan);
    tp.loaded = true;
  } catch (error) { toast(error.message, 'error'); }
}

function fillTradePlan(plan) {
  tp.plan = plan;
  tp.data = structuredClone(plan?.data || tpEmptyData());
  ['markets', 'windows', 'accounts', 'strategies'].forEach(key => { if (!Array.isArray(tp.data[key])) tp.data[key] = []; });
  tp.data.bias = tp.data.bias || { timeframes: [] };
  tp.data.zones = tp.data.zones || { sources: [] };
  $('#tpTitle').value = plan?.title || 'Obchodní plán';
  $('#tpValidFrom').value = plan?.valid_from || '';
  $('#tpStatus').value = plan?.status === 'active' ? 'active' : 'draft';
  const archived = plan?.status === 'archived';
  $('#tpVersion').textContent = plan ? `Verze ${plan.version}${archived ? ' · archiv' : ''}` : 'Nový plán';
  $('#tpNewVersion').hidden = !plan;
  tpFillInputs();
  if (!tp.data.commitment?.signature && !plan) {
    $('[data-tp="commitment.signature"]').value = document.body.dataset.userName || '';
  }
  tp.dirty = false;
  renderTpLists();
  renderTpVersions();
  updateTpProgress();
}

function tpFillInputs() {
  $$('[data-tp]', $('#tpForm')).forEach(input => {
    const value = tpGet(tp.data, input.dataset.tp);
    input.value = value === null || value === undefined ? '' : value;
    // Výběr bez uložené hodnoty ukáže první možnost (výchozí styl), ne prázdné pole.
    if (input.tagName === 'SELECT' && input.selectedIndex < 0) input.selectedIndex = 0;
  });
}

function renderTpLists() {
  if (!tp.data) return;
  renderTpMarkets();
  renderTpChips('#tpTimeframes', 'timeframes', 'bias.timeframes');
  renderTpChips('#tpZoneSources', 'zone_sources', 'zones.sources');
  renderTpWindows();
  renderTpAccounts();
  renderTpStrategies();
}

/** Účty nebo strategie se změnily jinde (nový účet, nová strategie): překreslit seznamy. */
function tradePlanSourcesChanged() {
  if (tp.data && $('#view-tradeplan')?.classList.contains('is-active')) {
    renderTpAccounts();
    renderTpStrategies();
  }
}

function markTpDirty() {
  tp.dirty = true;
  $('#tpSave').textContent = 'Uložit plán *';
  updateTpProgress();
}

/* ---------------------------------------------------------------- štítky */

function renderTpMarkets() {
  const known = (typeof marketList === 'function' ? marketList() : []).map(item => item.symbol);
  const all = [...new Set([...known, ...tp.data.markets])];
  $('#tpMarkets').innerHTML = all.map(symbol => `<button class="chip${tp.data.markets.includes(symbol) ? ' is-on' : ''}" type="button" data-tp-market="${escapeHtml(symbol)}">${escapeHtml(symbol)}</button>`).join('')
    + '<input class="tp-chip-input" id="tpMarketInput" maxlength="16" placeholder="Jiný trh + Enter" aria-label="Přidat trh">';
}

function renderTpChips(selector, group, path) {
  const selected = tpGet(tp.data, path) || [];
  $(selector).innerHTML = Object.entries(tp.options?.[group] || {}).map(([key, label]) =>
    `<button class="chip${selected.includes(key) ? ' is-on' : ''}" type="button" data-tp-chip="${escapeHtml(path)}" data-value="${escapeHtml(key)}">${escapeHtml(label)}</button>`).join('');
}

/* ---------------------------------------------------------------- časová okna */

function renderTpWindows() {
  const days = tp.options?.days || {};
  $('#tpWindows').innerHTML = tp.data.windows.map((window, index) => `<div class="tp-window is-${escapeHtml(window.kind || 'trade')}" data-tp-window="${index}">
      <label class="tp-window-name">Název<input data-window-field="name" maxlength="60" value="${escapeHtml(window.name || '')}" placeholder="Otevření NY (RTH)"></label>
      <label>Od<input type="time" data-window-field="from" value="${escapeHtml(window.from || '')}"></label>
      <label>Do<input type="time" data-window-field="to" value="${escapeHtml(window.to || '')}"></label>
      <label>Režim<select data-window-field="kind">${tpOptions('window_kinds', window.kind || 'trade')}</select></label>
      <div class="tp-days" role="group" aria-label="Dny">${TP_DAY_KEYS.map(day => `<button type="button" class="${(window.days || []).includes(day) ? 'is-on' : ''}" data-window-day="${day}">${escapeHtml(days[day] || day)}</button>`).join('')}</div>
      <label class="tp-window-note">Poznámka<input data-window-field="note" maxlength="200" value="${escapeHtml(window.note || '')}"></label>
      <button class="icon-button tp-remove" type="button" data-window-remove aria-label="Odebrat okno">×</button>
    </div>`).join('') || '<p class="tp-empty">Zatím žádné okno. Přidej aspoň hlavní okno, kdy obchoduješ.</p>';
}

/* ---------------------------------------------------------------- účty */

function tpAccountEntry(id) {
  return tp.data.accounts.find(item => Number(item.account_id) === Number(id));
}

function renderTpAccounts() {
  const accounts = state.accounts || [];
  if (!accounts.length) {
    $('#tpAccounts').innerHTML = '<p class="tp-empty">V deníku zatím není žádný účet. Přidej ho tlačítkem Nový účet.</p>';
    return;
  }
  $('#tpAccounts').innerHTML = accounts.map(account => {
    const entry = tpAccountEntry(account.id);
    const on = Boolean(entry);
    const currency = account.currency || 'USD';
    const value = key => escapeHtml(tpNumber(entry?.[key]));
    return `<article class="tp-item${on ? ' is-on' : ''}" data-tp-account="${account.id}">
      <header class="tp-item-head">
        <label class="tp-toggle"><input type="checkbox" data-account-toggle${on ? ' checked' : ''}><span><strong>${escapeHtml(account.name)}</strong><small>${escapeHtml([account.broker, currency, account.broker_link ? 'cTrader' : ''].filter(Boolean).join(' · '))}</small></span></label>
        <span class="badge${on ? ' badge-gold' : ''}">${on ? 'V plánu' : 'Mimo plán'}</span>
      </header>
      ${on ? `<div class="field-grid four tp-item-body">
        <label>Typ účtu<select data-account-field="role">${tpOptions('account_roles', entry.role)}</select></label>
        <label>Risk na obchod (${escapeHtml(currency)})<input type="number" min="0" step="any" data-account-field="risk_per_trade" value="${value('risk_per_trade')}"></label>
        <label>Denní limit ztráty<input type="number" min="0" step="any" data-account-field="max_daily_loss" value="${value('max_daily_loss')}"></label>
        <label>Max. obchodů denně<input type="number" min="0" step="1" data-account-field="max_trades_day" value="${value('max_trades_day')}"></label>
        <label>Max. drawdown<input type="number" min="0" step="any" data-account-field="max_drawdown" value="${value('max_drawdown')}"></label>
        <label>Typ drawdownu<select data-account-field="drawdown_type">${tpOptions('drawdown', entry.drawdown_type)}</select></label>
        <label class="span-2">Cíl zisku<input type="number" min="0" step="any" data-account-field="profit_target" value="${value('profit_target')}"></label>
        <label class="span-4">Pravidla účtu <small>třeba pravidla prop firmy</small><textarea rows="2" data-account-field="rules">${escapeHtml(entry.rules || '')}</textarea></label>
      </div>` : ''}
    </article>`;
  }).join('');
}

/* ---------------------------------------------------------------- strategie */

function tpStrategyEntry(id) {
  return tp.data.strategies.find(item => Number(item.strategy_id) === Number(id));
}

function renderTpStrategies() {
  const strategies = state.strategies || [];
  if (!strategies.length) {
    $('#tpStrategies').innerHTML = '<p class="tp-empty">V deníku zatím není žádná strategie. Přidej ji tlačítkem Nová strategie.</p>';
    return;
  }
  const planAccounts = tp.data.accounts.map(item => (state.accounts || []).find(account => account.id === Number(item.account_id))).filter(Boolean);
  $('#tpStrategies').innerHTML = strategies.map(strategy => {
    const entry = tpStrategyEntry(strategy.id);
    const on = Boolean(entry);
    const thumb = strategy.cover_id
      ? `<span class="tp-thumb"><img src="file.php?id=${encodeURIComponent(strategy.cover_id)}" alt="" loading="lazy"></span>`
      : `<span class="tp-thumb is-empty">${escapeHtml(strategyInitials(strategy.name))}</span>`;
    const meta = strategyMeta(strategy);
    const text = (key, label, rows = 2, extra = '') => `<label class="${extra}">${label}<textarea rows="${rows}" data-strategy-field="${key}">${escapeHtml(entry?.[key] || '')}</textarea></label>`;
    const chosen = (entry?.accounts || []).map(Number);
    return `<article class="tp-item tp-strategy${on ? ' is-on' : ''}" data-tp-strategy="${strategy.id}">
      <header class="tp-item-head">
        <label class="tp-toggle">${thumb}<input type="checkbox" data-strategy-toggle${on ? ' checked' : ''}><span><strong>${escapeHtml(strategy.name)}</strong><small>${escapeHtml(meta || 'Bez timeframu')}</small></span></label>
        <span class="badge${on ? ' badge-gold' : ''}">${on ? 'V plánu' : 'Mimo plán'}</span>
      </header>
      ${on ? `<div class="tp-item-body">
        <div class="field-grid four">
          <label>Kontext trhu<select data-strategy-field="context">${tpOptions('contexts', entry.context)}</select></label>
          <label>Vztah k biasu<select data-strategy-field="bias_rule">${tpOptions('bias_rules', entry.bias_rule)}</select></label>
          <label>Zóny<select data-strategy-field="zone_priority">${tpOptions('zone_priority', entry.zone_priority)}</select></label>
          <div class="tp-pair"><label>Min. RR<input type="number" min="0" step="0.1" data-strategy-field="min_rr" value="${escapeHtml(tpNumber(entry.min_rr))}"></label><label>Pokusů denně<input type="number" min="0" step="1" data-strategy-field="max_attempts" value="${escapeHtml(tpNumber(entry.max_attempts))}"></label></div>
        </div>
        <div class="field-grid two tp-gap">
          ${text('when', 'Kdy ji obchoduji <small>okno, seance</small>', 2)}
          ${text('conditions', 'Za jakých podmínek', 2)}
          ${text('entry', 'Vstup', 2)}
          ${text('stop', 'Stop loss', 2)}
          ${text('targets', 'Cíle', 2)}
          ${text('management', 'Řízení pozice', 2)}
          ${text('skip', 'Kdy ji neobchoduji', 2, 'span-2 tp-skip')}
        </div>
        ${planAccounts.length ? `<p class="subhead tp-gap">Na kterých účtech <small>nic nevybrané = všechny účty v plánu</small></p>
        <div class="chip-row">${planAccounts.map(account => `<button class="chip${chosen.includes(account.id) ? ' is-on' : ''}" type="button" data-strategy-account="${account.id}">${escapeHtml(account.name)}</button>`).join('')}</div>` : ''}
      </div>` : ''}
    </article>`;
  }).join('');
}

/* ---------------------------------------------------------------- průběh vyplnění */

function tpSectionState(key, data) {
  const any = paths => paths.some(path => tpFilled(tpGet(data, path)));
  const checks = {
    goals: [['mission', 'goals_process'], ['mission', 'goals_process', 'goals_outcome', 'time_budget']],
    time: [null, ['markets', 'windows', 'news_rule', 'no_trade_days', 'markets_note']],
    risk: [null, ['accounts', 'risk.daily_stop_r', 'risk.max_trades_day', 'risk.sizing', 'risk.scale_down']],
    bias: [['bias.process', 'bias.long_when'], ['bias.timeframes', 'bias.process', 'bias.long_when', 'bias.short_when', 'bias.balance_when', 'bias.invalidation']],
    zones: [null, ['zones.sources', 'zones.rules', 'zones.priority_a', 'zones.invalidation']],
    strategies: [['strategies'], ['strategies']],
    routine: [['routine.before', 'routine.during'], ['routine.before', 'routine.during', 'routine.after']],
    psychology: [['psychology.bad_day', 'psychology.stop_rules', 'psychology.triggers'], ['psychology.bad_day', 'psychology.stop_rules', 'psychology.triggers']],
    review: [['review.weekly', 'review.change_rules'], ['review.daily', 'review.weekly', 'review.monthly', 'review.metrics', 'review.change_rules']],
    commitment: [['commitment.statement'], ['commitment.statement']],
  };
  let done;
  if (key === 'time') done = data.markets.length > 0 && data.windows.length > 0;
  else if (key === 'risk') done = data.accounts.length > 0 && tpFilled(data.risk?.daily_stop_r);
  else if (key === 'zones') done = data.zones.sources.length > 0 && tpFilled(data.zones.rules);
  else done = any(checks[key][0]);
  return done ? 'done' : any(checks[key][1]) ? 'partial' : 'empty';
}

function updateTpProgress() {
  if (!tp.data) return;
  let score = 0;
  const data = collectTradePlan().data;
  TP_SECTIONS.forEach(key => {
    const status = tpSectionState(key, data);
    score += status === 'done' ? 1 : status === 'partial' ? .5 : 0;
    const button = $(`#tpSteps [data-tp-step="${key}"]`);
    button?.classList.toggle('is-done', status === 'done');
    button?.classList.toggle('is-partial', status === 'partial');
  });
  const percent = Math.round(score / TP_SECTIONS.length * 100);
  $('#tpProgressBar').style.width = `${percent}%`;
  $('#tpProgressText').textContent = `${percent} %`;
}

/* ---------------------------------------------------------------- historie verzí */

function renderTpVersions() {
  const statuses = tp.options?.statuses || {};
  $('#tpVersions').innerHTML = tp.versions.map(version => `<div class="tp-version-row${tp.plan?.id === version.id ? ' is-current' : ''}">
      <div><strong>Verze ${version.version}</strong><small>${escapeHtml(statuses[version.status] || version.status)}${version.valid_from ? ` · od ${escapeHtml(version.valid_from)}` : ''}</small></div>
      <span><button class="mini-button" type="button" data-tp-pdf="${version.id}">PDF</button>${version.status === 'archived' ? `<button class="mini-button danger" type="button" data-tp-delete="${version.id}">Smazat</button>` : ''}</span>
    </div>`).join('') || '<p class="tp-empty">Plán zatím není uložený. Po uložení tu uvidíš verze a jejich PDF.</p>';
}

/* ---------------------------------------------------------------- uložení a akce */

function collectTradePlan() {
  const data = structuredClone(tp.data || tpEmptyData());
  $$('[data-tp]', $('#tpForm')).forEach(input => tpSet(data, input.dataset.tp, input.value));
  return {
    id: tp.plan?.status === 'archived' ? null : tp.plan?.id || null,
    title: $('#tpTitle').value.trim(),
    status: $('#tpStatus').value,
    valid_from: $('#tpValidFrom').value,
    data,
  };
}

async function saveTradePlan({ quiet = false } = {}) {
  if (tp.saving) return tp.plan;
  tp.saving = true;
  try {
    const result = await api('trading_plan', { method: 'POST', body: collectTradePlan() });
    tp.versions = result.versions || [];
    fillTradePlan(result.plan);
    $('#tpSave').textContent = 'Uložit plán';
    if (!quiet) toast('Obchodní plán je uložený.');
    return result.plan;
  } finally {
    tp.saving = false;
  }
}

/** Návrh doplní jen prázdná pole a chybějící položky seznamů; nic vyplněného nepřepíše. */
async function insertTradePlanTemplate() {
  try {
    const { data } = await api('trading_plan_template');
    const fill = (target, source, prefix = '') => {
      Object.entries(source).forEach(([key, value]) => {
        const path = prefix ? `${prefix}.${key}` : key;
        if (['windows', 'accounts', 'strategies'].includes(path)) return;
        if (value && typeof value === 'object' && !Array.isArray(value)) {
          fill(target, value, path);
          return;
        }
        if (!tpFilled(tpGet(target, path)) && tpFilled(value)) tpSet(target, path, value);
      });
    };
    const current = collectTradePlan().data;
    fill(current, data);
    if (!current.windows.length) current.windows = data.windows;
    data.accounts.forEach(item => { if (!current.accounts.some(entry => Number(entry.account_id) === item.account_id)) current.accounts.push(item); });
    data.strategies.forEach(item => { if (!current.strategies.some(entry => Number(entry.strategy_id) === item.strategy_id)) current.strategies.push(item); });
    tp.data = current;
    tpFillInputs();
    if (!$('#tpValidFrom').value) $('#tpValidFrom').value = today();
    renderTpLists();
    markTpDirty();
    toast('Návrh je doplněný do prázdných polí. Uprav ho podle sebe a ulož.');
  } catch (error) { toast(error.message, 'error'); }
}

async function newTradePlanVersion() {
  if (!tp.plan) return;
  if (!confirm('Založit novou verzi plánu? Současná verze se uloží do historie (i s PDF) a dál budeš upravovat kopii.')) return;
  try {
    if (tp.dirty) await saveTradePlan({ quiet: true });
    const result = await api('trading_plan_version', { method: 'POST', body: { id: tp.plan.id } });
    tp.versions = result.versions || [];
    fillTradePlan(result.plan);
    toast(`Vznikla verze ${result.plan.version}. Předchozí je v historii.`);
  } catch (error) { toast(error.message, 'error'); }
}

function downloadTradePlanPdf(id) {
  const link = document.createElement('a');
  link.href = `pdf.php?trading_plan=${encodeURIComponent(id)}`;
  link.setAttribute('download', '');
  document.body.appendChild(link);
  link.click();
  link.remove();
}

async function exportTradePlanPdf() {
  const button = $('#tpPdf');
  const label = button.innerHTML;
  button.disabled = true;
  button.textContent = 'Připravuji PDF…';
  try {
    const plan = tp.dirty || !tp.plan ? await saveTradePlan({ quiet: true }) : tp.plan;
    downloadTradePlanPdf(plan.id);
    toast('Plán je uložený a PDF se stahuje.');
  } catch (error) { toast(error.message, 'error'); } finally {
    button.disabled = false;
    button.innerHTML = label;
  }
}

/* ---------------------------------------------------------------- události */

function bindTradePlanEvents() {
  const form = $('#tpForm');
  if (!form) return;
  form.addEventListener('submit', async event => {
    event.preventDefault();
    try { await saveTradePlan(); } catch (error) { toast(error.message, 'error'); }
  });
  form.addEventListener('input', event => {
    const target = event.target;
    if (target.id === 'tpMarketInput') return;
    const window = target.closest('[data-tp-window]');
    if (window && target.dataset.windowField) {
      tp.data.windows[Number(window.dataset.tpWindow)][target.dataset.windowField] = target.value;
      if (target.dataset.windowField === 'kind') window.className = `tp-window is-${target.value}`;
    }
    const account = target.closest('[data-tp-account]');
    if (account && target.dataset.accountField) tpAccountEntry(account.dataset.tpAccount)[target.dataset.accountField] = target.value;
    const strategy = target.closest('[data-tp-strategy]');
    if (strategy && target.dataset.strategyField) tpStrategyEntry(strategy.dataset.tpStrategy)[target.dataset.strategyField] = target.value;
    markTpDirty();
  });
  form.addEventListener('change', event => {
    const target = event.target;
    if (target.matches('[data-account-toggle]')) {
      const id = Number(target.closest('[data-tp-account]').dataset.tpAccount);
      const account = (state.accounts || []).find(item => item.id === id);
      if (target.checked) {
        const daily = account?.daily_risk ? Number(account.daily_risk) : null;
        tp.data.accounts.push({ account_id: id, role: 'personal', risk_per_trade: daily ? Math.round(daily / 2 * 100) / 100 : null, max_daily_loss: daily, max_trades_day: null, max_drawdown: null, drawdown_type: 'static', profit_target: null, rules: '' });
      } else {
        tp.data.accounts = tp.data.accounts.filter(item => Number(item.account_id) !== id);
        tp.data.strategies.forEach(item => { item.accounts = (item.accounts || []).filter(accountId => Number(accountId) !== id); });
      }
      renderTpAccounts();
      renderTpStrategies();
      markTpDirty();
    }
    if (target.matches('[data-strategy-toggle]')) {
      const id = Number(target.closest('[data-tp-strategy]').dataset.tpStrategy);
      if (target.checked) {
        tp.data.strategies.push({ strategy_id: id, context: 'any', bias_rule: 'with', zone_priority: 'AB', accounts: [], min_rr: null, max_attempts: null, when: '', conditions: '', entry: '', stop: '', targets: '', management: '', skip: '' });
      } else {
        tp.data.strategies = tp.data.strategies.filter(item => Number(item.strategy_id) !== id);
      }
      renderTpStrategies();
      markTpDirty();
    }
  });
  form.addEventListener('click', event => {
    const market = event.target.closest('[data-tp-market]');
    if (market) {
      const symbol = market.dataset.tpMarket;
      tp.data.markets = tp.data.markets.includes(symbol) ? tp.data.markets.filter(item => item !== symbol) : [...tp.data.markets, symbol];
      renderTpMarkets();
      markTpDirty();
    }
    const chip = event.target.closest('[data-tp-chip]');
    if (chip) {
      const list = tpGet(tp.data, chip.dataset.tpChip) || [];
      const value = chip.dataset.value;
      tpSet(tp.data, chip.dataset.tpChip, list.includes(value) ? list.filter(item => item !== value) : [...list, value]);
      chip.classList.toggle('is-on');
      markTpDirty();
    }
    const day = event.target.closest('[data-window-day]');
    if (day) {
      const window = tp.data.windows[Number(day.closest('[data-tp-window]').dataset.tpWindow)];
      const value = Number(day.dataset.windowDay);
      window.days = (window.days || []).includes(value) ? window.days.filter(item => item !== value) : [...(window.days || []), value].sort();
      day.classList.toggle('is-on');
      markTpDirty();
    }
    if (event.target.closest('[data-window-remove]')) {
      tp.data.windows.splice(Number(event.target.closest('[data-tp-window]').dataset.tpWindow), 1);
      renderTpWindows();
      markTpDirty();
    }
    const strategyAccount = event.target.closest('[data-strategy-account]');
    if (strategyAccount) {
      const entry = tpStrategyEntry(strategyAccount.closest('[data-tp-strategy]').dataset.tpStrategy);
      const id = Number(strategyAccount.dataset.strategyAccount);
      entry.accounts = (entry.accounts || []).map(Number).includes(id) ? entry.accounts.filter(item => Number(item) !== id) : [...(entry.accounts || []), id];
      strategyAccount.classList.toggle('is-on');
      markTpDirty();
    }
    if (event.target.closest('[data-tp-new-account]')) openAccountDialog();
    if (event.target.closest('[data-tp-new-strategy]')) openStrategyDialog();
    const pdf = event.target.closest('[data-tp-pdf]');
    if (pdf) downloadTradePlanPdf(pdf.dataset.tpPdf);
    const remove = event.target.closest('[data-tp-delete]');
    if (remove && confirm('Smazat tuhle archivovanou verzi plánu?')) {
      api('trading_plan', { method: 'DELETE', query: { id: remove.dataset.tpDelete } })
        .then(result => { tp.versions = result.versions || []; renderTpVersions(); toast('Verze je smazaná.'); })
        .catch(error => toast(error.message, 'error'));
    }
  });
  form.addEventListener('keydown', event => {
    if (event.target.id === 'tpMarketInput' && event.key === 'Enter') {
      event.preventDefault();
      const symbol = event.target.value.trim().toUpperCase();
      if (/^[A-Z0-9][A-Z0-9._/-]{0,15}$/.test(symbol) && !tp.data.markets.includes(symbol)) {
        tp.data.markets.push(symbol);
        renderTpMarkets();
        markTpDirty();
        $('#tpMarketInput').focus();
      }
    }
  });
  $('#tpAddWindow').addEventListener('click', () => {
    tp.data.windows.push({ name: '', from: '', to: '', days: [1, 2, 3, 4, 5], kind: 'trade', note: '' });
    renderTpWindows();
    markTpDirty();
    $$('#tpWindows [data-window-field="name"]').at(-1)?.focus();
  });
  $('#tpSteps').addEventListener('click', event => {
    const button = event.target.closest('[data-tp-step]');
    if (button) $(`#tp-${button.dataset.tpStep}`).scrollIntoView({ behavior: 'smooth', block: 'start' });
  });
  $('#tpTemplate').addEventListener('click', insertTradePlanTemplate);
  $('#tpNewVersion').addEventListener('click', newTradePlanVersion);
  $('#tpPdf').addEventListener('click', exportTradePlanPdf);
  window.addEventListener('beforeunload', event => {
    if (tp.dirty) {
      event.preventDefault();
      event.returnValue = '';
    }
  });
}
