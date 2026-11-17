import { useState } from "react";
import { Activity, AlertTriangle, Award, BarChart3, Clock, Target, Users } from "lucide-react";
import { teacherService, type TeacherStatistics } from "@/shared/api/teacherService";
import { useLoad } from "@/shared/hooks/useLoad";
import { Select } from "@/shared/ui/Select";
import { MultiSelect } from "@/shared/ui/MultiSelect";
import { ErrorState, Loading } from "@/shared/ui/ui";
import {
  AreaTrendChart,
  CHART_PALETTE,
  ColumnHistogramChart,
  DonutChart,
  HorizontalStackedChart,
  SemiCircleGaugeChart,
  SkillWeaknessRadarChart,
} from "@/shared/components/charts/DashboardCharts";
import "@/features/teacher/statistics/StatisticsPage.css";

type Kind = "assignments" | "exams" | "all";
const pct = (part: number, total: number) => (total ? Math.round((part / total) * 100) : 0);

const VERDICT_COLORS: Record<string, string> = {
  Accepted: CHART_PALETTE.emerald,
  Partial: CHART_PALETTE.amber,
  "Wrong Answer": CHART_PALETTE.rose,
  "Needs review": CHART_PALETTE.mutedSlate,
};

const VERDICT_LABELS: Record<string, string> = {
  Accepted: "Đạt tối đa",
  Partial: "Đạt một phần",
  "Wrong Answer": "Chưa đạt",
  "Needs review": "Chờ chấm",
};

const histogramColor = (label: string) => {
  if (label.startsWith("0") || label.startsWith("2")) return CHART_PALETTE.rose;
  if (label.startsWith("4")) return CHART_PALETTE.amber;
  if (label.startsWith("5") || label.startsWith("6")) return CHART_PALETTE.sky;
  return CHART_PALETTE.emerald;
};

export function StatisticsPage() {
  const [kind, setKind] = useState<Kind>("all");
  const [term, setTerm] = useState("");
  const [classIds, setClassIds] = useState<string[]>([]);
  const [activityIds, setActivityIds] = useState<string[]>([]);
  const [dateFrom, setDateFrom] = useState("");
  const [dateTo, setDateTo] = useState("");

  const { data, loading, error } = useLoad(
    () => teacherService.getStatistics({ kind, term, classIds, activityIds, dateFrom, dateTo }),
    [kind, term, classIds.join("|"), activityIds.join("|"), dateFrom, dateTo]
  );

  const resetAll = () => {
    setKind("all");
    setTerm("");
    setClassIds([]);
    setActivityIds([]);
    setDateFrom("");
    setDateTo("");
  };

  return (
    <section className="teacher-page statistics-page">
      <div className="statistics-header">
        <div>
          <h1>Dashboard Giảng viên</h1>
          <p>Theo dõi kết quả học tập, tiến độ tham gia và điểm yếu kỹ năng SQL của các lớp phụ trách.</p>
        </div>
        <button type="button" className="button" onClick={resetAll}>
          Đặt lại bộ lọc
        </button>
      </div>

      <div className="statistics-filters" role="group" aria-label="Bộ lọc thống kê giảng viên">
        <div className="statistics-kind" role="group" aria-label="Loại hoạt động">
          {([
            ["all", "Tất cả"],
            ["assignments", "Bài tập"],
            ["exams", "Kỳ thi"],
          ] as const).map(([value, label]) => (
            <button
              key={value}
              type="button"
              aria-pressed={kind === value}
              onClick={() => {
                setKind(value);
                setActivityIds([]);
              }}
            >
              {label}
            </button>
          ))}
        </div>

        <label className="statistics-field">
          <span>Học kỳ</span>
          <Select
            value={term}
            onChange={(e) => {
              setTerm(e.target.value);
              setClassIds([]);
              setActivityIds([]);
            }}
          >
            <option value="">Tất cả học kỳ</option>
            {(data?.terms || []).map((v) => (
              <option key={v} value={v}>
                {v}
              </option>
            ))}
          </Select>
        </label>

        <label className="statistics-field">
          <span>Lớp học phần</span>
          <MultiSelect
            label="Lớp học phần"
            allLabel="Tất cả lớp phụ trách"
            countLabel={(c) => `Đã chọn ${c} lớp`}
            options={(data?.classes || []).map((row) => ({
              value: row.id,
              label: row.id,
              hint: `${row.course} · ${row.students} SV`,
            }))}
            values={classIds}
            onChange={(values) => {
              setClassIds(values);
              setActivityIds([]);
            }}
          />
        </label>

        <label className="statistics-field">
          <span>Bài tập / Kỳ thi</span>
          <MultiSelect
            label="Hoạt động"
            allLabel="Tất cả hoạt động"
            countLabel={(c) => `Đã chọn ${c}`}
            options={(data?.activities || []).map((row) => ({
              value: row.id,
              label: row.title,
            }))}
            values={activityIds}
            onChange={setActivityIds}
          />
        </label>

        <label className="statistics-field">
          <span>Từ ngày</span>
          <input type="date" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} />
        </label>

        <label className="statistics-field">
          <span>Đến ngày</span>
          <input type="date" value={dateTo} onChange={(e) => setDateTo(e.target.value)} />
        </label>
      </div>

      {error ? (
        <ErrorState title="Không thể tải dữ liệu thống kê" message={error} onRetry={() => window.location.reload()} />
      ) : !data ? (
        <Loading variant="inline" rows={6} label="Đang tải dữ liệu biểu đồ…" />
      ) : (
        <div className={`statistics-body${loading ? " is-refreshing" : ""}`} aria-busy={loading}>
          <TeacherKpis data={data} />

          {/* Mục 1: Tổng quan chất lượng & Kết quả học tập */}
          <section className="statistics-section" aria-labelledby="teacher-sec-1">
            <div className="statistics-section-head">
              <span className="statistics-section-badge" aria-hidden="true">
                <BarChart3 size={18} />
              </span>
              <div>
                <h2 id="teacher-sec-1">Tổng quan chất lượng &amp; Kết quả học tập</h2>
                <p>Đánh giá phân bố điểm số và tỷ lệ sinh viên đạt chuẩn trong phạm vi giảng dạy.</p>
              </div>
            </div>
            <div className="statistics-bento">
              <article className="statistics-card col-span-8">
                <header>
                  <div>
                    <h3>Phổ điểm sinh viên (thang 10)</h3>
                    <p>Số lượng sinh viên theo từng khoảng điểm trung bình</p>
                  </div>
                  <span className="dash-pill is-neutral">{data.kpis.participants} SV có điểm</span>
                </header>
                {data.kpis.participants === 0 ? (
                  <p className="muted">Chưa có dữ liệu điểm phù hợp với bộ lọc.</p>
                ) : (
                  <ColumnHistogramChart data={data.distribution} colorResolver={histogramColor} />
                )}
              </article>

              <article className="statistics-card col-span-4">
                <header>
                  <div>
                    <h3>Tỷ lệ đạt chuẩn (&ge; 5.0)</h3>
                    <p>Mức độ hoàn thành mục tiêu môn học</p>
                  </div>
                </header>
                <SemiCircleGaugeChart
                  percentValue={data.kpis.passRate ?? 0}
                  centerValue={`${data.kpis.passRate ?? 0}%`}
                  centerLabel="Tỷ lệ đạt"
                  segments={[
                    {
                      label: "Đạt (≥ 5.0)",
                      count: Math.max(0, data.kpis.participants - data.kpis.below),
                      color: CHART_PALETTE.emerald,
                    },
                    {
                      label: "Dưới TB",
                      count: data.kpis.below,
                      color: CHART_PALETTE.rose,
                    },
                    {
                      label: "Chưa nộp",
                      count: Math.max(0, data.kpis.students - data.kpis.participants),
                      color: CHART_PALETTE.slate,
                    },
                  ]}
                />
              </article>
            </div>
          </section>

          {/* Mục 2: Xu hướng làm bài & Cơ cấu bài nộp */}
          <section className="statistics-section" aria-labelledby="teacher-sec-2">
            <div className="statistics-section-head">
              <span className="statistics-section-badge" aria-hidden="true">
                <Activity size={18} />
              </span>
              <div>
                <h2 id="teacher-sec-2">Xu hướng làm bài &amp; Cơ cấu kết quả nộp</h2>
                <p>Theo dõi mật độ nộp bài theo thời gian và tỷ lệ bài đạt tối đa / một phần.</p>
              </div>
            </div>
            <div className="statistics-bento">
              <article className="statistics-card col-span-8">
                <header>
                  <div>
                    <h3>Xu hướng lượt nộp &amp; điểm TB theo ngày</h3>
                    <p>Quan sát độ tích cực làm bài và biến động điểm số qua từng ngày</p>
                  </div>
                </header>
                {!data.trend?.length ? (
                  <p className="muted">Chưa có dữ liệu lượt nộp theo ngày trong khoảng lọc này.</p>
                ) : (
                  <AreaTrendChart data={data.trend} primaryColor={CHART_PALETTE.indigo} secondaryColor={CHART_PALETTE.teal} />
                )}
              </article>

              <article className="statistics-card col-span-4">
                <header>
                  <div>
                    <h3>Cơ cấu kết quả bài nộp</h3>
                    <p>Phân loại theo trạng thái chấm tự động</p>
                  </div>
                </header>
                <DonutChart
                  segments={(data.verdictBreakdown || []).map((v) => ({
                    label: VERDICT_LABELS[v.label] || v.label,
                    count: v.count,
                    color: VERDICT_COLORS[v.label] || CHART_PALETTE.indigo,
                  }))}
                  centerLabel={data.verdictBreakdown?.reduce((a, b) => a + b.count, 0) ?? 0}
                  centerSub="bài nộp"
                />
              </article>
            </div>
          </section>

          {/* Mục 3: So sánh lớp học, Kỹ năng SQL & Tiến độ hoạt động */}
          <section className="statistics-section" aria-labelledby="teacher-sec-3">
            <div className="statistics-section-head">
              <span className="statistics-section-badge" aria-hidden="true">
                <Target size={18} />
              </span>
              <div>
                <h2 id="teacher-sec-3">Lớp học phụ trách &amp; Kỹ năng SQL cần cải thiện</h2>
                <p>Đối chiếu kết quả từng lớp, nhận diện dạng bài hay sai và kiểm tra tiến độ hoàn thành hoạt động.</p>
              </div>
            </div>
            <div className="statistics-bento">
              <article className="statistics-card col-span-7">
                <header>
                  <div>
                    <h3>Bảng so sánh kết quả theo lớp</h3>
                    <p>Điểm trung bình thang 10 và tỷ lệ đạt (&ge; 5.0) từng lớp</p>
                  </div>
                </header>
                {!data.classAverages?.length ? (
                  <p className="muted">Chưa có lớp học nào trong phạm vi lọc.</p>
                ) : (
                  <div className="dash-table-wrap">
                    <table className="dash-table">
                      <thead>
                        <tr>
                          <th>Lớp học phần</th>
                          <th>Tham gia</th>
                          <th>Điểm TB</th>
                          <th>Tỷ lệ đạt</th>
                        </tr>
                      </thead>
                      <tbody>
                        {data.classAverages.map((c) => {
                          const score = c.averageScore;
                          const pillClass =
                            score === null ? "is-neutral" : c.passRate >= 70 ? "is-good" : c.passRate >= 50 ? "is-warn" : "is-bad";
                          const barColor =
                            score === null
                              ? CHART_PALETTE.slate
                              : score >= 7
                              ? CHART_PALETTE.emerald
                              : score >= 5
                              ? CHART_PALETTE.indigo
                              : CHART_PALETTE.rose;
                          return (
                            <tr key={c.id}>
                              <td>
                                <span className="dash-table-main">{c.id}</span>
                                <span className="dash-table-sub">{c.course}</span>
                              </td>
                              <td>
                                {c.participants}/{c.students} SV
                              </td>
                              <td>
                                <span className="dash-score-cell">
                                  <span className="dash-mini-track">
                                    <span style={{ width: `${((score ?? 0) / 10) * 100}%`, background: barColor }} />
                                  </span>
                                  {score === null ? "—" : score.toFixed(1)}
                                </span>
                              </td>
                              <td>
                                <span className={`dash-pill ${pillClass}`}>{c.passRate}%</span>
                              </td>
                            </tr>
                          );
                        })}
                      </tbody>
                    </table>
                  </div>
                )}
              </article>

              <article className="statistics-card col-span-5">
                <header>
                  <div>
                    <h3>Bản đồ điểm yếu kỹ năng SQL</h3>
                    <p>Tỷ lệ câu chưa đạt theo từng nhóm kiến thức (DDL, DML, SQL, Trigger)</p>
                  </div>
                </header>
                {data.weakCategories.length === 0 ? (
                  <p className="muted">Chưa có dữ liệu chi tiết theo phần thi/bài tập.</p>
                ) : (
                  <SkillWeaknessRadarChart categories={data.weakCategories} />
                )}
              </article>

              <article className="statistics-card col-span-12">
                <header>
                  <div>
                    <h3>Tiến độ hoàn thành theo hoạt động</h3>
                    <p>Tỷ lệ sinh viên đã nộp, đang làm dở và chưa bắt đầu</p>
                  </div>
                </header>
                {data.progress.length === 0 ? (
                  <p className="muted">Không có hoạt động nào khớp bộ lọc.</p>
                ) : (
                  <HorizontalStackedChart
                    rows={data.progress.map((p) => ({
                      id: p.id,
                      title: p.title,
                      counts: [p.done, p.inProgress, p.notStarted],
                      total: p.total,
                    }))}
                    segments={[
                      { label: "Đã nộp", color: CHART_PALETTE.emerald },
                      { label: "Đang làm", color: CHART_PALETTE.amber },
                      { label: "Chưa làm", color: CHART_PALETTE.slate },
                    ]}
                  />
                )}
              </article>
            </div>
          </section>
        </div>
      )}
    </section>
  );
}

function TeacherKpis({ data }: { data: TeacherStatistics }) {
  const { students, participants, averageScore, below, passRate = 0, pendingGrading = 0 } = data.kpis;
  const items = [
    {
      label: "Sinh viên trong phạm vi",
      value: students,
      note: `${participants} đã nộp (${pct(participants, students)}%)`,
      accent: CHART_PALETTE.indigo,
      tint: "#eef2ff",
      Icon: Users,
    },
    {
      label: "Điểm trung bình",
      value: averageScore === null ? "—" : averageScore.toFixed(2),
      note: `Thang 10 · Tỷ lệ đạt ${passRate}%`,
      accent: CHART_PALETTE.emerald,
      tint: "#ecfdf5",
      Icon: Award,
    },
    {
      label: "Dưới trung bình (< 5.0)",
      value: below,
      note: participants ? `${pct(below, participants)}% SV đã nộp` : "Chưa có dữ liệu",
      accent: CHART_PALETTE.rose,
      tint: "#fff1f2",
      Icon: AlertTriangle,
    },
    {
      label: "Bài chờ chấm tay",
      value: pendingGrading,
      note: `${data.kpis.submissions ?? 0} lượt nộp chính thức`,
      accent: CHART_PALETTE.amber,
      tint: "#fffbeb",
      Icon: Clock,
    },
  ];

  return (
    <dl className="statistics-kpis">
      {items.map(({ label, value, note, accent, tint, Icon }) => (
        <div
          className="statistics-kpi"
          key={label}
          style={{ ["--kpi-accent" as string]: accent, ["--kpi-tint" as string]: tint }}
        >
          <div className="statistics-kpi-top">
            <dt>{label}</dt>
            <span className="statistics-kpi-icon" aria-hidden="true">
              <Icon size={18} />
            </span>
          </div>
          <dd>
            <span className="statistics-kpi-value">{value}</span>
            <span className="statistics-kpi-sub">{note}</span>
          </dd>
        </div>
      ))}
    </dl>
  );
}
