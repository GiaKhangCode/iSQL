import { Clock3, DoorOpen, Send } from "lucide-react";
import { useLanguage } from "@/shared/context/LanguageContext";
import { formatTime } from "@/shared/lib/workspace/workspacePreferences";
import { useWorkspaceCountdown, useWorkspaceExam } from "@/features/workspace/components/WorkspaceExamContext";

const DAY_MS = 86_400_000;

/** "17 ngày 11:29:58" khi còn trên 1 ngày, "11:29:58" khi dưới 1 ngày. */
export function formatRemaining(milliseconds: number, dayLabel: (days: number, time: string) => string) {
  const safe = Math.max(0, milliseconds);
  const days = Math.floor(safe / DAY_MS);
  const time = formatTime(safe % DAY_MS);
  return days > 0 ? dayLabel(days, time) : time;
}

/**
 * Cụm trạng thái gọn trên thanh trên cùng của workspace, ngay bên trái nút Nộp bài:
 * thời gian còn lại (nếu có hạn), số lần nộp toàn bài, và số lần rời khỏi bài thi (chỉ khi thi).
 * Không có gì để hiện thì không render, để nút Nộp bài vẫn sát lề phải.
 */
export function WorkspaceHeaderStatus({ submissionsUsed, maxSubmissions }: { submissionsUsed?: number; maxSubmissions?: number | null }) {
  const { t } = useLanguage("workspace");
  const remainingMs = useWorkspaceCountdown();
  const exam = useWorkspaceExam();
  const showSubmissions = submissionsUsed != null;
  const showDepartures = Boolean(exam?.enabled);
  if (remainingMs == null && !showSubmissions && !showDepartures) return null;
  const submissionsLabel = `${t("Whole-problem submissions")}: ${submissionsUsed} / ${maxSubmissions ?? t("Unlimited")}`;
  return <div className="workspace-header-status">
    {remainingMs != null && <span className={`workspace-header-chip workspace-deadline${remainingMs <= 60000 ? " is-urgent" : ""}`} role="timer" aria-label={t("Time remaining")} title={t("Work is submitted automatically when time runs out.")}>
      <Clock3 size={14} aria-hidden="true" />
      <b>{formatRemaining(remainingMs, (days, time) => t("{value0} days {value1}", { value0: days, value1: time }))}</b>
    </span>}
    {showSubmissions && <span className="workspace-header-chip workspace-submission-count" title={submissionsLabel} aria-label={submissionsLabel}>
      <Send size={13} aria-hidden="true" />
      <b>{submissionsUsed}/{maxSubmissions ?? "∞"}</b>
    </span>}
    {showDepartures && <span className="workspace-header-chip workspace-departures" title={t("Workspace departures")} aria-label={`${t("Workspace departures")}: ${exam?.departures || 0}`}>
      <DoorOpen size={14} aria-hidden="true" />
      <b>{exam?.departures || 0}</b>
    </span>}
  </div>;
}
