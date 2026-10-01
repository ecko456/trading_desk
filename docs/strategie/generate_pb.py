"""P / b formace – generuje SVG do docs/strategie/.

Každý scénář je definovaný z pohledu LONG obchodu; short verze je zrcadlo
(cena se překlopí, texty se prohodí přes slovník T).
"""
import os
OUT = os.path.dirname(os.path.abspath(__file__)) + '/'

# (O, H, L, C)
TREND = [
 (20,23,19,22),(22,24,21,23),                                  # 1-2 kontext
 (23,29,22.5,28.5),(28.5,35,28,34.5),(34.5,41,34,40.5),        # 3-5 impulz 1 nahoru
 (40.5,43,38.5,39.5),(39.5,42,37.5,41.5),(41.5,42.5,38,38.8),
 (38.8,41.8,37,41),(41,42.8,39,39.8),                          # 6-10 rotace
 (39.8,45,39.5,44.5),(44.5,50,44,49.5),(49.5,55,49,54),        # 11-13 impulz 2
 (54,54.5,49,49.8),(49.8,50.5,45.5,46.2),(46.2,47.5,43.7,46.8),# 14-16 pullback do F5
 (46.8,51,46.5,50.5),(50.5,55.5,50,55),(55,58,54,57),(57,62,56.5,61.5)]  # 17-20 k OP
DOWN = [
 (60,61,58,58.5),(58.5,59,56.5,57),                            # 1-2 kontext
 (57,57.5,51,51.5),(51.5,52,45,45.5),(45.5,46,39.5,40),        # 3-5 impulz 1 dolů
 (40,42,37.5,41),(41,42.5,38,38.8),(38.8,42,37,41.2),
 (41.2,42.8,38.5,39.2),(39.2,42,38.2,41.5)]                    # 6-10 rotace
REV = DOWN + [
 (41.5,46,41,45.5),(45.5,50,45,49.5),(49.5,53,49,52.5),        # 11-13 close nad rotací + impulz
 (52.5,53,48.5,49),(49,49.5,45,45.5),(45.5,46.5,43,46),        # 14-16 pullback do F5
 (46,50,45.5,49.5),(49.5,54,49,53.5),(53.5,57,53,56.5),(56.5,59.5,56,59)]  # 17-20 k OP
CLOSEIN = DOWN + [
 (41.5,42,33,40.5),                                            # 11 vykopnutí lows + close v rotaci
 (40.5,44,40,43.5),(43.5,47.5,43,47),(47,49,45.5,46),
 (46,51,45.5,50.5),(50.5,54,50,53.5),(53.5,57,53,56.5)]        # 12-17 k TP 1:2

F5R = 0.618

def build(fname, sc, bull):
    C_ = sc['candles']
    W, H = 1280, 770
    mid = min(c[2] for c in C_) + max(c[1] for c in C_)
    t = (lambda p: p) if bull else (lambda p: mid - p)
    y = lambda p: round(660 - (t(p) - 10) * 10, 1)
    x = lambda i: 80 + (i - 1) * 40
    hi = lambda i: C_[i-1][1]; lo = lambda i: C_[i-1][2]
    ROT = sc['rot']
    rlo = min(lo(i) for i in range(ROT[0], ROT[1] + 1)); rhi = max(hi(i) for i in range(ROT[0], ROT[1] + 1))
    T = dict(nad='nad', pod='pod', low='low', high='high', lows='lows', highs='highs', trade='long',
             buy='buy', imp='nahoru' if sc['imp1_up'] else 'dolů', name=sc['name_bull'], dir='BULLISH (long)') if bull else \
        dict(nad='pod', pod='nad', low='high', high='low', lows='highs', highs='lows', trade='short',
             buy='sell', imp='dolů' if sc['imp1_up'] else 'nahoru', name=sc['name_bear'], dir='BEARISH (short)')
    f = lambda s: s.format(**T)
    o = []; a = o.append
    a(f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {W} {H}" width="{W}" height="{H}" font-family="Segoe UI, Arial, sans-serif">')
    a('<defs>' + ''.join(
        f'<marker id="{n}" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0,0 L10,5 L0,10 z" fill="{c}"/></marker>'
        for n, c in (('ar', '#d1d4dc'), ('arG', '#4caf50'), ('arY', '#ffb300'))) + '</defs>')
    a(f'<rect width="{W}" height="{H}" fill="#131722"/>')
    a(f'<text x="40" y="44" fill="#ffffff" font-size="24" font-weight="700">{f("{name} {dir}")} – {sc["title"]}</text>')
    a(f'<text x="40" y="70" fill="#9598a1" font-size="14">{f(sc["subtitle"])}</text>')
    for g in range(15, 70, 5):
        a(f'<line x1="50" x2="1240" y1="{660-(g-10)*10}" y2="{660-(g-10)*10}" stroke="#1e222d"/>')
    xe = 1010
    # rotation box
    bx0, bx1 = x(ROT[0]) - 16, x(ROT[1]) + 16
    a(f'<rect x="{bx0}" y="{min(y(rhi),y(rlo))}" width="{bx1-bx0}" height="{abs(y(rhi)-y(rlo))}" fill="#7e57c2" opacity="0.14" stroke="#7e57c2" stroke-width="1.2" stroke-dasharray="4 3"/>')
    pos, dy = sc['rot_lab'][bull]
    ry = min(y(rhi), y(rlo)) - dy if pos == 'above' else max(y(rhi), y(rlo)) + dy
    a(f'<text x="{(bx0+bx1)/2}" y="{ry}" fill="#b39ddb" font-size="13" font-weight="700" text-anchor="middle">Akumulace / rotace</text>')
    a(f'<text x="{(bx0+bx1)/2}" y="{ry+16}" fill="#b39ddb" font-size="11" text-anchor="middle">≥ 4 close uvnitř předchozích svíček</text>')
    # candles
    for i, (op, h, l, cl) in enumerate(C_, 1):
        col = '#26a69a' if t(cl) >= t(op) else '#ef5350'
        cx = x(i)
        a(f'<line x1="{cx}" x2="{cx}" y1="{y(h)}" y2="{y(l)}" stroke="{col}" stroke-width="1.6"/>')
        top, bot = sorted((y(op), y(cl)))
        a(f'<rect x="{cx-10}" y="{top}" width="20" height="{max(bot-top,1.5)}" fill="{col}"/>')
        a(f'<text x="{cx}" y="625" fill="#787b86" font-size="12" text-anchor="middle">{i}</text>')
    a('<text x="40" y="625" fill="#555a66" font-size="11" text-anchor="end">#</text>')
    a('<line x1="50" x2="1000" y1="605" y2="605" stroke="#2a2e39"/>')
    # impulse 1 bracket
    i0, i1 = sc['imp1']
    p0, p1 = (lo(i0), hi(i1)) if sc['imp1_up'] else (hi(i0), lo(i1))
    bxp = x(i0) - 26
    a(f'<line x1="{bxp}" x2="{bxp}" y1="{y(p0)}" y2="{y(p1)}" stroke="#d1d4dc" stroke-width="1.2" marker-end="url(#ar)" opacity="0.8"/>')
    ty = y(p0) + (y(p1) - y(p0)) * sc['imp1_frac'][bull]
    a(f'<text x="{x(i1)+18}" y="{ty}" fill="#d1d4dc" font-size="13" font-weight="700">{f("Impulz 1 ({imp})")}</text>')
    a(f'<text x="{x(i1)+18}" y="{ty+16}" fill="#9598a1" font-size="11">ideálně 3 svíčky jedním směrem</text>')

    if sc['mode'] == 'pullback':
        BRK, (j0, j1), ENTRY = sc['brk'], sc['imp2'], sc['entry']
        ih = max(hi(i) for i in range(j0, j1 + 1)); rng = ih - rlo
        F5 = ih - F5R * rng; C = lo(ENTRY); OP = C + rng; SL = rlo - 2
        for p, col, lab, dash in ((ih, '#787b86', f('1.0 ({high} impulzu 2)'), '4 4'), (F5, '#ffb300', 'F5 (0.618) – VSTUP', ''),
                                  (rlo, '#787b86', f('0.0 ({low} rotace)'), '4 4')):
            a(f'<line x1="{bx0}" x2="{xe}" y1="{y(p)}" y2="{y(p)}" stroke="{col}" stroke-width="1.3" stroke-dasharray="{dash}"/>')
            a(f'<text x="{xe+8}" y="{y(p)+5}" fill="{col}" font-size="13" font-weight="{700 if col == "#ffb300" else 400}">{lab}</text>')
        a(f'<line x1="{bx0}" x2="{xe}" y1="{y(SL)}" y2="{y(SL)}" stroke="#ef5350" stroke-width="2" stroke-dasharray="8 4"/>')
        a(f'<text x="{xe+8}" y="{y(SL)+5}" fill="#ef5350" font-size="14" font-weight="700">{f("SL – {pod} rotací")}</text>')
        a(f'<line x1="{x(ENTRY)}" x2="{xe}" y1="{y(OP)}" y2="{y(OP)}" stroke="#4caf50" stroke-width="2" stroke-dasharray="8 4"/>')
        a(f'<text x="{xe+8}" y="{y(OP)+5}" fill="#4caf50" font-size="14" font-weight="700">TP – OP (1.0 expanze)</text>')
        bc = C_[BRK-1][3]; s = 1 if bull else -1
        a(f'<circle cx="{x(BRK)}" cy="{y(bc)}" r="5" fill="none" stroke="#42a5f5" stroke-width="2"/>')
        a(f'<line x1="{x(BRK)-50}" x2="{x(BRK)-8}" y1="{y(bc)-s*40}" y2="{y(bc)-s*4}" stroke="#42a5f5" stroke-width="1.5"/>')
        a(f'<text x="{x(BRK)-54}" y="{y(bc)-s*46 + (0 if bull else 10)}" fill="#42a5f5" font-size="13" font-weight="700" text-anchor="end">{f("close {nad} rotací")}</text>')
        a(f'<text x="{x(j1)+4}" y="{y(ih) - s*14 + (0 if bull else 10)}" fill="#d1d4dc" font-size="13" font-weight="700" text-anchor="middle">Impulz 2</text>')
        ex, ey = x(ENTRY), y(F5)
        a(f'<circle cx="{ex}" cy="{ey}" r="5" fill="#ffb300"/>')
        a(f'<line x1="{ex+60}" x2="{ex+12}" y1="{ey+s*44}" y2="{ey+s*4}" stroke="#ffb300" stroke-width="2" marker-end="url(#arY)"/>')
        a(f'<text x="{ex+64}" y="{ey+s*56 + (0 if bull else 8)}" fill="#ffb300" font-size="14" font-weight="700">{f("VSTUP ({trade}) na F5")}</text>')
        a(f'<text x="{ex}" y="{y(C)+s*22 + (0 if bull else 10)}" fill="#ffffff" font-size="14" font-weight="700" text-anchor="middle">C</text>')
        a(f'<line x1="980" x2="980" y1="{y(rlo)}" y2="{y(ih)}" stroke="#d1d4dc" stroke-width="1.2" marker-start="url(#ar)" marker-end="url(#ar)" opacity="0.6"/>')
        a(f'<text x="972" y="{(y(rlo)+y(ih))/2+4}" fill="#9598a1" font-size="11" text-anchor="end">{f("{low} rotace → {high}")}</text>')
        a(f'<line x1="945" x2="945" y1="{y(C)}" y2="{y(OP)}" stroke="#4caf50" stroke-width="1.2" marker-start="url(#arG)" marker-end="url(#arG)" opacity="0.8"/>')
        a(f'<text x="937" y="{(y(C)+y(OP))/2 + 4}" fill="#4caf50" font-size="11" text-anchor="end">C→OP = stejná délka</text>')
    else:  # close-in: sweep of rotation lows, close back inside, market entry, RR 1:2
        SW = sc['sweep']; E = C_[SW-1][3]; SL = lo(SW) - 0.5; R = E - SL; TP = E + 2 * R
        x0, x1 = x(SW) - 12, xe
        a(f'<rect x="{x0}" y="{min(y(E),y(SL))}" width="{x1-x0}" height="{abs(y(SL)-y(E))}" fill="#ef5350" opacity="0.13"/>')
        a(f'<rect x="{x0}" y="{min(y(E),y(TP))}" width="{x1-x0}" height="{abs(y(TP)-y(E))}" fill="#4caf50" opacity="0.10"/>')
        a(f'<line x1="{bx0}" x2="{x(SW)+30}" y1="{y(rlo)}" y2="{y(rlo)}" stroke="#42a5f5" stroke-width="1.6" stroke-dasharray="5 3"/>')
        for p, col, lab in ((TP, '#4caf50', 'TP – RR 1:2'), (E, '#ffb300', f('VSTUP – market {buy} na close')), (SL, '#ef5350', f('SL – {pod} nové {low}'))):
            a(f'<line x1="{x0}" x2="{xe}" y1="{y(p)}" y2="{y(p)}" stroke="{col}" stroke-width="2" stroke-dasharray="{"" if col == "#ffb300" else "8 4"}"/>')
            a(f'<text x="{xe+8}" y="{y(p)+5}" fill="{col}" font-size="14" font-weight="700">{lab}</text>')
        s = 1 if bull else -1
        a(f'<text x="{xe-10}" y="{(y(E)+y(SL))/2+5}" fill="#ef5350" font-size="12" text-anchor="end">1R</text>')
        a(f'<text x="{xe-10}" y="{(y(E)+y(TP))/2+5}" fill="#4caf50" font-size="12" text-anchor="end">2R</text>')
        a(f'<circle cx="{x(SW)}" cy="{y(lo(SW))}" r="6" fill="none" stroke="#42a5f5" stroke-width="2"/>')
        a(f'<text x="{x(SW)+16}" y="{y(lo(SW))+s*26 + (0 if bull else 8)}" fill="#42a5f5" font-size="13" font-weight="700">{f("vykopnutí všech {lows} rotace")}</text>')
        a(f'<circle cx="{x(SW)}" cy="{y(E)}" r="5" fill="#ffb300"/>')
        a(f'<text x="{x(SW)+20}" y="{y(E)+s*28 + (0 if bull else 8)}" fill="#ffb300" font-size="13" font-weight="700">{f("close zpět v rotaci → vstup")}</text>')
    for k, st in enumerate(sc['steps']):
        a(f'<text x="40" y="{666 + k*20}" fill="#9598a1" font-size="13">{f(st)}</text>')
    a('</svg>')
    open(OUT + fname, 'w').write('\n'.join(o))

SCEN = {
 'trend': dict(candles=TREND, rot=(6, 10), imp1=(3, 5), imp1_up=True, imp1_frac={True: 0.26, False: 0.5}, rot_lab={True: ('below', 42), False: ('below', 22)},
    mode='pullback', brk=11, imp2=(11, 13), entry=16, name_bull='P do trendu', name_bear='b do trendu',
    title='impulz → rotace → impulz → pullback do F5',
    subtitle='Fibo: {low} rotace → {high} impulzu, který zavřel {nad} rotací · vstup F5 · SL {pod} rotací · TP = OP expanze',
    steps=['1. Impulz 1: jedna velká, ideálně 3 svíčky jedním směrem ({imp}).',
           '2. Akumulace / rotace: aspoň 4 close vedle sebe uvnitř předchozích svíček – trh nedokáže zavřít mimo rotaci.',
           '3. Impulz 2: svíčka zavře {nad} rotací (sv. 11) a impulz pokračuje ve směru trendu.',
           '4. Fibo od {low} rotace po {high} impulzu 2, vstup {trade} na F5 (61.8 %).',
           '5. SL {pod} rotací, TP na OP expanze (délka {low} rotace → {high} impulzu promítnutá od C).']),
 'reversal': dict(candles=REV, rot=(6, 10), imp1=(3, 5), imp1_up=False, imp1_frac={True: 0.1, False: 0.1}, rot_lab={True: ('below', 42), False: ('below', 22)},
    mode='pullback', brk=11, imp2=(11, 13), entry=16, name_bull='b reversal', name_bear='P reversal',
    title='impulz → rotace → close out proti → pullback do F5',
    subtitle='Po impulzu {imp} a rotaci trh zavře {nad} rotací (proti impulzu) · vstup na F5 · SL {pod} rotací · TP = OP expanze',
    steps=['1. Impulz 1: jedna velká, ideálně 3 svíčky jedním směrem ({imp}).',
           '2. Akumulace / rotace: aspoň 4 close vedle sebe uvnitř předchozích svíček.',
           '3. Close out PROTI impulzu: svíčka zavře {nad} rotací (sv. 11) → reversal.',
           '4. Fibo od {low} rotace po {high} impulzu z rotace, při pullbacku vstup {trade} na F5 (61.8 %).',
           '5. SL {pod} rotací, TP na OP expanze (délka {low} rotace → {high} impulzu promítnutá od C).']),
 'closein': dict(candles=CLOSEIN, rot=(6, 10), imp1=(3, 5), imp1_up=False, imp1_frac={True: 0.1, False: 0.1}, rot_lab={True: ('above', 30), False: ('above', 30)},
    mode='closein', sweep=11, name_bull='b reversal close-in', name_bear='P reversal close-in',
    title='vykopnutí rotace a close zpět dovnitř',
    subtitle='Po impulzu {imp} a rotaci jedna svíčka vykopne všechny {lows} rotace a zavře zpět v rotaci → market {buy}, SL {pod} nové {low}, TP 1:2',
    steps=['1. Impulz 1: jedna velká, ideálně 3 svíčky jedním směrem ({imp}).',
           '2. Akumulace / rotace: aspoň 4 close vedle sebe uvnitř předchozích svíček.',
           '3. Jedna svíčka (sv. 11) vykopne všechny {lows} rotace a zavře zpět uvnitř rotace.',
           '4. Vstup market {buy} na close této svíčky.',
           '5. SL {pod} nové {low} (knot sv. 11), TP = 2 × riziko (RR 1:2).']),
}
FILES = {('trend', True): 'p_do_trendu_bullish.svg', ('trend', False): 'b_do_trendu_bearish.svg',
         ('reversal', True): 'b_reversal_bullish.svg', ('reversal', False): 'p_reversal_bearish.svg',
         ('closein', True): 'b_reversal_closein_bullish.svg', ('closein', False): 'p_reversal_closein_bearish.svg'}
for (k, bull), fn in FILES.items():
    build(fn, SCEN[k], bull)
