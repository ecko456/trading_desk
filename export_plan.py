#!/usr/bin/env python3
"""Create a printable trading-plan PDF from a server-generated JSON payload.

Pořadí stránky je dané tím, co trader potřebuje vidět jako první:
bias z price action a z profilu, krátký popis trhu a potom zóny s tím,
co se na nich obchoduje a za jakých podmínek. Kontext a detaily jdou až za tím.
Prázdné položky se do PDF nevypisují.
"""

from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
from xml.sax.saxutils import escape

from reportlab.lib import colors
from reportlab.lib.enums import TA_CENTER, TA_LEFT, TA_RIGHT
from reportlab.lib.pagesizes import A4, landscape
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.units import mm
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.pdfgen.canvas import Canvas
from reportlab.platypus import (
    HRFlowable,
    Image,
    KeepTogether,
    PageBreak,
    Paragraph,
    SimpleDocTemplate,
    Spacer,
    Table,
    TableStyle,
)
from reportlab.lib.utils import ImageReader


PAGE_WIDTH, PAGE_HEIGHT = landscape(A4)

INK = colors.HexColor("#141B26")
INK_SOFT = colors.HexColor("#4A5566")
MUTED = colors.HexColor("#6F7B8D")
LINE = colors.HexColor("#DCE2EA")
PAPER = colors.HexColor("#F4F6F9")
WHITE = colors.white
BLUE = colors.HexColor("#2F63E6")
BLUE_SOFT = colors.HexColor("#E8EFFD")
GREEN = colors.HexColor("#138A55")
GREEN_SOFT = colors.HexColor("#E6F5ED")
RED = colors.HexColor("#C8354A")
RED_SOFT = colors.HexColor("#FBEAEC")
AMBER = colors.HexColor("#A5660F")
AMBER_SOFT = colors.HexColor("#FDF3E1")
VIOLET = colors.HexColor("#6D4FCF")
VIOLET_SOFT = colors.HexColor("#F0ECFC")


def register_fonts() -> tuple[str, str]:
    candidates = [
        ("/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf", "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf"),
        ("C:/Windows/Fonts/arial.ttf", "C:/Windows/Fonts/arialbd.ttf"),
        ("/System/Library/Fonts/Supplemental/Arial.ttf", "/System/Library/Fonts/Supplemental/Arial Bold.ttf"),
    ]
    for regular, bold in candidates:
        if os.path.isfile(regular) and os.path.isfile(bold):
            pdfmetrics.registerFont(TTFont("TradingSans", regular))
            pdfmetrics.registerFont(TTFont("TradingSans-Bold", bold))
            return "TradingSans", "TradingSans-Bold"
    return "Helvetica", "Helvetica-Bold"


FONT, FONT_BOLD = register_fonts()


def clean(value: object, fallback: str = "-") -> str:
    if value is None:
        return fallback
    text = str(value).strip()
    return text if text else fallback


def filled(value: object) -> bool:
    return clean(value, "") != ""


def ptext(value: object, fallback: str = "-") -> str:
    return escape(clean(value, fallback)).replace("\n", "<br/>")


def preview(value: object, limit: int = 260) -> str:
    text = clean(value, "")
    return text if len(text) <= limit else text[: limit - 1].rstrip() + "…"


def fmt_number(value: object) -> str:
    if value is None or value == "":
        return "-"
    try:
        number = float(value)
    except (TypeError, ValueError):
        return clean(value)
    if number.is_integer():
        return f"{number:,.0f}".replace(",", " ")
    return f"{number:,.4f}".rstrip("0").rstrip(".").replace(",", " ").replace(".", ",")


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


def bias_palette(value: object) -> tuple:
    key = str(value or "")
    if key == "long":
        return GREEN_SOFT, GREEN
    if key == "short":
        return RED_SOFT, RED
    if key in {"balance", "neutral"}:
        return AMBER_SOFT, AMBER
    return PAPER, MUTED


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


def make_styles() -> dict[str, ParagraphStyle]:
    base = getSampleStyleSheet()
    body = base["BodyText"]
    return {
        "title": ParagraphStyle("title", parent=base["Title"], fontName=FONT_BOLD, fontSize=20, leading=23, textColor=INK, alignment=TA_LEFT, spaceAfter=0),
        "meta": ParagraphStyle("meta", parent=body, fontName=FONT, fontSize=9, leading=12, textColor=INK_SOFT),
        "meta_right": ParagraphStyle("meta_right", parent=body, fontName=FONT, fontSize=9, leading=12.5, textColor=INK_SOFT, alignment=TA_RIGHT),
        "kicker": ParagraphStyle("kicker", parent=body, fontName=FONT_BOLD, fontSize=7, leading=9, textColor=BLUE),
        "h2": ParagraphStyle("h2", parent=base["Heading2"], fontName=FONT_BOLD, fontSize=13, leading=16, textColor=INK, spaceBefore=0, spaceAfter=0),
        "body": ParagraphStyle("body", parent=body, fontName=FONT, fontSize=9.4, leading=13.4, textColor=INK),
        "body_small": ParagraphStyle("body_small", parent=body, fontName=FONT, fontSize=8, leading=10.8, textColor=INK_SOFT),
        "label": ParagraphStyle("label", parent=body, fontName=FONT_BOLD, fontSize=6.6, leading=8.4, textColor=MUTED, spaceAfter=1.5),
        "label_center": ParagraphStyle("label_center", parent=body, fontName=FONT_BOLD, fontSize=7, leading=9, textColor=MUTED, alignment=TA_CENTER),
        "row_label": ParagraphStyle("row_label", parent=body, fontName=FONT_BOLD, fontSize=8.2, leading=10, textColor=INK),
        "bias_value": ParagraphStyle("bias_value", parent=body, fontName=FONT_BOLD, fontSize=15, leading=18, alignment=TA_CENTER),
        "bias_note": ParagraphStyle("bias_note", parent=body, fontName=FONT, fontSize=7, leading=9, textColor=INK_SOFT, alignment=TA_CENTER),
        "final_value": ParagraphStyle("final_value", parent=body, fontName=FONT_BOLD, fontSize=22, leading=26, alignment=TA_CENTER),
        "context_value": ParagraphStyle("context_value", parent=body, fontName=FONT_BOLD, fontSize=8.6, leading=10.8, textColor=INK),
        "card_title": ParagraphStyle("card_title", parent=body, fontName=FONT_BOLD, fontSize=10.5, leading=13, textColor=INK),
        "card_sub": ParagraphStyle("card_sub", parent=body, fontName=FONT, fontSize=8, leading=10, textColor=INK_SOFT),
        "price_right": ParagraphStyle("price_right", parent=body, fontName=FONT_BOLD, fontSize=10.5, leading=13, textColor=INK, alignment=TA_RIGHT),
        "badge": ParagraphStyle("badge", parent=body, fontName=FONT_BOLD, fontSize=8.4, leading=10, alignment=TA_CENTER),
        "cond_label": ParagraphStyle("cond_label", parent=body, fontName=FONT_BOLD, fontSize=7, leading=9, textColor=MUTED),
        "cond_text": ParagraphStyle("cond_text", parent=body, fontName=FONT, fontSize=8.8, leading=11.8, textColor=INK),
    }


def section_title(title: str, kicker: str, styles: dict[str, ParagraphStyle]) -> list:
    return [
        Spacer(1, 4 * mm),
        Paragraph(escape(kicker.upper()), styles["kicker"]),
        Paragraph(escape(title), styles["h2"]),
        Spacer(1, 1.5 * mm),
        HRFlowable(width="100%", thickness=0.7, color=LINE),
        Spacer(1, 2.5 * mm),
    ]


def boxed(content: list, width: float, background, border, padding: float = 7) -> Table:
    box = Table([[content]], colWidths=[width])
    box.setStyle(TableStyle([
        ("BACKGROUND", (0, 0), (-1, -1), background),
        ("BOX", (0, 0), (-1, -1), .7, border),
        ("LEFTPADDING", (0, 0), (-1, -1), padding),
        ("RIGHTPADDING", (0, 0), (-1, -1), padding),
        ("TOPPADDING", (0, 0), (-1, -1), padding - 1),
        ("BOTTOMPADDING", (0, 0), (-1, -1), padding - 1),
        ("VALIGN", (0, 0), (-1, -1), "MIDDLE"),
    ]))
    return box


def bias_cell(value: object, note: object, styles: dict[str, ParagraphStyle], width: float) -> Table:
    background, accent = bias_palette(value)
    word = BIAS_WORD.get(str(value or ""), "—")
    content = [Paragraph(escape(word), ParagraphStyle("bias_dynamic", parent=styles["bias_value"], textColor=accent))]
    if filled(note):
        content.append(Paragraph(escape(preview(note, 70)), styles["bias_note"]))
    return boxed(content, width, background, accent if value else LINE, padding=6)


def bias_board(plan: dict, styles: dict[str, ParagraphStyle], usable_width: float) -> Table:
    """Hlavní přehled nahoře: bias z price action a z MP/VP pro Weekly a Daily."""
    weekly = is_weekly(plan)
    columns = [("MONTHLY", "monthly"), ("WEEKLY", "weekly")] if weekly else [("WEEKLY", "weekly"), ("DAILY", "daily")]
    rows = [("PRICE ACTION", "pa"), ("MP / VP", "mp")]
    label_width = 30 * mm
    final_width = 58 * mm
    gap = 4 * mm
    cell_width = (usable_width - label_width - final_width - gap) / 2 - 3

    grid_rows = [[Paragraph("", styles["label"])] + [Paragraph(title, styles["label_center"]) for title, _ in columns]]
    for row_title, prefix in rows:
        cells = [Paragraph(row_title, styles["row_label"])]
        for _, frame in columns:
            key = f"{prefix}_{frame}"
            if key not in plan and prefix == "mp" and frame == "monthly":
                cells.append(Paragraph("—", styles["bias_note"]))
                continue
            cells.append(bias_cell(plan.get(key), plan.get(f"{key}_note"), styles, cell_width))
        grid_rows.append(cells)
    grid = Table(grid_rows, colWidths=[label_width, cell_width + 3, cell_width + 3])
    grid.setStyle(TableStyle([
        ("VALIGN", (0, 0), (-1, -1), "MIDDLE"),
        ("LEFTPADDING", (0, 0), (-1, -1), 0),
        ("RIGHTPADDING", (0, 0), (-1, -1), 3),
        ("TOPPADDING", (0, 0), (-1, -1), 1.5),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 1.5),
    ]))

    bias = str(plan.get("bias") or "neutral")
    background, accent = bias_palette(bias)
    final_content = [
        Paragraph("PRACOVNÍ BIAS", styles["label_center"]),
        Paragraph(escape(BIAS_WORD.get(bias, "BALANCE")), ParagraphStyle("final_dynamic", parent=styles["final_value"], textColor=accent)),
    ]
    alignment = alignment_text(plan)
    if alignment:
        final_content.append(Paragraph(escape(alignment), styles["bias_note"]))
    if filled(plan.get("pa_monthly")) and not weekly:
        final_content.append(Paragraph(escape(f"Monthly PA: {BIAS_WORD.get(str(plan.get('pa_monthly')), '—')}"), styles["bias_note"]))
    final_box = boxed(final_content, final_width, background, accent, padding=9)

    board = Table([[grid, final_box]], colWidths=[usable_width - final_width - gap, final_width + gap])
    board.setStyle(TableStyle([
        ("VALIGN", (0, 0), (-1, -1), "MIDDLE"),
        ("LEFTPADDING", (0, 0), (-1, -1), 0),
        ("RIGHTPADDING", (0, 0), (0, 0), gap),
        ("RIGHTPADDING", (1, 0), (1, 0), 0),
        ("TOPPADDING", (0, 0), (-1, -1), 0),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 0),
    ]))
    return board


def zone_sides(direction: str) -> list[tuple[str, str]]:
    if direction == "long":
        return [("long", "LONG")]
    if direction == "short":
        return [("short", "SHORT")]
    if direction == "both":
        return [("long", "LONG"), ("short", "SHORT")]
    return []


def condition_text(value: object, styles: dict[str, ParagraphStyle]) -> Paragraph:
    if filled(value):
        return Paragraph(ptext(value), styles["cond_text"])
    return Paragraph("Chybí definice", ParagraphStyle("cond_missing", parent=styles["cond_text"], textColor=AMBER))


def zone_card(zone: dict, index: int, styles: dict[str, ParagraphStyle], usable_width: float) -> KeepTogether:
    direction = str(zone.get("direction") or "")
    tint = GREEN_SOFT if direction == "long" else RED_SOFT if direction == "short" else BLUE_SOFT if direction == "both" else PAPER
    accent = GREEN if direction == "long" else RED if direction == "short" else BLUE if direction == "both" else MUTED
    title = clean(zone.get("name"), f"Zóna {index}")
    range_text = f"{fmt_number(zone.get('price_low'))} – {fmt_number(zone.get('price_high'))}"
    context = zone.get("va_context") or {}
    subtitle_parts = []
    if context.get("text"):
        subtitle_parts.append(f"<b>{escape(context['text'])}</b>")
    if filled(zone.get("priority")):
        subtitle_parts.append(f"Priorita {escape(clean(zone.get('priority')))}")
    if filled(zone.get("status")) and zone.get("status") != "planned":
        subtitle_parts.append(escape(ZONE_STATUS.get(str(zone.get("status")), str(zone.get("status")))))
    if filled(zone.get("source")):
        subtitle_parts.append(escape(preview(zone.get("source"), 90)))

    badge = boxed([Paragraph(escape(DIRECTION_WORD.get(direction, "SMĚR NEVYBRÁN")), ParagraphStyle("zone_badge", parent=styles["badge"], textColor=WHITE if direction else INK_SOFT))], 30 * mm, accent if direction else PAPER, accent, padding=4)
    header = Table([[
        [Paragraph(f"{index}. {escape(title)}", styles["card_title"]), Paragraph(" · ".join(subtitle_parts) or "&nbsp;", styles["card_sub"])],
        Paragraph(escape(range_text), styles["price_right"]),
        badge,
    ]], colWidths=[usable_width - 78 * mm, 42 * mm, 36 * mm])
    header.setStyle(TableStyle([
        ("BACKGROUND", (0, 0), (-1, -1), tint),
        ("LINEBEFORE", (0, 0), (0, -1), 3.5, accent),
        ("VALIGN", (0, 0), (-1, -1), "MIDDLE"),
        ("LEFTPADDING", (0, 0), (-1, -1), 8),
        ("RIGHTPADDING", (0, 0), (-1, -1), 8),
        ("TOPPADDING", (0, 0), (-1, -1), 6),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 6),
    ]))
    parts: list = [header]

    sides = zone_sides(direction)
    if sides:
        rows = []
        styles_list = [
            ("BOX", (0, 0), (-1, -1), .55, LINE),
            ("INNERGRID", (0, 0), (-1, -1), .4, LINE),
            ("VALIGN", (0, 0), (-1, -1), "TOP"),
            ("LEFTPADDING", (0, 0), (-1, -1), 8),
            ("RIGHTPADDING", (0, 0), (-1, -1), 8),
            ("TOPPADDING", (0, 0), (-1, -1), 5),
            ("BOTTOMPADDING", (0, 0), (-1, -1), 6),
        ]
        for row_index, (side, word) in enumerate(sides):
            side_color = GREEN if side == "long" else RED
            rows.append([
                [Paragraph(f"{word} · CO SE MUSÍ SPLNIT PRO VSTUP", ParagraphStyle(f"cl_{side}", parent=styles["cond_label"], textColor=side_color)), condition_text(zone.get(f"{side}_entry"), styles)],
                [Paragraph(f"{word} · KDY OBCHOD NEBERU", styles["cond_label"]), condition_text(zone.get(f"{side}_skip"), styles)],
            ])
            styles_list.append(("LINEBEFORE", (0, row_index), (0, row_index), 2.5, side_color))
        body = Table(rows, colWidths=[usable_width / 2, usable_width / 2])
        body.setStyle(TableStyle(styles_list))
        parts.append(body)

    extras = []
    if filled(zone.get("trigger")):
        extras.append(f"<b>Trigger:</b> {escape(preview(zone.get('trigger'), 160))}")
    if filled(zone.get("invalidation")):
        extras.append(f"<b>Invalidace:</b> {escape(preview(zone.get('invalidation'), 120))}")
    for key, name in (("stop_loss", "SL"), ("tp1", "TP1"), ("tp2", "TP2"), ("rr", "Min. RR")):
        if filled(zone.get(key)):
            extras.append(f"<b>{name}</b> {escape(fmt_number(zone.get(key)))}")
    if extras:
        parts.append(boxed([Paragraph("  ·  ".join(extras), styles["body_small"])], usable_width, WHITE, LINE, padding=6))
    parts.append(Spacer(1, 3 * mm))
    return KeepTogether(parts)


def context_grid(items: list[tuple[str, str]], styles: dict[str, ParagraphStyle], usable_width: float, per_row: int = 5) -> Table | None:
    items = [(name, value) for name, value in items if value]
    if not items:
        return None
    cells = [[Paragraph(escape(name.upper()), styles["label"]), Paragraph(escape(value), styles["context_value"])] for name, value in items]
    rows = [cells[i:i + per_row] for i in range(0, len(cells), per_row)]
    rows = [row + [""] * (per_row - len(row)) for row in rows]
    table = Table(rows, colWidths=[usable_width / per_row] * per_row, hAlign="LEFT")
    table.setStyle(TableStyle([
        ("BACKGROUND", (0, 0), (-1, -1), PAPER),
        ("BOX", (0, 0), (-1, -1), .6, LINE),
        ("INNERGRID", (0, 0), (-1, -1), .45, LINE),
        ("VALIGN", (0, 0), (-1, -1), "TOP"),
        ("LEFTPADDING", (0, 0), (-1, -1), 7),
        ("RIGHTPADDING", (0, 0), (-1, -1), 7),
        ("TOPPADDING", (0, 0), (-1, -1), 6),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 6),
    ]))
    return table


def simple_table(header: list[str], rows: list[list], widths: list[float], styles: dict[str, ParagraphStyle], tints: list | None = None) -> Table:
    data = [[Paragraph(escape(title.upper()), styles["label"]) for title in header]] + rows
    table = Table(data, colWidths=widths, repeatRows=1)
    style = [
        ("BACKGROUND", (0, 0), (-1, 0), PAPER),
        ("BOX", (0, 0), (-1, -1), .6, LINE),
        ("INNERGRID", (0, 0), (-1, -1), .4, LINE),
        ("VALIGN", (0, 0), (-1, -1), "TOP"),
        ("LEFTPADDING", (0, 0), (-1, -1), 7),
        ("RIGHTPADDING", (0, 0), (-1, -1), 7),
        ("TOPPADDING", (0, 0), (-1, -1), 5),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 5),
    ]
    for row_index, tint in enumerate(tints or [], 1):
        if tint is not None:
            style.append(("LINEBEFORE", (0, row_index), (0, row_index), 3, tint))
    table.setStyle(TableStyle(style))
    return table


def image_flowable(path: str, max_width: float, max_height: float) -> Image | None:
    try:
        width, height = ImageReader(path).getSize()
        if width <= 0 or height <= 0:
            return None
        scale = min(max_width / width, max_height / height)
        image = Image(path, width=width * scale, height=height * scale)
        image.hAlign = "LEFT"
        return image
    except Exception:
        return None


def page_decor(canvas: Canvas, page_number: int, plan: dict) -> None:
    canvas.saveState()
    canvas.setFillColor(INK)
    canvas.rect(0, PAGE_HEIGHT - 7 * mm, PAGE_WIDTH, 7 * mm, stroke=0, fill=1)
    canvas.setFillColor(BLUE)
    canvas.rect(0, PAGE_HEIGHT - 7 * mm, 38 * mm, 7 * mm, stroke=0, fill=1)
    canvas.setFont(FONT_BOLD, 6.6)
    canvas.setFillColor(WHITE)
    canvas.drawString(12 * mm, PAGE_HEIGHT - 4.6 * mm, "TRADING DESK")
    canvas.setFont(FONT, 6.6)
    canvas.setFillColor(colors.HexColor("#C9D3E3"))
    kind = "Týdenní náhled" if is_weekly(plan) else "Denní náhled"
    canvas.drawRightString(PAGE_WIDTH - 12 * mm, PAGE_HEIGHT - 4.6 * mm, f"{kind}  |  {clean(plan.get('market'))}  |  {clean(plan.get('plan_date'))}")
    canvas.setStrokeColor(LINE)
    canvas.line(12 * mm, 9 * mm, PAGE_WIDTH - 12 * mm, 9 * mm)
    canvas.setFont(FONT, 6.6)
    canvas.setFillColor(MUTED)
    canvas.drawString(12 * mm, 5.8 * mm, "Pracovní obchodní plán, nikoli predikce trhu")
    canvas.drawRightString(PAGE_WIDTH - 12 * mm, 5.8 * mm, f"Strana {page_number}")
    canvas.restoreState()


class DecoratedCanvas(Canvas):
    def __init__(self, *args, plan: dict, **kwargs):
        self._trading_plan = plan
        super().__init__(*args, **kwargs)

    def showPage(self) -> None:
        page_decor(self, self._pageNumber, self._trading_plan)
        super().showPage()


def date_label(plan: dict) -> str:
    date = clean(plan.get("plan_date"), "")
    if not date:
        return "-"
    try:
        from datetime import date as date_cls, timedelta
        year, month, day = (int(part) for part in date.split("-"))
        start = date_cls(year, month, day)
    except ValueError:
        return date
    if is_weekly(plan):
        end = start + timedelta(days=4)
        return f"Týden {start.isocalendar()[1]} · {start.day}. {start.month}. – {end.day}. {end.month}. {end.year}"
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
    styles = make_styles()
    margin_x = 14 * mm
    usable_width = PAGE_WIDTH - 2 * margin_x

    doc = SimpleDocTemplate(
        output_path,
        pagesize=landscape(A4),
        leftMargin=margin_x,
        rightMargin=margin_x,
        topMargin=13 * mm,
        bottomMargin=13 * mm,
        title=f"{'Týdenní' if weekly else 'Denní'} náhled {clean(plan.get('market'))} {clean(plan.get('plan_date'))}",
        author="Trading Desk",
        subject="Obchodní náhled",
    )

    story: list = []
    heading = "TÝDENNÍ TRADING NÁHLED" if weekly else "DENNÍ TRADING NÁHLED"
    title_row = Table([[
        [Paragraph(heading, styles["title"]), Paragraph(escape(f"{clean(plan.get('market'))} · {date_label(plan)}"), styles["meta"])],
        Paragraph(f"{escape(trade_type(plan.get('session')))}<br/>{escape(label(STATUS, plan.get('status'), clean(plan.get('status'))))}", styles["meta_right"]),
    ]], colWidths=[usable_width * .75, usable_width * .25])
    title_row.setStyle(TableStyle([
        ("VALIGN", (0, 0), (-1, -1), "BOTTOM"),
        ("LEFTPADDING", (0, 0), (-1, -1), 0),
        ("RIGHTPADDING", (0, 0), (-1, -1), 0),
        ("TOPPADDING", (0, 0), (-1, -1), 0),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 0),
    ]))
    story.extend([title_row, Spacer(1, 4 * mm), bias_board(plan, styles, usable_width), Spacer(1, 4 * mm)])

    # Krátký popis trhu: vlastní slova tradera, pod nimi automatický pracovní závěr.
    description = clean(plan.get("bias_description"), "")
    conclusion = working_conclusion(plan, refs)
    if description or conclusion:
        content = [Paragraph("CO SE NA TRHU ODEHRÁVÁ", styles["label"])]
        if description:
            content.append(Paragraph(ptext(description), styles["body"]))
        if conclusion:
            content.extend([Spacer(1, 1.5 * mm), Paragraph(f"<b>Pracovní závěr:</b> {escape(conclusion)}", styles["body_small"])])
        story.append(boxed(content, usable_width, WHITE, LINE, padding=9))

    if weekly_context.get("vah") is not None and weekly_context.get("val") is not None:
        source = "value minulého týdne" if weekly else "value minulého týdne z týdenního náhledu"
        poc = f"  ·  POC {fmt_number(weekly_context.get('poc'))}" if weekly_context.get("poc") is not None else ""
        story.extend([Spacer(1, 2 * mm), Paragraph(f"<b>Zóny jsou porovnané s {source}:</b> VAH {fmt_number(weekly_context.get('vah'))}{poc}  ·  VAL {fmt_number(weekly_context.get('val'))}", styles["body_small"])])

    story.extend(section_title("Obchodní zóny", f"{len(zones)} zón · co a za jakých podmínek obchoduji", styles))
    if zones:
        for index, zone in enumerate(zones, 1):
            story.append(zone_card(zone, index, styles, usable_width))
    else:
        story.append(Paragraph("Pro tento náhled nejsou uložené žádné obchodní zóny.", styles["body"]))

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
    context_table = context_grid(context_items, styles, usable_width)
    price_table = context_grid(prices, styles, usable_width, per_row=6)
    if context_table or price_table:
        story.extend(section_title("Market Profile", "profil minulého týdne" if weekly else "profil předchozího dne", styles))
        if price_table:
            story.extend([Paragraph("HODNOTY MINULÉHO TÝDNE" if weekly else "HODNOTY PŘEDCHOZÍHO DNE", styles["label"]), price_table, Spacer(1, 2.5 * mm)])
        if context_table:
            story.append(context_table)

    if refs:
        story.extend(section_title("Reference na dojetí", f"{len(refs)} nedokončených aukcí", styles))
        rows, tints = [], []
        for ref in refs:
            low, high = ref.get("price_low"), ref.get("price_high")
            price = fmt_number(low) if high in (None, "") or high == low else f"{fmt_number(low)} – {fmt_number(high)}"
            done = ref.get("status") == "filled"
            rows.append([
                Paragraph(escape(REF_KIND.get(str(ref.get("kind") or ""), "Reference")), styles["body_small"]),
                Paragraph(escape(price), styles["body_small"]),
                Paragraph("Dojeto" if done else "Na dojetí", styles["body_small"]),
                Paragraph(ptext(ref.get("note"), ""), styles["body_small"]),
            ])
            tints.append(LINE if done else VIOLET)
        story.append(simple_table(["Typ", "Cena", "Stav", "Poznámka"], rows, [usable_width * .2, usable_width * .2, usable_width * .14, usable_width * .46], styles, tints))

    # DiNapoli: trend podle DMA, swingy s Fibonacci úrovněmi a místa, kde se kryjí.
    dinapoli = plan.get("dinapoli") or {}
    dn_levels = dinapoli.get("levels") or []
    dn_clusters = dinapoli.get("clusters") or []
    dma = {"above": "Nad", "below": "Pod"}
    thrust = {"up": "Nahoru", "down": "Dolů"}
    trend_table = context_grid([
        ("3x3 DMA", label(dma, plan.get("dn_dma_3x3"))),
        ("7x5 DMA", label(dma, plan.get("dn_dma_7x5"))),
        ("25x5 DMA", label(dma, plan.get("dn_dma_25x5"))),
        ("Thrust", label(thrust, plan.get("dn_thrust"))),
        ("Vzory", clean(plan.get("dn_patterns"), "")),
    ], styles, usable_width)
    if dn_levels or trend_table or filled(plan.get("dn_notes")):
        swing_names = []
        for level in dn_levels:
            if level.get("swing") not in swing_names:
                swing_names.append(level.get("swing"))
        story.extend(section_title("DiNapoli", f"{len(swing_names)} swingů · {len(dn_clusters)} shod", styles))
        if trend_table:
            story.extend([trend_table, Spacer(1, 2.5 * mm)])
        if dn_levels:
            swings = plan.get("dn_swings") or []
            rows = []
            for index, name in enumerate(swing_names):
                own = {level.get("kind"): level.get("price") for level in dn_levels if level.get("swing") == name}
                source = next((swing for position, swing in enumerate(swings) if (clean(swing.get("label"), "") or f"S{position + 1}") == name), {})
                rows.append([Paragraph(f"<b>{escape(str(name))}</b>", styles["body_small"]),
                             Paragraph(escape(f"{fmt_number(source.get('price_a'))} → {fmt_number(source.get('price_b'))}"), styles["body_small"]),
                             Paragraph(escape(fmt_number(source.get("price_c")) if source.get("price_c") is not None else "-"), styles["body_small"])]
                            + [Paragraph(escape(fmt_number(own[kind]) if kind in own else "-"), styles["body_small"]) for kind in ("F3", "F5", "COP", "OP", "XOP")])
            width = usable_width / 8
            story.append(simple_table(["Swing", "A → B", "C", "F3 .382", "F5 .618", "COP", "OP", "XOP"], rows, [width] * 8, styles))
        if dn_clusters:
            rows, tints = [], []
            for cluster in dn_clusters:
                types = cluster.get("types") or []
                low, high = cluster.get("low"), cluster.get("high")
                rows.append([Paragraph(escape(" + ".join("Agreement" if kind == "agreement" else "Confluence" for kind in types)), styles["body_small"]),
                             Paragraph(escape(fmt_number(low) if low == high else f"{fmt_number(low)} – {fmt_number(high)}"), styles["body_small"]),
                             Paragraph(escape(" · ".join(cluster.get("members") or [])), styles["body_small"])])
                tints.append(AMBER if "agreement" in types else GREEN)
            story.extend([Spacer(1, 2.5 * mm), simple_table(["Shoda", "Pásmo", "Složení"], rows, [usable_width * .22, usable_width * .22, usable_width * .56], styles, tints)])
        if filled(plan.get("dn_notes")):
            story.extend([Spacer(1, 2 * mm), Paragraph(ptext(plan.get("dn_notes")), styles["body_small"])])

    if levels:
        story.extend(section_title("Klíčové levely", f"{len(levels)} horizontálních úrovní", styles))
        rows, tints = [], []
        for index, level in enumerate(levels, 1):
            kind = str(level.get("kind") or "")
            rows.append([
                Paragraph(escape(clean(level.get("name"), f"Level {index}")), styles["body_small"]),
                Paragraph(escape(fmt_number(level.get("price"))), styles["body_small"]),
                Paragraph(escape(LEVEL_KIND.get(kind, "-")), styles["body_small"]),
                Paragraph(ptext(level.get("source"), ""), styles["body_small"]),
                Paragraph(ptext(preview(level.get("note"), 140), ""), styles["body_small"]),
            ])
            tints.append(GREEN if kind == "support" else RED if kind == "resistance" else AMBER if kind == "pivot" else None)
        story.append(simple_table(["Level", "Cena", "Charakter", "Zdroj / shoda", "Poznámka"], rows, [usable_width * .24, usable_width * .12, usable_width * .13, usable_width * .21, usable_width * .30], styles, tints))

    if ideas:
        story.extend(section_title("Potenciální obchody a targety", f"{len(ideas)} scénářů", styles))
        rows, tints = [], []
        for index, idea in enumerate(ideas, 1):
            direction = str(idea.get("direction") or "")
            rows.append([
                Paragraph(f"<b>{escape(clean(idea.get('name'), f'Scénář {index}'))}</b><br/>{escape(clean(idea.get('trigger'), ''))}", styles["body_small"]),
                Paragraph(escape(clean(idea.get("zone_name"))), styles["body_small"]),
                Paragraph("Long" if direction == "long" else "Short", styles["body_small"]),
                Paragraph(escape(f"{fmt_number(idea.get('entry_price'))} / {fmt_number(idea.get('stop_loss'))}"), styles["body_small"]),
                Paragraph(escape(f"{fmt_number(idea.get('tp1'))} / {fmt_number(idea.get('tp2'))} / {fmt_number(idea.get('final_tp'))}"), styles["body_small"]),
                Paragraph(escape(label(IDEA_STATUS, idea.get("status"), "-")), styles["body_small"]),
            ])
            tints.append(GREEN if direction == "long" else RED)
        story.append(simple_table(["Scénář", "Zóna", "Směr", "Entry / SL", "TP1 / TP2 / finální", "Stav"], rows, [usable_width * .33, usable_width * .17, usable_width * .08, usable_width * .14, usable_width * .18, usable_width * .10], styles, tints))

    notes = [
        ("Co bias potvrzuje", plan.get("bias_confirm"), BLUE_SOFT, BLUE),
        ("Co bias ruší", plan.get("bias_invalidation"), RED_SOFT, RED),
        ("Red news", plan.get("important_news"), AMBER_SOFT, AMBER),
        ("No-trade podmínky", plan.get("no_trade_conditions"), RED_SOFT, RED),
        ("Další poznámky", plan.get("general_notes"), PAPER, LINE),
    ]
    visible_notes = [item for item in notes if filled(item[1])]
    custom_rows = plan.get("custom_readable") or []
    if custom_rows:
        story.extend(section_title("Vlastní pole", f"{len(custom_rows)} vyplněných", styles))
        rows = [[Paragraph(f"<b>{escape(str(row.get('label')))}</b>", styles["body_small"]), Paragraph(ptext(row.get("value")), styles["body_small"])] for row in custom_rows]
        story.append(simple_table(["Pole", "Hodnota"], rows, [usable_width * .32, usable_width * .68], styles))

    if visible_notes:
        story.extend(section_title("Potvrzení, rizika a poznámky", "exekuční rámec", styles))
        cells = [boxed([Paragraph(escape(title.upper()), styles["label"]), Paragraph(ptext(value), styles["body_small"])], usable_width / 2 - 4, background, border) for title, value, background, border in visible_notes]
        rows = [cells[i:i + 2] + ([""] if len(cells[i:i + 2]) == 1 else []) for i in range(0, len(cells), 2)]
        notes_table = Table(rows, colWidths=[usable_width / 2] * 2)
        notes_table.setStyle(TableStyle([
            ("VALIGN", (0, 0), (-1, -1), "TOP"),
            ("LEFTPADDING", (0, 0), (-1, -1), 2),
            ("RIGHTPADDING", (0, 0), (-1, -1), 2),
            ("TOPPADDING", (0, 0), (-1, -1), 2),
            ("BOTTOMPADDING", (0, 0), (-1, -1), 2),
        ]))
        story.append(notes_table)

    if trades:
        story.extend(section_title("Realizované obchody navázané na náhled", f"{len(trades)} záznamů", styles))
        rows = [[
            Paragraph(escape(clean(trade.get("trade_date"))), styles["body_small"]),
            Paragraph(escape(clean(trade.get("strategy"))), styles["body_small"]),
            Paragraph("Long" if trade.get("direction") == "long" else "Short", styles["body_small"]),
            Paragraph(escape(f"{fmt_number(trade.get('entry_price'))} / {fmt_number(trade.get('exit_price'))}"), styles["body_small"]),
            Paragraph(escape(f"{fmt_number(trade.get('result_r'))} R"), styles["body_small"]),
            Paragraph(ptext(trade.get("notes"), ""), styles["body_small"]),
        ] for trade in trades]
        story.append(simple_table(["Datum", "Setup", "Směr", "Entry / Exit", "Výsledek", "Poznámka"], rows, [usable_width * .1, usable_width * .2, usable_width * .08, usable_width * .15, usable_width * .1, usable_width * .37], styles))

    valid_images = []
    for screenshot in screenshots:
        image = image_flowable(str(screenshot.get("path", "")), usable_width / 2 - 16, 78 * mm)
        if image is None:
            continue
        caption = clean(screenshot.get("caption"), screenshot.get("original_name") or "Graf")
        valid_images.append([image, Spacer(1, 1.5 * mm), Paragraph(escape(caption), styles["meta"])])
    for group_index in range(0, len(valid_images), 2):
        story.append(PageBreak())
        story.extend(section_title("Grafy", f"{len(valid_images)} screenshotů", styles))
        group = valid_images[group_index:group_index + 2]
        if len(group) == 1:
            group.append("")
        gallery = Table([group], colWidths=[usable_width / 2] * 2)
        gallery.setStyle(TableStyle([
            ("BOX", (0, 0), (-1, -1), .5, LINE),
            ("INNERGRID", (0, 0), (-1, -1), .4, LINE),
            ("VALIGN", (0, 0), (-1, -1), "TOP"),
            ("LEFTPADDING", (0, 0), (-1, -1), 7),
            ("RIGHTPADDING", (0, 0), (-1, -1), 7),
            ("TOPPADDING", (0, 0), (-1, -1), 7),
            ("BOTTOMPADDING", (0, 0), (-1, -1), 7),
        ]))
        story.append(gallery)

    doc.build(story, canvasmaker=lambda *args, **kwargs: DecoratedCanvas(*args, plan=plan, **kwargs))


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
