import tempfile
import unittest
from pathlib import Path
import sys

from PIL import Image, ImageDraw

try:
    from pypdf import PdfReader
except ImportError:  # Text se ověří jen tam, kde je pypdf; samotné vytvoření PDF vždy.
    PdfReader = None


ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from export_plan import build_pdf, working_conclusion  # noqa: E402


def payload(chart: Path, plan_type: str = "weekly") -> dict:
    return {
        "plan": {
            "plan_type": plan_type,
            "plan_date": "2026-09-28",
            "market": "ES",
            "session": "hybrid_intraday",
            "status": "ready",
            "bias": "long",
            "bias_description": "Akceptace vyšších cen, preferuji návrat do týdenní VAL.",
            "bias_confirm": "Developing value roste a VAL drží jako support.",
            "pa_monthly": "long",
            "pa_weekly": "long",
            "pa_weekly_note": "HH/HL",
            "pa_daily": "balance",
            "mp_weekly": "long",
            "mp_daily": "",
            "profile_shape": "p",
            "previous_close": "upper",
            "value_area": "rising",
            "vpoc": "rising",
            "auction": "imbalance",
            "ref_vah": 6712.25,
            "ref_poc": 6690.5,
            "ref_val": 6668.75,
            "ref_close": 6705,
            "eu_open": "",
            "ny_open": "",
            "important_news": "16:00 ISM",
            "weekly_context": {"source": "self", "vah": 6712.25, "val": 6668.75, "poc": 6690.5},
        },
        "zones": [
            {
                "name": "Zóna A · týdenní VAL",
                "direction": "long",
                "price_low": 6664,
                "price_high": 6671,
                "priority": "A",
                "source": "VAL, SP",
                "long_entry": "Odmítnutí nižších cen a návrat nad 6671.",
                "long_skip": "Akceptace pod VAL.",
                "stop_loss": 6658,
                "tp1": 6690,
                "va_context": {"key": "at_val", "text": "Ve VAL předchozího týdne"},
            },
            {
                "name": "Zóna B · nad VAH",
                "direction": "both",
                "price_low": 6730,
                "price_high": 6738,
                "long_entry": "Akceptace nad 6738.",
                "short_entry": "Selhání nad poor high.",
                "short_skip": "",
                "va_context": {"key": "above_vah", "text": "Nad VAH předchozího týdne"},
            },
        ],
        "refs": [{"kind": "poor_high", "price_low": 6738, "status": "open", "note": "Pátek"}],
        "ideas": [],
        "trades": [],
        "screenshots": [{"path": str(chart), "caption": "Týdenní profil"}],
    }


class PdfExportTests(unittest.TestCase):
    def build(self, plan_type: str) -> tuple[Path, tempfile.TemporaryDirectory]:
        temporary = tempfile.TemporaryDirectory()
        directory = Path(temporary.name)
        chart = directory / "chart.png"
        canvas = Image.new("RGB", (1600, 900), "#101820")
        ImageDraw.Draw(canvas).line([(80, 700), (420, 570), (1050, 300), (1510, 210)], fill="#35b8aa", width=8)
        canvas.save(chart)
        output = directory / "plan.pdf"
        build_pdf(payload(chart, plan_type), str(output))
        return output, temporary

    def test_weekly_pdf_is_created(self):
        output, temporary = self.build("weekly")
        with temporary:
            self.assertTrue(output.is_file())
            self.assertGreater(output.stat().st_size, 10_000)

    def test_daily_pdf_is_created(self):
        output, temporary = self.build("daily")
        with temporary:
            self.assertGreater(output.stat().st_size, 10_000)

    @unittest.skipUnless(PdfReader, "pypdf není nainstalované")
    def test_pdf_starts_with_bias_then_description_then_zones(self):
        output, temporary = self.build("weekly")
        with temporary:
            text = "\n".join(page.extract_text() or "" for page in PdfReader(str(output)).pages)
            self.assertIn("TÝDENNÍ NÁHLED TRHU", text)
            self.assertIn("ES · týden 40", text)
            self.assertIn("Mapa ceny", text)
            self.assertLess(text.index("PRICE ACTION"), text.index("CO SE NA TRHU ODEHRÁVÁ"))
            self.assertLess(text.index("CO SE NA TRHU ODEHRÁVÁ"), text.index("Obchodní zóny"))
            self.assertIn("Ve VAL předchozího týdne", text)
            self.assertIn("KDY OBCHOD NEBERU", text)
            self.assertIn("Chybí definice", text)

    def test_pdf_uses_app_fonts(self):
        output, temporary = self.build("daily")
        with temporary:
            raw = output.read_bytes()
            self.assertIn(b"Manrope", raw)
            self.assertIn(b"Fraunces", raw)

    @unittest.skipUnless(PdfReader, "pypdf není nainstalované")
    def test_user_markup_is_printed_as_text(self):
        temporary = tempfile.TemporaryDirectory()
        with temporary:
            directory = Path(temporary.name)
            chart = directory / "chart.png"
            Image.new("RGB", (400, 300), "#101820").save(chart)
            data = payload(chart, "daily")
            data["zones"][0]["name"] = "<b>Zóna</b> & <font size='90'>X</font>"
            data["zones"][0]["long_entry"] = "<para>vstup</para> <img src='/etc/passwd'/>"
            data["plan"]["bias_description"] = "</para><font color='red'>popis</font>"
            output = directory / "plan.pdf"
            build_pdf(data, str(output))
            text = " ".join(" ".join(page.extract_text() or "" for page in PdfReader(str(output)).pages).split())
            self.assertIn("<b>Zóna</b> & <font size='90'>X</font>", text)
            self.assertIn("<img src='/etc/passwd'/>", text)

    def test_conclusion_mentions_alignment_and_open_references(self):
        plan = {"pa_weekly": "long", "mp_weekly": "long", "profile_shape": "b"}
        conclusion = working_conclusion(plan, [{"kind": "single_print", "status": "open"}, {"kind": "poor_low", "status": "filled"}])
        self.assertIn("long", conclusion)
        self.assertIn("Single prints", conclusion)
        self.assertNotIn("Poor low", conclusion)


if __name__ == "__main__":
    unittest.main()
