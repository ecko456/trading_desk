/*
 * Odkaz pro ATAS v náhledu (Sdílet → Odkaz pro ATAS). Indikátor v ATAS si přes tajný odkaz
 * stahuje zóny posledního denního náhledu trhu; server je drží jako snímek a obnovuje ho
 * při uložení náhledu nebo zóny (lib/atas.php, atas.php).
 */

const atasLink = { market: '', state: null, busy: false };

// Před otevřením se náhled uloží, aby odkaz vracel aktuální zóny.
async function shareCurrentPlanToAtas() {
  const button = $('#sharePlan');
  button.disabled = true;
  try {
    const plan = await savePlan({ quiet: true });
    await openAtasDialog(plan.market);
  } catch (error) {
    toast(error.message, 'error');
  } finally {
    button.disabled = false;
  }
}

async function openAtasDialog(market) {
  atasLink.market = String(market || '').trim().toUpperCase();
  const result = await api('atas_link', { query: { market: atasLink.market } });
  renderAtasLink(result);
  $('#atasDialog').showModal();
}

function atasUrl(token) {
  return new URL(`atas.php?t=${encodeURIComponent(token)}`, location.href).href;
}

function renderAtasLink(state) {
  atasLink.state = state;
  const link = state.link;
  const preview = state.preview || {};
  $('#atasMarket').textContent = state.market;
  $('#atasLinkBox').hidden = !link;
  $('#atasUrl').value = link ? atasUrl(link.token) : '';
  $('#atasWeekly').checked = link ? Boolean(link.include_weekly) : true;
  $('#atasCreate').hidden = Boolean(link);
  $('#atasRegenerate').hidden = !link;
  $('#atasRemove').hidden = !link;

  const day = preview.date ? `${prettyDate(preview.date)} · ${preview.zones} ${preview.zones === 1 ? 'zóna' : preview.zones >= 2 && preview.zones <= 4 ? 'zóny' : 'zón'}` : 'zatím žádný denní náhled';
  const read = link ? (link.read_at ? `ATAS naposledy četl ${timeAgo(link.read_at)}` : 'ATAS odkaz ještě nečetl') : 'Odkaz zatím není';
  $('#atasStatus').innerHTML = `
    <div><span>Odkaz teď vrací</span><strong>${escapeHtml(day)}</strong></div>
    <div><span>Stav</span><strong class="${link?.read_at ? 'is-live' : ''}">${escapeHtml(read)}</strong></div>`;
}

async function saveAtasLink(extra = {}) {
  if (atasLink.busy) return;
  atasLink.busy = true;
  try {
    const result = await api('atas_link', { method: 'POST', body: { market: atasLink.market, include_weekly: $('#atasWeekly').checked, ...extra } });
    renderAtasLink(result);
    return result;
  } catch (error) {
    toast(error.message, 'error');
  } finally {
    atasLink.busy = false;
  }
}

async function copyAtasUrl() {
  const input = $('#atasUrl');
  try {
    await navigator.clipboard.writeText(input.value);
    toast('Odkaz je zkopírovaný. Vlož ho do indikátoru v ATAS.');
  } catch (error) {
    input.select();
    toast('Odkaz označ a zkopíruj ručně (Ctrl+C).', 'error');
  }
}

function bindAtasEvents() {
  if (!$('#atasDialog')) return;
  $('#atasForm').addEventListener('submit', event => {
    event.preventDefault();
    if (event.submitter?.value === 'cancel') $('#atasDialog').close();
  });
  $('#atasCreate').addEventListener('click', async () => {
    const result = await saveAtasLink();
    if (result?.link) toast('Odkaz pro ATAS je připravený.');
  });
  $('#atasCopy').addEventListener('click', copyAtasUrl);
  $('#atasWeekly').addEventListener('change', () => { if (atasLink.state?.link) saveAtasLink(); });
  $('#atasRegenerate').addEventListener('click', async () => {
    if (!confirm('Vydat nový odkaz? Starý přestane fungovat a v ATAS bude potřeba vložit nový.')) return;
    const result = await saveAtasLink({ regenerate: true });
    if (result?.link) toast('Nový odkaz je připravený. Starý už neplatí.');
  });
  $('#atasRemove').addEventListener('click', async () => {
    if (!confirm('Zrušit odkaz? Indikátor v ATAS pak zóny nenačte.')) return;
    try {
      renderAtasLink(await api('atas_link', { method: 'DELETE', query: { market: atasLink.market } }));
      toast('Odkaz pro ATAS je zrušený.');
    } catch (error) { toast(error.message, 'error'); }
  });
}
