// Trading Desk: výpočet profilu pro nástřel náhledu (bez závislosti na ATAS).
//
// Ze svíček grafu (čas v UTC, OHLC, objem na cenách) poskládá obchodní dny v čase burzy,
// spočítá profil posledního dokončeného dne nebo týdne a vrátí ho jako JSON pro Trading Desk
// (formát „td-atas-draft“, verze 1, čte ho lib/atas-draft.php):
//   - High, Low, Open, Close, objem, VWAP;
//   - POC, VAH a VAL z objemu na cenách (stejný postup jako value area v ATAS);
//   - TPO po 30 minutách: single prints, kupní a prodejní tail, poor high a poor low;
//   - initial balance (první hodina RTH), průměr IB posledních dní, rozšíření IB;
//   - HVN a LVN z vyhlazeného profilu, tvar profilu (P, b, D, double, trend);
//   - naked POC starších dní (týdnů), na které cena od té doby nesáhla;
//   - overnight (ETH před další seancí), když už na grafu je.
// Indikátor TradingDeskDraft.cs jen přečte svíčky z ATAS a výsledek odešle nebo uloží.

namespace ATAS.Indicators.TradingDesk;

using System;
using System.Collections.Generic;
using System.Globalization;
using System.IO;
using System.Linq;
using System.Text;
using System.Text.Json;

/// <summary>Jedna svíčka: začátek v UTC, ceny, objem a volitelně objem na jednotlivých cenách.</summary>
public sealed class TdBar
{
	public DateTime Time;
	public decimal Open;
	public decimal High;
	public decimal Low;
	public decimal Close;
	public decimal Volume;
	public List<TdLevel>? Levels;
}

public readonly record struct TdLevel(decimal Price, decimal Volume);

public sealed class TdSettings
{
	public TimeZoneInfo Exchange = TdTime.NewYork();
	public TimeSpan RthStart = new(9, 30, 0);
	public TimeSpan RthEnd = new(16, 0, 0);
	public TimeSpan EthStart = new(18, 0, 0);
	public int IbMinutes = 60;
	public int ValueAreaPercent = 70;
	public int ValueAreaStep = 1;
	public int TpoMinutes = 30;
	public int MinSinglePrintTicks = 2;
	public int NakedLookbackDays = 20;
	public int IbAverageDays = 10;
	public decimal Tick = 0.25m;
	public bool Eth;
	public string Symbol = string.Empty;
	public string Market = string.Empty;
	public string Source = "ATAS TradingDeskDraft 1.0";
}

public static class TdTime
{
	/// <summary>Časové pásmo New Yorku: IANA (macOS, novější Windows), jinak název z Windows.</summary>
	public static TimeZoneInfo NewYork()
	{
		return Find("America/New_York") ?? Find("Eastern Standard Time") ?? TimeZoneInfo.Utc;
	}

	public static TimeZoneInfo? Find(string id)
	{
		try
		{
			return TimeZoneInfo.FindSystemTimeZoneById(id);
		}
		catch (Exception)
		{
			return null;
		}
	}

	public static DateTime NextWeekday(DateTime date)
	{
		do
			date = date.AddDays(1);
		while (date.DayOfWeek is DayOfWeek.Saturday or DayOfWeek.Sunday);

		return date;
	}

	public static DateTime Monday(DateTime date)
	{
		var offset = ((int)date.DayOfWeek + 6) % 7;
		return date.Date.AddDays(-offset);
	}
}

/// <summary>Profil jednoho dne nebo týdne.</summary>
public sealed class TdProfile
{
	public DateTime From;
	public DateTime To;
	public DateTime StartUtc;
	public DateTime EndUtc;
	public decimal Open;
	public decimal High;
	public decimal Low = decimal.MaxValue;
	public decimal Close;
	public decimal Volume;
	public decimal Vwap;
	public decimal Poc;
	public decimal Vah;
	public decimal Val;
	public readonly SortedDictionary<decimal, decimal> Vol = new();
	public readonly SortedDictionary<decimal, int> Tpo = new();
	public int Bars;

	public bool IsEmpty => Bars == 0;
}

public sealed class TdBand
{
	public decimal Low;
	public decimal High;
}

/// <summary>Výsledek pro jeden nástřel (denní nebo týdenní).</summary>
public sealed class TdDraft
{
	public string Kind = "daily";
	public TdProfile Profile = new();
	public TdProfile? Previous;
	public DateTime Next;
	public string Shape = string.Empty;
	public TdBand? Ib;
	public decimal IbOpen;
	public decimal IbClose;
	public decimal? IbAverageRange;
	public string IbExtension = string.Empty;
	public readonly List<TdBand> SinglePrints = new();
	public TdBand? BuyingTail;
	public TdBand? SellingTail;
	public bool PoorHigh;
	public bool PoorLow;
	public readonly List<(decimal Price, DateTime Date)> NakedPocs = new();
	public readonly List<decimal> Lvn = new();
	public readonly List<decimal> Hvn = new();
	public TdProfile? Overnight;
}

public static class TdDraftBuilder
{
	private sealed class Session
	{
		public DateTime Date;
		public readonly List<TdBar> Rth = new();
		public readonly List<TdBar> Eth = new();
	}

	/// <summary>
	/// Nástřel z dostupných svíček. kind: "daily" (poslední dokončená seance RTH) nebo
	/// "weekly" (poslední dokončený týden). Vrací null a důvod, když na grafu není dost dat.
	/// </summary>
	public static TdDraft? Build(IReadOnlyList<TdBar> bars, TdSettings s, string kind, DateTime nowUtc, out string reason)
	{
		reason = string.Empty;
		var sessions = Sessions(bars, s);
		var nowLocal = TimeZoneInfo.ConvertTimeFromUtc(DateTime.SpecifyKind(nowUtc, DateTimeKind.Unspecified), s.Exchange);

		// poslední seance, jejíž RTH už skončilo
		var done = sessions.Where(x => x.Rth.Count > 0 && nowLocal >= x.Date + s.RthEnd).ToList();

		if (done.Count == 0)
		{
			reason = "Na grafu není dokončená seance RTH. Dej indikátor na minutový graf s historií aspoň pár dní.";
			return null;
		}

		return kind == "weekly" ? BuildWeekly(sessions, done, s, nowLocal, out reason) : BuildDaily(sessions, done, s);
	}

	private static TdDraft BuildDaily(List<Session> sessions, List<Session> done, TdSettings s)
	{
		var day = done[^1];
		var index = done.Count - 1;
		var draft = new TdDraft { Kind = "daily", Next = TdTime.NextWeekday(day.Date) };
		draft.Profile = Profile(Pick(day, s), day.Date, day.Date, s);

		if (index > 0)
			draft.Previous = Profile(Pick(done[index - 1], s), done[index - 1].Date, done[index - 1].Date, s);

		Analyse(draft, s);

		// initial balance: první IbMinutes minut RTH
		var ibEnd = day.Rth[0].Time.AddMinutes(s.IbMinutes);
		var ibBars = day.Rth.Where(b => b.Time < ibEnd).ToList();

		if (ibBars.Count > 0)
		{
			draft.Ib = new TdBand { Low = ibBars.Min(b => b.Low), High = ibBars.Max(b => b.High) };
			draft.IbOpen = ibBars[0].Open;
			draft.IbClose = ibBars[^1].Close;
			var up = day.Rth.Max(b => b.High) > draft.Ib.High;
			var down = day.Rth.Min(b => b.Low) < draft.Ib.Low;
			draft.IbExtension = up && down ? "both" : up ? "up" : down ? "down" : "none";
			var ranges = done.Take(index).Reverse().Take(s.IbAverageDays)
				.Select(x => x.Rth.Where(b => b.Time < x.Rth[0].Time.AddMinutes(s.IbMinutes)).ToList())
				.Where(l => l.Count > 0)
				.Select(l => l.Max(b => b.High) - l.Min(b => b.Low))
				.ToList();

			if (ranges.Count > 0)
				draft.IbAverageRange = Math.Round(ranges.Average(), 2);
		}

		// naked POC: starší seance, jejichž POC cena od jejich konce nezasáhla
		foreach (var older in done.Take(index).Reverse().Take(s.NakedLookbackDays))
		{
			var p = Profile(Pick(older, s), older.Date, older.Date, s);

			if (!p.IsEmpty && !Touched(sessions, p.EndUtc, p.Poc))
				draft.NakedPocs.Add((p.Poc, older.Date));
		}

		// overnight: ETH další seance před jejím RTH, když už začalo
		var next = sessions.FirstOrDefault(x => x.Date > day.Date);

		if (next is not null && next.Eth.Count > 0)
		{
			var overnight = next.Eth.Where(b => next.Rth.Count == 0 || b.Time < next.Rth[0].Time).ToList();

			if (overnight.Count > 0)
				draft.Overnight = Profile(overnight, next.Date, next.Date, s);
		}

		return draft;
	}

	private static TdDraft? BuildWeekly(List<Session> sessions, List<Session> done, TdSettings s, DateTime nowLocal, out string reason)
	{
		reason = string.Empty;
		var last = done[^1].Date;
		var monday = TdTime.Monday(last);
		var friday = monday.AddDays(4);

		// týden je hotový v pátek po konci RTH nebo když už je po něm; jinak se bere předchozí
		if (last.DayOfWeek != DayOfWeek.Friday && nowLocal.Date <= friday)
			monday = monday.AddDays(-7);

		var week = done.Where(x => TdTime.Monday(x.Date) == monday).ToList();

		if (week.Count == 0)
		{
			reason = "Na grafu není celý minulý týden. Prodluž historii grafu.";
			return null;
		}

		var draft = new TdDraft { Kind = "weekly", Next = monday.AddDays(7) };
		draft.Profile = Profile(week.SelectMany(x => Pick(x, s)).ToList(), week[0].Date, week[^1].Date, s);
		var previous = done.Where(x => TdTime.Monday(x.Date) == monday.AddDays(-7)).ToList();

		if (previous.Count > 0)
			draft.Previous = Profile(previous.SelectMany(x => Pick(x, s)).ToList(), previous[0].Date, previous[^1].Date, s);

		Analyse(draft, s);

		var weeks = done.Where(x => x.Date < monday).GroupBy(x => TdTime.Monday(x.Date)).OrderByDescending(g => g.Key)
			.Take(Math.Max(1, s.NakedLookbackDays / 5));

		foreach (var group in weeks)
		{
			var list = group.OrderBy(x => x.Date).ToList();
			var p = Profile(list.SelectMany(x => Pick(x, s)).ToList(), list[0].Date, list[^1].Date, s);

			if (!p.IsEmpty && !Touched(sessions, p.EndUtc, p.Poc))
				draft.NakedPocs.Add((p.Poc, group.Key));
		}

		return draft;
	}

	/// <summary>Svíčky rozdělené do obchodních dní v čase burzy (ETH od 18:00 předchozího dne).</summary>
	private static List<Session> Sessions(IReadOnlyList<TdBar> bars, TdSettings s)
	{
		var map = new SortedDictionary<DateTime, Session>();

		foreach (var bar in bars)
		{
			var local = TimeZoneInfo.ConvertTimeFromUtc(DateTime.SpecifyKind(bar.Time, DateTimeKind.Unspecified), s.Exchange);
			var date = local.TimeOfDay >= s.EthStart ? local.Date.AddDays(1) : local.Date;

			if (date.DayOfWeek is DayOfWeek.Saturday or DayOfWeek.Sunday)
				date = TdTime.NextWeekday(date.AddDays(-1));

			if (!map.TryGetValue(date, out var session))
				map[date] = session = new Session { Date = date };

			session.Eth.Add(bar);

			if (local.Date == date && local.TimeOfDay >= s.RthStart && local.TimeOfDay < s.RthEnd)
				session.Rth.Add(bar);
		}

		return map.Values.ToList();
	}

	private static List<TdBar> Pick(Session session, TdSettings s)
	{
		return s.Eth ? session.Eth : session.Rth;
	}

	/// <summary>Sáhla cena po konci profilu (včetně ETH) na danou cenu?</summary>
	private static bool Touched(List<Session> sessions, DateTime afterUtc, decimal price)
	{
		return sessions.SelectMany(x => x.Eth).Any(b => b.Time > afterUtc && b.Low <= price && b.High >= price);
	}

	public static TdProfile Profile(IReadOnlyList<TdBar> bars, DateTime from, DateTime to, TdSettings s)
	{
		var p = new TdProfile { From = from, To = to, Bars = bars.Count };

		if (bars.Count == 0)
		{
			p.Low = 0;
			return p;
		}

		var tick = s.Tick > 0 ? s.Tick : 0.25m;
		p.Open = bars[0].Open;
		p.Close = bars[^1].Close;
		p.StartUtc = bars[0].Time;
		p.EndUtc = bars[^1].Time;
		var brackets = new Dictionary<long, (decimal Low, decimal High)>();
		var pv = 0m;

		foreach (var bar in bars)
		{
			p.High = Math.Max(p.High, bar.High);
			p.Low = Math.Min(p.Low, bar.Low);

			if (bar.Levels is { Count: > 0 })
			{
				foreach (var level in bar.Levels)
				{
					var price = Round(level.Price, tick);
					p.Vol[price] = p.Vol.GetValueOrDefault(price) + level.Volume;
					p.Volume += level.Volume;
					pv += price * level.Volume;
				}
			}
			else if (bar.Volume > 0)
			{
				// bez objemu na cenách: objem svíčky rovnoměrně od Low do High
				var steps = (int)((Round(bar.High, tick) - Round(bar.Low, tick)) / tick) + 1;
				var share = bar.Volume / steps;

				for (var i = 0; i < steps; i++)
				{
					var price = Round(bar.Low, tick) + i * tick;
					p.Vol[price] = p.Vol.GetValueOrDefault(price) + share;
					pv += price * share;
				}

				p.Volume += bar.Volume;
			}

			var key = (long)Math.Floor((bar.Time - bars[0].Time).TotalMinutes / Math.Max(1, s.TpoMinutes));
			brackets[key] = brackets.TryGetValue(key, out var b) ? (Math.Min(b.Low, bar.Low), Math.Max(b.High, bar.High)) : (bar.Low, bar.High);
		}

		p.High = Round(p.High, tick);
		p.Low = Round(p.Low, tick);

		foreach (var (low, high) in brackets.Values)
		{
			for (var price = Round(low, tick); price <= Round(high, tick); price += tick)
				p.Tpo[price] = p.Tpo.GetValueOrDefault(price) + 1;
		}

		p.Vwap = p.Volume > 0 ? Round(pv / p.Volume, tick) : Round((p.High + p.Low) / 2, tick);

		if (p.Vol.Count == 0)
		{
			p.Poc = p.Vwap;
			p.Vah = p.High;
			p.Val = p.Low;
			return p;
		}

		// POC: nejvyšší objem, při shodě blíž ke středu rozpětí
		var middle = (p.High + p.Low) / 2;
		p.Poc = p.Vol.OrderByDescending(x => x.Value).ThenBy(x => Math.Abs(x.Key - middle)).First().Key;
		(p.Vah, p.Val) = ValueArea(p, s);
		return p;
	}

	/// <summary>Value area jako v ATAS: od POC se přidávají ceny po krocích (ValueAreaStep) na stranu s větším objemem.</summary>
	public static (decimal Vah, decimal Val) ValueArea(TdProfile p, TdSettings s)
	{
		var prices = p.Vol.Keys.ToList();
		var volumes = p.Vol.Values.ToList();
		var poc = prices.IndexOf(p.Poc);
		var target = p.Volume * s.ValueAreaPercent / 100m;
		var volume = volumes[poc];
		var up = poc;
		var down = poc;
		var step = Math.Max(1, s.ValueAreaStep);

		while (volume < target && (up < prices.Count - 1 || down > 0))
		{
			var upperVol = 0m;
			var lowerVol = 0m;
			var upTo = Math.Min(prices.Count - 1, up + step);
			var downTo = Math.Max(0, down - step);

			for (var i = up + 1; i <= upTo; i++)
				upperVol += volumes[i];

			for (var i = down - 1; i >= downTo; i--)
				lowerVol += volumes[i];

			if (upTo == up)
				upperVol = -1;

			if (downTo == down)
				lowerVol = -1;

			if (upperVol >= lowerVol)
			{
				up = upTo;
				volume += Math.Max(0, upperVol);
			}
			else
			{
				down = downTo;
				volume += Math.Max(0, lowerVol);
			}
		}

		return (prices[up], prices[down]);
	}

	/// <summary>TPO (single prints, tails, poor high/low), HVN, LVN a tvar profilu.</summary>
	private static void Analyse(TdDraft draft, TdSettings s)
	{
		var p = draft.Profile;

		if (p.IsEmpty)
			return;

		var tick = s.Tick > 0 ? s.Tick : 0.25m;
		var ladder = new List<(decimal Price, int Count)>();

		for (var price = p.Low; price <= p.High; price += tick)
			ladder.Add((price, Math.Max(1, p.Tpo.GetValueOrDefault(price))));

		// úseky s jediným TPO: u Low kupní tail, u High prodejní tail, uvnitř single prints
		var start = -1;

		for (var i = 0; i <= ladder.Count; i++)
		{
			var single = i < ladder.Count && ladder[i].Count == 1;

			if (single && start < 0)
				start = i;

			if (single || start < 0)
				continue;

			var band = new TdBand { Low = ladder[start].Price, High = ladder[i - 1].Price };
			var length = i - start;

			if (start == 0 && length >= 2 && ladder.Count > length)
				draft.BuyingTail = band;
			else if (i == ladder.Count && length >= 2 && start > 0)
				draft.SellingTail = band;
			else if (start > 0 && i < ladder.Count && length >= s.MinSinglePrintTicks)
				draft.SinglePrints.Add(band);

			start = -1;
		}

		draft.PoorHigh = draft.SellingTail is null && ladder.Count > 1 && ladder[^1].Count >= 2;
		draft.PoorLow = draft.BuyingTail is null && ladder.Count > 1 && ladder[0].Count >= 2;

		// HVN a LVN z vyhlazeného objemu (klouzavý průměr přes 5 cen)
		var vols = ladder.Select(x => p.Vol.GetValueOrDefault(x.Price)).ToList();
		var smooth = vols.Select((_, i) => vols.Skip(Math.Max(0, i - 2)).Take(Math.Min(vols.Count, i + 3) - Math.Max(0, i - 2)).Average()).ToList();
		var max = smooth.Count > 0 ? smooth.Max() : 0;

		if (max > 0)
		{
			var peaks = new List<(decimal Price, decimal Vol)>();
			var valleys = new List<(decimal Price, decimal Vol)>();

			for (var i = 2; i < smooth.Count - 2; i++)
			{
				if (smooth[i] > smooth[i - 1] && smooth[i] >= smooth[i + 1] && smooth[i] >= 0.6m * max && Math.Abs(ladder[i].Price - p.Poc) > 4 * tick)
					peaks.Add((ladder[i].Price, smooth[i]));
			}

			// LVN: dno údolí (i ploché) s objemem nejvýš 35 % maxima, po obou stranách profilu aspoň
			// dvojnásobek; vrací se střed dna. Ne u POC a ne v single prints nebo tailu (jsou zvlášť).
			for (var i = 1; i < smooth.Count - 1; i++)
			{
				if (smooth[i] > 0.35m * max || smooth[i] >= smooth[i - 1])
					continue;

				var j = i;

				while (j + 1 < smooth.Count - 1 && Math.Abs(smooth[j + 1] - smooth[i]) <= smooth[i] * 0.02m)
					j++;

				if (smooth[j + 1] <= smooth[i])
					continue;

				var center = (i + j) / 2;
				var price = ladder[center].Price;
				var low = smooth[center];

				if (smooth.Take(i).Max() >= 2 * low && smooth.Skip(j + 1).Max() >= 2 * low && Math.Abs(price - p.Poc) > 4 * tick && !InBand(price, draft))
					valleys.Add((price, low));

				i = j;
			}

			draft.Hvn.AddRange(Spread(peaks.OrderByDescending(x => x.Vol).Select(x => x.Price), 4 * tick, 3));
			draft.Lvn.AddRange(Spread(valleys.OrderBy(x => x.Vol).Select(x => x.Price), 4 * tick, 3));
		}

		draft.Shape = Shape(draft, s);
	}

	private static bool InBand(decimal price, TdDraft draft)
	{
		return draft.SinglePrints.Append(draft.BuyingTail).Append(draft.SellingTail)
			.Any(b => b is not null && price >= b.Low && price <= b.High);
	}

	private static IEnumerable<decimal> Spread(IEnumerable<decimal> prices, decimal gap, int take)
	{
		var picked = new List<decimal>();

		foreach (var price in prices)
		{
			if (picked.All(x => Math.Abs(x - price) >= gap))
				picked.Add(price);

			if (picked.Count == take)
				break;
		}

		return picked.OrderBy(x => x);
	}

	/// <summary>Tvar: double (single prints uprostřed a objem na obou stranách), trend, P, b, jinak D.</summary>
	private static string Shape(TdDraft draft, TdSettings s)
	{
		var p = draft.Profile;
		var range = p.High - p.Low;

		if (range <= 0 || p.Volume <= 0)
			return "d";

		decimal Share(Func<decimal, bool> where) => p.Vol.Where(x => where(x.Key)).Sum(x => x.Value) / p.Volume;

		foreach (var band in draft.SinglePrints)
		{
			var mid = (band.Low + band.High) / 2;

			if (mid > p.Low + 0.25m * range && mid < p.High - 0.25m * range && Share(x => x < band.Low) >= 0.25m && Share(x => x > band.High) >= 0.25m)
				return "double";
		}

		var position = (p.Poc - p.Low) / range;
		var vaShare = (p.Vah - p.Val) / range;

		if (vaShare <= 0.4m && Math.Abs(p.Close - p.Open) >= 0.6m * range)
			return "trend";

		if (position >= 0.6m && Share(x => x <= p.Low + 0.4m * range) <= 0.25m)
			return "p";

		if (position <= 0.4m && Share(x => x >= p.High - 0.4m * range) <= 0.25m)
			return "b";

		return "d";
	}

	public static decimal Round(decimal price, decimal tick)
	{
		return tick > 0 ? Math.Round(price / tick, MidpointRounding.AwayFromZero) * tick : price;
	}

	/// <summary>Trh v Trading Desku z názvu kontraktu: ESZ6, ESZ26, ES 12-26 → ES.</summary>
	public static string MarketOf(string instrument)
	{
		var text = (instrument ?? string.Empty).Trim().ToUpperInvariant();
		var match = System.Text.RegularExpressions.Regex.Match(text, @"^([A-Z0-9]{1,4}?)(?:[FGHJKMNQUVXZ]\d{1,2}|\s+\d{1,2}-\d{2,4})(?:@.*)?$");

		if (match.Success)
			return match.Groups[1].Value;

		var root = new string(text.TakeWhile(char.IsLetterOrDigit).ToArray());
		return root.Length is > 0 and <= 16 ? root : "ES";
	}

	/// <summary>JSON pro Trading Desk (atas.php POST, nebo ruční nahrání souboru v náhledu).</summary>
	public static string ToJson(TdDraft draft, TdSettings s, DateTime nowUtc)
	{
		using var stream = new MemoryStream();

		using (var w = new Utf8JsonWriter(stream, new JsonWriterOptions { Indented = true }))
		{
			var p = draft.Profile;
			w.WriteStartObject();
			w.WriteString("format", "td-atas-draft");
			w.WriteNumber("version", 1);
			w.WriteString("kind", draft.Kind);
			w.WriteString("symbol", s.Symbol);
			w.WriteString("market", s.Market);
			w.WriteNumber("tick", s.Tick);
			w.WriteString("profile", s.Eth ? "eth" : "rth");
			w.WriteString("generated_at", nowUtc.ToString("yyyy-MM-dd'T'HH:mm:ss'Z'", CultureInfo.InvariantCulture));
			w.WriteString("source", s.Source);
			w.WriteStartObject("period");
			w.WriteString("from", Day(p.From));
			w.WriteString("to", Day(p.To));
			w.WriteEndObject();
			w.WriteString("next", Day(draft.Next));
			w.WriteNumber("open", p.Open);
			w.WriteNumber("high", p.High);
			w.WriteNumber("low", p.Low);
			w.WriteNumber("close", p.Close);
			w.WriteNumber("volume", Math.Round(p.Volume, 2));
			w.WriteNumber("poc", p.Poc);
			w.WriteNumber("vah", p.Vah);
			w.WriteNumber("val", p.Val);
			w.WriteNumber("vwap", p.Vwap);
			w.WriteString("shape", draft.Shape);

			if (draft.Ib is not null)
			{
				w.WriteStartObject("ib");
				w.WriteNumber("low", draft.Ib.Low);
				w.WriteNumber("high", draft.Ib.High);
				w.WriteNumber("open", draft.IbOpen);
				w.WriteNumber("close", draft.IbClose);
				w.WriteNumber("minutes", s.IbMinutes);

				if (draft.IbAverageRange is { } avg)
					w.WriteNumber("avg_range", avg);
				else
					w.WriteNull("avg_range");

				w.WriteEndObject();
				w.WriteString("ib_ext", draft.IbExtension);
			}

			w.WriteStartObject("prev");

			if (draft.Previous is { IsEmpty: false } prev)
			{
				w.WriteNumber("high", prev.High);
				w.WriteNumber("low", prev.Low);
				w.WriteNumber("poc", prev.Poc);
				w.WriteNumber("vah", prev.Vah);
				w.WriteNumber("val", prev.Val);
			}

			w.WriteEndObject();
			w.WriteStartArray("single_prints");

			foreach (var band in draft.SinglePrints)
				Band(w, null, band);

			w.WriteEndArray();
			w.WriteStartObject("tails");
			Band(w, "buying", draft.BuyingTail);
			Band(w, "selling", draft.SellingTail);
			w.WriteEndObject();
			w.WriteBoolean("poor_high", draft.PoorHigh);
			w.WriteBoolean("poor_low", draft.PoorLow);
			w.WriteStartArray("naked_pocs");

			foreach (var (price, date) in draft.NakedPocs)
			{
				w.WriteStartObject();
				w.WriteNumber("price", price);
				w.WriteString("date", Day(date));
				w.WriteEndObject();
			}

			w.WriteEndArray();
			Prices(w, "lvn", draft.Lvn);
			Prices(w, "hvn", draft.Hvn);

			if (draft.Overnight is { IsEmpty: false } on)
			{
				w.WriteStartObject("overnight");
				w.WriteNumber("low", on.Low);
				w.WriteNumber("high", on.High);
				w.WriteNumber("poc", on.Poc);
				w.WriteEndObject();
			}
			else
				w.WriteNull("overnight");

			w.WriteEndObject();
		}

		return Encoding.UTF8.GetString(stream.ToArray());
	}

	private static void Band(Utf8JsonWriter w, string? name, TdBand? band)
	{
		if (band is null)
		{
			if (name is not null)
				w.WriteNull(name);

			return;
		}

		if (name is null)
			w.WriteStartObject();
		else
			w.WriteStartObject(name);

		w.WriteNumber("low", band.Low);
		w.WriteNumber("high", band.High);
		w.WriteEndObject();
	}

	private static void Prices(Utf8JsonWriter w, string name, List<decimal> prices)
	{
		w.WriteStartArray(name);

		foreach (var price in prices)
		{
			w.WriteStartObject();
			w.WriteNumber("price", price);
			w.WriteEndObject();
		}

		w.WriteEndArray();
	}

	private static string Day(DateTime date)
	{
		return date.ToString("yyyy-MM-dd", CultureInfo.InvariantCulture);
	}
}
