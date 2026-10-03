<?php
declare(strict_types=1);

/*
 * Osobnost a silné stránky ve vstupním profilu.
 *
 * Dva zdroje, oba volitelné:
 *
 * 1. Big Five z IPIP. Padesát položek „IPIP Big-Five Factor Markers“ (Goldberg, 1992)
 *    z International Personality Item Pool, který je ve veřejné doméně. Překlad je náš
 *    a české normy k němu nejsou, proto se ukazuje podíl z maxima, ne percentil.
 * 2. Výsledek testu CliftonStrengths od Gallupu, pokud ho člověk má. Gallupův test ani
 *    jeho texty v aplikaci nejsou (jsou chráněné a placené). Člověk zadá jen pořadí svých
 *    talentů a výklad pro trading je náš.
 *
 * Z obojího vzniknou nejvýš tři oblasti, na které se má rychlý test před seancí zaměřit,
 * a doporučení dechového cvičení a zvukové kulisy. Osobnost nepředpovídá, jestli bude
 * někdo ziskový. Říká, kde u něj hrozí jaká chyba, a to je pořád hypotéza, kterou
 * ověřuje až deník.
 */

const BIG5_SCALE = ['Vůbec mě nevystihuje', 'Spíš mě nevystihuje', 'Ani ano, ani ne', 'Spíš mě vystihuje', 'Přesně mě vystihuje'];
const BIG5_MIN_ITEMS = 7;
const STRENGTHS_TOP_MAX = 10;
const STRENGTHS_BOTTOM_MAX = 5;
// Váha talentu podle pořadí: první je nejsilnější.
const STRENGTHS_RANK_WEIGHTS = [5, 4, 3, 2, 2, 1, 1, 1, 1, 1];
const STRENGTHS_BOTTOM_WEIGHT = 2;
const BIG5_WEIGHT = 4;
const FOCUS_MIN_SCORE = 4;
const FOCUS_MAX = 3;

const BREATH_PATTERNS = ['calm', 'box', 'custom'];
const SOUNDSCAPES = ['ocean', 'drone', 'bowls', 'rain', 'chimes'];
const BREATH_MINUTES = [1, 2, 3, 5, 10];

function ensure_personality_schema(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS psych_personality (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    big5 TEXT NOT NULL DEFAULT '{}',
    strengths TEXT NOT NULL DEFAULT '{}',
    preferences TEXT NOT NULL DEFAULT '{}',
    big5_at TEXT,
    strengths_at TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
SQL);
}

/* ---------------------------------------------------------------- oblasti zaměření */

/**
 * Oblasti, na které se může rychlý test zaměřit. Prvních osm jsou dimenze vstupního
 * profilu, další vycházejí jen z osobnosti a mají v rychlém testu vlastní otázku.
 */
function focus_areas(): array
{
    $areas = [];
    foreach (psych_dimensions() as $key => $dimension) {
        $areas[$key] = $dimension['label'];
    }
    return $areas + [
        'stimulation' => 'Potřeba akce',
        'conviction' => 'Lpění na názoru',
        'anticipation' => 'Předbíhání potvrzení',
        'social' => 'Vliv cizích názorů',
        'hesitation' => 'Váhání se vstupem',
    ];
}

/* ---------------------------------------------------------------- Big Five (IPIP) */

function big5_traits(): array
{
    return [
        'S' => [
            'label' => 'Emoční stabilita',
            'high' => 'Stres a ztráty tě rozhodí méně než většinu lidí. Pod tlakem rozhoduješ podobně jako v klidu.',
            'mid' => 'Na stres a ztráty reaguješ běžně: v klidu dobře, v sérii ztrát je dobré mít pravidla předem.',
            'low' => 'Na stres a ztrátu reaguješ silněji. Emoce se snadno přenesou do dalšího obchodu, proto má u tebe největší cenu zklidnění před seancí a pevný denní stop.',
            'thresholds' => [37.5, 70],
        ],
        'C' => [
            'label' => 'Svědomitost',
            'high' => 'Pořádek, plán a dotahování. Příprava, pravidla i deník ti jdou přirozeně.',
            'mid' => 'Strukturu dodržíš, když ji máš napsanou. Checklist ti pomůže víc než předsevzetí.',
            'low' => 'Spontánnost a menší potřeba struktury. Pravidla potřebuješ mít napsaná, krátká a na očích, jinak se rozpustí.',
            'thresholds' => [37.5, 70],
        ],
        'E' => [
            'label' => 'Extraverze',
            'high' => 'Energii ti dává akce a lidé. Na pomalém trhu hrozí obchody z nudy a vliv chatů a skupin.',
            'mid' => 'Akci i klid zvládáš podobně. Hlídej jen dny, kdy se trh dlouho nehýbe.',
            'low' => 'Vydržíš v klidu sám u grafu a čekání na setup tě tolik nestojí.',
            'thresholds' => [37.5, 70],
        ],
        'O' => [
            'label' => 'Otevřenost (intelekt a představivost)',
            'high' => 'Baví tě nápady a nové přístupy. Hlídej, ať systém neměníš dřív, než ho vyhodnotíš na datech.',
            'mid' => 'Nové věci zkusíš, ale držíš se toho, co funguje.',
            'low' => 'Držíš se osvědčeného a nové metody tě tolik nelákají. Pro trading spíš výhoda.',
            'thresholds' => [37.5, 80],
        ],
        'A' => [
            'label' => 'Přívětivost',
            'high' => 'Vstřícnost k lidem. V tradingu hlavně riziko, že převezmeš cizí názor na trh.',
            'mid' => 'S lidmi vycházíš, ale rozhoduješ se sám.',
            'low' => 'Nezávislost na názoru druhých. Druhou stranou je sklon jít proti trhu, když máš svůj názor.',
            'thresholds' => [40, 85],
        ],
    ];
}

/** Oblasti zaměření podle úrovně rysu: [rys][úroveň] => [oblast => intenzita]. */
function big5_focus_map(): array
{
    return [
        'S' => ['low' => ['revenge' => 2, 'physical' => 1]],
        'C' => ['low' => ['process' => 2, 'consistency' => 1]],
        'E' => ['high' => ['stimulation' => 2, 'social' => 1]],
        'O' => ['high' => ['consistency' => 1]],
        'A' => ['high' => ['social' => 1], 'low' => ['conviction' => 1]],
    ];
}

/**
 * IPIP Big-Five Factor Markers, 50 položek v původním pořadí. Znaménko říká, jestli
 * souhlas rys zvyšuje (+1), nebo snižuje (−1). Rys S je emoční stabilita, tedy opak
 * neuroticismu.
 */
function big5_items(): array
{
    $items = [
        ['E', 1, 'Na večírku jsem duší společnosti.'],
        ['A', -1, 'O ostatní lidi se příliš nestarám.'],
        ['C', 1, 'Vždycky mám všechno připravené.'],
        ['S', -1, 'Snadno se dostanu pod stres.'],
        ['O', 1, 'Mám bohatou slovní zásobu.'],
        ['E', -1, 'Moc nemluvím.'],
        ['A', 1, 'Zajímám se o lidi.'],
        ['C', -1, 'Nechávám své věci ležet kolem sebe.'],
        ['S', 1, 'Většinu času mám v sobě klid.'],
        ['O', -1, 'Abstraktní myšlenky chápu jen obtížně.'],
        ['E', 1, 'Mezi lidmi se cítím dobře.'],
        ['A', -1, 'Urážím lidi.'],
        ['C', 1, 'Dávám pozor na detaily.'],
        ['S', -1, 'Dělám si starosti.'],
        ['O', 1, 'Mám živou představivost.'],
        ['E', -1, 'Držím se v pozadí.'],
        ['A', 1, 'Soucítím s pocity druhých.'],
        ['C', -1, 'Věci často zpackám.'],
        ['S', 1, 'Málokdy mám pokleslou náladu.'],
        ['O', -1, 'Abstraktní myšlenky mě nezajímají.'],
        ['E', 1, 'Rozhovor obvykle začínám já.'],
        ['A', -1, 'Problémy druhých mě nezajímají.'],
        ['C', 1, 'Povinnosti vyřídím hned.'],
        ['S', -1, 'Snadno mě něco rozhodí.'],
        ['O', 1, 'Mívám výborné nápady.'],
        ['E', -1, 'Nemám moc co říct.'],
        ['A', 1, 'Mám měkké srdce.'],
        ['C', -1, 'Často zapomínám vracet věci na své místo.'],
        ['S', -1, 'Snadno se rozčílím.'],
        ['O', -1, 'Nemám dobrou představivost.'],
        ['E', 1, 'Na večírku mluvím s mnoha různými lidmi.'],
        ['A', -1, 'O ostatní lidi se vlastně nezajímám.'],
        ['C', 1, 'Líbí se mi pořádek.'],
        ['S', -1, 'Často se mi mění nálada.'],
        ['O', 1, 'Věci rychle pochopím.'],
        ['E', -1, 'Nechci na sebe přitahovat pozornost.'],
        ['A', 1, 'Udělám si čas na druhé.'],
        ['C', -1, 'Vyhýbám se svým povinnostem.'],
        ['S', -1, 'Mívám časté výkyvy nálad.'],
        ['O', 1, 'Používám složitá slova.'],
        ['E', 1, 'Nevadí mi být středem pozornosti.'],
        ['A', 1, 'Cítím emoce druhých.'],
        ['C', 1, 'Držím se rozvrhu.'],
        ['S', -1, 'Snadno mě něco podráždí.'],
        ['O', 1, 'Trávím čas přemýšlením o věcech.'],
        ['E', -1, 'Mezi cizími lidmi spíš mlčím.'],
        ['A', 1, 'Lidé se v mé přítomnosti cítí uvolněně.'],
        ['C', 1, 'Na svou práci mám vysoké nároky.'],
        ['S', -1, 'Často mívám smutnou náladu.'],
        ['O', 1, 'Mám spoustu nápadů.'],
    ];
    $result = [];
    foreach ($items as $index => [$trait, $sign, $text]) {
        $result[] = ['key' => sprintf('b%02d', $index + 1), 'trait' => $trait, 'sign' => $sign, 'text' => $text];
    }
    return $result;
}

/** Odpovědi 0–4 (index tlačítka) jen u známých položek. */
function normalize_big5_answers(array $answers): array
{
    $clean = [];
    foreach (big5_items() as $item) {
        $raw = $answers[$item['key']] ?? null;
        if (is_int($raw) || (is_string($raw) && preg_match('/^[0-4]$/', $raw))) {
            $value = (int)$raw;
            if ($value >= 0 && $value <= 4) {
                $clean[$item['key']] = $value;
            }
        }
    }
    return $clean;
}

/** Průměr 1–5 a podíl z rozsahu pro každý rys. Rys s méně než sedmi odpověďmi se nepočítá. */
function evaluate_big5(array $answers): array
{
    $sums = [];
    $counts = [];
    foreach (big5_items() as $item) {
        if (!array_key_exists($item['key'], $answers)) {
            continue;
        }
        $value = $answers[$item['key']] + 1;
        $scored = $item['sign'] > 0 ? $value : 6 - $value;
        $sums[$item['trait']] = ($sums[$item['trait']] ?? 0) + $scored;
        $counts[$item['trait']] = ($counts[$item['trait']] ?? 0) + 1;
    }

    $result = [];
    foreach (big5_traits() as $key => $trait) {
        $count = $counts[$key] ?? 0;
        if ($count < BIG5_MIN_ITEMS) {
            continue;
        }
        $mean = $sums[$key] / $count;
        $share = round(($mean - 1) / 4 * 100, 1);
        [$low, $high] = $trait['thresholds'];
        $level = $share <= $low ? 'low' : ($share >= $high ? 'high' : 'mid');
        $result[$key] = [
            'key' => $key,
            'label' => $trait['label'],
            'mean' => round($mean, 2),
            'share' => (int)round($share),
            'level' => $level,
            'risk' => isset(big5_focus_map()[$key][$level]),
            'text' => $trait[$level],
            'answered' => $count,
        ];
    }
    return $result;
}

/* ---------------------------------------------------------------- talenty (CliftonStrengths) */

function strength_domains(): array
{
    return ['executing' => 'Exekutiva', 'influencing' => 'Vliv', 'relationship' => 'Budování vztahů', 'thinking' => 'Myšlení'];
}

/**
 * 34 talentů CliftonStrengths s naším výkladem pro trading: co v tradingu pomáhá (plus),
 * na co si dát pozor (minus), kam se má rychlý test zaměřit, když je talent mezi
 * nejsilnějšími (focus), a kdy je naopak mezi nejslabšími (low). Názvy jsou ochranné
 * známky Gallup, Inc.; texty nejsou převzaté z reportu.
 */
function strength_themes(): array
{
    return [
        // Exekutiva
        'achiever' => ['Produktivní', 'Achiever', 'executing', 'Vydržíš u rutiny: příprava, zápis i review se u tebe dějí každý den.', 'Den bez obchodu ti připadá jako ztracený. Na pomalém trhu hrozí obchody jen proto, aby se něco dělo.', ['stimulation' => 2, 'need' => 1], ['process' => 1]],
        'arranger' => ['Organizátor', 'Arranger', 'executing', 'Zvládneš víc účtů, trhů a pozic najednou a rychle přeskládáš plán, když se změní situace.', 'Snadno si naložíš víc trhů a pozic, než udržíš v pozornosti.', ['attention' => 2], []],
        'belief' => ['Přesvědčení', 'Belief', 'executing', 'Pravidla, která vezmeš za svoje hodnoty, dodržíš i pod tlakem.', 'Pevný názor na trh se těžko pouští. Hrozí, že invalidaci biasu nevezmeš, protože „trh se mýlí“.', ['conviction' => 2], []],
        'consistency' => ['Konzistentnost', 'Consistency', 'executing', 'Stejná pravidla každý den a pro každý obchod, přesně to statistika systému potřebuje.', 'Jeden postup na trend i na balance. Hlídej, jestli setup sedí na typ dne.', [], ['consistency' => 2]],
        'deliberative' => ['Rozvážný', 'Deliberative', 'executing', 'Riziko vidíš dřív než ostatní; stop i velikost pozice máš promyšlené.', 'Čekáš na ještě jedno potvrzení, vstup uteče a pak ho doháníš za horší cenu.', ['hesitation' => 2], ['anticipation' => 2]],
        'discipline' => ['Disciplína', 'Discipline', 'executing', 'Rutina a struktura ti jdou samy: checklist, časy, zápis.', 'Den, který nejde podle plánu (news, rozbitá struktura), tě rozladí víc než ostatní.', [], ['process' => 2]],
        'focus' => ['Fokus', 'Focus', 'executing', 'Udržíš pozornost na jednom trhu a jednom setupu celou seanci.', 'Tunelové vidění: přehlédneš změnu kontextu (news, typ dne), protože jdeš za svým cílem.', ['conviction' => 1], ['attention' => 2]],
        'responsibility' => ['Zodpovědnost', 'Responsibility', 'executing', 'Chyby si přiznáš a zapíšeš, deník u tebe nelže.', 'Ztrátu bereš osobně a jako dluh, který musíš splatit, hlavně u prop účtu.', ['revenge' => 1, 'need' => 1], ['process' => 1]],
        'restorative' => ['Napravující', 'Restorative', 'executing', 'Review je tvoje silná stránka: najdeš, co se pokazilo, a opravíš proces.', 'Ztrátový obchod nebo den chceš hned napravit. Odtud vedou přikupování do ztráty a obchody na vrácení ztráty.', ['revenge' => 2], []],
        // Vliv
        'activator' => ['Aktivátor', 'Activator', 'influencing', 'Když setup přijde, jednáš bez zaváhání.', 'Netrpělivost: vstup dřív, než je setup kompletní, nebo obchod jen kvůli akci.', ['anticipation' => 2, 'stimulation' => 1], ['hesitation' => 1]],
        'command' => ['Velení', 'Command', 'influencing', 'Rozhodneš se jasně a ztrátu uneseš bez dramatu.', 'Spor s trhem: chceš mít pravdu a přidáváš do pozice proti trhu.', ['conviction' => 2, 'overconfidence' => 1], []],
        'communication' => ['Komunikace', 'Communication', 'influencing', 'Obchod umíš popsat slovy, zápis do deníku a sdílení na nástěnce ti jdou přirozeně.', 'Veřejně vyslovený názor na trh tě pak drží v pozici, i když trh říká něco jiného.', ['conviction' => 1, 'social' => 1], []],
        'competition' => ['Soutěživost', 'Competition', 'influencing', 'Měřitelné cíle a statistiky tě ženou ke zlepšení.', 'Trh není soupeř. Srovnávání výsledků s ostatními a potřeba „vyhrát den“ vedou k obchodům na vrácení ztráty.', ['revenge' => 2, 'need' => 1], []],
        'maximizer' => ['Maximalizující', 'Maximizer', 'influencing', 'Brousíš to, co funguje: A+ setupy, lepší exekuci, lepší výstupy.', 'Perfekcionismus: čekáš na dokonalý setup a dobrý necháš utéct, nebo posouváš cíl, až zisk zmizí.', ['hesitation' => 1, 'cutting' => 1], []],
        'self_assurance' => ['Sebejistota', 'Self-Assurance', 'influencing', 'Věříš svému úsudku a pod tlakem zůstaneš klidný.', 'Po sérii zisků roste velikost pozice i jistota a statistiky snadno přehlédneš.', ['overconfidence' => 2], ['hesitation' => 2]],
        'significance' => ['Uznání', 'Significance', 'influencing', 'Velké ambice a chuť dokázat něco výjimečného.', 'Obchod pro velký výsledek a uznání: větší pozice, honba za „velkým dnem“.', ['need' => 2, 'overconfidence' => 1], []],
        'woo' => ['Společenský', 'Woo', 'influencing', 'Snadno navážeš kontakt s dalšími tradery a učíš se od nich.', 'Chaty a skupiny ti mluví do obchodů a cizí nadšení se lehce přenese do tvých vstupů.', ['social' => 2], []],
        // Budování vztahů
        'adaptability' => ['Adaptabilita', 'Adaptability', 'relationship', 'Bereš trh takový, jaký je, a rychle se přizpůsobíš změně typu dne.', 'Improvizace: plán opustíš uprostřed seance a obchoduješ, co se zrovna nabízí.', ['process' => 2, 'consistency' => 1], []],
        'connectedness' => ['Propojenost', 'Connectedness', 'relationship', 'Vidíš souvislosti mezi trhy a ztrátu snáz přijmeš jako součást celku.', 'Hledáš význam a souvislosti i tam, kde je jen šum.', ['anticipation' => 1], []],
        'developer' => ['Rozvíjející', 'Developer', 'relationship', 'Vidíš i malé pokroky a máš trpělivost s vlastní křivkou učení.', 'Víc energie dáš do pomoci druhým než do vlastního deníku a review.', ['process' => 1], []],
        'empathy' => ['Empatie', 'Empathy', 'relationship', 'Vycítíš náladu trhu: strach a chamtivost v pohybu ceny.', 'Emoce trhu i lidí kolem si bereš za své, nálada se ti mění s grafem.', ['social' => 1, 'revenge' => 1], []],
        'harmony' => ['Harmonie', 'Harmony', 'relationship', 'Klid a věcnost, s trhem se nehádáš.', 'Postavit se proti většině je nepříjemné: kontrariánský setup podle plánu necháš být, když všichni tvrdí opak.', ['social' => 2], []],
        'includer' => ['Začleňující', 'Includer', 'relationship', 'Komunita a sdílení ti dodávají energii.', 'Snadno převezmeš názor skupiny místo svého plánu.', ['social' => 1], []],
        'individualization' => ['Individualizace', 'Individualization', 'relationship', 'Všímáš si, čím je každý den a každý trh jiný.', '„Tenhle obchod je výjimka.“ Výjimky z pravidel rozbíjejí statistiku systému.', ['consistency' => 2], []],
        'positivity' => ['Pozitivita', 'Positivity', 'relationship', 'Po ztrátě se rychle zvedneš a neneseš ji do dalšího dne.', 'Optimismus u ztrátové pozice: „ještě se to vrátí“ a stop se posune.', ['cutting' => 2], ['revenge' => 1]],
        'relator' => ['Vztahy', 'Relator', 'relationship', 'Hluboká důvěra v pár lidí. Ideální je stálý trading partner, kterému se zodpovídáš.', 'Názor blízkého člověka nebo mentora může přebít tvůj vlastní plán.', ['social' => 1], []],
        // Myšlení
        'analytical' => ['Analytický', 'Analytical', 'thinking', 'Rozhoduješ podle dat: statistiky deníku, expectancy, rozbor chyb.', 'Paralýza z analýzy: čekáš na další potvrzení nebo přidáváš indikátory.', ['hesitation' => 2], ['process' => 1]],
        'context' => ['Kontext', 'Context', 'thinking', 'Minulost trhu máš v malíčku: včerejší profil, reference, historické chování.', 'Kotvení k minulosti: držíš se starých úrovní, i když trh přešel do nového režimu.', ['conviction' => 1], []],
        'futuristic' => ['Vizionář', 'Futuristic', 'thinking', 'Scénáře na týden dopředu a jasná představa, kam se jako trader posouváš.', 'Obchoduješ pohyb, který si představuješ, dřív než přijde, a sníš o výsledku místo o procesu.', ['anticipation' => 2, 'need' => 1], []],
        'ideation' => ['Nápady', 'Ideation', 'thinking', 'Rychle vymyslíš nový setup nebo hypotézu k otestování.', 'Nové nápady uprostřed seance a přeskakování mezi systémy.', ['consistency' => 2], []],
        'input' => ['Sběratel', 'Input', 'thinking', 'Důkladná příprava: o trhu sesbíráš všechno podstatné.', 'Informační přetížení: příliš zdrojů, zpráv a indikátorů během seance.', ['attention' => 2], []],
        'intellection' => ['Intelektuální', 'Intellection', 'thinking', 'Hluboká reflexe, review a rozbor chyb ti dávají smysl.', 'Přemýšlení v reálném čase brzdí exekuci.', ['hesitation' => 2], []],
        'learner' => ['Učení', 'Learner', 'thinking', 'Baví tě učit se a nový poznatek rychle vstřebáš.', 'Láká tě nová metoda dřív, než tu současnou dotáhneš do statistiky.', ['consistency' => 2], []],
        'strategic' => ['Strategický', 'Strategic', 'thinking', 'Rychle vidíš vzorce a alternativní scénáře. Plán „když A, tak B“ je tvoje silná stránka.', 'Vidíš, kam se trh asi pohne, a vstupuješ dřív, než to potvrdí. Nebo přeskakuješ mezi scénáři.', ['anticipation' => 2, 'consistency' => 1], []],
    ];
}

function strength_theme(string $key): ?array
{
    $theme = strength_themes()[$key] ?? null;
    if ($theme === null) {
        return null;
    }
    [$name, $en, $domain, $plus, $minus] = $theme;
    return ['key' => $key, 'name' => $name, 'en' => $en, 'domain' => $domain, 'domain_label' => strength_domains()[$domain], 'plus' => $plus, 'minus' => $minus];
}

/** Pořadí talentů: jen známé klíče, bez opakování; nejslabší se nesmí krýt s nejsilnějšími. */
function normalize_strengths(array $data): array
{
    $themes = strength_themes();
    $pick = static function (mixed $list, int $max, array $exclude) use ($themes): array {
        $result = [];
        foreach (is_array($list) ? array_values($list) : [] as $key) {
            if (is_string($key) && isset($themes[$key]) && !in_array($key, $result, true) && !in_array($key, $exclude, true)) {
                $result[] = $key;
            }
            if (count($result) >= $max) {
                break;
            }
        }
        return $result;
    };
    $top = $pick($data['top'] ?? [], STRENGTHS_TOP_MAX, []);
    return ['top' => $top, 'bottom' => $pick($data['bottom'] ?? [], STRENGTHS_BOTTOM_MAX, $top)];
}

/* ---------------------------------------------------------------- vyhodnocení */

/**
 * Oblasti zaměření z talentů a z Big Five. Každý zdroj přidá body podle intenzity
 * a pořadí; vyberou se nejvýš tři oblasti s dostatečným skóre i se zdůvodněním.
 */
function personality_focus(?array $traits, array $strengths): array
{
    $scores = [];
    $reasons = [];
    $add = static function (array $map, float $weight, string $reason) use (&$scores, &$reasons): void {
        foreach ($map as $area => $intensity) {
            $scores[$area] = ($scores[$area] ?? 0) + $intensity * $weight;
            $reasons[$area][] = $reason;
        }
    };

    $themes = strength_themes();
    foreach ($strengths['top'] ?? [] as $index => $key) {
        $weight = STRENGTHS_RANK_WEIGHTS[$index] ?? 1;
        $add($themes[$key][5], $weight, sprintf('%s (%d.)', $themes[$key][0], $index + 1));
    }
    foreach ($strengths['bottom'] ?? [] as $key) {
        $add($themes[$key][6], STRENGTHS_BOTTOM_WEIGHT, sprintf('%s mezi nejslabšími', $themes[$key][0]));
    }

    $levels = ['low' => 'nízko', 'high' => 'vysoko'];
    foreach (big5_focus_map() as $trait => $byLevel) {
        $result = $traits[$trait] ?? null;
        if ($result !== null && isset($byLevel[$result['level']])) {
            $add($byLevel[$result['level']], BIG5_WEIGHT, sprintf('%s %s', $result['label'], $levels[$result['level']]));
        }
    }

    $order = array_keys(focus_areas());
    uksort($scores, static function (string $a, string $b) use ($scores, $order): int {
        return [$scores[$b], array_search($a, $order, true)] <=> [$scores[$a], array_search($b, $order, true)];
    });

    $labels = focus_areas();
    $focus = [];
    foreach ($scores as $area => $score) {
        if ($score < FOCUS_MIN_SCORE || count($focus) >= FOCUS_MAX) {
            continue;
        }
        $focus[] = ['area' => $area, 'label' => $labels[$area], 'score' => round($score, 1), 'reasons' => array_values(array_unique($reasons[$area]))];
    }
    return $focus;
}

/**
 * Doporučené dechové cvičení a zvuková kulisa. Delší výdech (4–8) tam, kde je hlavním
 * rizikem stres a reakce na ztrátu; box dýchání tam, kde jde spíš o tempo a pozornost.
 */
function personality_recommendation(array $focus, ?array $traits): array
{
    $areas = array_column($focus, 'area');
    $stressed = ($traits['S']['level'] ?? '') === 'low';
    $calmAreas = array_values(array_intersect($areas, ['revenge', 'physical', 'need']));

    if ($stressed) {
        $pattern = ['calm', 'Na stres reaguješ silněji. Delší výdech tělo zklidní nejrychleji.'];
    } elseif ($calmAreas !== []) {
        $pattern = ['calm', sprintf('Mezi tvými oblastmi je „%s“. Delší výdech srazí napětí dřív, než otevřeš graf.', focus_areas()[$calmAreas[0]])];
    } elseif ($focus !== []) {
        $pattern = ['box', 'Tvoje riziko je spíš v tempu a pozornosti než ve stresu. Pravidelný čtverec dechu srovná tempo a soustředění.'];
    } else {
        $pattern = ['calm', 'Delší výdech je nejjistější způsob, jak se před seancí zklidnit.'];
    }

    $music = [
        'revenge' => ['ocean', 'Pomalé vlny dýchají s tebou a táhnou dech do klidného rytmu.'],
        'physical' => ['ocean', 'Pomalé vlny dýchají s tebou a táhnou dech do klidného rytmu.'],
        'need' => ['ocean', 'Pomalé vlny dýchají s tebou a táhnou dech do klidného rytmu.'],
        'anticipation' => ['drone', 'Hluboký tón bez melodie drží pozornost v přítomnosti, ne v představě dalšího pohybu.'],
        'conviction' => ['drone', 'Hluboký tón bez melodie drží pozornost v přítomnosti, ne u vlastního názoru na trh.'],
        'overconfidence' => ['drone', 'Hluboký tón bez melodie tě vrátí na zem a do přítomnosti.'],
        'hesitation' => ['rain', 'Rovnoměrný déšť ztiší vnitřní dialog a přemýšlení.'],
        'cutting' => ['rain', 'Rovnoměrný déšť ztiší vnitřní dialog a přemýšlení.'],
        'attention' => ['bowls', 'Úder misky na začátku nádechu je kotva pro pozornost.'],
        'consistency' => ['bowls', 'Úder misky na začátku nádechu je kotva pro pozornost a pravidelnost.'],
        'process' => ['bowls', 'Úder misky na začátku nádechu je kotva pro pozornost a pravidelnost.'],
        'stimulation' => ['chimes', 'Jemná melodie drží zájem, takže klid nepřejde v nudu.'],
        'social' => ['chimes', 'Jemná melodie tě odvede od chatů a cizích názorů k vlastnímu dechu.'],
    ];
    $primary = $areas[0] ?? null;
    if ($primary === null && $stressed) {
        $primary = 'revenge';
    }
    [$soundscape, $musicReason] = $music[$primary] ?? ['ocean', 'Výchozí kulisa. Po vyplnění osobnosti ji aplikace vybere podle tebe.'];

    return ['pattern' => $pattern[0], 'pattern_reason' => $pattern[1], 'music' => $soundscape, 'music_reason' => $musicReason];
}

function normalize_breath_preferences(array $data): array
{
    $custom = (array)($data['custom'] ?? []);
    $seconds = static function (mixed $value, int $min, int $max, int $default): int {
        return is_numeric($value) ? max($min, min($max, (int)round((float)$value))) : $default;
    };
    $minutes = is_numeric($data['minutes'] ?? null) ? (int)$data['minutes'] : 0;
    $music = $data['music'] ?? '';
    return [
        'pattern' => in_array($data['pattern'] ?? '', BREATH_PATTERNS, true) ? $data['pattern'] : '',
        'custom' => [
            'inhale' => $seconds($custom['inhale'] ?? null, 2, 10, 4),
            'hold_in' => $seconds($custom['hold_in'] ?? null, 0, 10, 0),
            'exhale' => $seconds($custom['exhale'] ?? null, 2, 12, 6),
            'hold_out' => $seconds($custom['hold_out'] ?? null, 0, 10, 0),
        ],
        'minutes' => in_array($minutes, BREATH_MINUTES, true) ? $minutes : 0,
        'music' => is_string($music) && ($music === 'off' || in_array($music, SOUNDSCAPES, true)) ? $music : '',
        'volume' => is_numeric($data['volume'] ?? null) ? max(0, min(100, (int)$data['volume'])) : 60,
        'cues' => array_key_exists('cues', $data) ? (bool)$data['cues'] : true,
    ];
}

/* ---------------------------------------------------------------- uložení */

function personality_row(): ?array
{
    return fetch_one('SELECT * FROM psych_personality WHERE id = 1');
}

function personality_payload(): array
{
    $row = personality_row();
    $answers = normalize_big5_answers(json_decode((string)($row['big5'] ?? '{}'), true) ?: []);
    $strengths = normalize_strengths(json_decode((string)($row['strengths'] ?? '{}'), true) ?: []);
    $traits = $answers !== [] ? evaluate_big5($answers) : null;
    $focus = personality_focus($traits ?: null, $strengths);

    return [
        'big5' => $traits ? ['traits' => $traits, 'answers' => $answers, 'answered' => count($answers), 'updated_at' => $row['big5_at'] ?? null] : null,
        'strengths' => ($strengths['top'] !== [] || $strengths['bottom'] !== []) ? [
            'top' => array_map('strength_theme', $strengths['top']),
            'bottom' => array_map('strength_theme', $strengths['bottom']),
            'updated_at' => $row['strengths_at'] ?? null,
        ] : null,
        'focus' => $focus,
        'recommendation' => personality_recommendation($focus, $traits ?: null),
        'preferences' => normalize_breath_preferences(json_decode((string)($row['preferences'] ?? '{}'), true) ?: []),
    ];
}

/** Číselníky pro klienta: položky testu, škála, talenty, oblasti. */
function personality_catalog(): array
{
    $themes = [];
    foreach (array_keys(strength_themes()) as $key) {
        $theme = strength_theme($key);
        $themes[] = ['key' => $key, 'name' => $theme['name'], 'en' => $theme['en'], 'domain' => $theme['domain']];
    }
    return [
        'big5_items' => array_map(static fn(array $item): array => ['key' => $item['key'], 'text' => $item['text']], big5_items()),
        'big5_scale' => BIG5_SCALE,
        'themes' => $themes,
        'domains' => strength_domains(),
        'areas' => focus_areas(),
    ];
}

/**
 * Uloží jen části, které přišly: odpovědi Big Five, pořadí talentů, nastavení dýchání.
 * `clear` smaže Big Five nebo talenty.
 */
function save_personality(array $data): array
{
    $row = personality_row();
    $big5 = json_decode((string)($row['big5'] ?? '{}'), true) ?: [];
    $strengths = json_decode((string)($row['strengths'] ?? '{}'), true) ?: [];
    $preferences = json_decode((string)($row['preferences'] ?? '{}'), true) ?: [];
    $big5At = $row['big5_at'] ?? null;
    $strengthsAt = $row['strengths_at'] ?? null;
    $now = utc_now();

    if (array_key_exists('big5_answers', $data)) {
        $answers = normalize_big5_answers((array)$data['big5_answers']);
        if (count($answers) < count(big5_items()) * 0.8) {
            throw new InvalidArgumentException('Osobnostní test není dokončený. Odpověz aspoň na 40 z 50 výroků.');
        }
        $big5 = $answers;
        $big5At = $now;
    }
    if (array_key_exists('strengths', $data)) {
        $strengths = normalize_strengths((array)$data['strengths']);
        $strengthsAt = $now;
    }
    if (array_key_exists('preferences', $data)) {
        $preferences = normalize_breath_preferences((array)$data['preferences']);
    }
    $clear = $data['clear'] ?? null;
    if ($clear === 'big5') {
        $big5 = [];
        $big5At = null;
    } elseif ($clear === 'strengths') {
        $strengths = [];
        $strengthsAt = null;
    }

    $values = [
        json_encode((object)$big5, JSON_UNESCAPED_UNICODE),
        json_encode((object)$strengths, JSON_UNESCAPED_UNICODE),
        json_encode((object)$preferences, JSON_UNESCAPED_UNICODE),
        $big5At,
        $strengthsAt,
    ];
    if ($row === null) {
        db()->prepare('INSERT INTO psych_personality (id, big5, strengths, preferences, big5_at, strengths_at, created_at, updated_at) VALUES (1, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([...$values, $now, $now]);
    } else {
        db()->prepare('UPDATE psych_personality SET big5 = ?, strengths = ?, preferences = ?, big5_at = ?, strengths_at = ?, updated_at = ? WHERE id = 1')
            ->execute([...$values, $now]);
    }
    return personality_payload();
}

/** Klíče oblastí, na které se má rychlý test zaměřit kvůli osobnosti (v pořadí důležitosti). */
function personality_focus_areas(): array
{
    if (personality_row() === null) {
        return [];
    }
    $payload = personality_payload();
    $result = [];
    foreach ($payload['focus'] as $item) {
        $result[$item['area']] = $item['reasons'];
    }
    return $result;
}
