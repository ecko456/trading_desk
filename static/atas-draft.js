/*
 * Nástřel náhledu z ATAS (Náhled trhu → krok Profil → Nástřel z ATAS). Indikátor
 * „Trading Desk: nástřel náhledu“ spočítá profil posledního dne nebo týdne a pošle ho přes
 * odkaz pro ATAS (atas.php, POST), případně ho trader nahraje jako soubor. Server z něj
 * připraví hodnoty formuláře (lib/atas-draft.php). Tady se formulář jen předvyplní: uloží
 * ho až trader.
 *
 * Existující náhled stejného dne, trhu a typu se nepřepisuje, doplní se jen prázdná pole
 * a chybějící levely a reference.
 */

const atasDraft = { busy: false };

async function openAtasDraftDialog() {
  $('#atasDraftDialog').showModal();
  await loadAtasDrafts();
}

async function loadAtasDrafts() {
  const list = $('#atasDraftList');
  list.innerHTML = '<div class="empty-state compact">Načítám nástřely…</div>';
  try {
    renderAtasDrafts((await api('atas_drafts')).items || []);
  } catch (error) {
    list.innerHTML = `<div class="empty-state compact">${escapeHtml(error.message)}</div>`;
  }
}

function atasDraftPeriod(item) {
  const from = item.period?.from;
  const to = item.period?.to || from;
  if (!to) return '';
  if (item.kind === 'weekly' && from && from !== to) return `${prettyDate(from, false)} – ${prettyDate(to, false)} ${to.slice(0, 4)}`;
  return `${prettyDate(to)} ${to.slice(0, 4)}`;
}

function renderAtasDrafts(items) {
  const list = $('#atasDraftList');
  if (!items.length) {
    list.innerHTML = '<div class="empty-state compact">Zatím nepřišel žádný nástřel. V ATAS zaškrtni u indikátoru <b>Odeslat teď</b>, nebo nahraj soubor.</div>';
    return;
  }
  list.innerHTML = items.map(item => {
    const weekly = item.kind === 'weekly';
    const values = [['H', item.high], ['POC', item.poc], ['L', item.low], ['C', item.close]]
      .filter(([, value]) => value !== null && value !== undefined)
      .map(([label, value]) => `<span><em>${label}</em> ${escapeHtml(displayPrice(value))}</span>`).join('');
    const meta = [item.profile === 'eth' ? 'ETH' : 'RTH', item.symbol, `přišel ${timeAgo(item.received_at)}`].filter(Boolean).map(escapeHtml).join(' · ');
    return `<article class="draft-row" data-draft="${Number(item.id)}">
      <span class="draft-kind" data-kind="${weekly ? 'weekly' : 'daily'}">${weekly ? 'Týden' : 'Den'}</span>
      <div class="draft-main">
        <strong>${escapeHtml(item.market)} · ${escapeHtml(atasDraftPeriod(item))}</strong>
        <small>${meta}</small>
        <div class="draft-values">${values}</div>
      </div>
      <div class="draft-actions">
        <button class="button button-primary" type="button" data-draft-use>Použít</button>
        <button class="remove-row" type="button" data-draft-delete aria-label="Smazat nástřel">Smazat</button>
      </div>
    </article>`;
  }).join('');
}

/** Pole, která nový denní náhled sám převezme z týdenního (inheritWeeklyBias v app.js). */
const ATAS_DRAFT_INHERITED = ['pa_monthly', 'pa_weekly', 'mp_weekly', 'pa_weekly_note', 'mp_weekly_note'];

/**
 * Rozpracovaný nový (neuložený) náhled s vlastním obsahem, aby se omylem nezahodil.
 * Porovnává s výchozími hodnotami formuláře, takže prázdný náhled se nepočítá.
 */
function planFormHasOwnContent() {
  if ($('#planId').value) return false;
  if ($$('#planForm .plan-row').length) return true;
  const skip = new Set(['id', 'plan_type', 'plan_date', 'market', 'session', 'status', ...ATAS_DRAFT_INHERITED]);
  return $$('#planForm input, #planForm textarea, #planForm select').some(control => {
    if (!control.name || skip.has(control.name) || control.type === 'hidden' || control.type === 'file') return false;
    if (control.type === 'radio' || control.type === 'checkbox') return control.checked !== control.defaultChecked;
    if (control.tagName === 'SELECT') {
      const initial = [...control.options].find(option => option.defaultSelected) || control.options[0];
      return control.value !== (initial ? initial.value : '');
    }
    return control.value.trim() !== control.defaultValue.trim();
  });
}

/** Doplní do formuláře jen to, co v něm ještě není. Vrací počty doplněných položek. */
function fillPlanFromDraft(plan) {
  const form = $('#planForm');
  const skip = new Set(['plan_type', 'plan_date', 'market', 'status', 'levels', 'refs', 'general_notes']);
  let fields = 0;
  Object.entries(plan).forEach(([name, value]) => {
    if (skip.has(name) || value === null || value === undefined || value === '') return;
    const controls = $$(`[name="${CSS.escape(name)}"]`, form).filter(control => !control.closest('.plan-row'));
    if (!controls.length) return;
    if (controls[0].type === 'radio') {
      if (controls.some(control => control.checked)) return;
      const match = controls.find(control => control.value === String(value));
      if (match) { match.checked = true; fields += 1; }
      return;
    }
    const control = controls[0];
    if (String(control.value).trim() !== '') return;
    if (control.tagName === 'SELECT' && ![...control.options].some(option => option.value === String(value))) return;
    control.value = value;
    fields += 1;
  });

  const notes = $('[name="general_notes"]', form);
  if (plan.general_notes && !notes.value.includes(plan.general_notes)) {
    notes.value = notes.value.trim() ? `${notes.value.trim()}\n\n${plan.general_notes}` : plan.general_notes;
  }

  const priceKey = value => (numberOrNull(value) === null ? '' : String(numberOrNull(value)));
  const levelKeys = new Set($$('.level-row').map(row => `${$('[name="name"]', row).value.trim().toUpperCase()}|${priceKey($('[name="price"]', row).value)}`));
  let levels = 0;
  (plan.levels || []).forEach(level => {
    const key = `${String(level.name).trim().toUpperCase()}|${priceKey(level.price)}`;
    if (levelKeys.has(key)) return;
    levelKeys.add(key);
    appendRow('#levelList', levelTemplate(level, $$('.level-row').length));
    levels += 1;
  });

  const refKeys = new Set($$('.ref-row').map(row => `${$('[name="kind"]', row).value}|${priceKey($('[name="price_low"]', row).value)}`));
  let refs = 0;
  (plan.refs || []).forEach(ref => {
    const key = `${ref.kind}|${priceKey(ref.price_low)}`;
    if (refKeys.has(key)) return;
    refKeys.add(key);
    appendRow('#refList', refTemplate(ref));
    refs += 1;
  });
  return { fields, levels, refs };
}

async function applyAtasDraft({ plan, existing_id: existingId }) {
  const currentId = Number($('#planId').value || 0);
  const sameNew = !currentId
    && planType() === plan.plan_type
    && $('#planDate').value === plan.plan_date
    && $('#planMarket').value.trim().toUpperCase() === plan.market;
  const target = `${plan.plan_type === 'weekly' ? 'týdenní náhled od' : 'denní náhled na'} ${prettyDate(plan.plan_date, plan.plan_type !== 'weekly')}`;

  if (existingId) {
    if (currentId !== existingId) {
      if (!currentId && planFormHasOwnContent() && !confirm(`Na ${target} už náhled máš a otevře se. Rozpracovaný neuložený náhled se zahodí. Pokračovat?`)) return false;
      await loadPlan(existingId);
    }
  } else if (!sameNew) {
    if (planFormHasOwnContent() && !confirm(`Nástřel se otevře jako nový ${target}. Rozpracovaný neuložený náhled se zahodí. Pokračovat?`)) return false;
    resetPlan({ type: plan.plan_type, date: plan.plan_date, market: plan.market });
  }

  const done = fillPlanFromDraft(plan);
  activateView('plan');
  updateRefSides();
  schedulePlanRefresh();
  const added = [
    done.fields && `${done.fields} ${done.fields === 1 ? 'pole' : done.fields <= 4 ? 'pole' : 'polí'}`,
    done.levels && `${done.levels} ${done.levels === 1 ? 'level' : done.levels <= 4 ? 'levely' : 'levelů'}`,
    done.refs && `${done.refs} ${done.refs === 1 ? 'reference' : done.refs <= 4 ? 'reference' : 'referencí'}`,
  ].filter(Boolean).join(', ');
  if (existingId) toast(added ? `Náhled už existoval, doplnil se jen prázdný obsah: ${added}. Zkontroluj ho a ulož.` : 'Náhled už všechno z nástřelu obsahuje.');
  else toast(`Nástřel je v náhledu (${added || 'bez hodnot'}). Zkontroluj ho a ulož.`);
  return true;
}

async function useAtasDraft(id, button) {
  if (atasDraft.busy) return;
  atasDraft.busy = true;
  if (button) button.disabled = true;
  try {
    const result = await api('atas_draft', { query: { id } });
    if (await applyAtasDraft(result)) $('#atasDraftDialog').close();
  } catch (error) {
    toast(error.message, 'error');
  } finally {
    atasDraft.busy = false;
    if (button) button.disabled = false;
  }
}

async function deleteAtasDraft(id) {
  if (!confirm('Smazat tento nástřel? Náhledy, které z něj vznikly, zůstanou.')) return;
  try {
    renderAtasDrafts((await api('atas_draft', { method: 'DELETE', query: { id } })).items || []);
  } catch (error) {
    toast(error.message, 'error');
  }
}

async function uploadAtasDraftFile(file) {
  if (!file) return;
  if (file.size > 131072) { toast('Soubor je moc velký, nástřel z ATAS má jen pár kilobajtů.', 'error'); return; }
  try {
    const text = await file.text();
    const result = await api('atas_draft_file', { method: 'POST', body: { draft: text, market: $('#planMarket').value.trim() } });
    if (await applyAtasDraft(result)) $('#atasDraftDialog').close();
  } catch (error) {
    toast(error.message, 'error');
  }
}

function bindAtasDraftEvents() {
  if (!$('#atasDraftDialog')) return;
  $('#atasDraftOpen').addEventListener('click', () => {
    openAtasDraftDialog().catch(error => toast(error.message, 'error'));
  });
  $('#atasDraftForm').addEventListener('submit', event => {
    event.preventDefault();
    if (event.submitter?.value === 'cancel') $('#atasDraftDialog').close();
  });
  $('#atasDraftList').addEventListener('click', event => {
    const row = event.target.closest('[data-draft]');
    if (!row) return;
    const id = Number(row.dataset.draft);
    const use = event.target.closest('[data-draft-use]');
    if (use) useAtasDraft(id, use);
    else if (event.target.closest('[data-draft-delete]')) deleteAtasDraft(id);
  });
  $('#atasDraftFile').addEventListener('change', async event => {
    const input = event.target;
    await uploadAtasDraftFile(input.files?.[0]);
    input.value = '';
  });
}
