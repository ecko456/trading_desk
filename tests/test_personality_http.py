"""Osobnost ve vstupním profilu: Big Five (IPIP), talenty CliftonStrengths, napojení na rychlý test a dýchání."""

from pathlib import Path
import json
import unittest

from test_accounts_http import PHP, Client, Server

ROOT = Path(__file__).resolve().parents[1]
SAMPLE_TOP5 = ["futuristic", "harmony", "restorative", "context", "strategic"]


@unittest.skipIf(PHP is None, "PHP není nainstalované")
class PersonalityTests(unittest.TestCase):
    def setUp(self):
        self.server = Server()
        self.client = Client(self.server)
        self.client.api("GET", "auth_state")
        token = (self.server.data / "setup-token.txt").read_text().strip()
        # Šifrovaný deník: osobnost nesmí být na disku čitelná.
        status, result = self.client.api("POST", "setup", {"token": token, "login": "osobnost", "display_name": "Jan Trader", "secret_mode": "key"})
        self.assertEqual(status, 201, result)
        status, result = self.client.api("GET", "personality")
        self.assertEqual(status, 200, result)
        self.catalog = result["catalog"]

    def tearDown(self):
        self.server.stop()

    def save(self, body, expected=200):
        status, result = self.client.api("POST", "personality", body)
        self.assertEqual(status, expected, result)
        return result.get("personality", result)

    def answers(self, choose):
        """Odpovědi 0–4 pro všech 50 výroků; `choose(index)` vrací odpověď podle pořadí výroku."""
        return {item["key"]: choose(index) for index, item in enumerate(self.catalog["big5_items"])}

    def questions(self):
        status, result = self.client.api("GET", "psych_questions")
        self.assertEqual(status, 200, result)
        return {item["key"]: item for item in result["items"]}

    def test_catalog(self):
        self.assertEqual(len(self.catalog["big5_items"]), 50)
        self.assertEqual(len(self.catalog["big5_scale"]), 5)
        self.assertEqual(len(self.catalog["themes"]), 34)
        self.assertEqual(len({theme["key"] for theme in self.catalog["themes"]}), 34)
        self.assertEqual(set(self.catalog["domains"]), {"executing", "influencing", "relationship", "thinking"})
        self.assertNotIn("sign", self.catalog["big5_items"][0], "klíč bodování klient nepotřebuje")
        status, result = self.client.api("GET", "personality")
        empty = result["personality"]
        self.assertIsNone(empty["big5"])
        self.assertIsNone(empty["strengths"])
        self.assertEqual(empty["focus"], [])
        self.assertEqual((empty["recommendation"]["pattern"], empty["recommendation"]["music"]), ("calm", "ocean"))

    def test_strengths_drive_focus_and_quick_test(self):
        data = self.save({"strengths": {"top": SAMPLE_TOP5 + ["strategic", "nesmysl", 5], "bottom": ["discipline", "strategic"]}})
        self.assertEqual([theme["key"] for theme in data["strengths"]["top"]], SAMPLE_TOP5, "duplicity a neznámé klíče pryč")
        self.assertEqual([theme["key"] for theme in data["strengths"]["bottom"]], ["discipline"], "nejslabší se nekryjí s nejsilnějšími")
        self.assertEqual(data["strengths"]["top"][0]["name"], "Vizionář")
        # Vizionář 1. a Strategický 5. → předbíhání potvrzení, Harmonie 2. → cizí názory,
        # Napravující 3. → reakce na ztrátu.
        self.assertEqual([item["area"] for item in data["focus"]], ["anticipation", "social", "revenge"])
        self.assertEqual(data["focus"][0]["reasons"], ["Vizionář (1.)", "Strategický (5.)"])
        self.assertEqual(data["recommendation"]["pattern"], "calm", "reakce na ztrátu → delší výdech")
        self.assertEqual(data["recommendation"]["music"], "drone")
        # Nejslabší talenty samy o sobě: Disciplína → příprava, Rozvážný → předbíhání potvrzení.
        alone = self.save({"strengths": {"top": [], "bottom": ["discipline", "deliberative"]}})
        self.assertEqual([item["area"] for item in alone["focus"]], ["process", "anticipation"])
        self.assertEqual(alone["focus"][0]["reasons"], ["Disciplína mezi nejslabšími"])
        self.save({"strengths": {"top": SAMPLE_TOP5}})

        questions = self.questions()
        self.assertEqual(questions["early_entry"]["source"], "personality")
        self.assertEqual(questions["early_entry"]["weight"], 1.25)
        self.assertEqual(questions["early_entry"]["reason"], "Vizionář (1.), Strategický (5.)")
        self.assertEqual(questions["others_view"]["source"], "personality")
        self.assertNotIn("yesterday_loss", questions, "z osobnosti nejvýš dvě otázky navíc")
        self.assertEqual(questions["revenge"]["weight"], 1.25, "reakce na ztrátu je mezi oblastmi")
        self.assertEqual(questions["confidence"]["weight"], 1.0)
        self.assertEqual(questions["sleep"]["weight"], 1.0)
        self.assertIsNone(questions["sleep"]["source"])

    def test_personality_alone_does_not_turn_day_amber(self):
        self.save({"strengths": {"top": SAMPLE_TOP5}})
        answers = {key: 0 for key in self.questions()}
        answers["early_entry"] = 2
        status, check = self.client.api("POST", "psych_check", {"check_date": "2026-10-05", "answers": answers})
        self.assertEqual(status, 201, check)
        self.assertEqual(check["band"], "green", "osobnost je hypotéza, sama den neshodí")
        self.assertTrue(any("Předvídání patří do přípravy" in item for item in check["warnings_list"]))

    def test_profile_and_personality_share_three_extra_questions(self):
        status, result = self.client.api("GET", "psych_profile")
        profile_answers = {question["key"]: 3 for question in result["questions"]}
        status, result = self.client.api("POST", "psych_profile", {"answers": profile_answers})
        self.assertEqual(status, 201, result)
        self.save({"strengths": {"top": ["activator", "woo", "harmony", "analytical", "learner"]}})
        questions = self.questions()
        extra = [item for item in questions.values() if item["key"] not in {"sleep", "body", "stress", "pressure", "revenge", "preparation", "focus", "confidence"}]
        self.assertEqual(len(extra), 3)
        self.assertEqual([item["source"] for item in extra], ["profile", "profile", "personality"])
        self.assertEqual(questions["revenge"]["weight"], 1.5, "riziková oblast z profilu má přednost")

    def test_big5_scoring(self):
        items = self.catalog["big5_items"]
        # Neúplný test se neuloží.
        partial = {item["key"]: 2 for item in items[:30]}
        self.save({"big5_answers": partial}, expected=422)

        # Emoční stabilita na minimu: u každého výroku o stresu „přesně“, u klidu „vůbec“.
        stress_keys = {"b04", "b14", "b24", "b29", "b34", "b39", "b44", "b49"}
        calm_keys = {"b09", "b19"}
        answers = self.answers(lambda index: 2)
        for key in stress_keys:
            answers[key] = 4
        for key in calm_keys:
            answers[key] = 0
        answers["b01"] = "4"
        answers["b02"] = 9  # mimo škálu: nepočítá se
        data = self.save({"big5_answers": answers})
        traits = data["big5"]["traits"]
        self.assertEqual((traits["S"]["share"], traits["S"]["level"], traits["S"]["risk"]), (0, "low", True))
        self.assertEqual(traits["C"]["level"], "mid")
        self.assertEqual(data["big5"]["answered"], 49)
        self.assertEqual(traits["A"]["answered"], 9)
        self.assertEqual(data["focus"][0]["area"], "revenge")
        self.assertEqual(data["focus"][0]["reasons"], ["Emoční stabilita nízko"])
        self.assertEqual((data["recommendation"]["pattern"], data["recommendation"]["music"]), ("calm", "ocean"))

        # Talenty se k Big Five přičtou, smazání jednoho zdroje nechá druhý.
        data = self.save({"strengths": {"top": SAMPLE_TOP5}})
        self.assertEqual({item["area"] for item in data["focus"]} & {"revenge", "anticipation"}, {"revenge", "anticipation"})
        data = self.save({"clear": "big5"})
        self.assertIsNone(data["big5"])
        self.assertEqual(data["focus"][0]["area"], "anticipation")

    def test_breath_preferences_are_cleaned(self):
        data = self.save({"preferences": {"pattern": "turbo", "minutes": 7, "music": "<script>", "volume": 150, "cues": False, "custom": {"inhale": 1, "hold_in": 40, "exhale": "8", "hold_out": -3}}})
        prefs = data["preferences"]
        self.assertEqual((prefs["pattern"], prefs["minutes"], prefs["music"], prefs["volume"], prefs["cues"]), ("", 0, "", 100, False))
        self.assertEqual(prefs["custom"], {"inhale": 2, "hold_in": 10, "exhale": 8, "hold_out": 0})
        data = self.save({"preferences": {"pattern": "box", "minutes": 5, "music": "rain", "volume": 40}})
        prefs = data["preferences"]
        self.assertEqual((prefs["pattern"], prefs["minutes"], prefs["music"], prefs["volume"], prefs["cues"]), ("box", 5, "rain", 40, True))
        self.assertEqual(self.save({"preferences": {"music": "off"}})["preferences"]["music"], "off")

    def test_personality_is_sealed_and_exported(self):
        self.save({"strengths": {"top": ["significance", "woo"]}, "preferences": {"music": "chimes"}})
        for path in self.server.data.rglob("*"):
            if path.is_file():
                content = path.read_bytes()
                self.assertNotIn(b"significance", content, path.name)
                self.assertNotIn(b"chimes", content, path.name)
        status, export = self.client.api("GET", "export")
        self.assertEqual(status, 200, export)
        self.assertEqual(json.loads(export["psych_personality"][0]["strengths"])["top"], ["significance", "woo"])


class PersonalityStaticTests(unittest.TestCase):
    def test_scripts_are_loaded_before_app(self):
        html = (ROOT / "index.php").read_text(encoding="utf-8")
        order = [html.index(f"static/{name}") for name in ("soundscapes.js", "breathing.js", "personality.js", "app.js")]
        self.assertEqual(order, sorted(order))
        for identifier in ("personalitySummary", "bigFiveDialog", "strengthsDialog", "breathDialog", "breathCircle", "psychSource"):
            self.assertIn(f'id="{identifier}"', html)

    def test_gallup_is_not_presented_as_ours(self):
        html = (ROOT / "index.php").read_text(encoding="utf-8")
        self.assertIn("ochranné známky Gallup, Inc.", html)
        self.assertIn("veřejné doméně", html)
        php = (ROOT / "lib" / "personality.php").read_text(encoding="utf-8")
        self.assertIn("Goldberg", php)


if __name__ == "__main__":
    unittest.main()
