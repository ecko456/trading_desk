#!/usr/bin/env python3
"""PDF denního nebo týdenního náhledu trhu z JSON připraveného serverem (pdf.php).

Pořadí stránky je dané tím, co trader potřebuje vidět jako první: bias z price action
a z profilu, krátký popis trhu, mapa ceny a potom zóny s tím, co se na nich obchoduje
a za jakých podmínek. Kontext a detaily jdou až za tím. Prázdné položky se nevypisují.
Vzhled (písma, barvy, karty, záhlaví) je společný v pdf_kit.py.
"""

from __future__ import annotations

import argparse
import json
from pathlib import Path
from xml.sax.saxutils import escape

from reportlab.lib.pagesizes import A4, landscape
from reportlab.lib.styles import ParagraphStyle
from reportlab.lib.utils import ImageReader
from reportlab.platypus import (
    BaseDocTemplate,
    Flowable,
    Frame,
    Image,
    NextPageTemplate,
    PageBreak,
    PageTemplate,
    Paragraph,
    Spacer,
    Table,
    TableStyle,
)

from pdf_kit import *  # noqa: F401,F403 – paleta, písma a komponenty

PAGE_WIDTH, PAGE_HEIGHT = landscape(A4)


def label(mapping: dict[str, str], value: object, fallback: str = "") -> str:
    return mapping.get(str(value or ""), fallback)


VALUE_AREA = {"rising": "Výš (vyšší value)", "falling": "Níž (nižší value)", "overlap": "Překrývá se"}
VPOC = {"rising": "Roste", "falling": "Klesá", "stable": "Stabilní"}
AUCTION = {"balance": "Balance", "imbalance": "Imbalance", "unclear": "Nejasná"}
WEEKLY = {"inside": "Uvnitř VA", "above": "Nad VAH", "below": "Pod VAL"}
POSITION = {"inside": "Uvnitř VA", "above": "Nad VA", "below": "Pod VA", "outside": "Mimo range"}
CLOSE = {"above": "Nad VAH", "upper": "Horní polovina value", "poc": "Na POC", "lower": "Spodní polovina value", "below": "Pod VAL", "inside": "Uvnitř VA"}
SHAPE = {"p": "P · přijetí nahoře", "b": "b · přijetí dole", "d": "D · vyvážená aukce", "double": "B · dvojitá distribuce", "trend": "Trend · protažený profil"}
OPEN_TYPE = {"drive": "Open Drive", "test_drive": "Open Test Drive", "rejection_reverse": "Open Rejection Reverse", "auction_in": "Open Auction uvnitř range", "auction_out": "Open Auction mimo range"}
IB = {"small": "Malá", "normal": "Běžná", "large_drive": "Velká - drive", "large_rotation": "Velká - rotace"}
SIGN = {"buy": "Nákupní", "sell": "Prodejní"}
TAIL = {"buy": "Kupní (dole)", "sell": "Prodejní (nahoře)"}
STATUS = {"draft": "Rozpracovaný", "ready": "Připravený", "completed": "Dokončený", "archived": "Archivovaný"}
IDEA_STATUS = {"waiting": "Čeká", "active": "Aktivní", "invalid": "Neplatný", "executed": "Realizovaný"}
ZONE_STATUS = {"planned": "Čeká", "active": "Aktivní", "hit": "Zasažena", "invalid": "Neplatná"}
REF_KIND = {"single_print": "Single prints", "poor_high": "Poor high", "poor_low": "Poor low", "naked_poc": "Naked POC", "gap": "Gap", "excess": "Excess / tail", "lvn": "LVN", "other": "Jiná reference"}
LEVEL_KIND = {"support": "Support", "resistance": "Rezistence", "pivot": "Pivot"}
BIAS_WORD = {"long": "LONG", "short": "SHORT", "balance": "BALANCE", "neutral": "BALANCE"}
DIRECTION_WORD = {"long": "LONG", "short": "SHORT", "both": "LONG I SHORT"}


def trade_type(value: object) -> str:
    raw = str(value or "").strip()
    if raw in {"hybrid_intraday", "SWING"}:
        return "Hybrid Intraday"
    if raw == "" or raw.lower() == "intraday":
        return "Intraday"
    return raw


def is_weekly(plan: dict) -> bool:
    return str(plan.get("plan_type") or "daily") == "weekly"


def alignment_text(plan: dict) -> str:
    keys = ["pa_monthly", "pa_weekly", "mp_weekly"] if is_weekly(plan) else ["pa_monthly", "pa_weekly", "pa_daily", "mp_weekly", "mp_daily"]
    values = [str(plan.get(key) or "") for key in keys if plan.get(key)]
    if len(values) < 2:
        return ""
    if all(value == values[0] for value in values):
        return f"Souhra: vše {values[0]}"
    counts = {name: values.count(name) for name in ("long", "short", "balance")}
    return f"Smíšené: {counts['long']} long, {counts['short']} short, {counts['balance']} balance"


SHAPE_NOTES = {
    "p": "P profil ukazuje přijetí vyšších cen, často jako short covering; bez navazujících kupců hrozí návrat do spodní části.",
    "b": "b profil ukazuje přijetí nižších cen, často jako likvidaci longů; bez nových prodejců se trh často vrací nahoru.",
    "d": "D profil je vyvážená aukce; hrany value jsou pro obchod důležitější než její střed.",
    "double": "Dvojitá distribuce: klíčovou referencí je oblast mezi oběma distribucemi.",
    "trend": "Protažený trendový profil ukazuje iniciativu jedné strany; proti ní neobchoduj bez zřetelného selhání.",
}
CLOSE_NOTES = {
    "above": "Close nad VAH znamená přijetí cen nad value.",
    "upper": "Close v horní polovině value drží kupce ve výhodě.",
    "poc": "Close na POC je rovnováha, směr určí až otevření.",
    "lower": "Close ve spodní polovině value drží prodejce ve výhodě.",
    "below": "Close pod VAL znamená přijetí cen pod value.",
}


def working_conclusion(plan: dict, refs: list[dict]) -> str:
    """Stejná logika jako buildConclusion v app.js."""
    parts: list[str] = []
    keys = ["pa_monthly", "pa_weekly", "pa_daily", "mp_weekly", "mp_daily"]
    values = [str(plan.get(key)) for key in keys if plan.get(key)]
    if len(values) >= 2:
        if all(value == "long" for value in values):
            parts.append("Price action i profil ukazují na všech vyplněných timeframech long; obchody proti tomu potřebují mimořádný důvod.")
        elif all(value == "short" for value in values):
            parts.append("Price action i profil ukazují na všech vyplněných timeframech short; obchody proti tomu potřebují mimořádný důvod.")
        elif "long" in values and "short" in values:
            parts.append("Timeframy si odporují; menší risk a obchody ve směru vyššího timeframu.")
        else:
            parts.append("Část timeframů je v balance; výhodu mají obchody od hran range, ne uprostřed.")

    va, vpoc = plan.get("value_area"), plan.get("vpoc")
    if va == "rising" and vpoc == "rising":
        parts.append("Value i POC migrují výš, preferovaný je long scénář.")
    elif va == "falling" and vpoc == "falling":
        parts.append("Value i POC migrují níž, preferovaný je short scénář.")
    elif va or vpoc:
        parts.append("Migrace value a POC není v souladu; směrová výhoda je slabší.")

    if plan.get("profile_shape") in SHAPE_NOTES:
        parts.append(SHAPE_NOTES[plan["profile_shape"]])
    if plan.get("previous_close") in CLOSE_NOTES:
        parts.append(CLOSE_NOTES[plan["previous_close"]])
    if plan.get("auction") == "imbalance":
        parts.append("Aukce má iniciativní charakter; protisměr vyžaduje zřetelné selhání iniciativy.")
    elif plan.get("auction") == "balance":
        parts.append("Aukce rotuje; větší význam mají hrany balance než její střed.")

    open_refs = [ref for ref in refs if ref.get("status") != "filled"]
    if open_refs:
        grouped: dict[str, int] = {}
        for ref in open_refs:
            name = REF_KIND.get(str(ref.get("kind") or ""), "Reference")
            grouped[name] = grouped.get(name, 0) + 1
        parts.append("Na dojetí zůstává: " + ", ".join(f"{count}× {name}" if count > 1 else name for name, count in grouped.items()) + ".")
    return " ".join(parts)


# ---------------------------------------------------------------- vzhled

MARGIN = 14 * mm
BAND = 37 * mm
USABLE = PAGE_WIDTH - 2 * MARGIN
FOOTER_SPACE = 15 * mm
FIRST_TOP = BAND + 7 * mm
LATER_TOP = 18 * mm


def bias_tile(value: object, note: object, width: float) -> Table:
    accent, soft, word = direction_colors(value)
    content = [Paragraph(escape(word), ParagraphStyle("bias_word", parent=STYLES["value_big"], textColor=accent if value else MUTED, alignment=TA_CENTER))]
    if filled(note):
        content.append(Paragraph(escape(preview(note, 70)), STYLES["center"]))
    return frame(content, width, background=soft if value else PAPER, border=None, padding=7, radius=6, valign="MIDDLE")


def bias_board(plan: dict) -> Table:
    """Nahoře: bias z price action a z profilu pro dva timeframy, vpravo pracovní bias."""
    weekly = is_weekly(plan)
    columns = [("MONTHLY", "monthly"), ("WEEKLY", "weekly")] if weekly else [("WEEKLY", "weekly"), ("DAILY", "daily")]
    final_width = 74 * mm
    gap = 4 * mm
    label_width = 27 * mm
    cell = (USABLE - final_width - gap - label_width) / 2

    rows = [[""] + [Tracked(title, MUTED, 6.4, 1.2) for title, _ in columns]]
    for row_title, prefix in (("PRICE ACTION", "pa"), ("MP / VP", "mp")):
        cells = [Paragraph(row_title, STYLES["small_strong"])]
        for _, timeframe in columns:
            key = f"{prefix}_{timeframe}"
            cells.append(bias_tile(plan.get(key), plan.get(f"{key}_note"), cell - 4))
        rows.append(cells)
    matrix = Table(rows, colWidths=[label_width, cell, cell])
    matrix.setStyle(TableStyle([
        ("VALIGN", (0, 0), (-1, -1), "MIDDLE"),
        ("LEFTPADDING", (0, 0), (-1, -1), 0),
        ("RIGHTPADDING", (0, 0), (-1, -1), 4),
        ("TOPPADDING", (0, 0), (-1, -1), 2),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 2),
    ]))

    bias = str(plan.get("bias") or "neutral")
    night = {"long": NIGHT_GREEN, "short": NIGHT_RED}.get(bias, NIGHT_AMBER)
    final = [
        Tracked("Pracovní bias", GOLD_LIGHT, 6.6, 1.4),
        Spacer(1, 1.2 * mm),
        Paragraph(escape(BIAS_WORD.get(bias, "BALANCE")), ParagraphStyle("final_word", fontName=SERIF, fontSize=28, leading=31, textColor=night)),
    ]
    notes = [alignment_text(plan)]
    if filled(plan.get("pa_monthly")) and not weekly:
        notes.append(f"Monthly PA: {BIAS_WORD.get(str(plan.get('pa_monthly')), '—')}")
    notes = [note for note in notes if note]
    if notes:
        final.append(Paragraph(escape(" · ".join(notes)), ParagraphStyle("final_note", fontName=SANS, fontSize=7.6, leading=10, textColor=IVORY_MUTED)))
    final_box = frame(final, final_width, background=NIGHT, border=None, padding=11, radius=7, valign="MIDDLE")

    board = Table([[matrix, final_box]], colWidths=[USABLE - final_width, final_width])
    board.setStyle(TableStyle([
        ("VALIGN", (0, 0), (-1, -1), "MIDDLE"),
        ("LEFTPADDING", (0, 0), (-1, -1), 0),
        ("RIGHTPADDING", (0, 0), (-1, -1), 0),
        ("TOPPADDING", (0, 0), (-1, -1), 0),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 0),
    ]))
    return board


class PriceMap(Flowable):
    """Mapa ceny: value minulého týdne, POC, zóny a reference na jedné vodorovné ose."""

    def __init__(self, zones: list[dict], context: dict, refs: list[dict], width: float):
        super().__init__()
        self.zones = [zone for zone in zones if self._number(zone.get("price_low")) is not None or self._number(zone.get("price_high")) is not None]
        self.context = context
        self.refs = [ref for ref in refs if self._number(ref.get("price_low")) is not None and ref.get("status") != "filled"]
        self.width = width
        self.lanes: list[int] = []
        prices: list[float] = []
        for zone in self.zones:
            prices += [value for value in (self._number(zone.get("price_low")), self._number(zone.get("price_high"))) if value is not None]
        for key in ("vah", "val", "poc"):
            if self._number(context.get(key)) is not None:
                prices.append(self._number(context.get(key)))
        for ref in self.refs:
            prices += [value for value in (self._number(ref.get("price_low")), self._number(ref.get("price_high"))) if value is not None]
        self.low, self.high = (min(prices), max(prices)) if prices else (0.0, 1.0)
        pad = max((self.high - self.low) * .08, 1.0)
        self.low, self.high = self.low - pad, self.high + pad
        # Překrývající se zóny jdou do dalších pruhů nad sebou.
        ends: list[float] = []
        for zone in self.zones:
            start, end = self._span(zone)
            for lane, last in enumerate(ends):
                if start > last + (self.high - self.low) * .015:
                    ends[lane] = end
                    self.lanes.append(lane)
                    break
            else:
                ends.append(end)
                self.lanes.append(len(ends) - 1)
        self.lane_count = max(1, len(ends))

    @staticmethod
    def _number(value: object) -> float | None:
        try:
            return None if value in (None, "") else float(value)
        except (TypeError, ValueError):
            return None

    def _span(self, zone: dict) -> tuple[float, float]:
        low, high = self._number(zone.get("price_low")), self._number(zone.get("price_high"))
        low = low if low is not None else high
        high = high if high is not None else low
        return min(low, high), max(low, high)

    def x(self, price: float) -> float:
        return 6 + (price - self.low) / (self.high - self.low) * (self.width - 12)

    def wrap(self, available_width: float, available_height: float):
        self.height = 24 + self.lane_count * 13 + 16
        return self.width, self.height

    def draw(self) -> None:
        canvas = self.canv
        axis = 15
        top = self.height
        canvas.setFillColor(PAPER)
        canvas.roundRect(0, 0, self.width, top, 6, stroke=0, fill=1)
        vah, val, poc = (self._number(self.context.get(key)) for key in ("vah", "val", "poc"))
        if vah is not None and val is not None:
            left, right = self.x(min(vah, val)), self.x(max(vah, val))
            canvas.setFillColor(GOLD_SOFT)
            canvas.rect(left, axis, right - left, top - axis - 4, stroke=0, fill=1)
            canvas.setStrokeColor(GOLD_LINE)
            canvas.setLineWidth(.6)
            canvas.line(left, axis, left, top - 4)
            canvas.line(right, axis, right, top - 4)
            canvas.setFont(BOLD, 5.8)
            canvas.setFillColor(GOLD_DEEP)
            canvas.drawString(left + 2, top - 10, "VAL")
            canvas.drawRightString(right - 2, top - 10, "VAH")
        if poc is not None:
            canvas.setStrokeColor(GOLD)
            canvas.setLineWidth(.9)
            canvas.setDash(2, 2)
            canvas.line(self.x(poc), axis, self.x(poc), top - 4)
            canvas.setDash()
            canvas.setFont(BOLD, 5.8)
            canvas.setFillColor(GOLD_DEEP)
            canvas.drawCentredString(self.x(poc), top - 10, "POC")
        for index, zone in enumerate(self.zones):
            start, end = self._span(zone)
            accent, soft, _ = direction_colors(zone.get("direction"))
            left, right = self.x(start), max(self.x(end), self.x(start) + 7)
            y = axis + 4 + self.lanes[index] * 13
            canvas.setFillColor(soft)
            canvas.setStrokeColor(accent)
            canvas.setLineWidth(.8)
            canvas.roundRect(left, y, right - left, 10, 2.4, stroke=1, fill=1)
            canvas.setFont(BOLD, 6.4)
            canvas.setFillColor(accent)
            canvas.drawCentredString((left + right) / 2, y + 3.2, str(index + 1))
        for ref in self.refs:
            price = self._number(ref.get("price_low"))
            x = self.x(price)
            canvas.setFillColor(VIOLET)
            path = canvas.beginPath()
            path.moveTo(x - 3, axis - 1)
            path.lineTo(x + 3, axis - 1)
            path.lineTo(x, axis + 4)
            path.close()
            canvas.drawPath(path, stroke=0, fill=1)
        canvas.setStrokeColor(LINE)
        canvas.setLineWidth(.7)
        canvas.line(6, axis, self.width - 6, axis)
        canvas.setFont(SANS, 6.4)
        canvas.setFillColor(MUTED)
        for price in nice_ticks(self.low, self.high):
            canvas.setStrokeColor(LINE)
            canvas.line(self.x(price), axis, self.x(price), axis - 2)
            canvas.drawCentredString(self.x(price), 5.5, fmt_number(price))


def nice_ticks(low: float, high: float, count: int = 6) -> list[float]:
    """Kulaté hodnoty na ose (1, 2, 2,5 nebo 5 × 10^n)."""
    import math
    span = max(high - low, 1e-9)
    raw = span / count
    power = 10 ** math.floor(math.log10(raw))
    step = next(power * factor for factor in (1, 2, 2.5, 5, 10) if power * factor >= raw)
    start = math.ceil(low / step) * step
    ticks = []
    value = start
    while value <= high + 1e-9:
        ticks.append(round(value, 6))
        value += step
    return ticks


def zone_sides(direction: str) -> list[tuple[str, str]]:
    if direction == "long":
        return [("long", "LONG")]
    if direction == "short":
        return [("short", "SHORT")]
    if direction == "both":
        return [("long", "LONG"), ("short", "SHORT")]
    return []


def condition(value: object) -> Paragraph:
    if filled(value):
        return Paragraph(ptext(value), STYLES["cond"])
    return Paragraph("Chybí definice", STYLES["missing"])


def zone_card(zone: dict, index: int, width: float) -> Table:
    """Karta zóny jako tabulka o několika řádcích: dlouhá karta se smí rozdělit mezi strany."""
    direction = str(zone.get("direction") or "")
    accent, soft, word = direction_colors(direction)
    word = DIRECTION_WORD.get(direction, "SMĚR NEVYBRÁN")
    inner = width - 3.2
    context = zone.get("va_context") or {}
    subtitle = []
    if context.get("text"):
        subtitle.append(f"<b>{escape(context['text'])}</b>")
    if filled(zone.get("priority")):
        subtitle.append(f"Priorita {escape(clean(zone.get('priority')))}")
    if filled(zone.get("status")) and zone.get("status") != "planned":
        subtitle.append(escape(ZONE_STATUS.get(str(zone.get("status")), str(zone.get("status")))))
    if filled(zone.get("source")):
        subtitle.append(escape(preview(zone.get("source"), 90)))

    number = Paragraph(f"{index:02d}", ParagraphStyle("zone_number", fontName=SERIF, fontSize=17, leading=19, textColor=accent))
    title = [Paragraph(escape(clean(zone.get("name"), f"Zóna {index}")), STYLES["card_title"])]
    if subtitle:
        title.append(Paragraph(" · ".join(subtitle), STYLES["tiny"]))
    price = Paragraph(escape(f"{fmt_number(zone.get('price_low'))} – {fmt_number(zone.get('price_high'))}"), STYLES["price"])
    head = Table([[number, title, [price, Spacer(1, 1.2 * mm), pill(word, WHITE if direction else INK_2, accent if direction else PAPER_2, 27 * mm)]]],
                 colWidths=[11 * mm, inner - 11 * mm - 46 * mm - 16, 46 * mm])
    head.setStyle(TableStyle([
        ("VALIGN", (0, 0), (-1, -1), "TOP"),
        ("ALIGN", (2, 0), (2, 0), "RIGHT"),
        ("LEFTPADDING", (0, 0), (-1, -1), 0),
        ("RIGHTPADDING", (0, 0), (-1, -1), 0),
        ("TOPPADDING", (0, 0), (-1, -1), 0),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 0),
    ]))
    rows: list[list] = [["", head]]
    commands = [
        ("BACKGROUND", (0, 0), (0, -1), accent if direction else LINE),
        ("BACKGROUND", (1, 0), (1, 0), soft if direction else PAPER),
    ]
    half = (inner - 16) / 2
    for side, side_word in zone_sides(direction):
        side_color = GREEN if side == "long" else RED
        pair = Table([[
            [Paragraph(f"{side_word} · CO SE MUSÍ SPLNIT PRO VSTUP", tinted("cond_label", side_color)), Spacer(1, 1), condition(zone.get(f"{side}_entry"))],
            [Paragraph(f"{side_word} · KDY OBCHOD NEBERU", STYLES["cond_label"]), Spacer(1, 1), condition(zone.get(f"{side}_skip"))],
        ]], colWidths=[half, half])
        pair.setStyle(TableStyle([
            ("VALIGN", (0, 0), (-1, -1), "TOP"),
            ("LINEAFTER", (0, 0), (0, -1), .5, LINE_SOFT),
            ("LEFTPADDING", (0, 0), (-1, -1), 0),
            ("LEFTPADDING", (1, 0), (1, -1), 8),
            ("RIGHTPADDING", (0, 0), (-1, -1), 6),
            ("TOPPADDING", (0, 0), (-1, -1), 0),
            ("BOTTOMPADDING", (0, 0), (-1, -1), 0),
        ]))
        rows.append(["", pair])
        commands.append(("LINEABOVE", (1, len(rows) - 1), (1, len(rows) - 1), .5, LINE_SOFT))
    extras = []
    if filled(zone.get("trigger")):
        extras.append(f"<b>Trigger</b> {escape(preview(zone.get('trigger'), 160))}")
    if filled(zone.get("invalidation")):
        extras.append(f"<b>Invalidace</b> {escape(preview(zone.get('invalidation'), 120))}")
    for key, name in (("stop_loss", "SL"), ("tp1", "TP1"), ("tp2", "TP2"), ("rr", "Min. RR")):
        if filled(zone.get(key)):
            extras.append(f"<b>{name}</b> {escape(fmt_number(zone.get(key)))}")
    if extras:
        rows.append(["", Paragraph("&nbsp;&nbsp;·&nbsp;&nbsp;".join(extras), STYLES["small"])])
        commands += [("LINEABOVE", (1, len(rows) - 1), (1, len(rows) - 1), .5, LINE_SOFT), ("BACKGROUND", (1, len(rows) - 1), (1, len(rows) - 1), PAPER)]
    table = Table(rows, colWidths=[3.2, inner])
    table.setStyle(TableStyle(commands + [
        ("BOX", (0, 0), (-1, -1), .6, LINE),
        ("VALIGN", (0, 0), (-1, -1), "TOP"),
        ("LEFTPADDING", (0, 0), (0, -1), 0),
        ("RIGHTPADDING", (0, 0), (0, -1), 0),
        ("LEFTPADDING", (1, 0), (1, -1), 9),
        ("RIGHTPADDING", (1, 0), (1, -1), 9),
        ("TOPPADDING", (0, 0), (-1, -1), 7),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 7.5),
        ("ROUNDEDCORNERS", [6] * 4),
    ]))
    return table


def zone_flowables(zones: list[dict]) -> list:
    """Zóny po dvou vedle sebe; příliš dlouhá karta jde přes celou šířku a smí se rozdělit."""
    gap = 4 * mm
    column = (USABLE - gap) / 2
    limit = (PAGE_HEIGHT - LATER_TOP - FOOTER_SPACE) * .82
    items: list = []
    pending: list = []
    for index, zone in enumerate(zones, 1):
        card = zone_card(zone, index, column)
        _, height = card.wrap(column, PAGE_HEIGHT)
        if height > limit:
            if pending:
                items.append(grid(pending, USABLE, 2, gap))
                pending = []
            items.extend([zone_card(zone, index, USABLE), Spacer(1, gap)])
            continue
        pending.append(card)
        if len(pending) == 2:
            items.append(grid(pending, USABLE, 2, gap))
            pending = []
    if pending:
        items.append(grid(pending, USABLE, 2, gap))
    return items


def image_flowable(path: str, max_width: float, max_height: float) -> Image | None:
    try:
        width, height = ImageReader(path).getSize()
        if width <= 0 or height <= 0:
            return None
        scale = min(max_width / width, max_height / height)
        image = Image(path, width=width * scale, height=height * scale)
        image.hAlign = "CENTER"
        return image
    except Exception:
        return None


def date_label(plan: dict) -> str:
    date = clean(plan.get("plan_date"))
    if not date:
        return "–"
    try:
        from datetime import date as date_cls, timedelta
        year, month, day = (int(part) for part in date.split("-"))
        start = date_cls(year, month, day)
    except ValueError:
        return date
    if is_weekly(plan):
        end = start + timedelta(days=4)
        return f"týden {start.isocalendar()[1]} · {start.day}. {start.month}. – {end.day}. {end.month}. {end.year}"
    names = ["pondělí", "úterý", "středa", "čtvrtek", "pátek", "sobota", "neděle"]
    return f"{names[start.weekday()]} {start.day}. {start.month}. {start.year}"


def build_pdf(payload: dict, output_path: str) -> None:
    plan = payload.get("plan") or {}
    zones = payload.get("zones") or plan.get("zones") or []
    levels = payload.get("levels") or plan.get("levels") or []
    ideas = payload.get("ideas") or plan.get("ideas") or []
    refs = payload.get("refs") or plan.get("refs") or []
    screenshots = payload.get("screenshots") or []
    trades = payload.get("trades") or plan.get("trades") or []
    weekly_context = plan.get("weekly_context") or {}
    weekly = is_weekly(plan)
    kind = "Týdenní náhled trhu" if weekly else "Denní náhled trhu"
    market = clean(plan.get("market"), "–")

    def decorate(canvas, page: int, total: int) -> None:
        if page == 1:
            cover_band(canvas, PAGE_WIDTH, PAGE_HEIGHT, BAND, kind, f"{market} · {date_label(plan)}",
                       ("Pracovní plán na týden" if weekly else "Pracovní plán na seanci") + f" · {trade_type(plan.get('session'))}",
                       [("Stav", label(STATUS, plan.get("status"), clean(plan.get("status")))), ("Zóny", str(len(zones))), ("Red news", preview(plan.get("important_news"), 26))],
                       MARGIN)
        else:
            running_header(canvas, PAGE_WIDTH, PAGE_HEIGHT, MARGIN, f"{kind} · {market}", date_label(plan))
        running_footer(canvas, PAGE_WIDTH, MARGIN, "Pracovní obchodní plán, nikoli predikce trhu.", page, total)

    doc = BaseDocTemplate(
        output_path,
        pagesize=(PAGE_WIDTH, PAGE_HEIGHT),
        leftMargin=MARGIN, rightMargin=MARGIN, topMargin=LATER_TOP, bottomMargin=FOOTER_SPACE,
        title=f"{kind} {market} {clean(plan.get('plan_date'))}",
        author="Trading Desk",
        subject="Obchodní náhled",
    )
    frame_kwargs = {"leftPadding": 0, "rightPadding": 0, "topPadding": 0, "bottomPadding": 0}
    doc.addPageTemplates([
        PageTemplate("first", [Frame(MARGIN, FOOTER_SPACE, USABLE, PAGE_HEIGHT - FIRST_TOP - FOOTER_SPACE, id="first", **frame_kwargs)]),
        PageTemplate("later", [Frame(MARGIN, FOOTER_SPACE, USABLE, PAGE_HEIGHT - LATER_TOP - FOOTER_SPACE, id="later", **frame_kwargs)]),
    ])

    story: list = [NextPageTemplate("later"), bias_board(plan), Spacer(1, 4.5 * mm)]

    # Popis trhu vlastními slovy tradera a pod ním automatický pracovní závěr.
    description = clean(plan.get("bias_description"))
    conclusion = working_conclusion(plan, refs)
    if description or conclusion:
        content: list = [Tracked("Co se na trhu odehrává", GOLD, 6.6, 1.2)]
        if description:
            content += [Spacer(1, 1 * mm), Paragraph(ptext(description), STYLES["lead"])]
        if conclusion:
            content += [Spacer(1, 2 * mm), Paragraph(f'<font name="{SEMI}" color="#86601D">Pracovní závěr</font>&nbsp;&nbsp;{escape(conclusion)}', STYLES["small"])]
        story.append(frame(content, USABLE, background=WHITE, border=LINE, padding=11, radius=6, accent=GOLD))

    if zones:
        story.extend(section("Obchodní zóny", f"{plural(len(zones), 'zóna', 'zóny', 'zón')} · co a za jakých podmínek obchoduji"))
        if weekly_context.get("vah") is not None and weekly_context.get("val") is not None:
            source = "value minulého týdne" if weekly else "value minulého týdne z týdenního náhledu"
            poc = f" · POC {fmt_number(weekly_context.get('poc'))}" if weekly_context.get("poc") is not None else ""
            story.append(Paragraph(f'<font name="{SEMI}">Zóny jsou porovnané s {source}:</font> VAH {fmt_number(weekly_context.get("vah"))}{poc} · VAL {fmt_number(weekly_context.get("val"))}', STYLES["small"]))
            story.append(Spacer(1, 2 * mm))
        story.extend([PriceMap(zones, weekly_context, refs, USABLE), Spacer(1, 1.6 * mm),
                      Paragraph("Mapa ceny: zlatě value minulého týdne, čárkovaně POC, čísla jsou zóny níže, fialové značky reference na dojetí.", STYLES["tiny"]),
                      Spacer(1, 3.5 * mm)])
        story.extend(zone_flowables(zones))
    else:
        story.extend(section("Obchodní zóny", "co a za jakých podmínek obchoduji"))
        story.append(Paragraph("Pro tento náhled nejsou uložené žádné obchodní zóny.", STYLES["body"]))

    # Kontext z Market Profile.
    context_items = [
        ("Tvar profilu", label(SHAPE, plan.get("profile_shape"))),
        ("Close vůči objemu", label(CLOSE, plan.get("previous_close"))),
        ("Migrace value", label(VALUE_AREA, plan.get("value_area"))),
        ("Migrace POC", label(VPOC, plan.get("vpoc"))),
        ("Stav aukce", label(AUCTION, plan.get("auction"))),
        ("Cena vůči týdenní VA", "" if weekly else label(WEEKLY, plan.get("weekly_position"))),
        ("Single prints", label(SIGN, plan.get("single_print"))),
        ("Excess / tail", label(TAIL, plan.get("tail"))),
        ("Otevření Globexu", label(POSITION, plan.get("globex_open"))),
        ("EU open", label(POSITION, plan.get("eu_open"))),
        ("RTH open", label(POSITION, plan.get("ny_open"))),
        ("Typ otevření", label(OPEN_TYPE, plan.get("open_type"))),
        ("Initial Balance", label(IB, plan.get("initial_balance"))),
    ]
    prices = [(name, fmt_number(plan.get(key))) for name, key in (("High", "ref_high"), ("VAH", "ref_vah"), ("POC", "ref_poc"), ("VAL", "ref_val"), ("Low", "ref_low"), ("Close", "ref_close")) if plan.get(key) is not None]
    context_table = facts(context_items, USABLE, 5, WHITE)
    price_table = facts(prices, USABLE, 6, GOLD_SOFT)
    if context_table or price_table:
        story.extend(section("Market Profile", "profil minulého týdne" if weekly else "profil předchozího dne"))
        if price_table:
            story.extend([price_table, Spacer(1, 3 * mm)])
        if context_table:
            story.append(context_table)

    if refs:
        story.extend(section("Reference na dojetí", plural(len([ref for ref in refs if ref.get("status") != "filled"]), "nedokončená aukce", "nedokončené aukce", "nedokončených aukcí")))
        rows, accents = [], []
        for ref in refs:
            low, high = ref.get("price_low"), ref.get("price_high")
            price = fmt_number(low) if high in (None, "") or high == low else f"{fmt_number(low)} – {fmt_number(high)}"
            done = ref.get("status") == "filled"
            rows.append([
                Paragraph(escape(REF_KIND.get(str(ref.get("kind") or ""), "Reference")), STYLES["small_strong"]),
                Paragraph(escape(price), STYLES["small"]),
                Paragraph("Dojeto" if done else "Na dojetí", STYLES["small"]),
                Paragraph(ptext(ref.get("note")), STYLES["small"]),
            ])
            accents.append(LINE if done else VIOLET)
        story.append(data_table(["Typ", "Cena", "Stav", "Poznámka"], rows, [USABLE * .2, USABLE * .18, USABLE * .14, USABLE * .48], accents))

    # DiNapoli: trend podle DMA, levely a kde se kryjí (konfluence F5 + F5, shoda expanze + retracement).
    dinapoli = plan.get("dinapoli") or {}
    dn_levels = dinapoli.get("levels") or []
    dn_clusters = dinapoli.get("clusters") or []
    dma = {"above": "Nad", "below": "Pod"}
    thrust = {"up": "Nahoru", "down": "Dolů"}
    dn_types = {"confluence": "Konfluence", "agreement": "Shoda"}
    trend_table = facts([
        ("3x3 DMA", label(dma, plan.get("dn_dma_3x3"))),
        ("7x5 DMA", label(dma, plan.get("dn_dma_7x5"))),
        ("25x5 DMA", label(dma, plan.get("dn_dma_25x5"))),
        ("Thrust", label(thrust, plan.get("dn_thrust"))),
        ("Vzory", clean(plan.get("dn_patterns"))),
    ], USABLE, 5, WHITE)
    if dn_levels or trend_table or filled(plan.get("dn_notes")):
        confluences = sum(1 for cluster in dn_clusters if cluster.get("type") == "confluence")
        agreements = len(dn_clusters) - confluences
        story.extend(section("DiNapoli", " · ".join([plural(len(dn_levels), "level", "levely", "levelů"), plural(confluences, "konfluence", "konfluence", "konfluencí"), plural(agreements, "shoda", "shody", "shod")])))
        if trend_table:
            story.extend([trend_table, Spacer(1, 3 * mm)])
        if dn_levels:
            matched: dict = {}
            for cluster in dn_clusters:
                for member in cluster.get("members") or []:
                    matched.setdefault(member.get("index"), set()).add(dn_types.get(cluster.get("type"), ""))
            rows, accents = [], []
            for level in sorted(dn_levels, key=lambda item: -float(item.get("price") or 0)):
                revisited = level.get("status") == "revisited"
                rows.append([
                    Paragraph(escape(clean(level.get("timeframe"), "–")), STYLES["small_strong"]),
                    Paragraph(f"<b>{escape(clean(level.get('kind')))}</b>", STYLES["small"]),
                    Paragraph("Revisited" if revisited else "Naked", STYLES["small"]),
                    Paragraph(escape(fmt_number(level.get("price"))), STYLES["small"]),
                    Paragraph(escape(" + ".join(sorted(matched.get(level.get("index"), set())))), STYLES["small"]),
                    Paragraph(ptext(level.get("note")), STYLES["small"]),
                ])
                accents.append(GREEN if level.get("group") == "retracement" else AMBER)
            story.append(data_table(["TF", "Level", "Stav", "Cena", "Kryje se", "Poznámka"], rows,
                                    [USABLE * .1, USABLE * .1, USABLE * .13, USABLE * .15, USABLE * .17, USABLE * .35], accents))
        if dn_clusters:
            rows, accents = [], []
            for cluster in dn_clusters:
                low, high = cluster.get("low"), cluster.get("high")
                members = " · ".join(
                    f"{member.get('kind')} {fmt_number(member.get('price'))}" + (" (revisited)" if member.get("status") == "revisited" else "")
                    for member in cluster.get("members") or [])
                rows.append([Paragraph(escape(dn_types.get(cluster.get("type"), "")), STYLES["small_strong"]),
                             Paragraph(escape(clean(cluster.get("timeframe"), "–")), STYLES["small"]),
                             Paragraph(escape(fmt_number(low) if low == high else f"{fmt_number(low)} – {fmt_number(high)}"), STYLES["small"]),
                             Paragraph(escape(members), STYLES["small"]),
                             Paragraph(escape(f"{fmt_number(cluster.get('tolerance'))} b"), STYLES["small"])])
                accents.append(AMBER if cluster.get("type") == "agreement" else GREEN)
            story.extend([Spacer(1, 3 * mm), data_table(["Druh", "TF", "Pásmo", "Složení", "Tolerance"], rows,
                                                        [USABLE * .16, USABLE * .1, USABLE * .2, USABLE * .4, USABLE * .14], accents)])
        if filled(plan.get("dn_notes")):
            story.extend([Spacer(1, 2 * mm), Paragraph(ptext(plan.get("dn_notes")), STYLES["small"])])

    if levels:
        story.extend(section("Klíčové levely", plural(len(levels), "horizontální úroveň", "horizontální úrovně", "horizontálních úrovní")))
        rows, accents = [], []
        for index, level in enumerate(levels, 1):
            kind_key = str(level.get("kind") or "")
            rows.append([
                Paragraph(escape(clean(level.get("name"), f"Level {index}")), STYLES["small_strong"]),
                Paragraph(escape(fmt_number(level.get("price"))), STYLES["small"]),
                Paragraph(escape(LEVEL_KIND.get(kind_key, "–")), STYLES["small"]),
                Paragraph(ptext(level.get("source")), STYLES["small"]),
                Paragraph(ptext(preview(level.get("note"), 140)), STYLES["small"]),
            ])
            accents.append(GREEN if kind_key == "support" else RED if kind_key == "resistance" else AMBER if kind_key == "pivot" else LINE)
        story.append(data_table(["Level", "Cena", "Charakter", "Zdroj / shoda", "Poznámka"], rows, [USABLE * .24, USABLE * .12, USABLE * .13, USABLE * .21, USABLE * .30], accents))

    if ideas:
        story.extend(section("Potenciální obchody a targety", plural(len(ideas), "scénář", "scénáře", "scénářů")))
        rows, accents = [], []
        for index, idea in enumerate(ideas, 1):
            direction = str(idea.get("direction") or "")
            rows.append([
                Paragraph(f"<b>{escape(clean(idea.get('name'), f'Scénář {index}'))}</b><br/>{escape(clean(idea.get('trigger')))}", STYLES["small"]),
                Paragraph(escape(clean(idea.get("zone_name"), "–")), STYLES["small"]),
                Paragraph("Long" if direction == "long" else "Short", STYLES["small"]),
                Paragraph(escape(f"{fmt_number(idea.get('entry_price'))} / {fmt_number(idea.get('stop_loss'))}"), STYLES["small"]),
                Paragraph(escape(f"{fmt_number(idea.get('tp1'))} / {fmt_number(idea.get('tp2'))} / {fmt_number(idea.get('final_tp'))}"), STYLES["small"]),
                Paragraph(escape(label(IDEA_STATUS, idea.get("status"), "–")), STYLES["small"]),
            ])
            accents.append(GREEN if direction == "long" else RED)
        story.append(data_table(["Scénář", "Zóna", "Směr", "Entry / SL", "TP1 / TP2 / finální", "Stav"], rows, [USABLE * .33, USABLE * .17, USABLE * .08, USABLE * .14, USABLE * .18, USABLE * .10], accents))

    custom_rows = plan.get("custom_readable") or []
    if custom_rows:
        story.extend(section("Vlastní pole", plural(len(custom_rows), "vyplněné", "vyplněná", "vyplněných")))
        rows = [[Paragraph(escape(str(row.get("label"))), STYLES["small_strong"]), Paragraph(ptext(row.get("value")), STYLES["small"])] for row in custom_rows]
        story.append(data_table(["Pole", "Hodnota"], rows, [USABLE * .32, USABLE * .68]))

    notes = [
        ("Co bias potvrzuje", plan.get("bias_confirm"), GREEN),
        ("Co bias ruší", plan.get("bias_invalidation"), RED),
        ("Red news", plan.get("important_news"), AMBER),
        ("No-trade podmínky", plan.get("no_trade_conditions"), RED),
        ("Další poznámky", plan.get("general_notes"), GOLD),
    ]
    visible = [item for item in notes if filled(item[1])]
    if visible:
        story.extend(section("Potvrzení, rizika a poznámky", "exekuční rámec"))
        column = (USABLE - 4 * mm) / 2
        cards = [frame([Tracked(title, accent, 6.4, 1.1), Spacer(1, .8 * mm), Paragraph(ptext(value), STYLES["small"])], column, WHITE, LINE, 9, 6, accent) for title, value, accent in visible]
        story.append(grid(cards, USABLE, 2))

    if trades:
        story.extend(section("Realizované obchody navázané na náhled", plural(len(trades), "záznam", "záznamy", "záznamů")))
        rows, accents = [], []
        for trade in trades:
            rows.append([
                Paragraph(escape(clean(trade.get("trade_date"), "–")), STYLES["small_strong"]),
                Paragraph(escape(clean(trade.get("strategy"), "–")), STYLES["small"]),
                Paragraph("Long" if trade.get("direction") == "long" else "Short", STYLES["small"]),
                Paragraph(escape(f"{fmt_number(trade.get('entry_price'))} / {fmt_number(trade.get('exit_price'))}"), STYLES["small"]),
                Paragraph(escape(f"{fmt_number(trade.get('result_r'), 2)} R" if trade.get("result_r") is not None else "–"), STYLES["small"]),
                Paragraph(ptext(trade.get("notes")), STYLES["small"]),
            ])
            accents.append(GREEN if trade.get("direction") == "long" else RED)
        story.append(data_table(["Datum", "Setup", "Směr", "Entry / Exit", "Výsledek", "Poznámka"], rows, [USABLE * .1, USABLE * .2, USABLE * .08, USABLE * .15, USABLE * .1, USABLE * .37], accents))

    images = []
    for screenshot in screenshots:
        image = image_flowable(str(screenshot.get("path", "")), USABLE / 2 - 24, 82 * mm)
        if image is None:
            continue
        caption = clean(screenshot.get("caption"), screenshot.get("original_name") or "Graf")
        images.append(frame([image, Spacer(1, 2 * mm), Paragraph(escape(caption), STYLES["small"])], (USABLE - 4 * mm) / 2, WHITE, LINE, 8, 6))
    for start in range(0, len(images), 2):
        story.append(PageBreak())
        story.extend(section("Grafy", plural(len(images), "screenshot", "screenshoty", "screenshotů")))
        story.append(grid(images[start:start + 2], USABLE, 2))

    doc.build(story, canvasmaker=lambda *args, **kwargs: DocumentCanvas(*args, decorate=decorate, **kwargs))


def main() -> None:
    parser = argparse.ArgumentParser(description="Vygeneruje PDF denního nebo týdenního trading náhledu.")
    parser.add_argument("--input", required=True, help="Cesta ke vstupnímu JSON")
    parser.add_argument("--output", required=True, help="Cesta k výslednému PDF")
    args = parser.parse_args()

    with open(args.input, "r", encoding="utf-8") as source:
        payload = json.load(source)
    Path(args.output).parent.mkdir(parents=True, exist_ok=True)
    build_pdf(payload, args.output)


if __name__ == "__main__":
    main()
