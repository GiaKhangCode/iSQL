import {formatPoints} from "@/shared/lib/sql/formatPoints";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import { useLanguage } from "@/shared/context/LanguageContext";
import { useEffect, useState, type ComponentType, type ReactNode } from "react";
import { Link } from "react-router-dom";
import { ProblemMarkdown } from "@/shared/components/ProblemMarkdown";
import { Empty, Loading, Status } from "@/shared/ui/ui";
import { useLoad } from "@/shared/hooks/useLoad";
import type { Contest, Problem, Submission } from "@/data/models";
import { parseServerDateTime } from "@/shared/lib/datetime/serverDateTime";
import { ContestSwipeTabs } from "@/shared/components/ContestSwipeTabs";
import { LayeredContestBanner } from "@/shared/components/LayeredContestBanner";
import { ShieldAlert, RefreshCw, CheckCircle2 } from "lucide-react";
import { contestOverview } from "@/shared/lib/contest/contestOverview";
import "@/shared/components/ContestDetailView.css";

export type ContestPhase = "Upcoming" | "Live" | "Closed";

function timeRemaining(milliseconds: number) {
  const seconds = Math.max(0, Math.floor(milliseconds / 1000));
  const days = Math.floor(seconds / 86400);
  const hours = Math.floor((seconds % 86400) / 3600);
  const minutes = Math.floor((seconds % 3600) / 60);
  return `${days ? `${days}d ` : ""}${hours}h ${String(minutes).padStart(2, "0")}m ${String(seconds % 60).padStart(2, "0")}s`;
}

export function ContestDetailView({ contest, phase, now, preview = false, isExamination: propIsExamination, bannerActions, onUnlocked = () => window.location.reload(), getSubmissions, getProblem, AdmissionForm }: {
  contest: Contest;
  phase: ContestPhase;
  now: number;
  preview?: boolean;
  isExamination?: boolean;
  bannerActions?: ReactNode;
  onUnlocked?: () => void;
  getSubmissions?: (filters: { source?: string }) => Promise<Submission[]>;
  getProblem?: (id: string, context?: string) => Promise<Problem>;
  AdmissionForm?: ComponentType<{ contest: Contest; closed: boolean; onUnlocked: () => void }>;
}) {
  const { t: tr, locale: uiLocale } = useLanguage("student");
  const isExamination = propIsExamination ?? Boolean(contest.examinationId);
  const isComprehensive = contest.examType === "comprehensive";
  const examUrl = isExamination
    ? `/examinations/${encodeURIComponent(contest.id)}/exam`
    : `/contests/${encodeURIComponent(contest.id)}/exam`;
  const [selectedProblemId, setSelectedProblemId] = useState<string | null>(null);
  const { data: attempts, loading: attemptsLoading, error: attemptsError } = useLoad<Submission[]>(
    () => preview || phase === "Upcoming" || (contest.admissionRequired && !contest.admissionUnlocked) || !getSubmissions ? Promise.resolve([]) : getSubmissions({ source: "Contests" }).then((items: Submission[]) => items.filter((item) => item.context === contest.id)),
    [contest.id, preview, phase === "Upcoming", contest.admissionUnlocked],
  );
  useEffect(() => { setSelectedProblemId(null); }, [contest.id]);
  const starts = parseServerDateTime(contest.opensAt).getTime();
  const selectedId = phase === "Upcoming" ? null : selectedProblemId && contest.problemIds.includes(selectedProblemId) ? selectedProblemId : contest.problemIds[0] || null;
  const selectedProblem = contest.problemDetails.find((item) => item.id === selectedId);
  const ends = parseServerDateTime(contest.closesAt).getTime();
  const overview = contestOverview(contest.description, contest.title, contest.shortDescription);
  const topLeaderboard = [...contest.leaderboard].sort((a, b) => a.rank - b.rank).slice(0, 50);
  const finished = contest.examMode && contest.attemptStatus === "submitted";
  const workspace = (id: string) =>
    finished ? "/submissions" : isComprehensive ? examUrl : `/workspace/${encodeURIComponent(id)}?source=Contests&context=${encodeURIComponent(contest.id)}` +
    `&contextTitle=${encodeURIComponent(contest.title)}&contest=${encodeURIComponent(contest.id)}` +
    (phase === "Closed" ? "&closed=1" : "");

  function problemContent(id: string, active = true) {
    const summary = contest.problemDetails.find((item) => item.id === id);
    if (preview) return <div className="contest-problem-slide contest-preview-problem">
      <div className="contest-selected-heading"><span><TranslatedText>{summary?.topic || tr("SQL problem")}</TranslatedText></span><span>{formatPoints(summary?.points ?? 100)} <TranslatedText>{tr("points")}</TranslatedText></span></div>
      {summary?.description && <ProblemMarkdown className="contest-markdown">{summary.description}</ProblemMarkdown>}
    </div>;
    return <ContestSelectedProblem problemId={id} contestId={contest.id} summary={summary} getProblem={getProblem}
      attempts={(attempts || []).filter((item) => item.problemId === id)} attemptsLoading={attemptsLoading}
      attemptsError={attemptsError} enabled={active} showScores={contest.showScoresAfterSubmit !== false} />;
  }
  const selectedAttempts = (attempts || []).filter((item) => item.problemId === selectedId);
  const isViolationLocked = contest.finishReason === "violations";
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

  if (!preview && contest.admissionRequired && !contest.admissionUnlocked) return AdmissionForm ? <AdmissionForm contest={contest} closed={phase==="Closed"} onUnlocked={onUnlocked}/> : null;
  return <div className="contest-detail-shell contest-detail-workflow">
    <LayeredContestBanner id={contest.id} bannerUrl={contest.bannerUrl}
      className={`contest-detail-banner contest-phase-${phase.toLowerCase()}`} label={contest.title}>
      <div className="u049-top">
        <span className="u049-tag"><TranslatedText>{tr(phase).toLocaleUpperCase(uiLocale)}</TranslatedText> · <TranslatedText>{isExamination ? tr("EXAMINATION") : tr("CONTEST")}</TranslatedText></span>
        <div className="contest-banner-summary contest-header-timing">
          {phase === "Upcoming" && <p className="tiny"><TranslatedText>{tr("Starts in")}</TranslatedText> <TranslatedText>{Number.isFinite(starts) ? timeRemaining(starts - now) : tr("Not scheduled")}</TranslatedText></p>}
          {phase === "Live" && <p className="tiny"><TranslatedText>{tr("Ends in")}</TranslatedText> <TranslatedText>{Number.isFinite(ends) ? timeRemaining(ends - now) : tr("Not scheduled")}</TranslatedText></p>}
          {phase === "Closed" && <p className="tiny"><TranslatedText>{tr("Final score ·")}</TranslatedText> <TranslatedText>{contest.score == null ? tr("Score hidden by instructor") : `${formatPoints(contest.score)}/${formatPoints(contest.totalPoints)}`}</TranslatedText>{!isExamination && contest.rank ? <TranslatedText>{tr("· Rank {value0}", { value0: contest.rank })}</TranslatedText> : null}</p>}
        </div>
      </div>
      {preview && bannerActions}
      <div className="u049-body">
        <div className="u049-k">{contest.scope}</div>
        <h1 className="u049-t">{contest.title}</h1>
        <p className="tiny"><TranslatedText>{tr("Starts")}</TranslatedText> {contest.date} {contest.time} <TranslatedText>{tr("· Ends")}</TranslatedText> {contest.endDate || contest.date} {contest.endTime} · {contest.problemCount} <TranslatedText>{tr("problem")}</TranslatedText><TranslatedText>{contest.problemCount === 1 ? "" : tr("s")}</TranslatedText></p>

        {contest.shortDescription && <div className="u049-reveal"><div className="u049-reveal-inner"><p className="u049-sum">{contest.shortDescription}</p></div></div>}
      </div>
    </LayeredContestBanner>
    <div className="contest-detail-content-grid">
    <section className="contest-problem-workbench" aria-label={tr("Contest problems")}>
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
              Hệ thống ghi nhận bạn đã vượt quá số lần cảnh báo chuyển cửa sổ hoặc rời trang thi ({contest.examViolationLimit || 2}/{contest.examViolationLimit || 2} lần).
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
      <header className="contest-problem-stage-header">
        {contest.admissionRequired && phase === "Upcoming" && <button className="button primary" disabled><TranslatedText>{tr("Enter when the examination starts")}</TranslatedText></button>}
        <h2><TranslatedText>{contest.problemIds.length === 1 && selectedProblem ? selectedProblem.title : tr("Problems")}</TranslatedText></h2>
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
      {phase === "Upcoming" ? <p className="contest-problem-notice muted"><TranslatedText>{tr("Problems become available when the contest starts.")}</TranslatedText></p>
        : selectedId ? contest.problemIds.length === 1
          ? problemContent(selectedId)
          : <ContestSwipeTabs key={contest.id} className="contest-problem-slider"
              label={tr("Contest problems")} value={selectedId} onChange={setSelectedProblemId}
              tabs={contest.problemIds.map((id, index) => ({ id, label: <span className="contest-problem-tab-label">
                <span>{String.fromCharCode(65 + index)}. <TranslatedText>{contest.problemDetails.find((item) => item.id === id)?.title || tr("Problem {value0}", { value0: index + 1 })}</TranslatedText></span>
                {!preview && <Status value={attemptsLoading ? "Loading…" : attemptsError ? "Unavailable" : problemAttemptStatus((attempts || []).filter((item) => item.problemId === id))} />}
              </span> }))}
            >{(id, active) => problemContent(id, active)}</ContestSwipeTabs>
        : <Empty title={tr("No problems selected yet.")} />}
    </section>
    <aside className="contest-detail-sidebar">
      <section className="contest-context-section" aria-label={tr("Description")}>
        <header><h2><TranslatedText>{tr("Description")}</TranslatedText></h2></header>
        <ProblemMarkdown className="contest-markdown">{overview || tr("No description yet.")}</ProblemMarkdown>
      </section>
      {isExamination ? (
        <section className="contest-participation examination-session-info" aria-label={tr("Examination information")}>
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
              <dd>{contest.problemCount} bài ({contest.totalPoints || 10} điểm)</dd>
            </div>
            {contest.room && (
              <div>
                <dt><TranslatedText>{tr("Exam room")}</TranslatedText></dt>
                <dd><strong>{contest.room}</strong></dd>
              </div>
            )}
            <div>
              <dt><TranslatedText>{tr("Time")}</TranslatedText></dt>
              <dd>{contest.date} {contest.time} – {contest.endTime || contest.time}</dd>
            </div>
            {contest.proctors && contest.proctors.length > 0 && (
              <div>
                <dt><TranslatedText>{tr("Proctor")}</TranslatedText></dt>
                <dd>{contest.proctors.join(", ")}</dd>
              </div>
            )}
            <div>
              <dt><TranslatedText>{tr("Mode")}</TranslatedText></dt>
              <dd><TranslatedText>{tr("Strict exam mode")}</TranslatedText> ({tr("Max violations: {count}", { count: contest.examViolationLimit || 2 })})</dd>
            </div>
            {(phase === "Live" || phase === "Closed") && (
              <div>
                <dt><TranslatedText>{tr("Your score")}</TranslatedText></dt>
                <dd><TranslatedText>{contest.score == null ? tr("Score hidden by instructor") : `${formatPoints(contest.score)}/${formatPoints(contest.totalPoints)}`}</TranslatedText></dd>
              </div>
            )}
          </dl>
        </section>
      ) : (
        <section className="contest-participation"><h2><TranslatedText>{tr("Participation")}</TranslatedText></h2><dl>
          <div><dt><TranslatedText>{tr("Participants")}</TranslatedText></dt><dd>{contest.submitters ?? 0}</dd></div>
          {phase === "Live" && <div><dt><TranslatedText>{tr("Your score")}</TranslatedText></dt><dd><TranslatedText>{contest.score == null ? tr("Score hidden by instructor") : `${formatPoints(contest.score)}/${formatPoints(contest.totalPoints)}`}</TranslatedText></dd></div>}
          {contest.leaderboardEnabled && phase === "Live" && contest.rank && <div><dt><TranslatedText>{tr("Your rank")}</TranslatedText></dt><dd>#{contest.rank}</dd></div>}
        </dl>
          {contest.leaderboardEnabled && phase !== "Upcoming" && <div className="contest-leaderboard"><h3><TranslatedText>{tr("Leaderboard")}</TranslatedText></h3>
            {topLeaderboard.length ? <ol className="contest-leaderboard-scroll" tabIndex={0} aria-label={tr("Top 50 contest participants")}>{topLeaderboard.map((entry, index) => <li key={`${entry.rank}-${entry.student}-${index}`}><span>#{entry.rank} {entry.student}</span><b>{formatPoints(entry.score)}</b></li>)}</ol> : <p className="tiny muted"><TranslatedText>{tr("No submissions yet.")}</TranslatedText></p>}
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

function ContestSelectedProblem({ problemId, contestId, summary, attempts, attemptsLoading, attemptsError, enabled, showScores, getProblem }: {
  problemId: string; contestId: string; summary?: Contest["problemDetails"][number]; attempts: Submission[];
  attemptsLoading: boolean; attemptsError: string; enabled: boolean; showScores: boolean;
  getProblem?: (id: string, context?: string) => Promise<Problem>;
}) {
  const { t: tr } = useLanguage("student");
  const { data: problem, loading, error } = useLoad<Problem | null>(() => enabled && getProblem ? getProblem(problemId, contestId) : Promise.resolve(null), [problemId, contestId, enabled]);
  const maximum = summary?.points ?? 100;
  const score = Math.round(Math.max(0, ...attempts.map((item) => {
    const max = item.maxScore ?? 100;
    return max > 0 ? ((item.evaluatedScore ?? item.score ?? 0) / max) * maximum : 0;
  })) * 100) / 100;
  const status = problem?.draft && !attempts.length ? "In progress" : problemAttemptStatus(attempts);
  return <div className="contest-problem-slide">
    <div className="contest-selected-heading">
      <Status value={attemptsLoading ? "Loading…" : attemptsError ? "Unavailable" : status} />
    </div>
    <div className="contest-selected-body">
    <dl className="contest-selected-facts">
      <div><dt><TranslatedText>{tr("Your best score")}</TranslatedText></dt><dd><TranslatedText>{attemptsLoading ? tr("Loading…") : attemptsError ? tr("Unavailable") : !showScores ? tr("Score hidden by instructor") : `${formatPoints(score)}/${formatPoints(maximum)}`}</TranslatedText></dd></div>
      <div><dt><TranslatedText>{tr("Submissions")}</TranslatedText></dt><dd><TranslatedText>{attemptsLoading ? "…" : attemptsError ? tr("Unavailable") : attempts.length}</TranslatedText></dd></div>
      <div><dt><TranslatedText>{tr("Topics")}</TranslatedText></dt><dd>{summary?.topic || problem?.topic || "—"}</dd></div>
    </dl>
    {enabled && (loading ? <Loading variant="inline" rows={5} label={tr("Loading problem…")} /> : error ? <p className="tiny muted"><TranslatedText>{tr("Problem details could not be loaded. Use Open problem to try again.")}</TranslatedText></p> : problem && <>
      <section className="contest-problem-statement"><h4><TranslatedText>{tr("Problem")}</TranslatedText></h4><ProblemMarkdown className="contest-markdown">{problem.description || tr("No description available.")}</ProblemMarkdown></section>
    </>)}
    </div>
  </div>;
}
