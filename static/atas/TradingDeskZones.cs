// Trading Desk: zóny z denního náhledu v ATAS.
//
// Indikátor si každou minutu stáhne odkaz z Trading Desku (náhled → Sdílet → Odkaz pro ATAS)
// a nakreslí zóny posledního denního náhledu. Barva a popisek říkají, co na zóně hledat:
// zelená LONG, červená SHORT, modrá LONG i SHORT. Volitelně upozorní, když cena do zóny vstoupí.
//
// Sestavení: viz TradingDeskZones.csproj vedle (dotnet build -c Release). Hotové DLL přidej
// v ATAS v nastavení indikátorů tlačítkem „Add custom indicator“ (nebo ho zkopíruj do
// %AppData%\ATAS\Indicators) a na graf dej indikátor „Trading Desk: zóny z náhledu“.
// Kreslení a upozornění používají stejné API jako oficiální indikátory ATAS
// (github.com/AtasPlatform/Indicators: DailyLines, VerticalGrid, BarsPattern).

namespace ATAS.Indicators.TradingDesk;

using System;
using System.Collections.Generic;
using System.ComponentModel;
using System.ComponentModel.DataAnnotations;
using System.Drawing;
using System.Globalization;
using System.Net;
using System.Net.Http;
using System.Threading;
using System.Threading.Tasks;

using ATAS.Indicators.Drawing;

using OFT.Rendering.Context;
using OFT.Rendering.Tools;

[DisplayName("Trading Desk: zóny z náhledu")]
[Category("Trading Desk")]
public class TradingDeskZones : Indicator
{
	#region Nested types

	public enum ZoneFilter
	{
		[Display(Name = "Všechny zóny")]
		All,

		[Display(Name = "Jen zóny pro long")]
		Long,

		[Display(Name = "Jen zóny pro short")]
		Short
	}

	private sealed class Zone
	{
		public int Id;
		public string Name = string.Empty;
		public decimal Low;
		public decimal High;
		public string Direction = string.Empty;
		public string Priority = string.Empty;
		public string Scope = string.Empty;
	}

	private sealed class Feed
	{
		public string Market = string.Empty;
		public string Date = string.Empty;
		public long Start;
		public readonly List<Zone> Zones = new();
	}

	#endregion

	#region Fields

	private static readonly HttpClient Http = CreateClient();

	private static readonly Color LongColor = Color.FromArgb(0, 172, 124);
	private static readonly Color ShortColor = Color.FromArgb(204, 49, 72);
	private static readonly Color BothColor = Color.FromArgb(72, 132, 230);
	private static readonly Color NoneColor = Color.FromArgb(140, 140, 140);

	private readonly RenderFont _font = new("Arial", 9);
	private readonly Dictionary<Color, RenderPen> _pens = new();
	private readonly HashSet<int> _inside = new();
	private readonly Dictionary<int, DateTime> _lastAlert = new();

	private volatile Feed? _feed;
	private volatile string _status = "Vlož do nastavení odkaz z Trading Desku (náhled → Sdílet → Odkaz pro ATAS).";
	private Timer? _timer;
	private bool _initialized;
	private int _loading;
	private string _url = string.Empty;
	private int _refreshSeconds = 60;
	private int _transparency = 80;

	#endregion

	#region Properties

	[Display(Name = "Odkaz z Trading Desku", GroupName = "Zdroj", Order = 10,
		Description = "Odkaz z náhledu v Trading Desku: Sdílet → Odkaz pro ATAS. Vrací vždy poslední denní náhled trhu.")]
	public string Url
	{
		get => _url;
		set
		{
			_url = (value ?? string.Empty).Trim();
			RestartTimer();
		}
	}

	[Display(Name = "Obnovit každých (s)", GroupName = "Zdroj", Order = 20)]
	[Range(15, 3600)]
	public int RefreshSeconds
	{
		get => _refreshSeconds;
		set
		{
			_refreshSeconds = Math.Max(15, Math.Min(3600, value));
			RestartTimer();
		}
	}

	[Display(Name = "Zobrazit", GroupName = "Zóny", Order = 30,
		Description = "Jen long: zóny pro long a pro obojí. Jen short: zóny pro short a pro obojí.")]
	public ZoneFilter Show { get; set; } = ZoneFilter.All;

	[Display(Name = "Týdenní zóny", GroupName = "Zóny", Order = 40)]
	public bool ShowWeekly { get; set; } = true;

	[Display(Name = "Kreslit od začátku dne náhledu", GroupName = "Zóny", Order = 50,
		Description = "Zóny začínají v 18:00 New York předchozího dne (začátek obchodního dne). Vypnuto: přes celý graf.")]
	public bool FromPlanDay { get; set; } = true;

	[Display(Name = "Průhlednost výplně (%)", GroupName = "Vzhled", Order = 60)]
	[Range(0, 100)]
	public int Transparency
	{
		get => _transparency;
		set => _transparency = Math.Max(0, Math.Min(100, value));
	}

	[Display(Name = "Popisky zón", GroupName = "Vzhled", Order = 70)]
	public bool ShowLabels { get; set; } = true;

	[Display(Name = "Řádek s informací o náhledu", GroupName = "Vzhled", Order = 80)]
	public bool ShowInfo { get; set; } = true;

	[Display(Name = "Upozornit na vstup do zóny", GroupName = "Upozornění", Order = 90)]
	public bool UseAlerts { get; set; } = true;

	[Display(Name = "Zvuk upozornění", GroupName = "Upozornění", Order = 100)]
	public string AlertFile { get; set; } = "alert1";

	[Display(Name = "Znovu nejdřív za (min)", GroupName = "Upozornění", Order = 110)]
	[Range(1, 240)]
	public int AlertCooldownMinutes { get; set; } = 15;

	#endregion

	#region ctor

	public TradingDeskZones()
		: base(true)
	{
		DenyToChangePanel = true;
		EnableCustomDrawing = true;
		SubscribeToDrawingEvents(DrawingLayouts.Historical | DrawingLayouts.LatestBar);
		DrawAbovePrice = false;

		DataSeries[0].IsHidden = true;
		((ValueDataSeries)DataSeries[0]).VisualType = VisualMode.Hide;
	}

	#endregion

	#region Protected methods

	protected override void OnInitialize()
	{
		_initialized = true;
		RestartTimer();
	}

	protected override void OnDispose()
	{
		_initialized = false;
		_timer?.Dispose();
		_timer = null;
	}

	protected override void OnCalculate(int bar, decimal value)
	{
		// Upozornění jen na živé svíčce, ne při načítání historie.
		if (!UseAlerts || bar != CurrentBar - 1)
			return;

		var feed = _feed;

		if (feed is null)
			return;

		var price = GetCandle(bar).Close;
		var now = DateTime.UtcNow;

		foreach (var zone in feed.Zones)
		{
			if (!IsShown(zone))
				continue;

			if (price < zone.Low || price > zone.High)
			{
				_inside.Remove(zone.Id);
				continue;
			}

			if (!_inside.Add(zone.Id))
				continue;

			if (_lastAlert.TryGetValue(zone.Id, out var last) && now - last < TimeSpan.FromMinutes(AlertCooldownMinutes))
				continue;

			_lastAlert[zone.Id] = now;
			AddAlert(AlertFile, $"{InstrumentInfo.Instrument}: cena v zóně {zone.Name} {Format(zone.Low)}–{Format(zone.High)}, hledej {DirectionText(zone.Direction)}");
		}
	}

	protected override void OnRender(RenderContext context, DrawingLayouts layout)
	{
		if (ChartInfo is null)
			return;

		var region = Container.Region;
		var feed = _feed;
		var status = _status;

		if (feed is null)
		{
			if (!string.IsNullOrEmpty(status))
				context.DrawString(status, _font, NoneColor, region.X + 8, region.Bottom - 20);

			return;
		}

		var left = region.X;

		if (FromPlanDay && feed.Start > 0)
		{
			// Když den náhledu na grafu ještě nezačal (příprava večer předem), kreslí se přes
			// celý graf. Když začíná až za pravým okrajem (posun do historie), zóny k ní nepatří.
			var startBar = FirstBarFrom(DateTimeOffset.FromUnixTimeSeconds(feed.Start).UtcDateTime);

			if (startBar > LastVisibleBarNumber)
				return;

			if (startBar >= FirstVisibleBarNumber)
				left = Math.Max(region.X, ChartInfo.GetXByBar(startBar));
		}

		var right = region.Right;
		var shown = 0;

		foreach (var zone in feed.Zones)
		{
			if (!IsShown(zone))
				continue;

			var yTop = ChartInfo.GetYByPrice(zone.High, false);
			var yBottom = ChartInfo.GetYByPrice(zone.Low, false);

			if (yBottom < region.Y || yTop > region.Bottom || right <= left)
				continue;

			shown++;
			var color = ColorOf(zone.Direction);
			var rectangle = new Rectangle(left, yTop, right - left, Math.Max(1, yBottom - yTop));
			context.FillRectangle(Color.FromArgb(FillAlpha(zone), color), rectangle);
			context.DrawRectangle(PenOf(color), rectangle);

			if (!ShowLabels)
				continue;

			var label = Label(zone);
			var size = context.MeasureString(label, _font);
			context.DrawString(label, _font, color, right - size.Width - 6, Math.Max(region.Y, yTop - size.Height - 1));
		}

		if (ShowInfo)
		{
			var info = $"Trading Desk · {feed.Market} · {feed.Date} · {shown} z {feed.Zones.Count} zón";

			if (!string.IsNullOrEmpty(status))
				info += " · " + status;

			context.DrawString(info, _font, NoneColor, region.X + 8, region.Y + 6);
		}
	}

	#endregion

	#region Private methods

	private static HttpClient CreateClient()
	{
		var client = new HttpClient { Timeout = TimeSpan.FromSeconds(15) };
		client.DefaultRequestHeaders.UserAgent.ParseAdd("TradingDeskZones/1.0 (ATAS)");
		return client;
	}

	private void RestartTimer()
	{
		if (!_initialized)
			return;

		_timer?.Dispose();
		_timer = null;

		if (string.IsNullOrWhiteSpace(_url))
		{
			_feed = null;
			_status = "Vlož do nastavení odkaz z Trading Desku (náhled → Sdílet → Odkaz pro ATAS).";
			Redraw();
			return;
		}

		_timer = new Timer(_ => _ = LoadAsync(), null, TimeSpan.Zero, TimeSpan.FromSeconds(_refreshSeconds));
	}

	private async Task LoadAsync()
	{
		if (Interlocked.Exchange(ref _loading, 1) == 1)
			return;

		try
		{
			if (!Uri.TryCreate(_url, UriKind.Absolute, out var uri) || (uri.Scheme != Uri.UriSchemeHttps && uri.Scheme != Uri.UriSchemeHttp))
			{
				_feed = null;
				_status = "Odkaz musí začínat https://";
				return;
			}

			using var response = await Http.GetAsync(uri).ConfigureAwait(false);

			if (response.StatusCode == HttpStatusCode.NotFound)
			{
				_feed = null;
				_status = "Odkaz už neplatí. Vytvoř nový v Trading Desku.";
				return;
			}

			response.EnsureSuccessStatusCode();
			var text = await response.Content.ReadAsStringAsync().ConfigureAwait(false);
			_feed = Parse(text);
			_status = string.Empty;
		}
		catch (Exception error)
		{
			// Poslední načtené zóny zůstávají, jen se ukáže, že obnova selhala.
			_status = "Zóny se nepodařilo obnovit: " + error.Message;
		}
		finally
		{
			Interlocked.Exchange(ref _loading, 0);
			Redraw();
		}
	}

	private void Redraw()
	{
		try
		{
			DoActionInGuiThread(RedrawChart);
		}
		catch (Exception)
		{
			// Graf už může být zavřený.
		}
	}

	/// <summary>
	/// Řádky „klíč;hodnota“ a zóny „zone;id;název;od;do;směr;priorita;rozsah;…“.
	/// Neznámé řádky a sloupce navíc se přeskočí, takže starší indikátor přečte i novější formát.
	/// </summary>
	private static Feed Parse(string text)
	{
		var feed = new Feed();

		foreach (var raw in text.Split('\n'))
		{
			var line = raw.TrimEnd('\r');

			if (line.Length == 0 || line.StartsWith("#", StringComparison.Ordinal))
				continue;

			var parts = line.Split(';');

			switch (parts[0])
			{
				case "market" when parts.Length > 1:
					feed.Market = parts[1];
					break;
				case "date" when parts.Length > 1:
					feed.Date = parts[1];
					break;
				case "start" when parts.Length > 1:
					long.TryParse(parts[1], NumberStyles.Integer, CultureInfo.InvariantCulture, out feed.Start);
					break;
				case "zone" when parts.Length >= 8:
					if (!decimal.TryParse(parts[3], NumberStyles.Number, CultureInfo.InvariantCulture, out var low)
						|| !decimal.TryParse(parts[4], NumberStyles.Number, CultureInfo.InvariantCulture, out var high))
						break;

					int.TryParse(parts[1], NumberStyles.Integer, CultureInfo.InvariantCulture, out var id);
					feed.Zones.Add(new Zone
					{
						Id = id,
						Name = parts[2],
						Low = Math.Min(low, high),
						High = Math.Max(low, high),
						Direction = parts[5],
						Priority = parts[6],
						Scope = parts[7]
					});
					break;
			}
		}

		return feed;
	}

	private bool IsShown(Zone zone)
	{
		if (!ShowWeekly && zone.Scope == "weekly")
			return false;

		return Show switch
		{
			ZoneFilter.Long => zone.Direction is "long" or "both",
			ZoneFilter.Short => zone.Direction is "short" or "both",
			_ => true
		};
	}

	/// <summary>První svíčka v čase od začátku dne náhledu (časy svíček jsou v UTC); -1 když ještě není.</summary>
	private int FirstBarFrom(DateTime startUtc)
	{
		var low = 0;
		var high = CurrentBar - 1;
		var found = -1;

		while (low <= high)
		{
			var middle = (low + high) / 2;

			if (GetCandle(middle).Time >= startUtc)
			{
				found = middle;
				high = middle - 1;
			}
			else
				low = middle + 1;
		}

		return found;
	}

	private int FillAlpha(Zone zone)
	{
		var alpha = (int)Math.Round(255 * (100 - _transparency) / 100.0);
		return zone.Scope == "weekly" ? alpha / 2 : alpha;
	}

	private RenderPen PenOf(Color color)
	{
		if (!_pens.TryGetValue(color, out var pen))
		{
			pen = new RenderPen(Color.FromArgb(210, color), 1);
			_pens[color] = pen;
		}

		return pen;
	}

	private static Color ColorOf(string direction)
	{
		return direction switch
		{
			"long" => LongColor,
			"short" => ShortColor,
			"both" => BothColor,
			_ => NoneColor
		};
	}

	private static string DirectionText(string direction)
	{
		return direction switch
		{
			"long" => "jen LONG",
			"short" => "jen SHORT",
			"both" => "LONG i SHORT",
			_ => "směr nezadaný"
		};
	}

	private static string Label(Zone zone)
	{
		var label = zone.Name.Length > 0 ? zone.Name + " · " : string.Empty;
		label += zone.Direction switch
		{
			"long" => "LONG",
			"short" => "SHORT",
			"both" => "LONG i SHORT",
			_ => "bez směru"
		};

		if (zone.Priority.Length > 0)
			label += " · " + zone.Priority;

		if (zone.Scope == "weekly")
			label += " · týden";

		return label;
	}

	private static string Format(decimal price)
	{
		return price.ToString("0.#####", CultureInfo.InvariantCulture);
	}

	#endregion
}
