/* Napojení na cTrader: panel v Účtech a auditu, návrat z přihlášení a nastavení ve Správě. */

const brokerState = { data: null, syncing: false, autoSynced: false, admin: null };
/** Po jak dlouhé době se napojené účty při otevření stránky samy stáhnou znovu. */
const BROKER_STALE_MINUTES = 10;

const brokerReturnMessages = {
  connected: ['cTrader je napojený. Vyber, ke kterému účtu v deníku patří.', 'ok'],
  denied: ['Napojení jsi v cTraderu nepovolil.', 'error'],
  state: ['Návrat z cTraderu nešel ověřit. Spusť napojení znovu tlačítkem Napojit cTrader.', 'error'],
  empty: ['K tomuhle přihlášení v cTraderu nepatří žádný účet.', 'error'],
  off: ['Napojení na cTrader zatím není nastavené ve Správě.', 'error'],
  error: ['cTrader napojení odmítl. Zkus to znovu, případně zkontroluj nastavení ve Správě.', 'error'],
};

function brokerMoney(value, currency = 'USD') {
  const number = Number(value);
  if (!Number.isFinite(number)) return '—';
  try {
    return number.toLocaleString('cs-CZ', { style: 'currency', currency: currency || 'USD', minimumFractionDigits: 2, maximumFractionDigits: 2 });
  } catch (error) {
    return `${number.toLocaleString('cs-CZ', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${currency}`;
  }
}

function brokerPrice(value) {
  const number = Number(value);
  return value === null || value === undefined || !Number.isFinite(number) ? '—' : number.toLocaleString('cs-CZ', { maximumFractionDigits: 6 });
}

function brokerLabel(item) {
  return `${item.broker_name || 'cTrader'} · ${item.login || item.external_id}`;
}

function brokerStale(item) {
  if (!item.account_id) return false;
  if (!item.last_sync_at) return true;
  return Date.now() - new Date(item.last_sync_at).getTime() > BROKER_STALE_MINUTES * 60000;
}

async function refreshBroker({ autoSync = false } = {}) {
  if (!$('#brokerPanel')) return;
  try {
    brokerState.data = await api('broker_state');
    renderBroker();
    if (autoSync) maybeAutoSyncBroker();
  } catch (error) { toast(error.message, 'error'); }
}

function renderBroker() {
  const data = brokerState.data;
  const panel = $('#brokerPanel');
  panel.hidden = !data || !moduleOn('accounts');
  if (panel.hidden) return;
  const accounts = data.accounts || [];
  $('#brokerConnect').hidden = !data.configured;
  $('#brokerSyncAll').hidden = !data.configured || !accounts.some(item => item.account_id);
  $('#brokerSyncAll').disabled = brokerState.syncing;
  $('#brokerSyncAll').textContent = brokerState.syncing ? 'Synchronizuji…' : 'Synchronizovat';
  const note = $('#brokerNote');
  if (!data.configured) {
    note.textContent = isAdminViewer()
      ? 'Napojení zapneš ve Správě: vlož tam Client ID a Secret aplikace z openapi.ctrader.com.'
      : 'Napojení na cTrader zatím nezapnul správce aplikace.';
  } else if (!accounts.length) {
    note.textContent = 'Napoj svůj účet u brokera na cTraderu. Trading Desk uvidí zůstatek, otevřené pozice a historii, obchodovat ale neumí. Uzavřené obchody se samy zapíšou do deníku a Money audit vezme zůstatek přímo z cTraderu.';
  } else {
    note.textContent = 'Uzavřené pozice se zapisují do deníku jako obchody. Strategii, hodnocení a poznámky doplníš jako u ručně zapsaného obchodu.';
  }
  $('#brokerList').innerHTML = accounts.map(renderBrokerAccount).join('');
}

function brokerAccountOptions(item) {
  const linked = new Set((brokerState.data?.accounts || []).filter(other => other.id !== item.id && other.account_id).map(other => other.account_id));
  const free = (state.accounts || []).filter(account => !linked.has(account.id));
  const newName = `${item.broker_name || 'cTrader'} ${item.login || item.external_id}${item.is_live ? '' : ' demo'}`;
  return `<option value="new">+ Nový účet „${escapeHtml(newName)}“</option>${free.map(account => `<option value="${account.id}">${escapeHtml(account.name)}</option>`).join('')}`;
}

function renderBrokerPositions(item) {
  const currency = item.currency || 'USD';
  if (!item.positions.length) return '<p class="broker-empty">Žádná otevřená pozice.</p>';
  const rows = item.positions.map(position => {
    const pnl = Number(position.pnl);
    return `<tr>
      <td><strong>${escapeHtml(position.symbol)}</strong></td>
      <td><span class="badge ${position.direction === 'long' ? 'badge-green' : 'badge-due'}">${position.direction === 'long' ? 'Long' : 'Short'}</span></td>
      <td class="num">${brokerPrice(position.volume)} ${position.unit === 'lot' ? 'lot' : 'ks'}</td>
      <td class="num">${brokerPrice(position.entry)}</td>
      <td class="num">${brokerPrice(position.stop_loss)}</td>
      <td class="num">${brokerPrice(position.take_profit)}</td>
      <td class="num ${pnl >= 0 ? 'value-positive' : 'value-negative'}">${brokerMoney(position.pnl, currency)}</td>
    </tr>`;
  }).join('');
  return `<div class="table-wrap broker-positions"><table><thead><tr><th>Symbol</th><th>Směr</th><th class="num">Objem</th><th class="num">Vstup</th><th class="num">SL</th><th class="num">TP</th><th class="num">P&amp;L</th></tr></thead><tbody>${rows}</tbody></table></div>`;
}

function renderBrokerAccount(item) {
  const currency = item.currency || 'USD';
  const synced = item.last_sync_at ? timeAgo(item.last_sync_at) : 'zatím ne';
  const kind = `<span class="badge ${item.is_live ? 'badge-gold' : 'badge-violet'}">${item.is_live ? 'Live' : 'Demo'}</span>`;
  const error = item.last_error ? `<p class="broker-error" role="alert">${escapeHtml(item.last_error)}</p>` : '';
  if (!item.account_id) {
    const minimum = new Date(Date.now() - (brokerState.data.history_days || 365) * 86400000).toISOString().slice(0, 10);
    return `<article class="account-card broker-card is-pending">
      <div class="account-head"><div><strong>${escapeHtml(brokerLabel(item))}</strong><p>Napojeno, zatím bez účtu v deníku</p></div>${kind}</div>
      ${error}
      <form class="broker-link" data-broker-link="${item.id}">
        <label>Účet v deníku<select name="account_id">${brokerAccountOptions(item)}</select></label>
        <label>Zapsat obchody od<input type="date" name="import_from" value="${today()}" min="${minimum}" max="${today()}" required></label>
        <button class="button button-primary" type="submit">Propojit</button>
      </form>
      <p class="broker-hint">Do deníku se zapíšou pozice uzavřené od zvoleného dne. Nový účet dostane vstupní stav podle zůstatku k tomu dni. U stávajícího účtu nech dnešek, ať se ručně zapsané obchody nezdvojí.</p>
      <div class="account-actions"><button class="mini-button danger" type="button" data-broker-remove="${item.id}">Odebrat</button></div>
    </article>`;
  }
  return `<article class="account-card broker-card">
    <div class="account-head"><div><strong>${escapeHtml(brokerLabel(item))}</strong><p>Účet v deníku <b>${escapeHtml(item.account_name || '—')}</b> · obchody od ${escapeHtml(item.import_from || '—')}</p></div>${kind}</div>
    ${error}
    <dl class="account-figures">
      <div><dt>Zůstatek</dt><dd>${brokerMoney(item.balance, currency)}</dd></div>
      <div><dt>Equity</dt><dd>${brokerMoney(item.equity, currency)}</dd></div>
      <div><dt>Otevřené pozice</dt><dd>${item.positions.length}</dd></div>
      <div><dt>Zapsané obchody</dt><dd>${item.imported}</dd></div>
      <div><dt>Vklady a výběry</dt><dd>${brokerMoney(item.cash_flow, currency)}</dd></div>
      <div><dt>Synchronizace</dt><dd title="${escapeHtml(item.last_sync_at || '')}">${escapeHtml(synced)}</dd></div>
    </dl>
    ${renderBrokerPositions(item)}
    <div class="account-actions">
      <button class="mini-button" type="button" data-broker-sync="${item.id}"${brokerState.syncing ? ' disabled' : ''}>Synchronizovat</button>
      <button class="mini-button" type="button" data-broker-audit="${item.id}"${brokerState.syncing ? ' disabled' : ''}>Money audit z cTraderu</button>
      <span class="spacer"></span>
      <button class="mini-button" type="button" data-broker-unlink="${item.id}">Zrušit propojení</button>
      <button class="mini-button danger" type="button" data-broker-remove="${item.id}">Odebrat</button>
    </div>
  </article>`;
}

function brokerSyncMessage(result) {
  const imported = Number(result.imported) || 0;
  if (result.errors?.length) return [result.errors.join(' '), 'error'];
  if (imported > 0) return [`Z cTraderu ${imported === 1 ? 'přibyl 1 obchod' : imported <= 4 ? `přibyly ${imported} obchody` : `přibylo ${imported} obchodů`}.`, 'ok'];
  return ['cTrader je aktuální, nové obchody nejsou.', 'ok'];
}

/** Po synchronizaci se obnoví vše, co z obchodů a zůstatků vychází. */
async function afterBrokerChange(result) {
  if (result?.state) {
    brokerState.data = result.state;
    renderBroker();
  }
  await refreshAccounts();
  if (Number(result?.imported) > 0) await Promise.all([refreshTrades(), refreshDashboard()]);
}

async function syncBroker(id = null, { quiet = false } = {}) {
  if (brokerState.syncing) return;
  brokerState.syncing = true;
  renderBroker();
  try {
    const result = await api('broker_sync', { method: 'POST', body: id ? { id } : {} });
    brokerState.syncing = false;
    const [message, type] = brokerSyncMessage(result);
    if (!quiet || type === 'error' || Number(result.imported) > 0) toast(message, type);
    await afterBrokerChange(result);
  } catch (error) {
    brokerState.syncing = false;
    renderBroker();
    if (!quiet) toast(error.message, 'error');
  }
}

function maybeAutoSyncBroker() {
  const data = brokerState.data;
  if (brokerState.autoSynced || brokerState.syncing || !data?.configured) return;
  if (!(data.accounts || []).some(brokerStale)) return;
  brokerState.autoSynced = true;
  syncBroker(null, { quiet: true });
}

async function linkBrokerAccount(form) {
  const id = Number(form.dataset.brokerLink);
  const values = Object.fromEntries(new FormData(form));
  const button = $('button[type="submit"]', form);
  button.disabled = true;
  button.textContent = 'Stahuji z cTraderu…';
  brokerState.syncing = true;
  try {
    const result = await api('broker_account', { method: 'POST', body: { id, account_id: values.account_id === 'new' ? 'new' : Number(values.account_id), import_from: values.import_from } });
    brokerState.syncing = false;
    const imported = Number(result.imported) || 0;
    toast(imported ? `Účet je propojený. ${brokerSyncMessage(result)[0]}` : 'Účet je propojený s deníkem.');
    await afterBrokerChange(result);
  } catch (error) {
    brokerState.syncing = false;
    toast(error.message, 'error');
    await refreshBroker();
  }
}

async function brokerAuditNow(id) {
  if (brokerState.syncing) return;
  brokerState.syncing = true;
  renderBroker();
  try {
    const result = await api('broker_audit', { method: 'POST', body: { id } });
    brokerState.syncing = false;
    const audit = result.audit;
    toast(audit?.status === 'ok' ? 'Money audit z cTraderu sedí.' : 'Money audit z cTraderu nesedí, mrkni na detail.', audit?.status === 'ok' ? 'ok' : 'error');
    await afterBrokerChange(result);
  } catch (error) {
    brokerState.syncing = false;
    renderBroker();
    toast(error.message, 'error');
  }
}

async function unlinkBrokerAccount(id) {
  if (!confirm('Zrušit propojení s účtem v deníku? Zapsané obchody v deníku zůstanou.')) return;
  try {
    const result = await api('broker_account', { method: 'POST', body: { id, account_id: null } });
    await afterBrokerChange(result);
  } catch (error) { toast(error.message, 'error'); }
}

async function removeBrokerAccount(id) {
  if (!confirm('Odebrat napojení tohoto účtu cTraderu? Obchody, které už jsou v deníku, zůstanou. Přístup k účtu zrušíš i v cTrader ID v nastavení aplikací.')) return;
  try {
    const fresh = await api('broker_account', { method: 'DELETE', query: { id } });
    await afterBrokerChange({ state: fresh });
    toast('Napojení účtu bylo odebráno.');
  } catch (error) { toast(error.message, 'error'); }
}

/** Návrat z přihlášení do cTraderu (ctrader.php přesměruje na ./?ctrader=…). */
function handleBrokerReturn() {
  const params = new URLSearchParams(location.search);
  const result = params.get('ctrader');
  if (!result) return false;
  history.replaceState(null, '', location.pathname);
  const [message, type] = brokerReturnMessages[result] || brokerReturnMessages.error;
  const code = params.get('code');
  toast(code && type === 'error' ? `${message} (${code})` : message, type);
  activateView('accounts');
  return true;
}

/* ---------------------------------------------------------------- Správa */

async function refreshCtraderAdmin() {
  if (!isAdminViewer() || !$('#ctraderForm')) return;
  try {
    brokerState.admin = await api('admin_ctrader');
    renderCtraderAdmin();
  } catch (error) { toast(error.message, 'error'); }
}

function renderCtraderAdmin() {
  const settings = brokerState.admin;
  if (!settings) return;
  const form = $('#ctraderForm');
  form.elements.client_id.value = settings.client_id || '';
  form.elements.redirect_uri.value = settings.redirect_uri || settings.suggested_redirect_uri || '';
  form.elements.client_secret.value = '';
  form.elements.client_secret.placeholder = settings.has_secret ? 'Uložený, prázdné pole ho nechá' : 'Secret z aplikace';
  form.elements.client_secret.required = !settings.has_secret;
  const status = $('#ctraderStatus');
  status.textContent = settings.configured ? 'Zapnuto' : 'Vypnuto';
  status.className = `badge ${settings.configured ? 'badge-green' : ''}`;
  $('#ctraderClear').hidden = !settings.configured && !settings.client_id;
}

async function saveCtraderAdmin(event) {
  event.preventDefault();
  const values = Object.fromEntries(new FormData(event.target));
  try {
    brokerState.admin = await api('admin_ctrader', { method: 'POST', body: values });
    renderCtraderAdmin();
    toast('Napojení na cTrader je uložené. Členové teď mohou účty napojit v Účtech a auditu.');
    refreshBroker();
  } catch (error) { toast(error.message, 'error'); }
}

async function clearCtraderAdmin() {
  if (!confirm('Vypnout napojení na cTrader? Uložené Client ID a Secret se smažou a synchronizace přestane fungovat, dokud je znovu nevložíš.')) return;
  try {
    brokerState.admin = await api('admin_ctrader', { method: 'POST', body: { clear: true } });
    renderCtraderAdmin();
    toast('Napojení na cTrader je vypnuté.');
    refreshBroker();
  } catch (error) { toast(error.message, 'error'); }
}

async function copyCtraderRedirect() {
  const value = $('#ctraderRedirect').value;
  try {
    await navigator.clipboard.writeText(value);
    toast('Adresa pro návrat je zkopírovaná.');
  } catch (error) {
    $('#ctraderRedirect').select();
    toast('Adresu označ a zkopíruj ručně (Ctrl+C).', 'error');
  }
}

function bindBrokerEvents() {
  if (!$('#brokerPanel')) return;
  $('#brokerSyncAll').addEventListener('click', () => syncBroker());
  $('#brokerList').addEventListener('submit', event => {
    const form = event.target.closest('[data-broker-link]');
    if (!form) return;
    event.preventDefault();
    linkBrokerAccount(form);
  });
  $('#brokerList').addEventListener('click', event => {
    const sync = event.target.closest('[data-broker-sync]');
    if (sync) syncBroker(Number(sync.dataset.brokerSync));
    const audit = event.target.closest('[data-broker-audit]');
    if (audit) brokerAuditNow(Number(audit.dataset.brokerAudit));
    const unlink = event.target.closest('[data-broker-unlink]');
    if (unlink) unlinkBrokerAccount(Number(unlink.dataset.brokerUnlink));
    const remove = event.target.closest('[data-broker-remove]');
    if (remove) removeBrokerAccount(Number(remove.dataset.brokerRemove));
  });
  if ($('#ctraderForm')) {
    $('#ctraderForm').addEventListener('submit', saveCtraderAdmin);
    $('#ctraderClear').addEventListener('click', clearCtraderAdmin);
    $('#ctraderCopy').addEventListener('click', copyCtraderRedirect);
  }
}
