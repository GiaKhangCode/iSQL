import {formatPoints} from "@/shared/lib/sql/formatPoints";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import { useLanguage } from "@/shared/context/LanguageContext";
import { useEffect, useState, type ComponentType, type ReactNode } from "react";
import { Link } from "react-router-dom";
import { ProblemMarkdown } from "@/shared/components/ProblemMarkdown";
import { Empty, Loading, Status } from "@/shared/ui/ui";
import { useLoad } from "@/shared/hooks/useLoad";
import type { ExamSession, Problem, Submission } from "@/data/models";
import { parseServerDateTime } from "@/shared/lib/datetime/serverDateTime";
import { ExamSessionSwipeTabs } from "@/shared/components/ExamSessionSwipeTabs";
import { LayeredExamSessionBanner } from "@/shared/components/LayeredExamSessionBanner";
import { ShieldAlert, RefreshCw, CheckCircle2 } from "lucide-react";
import { examSessionOverview } from "@/shared/lib/exam-session/examSessionOverview";
import "@/shared/components/ExamSessionDetailView.css";

export type ExamSessionPhase = "Upcoming" | "Live" | "Closed";

function timeRemaining(milliseconds: number) {
  const seconds = Math.max(0, Math.floor(milliseconds / 1000));
  const days = Math.floor(seconds / 86400);
  const hours = Math.floor((seconds % 86400) / 3600);
  const minutes = Math.floor((seconds % 3600) / 60);
  return `${days ? `${days}d ` : ""}${hours}h ${String(minutes).padStart(2, "0")}m ${String(seconds % 60).padStart(2, "0")}s`;
}

export function ExamSessionDetailView({ examSession, phase, now, preview = false, isExamination: propIsExamination, bannerActions, onUnlocked = () => window.location.reload(), getSubmissions, getProblem, AdmissionForm }: {
  examSession: ExamSession;
  phase: ExamSessionPhase;
  now: number;
  preview?: boolean;
  isExamination?: boolean;
  bannerActions?: ReactNode;
  onUnlocked?: () => void;
  getSubmissions?: (filters: { source?: string }) => Promise<Submission[]>;
  getProblem?: (id: string, context?: string) => Promise<Problem>;
  AdmissionForm?: ComponentType<{ examSession: ExamSession; closed: boolean; onUnlocked: () => void }>;
}) {
  const { t: tr, locale: uiLocale } = useLanguage("student");
  const isExamination = propIsExamination ?? Boolean(examSession.examinationId);
  const isComprehensive = examSession.examType === "comprehensive";
  const examUrl = `/examinations/${encodeURIComponent(examSession.id)}/exam`;
  const [selectedProblemId, setSelectedProblemId] = useState<string | null>(null);
  const { data: attempts, loading: attemptsLoading, error: attemptsError } = useLoad<Submission[]>(
    () => preview || phase === "Upcoming" || (examSession.admissionRequired && !examSession.admissionUnlocked) || !getSubmissions ? Promise.resolve([]) : getSubmissions({ source: "ExamSession" }).then((items: Submission[]) => items.filter((item) => item.context === examSession.id)),
    [examSession.id, preview, phase === "Upcoming", examSession.admissionUnlocked],
  );
  useEffect(() => { setSelectedProblemId(null); }, [examSession.id]);
  const starts = parseServerDateTime(examSession.opensAt).getTime();
  const selectedId = phase === "Upcoming" ? null : selectedProblemId && examSession.problemIds.includes(selectedProblemId) ? selectedProblemId : examSession.problemIds[0] || null;
  const selectedProblem = examSession.problemDetails.find((item) => item.id === selectedId);
  const ends = parseServerDateTime(examSession.closesAt).getTime();
  const overview = examSessionOverview(examSession.description, examSession.title, examSession.shortDescription);
  const topLeaderboard = [...examSession.leaderboard].sort((a, b) => a.rank - b.rank).slice(0, 50);
  const finished = examSession.examMode && examSession.attemptStatus === "submitted";
  const workspace = (id: string) =>
    finished ? "/submissions" : isComprehensive ? examUrl : `/workspace/${encodeURIComponent(id)}?source=ExamSession&context=${encodeURIComponent(examSession.id)}` +
    `&contextTitle=${encodeURIComponent(examSession.title)}&examSession=${encodeURIComponent(examSession.id)}` +
    (phase === "Closed" ? "&closed=1" : "");

  function problemContent(id: string, active = true) {
    const summary = examSession.problemDetails.find((item) => item.id === id);
    if (preview) return <div className="exam-session-problem-slide exam-session-preview-problem">
      <div className="exam-session-selected-heading"><span><TranslatedText>{summary?.topic || tr("SQL problem")}</TranslatedText></span><span>{formatPoints(summary?.points ?? 100)} <TranslatedText>{tr("points")}</TranslatedText></span></div>
      {summary?.description && <ProblemMarkdown className="exam-session-markdown">{summary.description}</ProblemMarkdown>}
    </div>;
    return <ExamSessionSelectedProblem problemId={id} examSessionId={examSession.id} summary={summary} getProblem={getProblem}
      attempts={(attempts || []).filter((item) => item.problemId === id)} attemptsLoading={attemptsLoading}
      attemptsError={attemptsError} enabled={active} showScores={examSession.showScoresAfterSubmit !== false} />;
  }
  const selectedAttempts = (attempts || []).filter((item) => item.problemId === selectedId);
  const isViolationLocked = examSession.finishReason === "violations";
  const actionLabel = isViolationLocked
    ? "Bài thi đã bị khóa"
    : finished
    ? tr("Exam submitted")
    : phase === "Closed"
    ? tr("Review problem")
    : isComprehensive
    ? tr("Enter exam →")
    : problemAttemptStatus(selectedAttempts) === "In progress"
    ? tr("Continue solving")
    : tr("Open problem");

  if (!preview && examSession.admissionRequired && !examSession.admissionUnlocked) return AdmissionForm ? <AdmissionForm examSession={examSession} closed={phase==="Closed"} onUnlocked={onUnlocked}/> : null;
  return <div className="exam-session-detail-shell exam-session-detail-workflow">
    <LayeredExamSessionBanner id={examSession.id} bannerUrl={examSession.bannerUrl}
      className={`exam-session-detail-banner exam-session-phase-${phase.toLowerCase()}`} label={examSession.title}>
      <div className="u049-top">
        <span className="u049-tag"><TranslatedText>{tr(phase).toLocaleUpperCase(uiLocale)}</TranslatedText> · <TranslatedText>{isExamination ? tr("EXAMINATION") : tr("EXAM SESSION")}</TranslatedText></span>
        <div className="exam-session-banner-summary exam-session-header-timing">
          {phase === "Upcoming" && <p className="tiny"><TranslatedText>{tr("Starts in")}</TranslatedText> <TranslatedText>{Number.isFinite(starts) ? timeRemaining(starts - now) : tr("Not scheduled")}</TranslatedText></p>}
          {phase === "Live" && <p className="tiny"><TranslatedText>{tr("Ends in")}</TranslatedText> <TranslatedText>{Number.isFinite(ends) ? timeRemaining(ends - now) : tr("Not scheduled")}</TranslatedText></p>}
          {phase === "Closed" && <p className="tiny"><TranslatedText>{tr("Final score ·")}</TranslatedText> <TranslatedText>{examSession.score == null ? tr("Score hidden by instructor") : `${formatPoints(examSession.score)}/${formatPoints(examSession.totalPoints)}`}</TranslatedText>{!isExamination && examSession.rank ? <TranslatedText>{tr("· Rank {value0}", { value0: examSession.rank })}</TranslatedText> : null}</p>}
        </div>
      </div>
      {preview && bannerActions}
      <div className="u049-body">
        <div className="u049-k">{examSession.scope}</div>
        <h1 className="u049-t">{examSession.title}</h1>
        <p className="tiny"><TranslatedText>{tr("Starts")}</TranslatedText> {examSession.date} {examSession.time} <TranslatedText>{tr("· Ends")}</TranslatedText> {examSession.endDate || examSession.date} {examSession.endTime} · {examSession.problemCount} <TranslatedText>{tr("problem")}</TranslatedText><TranslatedText>{examSession.problemCount === 1 ? "" : tr("s")}</TranslatedText></p>

        {examSession.shortDescription && <div className="u049-reveal"><div className="u049-reveal-inner"><p className="u049-sum">{examSession.shortDescription}</p></div></div>}
      </div>
    </LayeredExamSessionBanner>
    <div className="exam-session-detail-content-grid">
    <section className="exam-session-problem-workbench" aria-label={tr("Exam session problems")}>
      {isViolationLocked && (
        <div className="exam-violation-locked-banner" style={{
          background: "rgba(239, 68, 68, 0.08)",
          border: "1px solid rgba(239, 68, 68, 0.3)",
          borderRadius: "10px",
          padding: "16px 20px",
          marginBottom: "20px",
          display: "flex",
          gap: "16px",
          alignItems: "flex-start"
        }}>
          <div style={{ color: "#dc2626", marginTop: "2px" }}>
            <ShieldAlert size={26} />
          </div>
          <div style={{ flex: 1 }}>
            <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: 6 }}>
              <h3 style={{ margin: 0, fontSize: "15px", color: "#b91c1c", fontWeight: 700 }}>
                Bài thi của bạn đang bị khóa do vi phạm quy chế thi
              </h3>
              <span style={{ fontSize: "11px", fontWeight: 700, padding: "2px 8px", borderRadius: "4px", background: "#ef4444", color: "#fff" }}>
                Khóa bài thi
              </span>
            </div>
            <p style={{ margin: "0 0 10px", fontSize: "13px", color: "#4b5563", lineHeight: 1.5 }}>
              Hệ thống ghi nhận bạn đã vượt quá số lần cảnh báo chuyển cửa sổ hoặc rời trang thi ({examSession.examViolationLimit || 2}/{examSession.examViolationLimit || 2} lần).
              Vui lòng giữ nguyên máy tính và báo ngay cho <strong>Cán bộ coi thi (CBCT)</strong> tại phòng thi để được kiểm tra và hỗ trợ mở khóa.
            </p>
            <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", flexWrap: "wrap", gap: "10px", borderTop: "1px solid rgba(239, 68, 68, 0.15)", paddingTop: "10px" }}>
              <div style={{ display: "flex", alignItems: "center", gap: "8px", fontSize: "12px", color: "#6b7280" }}>
                <span className="exam-pulse-dot" style={{ display: "inline-block", width: "8px", height: "8px", borderRadius: "50%", background: "#ef4444" }} />
                <span>Hệ thống tự động kiểm tra lệnh mở khóa từ Giám thị (mỗi 3s)…</span>
              </div>
              <button
                type="button"
                className="button compact secondary"
                onClick={onUnlocked}
                style={{ fontSize: "12px", display: "inline-flex", alignItems: "center", gap: "4px" }}
              >
                <RefreshCw size={12} /> Kiểm tra mở khóa ngay
              </button>
            </div>
          </div>
        </div>
      )}
      <header className="exam-session-problem-stage-header">
        {examSession.admissionRequired && phase === "Upcoming" && <button className="button primary" disabled><TranslatedText>{tr("Enter when the examination starts")}</TranslatedText></button>}
        <h2><TranslatedText>{examSession.problemIds.length === 1 && selectedProblem ? selectedProblem.title : tr("Problems")}</TranslatedText></h2>
        {phase !== "Upcoming" && (selectedId || isComprehensive) && (preview
          ? isComprehensive && <button className="button primary" type="button" disabled><TranslatedText>{actionLabel}</TranslatedText></button>
          : finished ? (
            <button className="button primary" type="button" disabled style={{ opacity: 0.65, cursor: "not-allowed" }}>
              <TranslatedText>{isViolationLocked ? "Bài thi đã bị khóa" : tr("Exam submitted")}</TranslatedText>
            </button>
          ) : (
            <Link className="button primary" to={selectedId ? workspace(selectedId) : examUrl}>
              <TranslatedText>{actionLabel}</TranslatedText>{!isComprehensive && " →"}
            </Link>
          ))}
      </header>
      {phase === "Upcoming" ? <p className="exam-session-problem-notice muted"><TranslatedText>{tr("Problems become available when the exam session starts.")}</TranslatedText></p>
        : selectedId ? examSession.problemIds.length === 1
          ? problemContent(selectedId)
          : <ExamSessionSwipeTabs key={examSession.id} className="exam-session-problem-slider"
              label={tr("Exam session problems")} value={selectedId} onChange={setSelectedProblemId}
              tabs={examSession.problemIds.map((id, index) => ({ id, label: <span className="exam-session-problem-tab-label">
                <span>{String.fromCharCode(65 + index)}. <TranslatedText>{examSession.problemDetails.find((item) => item.id === id)?.title || tr("Problem {value0}", { value0: index + 1 })}</TranslatedText></span>
                {!preview && <Status value={attemptsLoading ? "Loading…" : attemptsError ? "Unavailable" : problemAttemptStatus((attempts || []).filter((item) => item.problemId === id))} />}
              </span> }))}
            >{(id, active) => problemContent(id, active)}</ExamSessionSwipeTabs>
        : <Empty title={tr("No problems selected yet.")} />}
    </section>
    <aside className="exam-session-detail-sidebar">
      <section className="exam-session-context-section" aria-label={tr("Description")}>
        <header><h2><TranslatedText>{tr("Description")}</TranslatedText></h2></header>
        <ProblemMarkdown className="exam-session-markdown">{overview || tr("No description yet.")}</ProblemMarkdown>
      </section>
      {isExamination ? (
        <section className="exam-session-participation examination-session-info" aria-label={tr("Examination information")}>
          <h2><TranslatedText>{tr("Examination information")}</TranslatedText></h2>
          <dl>
            <div>
              <dt><TranslatedText>Trạng thái</TranslatedText></dt>
              <dd>
                {isViolationLocked ? (
                  <span className="status danger" style={{ background: "rgba(239, 68, 68, 0.15)", color: "#b91c1c", fontWeight: 700, padding: "2px 8px", borderRadius: "6px" }}>
                    Bị khóa (Vi phạm)
                  </span>
                ) : finished ? (
                  <span className="status neutral">Đã nộp bài</span>
                ) : phase === "Live" ? (
                  <span className="status success" style={{ background: "rgba(34, 197, 94, 0.15)", color: "#15803d", fontWeight: 700, padding: "2px 8px", borderRadius: "6px" }}>
                    Đang thi
                  </span>
                ) : (
                  <span className="status warning">Sắp diễn ra</span>
                )}
              </dd>
            </div>
            <div>
              <dt><TranslatedText>Số câu hỏi</TranslatedText></dt>
              <dd>{examSession.problemCount} bài ({examSession.totalPoints || 10} điểm)</dd>
            </div>
            {examSession.room && (
              <div>
                <dt><TranslatedText>{tr("Exam room")}</TranslatedText></dt>
                <dd><strong>{examSession.room}</strong></dd>
              </div>
            )}
            <div>
              <dt><TranslatedText>{tr("Time")}</TranslatedText></dt>
              <dd>{examSession.date} {examSession.time} – {examSession.endTime || examSession.time}</dd>
            </div>
            {examSession.proctors && examSession.proctors.length > 0 && (
              <div>
                <dt><TranslatedText>{tr("Proctor")}</TranslatedText></dt>
                <dd>{examSession.proctors.join(", ")}</dd>
              </div>
            )}
            <div>
              <dt><TranslatedText>{tr("Mode")}</TranslatedText></dt>
              <dd><TranslatedText>{tr("Strict exam mode")}</TranslatedText> ({tr("Max violations: {count}", { count: examSession.examViolationLimit || 2 })})</dd>
            </div>
            {(phase === "Live" || phase === "Closed") && (
              <div>
                <dt><TranslatedText>{tr("Your score")}</TranslatedText></dt>
                <dd><TranslatedText>{examSession.score == null ? tr("Score hidden by instructor") : `${formatPoints(examSession.score)}/${formatPoints(examSession.totalPoints)}`}</TranslatedText></dd>
              </div>
            )}
          </dl>
        </section>
      ) : (
        <section className="exam-session-participation"><h2><TranslatedText>{tr("Participation")}</TranslatedText></h2><dl>
          <div><dt><TranslatedText>{tr("Participants")}</TranslatedText></dt><dd>{examSession.submitters ?? 0}</dd></div>
          {phase === "Live" && <div><dt><TranslatedText>{tr("Your score")}</TranslatedText></dt><dd><TranslatedText>{examSession.score == null ? tr("Score hidden by instructor") : `${formatPoints(examSession.score)}/${formatPoints(examSession.totalPoints)}`}</TranslatedText></dd></div>}
          {examSession.leaderboardEnabled && phase === "Live" && examSession.rank && <div><dt><TranslatedText>{tr("Your rank")}</TranslatedText></dt><dd>#{examSession.rank}</dd></div>}
        </dl>
          {examSession.leaderboardEnabled && phase !== "Upcoming" && <div className="exam-session-leaderboard"><h3><TranslatedText>{tr("Leaderboard")}</TranslatedText></h3>
            {topLeaderboard.length ? <ol className="exam-session-leaderboard-scroll" tabIndex={0} aria-label={tr("Top 50 exam session participants")}>{topLeaderboard.map((entry, index) => <li key={`${entry.rank}-${entry.student}-${index}`}><span>#{entry.rank} {entry.student}</span><b>{formatPoints(entry.score)}</b></li>)}</ol> : <p className="tiny muted"><TranslatedText>{tr("No submissions yet.")}</TranslatedText></p>}
          </div>}
        </section>
      )}
    </aside>
    </div>
  </div>;
}

function problemAttemptStatus(attempts: Submission[]) {
  return attempts.some((item) => item.result === "Accepted") ? "Solved" : attempts.length ? "In progress" : "Not submitted";
}

function ExamSessionSelectedProblem({ problemId, examSessionId, summary, attempts, attemptsLoading, attemptsError, enabled, showScores, getProblem }: {
  problemId: string; examSessionId: string; summary?: ExamSession["problemDetails"][number]; attempts: Submission[];
  attemptsLoading: boolean; attemptsError: string; enabled: boolean; showScores: boolean;
  getProblem?: (id: string, context?: string) => Promise<Problem>;
}) {
  const { t: tr } = useLanguage("student");
  const { data: problem, loading, error } = useLoad<Problem | null>(() => enabled && getProblem ? getProblem(problemId, examSessionId) : Promise.resolve(null), [problemId, examSessionId, enabled]);
  const maximum = summary?.points ?? 100;
  const score = Math.round(Math.max(0, ...attempts.map((item) => {
    const max = item.maxScore ?? 100;
    return max > 0 ? ((item.evaluatedScore ?? item.score ?? 0) / max) * maximum : 0;
  })) * 100) / 100;
  const status = problem?.draft && !attempts.length ? "In progress" : problemAttemptStatus(attempts);
  return <div className="exam-session-problem-slide">
    <div className="exam-session-selected-heading">
      <Status value={attemptsLoading ? "Loading…" : attemptsError ? "Unavailable" : status} />
    </div>
    <div className="exam-session-selected-body">
    <dl className="exam-session-selected-facts">
      <div><dt><TranslatedText>{tr("Your best score")}</TranslatedText></dt><dd><TranslatedText>{attemptsLoading ? tr("Loading…") : attemptsError ? tr("Unavailable") : !showScores ? tr("Score hidden by instructor") : `${formatPoints(score)}/${formatPoints(maximum)}`}</TranslatedText></dd></div>
      <div><dt><TranslatedText>{tr("Submissions")}</TranslatedText></dt><dd><TranslatedText>{attemptsLoading ? "…" : attemptsError ? tr("Unavailable") : attempts.length}</TranslatedText></dd></div>
      <div><dt><TranslatedText>{tr("Topics")}</TranslatedText></dt><dd>{summary?.topic || problem?.topic || "—"}</dd></div>
    </dl>
    {enabled && (loading ? <Loading variant="inline" rows={5} label={tr("Loading problem…")} /> : error ? <p className="tiny muted"><TranslatedText>{tr("Problem details could not be loaded. Use Open problem to try again.")}</TranslatedText></p> : problem && <>
      <section className="exam-session-problem-statement"><h4><TranslatedText>{tr("Problem")}</TranslatedText></h4><ProblemMarkdown className="exam-session-markdown">{problem.description || tr("No description available.")}</ProblemMarkdown></section>
    </>)}
    </div>
  </div>;
}
