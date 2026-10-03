"""Společný vzhled PDF Trading Desku (denní náhled, obchodní plán).

Stejný jazyk jako aplikace: písma Fraunces (nadpisy) a Manrope (text), zlatá na inkoustové
a teplé papírové tóny. PDF je světlé kvůli tisku; tmavý je jen úvodní pás první strany.
Statické řezy písem (OFL) leží v lib/fonts; když chybí, použije se DejaVu.
"""

from __future__ import annotations

import os
from pathlib import Path
from xml.sax.saxutils import escape

from reportlab.lib import colors
from reportlab.lib.enums import TA_CENTER, TA_LEFT, TA_RIGHT
from reportlab.lib.styles import ParagraphStyle
from reportlab.lib.units import mm
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.pdfgen.canvas import Canvas
from reportlab.platypus import CondPageBreak, Flowable, Paragraph, Spacer, Table, TableStyle

FONT_DIR = Path(__file__).resolve().parent / "lib" / "fonts"


def _register() -> dict[str, str]:
    files = {
        "sans": "Manrope-Regular.ttf",
        "semi": "Manrope-SemiBold.ttf",
        "bold": "Manrope-ExtraBold.ttf",
        "serif": "Fraunces-SemiBold.ttf",
        "serif_regular": "Fraunces-Regular.ttf",
    }
    if all((FONT_DIR / name).is_file() for name in files.values()):
        names = {}
        for key, name in files.items():
            font = "TD-" + name[:-4]
            pdfmetrics.registerFont(TTFont(font, str(FONT_DIR / name)))
            names[key] = font
        # <b> v textu: obyčejný řez ztuční na SemiBold, SemiBold na ExtraBold.
        pdfmetrics.registerFontFamily(names["sans"], normal=names["sans"], bold=names["semi"], italic=names["sans"], boldItalic=names["semi"])
        pdfmetrics.registerFontFamily(names["semi"], normal=names["semi"], bold=names["bold"], italic=names["semi"], boldItalic=names["bold"])
        pdfmetrics.registerFontFamily(names["serif_regular"], normal=names["serif_regular"], bold=names["serif"], italic=names["serif_regular"], boldItalic=names["serif"])
        return names
    for regular, bold in (
        ("/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf", "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf"),
        ("C:/Windows/Fonts/arial.ttf", "C:/Windows/Fonts/arialbd.ttf"),
        ("/System/Library/Fonts/Supplemental/Arial.ttf", "/System/Library/Fonts/Supplemental/Arial Bold.ttf"),
    ):
        if os.path.isfile(regular) and os.path.isfile(bold):
            pdfmetrics.registerFont(TTFont("TD-Fallback", regular))
            pdfmetrics.registerFont(TTFont("TD-Fallback-Bold", bold))
            pdfmetrics.registerFontFamily("TD-Fallback", normal="TD-Fallback", bold="TD-Fallback-Bold", italic="TD-Fallback", boldItalic="TD-Fallback-Bold")
            return {"sans": "TD-Fallback", "semi": "TD-Fallback-Bold", "bold": "TD-Fallback-Bold", "serif": "TD-Fallback-Bold", "serif_regular": "TD-Fallback"}
    return {"sans": "Helvetica", "semi": "Helvetica-Bold", "bold": "Helvetica-Bold", "serif": "Times-Bold", "serif_regular": "Times-Roman"}


FONTS = _register()
SANS, SEMI, BOLD, SERIF, SERIF_REGULAR = FONTS["sans"], FONTS["semi"], FONTS["bold"], FONTS["serif"], FONTS["serif_regular"]


def mix(color: str, amount: float, base: str = "#FFFFFF") -> colors.Color:
    """Barva smíchaná s papírem (PDF pro tisk nemá průhlednost)."""
    a, b = colors.HexColor(color), colors.HexColor(base)
    return colors.Color(a.red * amount + b.red * (1 - amount), a.green * amount + b.green * (1 - amount), a.blue * amount + b.blue * (1 - amount))


# Světlý motiv aplikace (styles.css, [data-theme="light"]).
INK = colors.HexColor("#17130C")
INK_2 = colors.HexColor("#4B4438")
MUTED = colors.HexColor("#7C7364")
LINE = colors.HexColor("#E3DACB")
LINE_SOFT = colors.HexColor("#ECE5D8")
PAPER = colors.HexColor("#FAF7F1")
PAPER_2 = colors.HexColor("#F4EFE5")
WHITE = colors.white
NIGHT = colors.HexColor("#14110C")
NIGHT_2 = colors.HexColor("#1C1812")
IVORY = colors.HexColor("#F6F0E4")
IVORY_MUTED = colors.HexColor("#B9AE98")
GOLD = colors.HexColor("#A8792B")
GOLD_DEEP = colors.HexColor("#86601D")
GOLD_LIGHT = colors.HexColor("#D6B36F")
GOLD_SOFT = mix("#A8792B", .1)
GOLD_LINE = mix("#A8792B", .38)
GOLD_GRADIENT = [colors.HexColor("#F0D9A2"), colors.HexColor("#D0A95F"), colors.HexColor("#B08742")]
GREEN = colors.HexColor("#0D8A61")
GREEN_SOFT = mix("#0D8A61", .1)
RED = colors.HexColor("#C13A4C")
RED_SOFT = mix("#C13A4C", .09)
AMBER = colors.HexColor("#B5600E")
AMBER_SOFT = mix("#B5600E", .11)
VIOLET = colors.HexColor("#6A4FD0")
VIOLET_SOFT = mix("#6A4FD0", .1)
BLUE = colors.HexColor("#2F63C9")
BLUE_SOFT = mix("#2F63C9", .1)
# Na tmavém pásu: barvy tmavého motivu.
NIGHT_GREEN = colors.HexColor("#3FD3A1")
NIGHT_RED = colors.HexColor("#F27682")
NIGHT_AMBER = colors.HexColor("#F59B55")

DIRECTION = {
    "long": (GREEN, GREEN_SOFT, "LONG"),
    "short": (RED, RED_SOFT, "SHORT"),
    "both": (BLUE, BLUE_SOFT, "LONG I SHORT"),
    "balance": (AMBER, AMBER_SOFT, "BALANCE"),
    "neutral": (AMBER, AMBER_SOFT, "BALANCE"),
}


def direction_colors(value: object) -> tuple:
    return DIRECTION.get(str(value or ""), (MUTED, PAPER_2, "—"))


def clean(value: object, fallback: str = "") -> str:
    if value is None:
        return fallback
    text = str(value).strip()
    return text if text else fallback


def filled(value: object) -> bool:
    return clean(value) != ""


def ptext(value: object, fallback: str = "") -> str:
    """Text pro Paragraph: escapovaný (žádné značky od uživatele) a s konci řádků."""
    return escape(clean(value, fallback)).replace("\n", "<br/>")


def preview(value: object, limit: int = 260) -> str:
    text = clean(value)
    return text if len(text) <= limit else text[: limit - 1].rstrip() + "…"


def fmt_number(value: object, digits: int = 4) -> str:
    if value is None or value == "":
        return "–"
    try:
        number = float(value)
    except (TypeError, ValueError):
        return clean(value, "–")
    if number.is_integer():
        return f"{number:,.0f}".replace(",", " ")
    return f"{number:,.{digits}f}".rstrip("0").rstrip(".").replace(",", " ").replace(".", ",")


def fmt_money(value: object, currency: str = "USD") -> str:
    if value is None or value == "":
        return "–"
    try:
        number = float(value)
    except (TypeError, ValueError):
        return clean(value, "–")
    sign = "−" if number < 0 else ""
    text = f"{abs(number):,.2f}".replace(",", " ").replace(".", ",")
    if text.endswith(",00"):
        text = text[:-3]
    symbol = {"USD": "$", "EUR": "€", "CZK": "Kč", "GBP": "£"}.get(currency.upper(), currency.upper())
    return f"{sign}{symbol}{text}" if symbol in {"$", "£", "€"} else f"{sign}{text} {symbol}"


def plural(count: int, one: str, few: str, many: str) -> str:
    return f"{count} {one if count == 1 else few if 2 <= count <= 4 else many}"


def make_styles() -> dict[str, ParagraphStyle]:
    def style(name: str, font: str, size: float, leading: float, color=INK, **extra) -> ParagraphStyle:
        return ParagraphStyle(name, fontName=font, fontSize=size, leading=leading, textColor=color, **extra)

    return {
        "display": style("display", SERIF, 22, 26),
        "h2": style("h2", SERIF, 15.5, 19),
        "h3": style("h3", SERIF, 12.2, 15),
        "card_title": style("card_title", SEMI, 10.6, 13.4),
        "body": style("body", SANS, 9.4, 13.6),
        "body_strong": style("body_strong", SEMI, 9.4, 13.6),
        "lead": style("lead", SERIF_REGULAR, 11.5, 16, INK),
        "small": style("small", SANS, 8.2, 11.4, INK_2),
        "small_strong": style("small_strong", SEMI, 8.2, 11.4, INK),
        "tiny": style("tiny", SANS, 7.2, 9.6, MUTED),
        "label": style("label", BOLD, 6.5, 8.6, MUTED),
        "label_center": style("label_center", BOLD, 6.5, 8.6, MUTED, alignment=TA_CENTER),
        "value": style("value", SEMI, 10, 12.6),
        "value_big": style("value_big", BOLD, 15, 18),
        "price": style("price", BOLD, 12.5, 15, INK, alignment=TA_RIGHT),
        "pill": style("pill", BOLD, 7.2, 9, WHITE, alignment=TA_CENTER),
        "center": style("center", SANS, 8.2, 11, INK_2, alignment=TA_CENTER),
        "right": style("right", SANS, 8.2, 11, INK_2, alignment=TA_RIGHT),
        "missing": style("missing", SANS, 8.6, 11.8, AMBER),
        "cond": style("cond", SANS, 8.8, 12.4, INK),
        "cond_label": style("cond_label", BOLD, 6.6, 8.6, MUTED),
    }


STYLES = make_styles()


def tinted(style_name: str, color, name: str | None = None) -> ParagraphStyle:
    base = STYLES[style_name]
    return ParagraphStyle(name or f"{style_name}-{color}", parent=base, textColor=color)


def para(text: str, style_name: str = "body", color=None) -> Paragraph:
    return Paragraph(text, tinted(style_name, color) if color is not None else STYLES[style_name])


class Tracked(Flowable):
    """Řádek proložených verzálek (jako eyebrow v aplikaci)."""

    def __init__(self, text: str, color=GOLD, size: float = 6.8, spacing: float = 1.15, font: str | None = None, space_after: float = 2.2):
        super().__init__()
        self.text, self.color, self.size, self.spacing = text.upper(), color, size, spacing
        self.font = font or BOLD
        self.space_after = space_after

    def wrap(self, available_width: float, available_height: float):
        return available_width, self.size + self.space_after

    def draw(self) -> None:
        tracked_text(self.canv, 0, self.space_after, self.text, self.font, self.size, self.spacing, self.color)


def tracked_text(canvas: Canvas, x: float, y: float, text: str, font: str, size: float, spacing: float, color, *more: tuple) -> None:
    """Proložený text; prostrkání (Tc) se po vykreslení vrátí, jinak by se přeneslo na další text."""
    canvas.saveState()
    obj = canvas.beginText(x, y)
    obj.setFont(font, size)
    obj.setCharSpace(spacing)
    obj.setFillColor(color)
    obj.textOut(text)
    for extra_text, extra_font, extra_size, extra_spacing, extra_color in more:
        obj.setFont(extra_font, extra_size)
        obj.setCharSpace(extra_spacing)
        obj.setFillColor(extra_color)
        obj.textOut(extra_text)
    canvas.drawText(obj)
    canvas.restoreState()


class Rule(Flowable):
    """Zlatá krátká linka a vlasová čára přes celou šířku pod nadpisem sekce."""

    def __init__(self, gold_width: float = 16 * mm):
        super().__init__()
        self.gold_width = gold_width

    def wrap(self, available_width: float, available_height: float):
        self.width = available_width
        return available_width, 2.4

    def draw(self) -> None:
        canvas = self.canv
        canvas.setStrokeColor(LINE)
        canvas.setLineWidth(.6)
        canvas.line(0, 1, self.width, 1)
        canvas.setStrokeColor(GOLD)
        canvas.setLineWidth(1.6)
        canvas.line(0, 1, self.gold_width, 1)


def section(title: str, kicker: str = "", number: str | None = None) -> list:
    heading = f'<font color="#A8792B">{escape(number)}</font>&nbsp;&nbsp;{escape(title)}' if number else escape(title)
    # Nadpis nezůstane sám na konci strany: když zbývá málo místa, sekce začne na další.
    parts: list = [CondPageBreak(42 * mm), Spacer(1, 5.5 * mm)]
    if kicker:
        parts.append(Tracked(kicker))
    parts.extend([Paragraph(heading, STYLES["h2"]), Spacer(1, 2 * mm), Rule(), Spacer(1, 3.4 * mm)])
    return parts


def frame(content, width: float, background=WHITE, border=LINE, padding: float = 8, radius: float = 5, accent=None, accent_width: float = 3.2, valign: str = "TOP") -> Table:
    """Karta se zaoblenými rohy; volitelný barevný pruh vlevo."""
    if accent is None:
        table = Table([[content]], colWidths=[width])
        commands = [("BACKGROUND", (0, 0), (-1, -1), background), ("LEFTPADDING", (0, 0), (-1, -1), padding), ("RIGHTPADDING", (0, 0), (-1, -1), padding)]
    else:
        table = Table([["", content]], colWidths=[accent_width, width - accent_width])
        commands = [
            ("BACKGROUND", (0, 0), (0, -1), accent),
            ("BACKGROUND", (1, 0), (1, -1), background),
            ("LEFTPADDING", (0, 0), (0, -1), 0),
            ("RIGHTPADDING", (0, 0), (0, -1), 0),
            ("LEFTPADDING", (1, 0), (1, -1), padding),
            ("RIGHTPADDING", (1, 0), (1, -1), padding),
        ]
    commands += [
        ("TOPPADDING", (0, 0), (-1, -1), padding - 1),
        ("BOTTOMPADDING", (0, 0), (-1, -1), padding - 1),
        ("VALIGN", (0, 0), (-1, -1), valign),
        ("ROUNDEDCORNERS", [radius] * 4),
    ]
    if border is not None:
        commands.append(("BOX", (0, 0), (-1, -1), .6, border))
    table.setStyle(TableStyle(commands))
    return table


def pill(text: str, foreground=WHITE, background=GOLD, width: float = 26 * mm, border=None) -> Table:
    table = Table([[Paragraph(escape(text), tinted("pill", foreground))]], colWidths=[width])
    commands = [
        ("BACKGROUND", (0, 0), (-1, -1), background),
        ("LEFTPADDING", (0, 0), (-1, -1), 4),
        ("RIGHTPADDING", (0, 0), (-1, -1), 4),
        ("TOPPADDING", (0, 0), (-1, -1), 3),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 3.4),
        ("VALIGN", (0, 0), (-1, -1), "MIDDLE"),
        ("ROUNDEDCORNERS", [7] * 4),
    ]
    if border is not None:
        commands.append(("BOX", (0, 0), (-1, -1), .6, border))
    table.setStyle(TableStyle(commands))
    return table


def grid(cells: list, width: float, columns: int, gap: float = 3 * mm) -> Table:
    """Mřížka karet s mezerou; prázdná místa v posledním řádku zůstanou volná."""
    column = (width - gap * (columns - 1)) / columns
    rows = [cells[i:i + columns] for i in range(0, len(cells), columns)]
    rows = [row + [""] * (columns - len(row)) for row in rows]
    widths = []
    for index in range(columns):
        widths.append(column + (gap if index < columns - 1 else 0))
    table = Table(rows, colWidths=widths)
    commands = [
        ("VALIGN", (0, 0), (-1, -1), "TOP"),
        ("LEFTPADDING", (0, 0), (-1, -1), 0),
        ("RIGHTPADDING", (0, 0), (-1, -1), 0),
        ("TOPPADDING", (0, 0), (-1, -1), 0),
        ("BOTTOMPADDING", (0, 0), (-1, -1), gap),
    ]
    for index in range(columns - 1):
        commands.append(("RIGHTPADDING", (index, 0), (index, -1), gap))
    table.setStyle(TableStyle(commands))
    return table


def facts(items: list[tuple[str, str]], width: float, columns: int = 5, background=PAPER) -> Table | None:
    """Dlaždice štítek + hodnota (kontext trhu, parametry účtu)."""
    items = [(name, value) for name, value in items if clean(value)]
    if not items:
        return None
    columns = min(columns, len(items))
    column = width / columns
    cells = [[Paragraph(escape(name.upper()), STYLES["label"]), Paragraph(escape(value), STYLES["value"])] for name, value in items]
    rows = [cells[i:i + columns] for i in range(0, len(cells), columns)]
    rows = [row + [""] * (columns - len(row)) for row in rows]
    table = Table(rows, colWidths=[column] * columns)
    table.setStyle(TableStyle([
        ("BACKGROUND", (0, 0), (-1, -1), background),
        ("LINEAFTER", (0, 0), (-2, -1), .5, LINE),
        ("LINEBELOW", (0, 0), (-1, -2), .5, LINE),
        ("BOX", (0, 0), (-1, -1), .6, LINE),
        ("VALIGN", (0, 0), (-1, -1), "TOP"),
        ("LEFTPADDING", (0, 0), (-1, -1), 8),
        ("RIGHTPADDING", (0, 0), (-1, -1), 8),
        ("TOPPADDING", (0, 0), (-1, -1), 6.5),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 7),
        ("ROUNDEDCORNERS", [5] * 4),
    ]))
    return table


def data_table(header: list[str], rows: list[list], widths: list[float], accents: list | None = None) -> Table:
    """Tabulka bez mřížky: verzálková hlavička, vlasové linky mezi řádky, barevná tečka vlevo."""
    accents = accents or []
    head = [Paragraph(escape(title.upper()), STYLES["label"]) for title in header]
    body = []
    for index, row in enumerate(rows):
        accent = accents[index] if index < len(accents) else None
        first = row[0]
        if accent is not None and isinstance(first, Paragraph):
            hexcolor = accent.hexval()[2:] if hasattr(accent, "hexval") else "A8792B"
            first = Paragraph(f'<font color="#{hexcolor}" size="11">•</font>&nbsp;{first.text}', first.style)
        body.append([first] + row[1:])
    table = Table([head] + body, colWidths=widths, repeatRows=1)
    table.setStyle(TableStyle([
        ("BACKGROUND", (0, 0), (-1, 0), PAPER_2),
        ("LINEBELOW", (0, 0), (-1, 0), .6, LINE),
        ("LINEBELOW", (0, 1), (-1, -2), .4, LINE_SOFT),
        ("BOX", (0, 0), (-1, -1), .6, LINE),
        ("VALIGN", (0, 0), (-1, -1), "TOP"),
        ("LEFTPADDING", (0, 0), (-1, -1), 7),
        ("RIGHTPADDING", (0, 0), (-1, -1), 7),
        ("TOPPADDING", (0, 0), (-1, -1), 5.2),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 5.6),
        ("ROUNDEDCORNERS", [5] * 4),
    ]))
    return table


class CheckBox(Flowable):
    """Prázdný čtvereček k odškrtnutí na vytištěném plánu."""

    def __init__(self, size: float = 7.4, color=GOLD):
        super().__init__()
        self.size, self.color = size, color

    def wrap(self, available_width: float, available_height: float):
        return self.size, self.size + 2

    def draw(self) -> None:
        self.canv.setStrokeColor(self.color)
        self.canv.setLineWidth(.8)
        self.canv.roundRect(0, 0.6, self.size, self.size, 1.4, stroke=1, fill=0)


def checklist(lines: list[str], width: float, columns: int = 1) -> Table | None:
    """Zaškrtávací seznam pro tisk (prázdný čtvereček před každou položkou)."""
    lines = [line for line in (clean(item) for item in lines) if line]
    if not lines:
        return None
    column = width / columns
    cells = []
    for line in lines:
        inner = Table([[CheckBox(), Paragraph(escape(line), STYLES["small"])]], colWidths=[5 * mm, column - 5 * mm - 6])
        inner.setStyle(TableStyle([
            ("VALIGN", (0, 0), (-1, -1), "TOP"),
            ("LEFTPADDING", (0, 0), (-1, -1), 0),
            ("RIGHTPADDING", (0, 0), (-1, -1), 0),
            ("TOPPADDING", (0, 0), (-1, -1), 0),
            ("BOTTOMPADDING", (0, 0), (-1, -1), 0),
        ]))
        cells.append(inner)
    rows = [cells[i:i + columns] for i in range(0, len(cells), columns)]
    rows = [row + [""] * (columns - len(row)) for row in rows]
    table = Table(rows, colWidths=[column] * columns)
    table.setStyle(TableStyle([
        ("VALIGN", (0, 0), (-1, -1), "TOP"),
        ("LEFTPADDING", (0, 0), (-1, -1), 0),
        ("RIGHTPADDING", (0, 0), (-1, -1), 6),
        ("TOPPADDING", (0, 0), (-1, -1), 2),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 2),
    ]))
    return table


def lines_of(value: object) -> list[str]:
    return [line.strip(" \t-•*·") for line in clean(value).splitlines() if line.strip(" \t-•*·")]


# ---------------------------------------------------------------- stránka

def brand_mark(canvas: Canvas, x: float, y: float, size: float = 9) -> None:
    """Tři zlaté sloupky jako v logu aplikace."""
    widths = size * .2
    heights = [size * .5, size * .78, size]
    canvas.setFillColor(GOLD_LIGHT)
    for index, height in enumerate(heights):
        canvas.roundRect(x + index * (widths + size * .16), y, widths, height, widths / 2, stroke=0, fill=1)


def gradient_bar(canvas: Canvas, x: float, y: float, width: float, height: float) -> None:
    canvas.saveState()
    path = canvas.beginPath()
    path.rect(x, y, width, height)
    canvas.clipPath(path, stroke=0, fill=0)
    canvas.linearGradient(x, y, x + width, y, GOLD_GRADIENT, [0, .46, 1], extend=False)
    canvas.restoreState()


class DocumentCanvas(Canvas):
    """Plátno, které záhlaví a zápatí kreslí až na konci, aby znalo počet stran."""

    def __init__(self, *args, decorate=None, **kwargs):
        super().__init__(*args, **kwargs)
        self._pages: list[dict] = []
        self._decorate = decorate

    def showPage(self) -> None:
        self._pages.append(dict(self.__dict__))
        self._startPage()

    def save(self) -> None:
        total = len(self._pages)
        for state in self._pages:
            self.__dict__.update(state)
            if self._decorate is not None:
                self._decorate(self, self._pageNumber, total)
            super().showPage()
        super().save()


def cover_band(canvas: Canvas, page_width: float, page_height: float, height: float, kicker: str, title: str, subtitle: str, meta: list[tuple[str, str]], margin: float) -> None:
    """Tmavý úvodní pás první strany: značka, druh dokumentu, název a údaje vpravo."""
    canvas.saveState()
    top = page_height
    canvas.setFillColor(NIGHT)
    canvas.rect(0, top - height, page_width, height, stroke=0, fill=1)
    # Jemná záře v rohu jako pozadí aplikace; jen uvnitř pásu.
    canvas.saveState()
    clip = canvas.beginPath()
    clip.rect(0, top - height, page_width, height)
    canvas.clipPath(clip, stroke=0, fill=0)
    canvas.setFillColor(NIGHT_2)
    canvas.circle(page_width - 22 * mm, top - 4 * mm, 46 * mm, stroke=0, fill=1)
    canvas.restoreState()
    gradient_bar(canvas, 0, top - height - 1.2, page_width, 1.2)

    brand_mark(canvas, margin, top - 13 * mm, 8.5)
    tracked_text(canvas, margin + 7.5 * mm, top - 12.2 * mm, "TRADING DESK", BOLD, 7, 1.6, GOLD_LIGHT, ("   ·   " + kicker.upper(), BOLD, 7, 1.6, IVORY_MUTED))

    canvas.setFillColor(IVORY)
    size = 24
    while size > 15 and canvas.stringWidth(title, SERIF, size) > page_width * .58:
        size -= 1
    canvas.setFont(SERIF, size)
    canvas.drawString(margin, top - 24.5 * mm, title)
    if subtitle:
        canvas.setFont(SANS, 9.6)
        canvas.setFillColor(IVORY_MUTED)
        canvas.drawString(margin, top - 30.5 * mm, subtitle)

    x = page_width - margin
    for name, value in reversed([item for item in meta if clean(item[1])]):
        value_width = max(canvas.stringWidth(value, SEMI, 9.6), canvas.stringWidth(name.upper(), BOLD, 6) + len(name) * .9)
        canvas.setFont(SEMI, 9.6)
        canvas.setFillColor(IVORY)
        canvas.drawRightString(x, top - 24.8 * mm, value)
        tracked_text(canvas, x - canvas.stringWidth(name.upper(), BOLD, 6) - len(name) * .9, top - 19.6 * mm, name.upper(), BOLD, 6, .9, GOLD_LIGHT)
        x -= value_width + 9 * mm
        if x < page_width * .52:
            break
    canvas.restoreState()


def running_header(canvas: Canvas, page_width: float, page_height: float, margin: float, left: str, right: str) -> None:
    canvas.saveState()
    y = page_height - 10.5 * mm
    brand_mark(canvas, margin, y - .6, 6.5)
    tracked_text(canvas, margin + 6 * mm, y, "TRADING DESK", BOLD, 6.4, 1.3, GOLD, ("   " + left, SEMI, 7.4, 0, MUTED))
    canvas.setFont(SANS, 7.4)
    canvas.setFillColor(MUTED)
    canvas.drawRightString(page_width - margin, y, right)
    canvas.setStrokeColor(LINE)
    canvas.setLineWidth(.6)
    canvas.line(margin, y - 3.2 * mm, page_width - margin, y - 3.2 * mm)
    canvas.setStrokeColor(GOLD)
    canvas.setLineWidth(1.4)
    canvas.line(margin, y - 3.2 * mm, margin + 12 * mm, y - 3.2 * mm)
    canvas.restoreState()


def running_footer(canvas: Canvas, page_width: float, margin: float, note: str, page: int, total: int) -> None:
    canvas.saveState()
    canvas.setStrokeColor(LINE)
    canvas.setLineWidth(.5)
    canvas.line(margin, 10.5 * mm, page_width - margin, 10.5 * mm)
    canvas.setFont(SANS, 6.8)
    canvas.setFillColor(MUTED)
    canvas.drawString(margin, 6.6 * mm, note)
    canvas.setFont(SEMI, 7.2)
    canvas.setFillColor(INK_2)
    label = f"{page} / {total}"
    canvas.drawRightString(page_width - margin, 6.6 * mm, label)
    canvas.setFillColor(GOLD)
    canvas.drawRightString(page_width - margin - canvas.stringWidth(label, SEMI, 7.2) - 2.2 * mm, 6.6 * mm, "Strana")
    canvas.restoreState()


__all__ = [name for name in dir() if not name.startswith("_")] + ["TA_LEFT", "TA_CENTER", "TA_RIGHT", "mm"]
