// Náhradní třídy ATAS API, jen aby šly indikátory Trading Desku přeložit a otestovat bez ATAS.
// Kopírují názvy a podpisy, které indikátory používají (podle github.com/AtasPlatform/Indicators);
// chování je jen tolik, kolik potřebují testy. Do ATAS se nic z toho nedostane.

namespace ATAS.Indicators
{
	using System;
	using System.Collections.Generic;
	using System.Drawing;

	using OFT.Rendering.Context;

	[Flags]
	public enum DrawingLayouts
	{
		None = 0,
		Historical = 1,
		LatestBar = 2,
		Final = 4
	}

	public class PriceVolumeInfo
	{
		public decimal Price { get; set; }
		public decimal Volume { get; set; }
		public decimal Bid { get; set; }
		public decimal Ask { get; set; }
	}

	public class IndicatorCandle
	{
		public DateTime Time { get; set; }
		public DateTime LastTime { get; set; }
		public decimal Open { get; set; }
		public decimal High { get; set; }
		public decimal Low { get; set; }
		public decimal Close { get; set; }
		public decimal Volume { get; set; }
		public List<PriceVolumeInfo> Levels { get; } = new();

		public IEnumerable<PriceVolumeInfo> GetAllPriceLevels()
		{
			return Levels;
		}
	}

	public class InstrumentInfo
	{
		public string Instrument { get; set; } = "ESZ6";
		public decimal TickSize { get; set; } = 0.25m;
	}

	public class ChartInfo
	{
		public int GetXByBar(int bar)
		{
			return bar;
		}

		public int GetYByPrice(decimal price, bool flag)
		{
			return (int)price;
		}
	}

	public class Container
	{
		public Rectangle Region { get; set; } = new(0, 0, 800, 600);
	}

	public interface IDataSeries
	{
		bool IsHidden { get; set; }
	}

	public class ValueDataSeries : IDataSeries
	{
		public bool IsHidden { get; set; }
		public Drawing.VisualMode VisualType { get; set; }
	}

	public abstract class Indicator
	{
		private readonly List<IndicatorCandle> _candles = new();

		protected Indicator(bool useCandles = false)
		{
		}

		public List<IDataSeries> DataSeries { get; } = new() { new ValueDataSeries() };
		public InstrumentInfo InstrumentInfo { get; set; } = new();
		public ChartInfo? ChartInfo { get; set; } = new();
		public Container Container { get; } = new();
		public bool DenyToChangePanel { get; set; }
		public bool EnableCustomDrawing { get; set; }
		public bool DrawAbovePrice { get; set; }
		public int CurrentBar => _candles.Count;
		public int FirstVisibleBarNumber => 0;
		public int LastVisibleBarNumber => Math.Max(0, _candles.Count - 1);
		public List<string> Alerts { get; } = new();
		public int Redraws;

		public IndicatorCandle GetCandle(int bar)
		{
			return _candles[bar];
		}

		/// <summary>Jen pro testy: přidá svíčku a zavolá výpočet jako ATAS.</summary>
		public void AddCandleForTest(IndicatorCandle candle)
		{
			_candles.Add(candle);
		}

		public void RecalculateValues()
		{
			for (var i = 0; i < _candles.Count; i++)
				OnCalculate(i, _candles[i].Close);
		}

		protected void SubscribeToDrawingEvents(DrawingLayouts layouts)
		{
		}

		protected void AddAlert(string file, string message)
		{
			Alerts.Add(message);
		}

		protected void DoActionInGuiThread(Action action)
		{
			action();
		}

		protected void RedrawChart()
		{
			Redraws++;
		}

		protected virtual void OnInitialize()
		{
		}

		protected virtual void OnDispose()
		{
		}

		protected abstract void OnCalculate(int bar, decimal value);

		protected virtual void OnRender(RenderContext context, DrawingLayouts layout)
		{
		}
	}
}

namespace ATAS.Indicators.Drawing
{
	public enum VisualMode
	{
		Line,
		Hide
	}
}

namespace OFT.Rendering.Tools
{
	using System.Drawing;

	public class RenderFont
	{
		public RenderFont(string name, float size)
		{
		}
	}

	public class RenderPen
	{
		public RenderPen(Color color, int width)
		{
		}
	}
}

namespace OFT.Rendering.Context
{
	using System.Collections.Generic;
	using System.Drawing;

	using OFT.Rendering.Tools;

	public class RenderContext
	{
		public List<string> Texts { get; } = new();

		public void DrawString(string text, RenderFont font, Color color, int x, int y)
		{
			Texts.Add(text);
		}

		public void FillRectangle(Color color, Rectangle rectangle)
		{
		}

		public void DrawRectangle(RenderPen pen, Rectangle rectangle)
		{
		}

		public Size MeasureString(string text, RenderFont font)
		{
			return new Size(text.Length * 6, 12);
		}
	}
}
