import {formatPoints} from "@/shared/lib/sql/formatPoints";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import { useLanguage } from "@/shared/context/LanguageContext";
import type { ComponentType, ReactNode } from "react";
import { Link } from "react-router-dom";
import { ProblemMarkdown } from "@/shared/components/ProblemMarkdown";
import type { ExamSession } from "@/data/models";
import { parseServerDateTime } from "@/shared/lib/datetime/serverDateTime";
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

export function ExamSessionDetailView({ examSession, phase, now, preview = false, isExamination: propIsExamination, bannerActions, onUnlocked = () => window.location.reload(), AdmissionForm }: {
  examSession: ExamSession;
  phase: ExamSessionPhase;
  now: number;
  preview?: boolean;
  isExamination?: boolean;
  bannerActions?: ReactNode;
  onUnlocked?: () => void;
  AdmissionForm?: ComponentType<{ examSession: ExamSession; closed: boolean; onUnlocked: () => void }>;
}) {
  const { t: tr, locale: uiLocale } = useLanguage("student");
  const isExamination = propIsExamination ?? Boolean(examSession.examinationId);
  const isComprehensive = examSession.examType === "comprehensive";
  const examUrl = `/examinations/${encodeURIComponent(examSession.id)}/exam`;
  const starts = parseServerDateTime(examSession.opensAt).getTime();
  const ends = parseServerDateTime(examSession.closesAt).getTime();
  const overview = examSessionOverview(examSession.description, examSession.title, examSession.shortDescription);
  const topLeaderboard = [...examSession.leaderboard].sort((a, b) => a.rank - b.rank).slice(0, 50);
  const finished = examSession.examMode && examSession.attemptStatus === "submitted";
  const workspace = (id: string) =>
    finished ? "/submissions" : isComprehensive ? examUrl : `/workspace/${encodeURIComponent(id)}?source=ExamSession&context=${encodeURIComponent(examSession.id)}` +
    `&contextTitle=${encodeURIComponent(examSession.title)}&examSession=${encodeURIComponent(examSession.id)}` +
    (phase === "Closed" ? "&closed=1" : "");

  const isViolationLocked = examSession.finishReason === "violations";
  // Đề bài không hiện ở trang này: sinh viên chỉ đọc đề trong màn làm bài, sau khi bấm bắt đầu.
  const started = Boolean(examSession.attemptStatus);
  const closedWithoutWork = phase === "Closed" && !started;
  const actionLabel = isViolationLocked
    ? "Bài thi đã bị khóa"
    : finished
    ? tr("Exam submitted")
    : closedWithoutWork
    ? tr("Exam session ended")
    : phase === "Closed"
    ? tr("Review problem")
    : phase === "Upcoming"
    ? tr("Enter when the examination starts")
    : examSession.attemptStatus === "active"
    ? tr("Continue the exam →")
    : tr("Enter the exam →");
  const actionDisabled = preview || finished || closedWithoutWork || phase === "Upcoming" || !(examSession.problemIds[0] || isComprehensive);
  const violationLimit = examSession.examViolationLimit || 2;
  const minutes = Number.isFinite(starts) && Number.isFinite(ends) && ends > starts ? Math.round((ends - starts) / 60000) : null;
  const duration = minutes == null ? tr("Not scheduled")
    : minutes >= 60 ? tr("{hours} h {minutes} min", { hours: Math.floor(minutes / 60), minutes: minutes % 60 })
    : tr("{minutes} min", { minutes });
  const isExam = isExamination || Boolean(examSession.examMode);
  const notes: string[] = [
    tr("The questions appear only inside the exam screen, after you start."),
    tr("The exam follows the session schedule: starting late does not add time. Your work is saved automatically and submitted when time runs out."),
    ...(examSession.examMode ? [
      tr("The exam opens in full screen. Leaving full screen asks you to submit your exam."),
      tr("Switching to another tab or window counts as a violation. At {count} violations your work is submitted automatically and locked; ask the proctor to unlock it.", { count: violationLimit }),
      tr("Pasting content from outside is blocked and counts as a violation. Copying inside the exam screen is allowed."),
      tr("Do not close or reload the page during the exam. Choosing to leave the exam submits all your work after confirmation."),
    ] : []),
    ...(isExam ? [tr("You can submit a single question to check whether it is correct, as many times as you like. This does not submit the exam, and the best result of each question counts.")] : []),
    tr("“Submit & Exit” submits the whole exam. You cannot change your answers afterwards."),
    examSession.showScoresAfterSubmit !== false ? tr("Your score is shown after you submit.") : tr("Your instructor hides scores for this exam."),
  ];

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
    <div className={`exam-session-detail-content-grid${isExamination ? " is-single" : ""}`}>
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
        <h2><TranslatedText>{tr("Read before you start")}</TranslatedText></h2>
        {actionDisabled
          ? <button className="button primary" type="button" disabled><TranslatedText>{actionLabel}</TranslatedText></button>
          : <Link className="button primary" to={examSession.problemIds[0] ? workspace(examSession.problemIds[0]) : examUrl}><TranslatedText>{actionLabel}</TranslatedText></Link>}
      </header>
      <div className="exam-session-briefing">
        <dl className="exam-session-briefing-facts">
          <div><dt><TranslatedText>{tr("Starts")}</TranslatedText></dt><dd>{examSession.date} {examSession.time}</dd></div>
          <div><dt><TranslatedText>{tr("Ends")}</TranslatedText></dt><dd>{examSession.endDate || examSession.date} {examSession.endTime}</dd></div>
          <div><dt><TranslatedText>{tr("Duration")}</TranslatedText></dt><dd>{duration}</dd></div>
          {examSession.examMode && <div><dt><TranslatedText>{tr("Violation limit")}</TranslatedText></dt><dd>{tr("{count} times", { count: violationLimit })}</dd></div>}
          <div><dt><TranslatedText>{tr("Problems")}</TranslatedText></dt><dd>{examSession.problemCount}</dd></div>
          <div><dt><TranslatedText>{tr("Total points")}</TranslatedText></dt><dd>{formatPoints(examSession.totalPoints || 10)}</dd></div>
          {examSession.room && <div><dt><TranslatedText>{tr("Exam room")}</TranslatedText></dt><dd>{examSession.room}</dd></div>}
          {Boolean(examSession.proctors?.length) && <div><dt><TranslatedText>{tr("Proctor")}</TranslatedText></dt><dd>{examSession.proctors!.join(", ")}</dd></div>}
          {isExamination && (finished || phase === "Closed") && <div><dt><TranslatedText>{tr("Your score")}</TranslatedText></dt><dd><TranslatedText>{examSession.score == null ? tr("Score hidden by instructor") : `${formatPoints(examSession.score)}/${formatPoints(examSession.totalPoints)}`}</TranslatedText></dd></div>}
        </dl>
        <ul className="exam-session-briefing-notes">{notes.map((note) => <li key={note}>{note}</li>)}</ul>
        {overview && <section className="exam-session-briefing-overview" aria-label={tr("Exam notes")}>
          <h3><TranslatedText>{tr("Exam notes")}</TranslatedText></h3>
          <ProblemMarkdown className="exam-session-markdown">{overview}</ProblemMarkdown>
        </section>}
      </div>
    </section>
    {!isExamination && <aside className="exam-session-detail-sidebar">
        <section className="exam-session-participation"><h2><TranslatedText>{tr("Participation")}</TranslatedText></h2><dl>
          <div><dt><TranslatedText>{tr("Participants")}</TranslatedText></dt><dd>{examSession.submitters ?? 0}</dd></div>
          {phase === "Live" && <div><dt><TranslatedText>{tr("Your score")}</TranslatedText></dt><dd><TranslatedText>{examSession.score == null ? tr("Score hidden by instructor") : `${formatPoints(examSession.score)}/${formatPoints(examSession.totalPoints)}`}</TranslatedText></dd></div>}
          {examSession.leaderboardEnabled && phase === "Live" && examSession.rank && <div><dt><TranslatedText>{tr("Your rank")}</TranslatedText></dt><dd>#{examSession.rank}</dd></div>}
        </dl>
          {examSession.leaderboardEnabled && phase !== "Upcoming" && <div className="exam-session-leaderboard"><h3><TranslatedText>{tr("Leaderboard")}</TranslatedText></h3>
            {topLeaderboard.length ? <ol className="exam-session-leaderboard-scroll" tabIndex={0} aria-label={tr("Top 50 exam session participants")}>{topLeaderboard.map((entry, index) => <li key={`${entry.rank}-${entry.student}-${index}`}><span>#{entry.rank} {entry.student}</span><b>{formatPoints(entry.score)}</b></li>)}</ol> : <p className="tiny muted"><TranslatedText>{tr("No submissions yet.")}</TranslatedText></p>}
          </div>}
        </section>
    </aside>}
    </div>
  </div>;
}
