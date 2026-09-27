<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

function icon(string $name): string
{
    $paths = [
        'dashboard' => '<rect x="3.5" y="3.5" width="7" height="7" rx="1.6"/><rect x="13.5" y="3.5" width="7" height="7" rx="1.6"/><rect x="3.5" y="13.5" width="7" height="7" rx="1.6"/><rect x="13.5" y="13.5" width="7" height="7" rx="1.6"/>',
        'plan' => '<circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="3"/><path d="M12 2.5v3M12 18.5v3M2.5 12h3M18.5 12h3"/>',
        'archive' => '<path d="M3.5 12a8.5 8.5 0 1 0 2.6-6.1L3.5 8.3"/><path d="M3.5 3.5v4.8h4.8"/><path d="M12 7.5V12l3 2"/>',
        'calendar' => '<rect x="3.5" y="5" width="17" height="15.5" rx="2"/><path d="M3.5 10h17M8 3v4M16 3v4"/>',
        'journal' => '<path d="M5 4h10.5A3.5 3.5 0 0 1 19 7.5V20H8.5A3.5 3.5 0 0 1 5 16.5z"/><path d="M9 9h6M9 13h6"/>',
        'strategies' => '<path d="M4 4v16h16"/><path d="m8 15 3.5-4 3 2.5L19 8"/>',
        'psyche' => '<path d="M3 12h4l2.5-6.5 5 13L17 12h4"/>',
        'accounts' => '<path d="M4 7.5A2.5 2.5 0 0 1 6.5 5H18v3.5"/><path d="M4 7.5v10A2.5 2.5 0 0 0 6.5 20H20V8.5H6.5A2.5 2.5 0 0 1 4 6"/><circle cx="16" cy="14.2" r="1.2"/>',
        'backup' => '<ellipse cx="12" cy="6" rx="7.5" ry="2.8"/><path d="M4.5 6v12c0 1.6 3.4 2.8 7.5 2.8s7.5-1.2 7.5-2.8V6"/><path d="M4.5 12c0 1.6 3.4 2.8 7.5 2.8s7.5-1.2 7.5-2.8"/>',
        'moon' => '<path d="M19.5 14.5A7.8 7.8 0 1 1 9.5 4.5a6.2 6.2 0 0 0 10 10z"/>',
        'sun' => '<circle cx="12" cy="12" r="3.8"/><path d="M12 2.5v2M12 19.5v2M5.3 5.3l1.4 1.4M17.3 17.3l1.4 1.4M2.5 12h2M19.5 12h2M5.3 18.7l1.4-1.4M17.3 6.7l1.4-1.4"/>',
        'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'download' => '<path d="M12 4v11"/><path d="m7.5 10.5 4.5 4.5 4.5-4.5"/><path d="M5 19.5h14"/>',
        'lock' => '<rect x="5.5" y="10.5" width="13" height="9.5" rx="2"/><path d="M8.5 10.5V8a3.5 3.5 0 0 1 7 0v2.5"/>',
    ];
    return '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">' . ($paths[$name] ?? '') . '</svg>';
}

/** Silueta TPO profilu: řádky s různou šířkou, jak je trader vidí na grafu. */
function profile_glyph(array $widths): string
{
    $rects = '';
    foreach ($widths as $index => $width) {
        $rects .= sprintf('<rect x="3" y="%d" width="%d" height="3.3" rx="1"/>', 3 + $index * 5, $width);
    }
    return '<svg class="shape-glyph" viewBox="0 0 30 48" aria-hidden="true">' . $rects . '</svg>';
}

$shapes = [
    ['p', 'P', 'Přijetí nahoře, často short covering', [7, 15, 21, 24, 20, 11, 6, 4, 4]],
    ['b', 'b', 'Přijetí dole, často likvidace longů', [4, 4, 6, 11, 20, 24, 21, 15, 7]],
    ['d', 'D', 'Vyvážená aukce, normální rozložení', [4, 8, 14, 20, 24, 20, 14, 8, 4]],
    ['double', 'B', 'Dvojitá distribuce, dvě value', [6, 14, 20, 13, 5, 13, 20, 14, 6]],
    ['trend', 'Trend', 'Protažený profil, iniciativa jedné strany', [5, 6, 7, 6, 5, 7, 6, 5, 6]],
];
$vaOptions = '<option value="">—</option><option value="inside">Uvnitř VA</option><option value="above">Nad VA</option><option value="below">Pod VA</option><option value="outside">Mimo range</option>';
?>
<!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="dark light">
  <meta name="theme-color" content="#0b0e13">
  <title>Trading Desk</title>
  <script src="<?= asset_url('static/theme.js') ?>"></script>
  <link rel="stylesheet" href="<?= asset_url('static/styles.css') ?>">
</head>
<body>
  <div class="app-shell">
    <aside class="sidebar" id="sidebar">
      <div class="brand">
        <span class="brand-mark" aria-hidden="true"><i></i><i></i><i></i></span>
        <span><strong>Trading Desk</strong><small>Market Profile journal</small></span>
      </div>
      <nav class="nav" aria-label="Hlavní navigace">
        <p class="nav-group">Příprava</p>
        <button class="nav-item is-active" type="button" data-view="dashboard"><?= icon('dashboard') ?><span>Přehled</span></button>
        <button class="nav-item" type="button" data-view="plan"><?= icon('plan') ?><span>Náhled trhu</span></button>
        <button class="nav-item" type="button" data-view="archive"><?= icon('archive') ?><span>Historie náhledů</span></button>
        <button class="nav-item" type="button" data-view="calendar"><?= icon('calendar') ?><span>Kalendář</span></button>
        <p class="nav-group">Exekuce</p>
        <button class="nav-item" type="button" data-view="journal"><?= icon('journal') ?><span>Deník obchodů</span></button>
        <button class="nav-item" type="button" data-view="strategies"><?= icon('strategies') ?><span>Strategie</span></button>
        <p class="nav-group">Disciplína</p>
        <button class="nav-item" type="button" data-view="psyche"><?= icon('psyche') ?><span>Psychika</span></button>
        <button class="nav-item" type="button" data-view="accounts"><?= icon('accounts') ?><span>Účty a audit</span><em class="nav-flag" id="navAuditFlag" hidden>Money audit</em></button>
        <p class="nav-group">Data</p>
        <button class="nav-item" type="button" data-view="backup"><?= icon('backup') ?><span>Záloha a export</span></button>
      </nav>
      <div class="sidebar-foot">
        <div class="server-state"><span class="status-dot" id="serverDot"></span><span><strong id="serverStatus">Ověřuji server</strong><small>data zůstávají u tebe v PC</small></span></div>
        <button class="icon-button theme-toggle" type="button" id="themeToggle" aria-label="Přepnout světlý a tmavý vzhled" title="Světlý / tmavý vzhled"><?= icon('moon') ?><?= icon('sun') ?></button>
      </div>
    </aside>
    <div class="sidebar-scrim" id="sidebarScrim" hidden></div>

    <div class="main-column">
      <header class="topbar">
        <button class="icon-button mobile-menu" id="mobileMenu" type="button" aria-label="Otevřít navigaci"><?= icon('menu') ?></button>
        <div class="topbar-title">
          <p class="eyebrow" id="viewEyebrow">Trading journal</p>
          <h1 id="viewTitle">Přehled</h1>
        </div>
        <div class="top-actions">
          <select id="globalMarket" aria-label="Filtrovat trh">
            <option value="">Všechny trhy</option>
            <option>ES</option><option>NQ</option><option>GC</option><option>CL</option><option>6E</option>
          </select>
          <button class="button button-ghost" type="button" id="quickTrade"><?= icon('plus') ?>Obchod</button>
          <div class="menu-wrap">
            <button class="button button-primary" type="button" id="quickPlan" aria-haspopup="menu" aria-expanded="false"><?= icon('plus') ?>Nový náhled</button>
            <div class="menu" id="quickPlanMenu" role="menu" hidden>
              <button type="button" role="menuitem" data-new-plan="daily"><strong>Denní náhled</strong><small>Příprava na jednu session</small></button>
              <button type="button" role="menuitem" data-new-plan="weekly"><strong>Týdenní náhled</strong><small>Zóny a kontext na celý týden</small></button>
            </div>
          </div>
        </div>
      </header>

      <main class="workspace">
        <!-- PŘEHLED -->
        <section class="view is-active" id="view-dashboard">
          <div class="audit-banner" id="auditBanner" hidden>
            <div><strong id="auditBannerTitle">Money audit čeká</strong><p id="auditBannerText"></p></div>
            <button class="button button-primary" type="button" id="auditBannerAction">Spustit audit</button>
          </div>

          <section class="surface readiness">
            <div class="section-heading"><div><p class="eyebrow">Před session</p><h2 id="readinessTitle">Připravenost</h2></div><span class="readiness-score" id="readinessScore"></span></div>
            <div class="readiness-grid" id="readinessGrid"><div class="skeleton"></div></div>
          </section>

          <div class="metric-strip">
            <article><span>Celkem R</span><strong id="metricR">0,00R</strong><small id="metricTrades">0 obchodů</small></article>
            <article><span>Průměr na obchod</span><strong id="metricAvg">—</strong><small>očekávaná hodnota v R</small></article>
            <article><span>Profit factor</span><strong id="metricPf">—</strong><small>zisky / ztráty v R</small></article>
            <article><span>Čisté P&amp;L</span><strong id="metricUsd">$0</strong><small>po započtení poplatků</small></article>
            <article><span>Dodržení plánu</span><strong id="metricPlan">—</strong><small>obchodů podle plánu</small></article>
          </div>

          <div class="dashboard-grid">
            <section class="surface equity-surface">
              <div class="section-heading"><div><p class="eyebrow">Výkonnost</p><h2>Equity v R</h2></div><span id="equityCaption">Bez obchodů</span></div>
              <div class="equity-chart" id="equityChart" aria-label="Equity křivka"></div>
            </section>
            <section class="surface today-surface">
              <div class="section-heading"><div><p class="eyebrow">Aktivní příprava</p><h2>Týden a den</h2></div><button class="text-button" type="button" data-open-view="archive">Historie</button></div>
              <div id="todayPlan" class="empty-state compact">Zatím není uložený žádný náhled.</div>
            </section>
          </div>

          <section class="surface table-surface">
            <div class="section-heading"><div><p class="eyebrow">Poslední exekuce</p><h2>Nedávné obchody</h2></div><button class="text-button" type="button" data-open-view="journal">Celý deník</button></div>
            <div class="table-wrap"><table><thead><tr><th>Datum</th><th>Trh</th><th>Setup</th><th>Směr</th><th class="num">Výsledek</th><th>Plán</th></tr></thead><tbody id="recentTrades"></tbody></table></div>
          </section>
        </section>

        <!-- NÁHLED TRHU -->
        <section class="view" id="view-plan">
          <form id="planForm">
            <input type="hidden" name="id" id="planId">
            <div class="plan-toolbar">
              <div class="plan-toolbar-fields">
                <div class="type-switch" role="radiogroup" aria-label="Typ náhledu">
                  <label><input type="radio" name="plan_type" value="daily" id="planTypeDaily" checked><span>Denní</span></label>
                  <label><input type="radio" name="plan_type" value="weekly" id="planTypeWeekly"><span>Týdenní</span></label>
                </div>
                <label><span id="planDateLabel">Den session</span><input type="date" name="plan_date" id="planDate" required></label>
                <label>Trh<input name="market" id="planMarket" list="marketOptions" required autocomplete="off" placeholder="ES, NQ, vlastní…"></label>
                <label>Typ obchodu<input name="session" id="planSession" list="sessionOptions" autocomplete="off" placeholder="Intraday, Hybrid Intraday…" title="Intraday je plánovaný pouze na dnešní den. Hybrid Intraday lze při příznivém vývoji držet déle."></label>
                <label>Stav<select name="status" id="planStatus"><option value="draft">Rozpracovaný</option><option value="ready">Připravený</option><option value="completed">Dokončený</option><option value="archived">Archivovaný</option></select></label>
              </div>
              <div class="toolbar-actions">
                <div class="plan-progress" id="planProgress" title="Jak kompletní je náhled"><span><i id="planProgressBar"></i></span><em id="planProgressText">0 %</em></div>
                <button class="button button-ghost" type="button" id="newPlan">Vyčistit</button>
                <button class="button button-ghost" type="button" id="exportPlanPdf"><?= icon('download') ?>PDF</button>
                <button class="button button-primary" type="submit">Uložit náhled</button>
              </div>
            </div>

            <nav class="plan-steps" id="planSteps" aria-label="Kroky náhledu">
              <button type="button" data-step="structure"><i></i>Bias</button>
              <button type="button" data-step="profile"><i></i>Profil</button>
              <button type="button" data-step="refs"><i></i>Reference</button>
              <button type="button" data-step="open"><i></i>Otevření</button>
              <button type="button" data-step="zones"><i></i>Zóny a levely</button>
              <button type="button" data-step="ideas"><i></i>Scénáře</button>
              <button type="button" data-step="bias"><i></i>Závěr a rizika</button>
            </nav>

            <section class="timing" id="planTiming" aria-live="polite">
              <div class="timing-copy">
                <p class="eyebrow" id="timingEyebrow">Příprava</p>
                <h2 id="timingTitle">Náhled</h2>
                <p id="timingText"></p>
              </div>
              <ol class="timeline" id="timingSteps"></ol>
            </section>

            <div class="plan-layout">
              <div class="plan-primary">
                <section class="plan-section" id="step-structure" data-section="structure">
                  <div class="section-heading"><div><p class="eyebrow">Krok 1 · Bias</p><h2>Bias z price action a z profilu</h2></div><span class="alignment" id="paAlignment">Doplň aspoň dva timeframy</span></div>
                  <?php
                  $biasGroups = [
                      ['pa', 'Price action', 'Struktura: HH/HL, BOS, range', ['monthly' => 'Monthly', 'weekly' => 'Weekly', 'daily' => 'Daily'], 'HH/HL, BOS, range…'],
                      ['mp', 'Market Profile / Volume Profile', 'Kam migruje value a kde je přijatá cena', ['weekly' => 'Weekly', 'daily' => 'Daily'], 'Value výš, přijetí nad VAH…'],
                  ];
                  ?>
                  <div class="bias-board">
                    <?php foreach ($biasGroups as [$prefix, $groupLabel, $groupHint, $frames, $placeholder]): ?>
                    <div class="bias-group">
                      <p class="subhead"><?= $groupLabel ?><small><?= $groupHint ?></small></p>
                      <?php foreach ($frames as $key => $label): ?>
                      <div class="pa-row">
                        <strong><?= $label ?></strong>
                        <div class="tri-switch" role="radiogroup" aria-label="<?= $groupLabel ?> <?= $label ?>">
                          <label class="is-long"><input type="radio" name="<?= $prefix ?>_<?= $key ?>" value="long"><span>Long</span></label>
                          <label class="is-balance"><input type="radio" name="<?= $prefix ?>_<?= $key ?>" value="balance"><span>Balance</span></label>
                          <label class="is-short"><input type="radio" name="<?= $prefix ?>_<?= $key ?>" value="short"><span>Short</span></label>
                        </div>
                        <input name="<?= $prefix ?>_<?= $key ?>_note" aria-label="Poznámka <?= $groupLabel ?> <?= $label ?>" placeholder="<?= $placeholder ?>">
                      </div>
                      <?php endforeach; ?>
                    </div>
                    <?php endforeach; ?>
                  </div>
                  <p class="section-hint" id="biasInheritHint">Klikni znovu na vybranou volbu a zrušíš ji. Denní náhled si Weekly bias sám převezme z týdenního náhledu.</p>
                </section>

                <section class="plan-section" id="step-profile" data-section="profile">
                  <div class="section-heading"><div><p class="eyebrow">Krok 2 · Market Profile</p><h2 id="profileHeading">Profil předchozího dne</h2></div><span id="profileHeadingNote">Jak se trh vypořádal s objemem</span></div>
                  <fieldset class="shape-picker">
                    <legend>Jaký profil se utvořil</legend>
                    <div class="shape-options">
                      <?php foreach ($shapes as [$value, $letter, $hint, $widths]): ?>
                      <label><input type="radio" name="profile_shape" value="<?= $value ?>"><span><?= profile_glyph($widths) ?><strong><?= $letter ?></strong><small><?= $hint ?></small></span></label>
                      <?php endforeach; ?>
                    </div>
                  </fieldset>

                  <div class="profile-columns">
                    <div>
                      <p class="subhead" id="refPricesTitle">Hodnoty předchozího dne</p>
                      <div class="price-grid">
                        <label>High<input type="number" step="any" name="ref_high" data-ref-price></label>
                        <label>VAH<input type="number" step="any" name="ref_vah" data-ref-price></label>
                        <label>POC<input type="number" step="any" name="ref_poc" data-ref-price></label>
                        <label>VAL<input type="number" step="any" name="ref_val" data-ref-price></label>
                        <label>Low<input type="number" step="any" name="ref_low" data-ref-price></label>
                        <label>Close<input type="number" step="any" name="ref_close" data-ref-price></label>
                      </div>
                      <button class="text-button" type="button" id="refPricesToLevels">Přenést hodnoty do levelů</button>
                    </div>
                    <fieldset class="ladder">
                      <legend>Kde trh zavřel vůči objemu</legend>
                      <label class="is-above"><input type="radio" name="previous_close" value="above"><span>Nad VAH</span></label>
                      <label class="is-upper"><input type="radio" name="previous_close" value="upper"><span>Horní polovina value</span></label>
                      <label class="is-poc"><input type="radio" name="previous_close" value="poc"><span>Na POC</span></label>
                      <label class="is-lower"><input type="radio" name="previous_close" value="lower"><span>Spodní polovina value</span></label>
                      <label class="is-below"><input type="radio" name="previous_close" value="below"><span>Pod VAL</span></label>
                      <label class="legacy-option" id="closeLegacyOption" hidden><input type="radio" name="previous_close" value="inside"><span>Uvnitř VA (starší záznam)</span></label>
                      <small class="ladder-hint" id="closeLadderHint">Vyplň Close, VAH, POC a VAL a poloha se doplní sama.</small>
                    </fieldset>
                  </div>

                  <div class="field-grid three">
                    <label>Migrace value<select name="value_area"><option value="">—</option><option value="rising">Výš (vyšší value)</option><option value="falling">Níž (nižší value)</option><option value="overlap">Překrývá se</option></select></label>
                    <label>Migrace POC<select name="vpoc"><option value="">—</option><option value="rising">Roste</option><option value="falling">Klesá</option><option value="stable">Stabilní</option></select></label>
                    <label>Stav aukce<select name="auction"><option value="">—</option><option value="balance">Balance</option><option value="imbalance">Imbalance</option><option value="unclear">Nejasná</option></select></label>
                    <label data-daily-only>Cena vůči týdenní VA<select name="weekly_position"><option value="">—</option><option value="inside">Uvnitř VA</option><option value="above">Nad VAH</option><option value="below">Pod VAL</option></select></label>
                    <label>Single prints (iniciativa)<select name="single_print"><option value="">Bez významných</option><option value="buy">Nákupní</option><option value="sell">Prodejní</option></select></label>
                    <label>Excess / tail<select name="tail"><option value="">Bez výrazného</option><option value="buy">Kupní (dole)</option><option value="sell">Prodejní (nahoře)</option></select></label>
                  </div>
                </section>

                <section class="plan-section" id="step-refs" data-section="refs">
                  <div class="section-heading"><div><p class="eyebrow">Krok 3 · Nedokončené aukce</p><h2>Reference na dojetí</h2></div><span>Místa, kam se trh často vrací</span></div>
                  <div class="chip-row" id="refQuickAdd">
                    <button class="chip chip-add" type="button" data-add-ref="single_print">+ Single prints</button>
                    <button class="chip chip-add" type="button" data-add-ref="poor_high">+ Poor high</button>
                    <button class="chip chip-add" type="button" data-add-ref="poor_low">+ Poor low</button>
                    <button class="chip chip-add" type="button" data-add-ref="naked_poc">+ Naked POC</button>
                    <button class="chip chip-add" type="button" data-add-ref="gap">+ Gap</button>
                    <button class="chip chip-add" type="button" data-add-ref="excess">+ Excess</button>
                    <button class="chip chip-add" type="button" data-add-ref="lvn">+ LVN</button>
                    <button class="chip chip-add" type="button" data-add-ref="other">+ Jiná</button>
                  </div>
                  <div class="stack" id="refList"></div>
                </section>

                <section class="plan-section" id="step-open" data-section="open">
                  <div class="section-heading"><div><p class="eyebrow">Krok 4 · Otevření</p><h2 id="openHeading">Otevření a první hodina</h2></div><span id="openHeadingNote">Pole se odemknou, až nastane jejich čas</span></div>
                  <div class="field-grid three gated-grid">
                    <label class="gated" data-gate="globex"><span class="gate-label">Otevření Globexu <em data-gate-note></em></span><select name="globex_open"><?= $vaOptions ?></select></label>
                    <label class="gated" data-gate="eu"><span class="gate-label">EU open <em data-gate-note></em></span><select name="eu_open"><?= $vaOptions ?></select></label>
                    <label class="gated" data-gate="rth"><span class="gate-label">RTH open <em data-gate-note></em></span><select name="ny_open"><?= $vaOptions ?></select></label>
                    <label class="gated" data-gate="rth"><span class="gate-label">Typ otevření <em data-gate-note></em></span><select name="open_type"><option value="">—</option><option value="drive">Open Drive</option><option value="test_drive">Open Test Drive</option><option value="rejection_reverse">Open Rejection Reverse</option><option value="auction_in">Open Auction uvnitř range</option><option value="auction_out">Open Auction mimo range</option></select></label>
                    <label class="gated" data-gate="ib"><span class="gate-label">Initial Balance <em data-gate-note></em></span><select name="initial_balance"><option value="">—</option><option value="small">Malá</option><option value="normal">Běžná</option><option value="large_drive">Velká - drive</option><option value="large_rotation">Velká - rotace</option></select></label>
                  </div>
                </section>

                <section class="plan-section" id="step-zones" data-section="zones">
                  <div class="section-heading"><div><p class="eyebrow">Krok 5 · Lokace</p><h2>Obchodní zóny</h2></div><div class="section-actions"><button class="text-button" type="button" id="exportTradingViewZones">TradingView export</button><button class="button button-small" type="button" id="addZone"><?= icon('plus') ?>Zóna</button></div></div>
                  <div class="weekly-context" id="weeklyContext"></div>
                  <div class="stack" id="zoneList"></div>
                  <div class="subsection">
                    <div class="section-heading"><div><h3>Klíčové levely</h3></div><button class="button button-small" type="button" id="addLevel"><?= icon('plus') ?>Level</button></div>
                    <div class="stack" id="levelList"></div>
                  </div>
                </section>

                <section class="plan-section" id="step-ideas" data-section="ideas">
                  <div class="section-heading"><div><p class="eyebrow">Krok 6 · Scénáře</p><h2>Potenciální obchody a TP</h2></div><button class="button button-small" type="button" id="addIdea"><?= icon('plus') ?>Scénář</button></div>
                  <div class="stack" id="ideaList"></div>
                </section>

                <section class="plan-section chart-section">
                  <div class="section-heading"><div><p class="eyebrow">Kontext</p><h2>Screenshoty grafu</h2></div><span>PNG, JPEG nebo WebP · max. 20 MB</span></div>
                  <label class="dropzone" id="planDropzone">
                    <input type="file" id="planScreenshot" accept="image/png,image/jpeg,image/webp" multiple>
                    <strong>Přetáhni screenshot grafu</strong>
                    <span>nebo klikni a vyber soubor</span>
                  </label>
                  <div class="screenshot-grid" id="planScreenshots"></div>
                </section>
              </div>

              <aside class="plan-inspector">
                <section class="inspector-block bias-block" id="step-bias" data-section="bias">
                  <div class="section-heading"><div><p class="eyebrow">Krok 7 · Pracovní hypotéza</p><h2 id="biasHeading">Bias</h2></div></div>
                  <div class="segmented"><label><input type="radio" name="bias" value="long"><span>Long</span></label><label><input type="radio" name="bias" value="neutral" checked><span>Balance</span></label><label><input type="radio" name="bias" value="short"><span>Short</span></label></div>
                  <div class="working-conclusion"><span>Pracovní závěr</span><p id="workingConclusion">Doplň strukturu, profil a migraci value.</p></div>
                  <label>Popis biasu<textarea name="bias_description" rows="4" placeholder="Co trh aktuálně přijímá, kde je iniciativa a co je primární scénář…"></textarea></label>
                  <label>Co bias potvrzuje<textarea name="bias_confirm" rows="2" placeholder="Developing value, open, iniciativa, reakce v zóně…"></textarea></label>
                  <label>Co bias ruší<textarea name="bias_invalidation" rows="2" placeholder="Návrat do value, odmítnutí, selhání struktury…"></textarea></label>
                </section>

                <section class="inspector-block">
                  <div class="section-heading"><div><p class="eyebrow">Mapa ceny</p><h2>Zóny, levely a reference</h2></div></div>
                  <div class="price-map" id="priceMap"></div>
                </section>

                <section class="inspector-block">
                  <div class="section-heading"><div><p class="eyebrow">Riziko</p><h2>Filtry</h2></div></div>
                  <label>Red news<textarea name="important_news" rows="2" placeholder="Čas a událost…"></textarea></label>
                  <label>No-trade podmínky<textarea name="no_trade_conditions" rows="3" placeholder="Kdy nevstupuji…"></textarea></label>
                  <label>Poznámky<textarea name="general_notes" rows="3" placeholder="Další kontext a reference…"></textarea></label>
                </section>
              </aside>
            </div>
          </form>
        </section>

        <!-- DENÍK -->
        <section class="view" id="view-journal">
          <div class="view-toolbar"><div class="inline-fields"><label>Trh<select id="tradeMarketFilter"><option value="">Všechny</option><option>ES</option><option>NQ</option><option>GC</option><option>CL</option><option>6E</option></select></label><label>Hledat<input type="search" id="tradeSearch" placeholder="Setup nebo poznámka"></label></div><button class="button button-primary" type="button" id="addTrade"><?= icon('plus') ?>Přidat obchod</button></div>
          <section class="surface table-surface"><div class="table-wrap"><table><thead><tr><th>Datum</th><th>Trh</th><th>Setup</th><th>Směr</th><th class="num">Entry / Exit</th><th class="num">R</th><th class="num">P&amp;L</th><th>Plán</th><th></th></tr></thead><tbody id="tradeTable"></tbody></table></div></section>
        </section>

        <!-- HISTORIE NÁHLEDŮ -->
        <section class="view" id="view-archive">
          <div class="view-toolbar"><div class="inline-fields"><label>Trh<select id="planMarketFilter"><option value="">Všechny</option><option>ES</option><option>NQ</option><option>GC</option><option>CL</option><option>6E</option></select></label><label>Typ<select id="planTypeFilter"><option value="">Denní i týdenní</option><option value="daily">Denní</option><option value="weekly">Týdenní</option></select></label></div></div>
          <div class="archive-list" id="planArchive"></div>
        </section>

        <!-- STRATEGIE -->
        <section class="view" id="view-strategies">
          <div class="metric-strip four">
            <article><span>Nejlepší strategie</span><strong id="strategyBest">—</strong><small id="strategyBestDetail">zatím bez dat</small></article>
            <article><span>Nejhorší strategie</span><strong id="strategyWorst">—</strong><small id="strategyWorstDetail">zatím bez dat</small></article>
            <article><span>Aktivních setupů</span><strong id="strategyCount">0</strong><small>se zapsaným obchodem</small></article>
            <article><span>Obchodů bez strategie</span><strong id="strategyOrphans">0</strong><small>nejdou vyhodnotit</small></article>
          </div>
          <section class="surface equity-surface">
            <div class="section-heading"><div><p class="eyebrow">Vývoj v čase</p><h2>Kumulativní R podle strategie</h2></div><span id="strategyChartCaption">Bez dat</span></div>
            <div class="equity-chart" id="strategyChart" aria-label="Vývoj strategií v čase"></div>
            <div class="chart-legend" id="strategyLegend"></div>
          </section>
          <section class="surface table-surface">
            <div class="section-heading"><div><p class="eyebrow">Srovnání</p><h2>Výkonnost podle setupu</h2></div><span>Řazeno podle celkového R</span></div>
            <div class="table-wrap"><table><thead><tr><th>Strategie</th><th>Timeframe</th><th>Charakter</th><th class="num">Obchodů</th><th class="num">Celkem R</th><th class="num">Průměr R</th><th class="num">Profit factor</th><th class="num">Plán</th><th class="num">Exekuce</th><th>Poslední</th><th></th></tr></thead><tbody id="strategyTable"></tbody></table></div>
          </section>
        </section>

        <!-- KALENDÁŘ -->
        <section class="view" id="view-calendar">
          <div class="view-toolbar">
            <div class="calendar-nav"><button class="icon-button" type="button" id="calendarPrev" aria-label="Předchozí měsíc">‹</button><strong id="calendarLabel">—</strong><button class="icon-button" type="button" id="calendarNext" aria-label="Další měsíc">›</button><button class="button button-ghost button-small" type="button" id="calendarToday">Dnes</button></div>
            <div id="calendarSummary" class="calendar-summary"></div>
          </div>
          <div class="calendar-legend"><span><i class="calendar-mark is-plan">N</i>denní náhled</span><span><i class="calendar-mark is-week">T</i>týdenní náhled</span><span><i class="calendar-mark is-green">P</i>test psychiky</span><span><i class="calendar-mark is-broken">!</i>porušená pravidla</span></div>
          <div class="calendar-weekdays"><span>Po</span><span>Út</span><span>St</span><span>Čt</span><span>Pá</span><span>So</span><span>Ne</span></div>
          <div class="calendar-grid" id="calendarGrid"></div>
          <p class="section-hint">Klikni na den a můžeš k němu přidat red news, svátek nebo poznámku. Pokud na ten den existuje náhled, otevřeš ho přímo odtud.</p>
        </section>

        <!-- PSYCHIKA -->
        <section class="view" id="view-psyche">
          <section class="surface psyche-block">
            <div class="section-heading"><div><p class="eyebrow">Jednorázově</p><h2>Vstupní profil</h2></div><div class="view-actions"><button class="button button-ghost" type="button" id="editPsychRules">Pravidla pro špatný den</button><button class="button button-primary" type="button" id="startPsychProfile">Vyplnit profil</button></div></div>
            <div id="profileSummary" class="empty-state compact">Profil zatím není vyplněný. Šestnáct otázek odhalí, kde máš silné a kde slabé místo, a rychlý test se pak zaměří přesně na ně.</div>
          </section>

          <section class="surface psyche-block">
            <div class="section-heading"><div><p class="eyebrow">Před session</p><h2>Rychlý test psychiky</h2></div><button class="button button-primary" type="button" id="startPsychCheck">Spustit test</button></div>
            <div id="psychLatest" class="empty-state compact">Test zatím nebyl vyplněný. Zabere minutu a řekne ti, jestli dnes obchodovat.</div>
            <p class="disclaimer">Tohle není psychologická diagnostika ani terapie. Je to strukturovaný check-list tvého aktuálního stavu, postavený na faktorech, které měřitelně ovlivňují exekuci. Pokud dlouhodobě řešíš úzkost, nespavost nebo tlak, který přesahuje trading, patří to k odborníkovi, ne do deníku.</p>
          </section>

          <section class="surface psyche-block">
            <div class="section-heading"><div><p class="eyebrow">Ověření na tvých datech</p><h2>Kalibrace testu</h2></div><span id="calibrationSample"></span></div>
            <div id="calibrationBody" class="empty-state compact">Načítám…</div>
          </section>

          <section class="surface psyche-block">
            <div class="section-heading"><div><p class="eyebrow">Rozbor chyb</p><h2>Dny, kdy se pravidla porušila</h2></div><span id="disciplineSummary"></span></div>
            <div class="discipline-layout">
              <div class="discipline-list" id="disciplineDays"></div>
              <div class="discipline-detail" id="disciplineDetail"><div class="empty-state compact">Vyber den vlevo a ukážu ti, co mu předcházelo.</div></div>
            </div>
          </section>

          <section class="surface psyche-block">
            <div class="section-heading"><div><p class="eyebrow">Spouštěče</p><h2>Emoce u porušených pravidel</h2></div><span>Porovnání s obchody podle plánu</span></div>
            <div id="disciplineEmotions" class="empty-state compact">Zatím není co porovnávat.</div>
          </section>
        </section>

        <!-- ÚČTY -->
        <section class="view" id="view-accounts">
          <div class="view-toolbar"><div class="inline-fields"><label>Účet<select id="auditAccountFilter"><option value="">Všechny</option></select></label></div><div class="view-actions"><button class="button button-ghost" type="button" id="addAccount"><?= icon('plus') ?>Přidat účet</button><button class="button button-primary" type="button" id="openAudit">Money audit</button></div></div>
          <div class="account-list" id="accountList"></div>
          <section class="surface table-surface">
            <div class="section-heading"><div><p class="eyebrow">Historie kontrol</p><h2>Provedené Money audity</h2></div><span id="auditIntervalNote">Audit se rozsvítí každých 30 dní.</span></div>
            <div class="table-wrap"><table><thead><tr><th>Datum</th><th>Účet</th><th class="num">Nahlášeno</th><th class="num">Očekáváno</th><th class="num">Rozdíl</th><th>Evidence</th><th class="num">Měsíc</th><th></th></tr></thead><tbody id="auditTable"></tbody></table></div>
          </section>
        </section>

        <!-- ZÁLOHA -->
        <section class="view" id="view-backup">
          <div class="backup-layout">
            <section class="surface backup-intro"><p class="eyebrow">Kompletní záloha</p><h2>SQLite + všechny screenshoty</h2><p>ZIP obsahuje konzistentní kopii databáze, obrázky a manifest. Ulož jej také mimo disk počítače.</p><a class="button button-primary" href="backup.php"><?= icon('download') ?>Stáhnout kompletní ZIP</a></section>
            <section class="surface backup-intro"><p class="eyebrow">Přenositelný export</p><h2>Data ve formátu JSON</h2><p>Vhodné pro další analýzu, audit nebo budoucí migraci bez screenshotů.</p><a class="button button-ghost" href="api.php?action=export" download="trading-export.json"><?= icon('download') ?>Stáhnout JSON</a></section>
          </div>
          <section class="surface backup-notes"><h2>Kam se data ukládají</h2><dl><div><dt>Databáze</dt><dd><code>data/trading.sqlite3</code></dd></div><div><dt>Screenshoty</dt><dd><code>data/uploads/</code></dd></div><div><dt>Adresa aplikace</dt><dd><code>/trading/</code></dd></div></dl><p>Adresář <code>data</code> je webově uzavřený; screenshoty se zobrazují pouze přes kontrolovaný PHP endpoint.</p></section>
        </section>
      </main>
    </div>
  </div>

  <datalist id="marketOptions"></datalist>
  <datalist id="sessionOptions"></datalist>
  <datalist id="zoneNameOptions"></datalist>

  <dialog class="modal modal-wide" id="tradeDialog">
    <form method="dialog" id="tradeForm">
      <input type="hidden" name="id">
      <input type="hidden" name="plan_id">
      <div class="modal-head"><div><p class="eyebrow">Deník</p><h2 id="tradeDialogTitle">Přidat obchod</h2></div><button class="icon-button" value="cancel" aria-label="Zavřít">×</button></div>
      <fieldset class="form-section">
        <legend>Obchod</legend>
        <div class="field-grid four">
          <label>Datum<input type="date" name="trade_date" required></label>
          <label>Trh<input name="market" id="tradeMarket" list="marketOptions" required autocomplete="off" placeholder="ES, NQ, vlastní…"></label>
          <label>Účet<select name="account_id" id="tradeAccount"><option value="">—</option></select></label>
          <label>Typ obchodu<input name="session" id="tradeSession" list="sessionOptions" autocomplete="off" placeholder="Intraday, Hybrid Intraday…" title="Intraday je plánovaný pouze na dnešní den. Hybrid Intraday lze při příznivém vývoji držet déle."></label>
          <label>Směr<select name="direction"><option value="long">Long</option><option value="short">Short</option></select></label>
          <label class="field-with-action span-3">Strategie / setup<span><select name="strategy_id" id="tradeStrategy"><option value="">—</option><option value="__new">+ Přidat strategii / setup</option></select><button class="mini-button" type="button" id="editTradeStrategy" title="Upravit vybranou strategii">Upravit</button></span></label>
        </div>
      </fieldset>
      <fieldset class="form-section">
        <legend>Ceny a riziko</legend>
        <div class="field-grid four">
          <label>Entry<input type="number" step="any" name="entry_price"></label>
          <label>Exit<input type="number" step="any" name="exit_price"></label>
          <label>Stop loss<input type="number" step="any" name="stop_loss"></label>
          <label>Plánovaný TP<input type="number" step="any" name="target_price"></label>
          <label>Risk na trade ($)<input type="number" step="any" min="0" name="risk_amount" placeholder="např. 500"></label>
          <label>Poplatky ($)<input type="number" step="0.01" name="fees" value="0"></label>
          <label>Výsledek R<input type="number" step="0.01" name="result_r" placeholder="dopočítá se"></label>
          <label>Výsledek $<input type="number" step="0.01" name="result_usd" placeholder="dopočítá se"></label>
        </div>
        <p class="calc-hint" id="tradeCalcHint">Doplň entry, stop a risk na trade; velikost pozice, čisté P&amp;L i R se dopočítají.</p>
      </fieldset>
      <fieldset class="form-section">
        <legend>Hodnocení</legend>
        <div class="field-grid two">
          <label>Dodržen plán<select name="followed_plan"><option value="">—</option><option value="1">Ano</option><option value="0">Ne</option></select></label>
          <label>Hodnocení obchodu<select name="execution_rating"><option value="">—</option><option value="1">1 · Vše splněno podle plánu</option><option value="2">2 · Drobná odchylka od plánu</option><option value="3">3 · Částečně dodržená pravidla</option><option value="4">4 · Výrazné porušení pravidel</option><option value="5">5 · Nebyla dodržena pravidla pro exekuci</option></select></label>
        </div>
      </fieldset>
      <fieldset class="checkbox-field">
        <legend>Emoce během obchodu <span>můžeš označit více stavů</span></legend>
        <div class="checkbox-grid" id="tradeEmotions">
          <label><input type="checkbox" data-emotion value="klid"><span>Klidný a soustředěný</span></label>
          <label><input type="checkbox" data-emotion value="disciplinovana_trpelivost"><span>Disciplinovaná trpělivost</span></label>
          <label><input type="checkbox" data-emotion value="lehka_nervozita"><span>Lehká nervozita</span></label>
          <label><input type="checkbox" data-emotion value="netrpelivost"><span>Netrpělivost, nucený vstup</span></label>
          <label><input type="checkbox" data-emotion value="nuda"><span>Nuda a potřeba akce</span></label>
          <label><input type="checkbox" data-emotion value="vahavost"><span>Váhavost a pochybnosti o systému</span></label>
          <label><input type="checkbox" data-emotion value="strach_ze_ztraty"><span>Strach ze ztráty</span></label>
          <label><input type="checkbox" data-emotion value="fomo"><span>Strach z promeškání (FOMO)</span></label>
          <label><input type="checkbox" data-emotion value="chamtivost"><span>Chamtivost, držení přes plán</span></label>
          <label><input type="checkbox" data-emotion value="prehnane_sebevedomi"><span>Přehnané sebevědomí po sérii zisků</span></label>
          <label><input type="checkbox" data-emotion value="revenge"><span>Frustrace a snaha o revenge trade</span></label>
          <label><input type="checkbox" data-emotion value="externi_vlivy"><span>Rozrušený z externích vlivů</span></label>
          <label><input type="checkbox" data-emotion value="unava"><span>Únava a nesoustředěnost</span></label>
          <label><input type="checkbox" data-emotion value="uleva"><span>Úleva po uzavření pozice</span></label>
        </div>
      </fieldset>
      <div class="field-grid two notes-grid">
        <label>Chyba / odchylka<textarea name="mistake" rows="3"></textarea></label>
        <label>Poznámka a poučení<textarea name="notes" rows="3"></textarea></label>
      </div>
      <label class="trade-upload">Screenshoty obchodu<input type="file" id="tradeScreenshots" accept="image/png,image/jpeg,image/webp" multiple><span id="tradeScreenshotNames">Volitelně přidej entry, exit nebo výsledný graf.</span></label>
      <div class="modal-actions"><button class="button button-ghost button-calc" type="button" id="calcTradeResult">Vypočítat a doplnit R a výsledek</button><span class="spacer"></span><button class="button button-ghost" value="cancel">Zrušit</button><button class="button button-primary" type="submit" value="default">Uložit obchod</button></div>
    </form>
  </dialog>

  <dialog class="modal" id="strategyDialog" aria-labelledby="strategyDialogTitle">
    <form method="dialog" id="strategyForm">
      <input type="hidden" name="id">
      <div class="modal-head"><div><p class="eyebrow">Systém</p><h2 id="strategyDialogTitle">Přidat strategii / setup</h2></div><button class="icon-button" value="cancel" type="submit" formnovalidate aria-label="Zavřít">×</button></div>
      <div class="field-grid three">
        <label>Název strategie<input name="name" required placeholder="30BOS, F7 rejection…"></label>
        <label>Timeframe<input name="timeframe" list="strategyTimeframes" placeholder="M5 exekuce / H1 kontext"></label>
        <label>Charakter systému<select name="style"><option value="">—</option><option value="trend">Trendový</option><option value="reversal">Reversal</option><option value="both">Trendový i reversal</option></select></label>
      </div>
      <datalist id="strategyTimeframes">
        <option value="M1"></option><option value="M2"></option><option value="M3"></option><option value="M5"></option><option value="M15"></option><option value="M30"></option><option value="H1"></option><option value="H4"></option><option value="D1"></option><option value="W1"></option>
        <option value="M1 exekuce / M15 kontext"></option><option value="M5 exekuce / H1 kontext"></option><option value="M15 exekuce / H4 kontext"></option>
      </datalist>
      <label>Pravidla a poznámky<textarea name="notes" rows="4" placeholder="Podmínky vstupu, invalidace, řízení pozice…"></textarea></label>
      <label class="trade-upload">Screenshoty strategie<input type="file" id="strategyScreenshots" accept="image/png,image/jpeg,image/webp" multiple><span id="strategyScreenshotNames">Vlož ukázkové grafy setupu.</span></label>
      <div class="screenshot-grid" id="strategyGallery"></div>
      <div class="modal-actions"><button class="button button-ghost danger" type="button" id="deleteStrategy" hidden>Smazat strategii</button><span class="spacer"></span><button class="button button-ghost" value="cancel" type="submit" formnovalidate>Zrušit</button><button class="button button-primary" type="submit" value="default">Uložit strategii</button></div>
    </form>
  </dialog>

  <dialog class="modal" id="dayDialog" aria-labelledby="dayDialogTitle">
    <form method="dialog" id="dayForm">
      <input type="hidden" name="event_date">
      <div class="modal-head"><div><p class="eyebrow">Den</p><h2 id="dayDialogTitle">Detail dne</h2></div><button class="icon-button" value="cancel" type="submit" formnovalidate aria-label="Zavřít">×</button></div>
      <div id="dayOverview"></div>
      <div id="dayEvents" class="day-events"></div>
      <div class="field-grid three">
        <label>Typ<select name="kind"><option value="news">Red news</option><option value="holiday">Svátek</option><option value="note">Poznámka</option></select></label>
        <label>Čas<input name="time_label" placeholder="14:30"></label>
        <label>Dopad<select name="impact"><option value="high">Vysoký</option><option value="medium">Střední</option><option value="low">Nízký</option><option value="">—</option></select></label>
      </div>
      <label>Název<input name="title" placeholder="NFP, CPI, FOMC, Den díkůvzdání…"></label>
      <label>Poznámka<textarea name="notes" rows="2" placeholder="Co to znamená pro obchodování toho dne."></textarea></label>
      <div class="modal-actions"><button class="button button-ghost" type="button" id="dayOpenPlan" hidden>Otevřít náhled</button><span class="spacer"></span><button class="button button-ghost" value="cancel" type="submit" formnovalidate>Zavřít</button><button class="button button-primary" type="submit" value="default">Přidat do dne</button></div>
    </form>
  </dialog>

  <dialog class="modal" id="psychDialog" aria-labelledby="psychDialogTitle">
    <form method="dialog" id="psychForm">
      <div class="modal-head"><div><p class="eyebrow">Minuta před otevřením grafu</p><h2 id="psychDialogTitle">Rychlý test psychiky</h2></div><button class="icon-button" value="cancel" type="submit" formnovalidate aria-label="Zavřít">×</button></div>
      <p class="psych-progress" id="psychProgress">Otázka 1</p>
      <div class="psych-step" id="psychStep">
        <h3 id="psychQuestionText"></h3>
        <div class="psych-options" id="psychOptions"></div>
        <span class="psych-seconds" id="psychSeconds"></span>
        <div class="psych-timer"><i id="psychTimerBar"></i></div>
      </div>
      <p class="psych-hint">Odpověz první reakcí. Zpět se vrátit nejde. Když čas vyprší, otázka se přeskočí a počítá se jako mírná odpověď. Volit můžeš i klávesami 1 až 4.</p>
      <div class="modal-actions"><button class="button button-ghost" value="cancel" type="submit" formnovalidate>Zrušit test</button></div>
    </form>
  </dialog>

  <dialog class="modal" id="profileDialog" aria-labelledby="profileDialogTitle">
    <form method="dialog" id="profileForm">
      <div class="modal-head"><div><p class="eyebrow">Vstupní profil</p><h2 id="profileDialogTitle">Jak obchoduješ</h2></div><button class="icon-button" value="cancel" type="submit" formnovalidate aria-label="Zavřít">×</button></div>
      <p class="psych-progress" id="profileProgress">Otázka 1</p>
      <div class="psych-step">
        <h3 id="profileQuestionText"></h3>
        <div class="psych-options" id="profileOptions"></div>
      </div>
      <p class="psych-hint">Tady se nespěchá a vrátit se můžeš. Odpovídej podle toho, jak to opravdu je, ne jak by to mělo být. Profil slouží jen tobě.</p>
      <div class="modal-actions"><button class="button button-ghost" type="button" id="profileBack">Zpět</button><span class="spacer"></span><button class="button button-ghost" value="cancel" type="submit" formnovalidate>Zrušit</button></div>
    </form>
  </dialog>

  <dialog class="modal" id="rulesDialog" aria-labelledby="rulesDialogTitle">
    <form method="dialog" id="rulesForm">
      <div class="modal-head"><div><p class="eyebrow">Omezení rizika</p><h2 id="rulesDialogTitle">Co pro mě znamená špatný den</h2></div><button class="icon-button" value="cancel" type="submit" formnovalidate aria-label="Zavřít">×</button></div>
      <p class="calc-hint">Nadefinuj si předem, co se stane, když test skončí oranžově nebo červeně. Rozhoduješ o tom teď, v klidu, ne ráno pod tlakem. Výsledek testu ti pak tahle pravidla rovnou ukáže.</p>
      <div id="rulesEditor" class="rules-editor"></div>
      <div class="modal-actions"><button class="button button-ghost" value="cancel" type="submit" formnovalidate>Zrušit</button><button class="button button-primary" type="submit" value="default">Uložit pravidla</button></div>
    </form>
  </dialog>

  <dialog class="modal" id="psychResultDialog" aria-labelledby="psychResultTitle">
    <form method="dialog">
      <div class="modal-head"><div><p class="eyebrow" id="psychResultBadge">Výsledek</p><h2 id="psychResultTitle">Vyhodnocení</h2></div><button class="icon-button" value="cancel" type="submit" aria-label="Zavřít">×</button></div>
      <div id="psychResultBody"></div>
      <div class="modal-actions"><button class="button button-primary" value="default" type="submit">Rozumím</button></div>
    </form>
  </dialog>

  <dialog class="modal" id="accountDialog" aria-labelledby="accountDialogTitle">
    <form method="dialog" id="accountForm">
      <div class="modal-head"><div><p class="eyebrow">Účet</p><h2 id="accountDialogTitle">Přidat obchodní účet</h2></div><button class="icon-button" value="cancel" type="submit" formnovalidate aria-label="Zavřít">×</button></div>
      <div class="field-grid three">
        <label>Název účtu<input name="name" required placeholder="Apex 50k, Live IBKR…"></label>
        <label>Broker nebo prop firma<input name="broker" placeholder="Volitelné"></label>
        <label>Měna<select name="currency"><option>USD</option><option>EUR</option><option>CZK</option></select></label>
        <label>Aktuální stav konta<input type="number" step="0.01" min="0" name="starting_balance" required placeholder="50000"></label>
        <label>Risk na den<input type="number" step="0.01" min="0" name="daily_risk" placeholder="např. 500"></label>
        <label>Začátek evidence<input type="date" name="opened_at" required></label>
      </div>
      <p class="calc-hint">Účet po uložení nelze upravovat. Stav konta a risk na den jsou pevný referenční bod, proti kterému Money audit počítá očekávaný zůstatek. Smazat lze pouze účet bez jediného obchodu a auditu. Začátek evidence určuje, od kdy se do účtu započítávají obchody; prvních 30 dní do Money auditu se počítá vždy ode dneška.</p>
      <div class="modal-actions"><button class="button button-ghost" value="cancel" type="submit" formnovalidate>Zrušit</button><button class="button button-primary" type="submit" value="default">Uložit účet</button></div>
    </form>
  </dialog>

  <dialog class="modal" id="auditDialog" aria-labelledby="auditDialogTitle">
    <form method="dialog" id="auditForm">
      <div class="modal-head"><div><p class="eyebrow">Kontrola evidence</p><h2 id="auditDialogTitle">Money audit</h2></div><button class="icon-button" value="cancel" type="submit" formnovalidate aria-label="Zavřít">×</button></div>
      <div class="field-grid three">
        <label>Účet<select name="account_id" id="auditAccount" required></select></label>
        <label>Datum auditu<input type="date" name="audit_date" required></label>
        <label>Aktuální stav konta<input type="number" step="0.01" name="reported_balance" required placeholder="Opiš z platformy"></label>
      </div>
      <label class="trade-upload">Screenshot stavu konta<input type="file" id="auditScreenshot" accept="image/png,image/jpeg,image/webp" required><span id="auditScreenshotName">Povinný doklad. Nahraj obrazovku účtu nebo výpis od brokera.</span></label>
      <label>Poznámka<textarea name="notes" rows="2" placeholder="Vklady, výběry nebo jiné pohyby, které nejsou obchody."></textarea></label>
      <p class="calc-hint">Zůstatek opiš přímo z platformy. Očekávaný stav z deníku se ti ukáže až po vyhodnocení, aby kontrola dávala smysl.</p>
      <div class="modal-actions"><button class="button button-ghost" value="cancel" type="submit" formnovalidate>Zrušit</button><button class="button button-primary" type="submit" value="default">Vyhodnotit audit</button></div>
    </form>
  </dialog>

  <dialog class="modal" id="auditResultDialog" aria-labelledby="auditResultTitle">
    <form method="dialog">
      <div class="modal-head"><div><p class="eyebrow" id="auditResultBadge">Výsledek</p><h2 id="auditResultTitle">Vyhodnocení Money auditu</h2></div><button class="icon-button" value="cancel" type="submit" aria-label="Zavřít">×</button></div>
      <div id="auditResultBody"></div>
      <div class="modal-actions"><button class="button button-primary" value="default" type="submit">Zavřít</button></div>
    </form>
  </dialog>

  <dialog class="modal tv-export-dialog" id="tradingViewDialog" aria-labelledby="tradingViewDialogTitle">
    <div class="tv-export-shell">
      <div class="modal-head">
        <div><p class="eyebrow">TradingView</p><h2 id="tradingViewDialogTitle">Export zón, levelů a referencí</h2></div>
        <button class="icon-button" type="button" id="closeTradingViewDialog" aria-label="Zavřít">×</button>
      </div>
      <div class="tv-export-meta" aria-label="Souhrn exportu">
        <div><span>Trh</span><strong id="tvExportMarket">ES</strong></div>
        <div><span>Platné zóny</span><strong id="tvExportCount">0</strong></div>
        <div><span>Levely</span><strong id="tvExportLevelCount">0</strong></div>
        <div><span>Formát</span><strong>Pine Script v6</strong></div>
      </div>
      <div class="tv-export-settings">
        <label class="toggle-control"><input type="checkbox" id="tvShowLabels" checked><span><strong>Popisky vpravo od ceny</strong><small>Název bude několik barů napravo od aktuální ceny a výškově uprostřed zóny.</small></span></label>
        <label class="toggle-control"><input type="checkbox" id="tvIncludeSource"><span><strong>Přidat zdroj / shodu</strong><small>Do popisku vloží také MP, VP nebo DiNapoli konfluenci.</small></span></label>
        <label class="toggle-control"><input type="checkbox" id="tvIncludeRefs" checked><span><strong>Hodnoty profilu a reference</strong><small>VAH, POC, VAL, high a low z profilu a otevřené reference na dojetí.</small></span></label>
        <label>Prodloužení zón<select id="tvExtendMode"><option value="right">Doprava</option><option value="both">Oběma směry</option></select></label>
        <label class="range-control">Průhlednost výplně<div><input type="range" id="tvFillTransparency" min="60" max="96" value="86"><output id="tvFillTransparencyValue" for="tvFillTransparency">86</output></div></label>
      </div>
      <p class="tv-export-status" id="tvExportStatus">Vyplň u zón spodní a horní hranici.</p>
      <label class="tv-code-field">Vygenerovaný Pine Script<textarea id="tvPineCode" rows="18" readonly spellcheck="false" aria-label="Vygenerovaný Pine Script"></textarea></label>
      <p class="tv-export-help">V TradingView otevři Pine Editor, vlož kód, ulož jej a zvol <strong>Add to chart</strong>. Hranice, viditelnost i délku zón potom upravíš v nastavení indikátoru.</p>
      <div class="modal-actions tv-export-actions"><button class="button button-ghost" type="button" id="regenerateTradingViewZones">Obnovit kód</button><button class="button button-ghost" type="button" id="downloadTradingViewZones">Stáhnout .pine</button><button class="button button-primary" type="button" id="copyTradingViewZones">Kopírovat kód</button></div>
    </div>
  </dialog>

  <dialog class="lightbox" id="lightbox"><button class="icon-button" id="closeLightbox" aria-label="Zavřít">×</button><img id="lightboxImage" alt="Screenshot grafu"><p id="lightboxCaption"></p></dialog>
  <div class="toast" id="toast" role="status" aria-live="polite"></div>
  <script src="<?= asset_url('static/tradingview-export.js') ?>" defer></script>
  <script src="<?= asset_url('static/app.js') ?>" defer></script>
</body>
</html>
