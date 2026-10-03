# Hindsight: poznámky pro Clauda

Stránka `hindsight.php` (`/trading/hindsight.php`). Je to souvislá časová osa 5m svíček ES,
na které jsou:

- denní náhledy (zóny, bias), news;
- potenciální obchody (ideas) a realizované obchody z deníku;
- zpětné vyhodnocení.

Zadání uživatele je `Hindsight – zadání modulu.docx` na větvi `main`. Dokumentace pro
uživatele je v `README.md`, sekce Hindsight.

## Soubory

- `lib/hindsight.php`: server.
  - `market.sqlite3` (sdílená pro všechny, tabulky `bars`, `contracts`).
  - Import CSV z ATAS, kontrakty a roll.
  - Import, ukázková data a mazání kontraktu smí jen **správce dat grafu** (jeden člověk,
    `require_market_data_keeper` v `lib/accounts.php`, nastavení `market_data_keeper`
    v `app.sqlite3`). Výchozí je první správce, změna jen přes `bin/hindsight-keeper.php`.
    Když přestane být správcem, nenahrává nikdo (oprávnění samo nepřechází).
  - Anotace z deníku uživatele (zóny a bias jsou tytéž jako v denním náhledu).
  - Zámek při otevření NY a verze, prefs (`HS_LAYERS`).
- `hindsight.php`: stránka (toolbar, dialogy, nápověda).
- API akce `hindsight_*` v `api.php`.
- `static/hindsight/hindsight.js`: graf. Lightweight Charts v5.2.1 z `vendor/`, vrstvy,
  interakce, panel vyhodnocení, minimapa.
- `static/hindsight/time.js`: čas a seance (testuje `tests/test_hindsight_time.js`).
- `static/hindsight/evaluate.js`: čisté funkce vyhodnocení (testuje `tests/test_hindsight_eval.js`).
- `static/hindsight/hindsight.css`: vzhled, paleta ve CSS proměnných.

## Čas a seance

- Svíčky jsou v UTC (unix sekundy, začátek svíčky), 5m. 1m svíčky se při importu sloučí.
- Obchodní den trvá od 18:00 New York předchozího dne do 17:00 New York.
- Seance jsou v čase New Yorku:

  | seance | New York | Praha běžně |
  |---|---|---|
  | Asie | 18:00–03:00 | |
  | EU | 03:00–09:30 | |
  | NY / RTH | 09:30–16:15 | 15:30–22:15 |

- Zobrazení je v Europe/Prague, to je výchozí pásmo.
- USA a Evropa mění čas v jiné týdny (březen, konec října až začátek listopadu). V těch
  týdnech je RTH v Praze o hodinu dřív (14:30).
- `time.js` počítá posun obou zemí pro každý okamžik zvlášť.
- Konec RTH 16:15 platí i v hlavní aplikaci (`RTH_CLOSE` ve `static/app.js`).

## Kontrakty a import z ATAS

- Export z ATAS má řádky `YYYY-DD-MM HH:MM:SS;O;H;L;C[;V]` v pražském čase.
  **Pozor: rok, den, měsíc.**
- Pořadí v datu odhaduje `hs_date_order`. Data v budoucnosti penalizuje, takže **testy musí
  používat data v minulosti** vůči hodinám kontejneru. Když odhad nevyjde, import se zeptá.
- Spojitý (continuous) export má u starších kontraktů posunuté ceny. Proto se ukládá jen
  období **vybraného kontraktu**; svíčky ostatních se jen spočítají (`outside`).
- Kontrakt a roll:
  - kontrakt = čtvrtletní H/M/U/Z s expirací 3. pátek;
  - roll = čtvrtek 8 dní před expirací, přechod v 18:00 New York (`hs_contract_period`).
- Limity importu:
  - soubor se čte po řádcích a v paměti drží jen období kontraktu;
  - nejvýš 100 MB (`HS_MAX_IMPORT_BYTES`) a 300 s;
  - posun pásma se cachuje po hodinách.
  - Naměřeno: 3 roky 1m svíček (61 MB) za 12 s a 10 MB paměti.
- Fatální chyba PHP v API vrátí JSON 500 s českou hláškou (`register_shutdown_function`
  v `api.php`). Klient ukáže srozumitelnou větu i u 413 a 5xx a při přerušeném spojení.
- Vzorek skutečného exportu je `Chart.csv` na `main`:
  `git show origin/main:Chart.csv > $S/Chart.csv`.

## Graf (`hindsight.js`)

- Lightweight Charts neumí seance ani zóny, takže všechno kreslí primitive `layers`
  (background a foreground renderer): `drawRail`, `drawSessionLevels`, zóny, záře seancí,
  bias shora, obchody.
- **Pás seancí** je dole místo objemu (`RAIL_H` 28, `RAIL_PAD` 6).
  - Najetí ukáže high a low seance.
  - Klik seanci přiblíží, další klik vrátí předchozí zobrazení (`state.sessionZoom`,
    `toggleSessionZoom`). Kurzor ukazuje lupu + nebo −.
  - Ctrl + kolečko nebo přepnutí „Den po dni“ přepínač vynuluje.
- **Svislé měřítko**:
  - při posunu se cena sama centruje (`priceScale`, `followPrice`, `autoPrice`);
  - ruční tažení osy vypne automatiku; tlačítko AUTO (`#hsScale`) nebo klávesa A ji zapne.
- **Režim „Den po dni“** (dřív „Kolotoč“; `prefs.snap`): kolečko a šipky skáčou po dnech.
- **Paleta**:
  - svíčky up `#00AC7C`, down `#CC3148`;
  - seance asia `#814EC6`, eu `#08A4C6`, ny `#CB8117`;
  - ověřené validátorem palety (skill dataviz).
- **Klávesy**:
  - ←/→ den, Home/End;
  - Z (+ tažení) zóna;
  - L/S + klik potenciální obchod;
  - E panel vyhodnocení, A auto měřítko, Esc.
- **Zámek při otevření NY**: zóny a bias se při otevření RTH zamknou a vzniknou verze.
  Pozdější změny jsou „dodatečné“ a vyhodnocení je standardně nepočítá (jde to přepnout
  v pravidlech vyhodnocení). Správce má režim Zpětně (`#hsBackfill`) pro doplňování historie.

## Testy a ověření v prohlížeči

- `tests/test_hindsight_http.py`: API, import, kontrakty a roll, zámek.
- `tests/test_hindsight_time.js`, `tests/test_hindsight_eval.js`.
- Lokální server a data:

  ```bash
  TRADING_DATA_DIR=$S/hs php -d upload_max_filesize=100M -d post_max_size=128M -S 127.0.0.1:8475 router.php &
  curl -s --noproxy '*' 'http://127.0.0.1:8475/api.php?action=auth_state'   # vytvoří $S/hs/setup-token.txt
  ```

- Pak v Playwrightu (`page.request`):
  1. `POST api.php?action=setup` s `{token, login, display_name, secret_mode: 'key'}`;
  2. `POST api.php?action=hindsight_import` (multipart: `contract` např. `ESZ6`,
     `tz: 'Europe/Prague'`, `file`);
  3. otevřít `hindsight.php`.
- Pro měření v testu (viditelný rozsah a podobně) si obal `createChart` před načtením stránky:

  ```js
  await page.addInitScript(() => {
    let lib;
    Object.defineProperty(window, 'LightweightCharts', { configurable: true, get: () => lib,
      set(v) { const create = v.createChart; lib = { ...v, createChart(...a) { return (window.__chart = create(...a)); } }; } });
  });
  // pak: window.__chart.timeScale().getVisibleLogicalRange()
  ```

- Pás seancí je nad časovou osou, zhruba 40 px nad spodním okrajem `#hsChart`. Spolehlivě ho
  najdeš tak, že jedeš myší odspodu nahoru, dokud `#hsChart` nedostane třídu `is-zoom-in`.

## Otevřené

- Import obchodů z CSV. Čeká se na vzorek exportu od uživatele.
