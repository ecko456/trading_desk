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
        'key' => '<circle cx="8" cy="15" r="4"/><path d="M11 12l8.5-8.5M16.5 7l2.5 2.5M14.5 9l2 2"/>',
        'wall' => '<path d="M4 5.5A1.5 1.5 0 0 1 5.5 4h13A1.5 1.5 0 0 1 20 5.5v9a1.5 1.5 0 0 1-1.5 1.5H10l-4.5 4v-4h0A1.5 1.5 0 0 1 4 14.5z"/><path d="M8 8.5h8M8 12h5"/>',
        'shield' => '<path d="M12 3.5l7 2.8v5.2c0 4.4-3 7.7-7 9-4-1.3-7-4.6-7-9V6.3z"/><path d="m9 12 2.2 2.2L15.5 10"/>',
        'users' => '<circle cx="9" cy="8.5" r="3.2"/><path d="M3.5 19.5a5.5 5.5 0 0 1 11 0"/><circle cx="17" cy="9.5" r="2.5"/><path d="M15.5 14.3a4.5 4.5 0 0 1 5 5.2"/>',
        'logout' => '<path d="M14 4.5H6.5A1.5 1.5 0 0 0 5 6v12a1.5 1.5 0 0 0 1.5 1.5H14"/><path d="M10 12h10M16.5 8.5 20 12l-3.5 3.5"/>',
        'share' => '<circle cx="17.5" cy="6" r="2.5"/><circle cx="6.5" cy="12" r="2.5"/><circle cx="17.5" cy="18" r="2.5"/><path d="m8.7 10.8 6.6-3.6M8.7 13.2l6.6 3.6"/>',
        'comment' => '<path d="M5 17.5 3.5 21l4.2-1.8A8.5 8.5 0 1 0 5 17.5z"/>',
        'image' => '<rect x="3.5" y="4.5" width="17" height="15" rx="2"/><circle cx="9" cy="10" r="1.8"/><path d="m20.5 16-5-5-8 8.5"/>',
        'send' => '<path d="M4 12 20 4l-4.5 16-3.5-6.5z"/><path d="m12 13.5 8-9.5"/>',
        'user' => '<circle cx="12" cy="8.5" r="3.8"/><path d="M4.5 20a7.5 7.5 0 0 1 15 0"/>',
        'trash' => '<path d="M4.5 7h15M9.5 7V4.5h5V7M6.5 7l1 12.5h9l1-12.5"/>',
        'sliders' => '<path d="M4 6.5h9M17 6.5h3M4 12h3M11 12h9M4 17.5h11M19 17.5h1"/><circle cx="15" cy="6.5" r="2"/><circle cx="9" cy="12" r="2"/><circle cx="17" cy="17.5" r="2"/>',
        'fib' => '<path d="M3.5 19.5h17"/><path d="M4 18 11 5l5 8 4-4"/><path d="M3.5 9.5h17M3.5 13.5h17" stroke-dasharray="2 2.5"/>',
        'hindsight' => '<path d="M3.5 18.5h17"/><path d="M6 15V9M6 11h0M10 16V6M14 14V8M18 12V5"/><path d="M4.5 5.5h4"/>',
        'spark' => '<path d="M12 3.5v4M12 16.5v4M3.5 12h4M16.5 12h4M6 6l2.8 2.8M15.2 15.2 18 18M18 6l-2.8 2.8M8.8 15.2 6 18"/>',
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

// Bez přihlášení se ukáže jen přihlašovací stránka; deník se vůbec neotevře.
$viewer = current_user();
if ($viewer === null) {
    require __DIR__ . '/lib/auth-page.php';
    exit;
}
$viewerIsAdmin = is_admin($viewer);
$ws = workspace();
/** Nadpis skupiny v menu se skryje, když jsou skryté všechny její moduly. */
function group_attr(array $modules): string
{
    foreach ($modules as $module) {
        if (!is_hidden('modules', $module)) {
            return '';
        }
    }
    return ' hidden';
}
$viewerName = htmlspecialchars((string)$viewer['display_name'], ENT_QUOTES);
$viewerInitials = htmlspecialchars(mb_strtoupper(implode('', array_map(static fn(string $part): string => mb_substr($part, 0, 1), array_slice(preg_split('/\s+/u', trim((string)$viewer['display_name'])) ?: [], 0, 2)))), ENT_QUOTES);
?>
<!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="dark light">
  <meta name="theme-color" content="#07080b">
  <title>Trading Desk</title>
  <script src="<?= asset_url('static/theme.js') ?>"></script>
  <link rel="preload" href="static/fonts/manrope-latin-wght-normal.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="preload" href="static/fonts/fraunces-latin-opsz-normal.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="<?= asset_url('static/styles.css') ?>">
</head>
<body data-method="<?= htmlspecialchars($ws['method']) ?>" data-user-id="<?= (int)$viewer['id'] ?>" data-user-hue="<?= (int)$viewer['avatar_hue'] ?>" data-user-role="<?= htmlspecialchars((string)$viewer['role']) ?>" data-user-encrypted="<?= $viewer['encrypted'] ? '1' : '0' ?>" data-must-change="<?= $viewer['must_change_secret'] ? '1' : '0' ?>">
  <div class="app-shell">
    <aside class="sidebar" id="sidebar">
      <div class="brand">
        <span class="brand-mark" aria-hidden="true"><i></i><i></i><i></i></span>
        <span><strong>Trading Desk</strong><small id="brandTagline"><?= htmlspecialchars(['mp' => 'Market Profile journal', 'dn' => 'DiNapoli journal', 'both' => 'Market Profile · DiNapoli'][$ws['method']] ?? 'Trading journal', ENT_QUOTES) ?></small></span>
      </div>
      <nav class="nav" aria-label="Hlavní navigace">
        <p class="nav-group" data-module-group="wall"<?= group_attr(['wall']) ?>>Komunita</p>
        <button class="nav-item" type="button" data-view="wall"<?= module_attr('wall') ?>><?= icon('wall') ?><span>Nástěnka</span><em class="nav-flag is-gold" id="navWallFlag" hidden></em></button>
        <p class="nav-group">Příprava</p>
        <button class="nav-item is-active" type="button" data-view="dashboard"><?= icon('dashboard') ?><span>Přehled</span></button>
        <button class="nav-item" type="button" data-view="plan"><?= icon('plan') ?><span>Náhled trhu</span></button>
        <button class="nav-item" type="button" data-view="archive"<?= module_attr('archive') ?>><?= icon('archive') ?><span>Historie náhledů</span></button>
        <button class="nav-item" type="button" data-view="calendar"<?= module_attr('calendar') ?>><?= icon('calendar') ?><span>Kalendář</span></button>
        <a class="nav-item" href="hindsight.php"<?= module_attr('hindsight') ?>><?= icon('hindsight') ?><span>Hindsight</span></a>
        <p class="nav-group">Exekuce</p>
        <button class="nav-item" type="button" data-view="journal"><?= icon('journal') ?><span>Deník obchodů</span></button>
        <button class="nav-item" type="button" data-view="strategies"<?= module_attr('strategies') ?>><?= icon('strategies') ?><span>Strategie</span></button>
        <p class="nav-group" data-module-group="psyche,accounts"<?= group_attr(['psyche', 'accounts']) ?>>Disciplína</p>
        <button class="nav-item" type="button" data-view="psyche"<?= module_attr('psyche') ?>><?= icon('psyche') ?><span>Psychika</span></button>
        <button class="nav-item" type="button" data-view="accounts"<?= module_attr('accounts') ?>><?= icon('accounts') ?><span>Účty a audit</span><em class="nav-flag" id="navAuditFlag" hidden>Money audit</em></button>
        <p class="nav-group">Můj desk</p>
        <button class="nav-item" type="button" data-view="settings"><?= icon('sliders') ?><span>Nastavení</span></button>
        <button class="nav-item" type="button" data-view="backup"><?= icon('backup') ?><span>Záloha a export</span></button>
        <?php if ($viewerIsAdmin): ?>
        <p class="nav-group">Správa</p>
        <button class="nav-item" type="button" data-view="admin"><?= icon('users') ?><span>Členové</span><em class="nav-flag is-gold" id="navAdminFlag" hidden></em></button>
        <?php endif; ?>
      </nav>
      <div class="sidebar-foot">
        <div class="user-chip">
          <button class="user-chip-main" type="button" data-open-view="profile" title="Můj profil">
            <span class="avatar<?= $viewerIsAdmin ? ' is-admin' : '' ?>" data-hue="<?= (int)$viewer['avatar_hue'] ?>" id="chipAvatar"><?= $viewerInitials ?></span>
            <span><strong id="chipName"><?= $viewerName ?></strong><small><?= $viewerIsAdmin ? 'Správce' : 'Člen' ?> · <?= $viewer['encrypted'] ? icon('lock') . ' šifrovaný deník' : 'deník bez šifrování' ?></small></span>
          </button>
          <button class="icon-button" type="button" id="logoutButton" aria-label="Odhlásit se" title="Odhlásit se"><?= icon('logout') ?></button>
        </div>
        <div class="sidebar-foot-row">
          <div class="server-state"><span class="status-dot" id="serverDot"></span><span><strong id="serverStatus">Ověřuji server</strong><small><?= $viewer['encrypted'] ? 'deník se odemyká jen tobě' : 'data na tomto serveru' ?></small></span></div>
          <button class="icon-button theme-toggle" type="button" id="themeToggle" aria-label="Přepnout světlý a tmavý vzhled" title="Světlý / tmavý vzhled"><?= icon('moon') ?><?= icon('sun') ?></button>
        </div>
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
                <button class="button button-ghost" type="button" id="sharePlan"><?= icon('share') ?>Sdílet</button>
                <button class="button button-primary" type="submit">Uložit náhled</button>
              </div>
            </div>

            <nav class="plan-steps" id="planSteps" aria-label="Kroky náhledu">
              <button type="button" data-step="structure"><i></i>Bias</button>
              <button type="button" data-step="profile"><i></i>Profil</button>
              <button type="button" data-step="refs"><i></i>Reference</button>
              <button type="button" data-step="dinapoli"><i></i>DiNapoli</button>
              <button type="button" data-step="open"><i></i>Otevření</button>
              <button type="button" data-step="zones"><i></i>Zóny a levely</button>
              <button type="button" data-step="ideas"><i></i>Scénáře</button>
              <button type="button" data-step="custom"><i></i>Vlastní pole</button>
              <button type="button" data-step="bias"><i></i>Závěr a rizika</button>
            </nav>

            <section class="timing" id="planTiming" aria-live="polite"<?= el('timing') ?>>
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
                    <div class="bias-group"<?= el($prefix === 'pa' ? 'bias.pa' : 'bias.mp') ?>>
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
                  <fieldset class="shape-picker"<?= el('profile.shape') ?>>
                    <legend>Jaký profil se utvořil</legend>
                    <div class="shape-options">
                      <?php foreach ($shapes as [$value, $letter, $hint, $widths]): ?>
                      <label><input type="radio" name="profile_shape" value="<?= $value ?>"><span><?= profile_glyph($widths) ?><strong><?= $letter ?></strong><small><?= $hint ?></small></span></label>
                      <?php endforeach; ?>
                    </div>
                  </fieldset>

                  <div class="profile-columns">
                    <div<?= el('profile.values') ?>>
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
                    <fieldset class="ladder"<?= el('profile.close') ?>>
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

                  <div class="field-grid three"<?= el('profile.auction') ?>>
                    <label>Migrace value<select name="value_area"><option value="">—</option><option value="rising">Výš (vyšší value)</option><option value="falling">Níž (nižší value)</option><option value="overlap">Překrývá se</option></select></label>
                    <label>Migrace POC<select name="vpoc"><option value="">—</option><option value="rising">Roste</option><option value="falling">Klesá</option><option value="stable">Stabilní</option></select></label>
                    <label>Stav aukce<select name="auction"><option value="">—</option><option value="balance">Balance</option><option value="imbalance">Imbalance</option><option value="unclear">Nejasná</option></select></label>
                    <label data-daily-only>Cena vůči týdenní VA<select name="weekly_position"><option value="">—</option><option value="inside">Uvnitř VA</option><option value="above">Nad VAH</option><option value="below">Pod VAL</option></select></label>
                    <label>Single prints (iniciativa)<select name="single_print"><option value="">Bez významných</option><option value="buy">Nákupní</option><option value="sell">Prodejní</option></select></label>
                    <label>Excess / tail<select name="tail"><option value="">Bez výrazného</option><option value="buy">Kupní (dole)</option><option value="sell">Prodejní (nahoře)</option></select></label>
                  </div>
                </section>

                <section class="plan-section" id="step-refs" data-section="refs"<?= el('refs') ?>>
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

                <section class="plan-section dn-section" id="step-dinapoli" data-section="dinapoli">
                  <div class="section-heading"><div><p class="eyebrow">DiNapoli · Fibonacci a DMA</p><h2>DiNapoli levely</h2></div><span id="dnSummary">Retracementy, cíle a kde se kryjí</span></div>
                  <div class="dn-block"<?= el('dn.trend') ?>>
                    <p class="subhead">Trend podle posunutých průměrů<small>Kde je cena vůči DMA a jestli běží thrust</small></p>
                    <div class="dn-trend">
                      <?php foreach (['dn_dma_3x3' => '3x3 DMA', 'dn_dma_7x5' => '7x5 DMA', 'dn_dma_25x5' => '25x5 DMA'] as $name => $label): ?>
                      <div class="dn-trend-row"><strong><?= $label ?></strong><div class="tri-switch" role="radiogroup" aria-label="Cena vůči <?= $label ?>"><label class="is-long"><input type="radio" name="<?= $name ?>" value="above"><span>Nad</span></label><label class="is-short"><input type="radio" name="<?= $name ?>" value="below"><span>Pod</span></label></div></div>
                      <?php endforeach; ?>
                      <div class="dn-trend-row"><strong>Thrust</strong><div class="tri-switch" role="radiogroup" aria-label="Thrust"><label class="is-long"><input type="radio" name="dn_thrust" value="up"><span>Nahoru</span></label><label class="is-short"><input type="radio" name="dn_thrust" value="down"><span>Dolů</span></label></div></div>
                    </div>
                  </div>
                  <div class="dn-block"<?= el('dn.swings') ?>>
                    <div class="section-heading"><div><p class="subhead">DiNapoli levely<small>Retracementy F3, F5, F7 a expanze COP, OP, XOP. Konfluence = dva F5 u sebe, shoda = expanze u retracementu, vždy v rámci jednoho timeframu.</small></p></div><div class="section-actions"><button class="text-button" type="button" data-open-view="settings" data-settings-tab="dinapoli">Tolerance</button><button class="text-button" type="button" id="dnToLevels">Do klíčových levelů</button><button class="button button-small" type="button" id="addDnLevel"><?= icon('plus') ?>Level</button></div></div>
                    <div class="dn-level-list" id="dnLevelList"></div>
                    <div class="dn-clusters" id="dnClusters"></div>
                  </div>
                  <div class="dn-block"<?= el('dn.patterns') ?>>
                    <p class="subhead">Vzory<small>Co na grafu vidíš nebo čekáš</small></p>
                    <div class="chip-row" id="dnPatternChips"><?php foreach (DN_PATTERNS as $pattern): ?><button class="chip" type="button" data-dn-pattern="<?= htmlspecialchars($pattern) ?>"><?= htmlspecialchars($pattern) ?></button><?php endforeach; ?></div>
                    <input type="hidden" name="dn_patterns" id="dnPatterns">
                    <label class="dn-notes">Poznámka k DiNapoli<textarea name="dn_notes" rows="2" placeholder="Kde je Fib node, co by potvrdil thrust, kde je objektiv…"></textarea></label>
                  </div>
                </section>

                <section class="plan-section" id="step-open" data-section="open">
                  <div class="section-heading"><div><p class="eyebrow">Krok 4 · Otevření</p><h2 id="openHeading">Otevření a první hodina</h2></div><span id="openHeadingNote">Pole se odemknou, až nastane jejich čas</span></div>
                  <div class="field-grid three gated-grid">
                    <label class="gated" data-gate="globex"<?= el('open.va') ?>><span class="gate-label">Otevření Globexu <em data-gate-note></em></span><select name="globex_open"><?= $vaOptions ?></select></label>
                    <label class="gated" data-gate="eu"<?= el('open.va') ?>><span class="gate-label">EU open <em data-gate-note></em></span><select name="eu_open"><?= $vaOptions ?></select></label>
                    <label class="gated" data-gate="rth"<?= el('open.va') ?>><span class="gate-label">RTH open <em data-gate-note></em></span><select name="ny_open"><?= $vaOptions ?></select></label>
                    <label class="gated" data-gate="rth"<?= el('open.type') ?>><span class="gate-label">Typ otevření <em data-gate-note></em></span><select name="open_type"><option value="">—</option><option value="drive">Open Drive</option><option value="test_drive">Open Test Drive</option><option value="rejection_reverse">Open Rejection Reverse</option><option value="auction_in">Open Auction uvnitř range</option><option value="auction_out">Open Auction mimo range</option></select></label>
                    <label class="gated" data-gate="ib"<?= el('open.ib') ?>><span class="gate-label">Initial Balance <em data-gate-note></em></span><select name="initial_balance"><option value="">—</option><option value="small">Malá</option><option value="normal">Běžná</option><option value="large_drive">Velká - drive</option><option value="large_rotation">Velká - rotace</option></select></label>
                  </div>
                </section>

                <section class="plan-section" id="step-zones" data-section="zones">
                  <div class="section-heading"><div><p class="eyebrow">Krok 5 · Lokace</p><h2>Obchodní zóny</h2></div><div class="section-actions"><button class="text-button" type="button" id="exportTradingViewZones">TradingView export</button><button class="button button-small" type="button" id="addZone"><?= icon('plus') ?>Zóna</button></div></div>
                  <div class="weekly-context" id="weeklyContext"<?= el('zones.context') ?>></div>
                  <div class="stack" id="zoneList"></div>
                  <div class="subsection"<?= el('levels') ?>>
                    <div class="section-heading"><div><h3>Klíčové levely</h3></div><button class="button button-small" type="button" id="addLevel"><?= icon('plus') ?>Level</button></div>
                    <div class="stack" id="levelList"></div>
                  </div>
                </section>

                <section class="plan-section" id="step-ideas" data-section="ideas"<?= el('ideas') ?>>
                  <div class="section-heading"><div><p class="eyebrow">Krok 6 · Scénáře</p><h2>Potenciální obchody a TP</h2></div><button class="button button-small" type="button" id="addIdea"><?= icon('plus') ?>Scénář</button></div>
                  <div class="stack" id="ideaList"></div>
                </section>

                <section class="plan-section" id="step-custom" data-section="custom" hidden>
                  <div class="section-heading"><div><p class="eyebrow">Můj checklist</p><h2>Vlastní pole</h2></div><button class="text-button" type="button" data-open-view="settings" data-settings-tab="fields">Upravit pole</button></div>
                  <div class="custom-fields" id="planCustomFields"></div>
                </section>

                <section class="plan-section chart-section"<?= el('charts') ?>>
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
                  <div class="working-conclusion"<?= el('side.conclusion') ?>><span>Pracovní závěr</span><p id="workingConclusion">Doplň strukturu, profil a migraci value.</p></div>
                  <label>Popis biasu<textarea name="bias_description" rows="4" placeholder="Co trh aktuálně přijímá, kde je iniciativa a co je primární scénář…"></textarea></label>
                  <label>Co bias potvrzuje<textarea name="bias_confirm" rows="2" placeholder="Developing value, open, iniciativa, reakce v zóně…"></textarea></label>
                  <label>Co bias ruší<textarea name="bias_invalidation" rows="2" placeholder="Návrat do value, odmítnutí, selhání struktury…"></textarea></label>
                </section>

                <section class="inspector-block"<?= el('side.map') ?>>
                  <div class="section-heading"><div><p class="eyebrow">Mapa ceny</p><h2>Zóny, levely a reference</h2></div></div>
                  <div class="price-map" id="priceMap"></div>
                </section>

                <section class="inspector-block"<?= el('side.risk') ?>>
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
          <section class="surface table-surface"><div class="table-wrap"><table><thead id="tradeHead"><tr><th>Datum</th><th>Trh</th><th>Setup</th><th>Směr</th><th class="num">Entry / Exit</th><th class="num">R</th><th class="num">P&amp;L</th><th>Plán</th><th></th></tr></thead><tbody id="tradeTable"></tbody></table></div></section>
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
          <section class="surface table-surface" id="customStatsSurface" hidden>
            <div class="section-heading"><div><p class="eyebrow">Tvoje vlastní pole</p><h2>Co ti skutečně vydělává</h2></div><span>Průměrné R podle hodnot, které u obchodů zapisuješ</span></div>
            <div class="custom-stats" id="customFieldStats"></div>
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
          <section class="surface backup-notes"><h2>Kam se tvá data ukládají</h2><dl><div><dt>Deník</dt><dd><code>users/<?= (int)$viewer['id'] ?>/<?= $viewer['encrypted'] ? 'trading.sqlite3.sealed' : 'trading.sqlite3' ?></code></dd></div><div><dt>Screenshoty</dt><dd><code>users/<?= (int)$viewer['id'] ?>/uploads/</code></dd></div><div><dt>Šifrování</dt><dd><?= $viewer['encrypted'] ? 'Zapnuté. Soubory jsou zapečetěné tvým klíčem.' : 'Vypnuté. Zapneš ho v profilu.' ?></dd></div></dl><p>Datový adresář je webově uzavřený a každý člen má vlastní deník. ZIP záloha obsahuje jen tvůj deník a je odemčená, ať jde otevřít i bez aplikace. Ulož ji proto na bezpečné místo.</p></section>
        </section>

        <!-- NÁSTĚNKA -->
        <section class="view" id="view-wall">
          <div class="wall-layout">
            <div class="wall-feed">
              <section class="surface composer">
                <form id="composerForm">
                  <div class="composer-row">
                    <span class="avatar" data-hue="<?= (int)$viewer['avatar_hue'] ?>"><?= $viewerInitials ?></span>
                    <textarea name="body" id="composerText" rows="2" maxlength="4000" placeholder="Co se děje na trhu? Postřeh, otázka nebo graf…"></textarea>
                  </div>
                  <div class="composer-previews" id="composerPreviews"></div>
                  <div class="composer-foot">
                    <label class="attach-button"><?= icon('image') ?>Graf<input type="file" id="composerImages" accept="image/png,image/jpeg,image/webp" multiple></label>
                    <button class="attach-button" type="button" id="openSharePicker"><?= icon('share') ?>Sdílet z deníku</button>
                    <span class="spacer"></span>
                    <small id="composerCount"></small>
                    <button class="button button-primary button-small" type="submit"><?= icon('send') ?>Publikovat</button>
                  </div>
                </form>
              </section>
              <div class="wall-filters" id="wallFilters" role="group" aria-label="Filtr příspěvků">
                <button class="chip is-on" type="button" data-wall-kind="">Vše</button>
                <button class="chip" type="button" data-wall-kind="plan">Náhledy</button>
                <button class="chip" type="button" data-wall-kind="trade">Obchody</button>
                <button class="chip" type="button" data-wall-kind="strategy">Strategie</button>
                <button class="chip" type="button" data-wall-kind="note">Příspěvky</button>
              </div>
              <div class="wall-filter-note" id="wallAuthorNote" hidden><span id="wallAuthorText"></span><button class="mini-button" type="button" id="clearWallAuthor">Zobrazit všechny</button></div>
              <div class="wall-feed" id="wallPosts"><div class="skeleton"></div><div class="skeleton"></div></div>
              <button class="button button-ghost wall-more" type="button" id="wallMore" hidden>Načíst starší příspěvky</button>
            </div>
            <aside class="wall-rail">
              <section class="surface rail-card">
                <p class="eyebrow">Tvoje soukromí</p>
                <h3>Co vidí ostatní</h3>
                <p>Na nástěnce je jen to, co sám sdílíš: snímek náhledu, obchodu nebo strategie v okamžiku sdílení. Zbytek deníku vidíš jen ty.<?= $viewer['encrypted'] ? ' Tvůj deník je šifrovaný; sdílený snímek je pro členy čitelný.' : '' ?></p>
              </section>
              <section class="surface rail-card">
                <p class="eyebrow">Komunita</p>
                <h3>Členové</h3>
                <div class="member-list" id="memberList"></div>
              </section>
            </aside>
          </div>
        </section>

        <!-- NASTAVENÍ -->
        <section class="view" id="view-settings">
          <div class="settings-layout">
            <nav class="settings-tabs" id="settingsTabs" aria-label="Části nastavení">
              <button type="button" data-settings-tab="method" class="is-active"><?= icon('spark') ?><span><strong>Metodika</strong><small>Market Profile, DiNapoli</small></span></button>
              <button type="button" data-settings-tab="plan"><?= icon('plan') ?><span><strong>Náhled trhu</strong><small>Co v náhledu vidíš</small></span></button>
              <button type="button" data-settings-tab="dinapoli" id="dnSettingsTab"<?= $ws['method'] === 'mp' ? ' hidden' : '' ?>><?= icon('fib') ?><span><strong>DiNapoli</strong><small>Tolerance shody a konfluence</small></span></button>
              <button type="button" data-settings-tab="trade"><?= icon('journal') ?><span><strong>Zápis obchodu</strong><small>Pole a výchozí hodnoty</small></span></button>
              <button type="button" data-settings-tab="fields"><?= icon('sliders') ?><span><strong>Vlastní pole</strong><small>Co chceš zapisovat navíc</small></span></button>
              <button type="button" data-settings-tab="markets"><?= icon('strategies') ?><span><strong>Moje trhy</strong><small>Symboly a hodnota bodu</small></span></button>
              <button type="button" data-settings-tab="modules"><?= icon('dashboard') ?><span><strong>Moduly</strong><small>Co je v menu</small></span></button>
            </nav>
            <div class="settings-panels">
              <section class="surface settings-panel is-active" data-settings-panel="method">
                <div class="section-heading"><div><p class="eyebrow">Jak obchoduješ</p><h2>Metodika</h2></div><span id="methodSaved"></span></div>
                <p class="settings-lead">Podle metodiky se připraví náhled trhu: Market Profile přinese profil, value a reference, DiNapoli levely se shodou a konfluencí, DMA a vzory. Jednotlivé prvky pak doladíš v další části.</p>
                <div class="method-cards" id="methodCards">
                  <button type="button" class="method-card" data-method="mp"><span class="method-glyph method-glyph-mp" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i><i></i></span><strong>Market Profile</strong><small>Value area, POC, tvar profilu, reference na dojetí, otevření podle Daltona.</small></button>
                  <button type="button" class="method-card" data-method="dn"><span class="method-glyph method-glyph-dn" aria-hidden="true"><?= icon('fib') ?></span><strong>DiNapoli</strong><small>Levely F3, F5, F7 a expanze COP, OP, XOP, shoda a konfluence podle timeframu, DMA a vzory.</small></button>
                  <button type="button" class="method-card" data-method="both"><span class="method-glyph method-glyph-both" aria-hidden="true"><i></i><i></i><i></i><?= icon('fib') ?></span><strong>Obojí</strong><small>Profil i Fibonacci v jednom náhledu. Nejvíc informací, nejvíc polí.</small></button>
                </div>
                <div class="plan-preview" id="planPreview"></div>
              </section>

              <section class="surface settings-panel" data-settings-panel="plan">
                <div class="section-heading"><div><p class="eyebrow">Náhled trhu</p><h2>Prvky náhledu</h2></div><button class="text-button" type="button" id="resetPlanElements">Podle metodiky</button></div>
                <p class="settings-lead">Vypni, co nepoužíváš. Skryté části se neukazují ani nepočítají do připravenosti náhledu; už vyplněná data zůstanou uložená.</p>
                <div class="toggle-groups" id="planElementToggles"></div>
                <div class="settings-block">
                  <p class="subhead">Vlastní štítky zdroje zón<small>Přidají se k nabídce u každé zóny, třeba „Weekly open“ nebo „Gap fill“.</small></p>
                  <div class="token-editor"><div class="chip-row" id="tokenList"></div><form class="inline-add" id="tokenForm"><input name="token" maxlength="24" placeholder="Nový štítek"><button class="button button-small" type="submit"><?= icon('plus') ?>Přidat</button></form></div>
                </div>
              </section>

              <section class="surface settings-panel" data-settings-panel="dinapoli">
                <div class="section-heading"><div><p class="eyebrow">DiNapoli</p><h2>Tolerance podle timeframu</h2></div><button class="text-button" type="button" id="resetDnTimeframes">Výchozí hodnoty</button></div>
                <p class="settings-lead">Dva levely stejného timeframu, které jsou od sebe nejvýš o toleranci v bodech, tvoří <strong>konfluenci</strong> (F5 + F5), nebo <strong>shodu</strong> (expanze COP, OP, XOP + retracement F3, F5, F7). Každý druh má vlastní kritéria, proto má i vlastní toleranci. Levely z různých timeframů se neporovnávají.</p>
                <form id="dnTimeframesForm">
                  <div class="markets-table dn-tf-table" id="dnTimeframeRows"></div>
                  <div class="form-actions"><button class="button button-ghost" type="button" id="addDnTimeframe"><?= icon('plus') ?>Přidat timeframe</button><button class="button button-primary" type="submit">Uložit tolerance</button></div>
                </form>
              </section>

              <section class="surface settings-panel" data-settings-panel="trade">
                <div class="section-heading"><div><p class="eyebrow">Deník obchodů</p><h2>Zápis obchodu</h2></div></div>
                <p class="settings-lead">Datum, trh, směr, ceny, risk a výsledek jsou vždy. Ostatní zapni podle toho, co opravdu sleduješ. Rozbor chyb potřebuje „Dodržen plán“ a „Hodnocení exekuce“, rozbor spouštěčů „Emoce“.</p>
                <div class="toggle-groups" id="tradeElementToggles"></div>
                <form class="settings-block" id="defaultsForm">
                  <p class="subhead">Výchozí hodnoty nového obchodu<small>Předvyplní se, můžeš je u obchodu vždy změnit.</small></p>
                  <div class="field-grid four">
                    <label>Trh<select name="default_market" id="defaultMarket"></select></label>
                    <label>Typ obchodu<select name="session"><option>Intraday</option><option>Hybrid Intraday</option></select></label>
                    <label>Risk na trade ($)<input type="number" name="risk" min="0" step="any" placeholder="např. 300"></label>
                    <label>Poplatky ($)<input type="number" name="fees" min="0" step="0.01" placeholder="0"></label>
                    <label class="span-2">Účet<select name="account_id" id="defaultAccount"><option value="">—</option></select></label>
                  </div>
                  <button class="button button-primary" type="submit">Uložit výchozí hodnoty</button>
                </form>
              </section>

              <section class="surface settings-panel" data-settings-panel="fields">
                <div class="section-heading"><div><p class="eyebrow">Vlastní pole</p><h2>Co chceš zapisovat navíc</h2></div></div>
                <p class="settings-lead">Checklist před vstupem, kvalita setupu, VIX, počet pokusů… U obchodů se pole typu ano/ne, výběr, hodnocení a číslo samy vyhodnotí ve Strategiích: uvidíš, jaké průměrné R máš při které hodnotě.</p>
                <div class="field-scopes">
                  <div><p class="subhead">U obchodu</p><div class="field-list" id="tradeFieldList"></div></div>
                  <div><p class="subhead">V náhledu trhu</p><div class="field-list" id="planFieldList"></div></div>
                </div>
                <form class="settings-block field-form" id="fieldForm">
                  <p class="subhead" id="fieldFormTitle">Nové pole</p>
                  <input type="hidden" name="id">
                  <div class="field-grid four">
                    <label class="span-2">Název<input name="label" maxlength="60" required placeholder="Čekal jsem na potvrzení"></label>
                    <label>Kde<select name="scope"><option value="trade">U obchodu</option><option value="plan">V náhledu</option></select></label>
                    <label>Typ<select name="kind"><?php foreach (CUSTOM_FIELD_KINDS as $kind => $label): ?><option value="<?= $kind ?>"><?= $label ?></option><?php endforeach; ?></select></label>
                    <label class="span-2" data-field-options hidden>Možnosti<textarea name="options" rows="3" placeholder="Každá možnost na nový řádek: A+, A, B"></textarea></label>
                    <label class="span-2">Nápověda<input name="help" maxlength="160" placeholder="Volitelné vysvětlení pod polem"></label>
                    <label class="toggle-inline" data-field-table><input type="checkbox" name="in_table"><span>Ukazovat jako sloupec v deníku</span></label>
                  </div>
                  <div class="form-actions"><button class="button button-ghost" type="button" id="fieldCancel" hidden>Zrušit úpravu</button><button class="button button-primary" type="submit" id="fieldSubmit"><?= icon('plus') ?>Přidat pole</button></div>
                </form>
                <div class="field-ideas"><span>Nápady:</span><button class="chip chip-add" type="button" data-field-idea='{"label":"Čekal jsem na potvrzení","kind":"bool","scope":"trade"}'>Čekal jsem na potvrzení</button><button class="chip chip-add" type="button" data-field-idea='{"label":"Kvalita setupu","kind":"select","options":"A+\nA\nB","scope":"trade"}'>Kvalita setupu A+/A/B</button><button class="chip chip-add" type="button" data-field-idea='{"label":"Obchod ve směru biasu","kind":"bool","scope":"trade"}'>Ve směru biasu</button><button class="chip chip-add" type="button" data-field-idea='{"label":"Soustředění","kind":"rating","scope":"trade"}'>Soustředění 1–5</button><button class="chip chip-add" type="button" data-field-idea='{"label":"VIX","kind":"number","scope":"plan"}'>VIX v náhledu</button><button class="chip chip-add" type="button" data-field-idea='{"label":"Hlavní téma dne","kind":"text","scope":"plan"}'>Téma dne</button></div>
              </section>

              <section class="surface settings-panel" data-settings-panel="markets">
                <div class="section-heading"><div><p class="eyebrow">Instrumenty</p><h2>Moje trhy</h2></div><button class="text-button" type="button" id="resetMarkets">Výchozí seznam</button></div>
                <p class="settings-lead">Trhy v nabídkách a filtrech. Hodnota bodu slouží jen k orientačnímu počtu kontraktů; R i P&amp;L se počítají z risku. Čas RTH (New York) řídí zamykání polí otevření v náhledu.</p>
                <form id="marketsForm">
                  <div class="markets-table" id="marketRows"></div>
                  <div class="form-actions"><button class="button button-ghost" type="button" id="addMarket"><?= icon('plus') ?>Přidat trh</button><button class="button button-primary" type="submit">Uložit trhy</button></div>
                </form>
              </section>

              <section class="surface settings-panel" data-settings-panel="modules">
                <div class="section-heading"><div><p class="eyebrow">Menu</p><h2>Moduly</h2></div></div>
                <p class="settings-lead">Skryj, co nepoužíváš. Data zůstanou uložená a modul kdykoli zase zapneš. Přehled, náhled trhu, deník, nastavení a záloha jsou vždy.</p>
                <div class="toggle-groups" id="moduleToggles"></div>
                <div class="settings-block"><p class="subhead">Průvodce nastavením<small>Znovu projdeš úvodní tři kroky.</small></p><button class="button button-ghost" type="button" id="rerunOnboarding"><?= icon('spark') ?>Spustit průvodce</button></div>
              </section>
            </div>
          </div>
        </section>

        <!-- PROFIL -->
        <section class="view" id="view-profile">
          <div class="profile-layout">
            <section class="surface profile-card">
              <div class="identity-hero">
                <span class="avatar avatar-xl<?= $viewerIsAdmin ? ' is-admin' : '' ?>" data-hue="<?= (int)$viewer['avatar_hue'] ?>" id="profileAvatar"><?= $viewerInitials ?></span>
                <div><p class="eyebrow"><?= $viewerIsAdmin ? 'Správce' : 'Člen komunity' ?></p><h2 id="profileName"><?= $viewerName ?></h2><p>@<?= htmlspecialchars((string)$viewer['login']) ?> · člen od <?= htmlspecialchars(substr((string)$viewer['created_at'], 0, 10)) ?></p></div>
              </div>
              <form class="profile-form" id="accountProfileForm">
                <label>Zobrazované jméno<input name="display_name" required maxlength="60" value="<?= $viewerName ?>"></label>
                <label><span>E-mail <small class="optional">vidí ho jen správce</small></span><input name="email" type="email" maxlength="120" value="<?= htmlspecialchars((string)($viewer['email'] ?? '')) ?>"></label>
                <label>Barva avatara<input class="hue-range" type="range" name="avatar_hue" min="0" max="359" value="<?= (int)$viewer['avatar_hue'] ?>" id="hueRange"></label>
                <button class="button button-primary" type="submit">Uložit profil</button>
              </form>
            </section>

            <section class="surface profile-card">
              <div class="section-heading"><div><p class="eyebrow">Zabezpečení</p><h2>Přístup a šifrování</h2></div></div>
              <?php if ($viewer['encrypted']): ?>
              <div class="security-state is-encrypted"><?= icon('shield') ?><div><strong>Deník je šifrovaný</strong><p>Deník i screenshoty jsou na disku zapečetěné tvým klíčem. Odemknou se jen během tvého požadavku. Klíč nikdo neobnoví, ani správce.</p></div></div>
              <div class="profile-form">
                <p class="calc-hint">Nový klíč vydej, když máš podezření, že starý někdo viděl. Data se nepřešifrovávají, jen se vymění zámek; ostatní zařízení se odhlásí.</p>
                <button class="button button-ghost" type="button" id="rotateKey"><?= icon('key') ?>Vydat nový přístupový klíč</button>
              </div>
              <?php else: ?>
              <div class="security-state"><?= icon('shield') ?><div><strong>Deník bez šifrování</strong><p>Deník leží na serveru jako běžná databáze. Kdo má přístup k disku serveru, může ho přečíst. Šifrování to změní.</p></div></div>
              <form class="profile-form" id="passwordForm">
                <div class="field-grid three">
                  <label>Současné heslo<input name="current" type="password" autocomplete="current-password" required></label>
                  <label>Nové heslo<input name="next" type="password" autocomplete="new-password" minlength="10" required></label>
                  <label>Nové heslo znovu<input name="again" type="password" autocomplete="new-password" required></label>
                </div>
                <button class="button button-ghost" type="submit">Změnit heslo</button>
              </form>
              <div class="profile-form">
                <p class="calc-hint"><strong>Zapnout šifrování:</strong> heslo nahradí přístupový klíč. Celý deník i screenshoty se zašifrují a bez klíče je nepřečte nikdo, ani správce. Ztracený klíč nejde obnovit.</p>
                <button class="button button-primary" type="button" id="enableEncryption"><?= icon('lock') ?>Zapnout šifrování deníku</button>
              </div>
              <?php endif; ?>
              <div class="switch-row"><div><strong>Ostatní zařízení</strong><small>Ukončí všechna přihlášení kromě tohoto.</small></div><button class="button button-ghost button-small" type="button" id="logoutOthers">Odhlásit ostatní</button></div>
            </section>
          </div>
          <section class="surface profile-card privacy-card">
            <div class="section-heading"><div><p class="eyebrow">Soukromí</p><h2>Kdo co vidí</h2></div></div>
            <ul class="privacy-list">
              <li><?= icon('lock') ?><span><strong>Tvůj deník vidíš jen ty.</strong> Každý člen má vlastní databázi; aplikace cizí deník vůbec neotevře.</span></li>
              <li><?= icon('wall') ?><span><strong>Nástěnku vidí všichni schválení členové.</strong> Je na ní jen to, co sdílíš, jako snímek v okamžiku sdílení. Částky v dolarech a poznámky se sdílí jen, když je zaškrtneš.</span></li>
              <li><?= icon('users') ?><span><strong>Správce vidí seznam členů,</strong> jejich e-mail a velikost dat, ale ne obsah deníků.</span></li>
              <li><?= icon('shield') ?><span><strong>Šifrovaný deník</strong> chrání data na disku i v zálohách serveru. Proti správci, který by upravil kód aplikace, by pomohlo jen šifrování přímo v prohlížeči.</span></li>
            </ul>
          </section>
        </section>
<?php if ($viewerIsAdmin): ?>

        <!-- SPRÁVA ČLENŮ -->
        <section class="view" id="view-admin">
          <div class="metric-strip four">
            <article><span>Čeká na schválení</span><strong id="adminPendingCount">0</strong><small>nové registrace</small></article>
            <article><span>Aktivní členové</span><strong id="adminActiveCount">0</strong><small>mohou se přihlásit</small></article>
            <article><span>Šifrované deníky</span><strong id="adminEncryptedCount">0</strong><small>obsah nevidí ani správce</small></article>
            <article><span>Zablokovaní</span><strong id="adminBlockedCount">0</strong><small>bez přístupu</small></article>
          </div>
          <div class="admin-top">
            <section class="surface table-surface">
              <div class="section-heading"><div><p class="eyebrow">Ke schválení</p><h2>Nové registrace</h2></div><span id="pendingCaption"></span></div>
              <div class="pending-list" id="pendingList"></div>
            </section>
            <section class="surface settings-card">
              <div class="section-heading"><div><p class="eyebrow">Nastavení</p><h2>Přístup do aplikace</h2></div></div>
              <div class="switch-row"><div><strong>Otevřené registrace</strong><small>Kdokoli s adresou se může zaregistrovat; přihlásí se až po tvém schválení.</small></div><label class="switch" aria-label="Otevřené registrace"><input type="checkbox" id="registrationOpen"><i></i></label></div>
              <div class="switch-row"><div><strong>Šifrované deníky</strong><small>Členům se šifrováním nejde obnovit heslo ani přečíst deník. Můžeš je jen zablokovat nebo smazat.</small></div></div>
            </section>
          </div>
          <section class="surface table-surface">
            <div class="section-heading"><div><p class="eyebrow">Přehled</p><h2>Všichni členové</h2></div><span>Obsah deníků správce nevidí</span></div>
            <div class="table-wrap"><table><thead><tr><th>Člen</th><th>Stav</th><th>Role</th><th>Deník</th><th>Registrace</th><th>Naposledy</th><th class="num">Příspěvky</th><th class="num">Data</th><th></th></tr></thead><tbody id="memberTable"></tbody></table></div>
          </section>
        </section>
<?php endif; ?>
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
          <label<?= el('trade.account') ?>>Účet<select name="account_id" id="tradeAccount"><option value="">—</option></select></label>
          <label<?= el('trade.session') ?>>Typ obchodu<input name="session" id="tradeSession" list="sessionOptions" autocomplete="off" placeholder="Intraday, Hybrid Intraday…" title="Intraday je plánovaný pouze na dnešní den. Hybrid Intraday lze při příznivém vývoji držet déle."></label>
          <label>Směr<select name="direction"><option value="long">Long</option><option value="short">Short</option></select></label>
          <label class="field-with-action span-3"<?= el('trade.strategy') ?>>Strategie / setup<span><select name="strategy_id" id="tradeStrategy"><option value="">—</option><option value="__new">+ Přidat strategii / setup</option></select><button class="mini-button" type="button" id="editTradeStrategy" title="Upravit vybranou strategii">Upravit</button></span></label>
        </div>
      </fieldset>
      <fieldset class="form-section">
        <legend>Ceny a riziko</legend>
        <div class="field-grid four">
          <label>Entry<input type="number" step="any" name="entry_price"></label>
          <label>Exit<input type="number" step="any" name="exit_price"></label>
          <label>Stop loss<input type="number" step="any" name="stop_loss"></label>
          <label<?= el('trade.target') ?>>Plánovaný TP<input type="number" step="any" name="target_price"></label>
          <label>Risk na trade ($)<input type="number" step="any" min="0" name="risk_amount" placeholder="např. 500"></label>
          <label<?= el('trade.fees') ?>>Poplatky ($)<input type="number" step="0.01" name="fees" value="0"></label>
          <label>Výsledek R<input type="number" step="0.01" name="result_r" placeholder="dopočítá se"></label>
          <label>Výsledek $<input type="number" step="0.01" name="result_usd" placeholder="dopočítá se"></label>
        </div>
        <p class="calc-hint" id="tradeCalcHint">Doplň entry, stop a risk na trade; velikost pozice, čisté P&amp;L i R se dopočítají.</p>
      </fieldset>
      <fieldset class="form-section" data-el-group="trade.followed,trade.rating">
        <legend>Hodnocení</legend>
        <div class="field-grid two">
          <label<?= el('trade.followed') ?>>Dodržen plán<select name="followed_plan"><option value="">—</option><option value="1">Ano</option><option value="0">Ne</option></select></label>
          <label<?= el('trade.rating') ?>>Hodnocení obchodu<select name="execution_rating"><option value="">—</option><option value="1">1 · Vše splněno podle plánu</option><option value="2">2 · Drobná odchylka od plánu</option><option value="3">3 · Částečně dodržená pravidla</option><option value="4">4 · Výrazné porušení pravidel</option><option value="5">5 · Nebyla dodržena pravidla pro exekuci</option></select></label>
        </div>
      </fieldset>
      <fieldset class="form-section custom-fieldset" id="tradeCustomSection" hidden>
        <legend>Moje pole <button class="mini-button" type="button" data-open-view="settings" data-settings-tab="fields">Upravit</button></legend>
        <div class="custom-fields" id="tradeCustomFields"></div>
      </fieldset>
      <fieldset class="checkbox-field"<?= el('trade.emotions') ?>>
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
        <label<?= el('trade.mistake') ?>>Chyba / odchylka<textarea name="mistake" rows="3"></textarea></label>
        <label<?= el('trade.notes') ?>>Poznámka a poučení<textarea name="notes" rows="3"></textarea></label>
      </div>
      <label class="trade-upload"<?= el('trade.screenshots') ?>>Screenshoty obchodu<input type="file" id="tradeScreenshots" accept="image/png,image/jpeg,image/webp" multiple><span id="tradeScreenshotNames">Volitelně přidej entry, exit nebo výsledný graf.</span></label>
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
      <div class="modal-actions"><button class="button button-ghost danger" type="button" id="deleteStrategy" hidden>Smazat strategii</button><button class="button button-ghost" type="button" id="shareStrategy" hidden><?= icon('share') ?>Sdílet</button><span class="spacer"></span><button class="button button-ghost" value="cancel" type="submit" formnovalidate>Zrušit</button><button class="button button-primary" type="submit" value="default">Uložit strategii</button></div>
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
        <label class="toggle-control" id="tvIncludeDnControl"><input type="checkbox" id="tvIncludeDn" checked><span><strong>DiNapoli levely</strong><small>F3, F5, COP, OP, XOP a zóny confluence a agreement.</small></span></label>
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

  <dialog class="modal onboarding" id="onboardingDialog" aria-labelledby="onboardingTitle">
    <form method="dialog" id="onboardingForm">
      <div class="onboarding-head">
        <p class="eyebrow">Vítej v Trading Desku</p>
        <h2 id="onboardingTitle">Nastav si svůj desk</h2>
        <ol class="onboarding-steps" id="onboardingSteps"><li class="is-active">Metodika</li><li>Trhy</li><li>Moduly</li></ol>
      </div>
      <div class="onboarding-page" data-onboarding-page="1">
        <p class="settings-lead">Jak připravuješ obchody? Podle toho se připraví náhled trhu. Později ho doladíš v Nastavení.</p>
        <div class="method-cards compact" id="onboardingMethods">
          <button type="button" class="method-card" data-method="mp"><span class="method-glyph method-glyph-mp" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i><i></i></span><strong>Market Profile</strong><small>Value, POC, profil, reference</small></button>
          <button type="button" class="method-card" data-method="dn"><span class="method-glyph method-glyph-dn" aria-hidden="true"><?= icon('fib') ?></span><strong>DiNapoli</strong><small>Levely, shoda, konfluence, DMA</small></button>
          <button type="button" class="method-card" data-method="both"><span class="method-glyph method-glyph-both" aria-hidden="true"><i></i><i></i><i></i><?= icon('fib') ?></span><strong>Obojí</strong><small>Profil i Fibonacci</small></button>
        </div>
      </div>
      <div class="onboarding-page" data-onboarding-page="2" hidden>
        <p class="settings-lead">Co obchoduješ? Označ trhy, které chceš mít v nabídce. Další přidáš kdykoli v Nastavení.</p>
        <div class="chip-row market-picker" id="onboardingMarkets"></div>
        <div class="inline-add"><input id="onboardingNewMarket" maxlength="12" placeholder="Vlastní symbol, třeba FDAX"><input id="onboardingNewPoint" type="number" min="0" step="any" placeholder="Hodnota bodu"><button class="button button-small" type="button" id="onboardingAddMarket"><?= icon('plus') ?>Přidat</button></div>
        <div class="field-grid two onboarding-defaults">
          <label>Hlavní trh<select id="onboardingDefaultMarket"></select></label>
          <label>Obvyklý risk na trade ($)<input id="onboardingRisk" type="number" min="0" step="any" placeholder="např. 300"></label>
        </div>
      </div>
      <div class="onboarding-page" data-onboarding-page="3" hidden>
        <p class="settings-lead">Co chceš v aplikaci používat? Vypnuté moduly zmizí z menu a z přehledu.</p>
        <div class="toggle-groups" id="onboardingModules"></div>
      </div>
      <div class="modal-actions"><button class="button button-ghost" type="button" id="onboardingSkip">Přeskočit</button><span class="spacer"></span><button class="button button-ghost" type="button" id="onboardingBack" hidden>Zpět</button><button class="button button-primary" type="button" id="onboardingNext">Pokračovat</button></div>
    </form>
  </dialog>

  <dialog class="modal modal-narrow" id="shareDialog" aria-labelledby="shareDialogTitle">
    <form method="dialog" id="shareForm">
      <div class="modal-head"><div><p class="eyebrow">Společná nástěnka</p><h2 id="shareDialogTitle">Sdílet s komunitou</h2></div><button class="icon-button" value="cancel" type="submit" formnovalidate aria-label="Zavřít">×</button></div>
      <div class="share-summary"><?= icon('share') ?><div><strong id="shareSummaryTitle"></strong><small id="shareSummaryText"></small></div></div>
      <div class="share-options" id="shareOptions"></div>
      <label>Komentář k příspěvku<textarea name="note" rows="3" maxlength="4000" placeholder="Co na tom stojí za pozornost? Co bys udělal jinak?"></textarea></label>
      <p class="share-warning" id="shareWarning">Na nástěnku se uloží snímek v tomto okamžiku. Pozdější úpravy v deníku se projeví, až dáš Aktualizovat. Sdílený obsah vidí všichni schválení členové a není šifrovaný.</p>
      <div class="modal-actions"><button class="button button-ghost danger" type="button" id="unshareButton" hidden>Odebrat z nástěnky</button><span class="spacer"></span><button class="button button-ghost" value="cancel" type="submit" formnovalidate>Zrušit</button><button class="button button-primary" type="submit" value="default" id="shareSubmit"><?= icon('share') ?>Sdílet</button></div>
    </form>
  </dialog>

  <dialog class="modal modal-narrow" id="sharePickerDialog" aria-labelledby="sharePickerTitle">
    <form method="dialog">
      <div class="modal-head"><div><p class="eyebrow">Z tvého deníku</p><h2 id="sharePickerTitle">Co chceš sdílet?</h2></div><button class="icon-button" value="cancel" type="submit" aria-label="Zavřít">×</button></div>
      <div class="auth-tabs picker-tabs" role="tablist">
        <button type="button" role="tab" aria-selected="true" data-picker-tab="plan">Náhledy</button>
        <button type="button" role="tab" aria-selected="false" data-picker-tab="trade">Obchody</button>
        <button type="button" role="tab" aria-selected="false" data-picker-tab="strategy">Strategie</button>
      </div>
      <div class="picker-list" id="pickerList"></div>
      <div class="modal-actions"><button class="button button-ghost" value="cancel" type="submit">Zavřít</button></div>
    </form>
  </dialog>

  <dialog class="modal modal-narrow" id="secretDialog" aria-labelledby="secretDialogTitle">
    <form method="dialog" id="secretForm">
      <div class="modal-head"><div><p class="eyebrow" id="secretDialogEyebrow">Šifrování</p><h2 id="secretDialogTitle">Zapnout šifrování deníku</h2></div><button class="icon-button" value="cancel" type="submit" formnovalidate aria-label="Zavřít" id="secretDialogClose">×</button></div>
      <div id="secretAsk">
        <p class="calc-hint" id="secretIntro"></p>
        <label id="secretLabel">Současné heslo<input name="current" type="password" autocomplete="current-password" required spellcheck="false"></label>
      </div>
      <div class="key-card" id="appKeyCard" hidden>
        <span class="key-card-label"><?= icon('key') ?>Tvůj nový přístupový klíč</span>
        <output class="key-value" id="appKeyValue"></output>
        <div class="key-actions"><button class="button button-ghost" type="button" id="appCopyKey">Kopírovat</button><button class="button button-ghost" type="button" id="appDownloadKey"><?= icon('download') ?>Stáhnout</button></div>
        <p>Od teď se přihlašuješ tímto klíčem místo hesla. <strong>Server ho nikde neuchovává</strong> a bez něj deník neotevře nikdo, ani správce.</p>
        <label class="key-confirm"><input type="checkbox" id="appKeySaved"><span>Klíč mám bezpečně uložený</span></label>
      </div>
      <div class="modal-actions"><button class="button button-ghost" value="cancel" type="submit" formnovalidate id="secretCancel">Zrušit</button><button class="button button-primary" type="submit" value="default" id="secretSubmit">Pokračovat</button></div>
    </form>
  </dialog>

  <dialog class="modal modal-narrow" id="forcePasswordDialog" aria-labelledby="forcePasswordTitle">
    <form method="dialog" id="forcePasswordForm">
      <div class="modal-head"><div><p class="eyebrow">Dočasné heslo</p><h2 id="forcePasswordTitle">Nastav si nové heslo</h2></div></div>
      <p class="calc-hint">Správce ti obnovil přístup dočasným heslem. Než budeš pokračovat, nastav si vlastní.</p>
      <div class="field-grid three">
        <label>Dočasné heslo<input name="current" type="password" autocomplete="current-password" required></label>
        <label>Nové heslo<input name="next" type="password" autocomplete="new-password" minlength="10" required></label>
        <label>Nové heslo znovu<input name="again" type="password" autocomplete="new-password" required></label>
      </div>
      <div class="modal-actions"><button class="button button-ghost" type="button" id="forceLogout">Odhlásit se</button><span class="spacer"></span><button class="button button-primary" type="submit" value="default">Uložit heslo</button></div>
    </form>
  </dialog>

  <dialog class="modal modal-narrow" id="adminResultDialog" aria-labelledby="adminResultTitle">
    <form method="dialog">
      <div class="modal-head"><div><p class="eyebrow">Správa</p><h2 id="adminResultTitle">Dočasné heslo</h2></div><button class="icon-button" value="cancel" type="submit" aria-label="Zavřít">×</button></div>
      <p class="calc-hint" id="adminResultText"></p>
      <output class="temp-secret" id="adminResultSecret"></output>
      <p class="share-warning">Předej ho členovi bezpečnou cestou. Po přihlášení si ho musí změnit. Heslo se ukazuje jen teď.</p>
      <div class="modal-actions"><button class="button button-primary" value="default" type="submit">Hotovo</button></div>
    </form>
  </dialog>

  <dialog class="lightbox" id="lightbox"><button class="icon-button" id="closeLightbox" aria-label="Zavřít">×</button><img id="lightboxImage" alt="Screenshot grafu"><p id="lightboxCaption"></p></dialog>
  <div class="toast" id="toast" role="status" aria-live="polite"></div>
  <script src="<?= asset_url('static/tradingview-export.js') ?>" defer></script>
  <script src="<?= asset_url('static/dinapoli.js') ?>" defer></script>
  <script src="<?= asset_url('static/wall.js') ?>" defer></script>
  <script src="<?= asset_url('static/settings.js') ?>" defer></script>
  <script src="<?= asset_url('static/members.js') ?>" defer></script>
  <script src="<?= asset_url('static/app.js') ?>" defer></script>
</body>
</html>
