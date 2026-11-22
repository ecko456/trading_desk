// Trading Desk: nástřel denního nebo týdenního náhledu z ATAS (klasický ATAS i ATAS X).
//
// Indikátor spočítá z grafu profil poslední dokončené seance (nebo týdne): High, Low, Close,
// POC, VAH, VAL, VWAP, initial balance, single prints, tails, poor high/low, naked POC, HVN,
// LVN a overnight. Výsledek pošle do Trading Desku přes odkaz pro ATAS (stejný jako u zón)
// a uloží ho i jako soubor JSON, který jde v Trading Desku nahrát ručně. V Trading Desku pak
// v Náhledu trhu klikneš v kroku Profil na „Nástřel z ATAS“ a náhled se předvyplní;
// zkontroluješ ho a uložíš.
//
// Odeslání: v nastavení zaškrtni „Odeslat teď“, nebo nech zapnuté „Automaticky po konci
// seance“ (denní po konci RTH, týdenní v pátek). Nejlépe funguje na minutovém grafu ES
// s historií aspoň tří týdnů (kvůli naked POC a průměru IB).
//
// Výpočty jsou v TradingDeskProfile.cs (bez závislosti na ATAS). Indikátor nepoužívá WPF,
// takže ho načte i ATAS X. Sestavení: viz TradingDeskZones.csproj vedle.

namespace ATAS.Indicators.TradingDesk;

using System;
using System.Collections.Generic;
using System.ComponentModel;
using System.ComponentModel.DataAnnotations;
using System.Drawing;
using System.Globalization;
using System.IO;
using System.Linq;
using System.Net;
using System.Net.Http;
using System.Text;
using System.Threading;
using System.Threading.Tasks;

using ATAS.Indicators.Drawing;

using OFT.Rendering.Context;
using OFT.Rendering.Tools;

[DisplayName("Trading Desk: nástřel náhledu")]
[Category("Trading Desk")]
public class TradingDeskDraft : Indicator
{
	#region Nested types

	public enum DraftKind
	{
		[Display(Name = "Denní")]
		Daily,

		[Display(Name = "Týdenní")]
		Weekly,

		[Display(Name = "Denní i týdenní")]
		Both
	}

	public enum ProfileSource
	{
		[Display(Name = "RTH (seance burzy)")]
		Rth,

		[Display(Name = "Celý obchodní den (ETH)")]
		Eth
	}

	#endregion

	#region Fields

	private static readonly HttpClient Http = CreateClient();
	private static readonly Color InfoColor = Color.FromArgb(140, 140, 140);
	private static readonly Color ErrorColor = Color.FromArgb(204, 49, 72);

	private readonly RenderFont _font = new("Arial", 9);
	private volatile string _status = "Nástřel: zaškrtni v nastavení „Odeslat teď“, nebo počkej na konec seance.";
	private volatile bool _error;
	private bool _requested;
	private int _running;
	private DateTime _autoDaily;
	private DateTime _autoWeekly;
	private string _url = string.Empty;
	private string _folder = DefaultFolder();

	#endregion

	#region Properties

	[Display(Name = "Odkaz z Trading Desku", GroupName = "Trading Desk", Order = 10,
		Description = "Stejný odkaz jako pro zóny: náhled → Sdílet → Odkaz pro ATAS. Prázdné = nástřel se jen uloží do souboru.")]
	public string Url
	{
		get => _url;
		set => _url = (value ?? string.Empty).Trim();
	}

	[Display(Name = "Uložit i do souboru", GroupName = "Trading Desk", Order = 20,
		Description = "Soubor JSON jde v Trading Desku nahrát ručně (Náhled trhu → Nástřel z ATAS → Nahrát soubor).")]
	public bool SaveFile { get; set; } = true;

	[Display(Name = "Složka pro soubory", GroupName = "Trading Desk", Order = 30)]
	public string Folder
	{
		get => _folder;
		set => _folder = string.IsNullOrWhiteSpace(value) ? DefaultFolder() : value.Trim();
	}

	[Display(Name = "Trh v Trading Desku", GroupName = "Trading Desk", Order = 40,
		Description = "Prázdné = podle kontraktu na grafu (ESZ6 → ES).")]
	public string Market { get; set; } = string.Empty;

	[Display(Name = "Nástřel", GroupName = "Nástřel", Order = 50)]
	public DraftKind Kind { get; set; } = DraftKind.Both;

	[Display(Name = "Profil z", GroupName = "Nástřel", Order = 60)]
	public ProfileSource Source { get; set; } = ProfileSource.Rth;

	[Display(Name = "Odeslat teď", GroupName = "Nástřel", Order = 70,
		Description = "Zaškrtni a nástřel se hned spočítá a odešle; políčko se samo vrátí.")]
	public bool SendNow
	{
		get => false;
		set
		{
			if (!value)
				return;

			_requested = true;
			RecalculateValues();
		}
	}

	[Display(Name = "Automaticky po konci seance", GroupName = "Nástřel", Order = 80,
		Description = "Denní nástřel po konci RTH, týdenní v pátek po konci RTH. Jednou za den, dokud je ATAS spuštěný.")]
	public bool AutoAfterClose { get; set; } = true;

	[Display(Name = "Časové pásmo burzy", GroupName = "Seance", Order = 90,
		Description = "America/New_York pro CME (ES, NQ). Na Windows jde i Eastern Standard Time.")]
	public string TimeZone { get; set; } = "America/New_York";

	[Display(Name = "Začátek RTH", GroupName = "Seance", Order = 100)]
	public string RthStart { get; set; } = "09:30";

	[Display(Name = "Konec RTH", GroupName = "Seance", Order = 110)]
	public string RthEnd { get; set; } = "16:00";

	[Display(Name = "Initial balance (min)", GroupName = "Seance", Order = 120)]
	[Range(5, 240)]
	public int IbMinutes { get; set; } = 60;

	[Display(Name = "Value area (%)", GroupName = "Profil", Order = 130)]
	[Range(50, 95)]
	public int ValueAreaPercent { get; set; } = 70;

	[Display(Name = "Krok value area (ceny)", GroupName = "Profil", Order = 140,
		Description = "Kolik cen se přidává najednou; nastav stejně jako v ATAS (Settings → Value Area step).")]
	[Range(1, 10)]
	public int ValueAreaStep { get; set; } = 1;

	[Display(Name = "Naked POC za posledních dní", GroupName = "Profil", Order = 150)]
	[Range(1, 60)]
	public int NakedDays { get; set; } = 20;

	[Display(Name = "Řádek se stavem", GroupName = "Vzhled", Order = 160)]
	public bool ShowInfo { get; set; } = true;

	#endregion

	#region ctor

	public TradingDeskDraft()
		: base(true)
	{
		DenyToChangePanel = true;
		EnableCustomDrawing = true;
		SubscribeToDrawingEvents(DrawingLayouts.LatestBar);
		DataSeries[0].IsHidden = true;
		((ValueDataSeries)DataSeries[0]).VisualType = VisualMode.Hide;
	}

	#endregion

	#region Protected methods

	protected override void OnCalculate(int bar, decimal value)
	{
		if (bar != CurrentBar - 1)
			return;

		var kinds = new List<string>();

		if (_requested)
		{
			_requested = false;
			kinds.AddRange(Kinds());
		}
		else if (AutoAfterClose)
			kinds.AddRange(AutoKinds(GetCandle(bar).Time));

		if (kinds.Count == 0 || Interlocked.Exchange(ref _running, 1) == 1)
			return;

		List<TdBar> bars;
		TdSettings settings;

		try
		{
			settings = Settings();
			bars = Snapshot(settings);
		}
		catch (Exception error)
		{
			Interlocked.Exchange(ref _running, 0);
			Status("Nástřel se nespočítal: " + error.Message, true);
			return;
		}

		var now = DateTime.UtcNow;
		_ = Task.Run(() => ExportAsync(bars, settings, kinds, now));
	}

	protected override void OnRender(RenderContext context, DrawingLayouts layout)
	{
		if (!ShowInfo || ChartInfo is null)
			return;

		var status = _status;

		if (!string.IsNullOrEmpty(status))
			context.DrawString(status, _font, _error ? ErrorColor : InfoColor, Container.Region.X + 8, Container.Region.Bottom - 20);
	}

	#endregion

	#region Private methods

	private static HttpClient CreateClient()
	{
		var client = new HttpClient { Timeout = TimeSpan.FromSeconds(20) };
		client.DefaultRequestHeaders.UserAgent.ParseAdd("TradingDeskDraft/1.0 (ATAS)");
		return client;
	}

	private static string DefaultFolder()
	{
		return Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.MyDocuments), "Trading Desk", "ATAS");
	}

	private IEnumerable<string> Kinds()
	{
		return Kind switch
		{
			DraftKind.Daily => new[] { "daily" },
			DraftKind.Weekly => new[] { "weekly" },
			_ => new[] { "daily", "weekly" }
		};
	}

	/// <summary>Po konci RTH jednou za den denní nástřel, v pátek i týdenní.</summary>
	private IEnumerable<string> AutoKinds(DateTime lastBarUtc)
	{
		var settings = Settings();
		var local = TimeZoneInfo.ConvertTimeFromUtc(DateTime.SpecifyKind(lastBarUtc, DateTimeKind.Unspecified), settings.Exchange);

		if (local.TimeOfDay < settings.RthEnd || local.TimeOfDay >= settings.EthStart || local.DayOfWeek is DayOfWeek.Saturday or DayOfWeek.Sunday)
			yield break;

		if (Kind != DraftKind.Weekly && _autoDaily != local.Date)
		{
			_autoDaily = local.Date;
			yield return "daily";
		}

		if (Kind != DraftKind.Daily && local.DayOfWeek == DayOfWeek.Friday && _autoWeekly != local.Date)
		{
			_autoWeekly = local.Date;
			yield return "weekly";
		}
	}

	private TdSettings Settings()
	{
		var tick = InstrumentInfo?.TickSize ?? 0.25m;
		var instrument = InstrumentInfo?.Instrument ?? string.Empty;

		return new TdSettings
		{
			Exchange = TdTime.Find(TimeZone) ?? TdTime.NewYork(),
			RthStart = ParseTime(RthStart, new TimeSpan(9, 30, 0)),
			RthEnd = ParseTime(RthEnd, new TimeSpan(16, 0, 0)),
			IbMinutes = IbMinutes,
			ValueAreaPercent = ValueAreaPercent,
			ValueAreaStep = ValueAreaStep,
			NakedLookbackDays = NakedDays,
			Tick = tick > 0 ? tick : 0.25m,
			Eth = Source == ProfileSource.Eth,
			Symbol = instrument,
			Market = string.IsNullOrWhiteSpace(Market) ? TdDraftBuilder.MarketOf(instrument) : Market.Trim().ToUpperInvariant()
		};
	}

	private static TimeSpan ParseTime(string text, TimeSpan fallback)
	{
		return TimeSpan.TryParseExact((text ?? string.Empty).Trim(), new[] { @"h\:mm", @"hh\:mm" }, CultureInfo.InvariantCulture, out var time) ? time : fallback;
	}

	/// <summary>Svíčky za posledních NakedDays + rezervu (čte se na vlákně výpočtu, export běží mimo něj).</summary>
	private List<TdBar> Snapshot(TdSettings settings)
	{
		var bars = new List<TdBar>();
		var last = GetCandle(CurrentBar - 1).Time;
		var from = last.AddDays(-(settings.NakedLookbackDays * 7 / 5 + 14));

		for (var i = 0; i < CurrentBar; i++)
		{
			var candle = GetCandle(i);

			if (candle.Time < from)
				continue;

			var levels = new List<TdLevel>();

			foreach (var info in candle.GetAllPriceLevels())
				levels.Add(new TdLevel(info.Price, info.Volume));

			bars.Add(new TdBar
			{
				Time = candle.Time,
				Open = candle.Open,
				High = candle.High,
				Low = candle.Low,
				Close = candle.Close,
				Volume = candle.Volume,
				Levels = levels
			});
		}

		return bars;
	}

	private async Task ExportAsync(List<TdBar> bars, TdSettings settings, List<string> kinds, DateTime now)
	{
		var done = new List<string>();

		try
		{
			foreach (var kind in kinds)
			{
				var draft = TdDraftBuilder.Build(bars, settings, kind, now, out var reason);

				if (draft is null)
				{
					Status("Nástřel: " + reason, true);
					return;
				}

				var json = TdDraftBuilder.ToJson(draft, settings, now);
				var label = (kind == "weekly" ? "týden " : "den ") + draft.Profile.To.ToString("d. M.", CultureInfo.InvariantCulture);

				if (SaveFile)
				{
					Directory.CreateDirectory(_folder);
					var name = $"TradingDesk-{settings.Market}-{kind}-{draft.Profile.To:yyyy-MM-dd}.json";
					await File.WriteAllTextAsync(Path.Combine(_folder, name), json, new UTF8Encoding(false)).ConfigureAwait(false);
				}

				if (!string.IsNullOrWhiteSpace(_url))
					await SendAsync(json).ConfigureAwait(false);

				done.Add(label);
			}

			var where = string.IsNullOrWhiteSpace(_url) ? "uloženo do souboru" : "odesláno do Trading Desku";
			Status($"Nástřel ({string.Join(", ", done)}) {where} {DateTime.Now:HH:mm}.", false);
		}
		catch (Exception error)
		{
			Status("Nástřel se neodeslal: " + error.Message + (SaveFile ? " Soubor je ve složce " + _folder + "." : string.Empty), true);
		}
		finally
		{
			Interlocked.Exchange(ref _running, 0);
		}
	}

	private async Task SendAsync(string json)
	{
		if (!Uri.TryCreate(_url, UriKind.Absolute, out var uri) || (uri.Scheme != Uri.UriSchemeHttps && uri.Scheme != Uri.UriSchemeHttp))
			throw new InvalidOperationException("odkaz musí začínat https://.");

		using var content = new StringContent(json, Encoding.UTF8, "application/json");
		using var response = await Http.PostAsync(uri, content).ConfigureAwait(false);

		if (response.StatusCode == HttpStatusCode.NotFound)
			throw new InvalidOperationException("odkaz už neplatí, vytvoř nový v Trading Desku.");

		if (!response.IsSuccessStatusCode)
		{
			var text = await response.Content.ReadAsStringAsync().ConfigureAwait(false);
			throw new InvalidOperationException($"server odpověděl {(int)response.StatusCode}: {Shorten(text)}");
		}
	}

	private static string Shorten(string text)
	{
		text = (text ?? string.Empty).Replace('\n', ' ').Trim();
		return text.Length > 160 ? text[..160] + "…" : text;
	}

	private void Status(string text, bool error)
	{
		_status = text;
		_error = error;

		try
		{
			DoActionInGuiThread(RedrawChart);
		}
		catch (Exception)
		{
			// Graf už může být zavřený.
		}
	}

	#endregion
}
