import { formatPoints } from "@/shared/lib/sql/formatPoints";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import { useLanguage } from "@/shared/context/LanguageContext";
import { useState } from "react";
import { Link } from "react-router-dom";
import { type Submission } from "@/data/models";
import { Dialog, Status } from "@/shared/ui/ui";
import { submissionTimeLabel } from "@/shared/lib/datetime/serverDateTime";
import { SubmissionQuestions } from "@/shared/components/SubmissionQuestions";
/** Regrade requests are filed on the university portal, not in this app. */
const REGRADE_PORTAL_URL = "https://portal.uit.edu.vn/sinh-vien/phuc-khao";

export function SubmissionDetails({
  submission,
  problem,
  onClose,
  inline = false,
}: {
  submission: Submission;
  problem: any;
  onClose: () => void;
  inline?: boolean;
}) {
  const { t: tr, locale: uiLocale } = useLanguage("common");
  const [copy, setCopy] = useState("");
  const p = problem;
  // Read the immutable attempt snapshot, never the current draft.
  const query = submission.query;
  const maximumScore = submission.maxScore ?? 100;
  // Điểm cuối cùng là điểm giảng viên đã chấm (evaluated_score); chưa ai chấm thì không lấy điểm tự động thay.
  const finalScore = submission.evaluatedScore ?? null;
  const scoreLabel = (score: number | null, ungradedLabel?: string) => {
    if (submission.scoreVisible === false) return tr("Score hidden by instructor");
    if (score == null) return ungradedLabel ?? tr("Score hidden by instructor");
    if (submission.result === "Pending review" || submission.result === "Not answered") return tr("Awaiting grading");
    return `${formatPoints(score)} / ${formatPoints(maximumScore)}`;
  };
  const content = (
    <>
      <div className="submission-detail-content">
        <p className="tiny muted submission-detail-id">
          #{submission.id} <TranslatedText>{tr("· Read-only")}</TranslatedText>
        </p>
        <h1>
          {p.number}. {p.title}
        </h1>
        <div className="submission-attempt-meta">
          <p className="tiny muted"><TranslatedText>{tr(submissionTimeLabel(submission.submittedAt, uiLocale))}</TranslatedText></p>
          <div className="submission-source-verdict">
            <p className="tiny">
              <TranslatedText>{tr(submission.source)}</TranslatedText>
              {(submission.contextTitle || submission.context) && (submission.contextTitle || submission.context) !== submission.source && <> · {submission.contextTitle || submission.context}</>}
            </p>
            <Status value={submission.appealStatus || submission.result} />
          </div>
        </div>
        <section
          className="submission-evaluation"
          aria-label={tr("Submission evaluation")}
        >
          <div className="submission-score-grid">
            <div>
              <small><TranslatedText>{tr("Auto score")}</TranslatedText></small>
              <strong>{scoreLabel(submission.score)}</strong>
            </div>
            <div>
              <small><TranslatedText>{tr("Final score")}</TranslatedText></small>
              <strong>{scoreLabel(finalScore, "_")}</strong>
            </div>
          </div>
        </section>
        <SubmissionQuestions key={submission.id} submission={submission}/>
        <details className="submitted-query submission-full-script">
          <summary><TranslatedText>{tr("Full SQL for this submission")}</TranslatedText></summary>
          <div className="section-heading">
            <h3><TranslatedText>{tr("Submitted SQL")}</TranslatedText></h3>
            {query && (
              <div className="submitted-query-actions">
              <button
                className="text-button"
                onClick={async () => {
                  try {
                    await navigator.clipboard.writeText(query);
                    setCopy("Copied SQL.");
                  } catch {
                    setCopy(
                      "Copy unavailable. Select the SQL below to copy manually.",
                    );
                  }
                }}
              >
                <TranslatedText>{tr("Copy")}</TranslatedText>
              </button>
              </div>
            )}
          </div>
          {query ? (
            <pre tabIndex={0} aria-label={tr("Submitted SQL snapshot")}>
              <code>{query}</code>
            </pre>
          ) : (
            <p className="tiny muted">
              <TranslatedText>{tr("SQL snapshot is unavailable for this submission.")}</TranslatedText>
            </p>
          )}
          <small className="muted">
            <TranslatedText>{tr("Submission snapshot ·")}</TranslatedText> <TranslatedText>{submission.database || tr("Database unavailable")}</TranslatedText> <TranslatedText>{tr("· Read-only")}</TranslatedText>
          </small>
          <p role="status" className="tiny">
            <TranslatedText>{tr(copy)}</TranslatedText>
          </p>
        </details>
        {/* Bài thi chấm theo câu: nhận xét nằm ở từng câu phía trên, không có nhận xét chung. */}
        {(submission.feedback || !submission.sections?.some(section => section.questions?.some(question => question.manualScore != null))) && <section className="instructor-evaluation">
          <h3><TranslatedText>{tr("Instructor comments")}</TranslatedText></h3>
          <p className="tiny muted">
            <TranslatedText>{submission.feedback || tr("No instructor comments yet.")}</TranslatedText>
          </p>
        </section>}
        {(submission.appealStatus === "Awaiting regrade" || submission.appealStatus === "Regraded") && (
          <section className="instructor-evaluation">
            <h3><TranslatedText>{tr("Regrade")}</TranslatedText></h3>
            {submission.appealCreatedAt && (
              <p className="tiny muted">
                <TranslatedText>{tr(submissionTimeLabel(submission.appealCreatedAt, uiLocale))}</TranslatedText>
                {submission.appealResolvedAt && <> · <TranslatedText>{tr("Regraded")}</TranslatedText></>}
              </p>
            )}
          </section>
        )}
      </div>
      <footer className="submission-detail-footer">
        {submission.canAppeal && submission.appealStatus !== "Awaiting regrade" && submission.appealStatus !== "Regraded" && <a className="button" href={REGRADE_PORTAL_URL} target="_blank" rel="noopener noreferrer"><TranslatedText>{tr("Regrade")}</TranslatedText></a>}
        <Link className="button primary" to={"/workspace/" + p.id + "?source=" + encodeURIComponent(submission.source) + "&context=" + encodeURIComponent(submission.context)}>
          <TranslatedText>{tr("Open problem")}</TranslatedText>
        </Link>
      </footer>
    </>
  );
  if (inline) {
    return <div className="submission-inline-details">
      <header className="submission-inline-heading"><h2><TranslatedText>{tr("Submission details")}</TranslatedText></h2></header>
      {content}
    </div>;
  }
  return <Dialog className="submission-drawer" title={tr("Submission details")} onClose={onClose}>{content}</Dialog>;
}
