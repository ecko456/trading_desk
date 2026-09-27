'use strict';

/*
 * Nastavení prostředí a průvodce prvním spuštěním.
 * Načítá se před app.js a jeho pomocníky ($, api, toast, prefs…) používá až při volání.
 */

const settingsState = {
  tab: 'method',
  onboarding: null,
  saving: null,
};

const METHOD_LABELS = { mp: 'Market Profile', dn: 'DiNapoli', both: 'Market Profile i DiNapoli' };
const METHOD_TAGS = { mp: 'MP', dn: 'DiNapoli', core: '' };

function registry() {
  return state.workspace?.registry || { plan: [], trade: [], modules: [], presets: { mp: [], dn: [], both: [] }, kinds: {}, default_markets: [] };
}

async function saveWorkspace(patch, message = 'Uloženo.') {
  if (!state.workspace) await loadWorkspace();
  const next = { ...prefs(), ...patch };
  const result = await api('workspace', { method: 'POST', body: next });
  state.workspace.prefs = result.prefs;
  applyWorkspace();
  if (message) toast(message);
  return result.prefs;
}

/** Prvky náhledu po změně metodiky: přepočítá MP a DiNapoli části, ostatní volby tradera nechá. */
function planHiddenForMethod(method) {
  const methodKeys = registry().plan.filter(item => item.method !== 'core').map(item => item.key);
  const kept = (prefs().hidden?.plan || []).filter(key => !methodKeys.includes(key));
  return [...new Set([...(registry().presets?.[method] || []), ...kept])];
}

function toggleRow(group, item, checked, extra = '') {
  return `<label class="toggle-row">
    <span class="switch"><input type="checkbox" data-toggle-group="${group}" data-key="${escapeHtml(item.key)}"${checked ? ' checked' : ''}><i></i></span>
    <span><strong>${escapeHtml(item.label)}${extra}</strong><small>${escapeHtml(item.hint)}</small></span>
  </label>`;
}

/* ---------------------------------------------------------------- vykreslení */

function renderSettings() {
  $$('#settingsTabs [data-settings-tab]').forEach(button => button.classList.toggle('is-active', button.dataset.settingsTab === settingsState.tab));
  $$('[data-settings-panel]').forEach(panel => panel.classList.toggle('is-active', panel.dataset.settingsPanel === settingsState.tab));
  const current = prefs();

  $$('#methodCards [data-method]').forEach(card => card.setAttribute('aria-pressed', String(card.dataset.method === current.method)));
  renderPlanPreview();

  const groups = [];
  registry().plan.forEach(item => {
    let group = groups.find(entry => entry.name === item.group);
    if (!group) groups.push(group = { name: item.group, items: [] });
    group.items.push(item);
  });
  $('#planElementToggles').innerHTML = groups.map(group => `<section class="toggle-group">
    <header><strong>${escapeHtml(group.name)}</strong>${group.items.some(item => item.method !== 'core') ? `<span class="badge${group.items[0].method === 'dn' ? ' badge-violet' : ' badge-gold'}">${METHOD_TAGS[group.items.find(item => item.method !== 'core').method]}</span>` : ''}</header>
    ${group.items.map(item => toggleRow('plan', item, !current.hidden.plan.includes(item.key), item.method !== 'core' && group.items.some(other => other.method === 'core') ? ` <em class="method-tag">${METHOD_TAGS[item.method]}</em>` : '')).join('')}
  </section>`).join('');

  $('#tokenList').innerHTML = (current.tokens_extra || []).map(token => `<span class="chip is-on token-chip">${escapeHtml(token)}<button type="button" data-remove-token="${escapeHtml(token)}" aria-label="Odebrat štítek ${escapeHtml(token)}">×</button></span>`).join('') || '<span class="muted">Zatím žádné vlastní štítky.</span>';

  $('#tradeElementToggles').innerHTML = `<section class="toggle-group">${registry().trade.map(item => toggleRow('trade', item, !current.hidden.trade.includes(item.key))).join('')}</section>`;

  const defaults = current.defaults || {};
  const form = $('#defaultsForm');
  $('#defaultMarket').innerHTML = `<option value="">—</option>${marketList().map(item => `<option value="${escapeHtml(item.symbol)}">${escapeHtml(item.symbol)}</option>`).join('')}`;
  $('#defaultAccount').innerHTML = `<option value="">—</option>${state.accounts.map(account => `<option value="${account.id}">${escapeHtml(account.name)}</option>`).join('')}`;
  form.elements.default_market.value = defaults.market || '';
  form.elements.session.value = defaults.session || 'Intraday';
  form.elements.risk.value = defaults.risk ?? '';
  form.elements.fees.value = defaults.fees ?? '';
  form.elements.account_id.value = defaults.account_id ? String(defaults.account_id) : '';

  renderFieldLists();
  renderMarketRows(marketList());
  $('#moduleToggles').innerHTML = `<section class="toggle-group">${registry().modules.map(item => toggleRow('modules', item, !current.hidden.modules.includes(item.key))).join('')}</section>`;
}

function renderPlanPreview() {
  const hidden = prefs().hidden?.plan || [];
  const all = registry().plan;
  const on = all.filter(item => !hidden.includes(item.key));
  const groups = [...new Set(on.map(item => item.group))];
  $('#planPreview').innerHTML = `<p class="subhead">Tvůj náhled trhu<small>${on.length} z ${all.length} prvků zapnuto · metodika ${escapeHtml(METHOD_LABELS[prefs().method])}</small></p>
    <div class="preview-steps">${groups.map((group, index) => `<span><i>${index + 1}</i>${escapeHtml(group)}</span>`).join('')}</div>`;
}

function kindLabel(kind) {
  return registry().kinds?.[kind] || kind;
}

function renderFieldLists() {
  const fields = state.workspace?.fields || [];
  ['trade', 'plan'].forEach(scope => {
    const list = fields.filter(field => field.scope === scope);
    $(`#${scope}FieldList`).innerHTML = list.map(field => `<div class="field-item${field.archived ? ' is-archived' : ''}">
      <div><strong>${escapeHtml(field.label)}</strong><small>${escapeHtml(kindLabel(field.kind))}${field.kind === 'select' ? `: ${escapeHtml(field.options.join(', '))}` : ''}${field.in_table ? ' · sloupec v deníku' : ''}${field.archived ? ' · archivované' : ''}</small></div>
      <div class="field-actions">
        ${field.archived ? '' : `<button class="icon-button small" type="button" data-field-move="-1" data-field="${field.id}" aria-label="Posunout výš">↑</button><button class="icon-button small" type="button" data-field-move="1" data-field="${field.id}" aria-label="Posunout níž">↓</button>`}
        <button class="mini-button" type="button" data-field-edit="${field.id}">Upravit</button>
        <button class="mini-button" type="button" data-field-archive="${field.id}">${field.archived ? 'Obnovit' : 'Archivovat'}</button>
        <button class="mini-button danger" type="button" data-field-delete="${field.id}">Smazat</button>
      </div>
    </div>`).join('') || `<div class="empty-state compact">${scope === 'trade' ? 'Žádné vlastní pole u obchodu.' : 'Žádné vlastní pole v náhledu.'}</div>`;
  });
}

function renderMarketRows(markets) {
  $('#marketRows').innerHTML = `<div class="market-row market-head"><span>Symbol</span><span>Hodnota bodu ($)</span><span>RTH open (New York)</span><span></span></div>${markets.map(market => `<div class="market-row">
    <input name="symbol" maxlength="12" value="${escapeHtml(market.symbol)}" aria-label="Symbol" autocapitalize="characters">
    <input name="point_value" type="number" min="0" step="any" value="${escapeHtml(market.point_value ?? '')}" placeholder="neznámá" aria-label="Hodnota bodu">
    <input name="rth" type="time" value="${escapeHtml(market.rth || '09:30')}" aria-label="RTH open">
    <button class="remove-row" type="button" data-remove-market>Odebrat</button>
  </div>`).join('')}`;
}

function resetFieldForm() {
  const form = $('#fieldForm');
  form.reset();
  form.elements.id.value = '';
  form.elements.scope.disabled = false;
  form.elements.kind.disabled = false;
  $('#fieldFormTitle').textContent = 'Nové pole';
  $('#fieldSubmit').lastChild.textContent = 'Přidat pole';
  $('#fieldCancel').hidden = true;
  syncFieldForm();
}

function syncFieldForm() {
  const form = $('#fieldForm');
  $('[data-field-options]', form).hidden = form.elements.kind.value !== 'select';
  $('[data-field-table]', form).hidden = form.elements.scope.value !== 'trade' || form.elements.kind.value === 'textarea';
}

function editField(field) {
  const form = $('#fieldForm');
  form.elements.id.value = field.id;
  form.elements.label.value = field.label;
  form.elements.scope.value = field.scope;
  form.elements.kind.value = field.kind;
  form.elements.options.value = field.options.join('\n');
  form.elements.help.value = field.help;
  form.elements.in_table.checked = field.in_table;
  // Typ a umístění se u existujícího pole nemění, aby zůstaly platné uložené hodnoty.
  form.elements.scope.disabled = true;
  form.elements.kind.disabled = true;
  $('#fieldFormTitle').textContent = `Úprava pole „${field.label}“`;
  $('#fieldSubmit').lastChild.textContent = 'Uložit pole';
  $('#fieldCancel').hidden = false;
  syncFieldForm();
  form.scrollIntoView({ behavior: 'smooth', block: 'center' });
  form.elements.label.focus({ preventScroll: true });
}

function fieldsChanged(fields) {
  state.workspace.fields = fields;
  renderFieldLists();
  applyWorkspace();
  if ($('#view-strategies').classList.contains('is-active')) refreshStrategyStats();
}

/* ---------------------------------------------------------------- průvodce */

function openOnboarding() {
  const current = prefs();
  const known = [...(registry().default_markets || [])];
  marketList().forEach(market => { if (!known.some(item => item.symbol === market.symbol)) known.push(market); });
  settingsState.onboarding = {
    page: 1,
    method: current.method,
    markets: known,
    selected: new Set(marketList().map(item => item.symbol)),
    hiddenModules: [...(current.hidden?.modules || [])],
    finished: false,
  };
  renderOnboarding();
  $('#onboardingDialog').showModal();
}

function renderOnboarding() {
  const flow = settingsState.onboarding;
  $$('[data-onboarding-page]').forEach(page => { page.hidden = Number(page.dataset.onboardingPage) !== flow.page; });
  $$('#onboardingSteps li').forEach((step, index) => {
    step.classList.toggle('is-active', index + 1 === flow.page);
    step.classList.toggle('is-done', index + 1 < flow.page);
  });
  $$('#onboardingMethods [data-method]').forEach(card => card.setAttribute('aria-pressed', String(card.dataset.method === flow.method)));
  $('#onboardingMarkets').innerHTML = flow.markets.map(market => `<button type="button" class="chip${flow.selected.has(market.symbol) ? ' is-on' : ''}" data-onboarding-market="${escapeHtml(market.symbol)}">${escapeHtml(market.symbol)}</button>`).join('');
  const select = $('#onboardingDefaultMarket');
  const previous = select.value || prefs().defaults?.market || '';
  const chosen = flow.markets.filter(market => flow.selected.has(market.symbol));
  select.innerHTML = `<option value="">—</option>${chosen.map(market => `<option value="${escapeHtml(market.symbol)}">${escapeHtml(market.symbol)}</option>`).join('')}`;
  select.value = chosen.some(market => market.symbol === previous) ? previous : (chosen[0]?.symbol || '');
  if (!$('#onboardingRisk').value && prefs().defaults?.risk) $('#onboardingRisk').value = prefs().defaults.risk;
  $('#onboardingModules').innerHTML = `<section class="toggle-group">${registry().modules.map(item => toggleRow('onboarding', item, !flow.hiddenModules.includes(item.key))).join('')}</section>`;
  $('#onboardingBack').hidden = flow.page === 1;
  $('#onboardingNext').textContent = flow.page === 3 ? 'Hotovo, jdeme obchodovat' : 'Pokračovat';
}

async function finishOnboarding() {
  const flow = settingsState.onboarding;
  const markets = flow.markets.filter(market => flow.selected.has(market.symbol));
  if (!markets.length) {
    flow.page = 2;
    renderOnboarding();
    toast('Vyber aspoň jeden trh.', 'error');
    return;
  }
  flow.finished = true;
  await saveWorkspace({
    method: flow.method,
    onboarded: true,
    markets,
    hidden: { ...prefs().hidden, plan: planHiddenForMethod(flow.method), modules: flow.hiddenModules },
    defaults: { ...prefs().defaults, market: $('#onboardingDefaultMarket').value, risk: $('#onboardingRisk').value || null },
  }, 'Desk je nastavený. Kdykoli ho změníš v Nastavení.');
  $('#onboardingDialog').close();
  resetPlan({ type: planType() });
}

/* ---------------------------------------------------------------- události */

function bindSettingsEvents() {
  // Odkazy „Upravit pole“ z náhledu a dialogu obchodu otevřou rovnou správnou část.
  document.addEventListener('click', event => {
    const link = event.target.closest('[data-open-view="settings"][data-settings-tab]');
    if (!link) return;
    settingsState.tab = link.dataset.settingsTab;
    link.closest('dialog')?.close();
  }, true);

  $('#settingsTabs').addEventListener('click', event => {
    const button = event.target.closest('[data-settings-tab]');
    if (!button) return;
    settingsState.tab = button.dataset.settingsTab;
    renderSettings();
  });

  $('#methodCards').addEventListener('click', async event => {
    const card = event.target.closest('[data-method]');
    if (!card || card.dataset.method === prefs().method) return;
    try {
      await saveWorkspace({ method: card.dataset.method, hidden: { ...prefs().hidden, plan: planHiddenForMethod(card.dataset.method) } }, `Metodika: ${METHOD_LABELS[card.dataset.method]}. Náhled je upravený.`);
      renderSettings();
    } catch (error) { toast(error.message, 'error'); }
  });

  $('#view-settings').addEventListener('change', async event => {
    const input = event.target.closest('[data-toggle-group]');
    if (!input || input.dataset.toggleGroup === 'onboarding') return;
    const group = input.dataset.toggleGroup;
    const hidden = new Set(prefs().hidden?.[group] || []);
    if (input.checked) hidden.delete(input.dataset.key);
    else hidden.add(input.dataset.key);
    try {
      await saveWorkspace({ hidden: { ...prefs().hidden, [group]: [...hidden] } }, input.checked ? 'Zapnuto.' : 'Skryto.');
      renderPlanPreview();
    } catch (error) {
      input.checked = !input.checked;
      toast(error.message, 'error');
    }
  });

  $('#resetPlanElements').addEventListener('click', async () => {
    try {
      await saveWorkspace({ hidden: { ...prefs().hidden, plan: registry().presets?.[prefs().method] || [] } }, 'Prvky náhledu odpovídají metodice.');
      renderSettings();
    } catch (error) { toast(error.message, 'error'); }
  });

  $('#tokenForm').addEventListener('submit', async event => {
    event.preventDefault();
    const token = event.currentTarget.elements.token.value.trim();
    if (!token) return;
    try {
      await saveWorkspace({ tokens_extra: [...(prefs().tokens_extra || []), token] }, `Štítek „${token}“ je v nabídce zón.`);
      event.target.reset();
      renderSettings();
    } catch (error) { toast(error.message, 'error'); }
  });
  $('#tokenList').addEventListener('click', async event => {
    const button = event.target.closest('[data-remove-token]');
    if (!button) return;
    try {
      await saveWorkspace({ tokens_extra: (prefs().tokens_extra || []).filter(token => token !== button.dataset.removeToken) }, 'Štítek je odebraný.');
      renderSettings();
    } catch (error) { toast(error.message, 'error'); }
  });

  $('#defaultsForm').addEventListener('submit', async event => {
    event.preventDefault();
    const data = formObject(event.currentTarget);
    try {
      await saveWorkspace({ defaults: { market: data.default_market, session: data.session, risk: data.risk || null, fees: data.fees || null, account_id: data.account_id ? Number(data.account_id) : null } }, 'Výchozí hodnoty jsou uložené.');
    } catch (error) { toast(error.message, 'error'); }
  });

  const fieldForm = $('#fieldForm');
  fieldForm.addEventListener('change', event => { if (event.target.matches('[name="kind"], [name="scope"]')) syncFieldForm(); });
  fieldForm.addEventListener('submit', async event => {
    event.preventDefault();
    const form = event.currentTarget;
    const body = {
      id: form.elements.id.value ? Number(form.elements.id.value) : null,
      label: form.elements.label.value,
      scope: form.elements.scope.value,
      kind: form.elements.kind.value,
      options: form.elements.options.value,
      help: form.elements.help.value,
      in_table: form.elements.in_table.checked,
    };
    if (body.id) body.archived = (state.workspace.fields.find(field => field.id === body.id) || {}).archived || false;
    try {
      const result = await api('custom_field', { method: 'POST', body });
      fieldsChanged(result.fields);
      toast(body.id ? 'Pole je upravené.' : `Pole „${result.field.label}“ je přidané${result.field.scope === 'trade' ? ' do zápisu obchodu' : ' do náhledu'}.`);
      resetFieldForm();
    } catch (error) { toast(error.message, 'error'); }
  });
  $('#fieldCancel').addEventListener('click', resetFieldForm);
  $('.field-ideas').addEventListener('click', event => {
    const chip = event.target.closest('[data-field-idea]');
    if (!chip) return;
    resetFieldForm();
    const idea = JSON.parse(chip.dataset.fieldIdea);
    fieldForm.elements.label.value = idea.label;
    fieldForm.elements.scope.value = idea.scope;
    fieldForm.elements.kind.value = idea.kind;
    fieldForm.elements.options.value = idea.options || '';
    fieldForm.elements.in_table.checked = idea.scope === 'trade' && idea.kind !== 'textarea';
    syncFieldForm();
    fieldForm.elements.label.focus();
  });
  $('[data-settings-panel="fields"]').addEventListener('click', async event => {
    const move = event.target.closest('[data-field-move]');
    const edit = event.target.closest('[data-field-edit]');
    const archive = event.target.closest('[data-field-archive]');
    const remove = event.target.closest('[data-field-delete]');
    if (!move && !edit && !archive && !remove) return;
    const fieldId = Number(move?.dataset.field || edit?.dataset.fieldEdit || archive?.dataset.fieldArchive || remove?.dataset.fieldDelete);
    const field = state.workspace?.fields?.find(item => item.id === fieldId);
    try {
      if (move) fieldsChanged((await api('custom_field_move', { method: 'POST', body: { id: Number(move.dataset.field), delta: Number(move.dataset.fieldMove) } })).fields);
      if (edit && field) editField(field);
      if (archive && field) {
        const result = await api('custom_field', { method: 'POST', body: { ...field, archived: !field.archived } });
        fieldsChanged(result.fields);
        toast(field.archived ? 'Pole je zpátky ve formulářích.' : 'Pole je archivované. Uložené hodnoty zůstaly.');
      }
      if (remove && field && confirm(`Smazat pole „${field.label}“? Hodnoty zapsané u obchodů a náhledů se přestanou zobrazovat. Když je chceš zachovat, zvol raději Archivovat.`)) {
        fieldsChanged((await api('custom_field', { method: 'DELETE', query: { id: field.id } })).fields);
        toast('Pole je smazané.');
      }
    } catch (error) { toast(error.message, 'error'); }
  });

  $('#addMarket').addEventListener('click', () => {
    const rows = $$('#marketRows .market-row:not(.market-head)').map(row => ({ symbol: $('[name="symbol"]', row).value, point_value: $('[name="point_value"]', row).value, rth: $('[name="rth"]', row).value }));
    renderMarketRows([...rows, { symbol: '', point_value: '', rth: '09:30' }]);
    $$('#marketRows [name="symbol"]').pop().focus();
  });
  $('#marketRows').addEventListener('click', event => {
    if (event.target.closest('[data-remove-market]')) event.target.closest('.market-row').remove();
  });
  $('#resetMarkets').addEventListener('click', () => renderMarketRows(registry().default_markets || []));
  $('#marketsForm').addEventListener('submit', async event => {
    event.preventDefault();
    const markets = $$('#marketRows .market-row:not(.market-head)').map(row => ({ symbol: $('[name="symbol"]', row).value, point_value: $('[name="point_value"]', row).value || null, rth: $('[name="rth"]', row).value })).filter(market => market.symbol.trim());
    if (!markets.length) { toast('Nech v seznamu aspoň jeden trh.', 'error'); return; }
    try {
      const saved = await saveWorkspace({ markets }, 'Trhy jsou uložené.');
      renderMarketRows(saved.markets);
    } catch (error) { toast(error.message, 'error'); }
  });

  $('#rerunOnboarding').addEventListener('click', openOnboarding);

  // Průvodce
  $('#onboardingMethods').addEventListener('click', event => {
    const card = event.target.closest('[data-method]');
    if (!card) return;
    settingsState.onboarding.method = card.dataset.method;
    renderOnboarding();
  });
  $('#onboardingMarkets').addEventListener('click', event => {
    const chip = event.target.closest('[data-onboarding-market]');
    if (!chip) return;
    const selected = settingsState.onboarding.selected;
    if (selected.has(chip.dataset.onboardingMarket)) selected.delete(chip.dataset.onboardingMarket);
    else selected.add(chip.dataset.onboardingMarket);
    renderOnboarding();
  });
  $('#onboardingAddMarket').addEventListener('click', () => {
    const symbol = $('#onboardingNewMarket').value.trim().toUpperCase().replace(/[^A-Z0-9._!-]/g, '');
    if (!symbol) return;
    const flow = settingsState.onboarding;
    if (!flow.markets.some(market => market.symbol === symbol)) flow.markets.push({ symbol, point_value: $('#onboardingNewPoint').value || null, rth: '09:30' });
    flow.selected.add(symbol);
    $('#onboardingNewMarket').value = '';
    $('#onboardingNewPoint').value = '';
    renderOnboarding();
  });
  $('#onboardingModules').addEventListener('change', event => {
    const input = event.target.closest('[data-toggle-group="onboarding"]');
    if (!input) return;
    const hidden = new Set(settingsState.onboarding.hiddenModules);
    if (input.checked) hidden.delete(input.dataset.key);
    else hidden.add(input.dataset.key);
    settingsState.onboarding.hiddenModules = [...hidden];
  });
  $('#onboardingNext').addEventListener('click', async () => {
    const flow = settingsState.onboarding;
    if (flow.page < 3) {
      flow.page += 1;
      renderOnboarding();
      return;
    }
    try { await finishOnboarding(); } catch (error) { toast(error.message, 'error'); }
  });
  $('#onboardingBack').addEventListener('click', () => {
    settingsState.onboarding.page = Math.max(1, settingsState.onboarding.page - 1);
    renderOnboarding();
  });
  $('#onboardingSkip').addEventListener('click', () => $('#onboardingDialog').close());
  // Zavření bez dokončení se bere jako přeskočení, ať průvodce nevyskakuje znovu.
  $('#onboardingDialog').addEventListener('close', () => {
    if (settingsState.onboarding && !settingsState.onboarding.finished && !prefs().onboarded) {
      saveWorkspace({ onboarded: true }, 'Průvodce spustíš kdykoli v Nastavení › Moduly.').catch(() => {});
    }
  });
}
