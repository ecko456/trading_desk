# Trading Desk - lokální denní analýza a journal

Samostatná webová aplikace pro Ubuntu/Apache. Běží pod `/trading/`, takže nemění existující web na `/` ani jeho `/api/*`.

## Co umí

- **týdenní i denní náhled** trhu postavený pro Market Profile: bias z price action (Monthly, Weekly, Daily) a z MP/VP (Weekly, Daily), tvar profilu P, b, D, B, poloha close vůči value, reference na dojetí (single prints, poor high/low, naked POC…),
- pole o otevření trhu (Globex, EU, RTH, typ otevření, Initial Balance) jsou zamčená, dokud jejich čas nenastane; o víkendu se náhled sám nastaví na pondělí,
- zóny se směrem long, short nebo obojí a s definicí, co se musí splnit pro vstup a kdy obchod nebrat, zvlášť pro každý směr,
- u každé zóny automaticky poloha vůči value minulého týdne (ve VAL, v oblasti VAL, pod VAL, totéž u VAH); denní náhled si ji bere z týdenního,
- mapa ceny, která ukáže zóny, levely, value a reference na jedné ose,
- klíčové levely jako horizontální úrovně s cenou, charakterem a stylem čáry,
- srovnání výkonnosti jednotlivých strategií a setupů,
- měsíční kalendář s výsledky dnů, red news a svátky,
- vstupní psychologický profil se silnými a rizikovými oblastmi, ověřený proti vlastním obchodům,
- rychlý test psychiky přizpůsobený profilu, s vlastními pravidly pro špatný den,
- pravidlový rozbor dnů s porušenými pravidly,
- potenciální obchody s entry, SL, TP1, TP2, finálním TP a R:R,
- rozlišení Intraday a Hybrid Intraday obchodů,
- screenshoty grafů uložené jako soubory na serveru,
- knihovnu strategií a setupů s timeframem, charakterem systému a ukázkovými grafy,
- obchodní účty s pevným vstupním stavem konta a riskem na den,
- Money audit, který každých 30 dní ověří, jestli jsou zapsané všechny obchody,
- obchodní deník s automatickým výpočtem P&L a R pro ES, NQ, GC, CL a 6E,
- přehled s připraveností na nejbližší session (týdenní a denní náhled, test psychiky, red news, Money audit), průměrem R na obchod, profit factorem, dodržením plánu a equity v R,
- historie denních i týdenních náhledů,
- PDF, které nahoře ukáže bias, pod ním krátký popis trhu a potom zóny s podmínkami,
- export zón, levelů, hodnot profilu a otevřených referencí do TradingView jako Pine Script v6,
- tmavý i světlý vzhled s většími a čitelnějšími popisky,
- kompletní ZIP záloha SQLite databáze a screenshotů plus JSON export.

Data nejsou ukládána do `localStorage`. Zdroj pravdy je SQLite na Ubuntu a adresář screenshotů.

## Instalace

Návod pro Windows, Mac i Linux psaný pro netechnické uživatele je v souboru [INSTALL.md](INSTALL.md). Níže je zkrácený postup pro Ubuntu.

## Spuštění na GitHubu bez instalace

[![Open in GitHub Codespaces](https://github.com/codespaces/badge.svg)](https://codespaces.new/ecko456/trading_desk?quickstart=1)

Aplikace potřebuje PHP server, takže na GitHub Pages běžet nemůže. Spustí se ale v GitHub Codespaces, tedy v soukromém počítači v cloudu, který patří jen tobě. Konfigurace je v `.devcontainer/`: při prvním vytvoření se nainstaluje PHP a knihovny pro PDF a při každém startu se sama spustí aplikace na portu 8420. Postup krok za krokem a na co si dát pozor je v [INSTALL.md](INSTALL.md), varianta D.

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

## Uložení dat

Výchozí produkční umístění:

```text
/var/lib/trading-journal/trading.sqlite3
/var/lib/trading-journal/uploads/
```

Apache do adresáře zapisuje jako `www-data`. Data nejsou veřejně dostupná; obrázky se čtou přes `file.php` podle databázového ID.

## Ruční Apache konfigurace

Pokud nechceš použít instalátor, zkopíruj aplikaci do `/var/www/trading-journal`, vytvoř `/var/lib/trading-journal`, nainstaluj `php-sqlite3`, `php-mbstring` a `php-zip` a aktivuj obsah souboru `deploy/apache-trading.conf`.

Před reloadem vždy ověř konfiguraci:

```bash
sudo apache2ctl configtest
sudo systemctl reload apache2
```

## Zálohování

V aplikaci otevři záložku **Záloha** a stáhni kompletní ZIP. Obsahuje konzistentní SQLite snapshot, všechny screenshoty a manifest.

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

V dialogu **Přidat obchod** je pole *Strategie / setup* rozevírací seznam. Poslední položka **+ Přidat strategii / setup** otevře panel, kde zadáš název, timeframe, na kterém systém obchoduješ, charakter systému (trendový, reversal, nebo obojí), pravidla a ukázkové screenshoty. Nová strategie se rovnou předvyplní do obchodu. Tlačítkem **Upravit** vedle seznamu otevřeš vybranou strategii k doplnění.

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

Záložka **Strategie** srovnává setupy, které přiřazuješ obchodům. U každého vidíš počet obchodů, celkové R, průměrné R na obchod, profit factor, procento dodržení plánu, průměrné hodnocení exekuce a datum posledního obchodu. Řadí se podle celkového R, takže nahoře je to, co ti skutečně vydělává.

Zvlášť je vyčíslený řádek **Bez strategie** — obchody, které nemají přiřazený setup a nejdou tedy vyhodnotit. Tlačítkem *Obchody* se prokliknéš do deníku s předvyplněným filtrem.

Win rate se tu záměrně nepočítá. Rozhoduje expectancy v R a profit factor.

Nad tabulkou je graf **kumulativního R v čase**, kde má každá strategie vlastní barevnou linii. Osa X jsou obchodní dny, osa Y kumulativní R. Linie začíná až u prvního obchodu dané strategie a mezi jejími obchody se drží na poslední hodnotě, takže jdou křivky navzájem porovnat i když každý setup obchoduješ jindy.

## Kalendář

Měsíční mřížka, kde každý den ukazuje výsledek v R, počet obchodů a značky: **N** pro existující denní náhled, **P** pro vyplněný rychlý test psychiky a **!** pro den s porušenými pravidly. Zelené a červené podbarvení odpovídá výsledku dne.

Kliknutím na den otevřeš detail, kde můžeš přidat **red news** (s časem a dopadem), **svátek** nebo poznámku. Pokud na ten den existuje náhled, otevřeš ho přímo odtud.

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

Ten třetí bod je podstatný. Stejně velký problém se hodnotí jinak podle toho, kde padne: mírná nevyspalost u někoho, kdo nemá problém s fyzickým stavem, zůstane zelená; stejně mírné vracení se ke včerejší ztrátě u někoho, kdo má reakci na ztrátu jako rizikovou oblast, dá oranžovou.

Dvě odpovědi fungují jako tvrdá pojistka a shodí výsledek na červenou bez ohledu na součet: *musím dnes dohnat ztrátu* a *mám chuť to vrátit*.

K výsledku dostaneš konkrétní seznam **na co si dát pozor** — každá problematická odpověď má navázané riziko, které z ní v exekuci typicky plyne.

**Tohle není psychologická diagnostika ani terapie.** Je to strukturovaný check-list faktorů, které ovlivňují exekuci, a aplikace to i v UI takto říká. Pokud dlouhodobě řešíš úzkost, nespavost nebo tlak přesahující trading, patří to k odborníkovi.

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

Screenshot je u auditu povinný a vynucuje ho formulář v prohlížeči.

## Export náhledu do PDF

PDF je poskládané tak, aby nejdůležitější věci byly nahoře:

1. **Bias** jako přehledná tabulka: Price action a MP/VP pro Weekly a Daily (u týdenního náhledu Monthly a Weekly) a vpravo výrazně pracovní bias se souhrnem souladu timeframů.
2. **Co se na trhu odehrává**: tvůj krátký popis trhu a pod ním automatický pracovní závěr.
3. **Zóny**: rozsah, směr (long, short, long i short), poloha vůči value minulého týdne a pro každý směr zvlášť *co se musí splnit pro vstup* a *kdy obchod neberu*. Chybějící definice je v PDF vyznačená.
4. Potom Market Profile kontext, reference na dojetí, levely, scénáře, rizika a poznámky, navázané obchody a grafy.

Prázdné položky se do PDF nevypisují.

V otevřeném náhledu klikni na **PDF**. Aplikace nejprve uloží aktuální změny a následně stáhne hotový PDF soubor. Export je také dostupný tlačítkem **PDF** u každého záznamu v historii náhledů.

PDF se vytváří přímo na Ubuntu serveru pomocí lokálních balíčků `python3-reportlab`, `python3-pil` a fontu DejaVu Sans. Nepoužívá cloudovou službu ani dialog Tisk. Při aktualizaci starší instalace spusť znovu `sudo bash deploy/install.sh`, aby se PDF balíčky doplnily.

## Klíčové levely

V denním náhledu je vedle zón sekce **Klíčové levely** pro jednotlivé ceny, tedy horizontální čáry. U každého levelu zadáš název, cenu, charakter (support, rezistence, pivot), zdroj či shodu a styl čáry.

Levely jdou do TradingView exportu společně se zónami. Vykreslí se jako `line` s `extend.right`, obarvené podle charakteru — support zeleně, rezistence červeně, pivot žlutě — a v požadovaném stylu plná, čárkovaná nebo tečkovaná. Každý level má v TradingView vlastní vypínač a editovatelnou cenu. Do PDF se levely tisknou jako samostatná tabulka.

## Export zón do TradingView

V části **Obchodní zóny** otevři **TradingView export**. Aplikace použije aktuálně vyplněné spodní a horní hranice, rozliší long, short a obousměrné zóny barvou a připraví Pine Script v6. Volba **Hodnoty profilu a reference** přidá VAH, POC, VAL, high a low předchozího období (modře, čárkovaně), u denního náhledu i týdenní VAH a VAL, a otevřené reference na dojetí (fialově). Kód můžeš jedním kliknutím zkopírovat nebo stáhnout jako `.pine` soubor. Popisky lze zobrazit několik barů napravo od aktuální ceny, výškově uprostřed zóny, a volitelně do nich přidat také zdroj nebo shodu.

Doporučení: alespoň jednu kopii uchovávej mimo disk Ubuntu serveru.

## Bezpečnost

Aplikace je navržená pro osobní localhost. Pokud ji později zpřístupníš do domácí sítě nebo internetu, je nutné přidat autentizaci a HTTPS.
