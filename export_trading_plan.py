#!/usr/bin/env python3
"""PDF obchodního plánu (A4 na výšku) z JSON připraveného serverem (pdf.php).

Kapitoly odpovídají modulu Obchodní plán: 1 cíle a styl, 2 trhy a čas, 3 účty a risk,
4 bias, 5 zóny, 6 strategie, 7 den tradera, 8 psychika, 9 review, 10 závazek.
Úvodní strana shrne plán do čísel. Vzhled je společný v pdf_kit.py.
"""

from __future__ import annotations

import argparse
import json
from pathlib import Path
from xml.sax.saxutils import escape

from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle
from reportlab.lib.utils import ImageReader
from reportlab.platypus import (
    BaseDocTemplate,
    Flowable,
    Frame,
    Image,
    KeepTogether,
    NextPageTemplate,
    PageTemplate,
    Paragraph,
    Spacer,
    Table,
    TableStyle,
)

from pdf_kit import *  # noqa: F401,F403 – paleta, písma a komponenty

PAGE_WIDTH, PAGE_HEIGHT = A4
MARGIN = 16 * mm
BAND = 37 * mm
USABLE = PAGE_WIDTH - 2 * MARGIN
FOOTER_SPACE = 15 * mm
FIRST_TOP = BAND + 8 * mm
LATER_TOP = 18 * mm
GAP = 4 * mm
HALF = (USABLE - GAP) / 2
THIRD = (USABLE - 2 * GAP) / 3

SECTIONS = [
    ("01", "Cíle a styl", "proč a jak obchoduji"),
    ("02", "Trhy a čas", "kdy a co obchoduji"),
    ("03", "Účty a risk", "kolik smím ztratit"),
    ("04", "Jak stavím bias", "směr z price action a profilu"),
    ("05", "Jak stavím zóny", "kde obchoduji"),
    ("06", "Strategie", "co obchoduji a za jakých podmínek"),
    ("07", "Den tradera", "rutina před, během a po seanci"),
    ("08", "Psychika a disciplína", "jak reaguji, když to nejde"),
    ("09", "Review", "jak plán vyhodnocuji a měním"),
    ("10", "Závazek", "podpis plánu"),
]


def czech_date(value: object) -> str:
    text = clean(value)
    try:
        year, month, day = (int(part) for part in text[:10].split("-"))
        return f"{day}. {month}. {year}"
    except ValueError:
        return text or "–"


def option(options: dict, group: str, key: object, fallback: str = "–") -> str:
    return str((options.get(group) or {}).get(str(key or ""), fallback))


def empty_note(text: str = "Tahle část plánu zatím není vyplněná.") -> Paragraph:
    return Paragraph(escape(text), tinted("small", MUTED))


def card(title: str, body: list | Paragraph | None, accent=GOLD, width: float = USABLE, background=WHITE) -> Table:
    content: list = [Tracked(title, accent, 6.3, 1.1), Spacer(1, 1.2 * mm)]
    if isinstance(body, list):
        content.extend(body)
    elif body is not None:
        content.append(body)
    return frame(content, width, background, LINE, 9, 6, accent)


def text_block(value: object, style: str = "small") -> Paragraph | None:
    return Paragraph(ptext(value), STYLES[style]) if filled(value) else None


def bullets(value: object, numbered: bool = False, style: str = "small") -> list:
    items = lines_of(value)
    result = []
    for index, line in enumerate(items, 1):
        mark = f'<font name="{SEMI}" color="#A8792B">{index:02d}</font>&nbsp;&nbsp;' if numbered else '<font color="#A8792B">•</font>&nbsp;&nbsp;'
        result.append(Paragraph(mark + escape(line), STYLES[style]))
        result.append(Spacer(1, 1.2))
    return result


def pills(labels: list[str], foreground=GOLD_DEEP, background=GOLD_SOFT, border=GOLD_LINE) -> Table | None:
    labels = [label for label in labels if label]
    if not labels:
        return None
    widths = [stringwidth(label, BOLD, 7.2) + 16 for label in labels]
    cells = [pill(label, foreground, background, width, border) for label, width in zip(labels, widths)]
    table = Table([cells], colWidths=[width + 4 for width in widths], hAlign="LEFT")
    table.setStyle(TableStyle([("LEFTPADDING", (0, 0), (-1, -1), 0), ("RIGHTPADDING", (0, 0), (-1, -1), 4), ("TOPPADDING", (0, 0), (-1, -1), 0), ("BOTTOMPADDING", (0, 0), (-1, -1), 0)]))
    return table


def pill_rows(labels: list[str], width: float = USABLE, **kwargs) -> list:
    """Štítky zalomené do řádků podle šířky."""
    rows, current, used = [], [], 0.0
    for label in [label for label in labels if label]:
        size = stringwidth(label, BOLD, 7.2) + 20
        if current and used + size > width:
            rows.append(current)
            current, used = [], 0.0
        current.append(label)
        used += size
    if current:
        rows.append(current)
    result: list = []
    for row in rows:
        result.extend([pills(row, **kwargs), Spacer(1, 1.6 * mm)])
    return result


def stringwidth(text: str, font: str, size: float) -> float:
    from reportlab.pdfbase.pdfmetrics import stringWidth
    return stringWidth(text, font, size)


def stat_tiles(items: list[tuple[str, str]], columns: int = 4, width: float = USABLE) -> Table | None:
    items = [(name, value) for name, value in items if clean(value)]
    if not items:
        return None
    column = (width - GAP * (columns - 1)) / columns
    cells = [frame([Paragraph(escape(name.upper()), STYLES["label"]), Spacer(1, .8 * mm), Paragraph(escape(value), STYLES["value_big"])], column, PAPER, None, 8, 6) for name, value in items]
    return grid(cells, width, columns, GAP)


class DayMap(Flowable):
    """Den v pražském čase: obchodní okna, sledování a pauzy na jedné časové ose."""

    COLORS = {"trade": (GREEN, GREEN_SOFT), "watch": (GOLD, GOLD_SOFT), "off": (RED, RED_SOFT)}

    def __init__(self, windows: list[dict], width: float):
        super().__init__()
        self.width = width
        self.windows = []
        for window in windows:
            start, end = self.minutes(window.get("from")), self.minutes(window.get("to"))
            if start is not None and end is not None and end > start:
                self.windows.append((start, end, window))
        starts = [start for start, _, _ in self.windows] or [14 * 60]
        ends = [end for _, end, _ in self.windows] or [22 * 60]
        self.first = max(0, (min(starts) // 60 - 1) * 60)
        self.last = min(24 * 60, (max(ends) // 60 + 2) * 60)

    @staticmethod
    def minutes(value: object) -> int | None:
        try:
            hours, minutes = (int(part) for part in str(value).split(":"))
            return hours * 60 + minutes
        except ValueError:
            return None

    def x(self, minute: float) -> float:
        return 4 + (minute - self.first) / max(1, self.last - self.first) * (self.width - 8)

    def wrap(self, available_width: float, available_height: float):
        self.height = 52
        return self.width, self.height

    def draw(self) -> None:
        canvas = self.canv
        canvas.setFillColor(PAPER)
        canvas.roundRect(0, 0, self.width, self.height, 6, stroke=0, fill=1)
        base = 16
        for start, end, window in self.windows:
            stroke, fill = self.COLORS.get(str(window.get("kind")), (MUTED, PAPER_2))
            left, right = self.x(start), self.x(end)
            canvas.setFillColor(fill)
            canvas.setStrokeColor(stroke)
            canvas.setLineWidth(.8)
            canvas.roundRect(left, base, right - left, 22, 3, stroke=1, fill=1)
            canvas.setFillColor(stroke)
            canvas.setFont(SEMI, 6.6)
            name = clean(window.get("name"))
            while name and canvas.stringWidth(name, SEMI, 6.6) > right - left - 6:
                name = name[:-2] + "…" if len(name) > 2 else ""
            canvas.drawString(left + 3, base + 12.5, name)
            canvas.setFont(SANS, 6)
            canvas.drawString(left + 3, base + 4.5, f"{window.get('from')}–{window.get('to')}")
        canvas.setStrokeColor(LINE)
        canvas.setLineWidth(.6)
        canvas.line(4, base - 2, self.width - 4, base - 2)
        canvas.setFont(SANS, 6.2)
        canvas.setFillColor(MUTED)
        for minute in range(self.first, self.last + 1, 60):
            canvas.line(self.x(minute), base - 2, self.x(minute), base - 4.5)
            canvas.drawCentredString(self.x(minute), 4, f"{minute // 60}:00")


def image_cover(path: object, width: float, height: float):
    try:
        if not filled(path):
            return None
        source_width, source_height = ImageReader(str(path)).getSize()
        scale = min(width / source_width, height / source_height)
        image = Image(str(path), width=source_width * scale, height=source_height * scale)
        image.hAlign = "CENTER"
        return image
    except Exception:
        return None


def initials_block(name: str, width: float, height: float) -> Table:
    letters = "".join(word[0] for word in name.split()[:2]).upper() or "?"
    block = Table([[Paragraph(escape(letters), ParagraphStyle("initials", fontName=SERIF, fontSize=22, leading=24, textColor=GOLD_LIGHT, alignment=TA_CENTER))]], colWidths=[width], rowHeights=[height])
    block.setStyle(TableStyle([("BACKGROUND", (0, 0), (-1, -1), NIGHT), ("VALIGN", (0, 0), (-1, -1), "MIDDLE"), ("ROUNDEDCORNERS", [5] * 4)]))
    return block


def strategy_card(item: dict, info: dict, options: dict, accounts: dict) -> Table:
    """Karta strategie jako tabulka o několika řádcích, aby se dlouhá karta mohla rozdělit."""
    name = clean(info.get("name"), "Strategie")
    style = {"trend": "Trendový", "reversal": "Reversal", "both": "Trendový i reversal"}.get(clean(info.get("style")), "")
    meta = " · ".join(part for part in (clean(info.get("timeframe")), style) if part)
    stats = info.get("stats") or {}
    stat_parts = []
    if stats.get("trades"):
        stat_parts.append(plural(int(stats["trades"]), "obchod", "obchody", "obchodů"))
        stat_parts.append(f"celkem {fmt_number(stats.get('total_r'), 2)} R")
        if stats.get("expectancy_r") is not None:
            stat_parts.append(f"expectancy {fmt_number(stats.get('expectancy_r'), 2)} R")
        if stats.get("profit_factor") is not None:
            stat_parts.append(f"PF {fmt_number(stats.get('profit_factor'), 2)}")
    cover_width, cover_height = 44 * mm, 25 * mm
    cover = image_cover(info.get("cover"), cover_width, cover_height) or initials_block(name, cover_width, cover_height)
    heading = [Paragraph(escape(name), STYLES["h3"])]
    if meta:
        heading.append(Paragraph(escape(meta), STYLES["tiny"]))
    heading.append(Spacer(1, 1.4 * mm))
    heading.append(Paragraph(escape(" · ".join(stat_parts)) if stat_parts else "Zatím bez obchodů v deníku", tinted("small", INK_2 if stat_parts else MUTED)))
    heading.append(Spacer(1, 2 * mm))
    tags = [option(options, "contexts", item.get("context")), option(options, "bias_rules", item.get("bias_rule")), option(options, "zone_priority", item.get("zone_priority"))]
    if item.get("min_rr") is not None:
        tags.append(f"Min. RR {fmt_number(item.get('min_rr'), 2)}")
    if item.get("max_attempts") is not None:
        tags.append(f"Max. {item.get('max_attempts')} pokusy denně" if 2 <= int(item.get("max_attempts")) <= 4 else f"Max. {item.get('max_attempts')} pokusů denně" if int(item.get("max_attempts")) != 1 else "Max. 1 pokus denně")
    heading.extend(pill_rows(tags, USABLE - cover_width - 30))
    inner = USABLE - 3.2
    top = Table([[cover, heading]], colWidths=[cover_width + 6 * mm, inner - cover_width - 6 * mm - 18])
    top.setStyle(TableStyle([("VALIGN", (0, 0), (-1, -1), "TOP"), ("LEFTPADDING", (0, 0), (-1, -1), 0), ("RIGHTPADDING", (0, 0), (-1, -1), 0), ("TOPPADDING", (0, 0), (-1, -1), 0), ("BOTTOMPADDING", (0, 0), (-1, -1), 0)]))
    rows: list[list] = [["", top]]
    commands = []

    blocks = [(title, item.get(key)) for title, key in (
        ("Kdy ji obchoduji", "when"), ("Za jakých podmínek", "conditions"), ("Vstup", "entry"),
        ("Stop loss", "stop"), ("Cíle", "targets"), ("Řízení pozice", "management"),
    ) if filled(item.get(key))]
    column = (inner - 18 - GAP) / 2
    for start in range(0, len(blocks), 2):
        pair = []
        for title, value in blocks[start:start + 2]:
            pair.append([Tracked(title, GOLD, 6.1, 1), Spacer(1, .6 * mm), Paragraph(ptext(value), STYLES["small"])])
        pair += [""] * (2 - len(pair))
        table = Table([pair], colWidths=[column + GAP, column])
        table.setStyle(TableStyle([("VALIGN", (0, 0), (-1, -1), "TOP"), ("LEFTPADDING", (0, 0), (-1, -1), 0), ("RIGHTPADDING", (0, 0), (0, -1), GAP), ("RIGHTPADDING", (1, 0), (1, -1), 0), ("TOPPADDING", (0, 0), (-1, -1), 0), ("BOTTOMPADDING", (0, 0), (-1, -1), 0)]))
        rows.append(["", table])
        commands.append(("LINEABOVE", (1, len(rows) - 1), (1, len(rows) - 1), .5, LINE_SOFT))
    if filled(item.get("skip")):
        rows.append(["", [Tracked("Kdy ji neobchoduji", RED, 6.1, 1), Spacer(1, .6 * mm), Paragraph(ptext(item.get("skip")), STYLES["small"])]])
        commands += [("BACKGROUND", (1, len(rows) - 1), (1, len(rows) - 1), RED_SOFT), ("LINEABOVE", (1, len(rows) - 1), (1, len(rows) - 1), .5, LINE_SOFT)]
    names = [accounts.get(str(account_id), {}).get("name") for account_id in item.get("accounts") or []]
    names = [name for name in names if name]
    rows.append(["", Paragraph(f'<font name="{SEMI}">Účty:</font> {escape(", ".join(names)) if names else "všechny účty v plánu"}', STYLES["tiny"])])
    if filled(info.get("notes")):
        rows.append(["", Paragraph(f'<font name="{SEMI}">Popis strategie:</font> {escape(preview(info.get("notes"), 420))}', STYLES["tiny"])])

    table = Table(rows, colWidths=[3.2, inner])
    table.setStyle(TableStyle(commands + [
        ("BACKGROUND", (0, 0), (0, -1), GOLD),
        ("BOX", (0, 0), (-1, -1), .6, LINE),
        ("VALIGN", (0, 0), (-1, -1), "TOP"),
        ("LEFTPADDING", (0, 0), (0, -1), 0),
        ("RIGHTPADDING", (0, 0), (0, -1), 0),
        ("LEFTPADDING", (1, 0), (1, -1), 9),
        ("RIGHTPADDING", (1, 0), (1, -1), 9),
        ("TOPPADDING", (0, 0), (-1, -1), 6.5),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 7),
        ("ROUNDEDCORNERS", [6] * 4),
    ]))
    return table


def account_card(item: dict, info: dict, options: dict) -> Table:
    currency = clean(info.get("currency"), "USD")
    title = [Paragraph(escape(clean(info.get("name"), "Účet")), STYLES["card_title"])]
    meta = [clean(info.get("broker")), currency, "napojený na cTrader" if info.get("ctrader") else ""]
    title.append(Paragraph(escape(" · ".join(part for part in meta if part)), STYLES["tiny"]))
    role = option(options, "account_roles", item.get("role"))
    header = Table([[title, pill(role.upper(), GOLD_DEEP, GOLD_SOFT, stringwidth(role.upper(), BOLD, 7.2) + 16, GOLD_LINE)]], colWidths=[HALF - 3.2 - 18 - 34 * mm, 34 * mm])
    header.setStyle(TableStyle([("VALIGN", (0, 0), (-1, -1), "TOP"), ("ALIGN", (1, 0), (1, 0), "RIGHT"), ("LEFTPADDING", (0, 0), (-1, -1), 0), ("RIGHTPADDING", (0, 0), (-1, -1), 0), ("TOPPADDING", (0, 0), (-1, -1), 0), ("BOTTOMPADDING", (0, 0), (-1, -1), 0)]))
    drawdown = fmt_money(item.get("max_drawdown"), currency) if item.get("max_drawdown") is not None else ""
    if drawdown:
        drawdown += f" ({option(options, 'drawdown', item.get('drawdown_type')).lower()})"
    figures = [
        ("Risk na obchod", fmt_money(item.get("risk_per_trade"), currency) if item.get("risk_per_trade") is not None else ""),
        ("Denní limit ztráty", fmt_money(item.get("max_daily_loss"), currency) if item.get("max_daily_loss") is not None else ""),
        ("Max. obchodů denně", str(item.get("max_trades_day")) if item.get("max_trades_day") is not None else ""),
        ("Max. drawdown", drawdown),
        ("Cíl zisku", fmt_money(item.get("profit_target"), currency) if item.get("profit_target") is not None else ""),
        ("Vstupní stav", fmt_money(info.get("starting_balance"), currency) if info.get("starting_balance") is not None else ""),
    ]
    figures = [(name, value) for name, value in figures if value]
    cells = [[Paragraph(escape(name.upper()), STYLES["label"]), Paragraph(escape(value), STYLES["small_strong"])] for name, value in figures]
    figure_rows = [cells[i:i + 2] + [""] * (2 - len(cells[i:i + 2])) for i in range(0, len(cells), 2)]
    content: list = [header, Spacer(1, 2.4 * mm)]
    if figure_rows:
        column = (HALF - 3.2 - 18) / 2
        table = Table(figure_rows, colWidths=[column, column])
        table.setStyle(TableStyle([("VALIGN", (0, 0), (-1, -1), "TOP"), ("LEFTPADDING", (0, 0), (-1, -1), 0), ("RIGHTPADDING", (0, 0), (-1, -1), 4), ("TOPPADDING", (0, 0), (-1, -1), 1.6), ("BOTTOMPADDING", (0, 0), (-1, -1), 2.6)]))
        content.append(table)
    if filled(item.get("rules")):
        content.extend([Spacer(1, 1.6 * mm), Paragraph(ptext(item.get("rules")), STYLES["tiny"])])
    return frame(content, HALF, WHITE, LINE, 9, 6, GOLD)


def build_trading_plan(payload: dict, output_path: str) -> None:
    plan = payload.get("plan") or {}
    data = plan.get("data") or {}
    options = payload.get("options") or {}
    accounts = {str(key): value for key, value in (payload.get("accounts") or {}).items()}
    strategies = {str(key): value for key, value in (payload.get("strategies") or {}).items()}
    trader = clean(payload.get("trader"))
    title = clean(plan.get("title"), "Obchodní plán")
    version = plan.get("version") or 1
    status = option(options, "statuses", plan.get("status"), "Rozpracovaný")
    subtitle_parts = [f"Verze {version}"]
    if plan.get("valid_from"):
        subtitle_parts.append(f"platný od {czech_date(plan.get('valid_from'))}")
    if trader:
        subtitle_parts.append(trader)
    risk = data.get("risk") or {}

    def decorate(canvas, page: int, total: int) -> None:
        if page == 1:
            cover_band(canvas, PAGE_WIDTH, PAGE_HEIGHT, BAND, "Obchodní plán", title, " · ".join(subtitle_parts),
                       [("Stav", status), ("Styl", option(options, "styles", data.get("style"))), ("Review", czech_date((data.get("review") or {}).get("next_on")) if (data.get("review") or {}).get("next_on") else "")],
                       MARGIN)
        else:
            running_header(canvas, PAGE_WIDTH, PAGE_HEIGHT, MARGIN, f"{title} · verze {version}", trader)
        running_footer(canvas, PAGE_WIDTH, MARGIN, "Obchodní plán: pravidla, podle kterých obchoduji. Mění se jen novou verzí.", page, total)

    doc = BaseDocTemplate(output_path, pagesize=A4, leftMargin=MARGIN, rightMargin=MARGIN, topMargin=LATER_TOP, bottomMargin=FOOTER_SPACE,
                          title=f"{title} (verze {version})", author=trader or "Trading Desk", subject="Obchodní plán")
    padding = {"leftPadding": 0, "rightPadding": 0, "topPadding": 0, "bottomPadding": 0}
    doc.addPageTemplates([
        PageTemplate("first", [Frame(MARGIN, FOOTER_SPACE, USABLE, PAGE_HEIGHT - FIRST_TOP - FOOTER_SPACE, id="first", **padding)]),
        PageTemplate("later", [Frame(MARGIN, FOOTER_SPACE, USABLE, PAGE_HEIGHT - LATER_TOP - FOOTER_SPACE, id="later", **padding)]),
    ])
    story: list = [NextPageTemplate("later")]

    # ---------------------------------------------------------- úvod: plán v kostce
    if filled(data.get("mission")):
        story.append(frame([Tracked("Proč a jak obchoduji", GOLD, 6.6, 1.2), Spacer(1, 1.2 * mm), Paragraph(ptext(data.get("mission")), STYLES["lead"])], USABLE, WHITE, LINE, 12, 6, GOLD))
        story.append(Spacer(1, 5 * mm))
    plan_accounts = data.get("accounts") or []
    risks = sorted({float(item["risk_per_trade"]) for item in plan_accounts if item.get("risk_per_trade") is not None})
    currency = clean((accounts.get(str(plan_accounts[0]["account_id"])) or {}).get("currency"), "USD") if plan_accounts else "USD"
    trade_windows = [window for window in data.get("windows") or [] if window.get("kind") == "trade"]
    story.extend([Tracked("Plán v kostce", GOLD, 6.6, 1.2), Spacer(1, 2 * mm)])
    tiles = stat_tiles([
        ("Risk na obchod", (fmt_money(risks[0], currency) if len(risks) == 1 else f"{fmt_money(risks[0], currency)}–{fmt_money(risks[-1], currency)}") if risks else "podle účtu"),
        ("Denní stop", f"−{fmt_number(risk.get('daily_stop_r'), 2)} R" if risk.get("daily_stop_r") is not None else "–"),
        ("Týdenní stop", f"−{fmt_number(risk.get('weekly_stop_r'), 2)} R" if risk.get("weekly_stop_r") is not None else "–"),
        ("Obchodů denně", f"max. {risk.get('max_trades_day')}" if risk.get("max_trades_day") is not None else "–"),
        ("Ztrát v řadě", f"max. {risk.get('max_losses_row')}" if risk.get("max_losses_row") is not None else "–"),
        ("Trhy", ", ".join(data.get("markets") or []) or "–"),
        ("Obchodní okno", " · ".join(f"{window.get('from')}–{window.get('to')}" for window in trade_windows[:2]) or "–"),
        ("Strategie", str(len(data.get("strategies") or []))),
    ])
    if tiles:
        story.append(tiles)
    story.extend([Spacer(1, 3 * mm), Tracked("Obsah", GOLD, 6.6, 1.2), Spacer(1, 1.6 * mm)])
    toc = [Paragraph(f'<font name="{SERIF}" color="#A8792B" size="11">{number}</font>&nbsp;&nbsp;<font name="{SEMI}">{escape(name)}</font>&nbsp;&nbsp;<font color="#7C7364">{escape(hint)}</font>', STYLES["small"]) for number, name, hint in SECTIONS]
    toc_table = Table([[toc[i], toc[i + 5]] for i in range(5)], colWidths=[HALF + GAP, HALF])
    toc_table.setStyle(TableStyle([("LEFTPADDING", (0, 0), (-1, -1), 0), ("RIGHTPADDING", (0, 0), (-1, -1), 0), ("TOPPADDING", (0, 0), (-1, -1), 2.4), ("BOTTOMPADDING", (0, 0), (-1, -1), 2.4), ("LINEBELOW", (0, 0), (-1, -2), .4, LINE_SOFT)]))
    story.append(toc_table)

    def chapter(index: int) -> None:
        number, name, hint = SECTIONS[index]
        story.extend(section(name, hint, number))

    # ---------------------------------------------------------- 1 cíle a styl
    chapter(0)
    style_facts = facts([("Styl obchodování", option(options, "styles", data.get("style"))), ("Čas na trading", preview(data.get("time_budget"), 140))], USABLE, 2, PAPER)
    if style_facts:
        story.extend([style_facts, Spacer(1, GAP)])
    goal_cards = []
    if filled(data.get("goals_process")):
        goal_cards.append(card("Procesní cíle · co ovlivním", checklist(lines_of(data.get("goals_process")), HALF - 24), GREEN, HALF))
    if filled(data.get("goals_outcome")):
        goal_cards.append(card("Výsledkové cíle · kam mířím", checklist(lines_of(data.get("goals_outcome")), HALF - 24), GOLD, HALF))
    if goal_cards:
        story.append(grid(goal_cards, USABLE, 2, GAP))
    elif not style_facts:
        story.append(empty_note())

    # ---------------------------------------------------------- 2 trhy a čas
    chapter(1)
    markets = data.get("markets") or []
    if markets:
        story.extend([Tracked("Obchodované trhy", MUTED, 6.2, 1.1), Spacer(1, 1.4 * mm)])
        story.extend(pill_rows(markets, foreground=WHITE, background=NIGHT, border=None))
    if filled(data.get("markets_note")):
        story.extend([Paragraph(ptext(data.get("markets_note")), STYLES["small"]), Spacer(1, 2 * mm)])
    windows = data.get("windows") or []
    if windows:
        story.extend([Spacer(1, 2 * mm), Tracked("Den v pražském čase", MUTED, 6.2, 1.1), Spacer(1, 1.4 * mm), DayMap(windows, USABLE), Spacer(1, 1.4 * mm),
                      Paragraph('<font color="#0D8A61" size="11">•</font> obchoduji&nbsp;&nbsp;&nbsp;<font color="#A8792B" size="11">•</font> jen sleduji&nbsp;&nbsp;&nbsp;<font color="#C13A4C" size="11">•</font> neobchoduji · v týdnech, kdy USA a Evropa mění čas v jiný den, je vše o hodinu dřív', STYLES["tiny"]),
                      Spacer(1, 3 * mm)])
        days = options.get("days") or {}
        rows, accents = [], []
        for window in windows:
            kind = str(window.get("kind"))
            rows.append([
                Paragraph(escape(clean(window.get("name"), "Okno")), STYLES["small_strong"]),
                Paragraph(escape(f"{window.get('from') or '–'} – {window.get('to') or '–'}"), STYLES["small"]),
                Paragraph(escape(" ".join(days.get(str(day), "") for day in window.get("days") or []) or "–"), STYLES["small"]),
                Paragraph(escape(option(options, "window_kinds", kind)), STYLES["small"]),
                Paragraph(ptext(window.get("note")), STYLES["small"]),
            ])
            accents.append({"trade": GREEN, "watch": GOLD, "off": RED}.get(kind, MUTED))
        story.append(data_table(["Okno", "Čas", "Dny", "Režim", "Poznámka"], rows, [USABLE * .24, USABLE * .15, USABLE * .17, USABLE * .14, USABLE * .30], accents))
    time_cards = [card(title, text_block(data.get(key)), accent, HALF) for title, key, accent in (("Red news", "news_rule", AMBER), ("Dny bez obchodování", "no_trade_days", RED)) if filled(data.get(key))]
    if time_cards:
        story.extend([Spacer(1, GAP), grid(time_cards, USABLE, 2, GAP)])
    if not (markets or windows or time_cards):
        story.append(empty_note())

    # ---------------------------------------------------------- 3 účty a risk
    chapter(2)
    risk_tiles = stat_tiles([
        ("Denní stop", f"−{fmt_number(risk.get('daily_stop_r'), 2)} R" if risk.get("daily_stop_r") is not None else ""),
        ("Týdenní stop", f"−{fmt_number(risk.get('weekly_stop_r'), 2)} R" if risk.get("weekly_stop_r") is not None else ""),
        ("Obchodů denně", f"max. {risk.get('max_trades_day')}" if risk.get("max_trades_day") is not None else ""),
        ("Ztrát v řadě", f"max. {risk.get('max_losses_row')}" if risk.get("max_losses_row") is not None else ""),
    ])
    if risk_tiles:
        story.extend([risk_tiles, Spacer(1, 1 * mm)])
    account_cards = [account_card(item, accounts.get(str(item.get("account_id")), {}), options) for item in plan_accounts if str(item.get("account_id")) in accounts]
    if account_cards:
        story.extend([Tracked(f"Účty v plánu · {len(account_cards)}", MUTED, 6.2, 1.1), Spacer(1, 1.6 * mm), grid(account_cards, USABLE, 2, GAP)])
    if filled(risk.get("sizing")):
        story.extend([frame([Tracked("Velikost pozice", GOLD_DEEP, 6.3, 1.1), Spacer(1, 1 * mm), Paragraph(ptext(risk.get("sizing")), STYLES["body_strong"])], USABLE, GOLD_SOFT, GOLD_LINE, 10, 6), Spacer(1, GAP)])
    scale_cards = [card(title, text_block(risk.get(key)), accent, HALF) for title, key, accent in (("Když se nedaří · snížení risku", "scale_down", RED), ("Když se daří · zvýšení risku", "scale_up", GREEN)) if filled(risk.get(key))]
    if scale_cards:
        story.append(grid(scale_cards, USABLE, 2, GAP))
    if not (risk_tiles or account_cards or scale_cards or filled(risk.get("sizing"))):
        story.append(empty_note())

    # ---------------------------------------------------------- 4 bias
    chapter(3)
    bias = data.get("bias") or {}
    timeframes = [option(options, "timeframes", key) for key in bias.get("timeframes") or []]
    if timeframes:
        story.extend([Tracked("Timeframy, ze kterých bias skládám", MUTED, 6.2, 1.1), Spacer(1, 1.4 * mm)])
        story.extend(pill_rows(timeframes))
    if filled(bias.get("process")):
        story.extend([card("Postup shora dolů", bullets(bias.get("process"), numbered=True), GOLD), Spacer(1, GAP)])
    direction_cards = [card(title, text_block(bias.get(key)), accent, THIRD, soft) for title, key, accent, soft in (
        ("Long, když", "long_when", GREEN, GREEN_SOFT), ("Short, když", "short_when", RED, RED_SOFT), ("Balance, když", "balance_when", AMBER, AMBER_SOFT)) if filled(bias.get(key))]
    if direction_cards:
        story.append(grid(direction_cards, USABLE, 3, GAP))
    if filled(bias.get("invalidation")):
        story.append(card("Kdy bias ruším", text_block(bias.get("invalidation")), INK))
    if not (timeframes or direction_cards or filled(bias.get("process")) or filled(bias.get("invalidation"))):
        story.append(empty_note())

    # ---------------------------------------------------------- 5 zóny
    chapter(4)
    zones = data.get("zones") or {}
    sources = [option(options, "zone_sources", key) for key in zones.get("sources") or []]
    if sources:
        story.extend([Tracked("Z čeho zóny stavím", MUTED, 6.2, 1.1), Spacer(1, 1.4 * mm)])
        story.extend(pill_rows(sources))
    if filled(zones.get("rules")):
        story.extend([card("Jak zónu kreslím", bullets(zones.get("rules")), GOLD), Spacer(1, GAP)])
    ladder = []
    for letter, key, accent, background in (("A", "priority_a", GOLD_DEEP, GOLD_SOFT), ("B", "priority_b", INK_2, PAPER), ("C", "priority_c", MUTED, WHITE)):
        if filled(zones.get(key)):
            ladder.append([Paragraph(letter, ParagraphStyle(f"letter{letter}", fontName=SERIF, fontSize=20, leading=22, textColor=accent, alignment=TA_CENTER)), Paragraph(ptext(zones.get(key)), STYLES["small"])])
    if ladder:
        table = Table(ladder, colWidths=[16 * mm, USABLE - 16 * mm])
        commands = [("VALIGN", (0, 0), (-1, -1), "MIDDLE"), ("BOX", (0, 0), (-1, -1), .6, LINE), ("LINEBELOW", (0, 0), (-1, -2), .5, LINE_SOFT),
                    ("LEFTPADDING", (0, 0), (-1, -1), 8), ("RIGHTPADDING", (0, 0), (-1, -1), 9), ("TOPPADDING", (0, 0), (-1, -1), 6), ("BOTTOMPADDING", (0, 0), (-1, -1), 7), ("ROUNDEDCORNERS", [6] * 4)]
        for row, (_, _, _, background) in enumerate([item for item in (("A", "priority_a", GOLD_DEEP, GOLD_SOFT), ("B", "priority_b", INK_2, PAPER), ("C", "priority_c", MUTED, WHITE)) if filled(zones.get(item[1]))]):
            commands.append(("BACKGROUND", (0, row), (-1, row), background))
        table.setStyle(TableStyle(commands))
        story.extend([KeepTogether([Tracked("Priorita zóny", MUTED, 6.2, 1.1), Spacer(1, 1.4 * mm), table]), Spacer(1, GAP)])
    zone_facts = facts([("Max. šířka zóny", f"{fmt_number(zones.get('max_width'))} b" if zones.get("max_width") is not None else ""), ("Platnost", preview(zones.get("validity"), 160))], USABLE, 2, PAPER)
    if zone_facts:
        story.extend([zone_facts, Spacer(1, GAP)])
    if filled(zones.get("invalidation")):
        story.append(card("Kdy zóna padá", text_block(zones.get("invalidation")), RED))
    if not (sources or ladder or zone_facts or filled(zones.get("rules")) or filled(zones.get("invalidation"))):
        story.append(empty_note())

    # ---------------------------------------------------------- 6 strategie
    chapter(5)
    plan_strategies = [item for item in data.get("strategies") or [] if str(item.get("strategy_id")) in strategies]
    if plan_strategies:
        for item in plan_strategies:
            # Karta, která se vejde na stranu, se nerozdělí; delší se rozdělí po řádcích.
            story.extend([KeepTogether([strategy_card(item, strategies[str(item["strategy_id"])], options, accounts)]), Spacer(1, GAP)])
    else:
        story.append(empty_note("V plánu zatím není žádná strategie. Přidej je v modulu Obchodní plán."))

    # ---------------------------------------------------------- 7 den tradera
    chapter(6)
    routine = data.get("routine") or {}
    routine_cards = [card(title, checklist(lines_of(routine.get(key)), THIRD - 24), accent, THIRD) for title, key, accent in (
        ("Před seancí", "before", GOLD), ("Během seance", "during", GREEN), ("Po seanci", "after", BLUE)) if filled(routine.get(key))]
    story.append(grid(routine_cards, USABLE, 3, GAP) if routine_cards else empty_note())

    # ---------------------------------------------------------- 8 psychika
    chapter(7)
    psychology = data.get("psychology") or {}
    if filled(psychology.get("bad_day")):
        story.extend([card("Špatný den", bullets(psychology.get("bad_day")), AMBER), Spacer(1, GAP)])
    triggers = lines_of(psychology.get("triggers"))
    if triggers:
        pairs = []
        for line in triggers:
            cause, _, action = line.replace("->", "→").partition("→")
            pairs.append([Paragraph(escape(cause.strip()), STYLES["small_strong"]), Paragraph(escape(action.strip() or "–"), STYLES["small"])])
        story.extend([data_table(["Spouštěč", "Co udělám"], pairs, [USABLE * .42, USABLE * .58], [AMBER] * len(pairs)), Spacer(1, GAP)])
    if filled(psychology.get("stop_rules")):
        story.append(card("Kdy končím den", bullets(psychology.get("stop_rules")), RED, USABLE, RED_SOFT))
    if not (triggers or filled(psychology.get("bad_day")) or filled(psychology.get("stop_rules"))):
        story.append(empty_note())

    # ---------------------------------------------------------- 9 review
    chapter(8)
    review = data.get("review") or {}
    review_cards = [card(title, text_block(review.get(key)), accent, THIRD) for title, key, accent in (
        ("Denně", "daily", GOLD), ("Týdně", "weekly", GOLD_DEEP), ("Měsíčně", "monthly", INK)) if filled(review.get(key))]
    if review_cards:
        story.append(grid(review_cards, USABLE, 3, GAP))
    metrics = checklist(lines_of(review.get("metrics")), HALF - 24)
    side = []
    if metrics:
        side.append(card("Co sleduji", metrics, GREEN, HALF))
    if filled(review.get("change_rules")):
        side.append(card("Kdy smím plán změnit", text_block(review.get("change_rules")), RED, HALF))
    if side:
        story.append(grid(side, USABLE, 2, GAP))
    if review.get("next_on"):
        story.append(Paragraph(f'<font name="{SEMI}">Příští review plánu:</font> {escape(czech_date(review.get("next_on")))}', STYLES["small"]))
    if not (review_cards or side or review.get("next_on")):
        story.append(empty_note())

    # ---------------------------------------------------------- 10 závazek
    chapter(9)
    commitment = data.get("commitment") or {}
    block: list = []
    if filled(commitment.get("statement")):
        block.extend([Paragraph(ptext(commitment.get("statement")), STYLES["lead"]), Spacer(1, 10 * mm)])
    signature = Table([[
        [Paragraph("&nbsp;", STYLES["body"]), Paragraph(escape(clean(commitment.get("signature"), trader) or " "), STYLES["small_strong"]), Paragraph("PODPIS", STYLES["label"])],
        [Paragraph("&nbsp;", STYLES["body"]), Paragraph(escape(czech_date(commitment.get("signed_on")) if commitment.get("signed_on") else " "), STYLES["small_strong"]), Paragraph("DATUM", STYLES["label"])],
    ]], colWidths=[USABLE * .62, USABLE * .38])
    signature.setStyle(TableStyle([("LINEABOVE", (0, 0), (0, 0), .8, INK), ("LINEABOVE", (1, 0), (1, 0), .8, INK), ("LEFTPADDING", (0, 0), (-1, -1), 0), ("RIGHTPADDING", (0, 0), (0, 0), 14 * mm), ("TOPPADDING", (0, 0), (-1, -1), 2)]))
    block.append(signature)
    story.append(KeepTogether(block))

    doc.build(story, canvasmaker=lambda *args, **kwargs: DocumentCanvas(*args, decorate=decorate, **kwargs))


def main() -> None:
    parser = argparse.ArgumentParser(description="Vygeneruje PDF obchodního plánu.")
    parser.add_argument("--input", required=True, help="Cesta ke vstupnímu JSON")
    parser.add_argument("--output", required=True, help="Cesta k výslednému PDF")
    args = parser.parse_args()
    with open(args.input, "r", encoding="utf-8") as source:
        payload = json.load(source)
    Path(args.output).parent.mkdir(parents=True, exist_ok=True)
    build_trading_plan(payload, args.output)


if __name__ == "__main__":
    main()
