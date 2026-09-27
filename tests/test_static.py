from collections import Counter
from html.parser import HTMLParser
from pathlib import Path
import re
import unittest


ROOT = Path(__file__).resolve().parents[1]


class IdCollector(HTMLParser):
    def __init__(self):
        super().__init__()
        self.ids = []
        self.inline_handlers = []
        self.inline_styles = []

    def handle_starttag(self, tag, attrs):
        attributes = dict(attrs)
        if attributes.get("id"):
            self.ids.append(attributes["id"])
        self.inline_handlers.extend(name for name, _ in attrs if name.startswith("on"))
        if "style" in attributes:
            self.inline_styles.append(tag)


class StaticAppTests(unittest.TestCase):
    def test_html_ids_are_unique_and_csp_safe(self):
        source = (ROOT / "index.php").read_text(encoding="utf-8")
        source = re.sub(r"<\?php.*?\?>", "", source, flags=re.DOTALL)
        parser = IdCollector()
        parser.feed(source)
        duplicates = [key for key, count in Counter(parser.ids).items() if count > 1]
        self.assertEqual(duplicates, [])
        self.assertEqual(parser.inline_handlers, [])
        self.assertEqual(parser.inline_styles, [])

    def test_required_assets_exist(self):
        self.assertTrue((ROOT / "static" / "styles.css").is_file())
        self.assertTrue((ROOT / "static" / "app.js").is_file())
        self.assertTrue((ROOT / "static" / "tradingview-export.js").is_file())
        self.assertTrue((ROOT / "static" / "theme.js").is_file())
        self.assertTrue((ROOT / "pdf.php").is_file())
        self.assertTrue((ROOT / "export_plan.py").is_file())

    def test_pdf_export_is_wired_to_the_plan_toolbar(self):
        html = (ROOT / "index.php").read_text(encoding="utf-8")
        javascript = (ROOT / "static" / "app.js").read_text(encoding="utf-8")
        installer = (ROOT / "deploy" / "install.sh").read_text(encoding="utf-8")
        self.assertIn('id="exportPlanPdf"', html)
        self.assertIn("pdf.php?id=", javascript)
        self.assertIn("python3-reportlab", installer)

    def test_tradingview_export_is_wired_to_the_zone_section(self):
        html = (ROOT / "index.php").read_text(encoding="utf-8")
        javascript = (ROOT / "static" / "app.js").read_text(encoding="utf-8")
        self.assertIn('id="exportTradingViewZones"', html)
        self.assertIn('id="tradingViewDialog"', html)
        self.assertLess(html.index("static/tradingview-export.js"), html.index("static/app.js"))
        self.assertIn("openTradingViewExport", javascript)

    def test_trade_dialog_uses_selects_and_risk_per_trade(self):
        html = (ROOT / "index.php").read_text(encoding="utf-8")
        self.assertIn('<select name="strategy_id" id="tradeStrategy"', html)
        self.assertIn('value="__new"', html)
        self.assertIn('name="risk_amount"', html)
        self.assertIn('<select name="account_id" id="tradeAccount"', html)
        self.assertIn('<select name="execution_rating"', html)
        self.assertNotIn('name="quantity"', html)
        self.assertNotIn("Exekuce 1-5", html)

    def test_market_and_session_are_free_text_with_suggestions(self):
        html = (ROOT / "index.php").read_text(encoding="utf-8")
        self.assertIn('<datalist id="marketOptions">', html)
        self.assertIn('<datalist id="sessionOptions">', html)
        self.assertIn('<input name="market" id="tradeMarket" list="marketOptions"', html)
        self.assertIn('<input name="session" id="tradeSession" list="sessionOptions"', html)
        self.assertIn('<input name="market" id="planMarket" list="marketOptions"', html)
        self.assertNotIn('name="point_value"', html)
        self.assertNotIn('<select name="market"', html)

    def test_emotions_allow_multiple_choices(self):
        html = (ROOT / "index.php").read_text(encoding="utf-8")
        javascript = (ROOT / "static" / "app.js").read_text(encoding="utf-8")
        self.assertIn('id="tradeEmotions"', html)
        self.assertGreaterEqual(html.count('type="checkbox" data-emotion'), 10)
        self.assertNotIn('<select name="emotion"', html)
        self.assertIn("selectedEmotions", javascript)
        self.assertIn("setEmotions", javascript)

    def test_result_button_and_rounding_are_present(self):
        html = (ROOT / "index.php").read_text(encoding="utf-8")
        javascript = (ROOT / "static" / "app.js").read_text(encoding="utf-8")
        self.assertIn('id="calcTradeResult"', html)
        self.assertIn("Vypočítat a doplnit R a výsledek", html)
        self.assertIn("applyTradeResult", javascript)
        self.assertIn("roundedFieldValue", javascript)

    def test_strategy_and_audit_dialogs_are_wired(self):
        html = (ROOT / "index.php").read_text(encoding="utf-8")
        javascript = (ROOT / "static" / "app.js").read_text(encoding="utf-8")
        for identifier in ("strategyDialog", "accountDialog", "auditDialog", "auditResultDialog", "auditBanner", "navAuditFlag"):
            self.assertIn(f'id="{identifier}"', html)
        for function in ("openStrategyDialog", "openAuditDialog", "submitAudit", "updateAuditBanner", "updateTradeCalcHint"):
            self.assertIn(function, javascript)

    def test_audit_banner_supports_upcoming_deadline(self):
        html = (ROOT / "index.php").read_text(encoding="utf-8")
        javascript = (ROOT / "static" / "app.js").read_text(encoding="utf-8")
        php = (ROOT / "bootstrap.php").read_text(encoding="utf-8")
        self.assertIn('id="auditBannerTitle"', html)
        self.assertIn("AUDIT_WARNING_DAYS", php)
        self.assertIn("audit_soon", php)
        self.assertIn("days_to_audit", php)
        self.assertIn("audit_soon", javascript)
        self.assertIn("is-soon", javascript)

    def test_levels_section_is_wired(self):
        html = (ROOT / "index.php").read_text(encoding="utf-8")
        javascript = (ROOT / "static" / "app.js").read_text(encoding="utf-8")
        pine = (ROOT / "static" / "tradingview-export.js").read_text(encoding="utf-8")
        self.assertIn('id="levelList"', html)
        self.assertIn('id="addLevel"', html)
        self.assertIn('id="tvExportLevelCount"', html)
        for function in ("levelTemplate", "renderLevels", "data.levels"):
            self.assertIn(function, javascript)
        self.assertIn("levelBlock", pine)
        self.assertIn("line.new", pine)

    def test_plan_sections_start_empty(self):
        javascript = (ROOT / "static" / "app.js").read_text(encoding="utf-8")
        php = (ROOT / "bootstrap.php").read_text(encoding="utf-8")
        self.assertNotIn("renderZones([{}])", javascript)
        self.assertNotIn("renderIdeas([{}])", javascript)
        for renderer in ("function renderZones(zones = [])", "function renderIdeas(ideas = [])", "function renderLevels(levels = [])"):
            self.assertIn(renderer, javascript)
        self.assertIn("row_has_content", php)
        self.assertIn("remove_blank_plan_rows", php)

    def test_dashboard_does_not_overwrite_shared_lists(self):
        javascript = (ROOT / "static" / "app.js").read_text(encoding="utf-8")
        dashboard = javascript[javascript.index("async function refreshDashboard"):]
        dashboard = dashboard[:dashboard.index("\n}\n")]
        # Přehled si tahá jen 20 obchodů a 100 náhledů, takže jimi nesmí přepsat
        # plné seznamy, ze kterých se vykresluje Deník a Historie náhledů.
        self.assertNotIn("state.trades =", dashboard)
        self.assertNotIn("state.plans =", dashboard)
        self.assertIn("refreshTrades.sequence", javascript)
        self.assertIn("refreshPlans.sequence", javascript)

    def test_point_value_is_derived_not_entered(self):
        html = (ROOT / "index.php").read_text(encoding="utf-8")
        javascript = (ROOT / "static" / "app.js").read_text(encoding="utf-8")
        php = (ROOT / "bootstrap.php").read_text(encoding="utf-8")
        self.assertNotIn('id="tradePointValue"', html)
        self.assertNotIn("syncPointValue", javascript)
        self.assertIn("riskPerPoint", javascript)
        self.assertIn("riskPerPoint", php)

    def test_new_sections_are_wired(self):
        html = (ROOT / "index.php").read_text(encoding="utf-8")
        javascript = (ROOT / "static" / "app.js").read_text(encoding="utf-8")
        php = (ROOT / "bootstrap.php").read_text(encoding="utf-8")
        for view in ("strategies", "calendar", "psyche"):
            self.assertIn(f'data-view="{view}"', html)
            self.assertIn(f'id="view-{view}"', html)
        for identifier in ("strategyTable", "calendarGrid", "dayDialog", "psychDialog", "disciplineDays", "disciplineDetail"):
            self.assertIn(f'id="{identifier}"', html)
        for function in ("refreshStrategyStats", "refreshCalendar", "refreshDiscipline", "showDisciplineDay", "openPsychCheck"):
            self.assertIn(function, javascript)
        for helper in ("strategy_statistics", "calendar_month", "discipline_analysis", "evaluate_psych", "psych_questions"):
            self.assertIn(helper, php)

    def test_strategy_chart_is_wired(self):
        html = (ROOT / "index.php").read_text(encoding="utf-8")
        javascript = (ROOT / "static" / "app.js").read_text(encoding="utf-8")
        php = (ROOT / "bootstrap.php").read_text(encoding="utf-8")
        self.assertIn('id="strategyChart"', html)
        self.assertIn('id="strategyLegend"', html)
        self.assertIn("renderStrategyChart", javascript)
        self.assertIn("strategy_series", php)

    def test_psych_check_runs_one_timed_question_at_a_time(self):
        html = (ROOT / "index.php").read_text(encoding="utf-8")
        javascript = (ROOT / "static" / "app.js").read_text(encoding="utf-8")
        php = (ROOT / "bootstrap.php").read_text(encoding="utf-8")
        for identifier in ("psychStep", "psychQuestionText", "psychOptions", "psychTimerBar", "psychProgress"):
            self.assertIn(f'id="{identifier}"', html)
        # Čas na otázku se odvozuje z počtu slov, aby zbyl prostor na přečtení.
        self.assertIn("PSYCH_SECONDS_PER_WORD", javascript)
        self.assertIn("psychSeconds", javascript)
        self.assertNotIn('id="psychQuestions"', html)
        # Nezodpovězená otázka nesmí být hodnocená jako bezproblémová.
        self.assertIn("$skipped[] = $question['key'];", php)

    def test_profile_and_personal_rules_are_wired(self):
        html = (ROOT / "index.php").read_text(encoding="utf-8")
        javascript = (ROOT / "static" / "app.js").read_text(encoding="utf-8")
        php = (ROOT / "bootstrap.php").read_text(encoding="utf-8")
        for identifier in ("profileDialog", "profileOptions", "rulesDialog", "rulesEditor", "profileSummary"):
            self.assertIn(f'id="{identifier}"', html)
        for function in ("openProfileTest", "renderProfileSummary", "openRulesDialog", "saveRules"):
            self.assertIn(function, javascript)
        for helper in ("psych_dimensions", "evaluate_psych_profile", "profile_reality_check", "normalize_psych_rules", "rules_to_lines"):
            self.assertIn(helper, php)

    def test_every_profile_dimension_names_its_source(self):
        php = (ROOT / "bootstrap.php").read_text(encoding="utf-8")
        block = php[php.index("function psych_dimensions"):]
        block = block[:block.index("\n}\n")]
        labels = block.count("'label' =>")
        sources = block.count("'source' =>")
        self.assertEqual(labels, sources, "každá dimenze musí uvádět, odkud myšlenka pochází")
        self.assertGreaterEqual(labels, 8)

    def test_psych_check_is_not_presented_as_diagnosis(self):
        html = (ROOT / "index.php").read_text(encoding="utf-8")
        # Test je check-list, ne psychologická diagnostika. Upozornění musí zůstat v UI.
        self.assertIn("disclaimer", html)
        self.assertIn("Tohle není psychologická diagnostika", html)

    def test_hidden_attribute_beats_author_display_rules(self):
        css = (ROOT / "static" / "styles.css").read_text(encoding="utf-8")
        javascript = (ROOT / "static" / "app.js").read_text(encoding="utf-8")
        self.assertRegex(css, r"\[hidden\]\s*\{[^}]*display:\s*none\s*!important")
        toggled = re.findall(r"\$\('#(\w+)'\)\.hidden|(\w+)\.hidden\s*=", javascript)
        self.assertTrue(toggled, "žádný prvek se nepřepíná přes hidden, test je zbytečný")

    def test_css_braces_are_balanced(self):
        css = (ROOT / "static" / "styles.css").read_text(encoding="utf-8")
        self.assertEqual(css.count("{"), css.count("}"))

    def test_plan_can_be_daily_or_weekly(self):
        html = (ROOT / "index.php").read_text(encoding="utf-8")
        javascript = (ROOT / "static" / "app.js").read_text(encoding="utf-8")
        php = (ROOT / "bootstrap.php").read_text(encoding="utf-8")
        self.assertIn('name="plan_type" value="daily"', html)
        self.assertIn('name="plan_type" value="weekly"', html)
        self.assertIn("UNIQUE(plan_type, plan_date, market, session)", php)
        # Týdenní náhled patří vždy k pondělí, na klientu i na serveru.
        self.assertIn("function week_start", php)
        self.assertIn("function mondayOf", javascript)

    def test_opening_fields_are_locked_until_their_time(self):
        html = (ROOT / "index.php").read_text(encoding="utf-8")
        javascript = (ROOT / "static" / "app.js").read_text(encoding="utf-8")
        for gate in ("globex", "eu", "rth", "ib"):
            self.assertIn(f'data-gate="{gate}"', html)
        for function in ("function sessionSchedule", "function applySessionLocks", "function nextSessionDate", "function zonedInstant"):
            self.assertIn(function, javascript)
        # RTH se počítá v newyorském čase, aby seděl i v týdnech s rozdílným letním časem.
        self.assertIn("America/New_York", javascript)
        self.assertIn("Europe/Prague", javascript)

    def test_locked_fields_are_still_saved(self):
        javascript = (ROOT / "static" / "app.js").read_text(encoding="utf-8")
        block = javascript[javascript.index("function planFieldValues"):]
        block = block[:block.index("\n}\n")]
        # FormData zamčená pole vynechává; náhled je musí číst sám, jinak by se hodnota ztratila.
        self.assertNotIn("new FormData", block)
        self.assertIn(".plan-row", block)
        for template in ("function zoneTemplate", "function ideaTemplate", "function levelTemplate", "function refTemplate"):
            section = javascript[javascript.index(template):]
            section = section[:section.index("\n}\n")]
            self.assertIn("plan-row", section, template)

    def test_bias_board_has_price_action_and_profile(self):
        html = (ROOT / "index.php").read_text(encoding="utf-8")
        pdf = (ROOT / "export_plan.py").read_text(encoding="utf-8")
        self.assertIn("['pa', 'Price action'", html)
        self.assertIn("['mp', 'Market Profile / Volume Profile'", html)
        self.assertIn("def bias_board", pdf)
        # PDF začíná biasem, pak popisem trhu a teprve potom zónami.
        start = pdf.index("def build_pdf")
        order = [pdf.index(marker, start) for marker in ("bias_board(plan", "CO SE NA TRHU ODEHRÁVÁ", '"Obchodní zóny"')]
        self.assertEqual(order, sorted(order))

    def test_zone_conditions_are_defined_per_direction(self):
        javascript = (ROOT / "static" / "app.js").read_text(encoding="utf-8")
        css = (ROOT / "static" / "styles.css").read_text(encoding="utf-8")
        php = (ROOT / "bootstrap.php").read_text(encoding="utf-8")
        for field in ("long_entry", "long_skip", "short_entry", "short_skip"):
            self.assertIn(field, javascript)
            self.assertIn(field, php)
        self.assertIn('.zone-row[data-direction="both"] .cond-long', css)
        self.assertIn('.zone-row[data-direction="both"] .cond-short', css)
        self.assertIn("function missingZoneConditions", javascript)
        self.assertIn("payload.status === 'ready'", javascript)

    def test_weekly_value_context_uses_same_rules_everywhere(self):
        javascript = (ROOT / "static" / "app.js").read_text(encoding="utf-8")
        php = (ROOT / "bootstrap.php").read_text(encoding="utf-8")
        js_block = javascript[javascript.index("function vaContext"):]
        js_block = js_block[:js_block.index("\n}\n")]
        php_block = php[php.index("function zone_value_context"):]
        php_block = php_block[:php_block.index("\n}\n")]
        keys = ["span", "at_val", "at_vah", "near_val_below", "below_val", "near_vah_above", "above_vah", "near_val_inside", "near_vah_inside", "inside"]
        for key in keys:
            self.assertIn(f"'{key}'", js_block, key)
            self.assertIn(f"'{key}'", php_block, key)
        self.assertIn("0.05", js_block)
        self.assertIn("0.05", php_block)

    def test_theme_loads_before_styles(self):
        html = (ROOT / "index.php").read_text(encoding="utf-8")
        css = (ROOT / "static" / "styles.css").read_text(encoding="utf-8")
        self.assertTrue((ROOT / "static" / "theme.js").is_file())
        self.assertLess(html.index("static/theme.js"), html.index("static/styles.css"))
        self.assertIn(':root[data-theme="light"]', css)

    def test_calibration_is_refreshed_with_psyche_view(self):
        javascript = (ROOT / "static" / "app.js").read_text(encoding="utf-8")
        css = (ROOT / "static" / "styles.css").read_text(encoding="utf-8")
        block = javascript[javascript.index("function activateView"):]
        block = block[:block.index("\n}\n")]
        self.assertIn("refreshCalibration()", block)
        self.assertIn(".calibration-suggestion", css)


if __name__ == "__main__":
    unittest.main()
