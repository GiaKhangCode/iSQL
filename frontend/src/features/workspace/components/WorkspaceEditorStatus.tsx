import { TranslatedText } from "@/shared/ui/TranslatedText";
import { Clock3 } from "lucide-react";
import { useLanguage } from "@/shared/context/LanguageContext";
import { formatTime } from "@/shared/lib/workspace/workspacePreferences";
import { useWorkspaceCountdown, useWorkspaceExam } from "@/features/workspace/components/WorkspaceExamContext";

export function WorkspaceEditorStatus({ line, column, executionTimeMs, submissionsUsed, maxSubmissions }: { line: number; column: number; executionTimeMs?: number; submissionsUsed?: number; maxSubmissions?: number | null }) {
  const { t } = useLanguage("workspace");
  const remainingMs = useWorkspaceCountdown();
  const exam = useWorkspaceExam();
  return <div className="workspace-editor-status">
    <span className="cursor-position muted"><TranslatedText>{t("Ln")}</TranslatedText> {line}<TranslatedText>{t(", Col")}</TranslatedText> {column}</span>
    {submissionsUsed != null && <span className="workspace-submission-count"><TranslatedText>{t("Whole-problem submissions")}</TranslatedText> · <b>{submissionsUsed} / <TranslatedText>{maxSubmissions ?? t("Unlimited")}</TranslatedText></b></span>}
    {remainingMs != null && <span className={`workspace-deadline${remainingMs <= 60000 ? " is-urgent" : ""}`} role="timer" aria-label={t("Time remaining")} title={t("Work is submitted automatically when time runs out.")}><Clock3 size={14} /><TranslatedText>{t("Time left")}</TranslatedText> <b>{formatTime(Math.max(0, remainingMs))}</b></span>}
    {exam?.enabled && <span className="muted"><TranslatedText>{t("Workspace departures")}</TranslatedText> · <b>{exam.departures || 0}</b></span>}
    {executionTimeMs != null && <span className="workspace-execution-time muted"><TranslatedText>{t("Execution time")}</TranslatedText> · {executionTimeMs.toFixed(2)} <TranslatedText>{t("ms")}</TranslatedText></span>}
  </div>;
}
