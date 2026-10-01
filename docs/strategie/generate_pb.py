"""P do trendu (bullish) a b do trendu (bearish) – generuje SVG do docs/strategie/."""
import os
OUT = os.path.dirname(os.path.abspath(__file__)) + '/'
# (O, H, L, C) – bullish P; b je zrcadlo
P = [
 (20,23,19,22),(22,24,21,23),                                  # 1-2 kontext
 (23,29,22.5,28.5),(28.5,35,28,34.5),(34.5,41,34,40.5),        # 3-5 impulz 1
 (40.5,43,38.5,39.5),(39.5,42,37.5,41.5),(41.5,42.5,38,38.8),
 (38.8,41.8,37,41),(41,42.8,39,39.8),                          # 6-10 rotace
 (39.8,45,39.5,44.5),(44.5,50,44,49.5),(49.5,55,49,54),        # 11-13 impulz 2
 (54,54.5,49,49.8),(49.8,50.5,45.5,46.2),(46.2,47.5,43.7,46.8),# 14-16 pullback do F5
 (46.8,51,46.5,50.5),(50.5,55.5,50,55),(55,58,54,57),(57,62,56.5,61.5)]  # 17-20 k OP
ROT = (6, 10); IMP1 = (3, 5); IMP2 = (11, 13); BRK = 11; ENTRY = 16
F5R = 0.618

def build(fname, bull):
    W, H = 1280, 770
    t = (lambda p: p) if bull else (lambda p: 81 - p)
    y = lambda p: round(660 - (t(p) - 10) * 10, 1)
    x = lambda i: 80 + (i - 1) * 40
    hi = lambda i: P[i-1][1]; lo = lambda i: P[i-1][2]
    rlo = min(lo(i) for i in range(ROT[0], ROT[1] + 1)); rhi = max(hi(i) for i in range(ROT[0], ROT[1] + 1))
    ih = max(hi(i) for i in range(IMP2[0], IMP2[1] + 1)); rng = ih - rlo
    F5 = ih - F5R * rng; C = lo(ENTRY); OP = C + rng; SL = rlo - 2
    T = dict(nazev='P do trendu', dir='BULLISH (long)', nad='nad', pod='pod', low='low', high='high',
             Low='Low', High='High', trade='long', up='nahoru') if bull else \
        dict(nazev='b do trendu', dir='BEARISH (short)', nad='pod', pod='nad', low='high', high='low',
             Low='High', High='Low', trade='short', up='dolů')
    f = lambda s: s.format(**T)
    o = []; a = o.append
    a(f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {W} {H}" width="{W}" height="{H}" font-family="Segoe UI, Arial, sans-serif">')
    a('<defs>' + ''.join(
        f'<marker id="{n}" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0,0 L10,5 L0,10 z" fill="{c}"/></marker>'
        for n, c in (('ar', '#d1d4dc'), ('arG', '#4caf50'), ('arY', '#ffb300'))) + '</defs>')
    a(f'<rect width="{W}" height="{H}" fill="#131722"/>')
    a(f'<text x="40" y="44" fill="#ffffff" font-size="24" font-weight="700">{f("{nazev}")} {T["dir"]} – impulz → rotace → impulz → pullback do F5</text>')
    a(f'<text x="40" y="70" fill="#9598a1" font-size="14">{f("Fibo: {low} rotace → {high} impulzu, který zavřel {nad} rotací · vstup F5 · SL {pod} rotací · TP = OP expanze")}</text>')
    for g in range(15, 70, 5):
        a(f'<line x1="50" x2="1240" y1="{660-(g-10)*10}" y2="{660-(g-10)*10}" stroke="#1e222d"/>')
    # rotation box
    bx0, bx1 = x(ROT[0]) - 16, x(ROT[1]) + 16
    a(f'<rect x="{bx0}" y="{min(y(rhi),y(rlo))}" width="{bx1-bx0}" height="{abs(y(rhi)-y(rlo))}" fill="#7e57c2" opacity="0.14" stroke="#7e57c2" stroke-width="1.2" stroke-dasharray="4 3"/>')
    ry = max(y(rhi), y(rlo)) + (42 if bull else 22)
    a(f'<text x="{(bx0+bx1)/2}" y="{ry}" fill="#b39ddb" font-size="13" font-weight="700" text-anchor="middle">Akumulace / rotace</text>')
    a(f'<text x="{(bx0+bx1)/2}" y="{ry+16}" fill="#b39ddb" font-size="11" text-anchor="middle">≥ 4 close uvnitř předchozích svíček</text>')
    xe = 1010
    # fib, SL, TP
    for p, col, lab, dash in ((ih, '#787b86', f('1.0 ({high} impulzu 2)'), '4 4'), (F5, '#ffb300', 'F5 (0.618) – VSTUP', ''),
                              (rlo, '#787b86', f('0.0 ({low} rotace)'), '4 4')):
        a(f'<line x1="{x(ROT[0])-16}" x2="{xe}" y1="{y(p)}" y2="{y(p)}" stroke="{col}" stroke-width="1.3" stroke-dasharray="{dash}"/>')
        a(f'<text x="{xe+8}" y="{y(p)+5}" fill="{col}" font-size="13" font-weight="{700 if col=="#ffb300" else 400}">{lab}</text>')
    a(f'<line x1="{x(ROT[0])-16}" x2="{xe}" y1="{y(SL)}" y2="{y(SL)}" stroke="#ef5350" stroke-width="2" stroke-dasharray="8 4"/>')
    a(f'<text x="{xe+8}" y="{y(SL)+5}" fill="#ef5350" font-size="14" font-weight="700">{f("SL – {pod} rotací")}</text>')
    a(f'<line x1="{x(ENTRY)}" x2="{xe}" y1="{y(OP)}" y2="{y(OP)}" stroke="#4caf50" stroke-width="2" stroke-dasharray="8 4"/>')
    a(f'<text x="{xe+8}" y="{y(OP)+5}" fill="#4caf50" font-size="14" font-weight="700">TP – OP (1.0 expanze)</text>')
    # candles
    for i, (op, h, l, cl) in enumerate(P, 1):
        col = '#26a69a' if t(cl) >= t(op) else '#ef5350'
        cx = x(i)
        a(f'<line x1="{cx}" x2="{cx}" y1="{y(h)}" y2="{y(l)}" stroke="{col}" stroke-width="1.6"/>')
        top, bot = sorted((y(op), y(cl)))
        a(f'<rect x="{cx-10}" y="{top}" width="20" height="{max(bot-top,1.5)}" fill="{col}"/>')
        a(f'<text x="{cx}" y="625" fill="#787b86" font-size="12" text-anchor="middle">{i}</text>')
    a('<text x="40" y="625" fill="#555a66" font-size="11" text-anchor="end">#</text>')
    a('<line x1="50" x2="1000" y1="605" y2="605" stroke="#2a2e39"/>')
    # impulse brackets (beside the candles)
    def bracket(i0, i1, p0, p1, txt, sub, side):
        bxp = x(i0) - 26 if side == 'L' else x(i1) + 26
        a(f'<line x1="{bxp}" x2="{bxp}" y1="{y(p0)}" y2="{y(p1)}" stroke="#d1d4dc" stroke-width="1.2" marker-end="url(#ar)" opacity="0.8"/>')
        anchor = 'start'; tx = x(i1) + 18
        my = (y(p0) + y(p1)) / 2 + (45 if bull else 0)
        a(f'<text x="{tx}" y="{my}" fill="#d1d4dc" font-size="13" font-weight="700" text-anchor="{anchor}">{txt}</text>')
        a(f'<text x="{tx}" y="{my+16}" fill="#9598a1" font-size="11" text-anchor="{anchor}">{sub}</text>')
    bracket(IMP1[0], IMP1[1], lo(IMP1[0]), hi(IMP1[1]), 'Impulz 1', 'ideálně 3 svíčky jedním směrem', 'L')
    # close above rotation
    bc = P[BRK-1][3]
    a(f'<circle cx="{x(BRK)}" cy="{y(bc)}" r="5" fill="none" stroke="#42a5f5" stroke-width="2"/>')
    a(f'<line x1="{x(BRK)-50}" x2="{x(BRK)-8}" y1="{y(bc)+(-40 if bull else 40)}" y2="{y(bc)+(-4 if bull else 4)}" stroke="#42a5f5" stroke-width="1.5"/>')
    a(f'<text x="{x(BRK)-54}" y="{y(bc)+(-46 if bull else 56)}" fill="#42a5f5" font-size="13" font-weight="700" text-anchor="end">{f("close {nad} rotací")}</text>')
    lx = x(IMP2[1]) + 4; ly = y(ih) + (-14 if bull else 24)
    a(f'<text x="{lx}" y="{ly}" fill="#d1d4dc" font-size="13" font-weight="700" text-anchor="middle">Impulz 2</text>')
    # entry
    ex, ey = x(ENTRY), y(F5)
    s = 1 if bull else -1
    a(f'<circle cx="{ex}" cy="{ey}" r="5" fill="#ffb300"/>')
    a(f'<line x1="{ex+60}" x2="{ex+12}" y1="{ey+s*44}" y2="{ey+s*4}" stroke="#ffb300" stroke-width="2" marker-end="url(#arY)"/>')
    a(f'<text x="{ex+64}" y="{ey+s*56 + (0 if bull else 8)}" fill="#ffb300" font-size="14" font-weight="700">{f("VSTUP ({trade}) na F5")}</text>')
    a(f'<text x="{x(ENTRY)}" y="{y(C)+(22 if bull else -12)}" fill="#ffffff" font-size="14" font-weight="700" text-anchor="middle">C</text>')
    # OP measure
    a(f'<line x1="980" x2="980" y1="{y(rlo)}" y2="{y(ih)}" stroke="#d1d4dc" stroke-width="1.2" marker-start="url(#ar)" marker-end="url(#ar)" opacity="0.6"/>')
    a(f'<text x="972" y="{(y(rlo)+y(ih))/2+4}" fill="#9598a1" font-size="11" text-anchor="end">{f("{low} rotace → {high}")}</text>')
    a(f'<line x1="945" x2="945" y1="{y(C)}" y2="{y(OP)}" stroke="#4caf50" stroke-width="1.2" marker-start="url(#arG)" marker-end="url(#arG)" opacity="0.8"/>')
    a(f'<text x="937" y="{(y(C)+y(OP))/2 + 4}" fill="#4caf50" font-size="11" text-anchor="end">C→OP = stejná délka</text>')
    steps = ['1. Impulz 1: jedna velká, ideálně 3 svíčky jedním směrem ({up}).',
             '2. Akumulace / rotace: aspoň 4 close vedle sebe uvnitř předchozích svíček – trh nedokáže zavřít mimo rotaci.',
             '3. Impulz 2: svíčka zavře {nad} rotací (sv. 11) a impulz pokračuje.',
             '4. Fibo od {low} rotace po {high} impulzu 2, vstup {trade} na F5 (61.8 %).',
             '5. SL {pod} rotací, TP na OP expanze (délka {low} rotace → {high} impulzu promítnutá od C).']
    for k, st in enumerate(steps):
        a(f'<text x="40" y="{666 + k*20}" fill="#9598a1" font-size="13">{f(st)}</text>')
    a('</svg>')
    open(OUT + fname, 'w').write('\n'.join(o))

build('p_do_trendu_bullish.svg', True)
build('b_do_trendu_bearish.svg', False)
