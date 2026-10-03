# Trading Desk - lokální denní analýza a journal

Samostatná webová aplikace pro Ubuntu/Apache. Běží pod `/trading/`, takže nemění existující web na `/` ani jeho `/api/*`.

## Co umí

- **účty se schvalováním**: registraci každého člena schvaluje správce, každý má vlastní, oddělený deník,
- **šifrovaný deník na přání**: místo hesla přístupový klíč, deník i screenshoty jsou na disku zašifrované a správce je nepřečte,
- **společná nástěnka**: sdílení náhledů, obchodů a strategií, vlastní příspěvky s grafy, komentáře a reakce,
- **správa členů**: schvalování, blokace, role správce, obnova hesla, uzavření registrací,
- **prostředí na míru**: každý trader si v Nastavení zvolí metodiku (Market Profile, DiNapoli nebo obojí), zapne jen ty prvky náhledu a zápisu obchodu, které používá, přidá vlastní pole, vlastní trhy s hodnotou bodu, výchozí hodnoty a moduly v menu,
- **DiNapoli levely**: zadáváš F3, F5, F7 a expanze COP, OP, XOP s timeframem a stavem naked / revisited; aplikace sama najde konfluenci (dva F5) a shodu (expanze u retracementu) podle tolerance, kterou si nastavíš pro každý timeframe; k tomu trend podle DMA 3x3, 7x5 a 25x5 a vzory (Double Repo, Railroad Tracks, Single Penetration…),
- **vlastní pole a jejich rozbor**: ano/ne, výběr, hodnocení 1–5, číslo i text; u obchodů aplikace spočítá, jak se ti daří podle každé hodnoty,
- **týdenní i denní náhled** trhu postavený pro Market Profile: bias z price action (Monthly, Weekly, Daily) a z MP/VP (Weekly, Daily), tvar profilu P, b, D, B, poloha close vůči value, reference na dojetí (single prints, poor high/low, naked POC…),
- pole o otevření trhu (Globex, EU, RTH, typ otevření, Initial Balance) jsou zamčená, dokud jejich čas nenastane; o víkendu se náhled sám nastaví na pondělí,
- zóny se směrem long, short nebo obojí a s definicí, co se musí splnit pro vstup a kdy obchod nebrat, zvlášť pro každý směr,
- u každé zóny automaticky poloha vůči value minulého týdne (ve VAL, v oblasti VAL, pod VAL, totéž u VAH); denní náhled si ji bere z týdenního,
- mapa ceny, která ukáže zóny, levely, value a reference na jedné ose,
- klíčové levely jako horizontální úrovně s cenou, charakterem a stylem čáry,
- srovnání výkonnosti jednotlivých strategií a setupů,
- měsíční kalendář s výsledky dnů, red news a svátky,
- **Hindsight**: rok 5m svíček ES na jedné souvislé ose se seancemi, tvými zónami, biasem, red news, potenciálními a realizovanými obchody; zóny, bias i potenciální obchody se zadávají přímo v grafu a jsou stejné jako v denním náhledu,
- vstupní psychologický profil se silnými a rizikovými oblastmi, ověřený proti vlastním obchodům,
- **osobnost a silné stránky**: osobnostní test Big Five (IPIP) a talenty z reportu Gallup CliftonStrengths s výkladem pro trading; rychlý test se podle nich zaměří na tvoje slabá místa,
- **dýchání před seancí**: kruh, který se s nádechem zvětšuje a s výdechem zmenšuje, rytmy 4–8 a box 5–5–5–5 a pět zvukových kulis vybraných podle osobnosti,
- rychlý test psychiky přizpůsobený profilu, s vlastními pravidly pro špatný den,
- pravidlový rozbor dnů s porušenými pravidly,
- potenciální obchody s entry, SL, TP1, TP2, finálním TP a R:R,
- rozlišení Intraday a Hybrid Intraday obchodů,
- screenshoty grafů uložené jako soubory na serveru,
- knihovnu strategií a setupů s timeframem, charakterem systému a ukázkovými grafy,
- obchodní účty s pevným vstupním stavem konta a riskem na den,
- Money audit, který každých 30 dní ověří, jestli jsou zapsané všechny obchody,
- obchodní deník s automatickým výpočtem P&L a R pro ES, NQ, GC, CL, 6E a vlastní trhy,
- přehled s připraveností na nejbližší session (týdenní a denní náhled, test psychiky, red news, Money audit), průměrem R na obchod, profit factorem, dodržením plánu a equity v R,
- historie denních i týdenních náhledů,
- **obchodní plán**: účty a risk, obchodní okna, bias, stavba zón, strategie s podmínkami, rutina, psychika a review v jednom dokumentu s verzemi; vzorový návrh pro Market a Volume Profile a export do PDF,
- PDF náhledu ve vzhledu aplikace: nahoře bias, pod ním krátký popis trhu, mapa ceny a očíslované zóny s podmínkami,
- export zón, levelů, hodnot profilu a otevřených referencí do TradingView jako Pine Script v6,
- tmavý i světlý vzhled s většími a čitelnějšími popisky,
- kompletní ZIP záloha SQLite databáze a screenshotů plus JSON export.

Data nejsou ukládána do `localStorage`. Zdroj pravdy je SQLite na serveru a adresář screenshotů, pro každého člena zvlášť.

## Instalace

Návod pro Windows, Mac i Linux psaný pro netechnické uživatele je v souboru [INSTALL.md](INSTALL.md). Níže je zkrácený postup pro Ubuntu.

## Spuštění na GitHubu bez instalace

[![Open in GitHub Codespaces](https://github.com/codespaces/badge.svg)](https://codespaces.new/ecko456/trading_desk?quickstart=1)

Aplikace potřebuje PHP server, takže na GitHub Pages běžet nemůže. Spustí se ale v GitHub Codespaces, tedy v soukromém počítači v cloudu, který patří jen tobě. Konfigurace je v `.devcontainer/`: při prvním vytvoření se nainstaluje PHP a knihovny pro PDF a při každém startu se sama spustí aplikace na portu 8420. Kód pro založení správce vypíše terminál. Postup krok za krokem a na co si dát pozor je v [INSTALL.md](INSTALL.md), varianta D.

## Nasazení na Ubuntu

1. Přenes celý adresář `trading-web` na Ubuntu, například do `/tmp/trading-web`.
2. V jeho kořeni spusť:

```bash
sudo bash deploy/install.sh
```

3. Otevři:

```text
http://localhost/trading/
```

Instalátor pouze přidá Apache alias `/trading/`. Existující aplikace na `/` zůstane beze změny.

Na stejném serveru běží i aplikace **Odměny** (hodnocení operátorů). S Trading Deskem nemá nic
společného a má vlastní repozitáře: ostrá verze [`odmeny`](https://github.com/ecko456/odmeny)
na `/odmeny/` a verze 2 [`odmeny_v2`](https://github.com/ecko456/odmeny_v2) na `/odmeny_v2/`.

## Uložení dat

Výchozí produkční umístění:

```text
/var/lib/trading-journal/app.sqlite3                       členové, relace, nástěnka
/var/lib/trading-journal/users/<id>/trading.sqlite3        deník člena bez šifrování
/var/lib/trading-journal/users/<id>/trading.sqlite3.sealed deník člena se šifrováním
/var/lib/trading-journal/users/<id>/uploads/               screenshoty člena
/var/lib/trading-journal/wall/                             obrázky sdílené na nástěnce
/var/lib/trading-journal/market.sqlite3                    svíčky ES pro Hindsight (společné, nahrává správce)
```

Apache do adresáře zapisuje jako `www-data`. Data nejsou veřejně dostupná; obrázky se čtou přes `file.php`, a to jen z deníku přihlášeného člena nebo z nástěnky.

Deník z verze 16 a starší (`trading.sqlite3` přímo v datovém adresáři) si při založení převezme první správce. Když si při tom zvolí šifrování, deník se rovnou zašifruje.

## Ruční Apache konfigurace

Pokud nechceš použít instalátor, zkopíruj aplikaci do `/var/www/trading-journal`, vytvoř `/var/lib/trading-journal`, nainstaluj `php-sqlite3`, `php-mbstring` a `php-zip` a aktivuj obsah souboru `deploy/apache-trading.conf`.

Před reloadem vždy ověř konfiguraci:

```bash
sudo apache2ctl configtest
sudo systemctl reload apache2
```

## Zálohování

V aplikaci otevři záložku **Záloha** a stáhni kompletní ZIP. Obsahuje konzistentní snapshot tvého deníku, tvoje screenshoty a manifest. U šifrovaného deníku je ZIP odemčený, aby šel otevřít i bez aplikace, takže ho ulož na bezpečné místo.

Správce serveru zálohuje celý adresář `/var/lib/trading-journal`. Šifrované deníky v něm zůstávají zapečetěné a bez klíčů jejich majitelů jsou nečitelné.

## Účty, šifrování a nástěnka

### První správce

Po instalaci aplikace nemá žádný účet. Na přihlašovací stránce se ukáže **Založení správce**, které chce jednorázový kód. Kód leží jen na disku serveru a do prohlížeče se nikdy neposílá. Vypíše ho instalátor, terminál Codespace i spouštěč na Macu, případně:

```bash
sudo -u www-data TRADING_DATA_DIR=/var/lib/trading-journal php /var/www/trading-journal/bin/setup-token.php
```

Po založení správce kód přestane platit.

### Registrace a schválení

Kdo zná adresu, může se zaregistrovat. Přihlásí se ale až po schválení správcem v sekci **Členové**. Tam správce také blokuje a maže účty, uděluje práva správce, obnovuje zapomenuté heslo (vygeneruje dočasné, které si člen po přihlášení změní) a může registrace úplně uzavřít. Obsah deníků správce nevidí, jen jméno, e-mail, stav a velikost dat.

Přihlášení je chráněné proti hádání: po osmi neúspěšných pokusech na jeden účet se další zkoušky na čtvrt hodiny odmítnou, stejně jako nadměrný počet registrací z jedné adresy.

### Šifrovaný deník

Při registraci, nebo později v **Profilu**, jde místo hesla zvolit **šifrovaný deník**. Aplikace vygeneruje přístupový klíč (160 bitů, osm skupin po čtyřech znacích), který se ukáže jen jednou. Deník i všechny screenshoty se pak ukládají zašifrované (libsodium, XChaCha20-Poly1305):

- náhodný datový klíč deníku je na disku zabalený klíčem odvozeným z přístupového klíče,
- po přihlášení se datový klíč zabalí ještě tokenem relace, který má jen prohlížeč v cookie,
- při každém požadavku se deník odemkne jen do paměti a po uložení se znovu zapečetí.

Bez aktivní relace člena tak deník neotevře nikdo, ani s přístupem k disku nebo zálohám serveru. **Ztracený klíč nejde obnovit** a správce ho resetovat nemůže. Nový klíč si člen vydá v profilu; data se nepřešifrovávají, jen se vymění zámek.

Co šifrování nechrání: správce serveru, který by upravil kód aplikace a zachytil klíč při přihlášení. Proti tomu by pomohlo jen šifrování přímo v prohlížeči.

### Společná nástěnka

Náhled, obchod nebo strategii sdílíš tlačítkem **Sdílet** v náhledu, deníku, historii nebo u strategie, případně na nástěnce přes **Sdílet z deníku**. Na nástěnku se uloží **snímek** v okamžiku sdílení: šifrovaný deník zůstane šifrovaný a na nástěnce je jen to, co jsi výslovně zveřejnil. U obchodu se částky v dolarech a poznámky s emocemi sdílí, jen když je zaškrtneš; jinak ostatní vidí výsledek v R. Opětovné sdílení snímek aktualizuje a komentáře zůstanou.

Na nástěnku jde psát i vlastní příspěvky s až šesti grafy. Pod každým příspěvkem jsou komentáře a reakce (Líbí se, Silné, Přesné, Zajímavé). Autor může mazat komentáře pod svým příspěvkem, správce moderuje celou nástěnku. V menu se ukazuje počet nových příspěvků od poslední návštěvy.

## Nastavení: prostředí na míru

Každý člen má vlastní **Nastavení** (v menu *Můj desk*). Při prvním přihlášení se otevře krátký průvodce: metodika, trhy, výchozí risk a moduly. Spustit ho jde znovu kdykoli v Nastavení → Moduly.

- **Metodika**: Market Profile, DiNapoli, nebo obojí. Podle volby se náhled trhu sám upraví: DiNapoli skryje profil, reference a otevření podle Daltona a přidá DiNapoli levely; Market Profile je naopak schová. Tvoje ruční volby prvků, které k metodice nepatří, zůstanou.
- **Náhled trhu**: vypínače pro každý prvek náhledu (bias z price action, bias z MP, tvar profilu, hodnoty profilu, reference, trend podle DMA, DiNapoli levely, vzory, otevření, časová osa, podmínky zón, štítky, TP, levely, scénáře, grafy a pravý panel). Skryté části se nezobrazují, nepočítají se do připravenosti a už vyplněná data zůstanou uložená. Vedle je živý náhled, jak bude formulář vypadat. Štítky zón (VAH, F5, COP…) jde doplnit o vlastní.
- **Zápis obchodu**: vypínače polí dialogu obchodu (účet, session, strategie, TP, poplatky, dodržení plánu, hodnocení, emoce, chyba, poznámky, screenshoty) a výchozí hodnoty pro nový obchod: trh, session, risk, poplatky a účet.
- **Vlastní pole**: pro obchod i pro náhled. Typy *ano / ne*, *výběr z možností*, *hodnocení 1–5*, *číslo*, *krátký* a *delší text*. Pole jde přejmenovat, přesunout, archivovat (hodnoty zůstanou) nebo smazat. Pole obchodu můžeš zobrazit jako sloupec v deníku. Nápady jedním kliknutím: *Čekal jsem na potvrzení*, *Kvalita setupu A+/A/B*, *Soulad s vyšším TF*…
- **DiNapoli**: tolerance v bodech pro každý timeframe, zvlášť pro konfluenci a pro shodu (viz níže). Timeframy jde přidat, přejmenovat i odebrat.
- **Moje trhy**: symboly, hodnota bodu (pro výpočet velikosti pozice a R) a čas RTH open v New Yorku (pro časovou osu a zamykání polí). Základní trhy ES, NQ, YM, RTY, GC, CL a 6E jdou upravit nebo vrátit do výchozího stavu.
- **Moduly**: co je v menu. Nástěnka, historie, kalendář, strategie, psychika a účty jde vypnout; přehled i připravenost na session se přizpůsobí.

Nastavení je uložené v deníku daného člena, takže u šifrovaného deníku je šifrované také.

### Rozbor vlastních polí

V části **Strategie** je pod výkonností strategií rozbor vlastních polí obchodu: pro každou hodnotu (Ano / Ne, A+ / A / B, hodnocení 1–5) počet obchodů, průměrné R a součet R. Číselná pole se rozdělí podle mediánu. Ukáže se tak třeba, jestli obchody s potvrzením opravdu vycházejí lépe. Úspěšnost v procentech se schválně nepočítá; rozhoduje průměrné R.

## DiNapoli levely

Když máš v metodice DiNapoli, objeví se v náhledu krok **DiNapoli**:

1. **Trend podle DMA**: poloha ceny nad nebo pod 3x3, 7x5 a 25x5 a směr thrustu.
2. **Levely**: každý level zadáš tak, jak ho vidíš v grafu: **timeframe**, **typ** (retracement F3, F5, F7 nebo expanze COP, OP, XOP), **stav** (*naked* = cena se k němu ještě nevrátila, *revisited* = už byl navštívený), **hladinu** a volitelně poznámku. Enter v ceně posledního levelu přidá další řádek se stejným timeframem a typem.
3. **Konfluence a shoda**: aplikace porovná levely **stejného timeframu**:

```text
Konfluence   dva F5 levely, které jsou od sebe nejvýš o toleranci konfluence
Shoda        expanze (COP, OP, XOP) u retracementu (F3, F5, F7), nejvýš o toleranci shody
```

   Tolerance v bodech se nastavuje v **Nastavení → DiNapoli** pro každý timeframe zvlášť, jinou pro konfluenci a jinou pro shodu. Výchozí timeframy jsou M5, M15, M30, H1, H4, D1, W1, měsíční **MN** a čtvrtletní **Q**, všechny s tolerancí 5 bodů; u vyšších timeframů si ji nejspíš zvětšíš. Víc levelů, které na sebe navazují, tvoří jedno pásmo. Řádky, které do konfluence nebo shody patří, se v seznamu zvýrazní; pásmo s revisited levelem má čárkovaný okraj. Tlačítkem **Udělat zónu** z pásma vznikne obchodní zóna, **Do klíčových levelů** přenese všechny levely mezi klíčové levely.
4. **Vzory**: Double Repo, Single Penetration, Railroad Tracks, Failure, Bread & Butter, Minesweeper A a B a Fib Node jako štítky, plus poznámky.

DiNapoli levely se ukážou na mapě ceny, v pracovním závěru, v PDF, ve sdíleném náhledu na nástěnce a volitelně v TradingView exportu. Výpočet v prohlížeči i na serveru je stejný a testy to hlídají. Swingy zadané ve starší verzi (A, B, C) se při aktualizaci samy převedly na levely F3, F5, COP, OP a XOP bez timeframu; timeframe jim doplníš v náhledu.

## Náhled trhu: týdenní a denní

Nahoře v náhledu přepínáš **Denní** a **Týdenní**. Týdenní náhled se vždy vztahuje k pondělí daného týdne; když vybereš jiný den, datum se samo posune na pondělí. Denní a týdenní náhled mohou existovat pro stejný den vedle sebe.

Náhled pozná, kdy ho tvoříš. Zjistí nejbližší obchodní session: o víkendu a po zavírce RTH je to další obchodní den, jinak dnešek. Nahoře ukáže, v jaké fázi trh je, a časovou osu otevření:

```text
Globex    18:00 New York předchozího dne  (v Praze obvykle 00:00)
EU open   09:00 Praha
RTH open  09:30 New York u ES, NQ, YM, RTY  (CL 09:00, GC a 6E 08:20)
IB        RTH open + 60 minut
```

Pole, která se k těmto časům váží, jsou do té doby **šedá a zamčená**. V neděli tak při tvorbě zón nejde vyplnit, jak otevřela EU nebo RTH. Odemknou se samy, i když necháš náhled otevřený. RTH se počítá v newyorském čase, takže sedí i v týdnech, kdy USA a Evropa přecházejí na letní čas v jiný den. Hodnota v zamčeném poli se nikdy neztratí; uloží se i tak.

Náhled má sedm kroků. Nahoře vidíš, které jsou hotové, a celkové procento:

1. **Bias** z price action (Monthly, Weekly, Daily) a z Market Profile / Volume Profile (Weekly, Daily). Aplikace ukáže, jestli jsou timeframy v souladu. Nový denní náhled si Weekly bias sám převezme z týdenního náhledu stejného týdne a trhu, ale jen do prázdných polí.
2. **Profil** předchozího dne nebo minulého týdne: tvar P, b, D, B nebo trendový, hodnoty High, VAH, POC, VAL, Low a Close, migrace value a POC, stav aukce, single prints a excess. Kde trh zavřel vůči objemu, se doplní samo z Close, VAH, POC a VAL. Tlačítkem **Přenést hodnoty do levelů** z nich uděláš levely PW VAH, PD POC a podobně.
3. **Reference na dojetí**: single prints, poor high, poor low, naked POC, gap, excess, LVN. U každé je vidět, jak daleko je nad nebo pod close. Z reference jde jedním kliknutím udělat zónu.
4. **Otevření**: Globex, EU, RTH, typ otevření podle Daltona a Initial Balance. Pole se odemykají podle času.
5. **Zóny a levely**. U zóny vybereš, co na ní budeš obchodovat: **Long**, **Short**, nebo **Long i short**. Podle toho se objeví pole *co se musí splnit, abych vstoupil* a *kdy obchod neberu*, u obou směrů zvlášť. Náhled se stavem **Připravený** nejde uložit, dokud u některého vybraného směru podmínka chybí; rozpracovaný jde uložit vždy a chybějící pole se zvýrazní. Zdroj a konfluenci vybíráš štítky (VAH, VAL, POC, nPOC, SP, poor high…). U zóny se ukáže šířka a RR k TP1 od hrany, na kterou cena přijde jako první.
6. **Scénáře** potenciálních obchodů s TP.
7. **Závěr a rizika**: pracovní bias, popis trhu, pracovní závěr složený z vyplněných polí, red news a no-trade podmínky.

### Poloha zóny vůči týdenní value

Týdenní náhled si pamatuje VAH a VAL minulého týdne z kroku 2. Každá zóna pak dostane štítek, kde leží:

```text
Ve VAL předchozího týdne            zóna přímo zasahuje do VAL
V oblasti VAL, těsně pod / nad ní   do vzdálenosti šířky zóny, nejméně 5 % šířky value
Pod VAL předchozího týdne           dál pod VAL
Uvnitř value, nad / pod POC         uprostřed value
Ve VAH / v oblasti VAH / nad VAH    totéž u horní hrany
```

Denní náhled si value bere z týdenního náhledu stejného týdne a trhu. Když týdenní náhled chybí, aplikace to napíše a nabídne ho vytvořit. Stejné hodnocení je na obrazovce i v PDF.

### Mapa ceny

Vpravo v náhledu je svislá osa se všemi cenami: value a POC předchozího období, týdenní value, zóny barevně podle směru, levely a otevřené reference. Hned je vidět, co je nad a co pod cenou a kde se reference kryjí se zónou.

### Přehled a připravenost

Na přehledu nahoře je **připravenost na nejbližší session**: týdenní náhled, denní náhled, dnešní test psychiky, red news a svátky z kalendáře a stav Money auditu. Chybějící krok jde rovnou vytvořit nebo spustit.

## Strategie a setupy

V dialogu **Přidat obchod** je pole *Strategie / setup* rozevírací seznam. Poslední položka **+ Přidat strategii / setup** otevře panel, kde zadáš název, timeframe, na kterém systém obchoduješ, charakter systému (trendový, reversal, nebo obojí), pravidla a obrázky strategie. Nová strategie se rovnou předvyplní do obchodu. Tlačítkem **Upravit** vedle seznamu otevřeš vybranou strategii k doplnění. Strategii jde přidat i přímo v záložce **Strategie** (viz níže).

Obrázky strategie můžou být PNG, JPEG, WebP nebo **SVG** (schéma setupu). První obrázek je náhled strategie. SVG jde nahrát jen ke strategii, ne ke grafům náhledu nebo obchodu. Před uložením se vyčistí: zůstane jen kresba a text, skripty, vložené HTML, animace a odkazy na cizí servery se zahodí. Soubor s vlastními entitami nebo DTD se odmítne. Prostý řádek `<!DOCTYPE svg …>`, jaký přidává třeba Illustrator, nevadí.

Přejmenování strategie přepíše název i u všech navázaných obchodů. Smazání strategie obchody zachová, jen ztratí odkaz.

## Risk na trade a výpočet R

Místo počtu kontraktů se zadává **risk na trade v dolarech**. Z něj a ze vzdálenosti mezi entry a stop lossem vychází všechno ostatní:

```text
risk na bod = risk / |entry - stop|
čisté P&L   = (exit - entry) * směr * risk na bod - poplatky
výsledek R  = čisté P&L / risk
```

Výsledné R je tedy vždy počítané z entry a exitu a poplatky jsou už započítané. Přímo v dialogu se pod formulářem průběžně ukazuje dopočtená velikost pozice, hrubý i čistý výsledek a R. Tlačítko **Vypočítat a doplnit R a výsledek** hodnoty zapíše do polí *Výsledek R* a *Výsledek $*.

Všechny počítané hodnoty se zaokrouhlují na dvě desetinná místa, aby formulář šlo vždy uložit. Starší záznamy s delším desetinným rozvojem se při otevření k úpravě zaokrouhlí také.

Hodnota bodu se nikde nezadává, protože se ve vzorci vykrátí. Když si dosadíš `kontrakty = risk / (|entry − stop| × hodnota bodu)` do výpočtu P&L, hodnota bodu se vyruší a zbyde jen risk na bod pohybu, tedy `risk / |entry − stop|`. Díky tomu funguje výpočet i pro trh, který aplikace nezná.

*Trh* i *Typ obchodu* jsou volně psaná pole s našeptávačem. Nabízí se běžné kontrakty a typy, ale můžeš zapsat cokoli vlastního; jednou použitá hodnota se objeví v našeptávači i ve filtrech. U známých kontraktů (ES 50, NQ 20, GC 100, CL 1000, 6E 125000, mikra odpovídajícím dílem) se navíc orientačně ukáže i počet kontraktů.

Pole *Hodnocení obchodu* je stupnice 1 až 5, kde 1 znamená vše splněno podle plánu a 5 nedodržená pravidla exekuce. *Emoce* jsou zaškrtávací a můžeš označit více stavů najednou, od klidu a disciplinované trpělivosti přes FOMO a chamtivost až po revenge trading. Ukládají se jako seznam oddělený čárkou.

## Strategie

Vpravo je sloupec **Moje strategie**: tlačítko **+ Přidat strategii** a karta každé strategie s náhledovým obrázkem, timeframem, popisem a výsledkem v R. Klik na kartu otevře rychlý náhled se všemi obrázky (klik na obrázek ho zvětší), pravidly a statistikou. Z náhledu se dá strategie upravit nebo otevřít její obchody v deníku. Při víc než šesti strategiích se nad kartami objeví hledání. Na tabletu a mobilu je sloupec nahoře a karty se posouvají do strany.

Vlevo zůstávají statistiky. Záložka **Strategie** srovnává setupy, které přiřazuješ obchodům. U každého vidíš počet obchodů, celkové R, průměrné R na obchod, profit factor, procento dodržení plánu, průměrné hodnocení exekuce a datum posledního obchodu. Řadí se podle celkového R, takže nahoře je to, co ti skutečně vydělává.

Zvlášť je vyčíslený řádek **Bez strategie** — obchody, které nemají přiřazený setup a nejdou tedy vyhodnotit. Tlačítkem *Obchody* se prokliknéš do deníku s předvyplněným filtrem.

Win rate se tu záměrně nepočítá. Rozhoduje expectancy v R a profit factor.

Nad tabulkou je graf **kumulativního R v čase**, kde má každá strategie vlastní barevnou linii. Osa X jsou obchodní dny, osa Y kumulativní R. Linie začíná až u prvního obchodu dané strategie a mezi jejími obchody se drží na poslední hodnotě, takže jdou křivky navzájem porovnat i když každý setup obchoduješ jindy.

## Kalendář

Měsíční mřížka, kde každý den ukazuje výsledek v R, počet obchodů a značky: **N** pro existující denní náhled, **P** pro vyplněný rychlý test psychiky a **!** pro den s porušenými pravidly. Zelené a červené podbarvení odpovídá výsledku dne.

Kliknutím na den otevřeš detail, kde můžeš přidat **red news** (s časem a dopadem), **svátek** nebo poznámku. Pokud na ten den existuje náhled, otevřeš ho přímo odtud.

## Hindsight

Samostatná stránka přes celou obrazovku (menu **Hindsight**, adresa `hindsight.php`). Jedna souvislá časová osa 5m svíček ES až rok zpátky, na které jsou vidět zóny, bias a red news každého dne. Odpovídá na tři otázky: drží moje zóny, sedí můj bias, kde nechávám obchody na stole.

### Ovládání

- **kolečko** posouvá v čase se setrvačností, **Ctrl + kolečko** přibližuje kolem kurzoru, graf jde i táhnout myší,
- **svislé měřítko** drží svíčky v obraze samo (AUTO v rohu pod cenovou osou). Když si ho ručně přiblížíš tažením cenové osy, zvolený rozsah zůstane, ale při posunu v čase se plynule dorovná, takže svíčky nikdy neutečou z obrazu; **A**, klik na AUTO nebo dvojklik na osu vrátí plnou automatiku,
- **pás seancí** dole v grafu (místo objemu): Asie, Evropa a New York v barvách seancí, u každé čas v Praze, rozsah high–low a změna open → close; seance pod kurzorem se zvýrazní, najetí na pás ukáže high a low seance (do konce dne tečkovaně) a klik seanci přiblíží; další klik na pás vrátí předchozí zobrazení (i s režimem Den po dni). Oddálený graf ukazuje jen rytmus seancí. Seance svítí zdola (záře od pásu), bias dne shora,
- svíčky: rostoucí dutá, klesající plná (rozdíl je vidět i tvarem, nejen barvou),
- **← →** skočí na předchozí a další den, **Home / End** na první a poslední,
- **datum** nahoře nebo **minimapa** dole (celý rok, barevný pruh = bias vyšel / nevyšel) skočí kamkoli, okno v minimapě jde táhnout,
- **Den po dni** drží na obrazovce vždy přesně jeden obchodní den, kolečko a šipky pak listují po dnech,
- **hlavička dne** nahoře ukazuje datum, šipku biasu, ✓/✗ (RTH close proti RTH open), počet obchodů a P&L dne; klik na ni nastaví bias a poznámku,
- **Z + tažení** v grafu nakreslí zónu (nebo tlačítko + Zóna): typ support / resistance / VPOC / jiná, popisek, poznámka a platnost *jen tento den*, *do data* nebo *dokud ji neukončím*; klik na zónu ji upraví, ukončí k danému dni nebo smaže,
- **L / S + klik** (nebo tlačítka + Long / + Short) přidá potenciální long nebo short v místě kliknutí: vstup je cena a čas kliknutí, stop je dvojnásobek průměrného rozpětí svíčky toho dne a cíl 2R. Stop, vstup i cíl jde táhnout za čtverečky vpravo nebo přepsat v okénku; tam se zadá i výsledek *nevzatý / propáslý / vzatý* a propojení s obchodem z deníku,
- klik na **box pozice** potenciální obchod upraví nebo smaže, klik na **šipku obchodu** ukáže detail a doplní časy vstupu a výstupu,
- **vrstvy** (seance, news, zóny, bias, potenciální, realizované) jdou vypnout; volba i režim Den po dni se pamatují pro každého tradera,
- zóny a bias se při otevření NY **zamknou**, pozdější změny jsou dodatečné verze (viz níže),
- **E** (nebo tlačítko Vyhodnocení) vysune panel s vyhodnocením: drží moje zóny, sedí můj bias, kde nechávám obchody na stole.

### Potenciální a realizované obchody

Potenciální obchod je **scénář denního náhledu ES** (vstup, stop loss, TP): co přidáš v Hindsightu, je v náhledu mezi scénáři, a scénář z náhledu se ukáže v grafu. Nová pole *Výsledek* (nevzatý, propáslý, vzatý) a čas vstupu z grafu editor náhledu zachová; vzatý obchod se v náhledu označí jako *Realizovaný*.

V grafu je potenciální obchod čárkovaný **box pozice**: zelená část od vstupu k cíli, červená ke stopu. Hindsight ho vyhodnotí proti svíčkám: od času vstupu (u scénáře bez času od začátku dne) čeká, až cena sáhne na vstup, a pak rozhodne, jestli přišel dřív stop, nebo cíl. Svíčka, která zasáhne obojí, se počítá jako stop (horší případ, u výsledku je otazník). Když nepřijde ani jedno, výsledek je k poslední svíčce dne. Popisek ukáže R a výsledek, třeba `L VAL reject · 2.0R · TP +2.0R · propáslý`.

Realizované obchody jsou **obchody ES/MES z deníku**: šipka vstupu a výstupu se špičkou přesně na ceně, spojené čarou v barvě výsledku (zelená zisk, červená ztráta). Aby seděly v čase, potřebují čas vstupu a výstupu: v dialogu obchodu přibyla pole *Čas vstupu* a *Čas výstupu* (pražský čas), nebo je doplníš v Hindsightu (klik na hlavičku dne ukáže obchody dne, i ty bez času). Večerní čas před obchodním dnem (Asie) se přiřadí správně i v týdnech, kdy USA a Evropa mají jiný letní čas. Import obchodů z CSV (bez duplicit podle ID z platformy) přijde později.

Seance se počítají v newyorském čase (Asie 18:00–03:00, Evropa 03:00–09:30, New York = RTH 09:30–16:15 ET, v Praze běžně 15:30–22:15) a zobrazují v pražském čase. Letní čas USA a Evropy se mění v jiné týdny; posun se počítá pro každý okamžik zvlášť, takže seance sedí na minutu i v březnu a na přelomu října a listopadu. Obchodní den začíná v 18:00 New York předchozího dne.

Zóny a bias jsou **stejná data jako denní náhled ES** (případně MES): co zadáš v Hindsightu, uvidíš v náhledu, a naopak. Zóna ze staršího náhledu platí jen svůj den; v editoru zóny v náhledu je nově *Platnost zóny*, *Typ zóny* a *Poznámka k zóně*. Zadávat jde i zpětně, třeba pro prezentaci. Red news bere Hindsight z kalendáře (dopad *vysoký* nebo nezadaný) v čase, který je u nich uvedený (pražský čas).

### Svíčky: import z ATAS

Svíčky jsou tržní data, ne osobní deník, proto jsou společné pro všechny tradery v `market.sqlite3` a nahrává je jen správce (tlačítko **Data** v Hindsightu). Časy se ukládají v UTC.

1. V ATAS vyexportuj 5m (nebo 1m) svíčky **konkrétního kontraktu**, třeba ESZ6. Export CSV z ATAS má datum ve tvaru rok-den-měsíc a pražský čas, to import pozná sám.
2. V dialogu **Data** vyber kontrakt. Nic není předvybrané: kontrakt se volí při každém importu.
3. Uloží se **jen svíčky z období vybraného kontraktu**: od rollu předchozího kontraktu do vlastního rollu. Roll je ve čtvrtek osm dní před expirací (třetí pátek v březnu, červnu, září a prosinci) a přechází se na začátku obchodního dne, v 18:00 New York den předem. Například ESZ6 = obchodní dny 10. 9. 2026 až 9. 12. 2026.
4. Svíčky mimo období import přeskočí a řekne, ke kterému kontraktu patří. Pro to období vyexportuj z ATAS přímo ten kontrakt.

Proč po kontraktech: spojitý (continuous) export z ATAS nemá v den rollu skok, protože starší kontrakty v něm mají posunuté ceny. Zóny, obchody a news ale musí sedět na skutečných cenách daného kontraktu. Graf pak skládá kontrakty za sebou bez úprav cen a den rollu označí svislou čarou `ROLL ESU6 → ESZ6`.

Opakovaný import stejného souboru nic nezdvojí, svíčky se jen přepíšou. 1m svíčky se sloučí do 5m. Když z dat nejde poznat, jestli je v datu napřed den, nebo měsíc (třeba soubor jen s 3. 4.), import se zeptá na formát. Soubor může mít nejvýš 100 MB (rok 1m svíček je kolem 20 MB, rok 5m kolem 4 MB). Import čte soubor po řádcích a v paměti drží jen období vybraného kontraktu, takže zvládne i dlouhý spojitý (continuous) export; svíčky jiných kontraktů jen spočítá. Kdyby server přesto nestihl import dokončit (čas, paměť), aplikace to napíše místo prázdné stránky. Dokud nejsou nahraná skutečná data, jde modul vyzkoušet na **ukázkových datech** (vymyšlených, jedním kliknutím smazatelných).

Graf kreslí [TradingView Lightweight Charts™](https://www.tradingview.com/) (Apache 2.0), písma jsou Inter a JetBrains Mono (SIL OFL). Vše je přibalené ve `static/hindsight/`, stránka nic nenačítá z cizích serverů.

### Zámek při otevření NY a verze

Zóny a bias denního náhledu ES/MES se **zamknou při otevření NY** (9:30 New York, v Praze 15:30, v týdnech s rozdílným letním časem 14:30). Zamčený je směr biasu a zóny (ceny a typ). Poznámky, popisky, platnost zóny (ukončení zóny je běžná věc) a potenciální obchody jde měnit kdykoli.

- První úprava po zamčení nejdřív uloží **verzi 1: jak náhled vypadal při otevření** (do té chvíle se nezměnil). Každá další změna zamčeného obsahu je **dodatečná verze** s časem. Platí to pro Hindsight i pro editor náhledu (ten to napíše i v časové ose a po uložení).
- **Vyhodnocení bere verzi z otevření**: podbarvení a ✓/✗ dne počítá s biasem z otevření, minimapa taky. Změněný bias má v hlavičce dne ✎ (v nápovědě je, z čeho na co).
- V grafu je zóna přidaná nebo změněná po otevření **tečkovaná** s popiskem *dodatečně*; zóna, která při otevření v náhledu byla a pak zmizela, zůstane jako **čárkovaný obrys** (*odstraněna po otevření*).
- Klik na hlavičku dne ukáže **historii verzí** (1 při otevření NY, další dodatečně s časem).
- Náhled vytvořený až po otevření má verzi z otevření prázdnou, takže všechno v něm je dodatečné. Když se zamčený náhled smaže, verze zůstanou a nový náhled na stejný den na ně naváže.
- **Správce** má v Hindsightu přepínač **Zpětně** (pro prezentaci): dokud je zapnutý, jeho úpravy minulých dnů se berou, jako by byly před otevřením, nic se neoznačí a dosavadní verze toho náhledu se zahodí. Platí i pro editor náhledu. Ostatní tradeři tuhle možnost nemají.

Náhledy z doby před touto verzí nemají verze: za platný se bere stav při první úpravě po nasazení.

### Vyhodnocení

Panel **Vyhodnocení** (klávesa E) odpovídá na tři otázky za zvolené období: *co je v grafu*, *posledních 20* nebo *60 obchodních dní*, nebo *vše načtené* (až rok). Řádky v seznamech skočí na daný den. Volba období, otevřený panel i pravidla se pamatují pro každého tradera.

- **Drží moje zóny?** Každá zóna se hodnotí v každém dni své platnosti. První dotek; strana podle toho, odkud cena přišla (close svíčky před dotekem nad zónou = má podržet jako support, pod ní = jako resistance; když leží v zóně, rozhodne typ zóny). **Držela** = odraz aspoň o 8 bodů od okraje zóny dřív, než 5m svíčka zavře za zónou o víc než 4 body (**proražená**). Jinak *zasažená bez rozhodnutí*, nebo *nezasažená*. Maximum svíčky je dřív než její close, takže odraz a průraz v jedné svíčce znamená „držela“; svíčka doteku se počítá jen svým close. Panel ukáže podíl držených z rozhodnutých, rozpad podle typu zóny a poslední případy. V grafu je u zóny kroužek v místě doteku a ✓ / ✗ tam, kde se rozhodlo; najetí myší na zónu napíše výsledek toho dne.
- **Sedí můj bias?** RTH close proti RTH open s biasem z otevření NY; long a short zvlášť, seznam dnů, kdy nevyšel. Neutral se standardně nehodnotí.
- **Kde nechávám obchody na stole?** Potenciální obchody podle výsledku (propáslé, nevzaté, vzaté): kolik R zůstalo na stole (propáslé a nevzaté, které skončily v zisku), kolik R ses ušetřil (šly do stopu), a u vzatých propojených s deníkem potenciál proti skutečnému výsledku. K tomu součet realizovaných obchodů ES.

**Pravidla vyhodnocení** (dole v panelu) si každý nastaví sám: zóny sledovat celý obchodní den, nebo jen RTH; velikost odrazu a průrazu v bodech; jestli počítat i dodatečné zóny (přidané po otevření NY; standardně ne); pásmo, ve kterém je neutral bias správně (0 = nehodnotit); a jak brát svíčku, která zasáhne stop i cíl potenciálního obchodu (stop, nebo cíl). Pravidla platí pro panel, ✓/✗ v hlavičkách dnů, minimapu i značky v grafu.

Pravidla jsou v `static/hindsight/evaluate.js` jako čisté funkce a mají vlastní testy (`tests/test_hindsight_eval.js`).

Hotové jsou všechny čtyři fáze zadání. Chybí jen import realizovaných obchodů z CSV (dedup podle ID z platformy je připravený), až bude vzorový export.

## Psychika a disciplína

### Odkud to je a co to není

Tohle si přečti dřív, než na výsledky začneš spoléhat.

**Neexistuje veřejně ověřený psychologický test pro tradery s publikovanými normami.** Kiev, Steenbarger, Shull ani Douglas žádný takový nástroj nevydali. Napsali knihy s rámci, ne dotazníky s kalibrovanými hranicemi. Cokoli, co by tvrdilo „tenhle test je od nich", by bylo buď opsané, nebo vymyšlené.

Co v aplikaci je:

- **Dimenze profilu** odpovídají konstruktům, které jsou v té literatuře opakovaně popsané. U každé dimenze je přímo v aplikaci uvedené, odkud myšlenka pochází — obchodování z potřeby (Ari Kiev), averze ke ztrátě (Kahneman a Tversky, v tradingu Mark Douglas), disposition effect, důraz na proces (Brett Steenbarger), vliv fyzického stavu na ochotu riskovat (John Coates).
- **Formulace otázek, bodování a hranice pásem jsou původní.** Nejsou převzaté z ničího dotazníku a nejsou kalibrované na datech. Jsou to rozumné heuristiky, nic víc.
- **Ověření proti tvým obchodům** je jediná část opřená o data. Porovnává, co jsi o sobě napsal, s tím, co je vidět v tvém deníku.

Ta poslední část je důvod, proč to celé dává smysl. Cizí stupnice by byla kalibrovaná na někom jiném. Tvoje vlastní obchody jsou to jediné, co o tobě nemá důvod lhát.

### Vstupní profil

Šestnáct otázek, dvě na každou z osmi dimenzí. Bez časového limitu a se šipkou zpět — tady se nespěchá.

Výsledkem je rozpad na **silné a rizikové oblasti**. Dimenze, kde skóruješ 60 % a víc, se označí jako riziková, pod 25 % jako silná stránka.

Pod profilem je **ověření proti tvým obchodům**: podíl porušení pravidel, která přišla po ztrátě; poměr průměrného zisku k průměrné ztrátě; podíl obchodních dnů bez náhledu; rozdíl v risku po ziskovém a po ztrátovém dni. Když si odporují s tím, co jsi o sobě napsal, aplikace to označí. Spustí se od deseti uzavřených obchodů.

### Osobnost a silné stránky

Vstupní profil se ptá, jak obchoduješ. Tahle část se ptá, jaký jsi. Obě se sčítají a rychlý test před seancí podle nich ví, na co se zaměřit. Vyplnit jde jedno, druhé nebo obojí.

**Talenty z Gallupu.** Gallupův test CliftonStrengths v aplikaci být nemůže: je placený a otázky i texty jsou chráněné. Když ho ale máš hotový, zadáš tlačítkem **Talenty z Gallupu** pořadí svých talentů z reportu. Stačí prvních pět, až deset zpřesní výsledek a z reportu všech 34 talentů můžeš přidat i posledních pět. U každého talentu aplikace ukáže **vlastní výklad pro trading**: co ti v obchodování pomáhá a na co si dát pozor. Například Aktivátor jedná bez zaváhání, ale hrozí mu vstup dřív, než je setup kompletní. Soutěživost žene ke zlepšení, ale potřeba „vyhrát den“ vede k obchodům na vrácení ztráty. Texty nejsou převzaté z reportu. CliftonStrengths® a názvy talentů jsou ochranné známky Gallup, Inc. a Trading Desk s Gallupem nijak nesouvisí.

**Osobnostní test.** Kdo Gallup nemá, vyplní tlačítkem **Osobnostní test** padesát výroků Big Five (IPIP Big-Five Factor Markers, Goldberg 1992). Pochází z International Personality Item Pool, který je ve veřejné doméně, a překlad je náš. Výsledkem je pět rysů: emoční stabilita, svědomitost, extraverze, otevřenost a přívětivost. Ukazuje se podíl z rozsahu odpovědí, ne percentil, protože české normy k překladu nejsou. Hranice pásem jsou hrubé a u přívětivosti a otevřenosti přísnější, protože tam většina lidí skóruje vysoko.

**Na co se rychlý test zaměří.** Z talentů (podle pořadí) a z rysů (nízká emoční stabilita, nízká svědomitost, vysoká extraverze…) vzniknou nejvýš tři oblasti i se zdůvodněním. K osmi oblastem vstupního profilu přibylo pět, které vycházejí jen z osobnosti: *potřeba akce*, *lpění na názoru*, *předbíhání potvrzení*, *vliv cizích názorů* a *váhání se vstupem*. Každá má v rychlém testu vlastní otázku.

**Co osobnost není.** Osobnost nepředpovídá, jestli budeš ziskový. Ukazuje, kde ti hrozí jaká chyba, a to je hypotéza. Proto má v rychlém testu menší váhu než vstupní profil a sama den neshodí. Jestli platí, ukáže až deník a rozbor chyb.

### Pravidla pro špatný den

Tlačítkem **Pravidla pro špatný den** si pro každé pásmo předem nadefinuješ, co pro tebe znamená: maximální počet obchodů, risk jako procento obvyklé velikosti, jen A+ setupy, jen jeden účet, žádné obchody kolem red news, konec po prvním porušení pravidel, plus vlastní pravidlo textem. Pro červenou je ve výchozím stavu zákaz obchodování.

Rozhoduješ o tom v klidu, ne ráno pod tlakem. Výsledek testu ti pak tvoje vlastní pravidla rovnou ukáže jako seznam.

### Rychlý test psychiky

Osm otázek na stav před session: spánek, fyzická kondice, vnější stres, finanční tlak, uzavření poslední session, připravenost, prostor na soustředění a důvěra v systém. Každá odpověď má 0 až 3 body.

Otázky chodí **po jedné a na čas**. Kliknutím na odpověď se otevře další a zpět se vrátit nejde — jde o první reakci, ne o zvažování. Na spodní hraně panelu běží grafický odpočet, který postupně mění barvu z tyrkysové na červenou. Odpovídat můžeš i klávesami 1 až 4.

Čas na otázku se počítá z počtu slov, aby zbyl prostor na přečtení i pro pomalejšího čtenáře:

```text
sekundy = 6 + počet slov × 0,6     (ohraničeno na 12 až 45 s)
```

Koeficient 0,6 s na slovo odpovídá zhruba 100 slovům za minutu, tedy výrazně pomalejšímu tempu než běžné tiché čtení. Šest sekund navíc je na rozhodnutí. V praxi to vychází na 14 až 20 sekund na otázku. Pokud ti to nesedí, konstanty `PSYCH_BASE_SECONDS` a `PSYCH_SECONDS_PER_WORD` najdeš nahoře v `static/app.js`.

Když čas vyprší, otázka se přeskočí a počítá se jako **mírná odpověď (1 bod)**, ne jako bezproblémová. Jinak by se dal test obejít tím, že se nechá doběhnout. Když takhle vypadnou dvě a víc otázek, objeví se to i mezi varováními.

```text
pod 22 % maxima    zelená    obchoduj podle plánu
pod 46 % maxima    oranžová  platí tvoje pravidla pro oranžovou
46 % a víc         červená   platí tvoje pravidla pro červenou
```

Počítá se podíl z maxima, ne pevný počet bodů, protože počet i váha otázek se mění podle profilu. Hranice 22 % a 46 % jsou výchozí a jdou zkalibrovat na tvých datech, viz níže.

**Test se přizpůsobuje profilu ve třech věcech:**

1. Otázky z tvých rizikových oblastí mají **1,5× vyšší váhu**.
2. Přidají se až **dvě cílené otázky** navíc přesně na tvoje slabiny.
3. Zhoršená odpověď v rizikové oblasti **sama o sobě shodí den na oranžovou**, i když je celkové skóre nízké.

K tomu se test přizpůsobuje **osobnosti** (viz výše): oblasti z osobnosti mají váhu 1,25 a dostanou až dvě vlastní otázky. Cílených otázek je dohromady nejvýš tři a vstupní profil má přednost. Zhoršená odpověď v oblasti z osobnosti se objeví ve varováních, ale sama den na oranžovou neshodí. U otázek navíc test ukáže, proč se ptá: „Tvoje riziková oblast z profilu“, nebo „Z tvé osobnosti: Aktivátor (2.), Extraverze vysoko“.

Ten třetí bod je podstatný. Stejně velký problém se hodnotí jinak podle toho, kde padne: mírná nevyspalost u někoho, kdo nemá problém s fyzickým stavem, zůstane zelená; stejně mírné vracení se ke včerejší ztrátě u někoho, kdo má reakci na ztrátu jako rizikovou oblast, dá oranžovou.

Dvě odpovědi fungují jako tvrdá pojistka a shodí výsledek na červenou bez ohledu na součet: *musím dnes dohnat ztrátu* a *mám chuť to vrátit*.

K výsledku dostaneš konkrétní seznam **na co si dát pozor** — každá problematická odpověď má navázané riziko, které z ní v exekuci typicky plyne.

**Tohle není psychologická diagnostika ani terapie.** Je to strukturovaný check-list faktorů, které ovlivňují exekuci, a aplikace to i v UI takto říká. Pokud dlouhodobě řešíš úzkost, nespavost nebo tlak přesahující trading, patří to k odborníkovi.

### Dýchání před seancí

Karta **Dýchání a zklidnění** v Psychice spustí dechové cvičení. Uprostřed je kruh: při nádechu se zvětšuje, při zadržení stojí a při výdechu se zmenšuje. V něm běží odpočet fáze, kolem kruhu se plní prstenec a pod ním je zbývající čas a počet dechů. Barva říká fázi: modrá nádech, zlatá zadržení, zelená výdech. Před začátkem jsou tři sekundy na přípravu, cvičení jde pozastavit a končí vždy celým dechem.

Rytmy:

- **Zklidnění 4–8**: nádech 4 s, výdech 8 s, pět dechů za minutu. Delší výdech zpomaluje tep a tělo zklidní nejrychleji. Studie ze Stanfordu (Balban a kol., 2023) srovnávala pět minut denně různých technik a dýchání s důrazem na dlouhý výdech zlepšovalo náladu nejvíc.
- **Box 5–5–5–5**: výdech 5 s, zadržení 5 s, nádech 5 s, zadržení 5 s. Pravidelný čtverec srovná tempo a soustředění.
- **Vlastní**: nádech, zadržení, výdech a zadržení podle sebe.

Délka 1, 2, 3, 5 nebo 10 minut. Když se ti zatočí hlava, dýchej chvíli normálně.

**Zvuk.** Pět kulis: *Oceán* (vlny, které přicházejí s nádechem a odcházejí s výdechem), *Hluboký tón* (teplý tón bez melodie, který se s nádechem otevírá), *Tibetské misky* (úder misky na začátku každého nádechu), *Déšť* a *Zvonkohra* (jemná pentatonická melodie). Hudba se nestahuje ani neukládá: vzniká přímo v prohlížeči (Web Audio) a dýchá s kruhem, takže nejsou potřeba žádné nahrávky ani licence. Hlasitosti jsou srovnané, aby přepnutí kulisy neznamenalo skok. Kliknutím na kulisu si ji poslechneš. Volitelně zazní jemný tón při každé změně fáze, takže jde dýchat i se zavřenýma očima.

**Co aplikace doporučí.** Rytmus i kulisu vybere podle osobnosti a u doporučené volby napíše proč. Rytmus 4–8 doporučí, když vyšla nízká emoční stabilita nebo je mezi oblastmi reakce na ztrátu, citlivost na fyzický stav nebo tlak na výsledek. Jinak doporučí box. Kulisu vybere podle první oblasti:

```text
reakce na ztrátu, fyzický stav, tlak na výsledek          →  Oceán
předbíhání potvrzení, lpění na názoru, série zisků        →  Hluboký tón
váhání se vstupem, práce se ziskem a ztrátou              →  Déšť
pozornost, držení systému, příprava                       →  Tibetské misky
potřeba akce, vliv cizích názorů                          →  Zvonkohra
```

Co si sám změníš (rytmus, délku, kulisu, hlasitost), si aplikace pamatuje. Zbytek dál sleduje doporučení.

**Po rychlém testu** aplikace dýchání nabídne sama: u zelené dvě minuty na naladění, u oranžové tři minuty 4–8, u červené pět minut 4–8. Tohle doporučení platí jen pro ten den a uložené nastavení nepřepíše.

### Kalibrace testu

Výchozí hranice jsou odhad. Kalibrace je ověří na tvých vlastních datech: vezme dny, kdy jsi udělal test a zároveň obchodoval, a porovná skóre testu s výsledkem dne a s porušením pravidel.

- ukáže průměrné R a podíl dnů s porušením pravidel zvlášť pro zelené, oranžové a červené dny,
- spočítá souvislost skóre s výsledkem dne a s porušením pravidel (čekáme, že vyšší skóre znamená horší den),
- navrhne nové hranice, které nejlépe oddělují dobré a špatné dny (Youdenovo J).

Návrh se **nikdy nepoužije sám**; použiješ ho tlačítkem. Kalibrace potřebuje aspoň 12 takových dnů a na každé straně aspoň tři dny. Pod padesát dnů ber návrh jako orientační.

### Rozbor chyb

Vypíše dny, kdy byl aspoň jeden obchod označený jako *nedodržený plán* nebo s hodnocením exekuce 4 a 5. Po kliknutí na den aplikace projde data a hledá vzorce:

- porušení přišlo až **po ztrátovém obchodu** téhož dne,
- obchodoval jsi **po vyčerpání denního rizika**,
- **zvýšil jsi risk** oproti průměru předchozích obchodů, včetně těch téhož dne,
- **chyběl náhled**, nebo byl jen týdenní bez denní přípravy, nebo chyběla přiřazená strategie,
- jaké **emoce** sis u porušení označil,
- ráno byl **varovný signál** z rychlého testu, který jsi nerespektoval,
- kolik takových dnů je za posledních 30 dní.

K tomu dostaneš sadu otázek na sebe. Odpovědi si piš přímo k obchodu do polí *Chyba / odchylka* a *Poznámka a poučení* — po měsíci ti rozbor ukáže, jestli se vzorec opakuje.

Rozbor je **pravidlová analýza tvých vlastních zápisů**, ne generovaný text. Nic se nikam neposílá a výsledek je pokaždé stejný pro stejná data.

Sekce **Spouštěče** porovnává, které emoce se objevují u obchodů mimo pravidla oproti obchodům podle plánu.

## Účty a Money audit

V záložce **Účty a audit** přidáš obchodní účet s aktuálním stavem konta a riskem na den. Účet po uložení **nelze upravit**, protože je referenčním bodem kontroly; smazat lze jen účet, ke kterému zatím není žádný obchod ani audit.

Třicetidenní lhůta běží od chvíle, kdy účet vznikne v aplikaci, a po každém auditu se resetuje. Pole *Začátek evidence* slouží jen k tomu, od kdy se do účtu započítávají obchody, a lhůtu auditu neovlivňuje.

Upozornění má dva stupně. Tři dny před termínem se v navigaci i na přehledu objeví oranžové **Money audit za X dní** s konkrétním datem, ať se dá screenshot připravit dopředu. V den termínu a dál se přepne na červené **Money audit čeká**. Dokud je do nejbližšího termínu víc než tři dny, přehled je čistý a nic nesvítí.

Každých 30 dní se v navigaci i na přehledu rozsvítí **Money audit**. Musíš nahrát screenshot stavu konta a opsat aktuální zůstatek. Očekávaný zůstatek se ti schválně ukáže až po vyhodnocení. Aplikace pak porovná:

```text
očekávaný zůstatek = vstupní stav + součet čistých výsledků obchodů na účtu
rozdíl             = nahlášený zůstatek - očekávaný zůstatek
tolerance          = 0,4 * risk na den (bez risku 0,2 % vstupního stavu, minimálně $20)
```

Kontrola tedy není přesná na dolary, ale odchylku zhruba od 0,4R nahoru označí jako nesedící evidenci, uvede směr odchylky a přepočte ji na násobek průměrného risku. Závěr obsahuje také procento obchodů, u kterých byl dodržen plán, průměrné hodnocení exekuce a výsledek aktuálního měsíce v R i v penězích. Win rate se zde záměrně nepočítá.

Screenshot je u auditu povinný a vynucuje ho formulář v prohlížeči. Výjimkou je účet napojený na cTrader (viz níže): tam se zůstatek bere přímo od brokera.

## Napojení na cTrader

Účty u brokera nebo prop firmy, která běží na platformě **cTrader**, jde napojit přes oficiální cTrader Open API (Spotware). Napojení je **jen pro čtení** (oprávnění `accounts`): Trading Desk vidí zůstatek, equity, otevřené pozice a historii obchodů, ale obchodovat neumí. Futures obchodované přes ATAS (Rithmic, CQG) cTrader nevidí.

**Zapnutí (jednou, správce).** Na [openapi.ctrader.com](https://openapi.ctrader.com/apps) se správce přihlásí svým cTrader ID a přidá aplikaci. Spotware ji před použitím schvaluje. Do *Redirect URIs* aplikace přidá adresu pro návrat, kterou mu Trading Desk ukáže ve **Správě** v kartě *cTrader Open API* (`https://…/trading/ctrader.php`). Tam pak vloží i Client ID a Secret. Secret zůstane jen na serveru v datovém adresáři a do prohlížeče se už nevrací.

**Napojení účtu (každý člen sám).** V **Účtech a auditu** klikne na **Napojit cTrader**, přihlásí se cTrader ID a povolí účty. Po návratu u každého účtu cTraderu vybere:

- **Účet v deníku**: nový (vytvoří se s názvem brokera a čísla účtu), nebo stávající.
- **Zapsat obchody od**: pozice uzavřené od toho dne se zapíšou do deníku, nejvýš rok zpátky. Nový účet dostane vstupní stav podle zůstatku k tomu dni. U stávajícího účtu nech dnešek, ať se ručně zapsané obchody nezdvojí.

Přístupové klíče k cTraderu leží v deníku člena, u šifrovaného deníku tedy zašifrované. ZIP záloha je odemčená, proto se do ní klíče nedávají; po obnovení ze zálohy stačí účet znovu napojit. Přístup jde kdykoli zrušit i v cTrader ID v nastavení aplikací.

**Co se zapíše.** Každá uzavřená pozice je jeden obchod deníku: trh (symbol), směr, vstup (průměrná cena pozice), výstup (průměr uzavírajících obchodů), objem v lotech, poplatky (komise, swap, převod měny) a čistý výsledek v měně účtu. Čistý výsledek se bere přesně podle změny zůstatku, kterou cTrader u obchodu hlásí. Když měla pozice při vstupu stop loss, dopočte se i risk a R; posunutý stop (třeba na break-even) se pro risk nepoužije. Bez stop lossu zůstane R prázdné (v deníku „—“) a risk doplníš ručně. Strategii, hodnocení a poznámky doplníš jako u ručního obchodu. Smazaný obchod se při další synchronizaci znovu neobjeví a stejná pozice se nezapíše dvakrát. Částečně uzavřená pozice se zapíše, až se uzavře celá.

**Synchronizace** proběhne při propojení, tlačítkem **Synchronizovat** a sama při otevření aplikace, když od poslední uběhlo víc než 10 minut. Na pozadí bez přihlášení neběží, protože šifrovaný deník server bez člena neotevře.

**Money audit napojeného účtu** se udělá sám, jakmile je na řadě (každých 30 dní), a bez screenshotu: nahlášený zůstatek je zůstatek z cTraderu. Kdykoli ho spustíš i tlačítkem **Money audit z cTraderu**. Očekávaný zůstatek u napojeného účtu zahrnuje i vklady a výběry (třeba výplatu z prop účtu) od začátku importu a výsledek částečně uzavřených pozic:

```text
očekávaný zůstatek = vstupní stav + čisté výsledky obchodů + vklady − výběry + realizovaný výsledek otevřených pozic
```

## Obchodní plán

Záložka **Obchodní plán** je jeden dokument s pravidly, podle kterých obchoduješ. Píše se před seancí (ideálně o víkendu) a během obchodování se jen dodržuje. Plán je rozdělený do deseti kroků, které jdou v navigaci nahoře a v PDF ve stejném pořadí:

1. **Cíle a styl**: proč obchoduješ, styl (Intraday, Hybrid Intraday, Swing), čas na trading, procesní cíle (co ovlivníš) a výsledkové cíle (kam míříš).
2. **Trhy a čas**: obchodované trhy a obchodní okna v pražském čase se dny a režimem *obchoduji*, *jen sleduji* nebo *neobchoduji*. Pravidlo pro red news a dny bez obchodování.
3. **Účty a risk**: denní a týdenní stop v R, max. obchodů denně a ztrát v řadě, výpočet velikosti pozice, kdy risk snížit a kdy zvýšit. Účty z deníku připojíš do plánu a u každého nastavíš roli (prop challenge, prop funded, vlastní kapitál, demo), risk na obchod, denní limit ztráty, drawdown prop firmy, profit target a vlastní pravidla.
4. **Jak stavím bias**: timeframy, postup shora dolů, kdy je bias long, short a balance a kdy ho ruším.
5. **Jak stavím zóny**: z čeho zóny stavíš (VAH/VAL, POC, naked POC, single prints, poor high/low, excess, IB, DiNapoli…), jak je kreslíš, priorita A, B a C, max. šířka, platnost a kdy zóna padá.
6. **Strategie**: strategie z knihovny připojíš do plánu a u každé napíšeš, kdy ji obchoduješ, za jakých podmínek, vstup, stop loss, cíle, řízení pozice a hlavně **kdy ji neobchoduješ**. K tomu typ dne, vztah k biasu, minimální prioritu zóny, minimální RR, max. pokusů denně a na kterých účtech plánu se smí obchodovat.
7. **Den tradera**: rutina před seancí, během ní a po ní.
8. **Psychika a disciplína**: co dělat ve špatný den, spouštěče a reakce na ně, kdy končíš den.
9. **Review**: denní, týdenní a měsíční vyhodnocení, co sleduješ a kdy smíš plán změnit.
10. **Závazek**: prohlášení, podpis a datum.

Tlačítko **Vložit návrh** doplní do prázdných polí můj vzorový plán pro intraday obchodování s Market a Volume Profile (okna kolem otevření New Yorku, risk podle denního risku účtů, pravidla podle charakteru strategií). Co už máš vyplněné, nepřepíše. Návrh je výchozí bod, ne hotový plán: projdi ho a přepiš podle sebe.

**Verze.** Plán má stav *Rozpracovaný* nebo *Platný* a datum platnosti. Změna pravidel je nová verze: tlačítko **Nová verze** v kartě *Verze plánu* uloží současný plán do historie (jen pro čtení) a otevře jeho kopii k úpravám. Každou verzi jde z historie stáhnout v PDF, archivní i smazat.

**PDF.** Tlačítko **PDF** plán uloží a stáhne jako `obchodni-plan-v1.pdf`: titulní pás, plán v kostce (risk, stopy, trhy, okno, počet strategií), obsah, deset kapitol s mapou dne, kartami účtů a strategií (s obrázkem strategie) a podpis na konci.

Plán je uložený v deníku člena, u šifrovaného deníku tedy zašifrovaný. Modul jde v Nastavení skrýt jako ostatní moduly.

## Export náhledu do PDF

PDF je poskládané tak, aby nejdůležitější věci byly nahoře:

1. **Bias** jako přehledná tabulka: Price action a MP/VP pro Weekly a Daily (u týdenního náhledu Monthly a Weekly) a vpravo výrazně pracovní bias se souhrnem souladu timeframů.
2. **Co se na trhu odehrává**: tvůj krátký popis trhu a pod ním automatický pracovní závěr.
3. **Zóny**: rozsah, směr (long, short, long i short), poloha vůči value minulého týdne a pro každý směr zvlášť *co se musí splnit pro vstup* a *kdy obchod neberu*. Chybějící definice je v PDF vyznačená.
4. Potom Market Profile kontext, reference na dojetí, DiNapoli (trend podle DMA, levely s timeframem a stavem, konfluence a shoda, vzory), levely, scénáře, rizika a poznámky, vlastní pole, navázané obchody a grafy.

Prázdné položky se do PDF nevypisují.

Vzhled odpovídá aplikaci: tmavý titulní pás s trhem, datem, stavem a red news, písma Fraunces a Manrope, zlaté akcenty a barvy směru (long zeleně, short červeně, balance oranžově). Zóny jsou očíslované stejně jako na mapě ceny a každá má vlastní kartu. Na dalších stranách je nahoře trh a datum, dole číslo strany.

V otevřeném náhledu klikni na **PDF**. Aplikace nejprve uloží aktuální změny a následně stáhne hotový PDF soubor. Export je také dostupný tlačítkem **PDF** u každého záznamu v historii náhledů.

PDF se vytváří přímo na Ubuntu serveru pomocí lokálních balíčků `python3-reportlab` a `python3-pil`. Písma Fraunces a Manrope jsou přiložená v `lib/fonts` (licence SIL Open Font License); kdyby chyběla, použije se DejaVu Sans. Nepoužívá cloudovou službu ani dialog Tisk. Při aktualizaci starší instalace spusť znovu `sudo bash deploy/install.sh`, aby se PDF balíčky doplnily.

## Klíčové levely

V denním náhledu je vedle zón sekce **Klíčové levely** pro jednotlivé ceny, tedy horizontální čáry. U každého levelu zadáš název, cenu, charakter (support, rezistence, pivot), zdroj či shodu a styl čáry.

Levely jdou do TradingView exportu společně se zónami. Vykreslí se jako `line` s `extend.right`, obarvené podle charakteru — support zeleně, rezistence červeně, pivot žlutě — a v požadovaném stylu plná, čárkovaná nebo tečkovaná. Každý level má v TradingView vlastní vypínač a editovatelnou cenu. Do PDF se levely tisknou jako samostatná tabulka.

## Export zón do TradingView

V části **Obchodní zóny** otevři **TradingView export**. Aplikace použije aktuálně vyplněné spodní a horní hranice, rozliší long, short a obousměrné zóny barvou a připraví Pine Script v6. Volba **Hodnoty profilu a reference** přidá VAH, POC, VAL, high a low předchozího období (modře, čárkovaně), u denního náhledu i týdenní VAH a VAL, a otevřené reference na dojetí (fialově). Volba **DiNapoli levely** přidá zadané levely (retracementy čárkovaně, expanze tečkovaně) a pásma konfluence a shody jako zóny. Kód můžeš jedním kliknutím zkopírovat nebo stáhnout jako `.pine` soubor. Popisky lze zobrazit několik barů napravo od aktuální ceny, výškově uprostřed zóny, a volitelně do nich přidat také zdroj nebo shodu.

Doporučení: alespoň jednu kopii uchovávej mimo disk Ubuntu serveru.

## Bezpečnost

Aplikace má vlastní účty, schvalování registrací a ochranu proti hádání hesel. API odmítá zápisy, které nepřišly z její vlastní stránky (ochrana proti CSRF), relace běží v cookie s příznaky HttpOnly a SameSite a bezpečnostní hlavičky zakazují cizí skripty i vkládání do rámů.

Hádání hesla brzdí i změna hesla, zapnutí šifrování a výměna přístupového klíče, ne jen přihlášení. Z webu jsou dostupné jen stránky aplikace a soubory ve `static/`; historie gitu, dokumentace, Python převodník a datový adresář ne. Přes HTTPS posílá aplikace hlavičku HSTS, takže prohlížeč už pak na nešifrované spojení nespadne.

Na serveru dostupném z internetu je ale **HTTPS povinné**: bez něj jdou heslo, přístupový klíč i cookie relace po síti čitelně. Postup je v [INSTALL.md](INSTALL.md), varianta E.
