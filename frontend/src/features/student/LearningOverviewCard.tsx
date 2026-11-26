import { useMemo } from "react";
import { Link } from "react-router-dom";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import { useLanguage } from "@/shared/context/LanguageContext";
import { studentApi } from "@/features/student/studentApi";
import { useLoad } from "@/shared/hooks/useLoad";
import { Loading, Status } from "@/shared/ui/ui";
import { parseServerDateTime, shortMonthLabel } from "@/shared/lib/datetime/serverDateTime";
import type { Submission } from "@/data/models";

const RECENT_LIMIT = 5;

/** Các kết quả đã được chấm xong; bài nộp đang chờ hoặc chưa trả lời không tính vào tỷ lệ đạt. */
const DECIDED_RESULTS = new Set(["Accepted", "Wrong Answer", "Runtime Error", "Time Limit Exceeded", "Partial", "Rejected"]);

/**
 * Thẻ "Tổng quan học tập" của trang Tổng quan sinh viên: số bài đã giải theo độ khó, tổng bài nộp, tỷ lệ đạt và
 * vài bài nộp gần nhất. Dựng cùng khuôn với thẻ Hạn nộp bên cạnh (thanh tiêu đề, hàng có ô ngày, đường kẻ ngăn).
 * Danh sách lấy từ /api/submissions (cùng nguồn với trang Bài đã nộp) nên điểm bị ẩn của bài thi vẫn được ẩn như ở đó.
 */
export function LearningOverviewCard({ solved, easy, medium, hard }: { solved: number; easy: number; medium: number; hard: number }) {
  const { t: tr } = useLanguage("student");
  const { t, locale } = useLanguage();
  const { data: submissions, loading, error } = useLoad<Submission[]>(() => studentApi.getSubmissions(), []);
  const { data: problems } = useLoad(() => studentApi.getProblems({ includePrivate: true }), []);

  const { total, rate, recent } = useMemo(() => {
    const rows = (submissions ?? []).filter((item) => !item.questionOnly);
    const decided = rows.filter((item) => DECIDED_RESULTS.has(item.result));
    const accepted = decided.filter((item) => item.result === "Accepted").length;
    const newestFirst = [...rows].sort((a, b) => parseServerDateTime(b.submittedAt).getTime() - parseServerDateTime(a.submittedAt).getTime());
    return {
      total: rows.length,
      rate: decided.length ? Math.round((accepted / decided.length) * 100) : null,
      recent: newestFirst.slice(0, RECENT_LIMIT),
    };
  }, [submissions]);

  const ready = !loading && !error;

  return (
    <section className="activity-section dashboard-activity-card dashboard-overview-card">
      <div className="dashboard-overview-heading">
        <h2><TranslatedText>{tr("Learning overview")}</TranslatedText></h2>
        <Link to="/submissions"><TranslatedText>{tr("View all")}</TranslatedText></Link>
      </div>
      <div className="dashboard-overview-stats">
        <div className="dashboard-overview-stat">
          <b>{solved}</b>
          <span><TranslatedText>{tr("problems solved")}</TranslatedText></span>
          <small>{t("Easy")} {easy} · {t("Medium")} {medium} · {t("Hard")} {hard}</small>
        </div>
        <div className="dashboard-overview-stat">
          <b>{ready ? total : "—"}</b>
          <span><TranslatedText>{tr("submissions")}</TranslatedText></span>
        </div>
        <div className="dashboard-overview-stat">
          <b>{ready && rate !== null ? `${rate}%` : "—"}</b>
          <span><TranslatedText>{tr("acceptance rate")}</TranslatedText></span>
        </div>
      </div>
      <p className="dashboard-overview-label"><b><TranslatedText>{tr("Recent submissions")}</TranslatedText></b></p>
      <div className="learning-scroll dashboard-recent-scroll" tabIndex={0} aria-label={tr("Recent submissions")}>
        {loading ? <Loading variant="inline" rows={3} label={tr("Loading recent submissions…")} /> : error ? (
          <p className="tiny muted" role="alert"><TranslatedText>{tr("Could not load recent submissions.")}</TranslatedText></p>
        ) : !recent.length ? (
          <p className="tiny muted"><TranslatedText>{tr("No submissions yet.")}</TranslatedText></p>
        ) : recent.map((item) => {
          const problem = problems?.find((entry: { id: string }) => entry.id === item.problemId);
          const title = problem ? `${problem.number}. ${problem.title}` : item.contextTitle || item.context;
          const where = item.contextTitle ? `${tr(item.source)} · ${item.contextTitle}` : tr(item.source);
          const date = parseServerDateTime(item.submittedAt);
          const valid = Number.isFinite(date.getTime());
          return (
            <Link className="dashboard-deadline-row" key={item.id} to={`/submissions?submission=${encodeURIComponent(item.id)}`}>
              <time>
                <b>{shortMonthLabel(date, locale)}</b>
                <strong>{valid ? String(date.getDate()).padStart(2, "0") : "—"}</strong>
              </time>
              <span>
                <b>{title}</b>
                <small>{where}{valid ? ` · ${date.toLocaleTimeString(locale, { hour: "2-digit", minute: "2-digit" })}` : ""}</small>
              </span>
              <Status value={item.appealStatus || item.result} />
            </Link>
          );
        })}
      </div>
    </section>
  );
}
