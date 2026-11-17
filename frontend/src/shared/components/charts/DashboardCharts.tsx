import Chart from "react-apexcharts";
import type { ApexOptions } from "apexcharts";
import "./DashboardCharts.css";

export const CHART_PALETTE = {
  indigo: "#5b5bd6",
  teal: "#0d9488",
  sky: "#3b82f6",
  emerald: "#10b981",
  green: "#22c55e",
  amber: "#f59e0b",
  rose: "#f43f5e",
  violet: "#8b5cf6",
  slate: "#cbd5e1",
  mutedSlate: "#94a3b8",
};

const BASE_FONT = "inherit";

export function ColumnHistogramChart({
  data,
  height = 260,
  colorResolver,
}: {
  data: { label: string; count: number }[];
  height?: number;
  colorResolver?: (label: string, index: number) => string;
}) {
  const total = data.reduce((s, d) => s + d.count, 0);
  const colors = data.map((d, i) => (colorResolver ? colorResolver(d.label, i) : CHART_PALETTE.indigo));

  const options: ApexOptions = {
    chart: {
      type: "bar",
      toolbar: { show: false },
      fontFamily: BASE_FONT,
      animations: { enabled: true, speed: 450 },
    },
    plotOptions: {
      bar: {
        distributed: true,
        borderRadius: 8,
        borderRadiusApplication: "end",
        columnWidth: "48%",
        colors: {
          backgroundBarColors: ["#f1f5f9"],
          backgroundBarOpacity: 1,
          backgroundBarRadius: 8,
        },
        dataLabels: { position: "top" },
      },
    },
    colors,
    legend: { show: false },
    dataLabels: {
      enabled: true,
      offsetY: -20,
      style: { fontSize: "11px", fontWeight: 700, colors: ["#334155"] },
      formatter: (val: number) => (val > 0 ? String(val) : ""),
    },
    grid: {
      borderColor: "#eef2f6",
      strokeDashArray: 4,
      yaxis: { lines: { show: true } },
      xaxis: { lines: { show: false } },
      padding: { top: 14, right: 6, left: 6, bottom: 0 },
    },
    xaxis: {
      categories: data.map((d) => d.label),
      axisBorder: { show: false },
      axisTicks: { show: false },
      labels: { style: { colors: "#64748b", fontSize: "11.5px", fontWeight: 500 } },
    },
    yaxis: {
      min: 0,
      forceNiceScale: true,
      labels: {
        style: { colors: "#94a3b8", fontSize: "11px" },
        formatter: (val: number) => String(Math.round(val)),
      },
    },
    tooltip: {
      theme: "dark",
      y: {
        formatter: (val: number) => {
          const share = total > 0 ? Math.round((val / total) * 100) : 0;
          return `${val} sinh viên (${share}%)`;
        },
        title: { formatter: () => "Số lượng:" },
      },
    },
  };

  const series = [{ name: "Sinh viên", data: data.map((d) => d.count) }];

  return (
    <div className="dash-chart-wrap">
      <Chart options={options} series={series} type="bar" height={height} />
    </div>
  );
}

export function SemiCircleGaugeChart({
  segments,
  centerValue,
  centerLabel,
  percentValue,
}: {
  segments: { label: string; count: number; color: string }[];
  centerValue: string;
  centerLabel: string;
  percentValue: number;
}) {
  const total = segments.reduce((s, x) => s + x.count, 0);
  const clampedPct = Math.max(0, Math.min(100, percentValue));
  const mainColor =
    clampedPct >= 70 ? CHART_PALETTE.emerald : clampedPct >= 50 ? CHART_PALETTE.amber : CHART_PALETTE.rose;

  const options: ApexOptions = {
    chart: {
      type: "radialBar",
      sparkline: { enabled: true },
      fontFamily: BASE_FONT,
    },
    plotOptions: {
      radialBar: {
        startAngle: -90,
        endAngle: 90,
        hollow: { size: "66%" },
        track: {
          background: "#f1f5f9",
          strokeWidth: "100%",
          margin: 4,
        },
        dataLabels: {
          name: {
            show: true,
            offsetY: -4,
            fontSize: "12px",
            fontWeight: 500,
            color: "#64748b",
          },
          value: {
            show: true,
            offsetY: -36,
            fontSize: "26px",
            fontWeight: 700,
            color: "#0f172a",
            formatter: () => centerValue,
          },
        },
      },
    },
    fill: {
      type: "gradient",
      gradient: {
        shade: "light",
        type: "horizontal",
        gradientToColors: [CHART_PALETTE.teal],
        stops: [0, 100],
      },
    },
    colors: [mainColor],
    stroke: { lineCap: "round" },
    labels: [centerLabel],
  };

  return (
    <div className="dash-gauge-wrap">
      <div style={{ width: "100%", maxWidth: 250 }}>
        <Chart options={options} series={[clampedPct]} type="radialBar" height={205} />
      </div>

      <div className="dash-gauge-metrics">
        {segments.map((seg) => {
          const share = total > 0 ? Math.round((seg.count / total) * 100) : 0;
          return (
            <div className="dash-gauge-metric" key={seg.label}>
              <span className="dash-gauge-metric-bar" style={{ background: seg.color }} />
              <span className="dash-gauge-metric-label" title={seg.label}>
                {seg.label}
              </span>
              <span className="dash-gauge-metric-val">
                {seg.count}
                <small>{share}%</small>
              </span>
            </div>
          );
        })}
      </div>
    </div>
  );
}

export function DonutChart({
  segments,
  centerLabel,
  centerSub,
  height = 240,
}: {
  segments: { label: string; count: number; color: string }[];
  centerLabel: string | number;
  centerSub: string;
  height?: number;
}) {
  const total = segments.reduce((acc, s) => acc + s.count, 0);

  if (total === 0) {
    return <p className="muted">Chưa có dữ liệu phù hợp với bộ lọc.</p>;
  }

  const options: ApexOptions = {
    chart: {
      type: "donut",
      fontFamily: BASE_FONT,
      animations: { enabled: true, speed: 450 },
    },
    labels: segments.map((s) => s.label),
    colors: segments.map((s) => s.color),
    stroke: { width: 2, colors: ["#ffffff"] },
    dataLabels: { enabled: false },
    legend: {
      position: "bottom",
      fontSize: "12px",
      fontWeight: 500,
      labels: { colors: "#475569" },
      markers: { size: 6, strokeWidth: 0 },
      itemMargin: { horizontal: 10, vertical: 4 },
    },
    plotOptions: {
      pie: {
        donut: {
          size: "68%",
          labels: {
            show: true,
            name: { show: true, fontSize: "11.5px", color: "#64748b", offsetY: 16 },
            value: {
              show: true,
              fontSize: "22px",
              fontWeight: 700,
              color: "#0f172a",
              offsetY: -14,
              formatter: (v: string) => v,
            },
            total: {
              show: true,
              label: centerSub,
              fontSize: "11.5px",
              fontWeight: 500,
              color: "#64748b",
              formatter: () => String(centerLabel),
            },
          },
        },
      },
    },
    tooltip: {
      theme: "dark",
      y: {
        formatter: (val: number) => {
          const share = total > 0 ? Math.round((val / total) * 100) : 0;
          return `${val} (${share}%)`;
        },
      },
    },
  };

  return (
    <div className="dash-chart-wrap">
      <Chart options={options} series={segments.map((s) => s.count)} type="donut" height={height} />
    </div>
  );
}

export function AreaTrendChart({
  data,
  height = 260,
  primaryColor = CHART_PALETTE.indigo,
  secondaryColor = CHART_PALETTE.teal,
}: {
  data: { label: string; date: string; submissions: number; avgScore: number }[];
  height?: number;
  primaryColor?: string;
  secondaryColor?: string;
}) {
  if (!data.length) return null;

  const options: ApexOptions = {
    chart: {
      type: "line",
      toolbar: { show: false },
      fontFamily: BASE_FONT,
      zoom: { enabled: false },
    },
    stroke: {
      width: [2.5, 2.5],
      curve: "smooth",
      dashArray: [0, 5],
    },
    fill: {
      type: ["gradient", "solid"],
      gradient: {
        shadeIntensity: 1,
        opacityFrom: 0.34,
        opacityTo: 0.04,
        stops: [0, 95, 100],
      },
    },
    colors: [primaryColor, secondaryColor],
    markers: {
      size: [3.5, 3.5],
      strokeWidth: 2,
      hover: { size: 6 },
    },
    grid: {
      borderColor: "#eef2f6",
      strokeDashArray: 4,
      padding: { top: 8, right: 8, left: 6, bottom: 0 },
    },
    xaxis: {
      categories: data.map((d) => d.label),
      axisBorder: { show: false },
      axisTicks: { show: false },
      labels: { style: { colors: "#64748b", fontSize: "11px" } },
    },
    yaxis: [
      {
        title: { text: "Lượt nộp", style: { color: "#64748b", fontSize: "11px", fontWeight: 600 } },
        min: 0,
        forceNiceScale: true,
        labels: {
          style: { colors: "#94a3b8", fontSize: "11px" },
          formatter: (val: number) => String(Math.round(val)),
        },
      },
      {
        opposite: true,
        title: { text: "Điểm TB", style: { color: "#64748b", fontSize: "11px", fontWeight: 600 } },
        min: 0,
        max: 10,
        tickAmount: 5,
        labels: {
          style: { colors: "#94a3b8", fontSize: "11px" },
          formatter: (val: number) => val.toFixed(1),
        },
      },
    ],
    legend: {
      position: "top",
      horizontalAlign: "left",
      fontSize: "12px",
      fontWeight: 500,
      labels: { colors: "#475569" },
      markers: { size: 6, strokeWidth: 0 },
    },
    tooltip: {
      theme: "dark",
      shared: true,
      intersect: false,
      y: [
        { formatter: (val: number) => `${val} lượt nộp` },
        { formatter: (val: number) => `${Number(val).toFixed(2)} / 10` },
      ],
    },
  };

  const series = [
    { name: "Số lượt nộp", type: "area", data: data.map((d) => d.submissions) },
    { name: "Điểm TB (thang 10)", type: "line", data: data.map((d) => d.avgScore) },
  ];

  return (
    <div className="dash-chart-wrap">
      <Chart options={options} series={series} type="line" height={height} />
    </div>
  );
}

export function HorizontalStackedChart({
  rows,
  segments,
}: {
  rows: { id: string; title: string; counts: number[]; total: number }[];
  segments: { label: string; color: string }[];
}) {
  const chartHeight = Math.max(190, rows.length * 46 + 68);

  const options: ApexOptions = {
    chart: {
      type: "bar",
      stacked: true,
      stackType: "100%",
      toolbar: { show: false },
      fontFamily: BASE_FONT,
    },
    plotOptions: {
      bar: {
        horizontal: true,
        barHeight: "52%",
        borderRadius: 4,
      },
    },
    colors: segments.map((s) => s.color),
    stroke: { width: 1, colors: ["#ffffff"] },
    dataLabels: {
      enabled: true,
      style: { fontSize: "11px", fontWeight: 600, colors: ["#ffffff"] },
      formatter: (val: number) => (val >= 12 ? `${Math.round(val)}%` : ""),
    },
    grid: {
      borderColor: "#eef2f6",
      strokeDashArray: 4,
      xaxis: { lines: { show: true } },
      yaxis: { lines: { show: false } },
    },
    xaxis: {
      categories: rows.map((r) => (r.title.length > 42 ? `${r.title.slice(0, 42)}…` : r.title)),
      labels: {
        style: { colors: "#94a3b8", fontSize: "11px" },
        formatter: (v: string) => `${v}%`,
      },
    },
    yaxis: {
      labels: {
        maxWidth: 240,
        style: { colors: "#334155", fontSize: "12px", fontWeight: 500 },
      },
    },
    legend: {
      position: "top",
      horizontalAlign: "left",
      fontSize: "12px",
      fontWeight: 500,
      labels: { colors: "#475569" },
      markers: { size: 6, strokeWidth: 0 },
    },
    tooltip: {
      theme: "dark",
      y: {
        formatter: (val: number, opts) => {
          const row = opts ? rows[opts.dataPointIndex] : undefined;
          const pctVal = row && row.total > 0 ? Math.round((val / row.total) * 100) : 0;
          return `${val} / ${row?.total ?? 0} (${pctVal}%)`;
        },
      },
    },
  };

  const series = segments.map((seg, segIdx) => ({
    name: seg.label,
    data: rows.map((r) => r.counts[segIdx] ?? 0),
  }));

  return (
    <div className="dash-chart-wrap">
      <Chart options={options} series={series} type="bar" height={chartHeight} />
    </div>
  );
}

export function SkillWeaknessRadarChart({
  categories,
  height = 270,
}: {
  categories: { name: string; total: number; failed: number; failureRate: number }[];
  height?: number;
}) {
  if (!categories.length) return null;

  // Radar looks best with >= 3 axes; fallback to horizontal bar for 1-2 categories
  if (categories.length < 3) {
    const barOpts: ApexOptions = {
      chart: { type: "bar", toolbar: { show: false }, fontFamily: BASE_FONT },
      plotOptions: { bar: { horizontal: true, borderRadius: 6, barHeight: "48%" } },
      colors: [CHART_PALETTE.rose],
      xaxis: {
        max: 100,
        categories: categories.map((c) => `Phần ${c.name}`),
        labels: { formatter: (v: string) => `${v}%` },
      },
      dataLabels: { enabled: true, formatter: (v: number) => `${v}% sai` },
      tooltip: {
        theme: "dark",
        y: {
          formatter: (val: number, opts) => {
            const item = opts ? categories[opts.dataPointIndex] : undefined;
            return item ? `${val}% sai (${item.failed}/${item.total} lượt câu)` : `${val}% sai`;
          },
        },
      },
    };
    return (
      <div className="dash-chart-wrap">
        <Chart
          options={barOpts}
          series={[{ name: "Tỷ lệ sai", data: categories.map((c) => c.failureRate) }]}
          type="bar"
          height={Math.max(150, categories.length * 60 + 50)}
        />
      </div>
    );
  }

  const options: ApexOptions = {
    chart: {
      type: "radar",
      toolbar: { show: false },
      fontFamily: BASE_FONT,
    },
    xaxis: {
      categories: categories.map((c) => `Phần ${c.name}`),
      labels: { style: { colors: categories.map(() => "#334155"), fontSize: "12px", fontWeight: 600 } },
    },
    yaxis: {
      min: 0,
      max: 100,
      tickAmount: 4,
      labels: { formatter: (v: number) => `${Math.round(v)}%`, style: { fontSize: "10px", colors: "#94a3b8" } },
    },
    colors: [CHART_PALETTE.rose],
    fill: { opacity: 0.24 },
    stroke: { width: 2.2 },
    markers: { size: 4, hover: { size: 6 } },
    tooltip: {
      theme: "dark",
      y: {
        formatter: (val: number, opts) => {
          const item = opts ? categories[opts.dataPointIndex] : undefined;
          return item ? `${val}% sai (${item.failed}/${item.total} lượt câu)` : `${val}% sai`;
        },
      },
    },
  };

  return (
    <div className="dash-chart-wrap">
      <Chart
        options={options}
        series={[{ name: "Tỷ lệ làm sai (%)", data: categories.map((c) => c.failureRate) }]}
        type="radar"
        height={height}
      />
    </div>
  );
}
