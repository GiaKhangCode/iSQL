import {useState} from "react";
import {Link} from "react-router-dom";
import {useLanguage} from "@/shared/context/LanguageContext";
import {TranslatedText} from "@/shared/ui/TranslatedText";
import {Table} from "@/shared/ui/Table";
import {ErrorState, Loading} from "@/shared/ui/ui";
import {useLoad} from "@/shared/hooks/useLoad";
import {regradeAssignments} from "@/shared/api/regradeAssignments";
import {parseServerDateTime} from "@/shared/lib/datetime/serverDateTime";
import {formatPoints} from "@/shared/lib/sql/formatPoints";
import "@/styles/regrade-assignments.css";

/** Lecturer: the exam submissions the faculty office assigned for regrading. */
export function MyRegradesPage() {
  const {t, locale} = useLanguage("teacher");
  const {data: rows, loading, error, mutate} = useLoad(() => regradeAssignments.mine(), []);
  const [filter, setFilter] = useState<"all" | "pending" | "completed">("all");
  const date = (value: string | null) => (value ? parseServerDateTime(value).toLocaleString(locale) : "—");
  if (loading) return <Loading variant="table" columns={5} rows={5} label={t("Loading submissions…")} />;
  if (error || !rows) return <ErrorState title={t("Could not load data")} message={error} onRetry={() => void mutate()} />;

  const visible = rows.filter(row =>
    filter === "pending" ? !row.completedAt : filter === "completed" ? Boolean(row.completedAt) : true,
  );
  const pendingCount = rows.filter(row => !row.completedAt).length;
  const completedCount = rows.length - pendingCount;

  return <section className="teacher-page regrade-page">
    <h1 className="sr-only"><TranslatedText>{t("Exam submissions assigned for regrading")}</TranslatedText></h1>
    <div className="regrade-filter-tabs" role="tablist" aria-label={t("Status")}>
      <button type="button" className={`button${filter === "all" ? " primary" : ""}`} onClick={() => setFilter("all")}>
        <TranslatedText>{t("All")}</TranslatedText> ({rows.length})
      </button>
      <button type="button" className={`button${filter === "pending" ? " primary" : ""}`} onClick={() => setFilter("pending")}>
        <TranslatedText>{t("Pending grading")}</TranslatedText> ({pendingCount})
      </button>
      <button type="button" className={`button${filter === "completed" ? " primary" : ""}`} onClick={() => setFilter("completed")}>
        <TranslatedText>{t("Completed")}</TranslatedText> ({completedCount})
      </button>
    </div>
    <div className="regrade-table-wrap">
      <Table className="regrade-table">
        <thead><tr><th><TranslatedText>{t("Student ID")}</TranslatedText></th><th><TranslatedText>{t("Student")}</TranslatedText></th><th><TranslatedText>{t("Exam submission")}</TranslatedText></th>
          <th><TranslatedText>{t("Score")}</TranslatedText></th><th><TranslatedText>{t("Status")}</TranslatedText></th></tr></thead>
        <tbody>
          {visible.map(row => <tr key={row.submissionId}>
            <td data-label={t("Student ID")}>{row.studentCode ?? "—"}</td>
            <td data-label={t("Student")}>{row.studentName}</td>
            <td data-label={t("Exam submission")}>
              <strong>{row.problemTitle}</strong>
              <small className="muted">{row.sessionTitle} · {date(row.assignedAt)}</small>
              {row.appealReason && <small className="muted regrade-reason-text">{t("Appeal reason")}: {row.appealReason}</small>}
              {(row.assignmentNote || row.note) && <small className="muted">{t("Note")}: {row.assignmentNote || row.note}</small>}
            </td>
            <td data-label={t("Score")}>{row.score != null ? `${formatPoints(row.score)}${row.maxScore != null ? ` / ${formatPoints(row.maxScore)}` : ""}` : "—"}</td>
            <td data-label={t("Status")}>
              <Link className={`button${row.completedAt ? "" : " primary"}`} state={{returnTo: "/teacher/regrade"}} to={`/teacher/results/review/${encodeURIComponent(row.submissionId)}`}>
                <TranslatedText>{row.completedAt ? t("Review again") : t("Grade")}</TranslatedText></Link>
            </td>
          </tr>)}
          {!visible.length && <tr><td colSpan={5} className="teacher-empty-row"><TranslatedText>{t("You have not been assigned any submissions to regrade.")}</TranslatedText></td></tr>}
        </tbody>
      </Table>
    </div>
  </section>;
}
