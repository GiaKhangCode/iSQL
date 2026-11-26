import { useState } from "react";
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
} from "@/shared/components/charts/DashboardCharts";
import "@/features/teacher/statistics/StatisticsPage.css";

type Kind = "all" | "exams" | "assignments";
const pct = (part: number, total: number) => (total ? Math.round((part / total) * 100) : 0);

const histogramColor = (label: string) => {
  if (label.startsWith("0") || label.startsWith("2")) return CHART_PALETTE.rose;
  if (label.startsWith("4")) return CHART_PALETTE.amber;
  if (label.startsWith("5") || label.startsWith("6")) return CHART_PALETTE.sky;
  return CHART_PALETTE.emerald;
};

export function OfficeStatisticsPage() {
  const [kind, setKind] = useState<Kind>("all");
  const [term, setTerm] = useState("");
  const [course, setCourse] = useState("");
  const [examinationId, setExaminationId] = useState("");
  const [classIds, setClassIds] = useState<string[]>([]);
  const [activityIds, setActivityIds] = useState<string[]>([]);
  const [dateFrom, setDateFrom] = useState("");
  const [dateTo, setDateTo] = useState("");

  const { data, loading, error } = useLoad(
    () =>
      teacherService.getOfficeStatistics({
        kind,
        term,
        course,
        examinationId,
        classIds,
        activityIds,
        dateFrom,
        dateTo,
      }),
    [kind, term, course, examinationId, classIds.join("|"), activityIds.join("|"), dateFrom, dateTo]
  );

  const resetAll = () => {
    setKind("all");
    setTerm("");
    setCourse("");
    setExaminationId("");
    setClassIds([]);
    setActivityIds([]);
    setDateFrom("");
    setDateTo("");
  };

  const filtersActive = kind !== "all" || !!(term || course || examinationId || dateFrom || dateTo) || classIds.length > 0 || activityIds.length > 0;

  const office = data?.officeOverview;
  const regrade = office?.regradeSummary;

  return (
    <section className="teacher-page statistics-page">
      <div className="statistics-filters office-filters" role="group" aria-label="Bộ lọc thống kê giáo vụ khoa">
        <div className="office-filters-top">
          <div className="statistics-kind" role="group" aria-label="Phạm vi hoạt động">
            {([
              ["all", "Tất cả"],
              ["exams", "Kỳ thi / Ca thi"],
              ["assignments", "Bài tập"],
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
          <button type="button" className="office-filters-reset" onClick={resetAll} disabled={!filtersActive}>
            Đặt lại bộ lọc
          </button>
        </div>

        <div className="office-filters-grid">
        <label className="statistics-field">
          <span>Học kỳ</span>
          <Select
            value={term}
            onChange={(e) => {
              setTerm(e.target.value);
              setClassIds([]);
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
          <span>Môn học</span>
          <Select
            value={course}
            onChange={(e) => {
              setCourse(e.target.value);
              setClassIds([]);
            }}
          >
            <option value="">Tất cả môn học</option>
            {(data?.courses || []).map((v) => (
              <option key={v} value={v}>
                {v}
              </option>
            ))}
          </Select>
        </label>

        <label className="statistics-field">
          <span>Kỳ thi</span>
          <Select
            value={examinationId}
            onChange={(e) => {
              setExaminationId(e.target.value);
              setActivityIds([]);
            }}
          >
            <option value="">Tất cả kỳ thi</option>
            {(data?.examinations || []).map((ex) => (
              <option key={ex.id} value={ex.id}>
                {ex.title}
              </option>
            ))}
          </Select>
        </label>

        <label className="statistics-field">
          <span>Lớp học phần</span>
          <MultiSelect
            label="Lớp học phần"
            allLabel="Tất cả lớp"
            countLabel={(c) => `Đã chọn ${c} lớp`}
            options={(data?.classes || []).map((row) => ({
              value: row.id,
              label: row.id,
              hint: `${row.course} · ${row.students} SV`,
            }))}
            values={classIds}
            onChange={setClassIds}
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
      </div>

      {error ? (
        <ErrorState title="Không thể tải dữ liệu Dashboard" message={error} onRetry={() => window.location.reload()} />
      ) : !data ? (
        <Loading variant="inline" rows={6} label="Đang tổng hợp dữ liệu toàn khoa…" />
      ) : (
        <div className={`statistics-body${loading ? " is-refreshing" : ""}`} aria-busy={loading}>
          <OfficeKpis data={data} />

          {/* Mục 1: Chất lượng & Phân hóa kết quả toàn khoa */}
          <section className="statistics-section" aria-label="Chất lượng và phân hóa kết quả toàn khoa">
            <div className="statistics-bento">
              <article className="statistics-card col-span-8">
                <header>
                  <div>
                    <h3>Phổ điểm toàn khoa (thang 10)</h3>
                    <p>Mức độ phân hóa điểm số của toàn bộ sinh viên tham gia</p>
                  </div>
                  <span className="dash-pill is-neutral">{data.kpis.participants} SV có điểm</span>
                </header>
                {data.kpis.participants === 0 ? (
                  <p className="muted">Chưa có dữ liệu điểm trong phạm vi lọc.</p>
                ) : (
                  <ColumnHistogramChart data={data.distribution} colorResolver={histogramColor} />
                )}
              </article>

              <article className="statistics-card col-span-4">
                <header>
                  <div>
                    <h3>Tỷ lệ Đạt / Không đạt &amp; Vắng</h3>
                    <p>Cơ cấu sinh viên theo ngưỡng đạt 5.0</p>
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
                      label: "Không đạt",
                      count: data.kpis.below,
                      color: CHART_PALETTE.rose,
                    },
                    {
                      label: "Vắng / Chưa nộp",
                      count: Math.max(0, data.kpis.students - data.kpis.participants),
                      color: CHART_PALETTE.slate,
                    },
                  ]}
                />
              </article>
            </div>
          </section>

          {/* Mục 2: chấm thi và phúc khảo */}
          <section className="statistics-section" aria-label="Vận hành chấm thi và xử lý phúc khảo">
            <div className="statistics-bento">
              <article className="statistics-card col-span-7">
                <header>
                  <div>
                    <h3>Tiến độ chấm điểm theo kỳ thi / hoạt động</h3>
                    <p>Tình trạng hoàn tất chấm điểm và xử lý phúc khảo từng bài thi</p>
                  </div>
                </header>
                {!office?.gradingByActivity.length ? (
                  <p className="muted">Chưa có bài nộp nào trong phạm vi lọc.</p>
                ) : (
                  <HorizontalStackedChart
                    rows={office.gradingByActivity.map((g) => ({
                      id: g.id,
                      title: g.title,
                      counts: [g.graded, g.regraded, g.awaitingRegrade, g.needsReview],
                      total: g.total,
                    }))}
                    segments={[
                      { label: "Đã chấm", color: CHART_PALETTE.emerald },
                      { label: "Đã phúc khảo", color: CHART_PALETTE.sky },
                      { label: "Chờ phúc khảo", color: CHART_PALETTE.violet },
                      { label: "Chờ chấm", color: CHART_PALETTE.amber },
                    ]}
                  />
                )}
              </article>

              <article className="statistics-card col-span-5">
                <header>
                  <div>
                    <h3>Tình hình phúc khảo bài thi</h3>
                    <p>Giảng viên được phân công và tiến độ chấm lại</p>
                  </div>
                  {regrade && <span className="dash-pill is-good">Đổi điểm: {regrade.scoreChanged}</span>}
                </header>
                <DonutChart
                  segments={[
                    {
                      label: "Đang chờ chấm phúc khảo",
                      count: regrade?.awaitingRegrade ?? 0,
                      color: CHART_PALETTE.amber,
                    },
                    {
                      label: "Đã phúc khảo xong",
                      count: regrade?.completedRegrade ?? 0,
                      color: CHART_PALETTE.emerald,
                    },
                  ]}
                  centerLabel={
                    (regrade?.awaitingRegrade ?? 0) +
                    (regrade?.completedRegrade ?? 0)
                  }
                  centerSub="hồ sơ phúc khảo"
                />
              </article>
            </div>
          </section>

          {/* Mục 3: so sánh môn học và lưu lượng */}
          <section className="statistics-section" aria-label="Mặt bằng môn học và lưu lượng hệ thống">
            <div className="statistics-bento">
              <article className="statistics-card col-span-5">
                <header>
                  <div>
                    <h3>So sánh kết quả theo môn học</h3>
                    <p>Điểm trung bình và tỷ lệ đạt giữa các môn</p>
                  </div>
                </header>
                {!office?.courseAverages.length ? (
                  <p className="muted">Chưa có dữ liệu môn học.</p>
                ) : (
                  <div className="dash-table-wrap">
                    <table className="dash-table">
                      <thead>
                        <tr>
                          <th>Môn học</th>
                          <th>Điểm TB</th>
                          <th>Tỷ lệ đạt</th>
                        </tr>
                      </thead>
                      <tbody>
                        {office.courseAverages.map((c) => {
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
                            <tr key={c.course}>
                              <td>
                                <span className="dash-table-main">{c.course}</span>
                                <span className="dash-table-sub">
                                  {c.participants}/{c.students} SV tham gia
                                </span>
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

              <article className="statistics-card col-span-7">
                <header>
                  <div>
                    <h3>Xu hướng lượt nộp &amp; điểm TB toàn khoa</h3>
                    <p>Số lượt nộp chính thức và điểm trung bình (thang 10) qua từng ngày</p>
                  </div>
                </header>
                {!data.trend?.length ? (
                  <p className="muted">Chưa có dữ liệu theo ngày trong khoảng lọc này.</p>
                ) : (
                  <AreaTrendChart data={data.trend} primaryColor={CHART_PALETTE.teal} secondaryColor={CHART_PALETTE.indigo} />
                )}
              </article>
            </div>
          </section>
        </div>
      )}
    </section>
  );
}

function OfficeKpis({ data }: { data: TeacherStatistics }) {
  const office = data.officeOverview;
  const regradeTotal =
    (office?.regradeSummary.awaitingRegrade ?? 0) +
    (office?.regradeSummary.completedRegrade ?? 0);

  const items = [
    {
      label: "Kỳ thi / Hoạt động",
      value: office?.totalActivities ?? 0,
      note: `${office?.totalExaminations ?? 0} kỳ thi chính thức`,
      accent: CHART_PALETTE.indigo,
      tint: "#eef2ff",
    },
    {
      label: "Sinh viên tham gia",
      value: `${data.kpis.participants}/${data.kpis.students}`,
      note: `Tỷ lệ tham dự ${pct(data.kpis.participants, data.kpis.students)}%`,
      accent: CHART_PALETTE.sky,
      tint: "#eff6ff",
    },
    {
      label: "Điểm TB toàn khoa",
      value: data.kpis.averageScore === null ? "—" : data.kpis.averageScore.toFixed(2),
      note: `Thang 10 · Tỷ lệ đạt ${data.kpis.passRate ?? 0}%`,
      accent: CHART_PALETTE.emerald,
      tint: "#ecfdf5",
    },
    {
      label: "Phúc khảo & Vi phạm thi",
      value: regradeTotal,
      note: `${office?.regradeSummary.awaitingRegrade ?? 0} chờ chấm · ${office?.violationEvents ?? 0} lỗi rời phòng`,
      accent: CHART_PALETTE.amber,
      tint: "#fffbeb",
    },
  ];

  return (
    <dl className="statistics-kpis">
      {items.map(({ label, value, note, accent, tint }) => (
        <div
          className="statistics-kpi"
          key={label}
          style={{ ["--kpi-accent" as string]: accent, ["--kpi-tint" as string]: tint }}
        >
          <div className="statistics-kpi-top">
            <dt>{label}</dt>
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
