import os
os.makedirs('/home/user/trading_desk/docs/strategie', exist_ok=True)
OUT = '/home/user/trading_desk/docs/strategie/'
BASE_UP = [
 (20,25,18,24),(24,31,23,30),(30,36,29,35),(35,36,31,32),(32,33,28,30),
 (30,38,29.5,37),(37,44,36,43),(43,49,42,47),(47,48,43,44),(44,46,40,42),
 (42,52,41.5,51),(51,58,50,57)]
STD = BASE_UP + [
 (57,64,56,58),(58,60,52,53),(53,55,47,48),(48,50,44,45),(45,46,36,37),(37,38,32,34),
 (34,41,33,40),(40,47,39,46),(46,52,45,48),(48,57.5,47,50),
 (50,51,44,45),(45,46,38,39),(39,40,32,33),(33,35,28,29),(29,30,25,26)]
ANOM = BASE_UP + [
 (57,64,48,50),(50,54,49,53),(53,53.8,46,47),(47,48,42,43),
 (43,49,42.5,48),(48,54,47,53),(53,56,52,54),(54,59.5,53,55),
 (55,56,50,51),(51,52,45,46),(46,47,41,42),(42,43,37,38)]
TRAP = [list(c) for c in ANOM]; TRAP[14] = (53,55,46,47)
F5R, F7R = 0.618, 0.789

def build(fname, candles, bull, sc):
    W, H = 1280, 770
    t = (lambda p: 84 - p) if bull else (lambda p: p)
    y = lambda p: round(660 - (t(p) - 10) * 10, 1)
    x = lambda i: 80 + (i - 1) * 34
    A, B, Cc = sc['A'], sc['B'], sc['C']
    lo = lambda i: candles[i-1][2]; hi = lambda i: candles[i-1][1]
    Ap, Bp, Cp = hi(A), lo(B), hi(Cc)
    rng = Ap - Bp
    F5 = Bp + F5R * rng; F7 = Bp + F7R * rng; OP = Cp - rng; SL = Ap + 2
    # text variants
    T = dict(HH='LL', LL='HH', LH='HL', HL='LH', High='Low', high='low', low='high', Low='High',
             short='long', nad='pod', pod='nad', vyssi='nižší', nejv='nejnižšího', up='downtrend', bearish='bullish') if bull else \
        dict(HH='HH', LL='LL', LH='LH', HL='HL', High='High', high='high', low='low', Low='Low',
             short='short', nad='nad', pod='pod', vyssi='vyšší', nejv='nejvyššího', up='uptrend', bearish='bearish')
    up = lambda above: (not above) if bull else above   # flip label side
    o = []; a = o.append
    a(f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {W} {H}" width="{W}" height="{H}" font-family="Segoe UI, Arial, sans-serif">')
    a('<defs>' + ''.join(
        f'<marker id="{n}" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0,0 L10,5 L0,10 z" fill="{c}"/></marker>'
        for n, c in (('ar', '#d1d4dc'), ('arG', '#4caf50'), ('arY', '#ffb300'))) + '</defs>')
    a(f'<rect width="{W}" height="{H}" fill="#131722"/>')
    a(f'<text x="40" y="44" fill="#ffffff" font-size="24" font-weight="700">{sc["title"]}</text>')
    a(f'<text x="40" y="70" fill="#9598a1" font-size="14">{sc["subtitle"].format(**T)}</text>')
    for g in range(20, 70, 5):
        a(f'<line x1="50" x2="1240" y1="{660-(g-10)*10}" y2="{660-(g-10)*10}" stroke="#1e222d"/>')
    xf2 = 1070
    a(f'<rect x="{x(B)}" y="{min(y(F5),y(F7))}" width="{xf2-x(B)}" height="{abs(y(F5)-y(F7))}" fill="#ffb300" opacity="0.10"/>')
    for p, col, lab, dash in ((Ap, '#787b86', f'1.0  (A – {T["High"]})', '4 4'),
                              (F7, '#ffb300', 'F7  (0.789)', ''), (F5, '#ffb300', 'F5  (0.618)', ''),
                              (Bp, '#787b86', f'0.0  (B – {T["Low"]} impulsu)', '4 4')):
        a(f'<line x1="{x(A)}" x2="{xf2}" y1="{y(p)}" y2="{y(p)}" stroke="{col}" stroke-width="1.3" stroke-dasharray="{dash}"/>')
        a(f'<text x="{xf2+8}" y="{y(p)+5}" fill="{col}" font-size="13">{lab}</text>')
    a(f'<line x1="{x(A)}" x2="{xf2}" y1="{y(SL)}" y2="{y(SL)}" stroke="#ef5350" stroke-width="2" stroke-dasharray="8 4"/>')
    a(f'<text x="{xf2+8}" y="{y(SL)+5}" fill="#ef5350" font-size="14" font-weight="700">SL – {T["nad"]} {T["High"]}</text>')
    a(f'<line x1="{x(Cc)}" x2="{xf2}" y1="{y(OP)}" y2="{y(OP)}" stroke="#4caf50" stroke-width="2" stroke-dasharray="8 4"/>')
    a(f'<text x="{xf2+8}" y="{y(OP)+5}" fill="#4caf50" font-size="14" font-weight="700">TP – OP (1.0 expanze)</text>')
    # structure lines (pivot / anomaly low)
    for ln in sc['lines']:
        i0, p, i1, col, lab, sub, width = ln['i0'], ln['p'], ln['i1'], ln['col'], ln['lab'], ln.get('sub'), ln.get('w', 2)
        a(f'<line x1="{x(i0)}" x2="{x(i1)+20}" y1="{y(p)}" y2="{y(p)}" stroke="{col}" stroke-width="{width}" stroke-dasharray="{ln.get("dash","")}"/>')
        dy = 38 if not bull else -48
        a(f'<text x="{x(i0)+ln.get("lx",-10)}" y="{y(p)+dy}" fill="{col}" font-size="13" text-anchor="middle">{lab.format(**T)}</text>')
        if sub:
            a(f'<text x="{x(i0)+ln.get("lx",-10)}" y="{y(p)+dy+16}" fill="{col}" font-size="11" text-anchor="middle">{sub.format(**T)}</text>')
        if ln.get('bos'):
            if ln.get('bos_above'):
                a(f'<text x="{x(i1)+16}" y="{y(p)+(-8 if not bull else 18)}" fill="{col}" font-size="14" font-weight="700">BOS</text>')
            else:
                a(f'<text x="{x(i1)+28}" y="{y(p)+5}" fill="{col}" font-size="14" font-weight="700">BOS</text>')
            a(f'<circle cx="{x(i1)}" cy="{y(p)}" r="5" fill="none" stroke="{col}" stroke-width="2"/>')
    for i, (op, h, l, cl) in enumerate(candles, 1):
        col = '#26a69a' if t(cl) >= t(op) else '#ef5350'
        cx = x(i)
        a(f'<line x1="{cx}" x2="{cx}" y1="{y(h)}" y2="{y(l)}" stroke="{col}" stroke-width="1.6"/>')
        top, bot = sorted((y(op), y(cl)))
        a(f'<rect x="{cx-9}" y="{top}" width="18" height="{max(bot-top,1.5)}" fill="{col}"/>')
        a(f'<text x="{cx}" y="625" fill="#787b86" font-size="12" text-anchor="middle">{i}</text>')
    a('<text x="40" y="625" fill="#555a66" font-size="11" text-anchor="end">#</text>')
    a('<line x1="50" x2="1000" y1="605" y2="605" stroke="#2a2e39"/>')
    def lab(i, p, txt, above, col='#d1d4dc', fs=13, bold=False):
        ab = up(above)
        yy = y(p) - 10 if ab else y(p) + 20
        fw = ' font-weight="700"' if bold else ''
        a(f'<text x="{x(i)}" y="{yy}" fill="{col}" font-size="{fs}" text-anchor="middle"{fw}>{txt.format(**T)}</text>')
    for (i, kind, txt) in sc['swings']:
        lab(i, hi(i) if kind == 'h' else lo(i), txt, kind == 'h')
    at = sc.get('Alabel', 'A – nejvyšší High' if not bull else 'A – nejnižší Low').format(**T)
    a(f'<text x="{x(A)-12}" y="{y(Ap)+5}" fill="#ffffff" font-size="14" font-weight="700" text-anchor="end">{at}</text>')
    lab(B, Bp, 'B', False, '#ffffff', 14, True); lab(Cc, Cp, 'C', True, '#ffffff', 14, True)
    bx = 1040
    a(f'<line x1="{bx}" x2="{bx}" y1="{y(Ap)}" y2="{y(Bp)}" stroke="#d1d4dc" stroke-width="1.2" marker-start="url(#ar)" marker-end="url(#ar)" opacity="0.7"/>')
    my = (y(Ap) + y(Bp)) / 2 + (30 if not bull else -60)
    a(f'<text x="{bx-8}" y="{my}" fill="#d1d4dc" font-size="12" text-anchor="end">impuls A→B</text>')
    a(f'<text x="{bx-8}" y="{my+16}" fill="#9598a1" font-size="11" text-anchor="end">(break struktury)</text>')
    a(f'<line x1="1000" x2="1000" y1="{y(Cp)}" y2="{y(OP)}" stroke="#4caf50" stroke-width="1.2" marker-start="url(#arG)" marker-end="url(#arG)" opacity="0.8"/>')
    a(f'<text x="992" y="{y(OP) + (22 if not bull else -12)}" fill="#4caf50" font-size="12" text-anchor="end">C→OP = A→B</text>')
    ecol = sc.get('ecol', '#ffb300'); etxt = sc.get('etxt', 'VSTUP ({short}) v F5 / F7').format(**T)
    ex = x(Cc); ey = y(F7); s = -1 if not bull else 1; ed = sc.get('edy', 40)
    a(f'<line x1="{ex+60}" x2="{ex+14}" y1="{ey+s*ed}" y2="{ey+s*3}" stroke="#ffb300" stroke-width="2" marker-end="url(#arY)"/>')
    a(f'<text x="{ex+64}" y="{ey+s*(ed+6) + (10 if bull else 0)}" fill="{ecol}" font-size="14" font-weight="700">{etxt}</text>')
    a(f'<circle cx="{ex}" cy="{ey}" r="5" fill="#ffb300"/>')
    a(f'<circle cx="{x(sc["f5"])}" cy="{y(F5)}" r="4" fill="none" stroke="#ffb300" stroke-width="2"/>')
    for m in sc.get('marks', []):
        a(f'<circle cx="{x(m[0])}" cy="{y(m[1])}" r="7" fill="none" stroke="#ef5350" stroke-width="2.5"/>')
    for co in sc.get('callouts', []):
        bx0, by0, bw, bh = co['box']
        if bull: by0 = 680 - by0 - bh
        for (ti, tp) in co['to']:
            a(f'<line x1="{bx0+40}" y1="{by0 + (0 if not bull else bh)}" x2="{x(ti)}" y2="{y(tp)}" stroke="#ef5350" stroke-width="1.2" stroke-dasharray="4 3"/>')
        a(f'<rect x="{bx0}" y="{by0}" width="{bw}" height="{bh}" rx="6" fill="#2a1416" stroke="#ef5350" stroke-width="1.2"/>')
        bold = ' font-weight="700"'
        for k, ln in enumerate(co['lines']):
            a(f'<text x="{bx0+12}" y="{by0+22+k*18}" fill="{"#ef5350" if k==0 else "#d1d4dc"}" font-size="{13 if k==0 else 12}"{bold if k==0 else ""}>{ln.format(**T)}</text>')
    for k, st in enumerate(sc['steps']):
        a(f'<text x="40" y="{666 + k*20}" fill="#9598a1" font-size="13">{st.format(**T)}</text>')
    a('</svg>')
    open(OUT + fname, 'w').write('\n'.join(o))

SW = [(3,'h','{HH}'),(5,'l','{HL}'),(8,'h','{HH}'),(10,'l','{HL}')]
std = dict(A=13, B=18, C=22, f5=21, swings=SW,
    title='', subtitle='{up} ({HH}/{HL}) → break {low} prvního pivotu od {nejv} {high} → retest F5/F7 → {short}, SL {nad} {high}, TP = OP expanze',
    lines=[dict(i0=10, p=40, i1=17, col='#42a5f5', lab='Pivot {low}', sub='(sv. 10 vyčnívá {pod} sv. 9)', bos=True)],
    steps=['1. Trh dělá {vyssi} high a {vyssi} low ({HH}/{HL}).',
           '2. Od {nejv} {high} (A) najdu první pivot {low} – svíčka, jejíž {low} vyčnívá {pod} svíčku nalevo.',
           '3. Čekám na break tohoto {low} (BOS). Impuls A→B = pohyb, který udělal break struktury.',
           '4. Fibo A→B, čekám na návrat do F5 (61.8 %) nebo F7 (78.9 %) → vstup {short}.',
           '5. SL {nad} {high} (A), TP na OP expanze (100 % A→B promítnuto od C).'])
anom = dict(A=13, B=16, C=20, f5=19, edy=14, swings=SW,
    Alabel='A – {HH} + {HL}→LL', subtitle='Svíčka A udělá nové {HH} a zároveň {LL} ({low} {pod} {low} předchozí svíčky) → break jejího {low} = BOS',
    title='', lines=[
      dict(i0=10, p=40, i1=15, col='#42a5f5', lab='Standardní pivot {low}', sub='(zde ještě neprolomen)', w=1.2, dash='3 4'),
      dict(i0=13, p=48, i1=15, col='#ab47bc', lab='{Low} svíčky A', sub='(anomálie)', lx=34, bos=True, bos_above=True)],
    steps=['1. Trh dělá {vyssi} high a {vyssi} low ({HH}/{HL}).',
           '2. ANOMÁLIE: svíčka 13 udělá nové {HH} a zároveň {low} {pod} {low} svíčky 12 (outside bar).',
           '3. Break {low} této svíčky (sv. 15) = break struktury – nečekám na standardní pivot {low} (sv. 10).',
           '4. Fibo A→B, čekám na návrat do F5 (61.8 %) nebo F7 (78.9 %) → vstup {short}.',
           '5. SL {nad} {high} (A), TP na OP expanze (100 % A→B promítnuto od C).'])
anom['Alabel'] = 'A – nové {HH} + {low} {pod} sv. 12'
trap = dict(anom)
trap.update(subtitle='Ukázka, čemu se vyhnout: svíčka v impulsu je outside bar → její {high} je {LH} a návrat do F5/F7 ho prorazí',
    ecol='#ef5350', etxt='VSTUP ✗ – struktura už je proti {short}u',
    marks=[(19, 55)],
    lines=anom['lines'] + [dict(i0=15, p=55, i1=19, col='#ef5350', lab='', w=1.6, dash='5 3')],
    callouts=[dict(box=(600, 420, 400, 100), to=[(15, 55), (19, 55)], lines=[
        'POZOR: sv. 15 = outside bar → {high} sv. 15 je {LH}',
        '{High} nad sv. 14 a zároveň {low} {pod} sv. 14.',
        'Sv. 19 prorazí jeho {high} → BOS PROTI {short}u.',
        'Vstup v F7 (sv. 20) je až po změně struktury.'])],
    steps=['1. Anomálie: sv. 13 nové {HH} + {LL} → break jejího {low} (sv. 15) = BOS.',
           '2. POZOR: sv. 15 je sama outside bar ({high} nad sv. 14, {low} {pod} sv. 14) → její {high} je {LH}.',
           '3. Návrat k F5/F7 prorazí {high} sv. 15 (sv. 19) → struktura se otočí PROTI směru obchodu → NEBRAT.',
           '4. Výjimka: na vyšším TF proběhl také break a během návratu se tam neutvořilo {high}, které mění strukturu proti obchodu.',
           '5. Jinak platí: SL {nad} {high} (A), TP na OP expanze – ale jen pokud setup není zneplatněný.'])
for name, cs, sc, ttl in (('reversal', STD, std, 'Reversal'), ('reversal_anomalie', ANOM, anom, 'Reversal – anomálie'), ('reversal_past', TRAP, trap, 'POZOR – past')):
    for bull in (False, True):
        s = dict(sc); 
        s['title'] = f'{ttl} {"BULLISH (long)" if bull else "BEARISH (short)"} – break struktury + návrat do Fibo F5 / F7'
        build(f'{name}_{"bullish" if bull else "bearish"}.svg', cs, bull, s)
