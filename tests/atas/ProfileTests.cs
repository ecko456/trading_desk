// Testy výpočtu nástřelu z ATAS (TradingDeskProfile.cs) a obalu indikátoru (TradingDeskDraft.cs)
// nad vymyšlenými minutovými svíčkami ES. Spuštění (potřebuje .NET SDK 10):
//   dotnet run --project tests/atas
//   dotnet run --project tests/atas -- --update-fixture   # přepíše tests/atas/draft-sample.json
// Ukázkový JSON čte i Python test (tests/test_atas_http.py), takže server zkouší přesně to,
// co indikátor posílá.

using System;
using System.Collections.Generic;
using System.IO;
using System.Linq;
using System.Text.Json;
using System.Threading;

using ATAS.Indicators;
using ATAS.Indicators.TradingDesk;

internal static class Program
{
	private static readonly TimeZoneInfo Ny = TdTime.NewYork();
	private static int _failed;
	private static int _passed;

	private static int Main(string[] args)
	{
		var bars = Market();
		var s = new TdSettings { Symbol = "ESZ6", Market = "ES" };

		Test("value area jako v ATAS (krok 1, 70 %)", () =>
		{
			var p = new TdProfile { High = 102, Low = 100 };
			var volumes = new decimal[] { 1, 2, 3, 10, 4, 3, 8, 1, 1 };

			for (var i = 0; i < volumes.Length; i++)
				p.Vol[100 + i * 0.25m] = volumes[i];

			p.Volume = volumes.Sum();
			p.Poc = 100.75m;
			var (vah, val) = TdDraftBuilder.ValueArea(p, s);
			Equal(101.50m, vah, "VAH");
			Equal(100.75m, val, "VAL");
		});

		Test("denní nástřel po konci pátku: profil, IB, single prints, tail, poor high, naked POC", () =>
		{
			var now = Utc(2026, 11, 20, 16, 30);
			var d = TdDraftBuilder.Build(bars, s, "daily", now, out var reason) ?? throw new Exception(reason);
			var p = d.Profile;
			Equal(new DateTime(2026, 11, 20), p.From, "den profilu");
			Equal(new DateTime(2026, 11, 23), d.Next, "další obchodní den (pondělí)");
			Equal(390, p.Bars, "svíček RTH");
			Equal((6008m, 6030m, 6000m, 6022m), (p.Open, p.High, p.Low, p.Close), "OHLC");
			Equal(6025m, p.Poc, "POC");
			True(p.Val <= p.Poc && p.Poc <= p.Vah && p.Val >= p.Low && p.Vah <= p.High, $"VA kolem POC ({p.Val}–{p.Vah})");
			var inside = p.Vol.Where(x => x.Key >= p.Val && x.Key <= p.Vah).Sum(x => x.Value);
			True(inside >= p.Volume * 0.7m, "VA má aspoň 70 % objemu");
			Equal((6000m, 6012m), (d.Ib!.Low, d.Ib.High), "IB");
			Equal("up", d.IbExtension, "rozšíření IB");
			Equal(12m, d.IbAverageRange, "průměr IB");
			Equal("6012.25-6017.75", string.Join(",", d.SinglePrints.Select(b => $"{b.Low}-{b.High}")), "single prints");
			Equal("6000.00-6003.75", $"{d.BuyingTail!.Low}-{d.BuyingTail.High}", "kupní tail");
			True(d.SellingTail is null && d.PoorHigh && !d.PoorLow, "poor high bez prodejního tailu");
			Equal("5985.00@2026-11-18", string.Join(",", d.NakedPocs.Select(x => $"{x.Price}@{x.Date:yyyy-MM-dd}")), "naked POC");
			Equal("p", d.Shape, "tvar");
			Equal(6025m, d.Previous!.Poc, "POC předchozího dne");
			True(d.Overnight is null, "po konci RTH v pátek ještě žádný overnight");
		});

		Test("během RTH je to předchozí den a overnight je ETH před seancí", () =>
		{
			var d = TdDraftBuilder.Build(bars, s, "daily", Utc(2026, 11, 20, 10, 0), out var reason) ?? throw new Exception(reason);
			Equal(new DateTime(2026, 11, 19), d.Profile.From, "den");
			Equal(new DateTime(2026, 11, 20), d.Next, "další den");
			Equal((6020m, 6026m), (d.Overnight!.Low, d.Overnight.High), "overnight");
		});

		Test("letní a zimní čas: obě seance mají 390 minut RTH", () =>
		{
			foreach (var day in new[] { new DateTime(2026, 10, 30), new DateTime(2026, 11, 2) })
			{
				var d = TdDraftBuilder.Build(bars, s, "daily", Utc(day.Year, day.Month, day.Day, 17, 0), out var reason) ?? throw new Exception(reason);
				Equal(day, d.Profile.From, "den");
				Equal(390, d.Profile.Bars, $"svíček RTH {day:d. M.}");
				Equal(6000m, d.Profile.Low, $"Low {day:d. M.}");
			}
		});

		Test("týdenní: hotový týden v sobotu, ve středu předchozí", () =>
		{
			var w = TdDraftBuilder.Build(bars, s, "weekly", Utc(2026, 11, 21, 12, 0), out var reason) ?? throw new Exception(reason);
			Equal((new DateTime(2026, 11, 16), new DateTime(2026, 11, 20), new DateTime(2026, 11, 23)), (w.Profile.From, w.Profile.To, w.Next), "týden 16.–20. 11.");
			Equal((5960m, 6030m), (w.Profile.Low, w.Profile.High), "rozpětí týdne");
			Equal(new DateTime(2026, 11, 9), w.Previous!.From, "předchozí týden");
			var mid = TdDraftBuilder.Build(bars, s, "weekly", Utc(2026, 11, 18, 17, 0), out reason) ?? throw new Exception(reason);
			Equal((new DateTime(2026, 11, 9), new DateTime(2026, 11, 13), new DateTime(2026, 11, 16)), (mid.Profile.From, mid.Profile.To, mid.Next), "ve středu minulý týden");
		});

		Test("dva objemové shluky: HVN v obou, LVN mezi nimi", () =>
		{
			var day = new DateTime(2026, 11, 20);
			var list = new List<TdBar>();

			for (var b = 0; b < 13; b++)
			{
				for (var m = 0; m < 30; m++)
				{
					var bar = Bar(day.AddHours(9.5).AddMinutes(b * 30 + m), 6000, 6030, 100);

					// shluky objemu 6002–6008 (dopoledne) a 6022–6028 (odpoledne), mezi nimi jen základ
					for (var price = b < 6 ? 6002m : 6022m; price <= (b < 6 ? 6008m : 6028m); price += 0.25m)
						bar.Levels!.Add(new TdLevel(price, b < 6 ? 20 : 24));

					bar.Volume = bar.Levels!.Sum(l => l.Volume);
					list.Add(bar);
				}
			}

			var d = TdDraftBuilder.Build(list, s, "daily", Utc(2026, 11, 20, 17, 0), out var reason) ?? throw new Exception(reason);
			True(d.Profile.Poc >= 6022 && d.Profile.Poc <= 6028, $"POC v odpoledním shluku ({d.Profile.Poc})");
			True(d.Hvn.Any(x => x >= 6002 && x <= 6008), "HVN v dopoledním shluku: " + string.Join(",", d.Hvn));
			True(d.Lvn.Count > 0 && d.Lvn.All(x => x > 6008 && x < 6022), "LVN mezi shluky: " + string.Join(",", d.Lvn));
			Equal("d", d.Shape, "tvar");
		});

		Test("málo dat: srozumitelný důvod místo pádu", () =>
		{
			var none = TdDraftBuilder.Build(new List<TdBar>(), s, "daily", Utc(2026, 11, 20, 17, 0), out var reason);
			True(none is null && reason.Contains("dokončená seance"), reason);
		});

		Test("trh podle kontraktu", () =>
		{
			Equal("ES,MES,ES,NQ,6E,BTCUSDT", string.Join(",", new[] { "ESZ6", "MESZ26", "ES 12-26", "NQH7", "6EZ6", "BTCUSDT" }.Select(TdDraftBuilder.MarketOf)), "trhy");
		});

		Test("JSON pro Trading Desk a ukázkový soubor pro Python test", () =>
		{
			var now = Utc(2026, 11, 20, 16, 30);
			var d = TdDraftBuilder.Build(bars, s, "daily", now, out _)!;
			var json = TdDraftBuilder.ToJson(d, s, now);
			using var doc = JsonDocument.Parse(json);
			var root = doc.RootElement;
			Equal(("td-atas-draft", 1, "daily", "2026-11-20", "2026-11-23"), (root.GetProperty("format").GetString(), root.GetProperty("version").GetInt32(), root.GetProperty("kind").GetString(),
				root.GetProperty("period").GetProperty("to").GetString(), root.GetProperty("next").GetString()), "hlavička");
			Equal(6025m, root.GetProperty("poc").GetDecimal(), "poc");
			Equal(JsonValueKind.Null, root.GetProperty("overnight").ValueKind, "overnight null");
			Equal(5985m, root.GetProperty("naked_pocs")[0].GetProperty("price").GetDecimal(), "naked POC");
			var fixture = Path.Combine(Root(), "tests", "atas", "draft-sample.json");

			if (args.Contains("--update-fixture"))
				File.WriteAllText(fixture, json + "\n");

			Equal(File.ReadAllText(fixture).TrimEnd(), json, "tests/atas/draft-sample.json je aktuální (jinak --update-fixture)");
		});

		Test("indikátor: Odeslat teď uloží soubor a ukáže stav", () =>
		{
			var folder = Path.Combine(Path.GetTempPath(), "td-draft-" + Guid.NewGuid().ToString("N"));
			var indicator = new TradingDeskDraft { Folder = folder, Kind = TradingDeskDraft.DraftKind.Both, AutoAfterClose = false };

			foreach (var bar in bars)
			{
				var candle = new IndicatorCandle { Time = bar.Time, Open = bar.Open, High = bar.High, Low = bar.Low, Close = bar.Close, Volume = bar.Volume };
				candle.Levels.AddRange(bar.Levels!.Select(l => new PriceVolumeInfo { Price = l.Price, Volume = l.Volume }));
				indicator.AddCandleForTest(candle);
			}

			indicator.SendNow = true;
			var files = Array.Empty<string>();

			for (var i = 0; i < 100 && files.Length < 2; i++)
			{
				Thread.Sleep(50);
				files = Directory.Exists(folder) ? Directory.GetFiles(folder).Select(x => Path.GetFileName(x)).OrderBy(x => x).ToArray() : files;
			}

			Equal("TradingDesk-ES-daily-2026-11-20.json,TradingDesk-ES-weekly-2026-11-20.json", string.Join(",", files), "soubory");
			True(indicator.SendNow == false, "políčko Odeslat teď se vrátí");
			Directory.Delete(folder, true);
		});

		Console.WriteLine($"{_passed} ok, {_failed} chyb");
		return _failed == 0 ? 0 : 1;
	}

	/// <summary>
	/// Tři a půl týdne (26. 10. – 20. 11. 2026, přes konec letního času 1. 11.). Každý den: overnight
	/// 18:00–9:29 v 6020–6026, RTH 13 půlhodin: 6000–6012 (otevření 6008), 6004–6012, rychle 6012–6024,
	/// pak 6018–6030 s objemem navíc na 6025 (POC), zavření 6022. 18. 11. je celý den o 40 bodů níž,
	/// takže jeho POC 5985 zůstane naked.
	/// </summary>
	private static List<TdBar> Market()
	{
		var bars = new List<TdBar>();

		for (var day = new DateTime(2026, 10, 26); day <= new DateTime(2026, 11, 20); day = day.AddDays(1))
		{
			if (day.DayOfWeek is DayOfWeek.Saturday or DayOfWeek.Sunday)
				continue;

			var shift = day == new DateTime(2026, 11, 18) ? -40m : 0m;
			for (var t = day.AddDays(-1).AddHours(18); t < day.AddHours(9.5); t = t.AddMinutes(1))
				bars.Add(Bar(t, 6020, 6026, 50));

			var brackets = new (decimal Low, decimal High)[] { (6000, 6012), (6004, 6012), (6012, 6024) };

			for (var b = 0; b < 13; b++)
			{
				var (low, high) = b < brackets.Length ? brackets[b] : (6018m, 6030m);

				for (var m = 0; m < 30; m++)
				{
					var time = day.AddHours(9.5).AddMinutes(b * 30 + m);
					var bar = Bar(time, low + shift, high + shift, 100, b is >= 4 and <= 8 ? 6025m + shift : null);

					if (b == 0 && m == 0)
						bar.Open = 6008 + shift;

					if (b == 12 && m == 29)
						bar.Close = 6022 + shift;

					bars.Add(bar);
				}
			}
		}

		return bars;
	}

	private static TdBar Bar(DateTime local, decimal low, decimal high, decimal volume, decimal? extraAt = null)
	{
		var levels = new List<TdLevel>();
		var steps = (int)((high - low) / 0.25m) + 1;

		for (var i = 0; i < steps; i++)
			levels.Add(new TdLevel(low + i * 0.25m, volume / steps));

		if (extraAt is { } price)
			levels.Add(new TdLevel(price, 200));

		var mid = TdDraftBuilder.Round((low + high) / 2, 0.25m);
		return new TdBar
		{
			Time = TimeZoneInfo.ConvertTimeToUtc(DateTime.SpecifyKind(local, DateTimeKind.Unspecified), Ny),
			Open = mid,
			High = high,
			Low = low,
			Close = mid,
			Volume = levels.Sum(l => l.Volume),
			Levels = levels
		};
	}

	private static DateTime Utc(int y, int m, int d, int hourNy, int minute)
	{
		return TimeZoneInfo.ConvertTimeToUtc(new DateTime(y, m, d, hourNy, minute, 0, DateTimeKind.Unspecified), Ny);
	}

	/// <summary>Kořen repozitáře od aktuální složky (dotnet run z repozitáře), jinak od výstupu sestavení.</summary>
	private static string Root()
	{
		foreach (var start in new[] { Directory.GetCurrentDirectory(), AppContext.BaseDirectory })
		{
			var dir = new DirectoryInfo(start);

			while (dir is not null && !File.Exists(Path.Combine(dir.FullName, "atas.php")))
				dir = dir.Parent;

			if (dir is not null)
				return dir.FullName;
		}

		throw new Exception("kořen repozitáře nenalezen, spusť test z repozitáře");
	}

	private static void Test(string name, Action body)
	{
		try
		{
			body();
			_passed++;
			Console.WriteLine("ok   " + name);
		}
		catch (Exception error)
		{
			_failed++;
			Console.WriteLine("FAIL " + name + ": " + error.Message);
		}
	}

	private static void Equal<T>(T expected, T actual, string what)
	{
		if (!EqualityComparer<T>.Default.Equals(expected, actual))
			throw new Exception($"{what}: čekáno {expected}, je {actual}");
	}

	private static void True(bool condition, string what)
	{
		if (!condition)
			throw new Exception(what);
	}
}
