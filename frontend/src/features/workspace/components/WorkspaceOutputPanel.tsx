import { useEffect, useId, useState, type KeyboardEvent } from "react";
import { ChevronUp } from "lucide-react";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import { DataGrid, Status } from "@/shared/ui/ui";
import { useLanguage } from "@/shared/context/LanguageContext";
import { SqlErrorMessage } from "@/shared/components/SqlErrorMessage";
import { SubmitQuestionButton, type WorkspaceAttempt } from "@/features/workspace/components/WorkspaceSubmissionPanel";
import type { DataTable, WorkspacePart } from "@/data/models";
import type { QueryResult } from "@/shared/types/queryResult";
import "@/features/workspace/components/WorkspaceOutputPanel.css";

type OutputTab = "results" | "messages";
type SubmitQuestion = (attempt: WorkspaceAttempt) => void | QueryResult | Promise<void | QueryResult>;

const isError = (result: QueryResult) => /error|time limit/i.test(result.status);

/** Nhãn của một lần chạy. DQL: Lỗi thực thi / Sai kết quả / Đúng (so với đáp án). Loại khác: chỉ nhãn lỗi. */
function verdict(result: QueryResult, part: WorkspacePart): "Runtime Error" | "Wrong Answer" | "Correct" | null {
  if (isError(result)) return "Runtime Error";
  if (part !== "SQL") return null;
  if (typeof result.total === "number" && result.total > 0 && typeof result.passed === "number") return result.passed === result.total ? "Correct" : "Wrong Answer";
  const flags = (result.comparisons || []).map(item => item.matched).filter((value): value is boolean => typeof value === "boolean");
  return flags.length ? (flags.every(Boolean) ? "Correct" : "Wrong Answer") : null;
}

/** Lưới kết quả của câu DQL: chỉ bộ dữ liệu đầu tiên. */
function resultGrid(result: QueryResult, part: WorkspacePart): DataTable | undefined {
  if (part !== "SQL" || isError(result)) return undefined;
  return result.comparisons?.[0]?.actualTable || result.table || result.tables?.[0];
}

/**
 * Panel kết quả ở đáy khung soạn SQL, theo kiểu SSMS: tab Kết quả (lưới dữ liệu, chỉ câu DQL) và tab Thông báo
 * ("(n rows affected)", lỗi SQL, thời điểm hoàn thành). Nhãn đúng/sai và nút "Nộp câu này" ở bên phải thanh tiêu đề.
 * Chiều cao do biến CSS --editor-pane-height của khung chứa quyết định; khi thu gọn chỉ còn thanh tiêu đề.
 */
export function WorkspaceOutputPanel({ open, onOpenChange, busy, part, questionId, attempts, currentScript, onSubmitQuestion, submitBusy = false }: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  busy: boolean;
  part: WorkspacePart;
  questionId?: string;
  attempts: WorkspaceAttempt[];
  currentScript?: string;
  onSubmitQuestion?: SubmitQuestion;
  submitBusy?: boolean;
}) {
  const { t: tr } = useLanguage("workspace");
  const { t, locale } = useLanguage();
  const prefix = useId();
  const id = questionId || "";
  const latest = attempts.find(attempt => attempt.kind === "run" && (!attempt.part || attempt.part === part) && (!attempt.result.questionId || attempt.result.questionId === id));
  const result = latest?.result;
  const grid = result ? resultGrid(result, part) : undefined;
  const label = result ? verdict(result, part) : null;
  const [tab, setTab] = useState<OutputTab>("messages");
  const activeTab: OutputTab = grid ? tab : "messages";
  // Mỗi lần chạy mới: có lưới thì mở tab Kết quả, lỗi hoặc không có lưới thì mở tab Thông báo (như SSMS).
  useEffect(() => { if (latest) setTab(grid ? "results" : "messages"); }, [latest?.id]);

  const submitted = attempts.find(attempt => attempt.kind === "submit" && (!attempt.part || attempt.part === part) && attempt.result.questionId === id);
  const submittedStatus = submitted?.result.sections?.find(section => section.part === part)?.questions?.find(item => item.id === id)?.status || submitted?.result.status || "";
  const failedSubmission = Boolean(submitted && /error|runtime/i.test(submittedStatus));
  const stale = Boolean(latest && currentScript !== undefined && currentScript.trim() !== latest.script?.trim());
  const alreadySubmitted = !failedSubmission && Boolean(submitted?.script?.trim() && submitted.script.trim() === latest?.script?.trim());
  const canSubmit = Boolean(onSubmitQuestion && latest?.script?.trim() && latest.result.questionId);

  const tabs: OutputTab[] = grid ? ["results", "messages"] : ["messages"];
  const choose = (next: OutputTab) => { setTab(next); if (!open) onOpenChange(true); };
  const onTabKey = (event: KeyboardEvent<HTMLButtonElement>, index: number) => {
    const next = event.key === "ArrowRight" ? (index + 1) % tabs.length : event.key === "ArrowLeft" ? (index + tabs.length - 1) % tabs.length : -1;
    if (next < 0) return;
    event.preventDefault();
    choose(tabs[next]);
    document.getElementById(`${prefix}-tab-${tabs[next]}`)?.focus();
  };
  const finishedAt = latest && latest.id > 1e12 ? new Date(Math.floor(latest.id)).toLocaleString(locale) : "";
  const rowsAffected = (count: number) => tr(count === 1 ? "(1 row affected)" : "({value0} rows affected)", { value0: count });
  const counts = result ? (grid ? [grid.rowCount ?? grid.rows.length] : result.rowCounts || []) : [];

  return <section className={`workspace-output${open ? " is-open" : ""}`} aria-label={tr("Output")}>
    <header className="workspace-output-header">
      <button type="button" className="workspace-output-toggle" aria-expanded={open} aria-controls={`${prefix}-panel`} onClick={() => onOpenChange(!open)} aria-label={tr(open ? "Collapse output" : "Expand output")} title={tr(open ? "Collapse output" : "Expand output")}>
        <ChevronUp size={14} className="workspace-output-chevron" aria-hidden="true" />
      </button>
      <div className="workspace-output-tabs" role="tablist" aria-label={tr("Output")}>
        {tabs.map((item, index) => <button key={item} id={`${prefix}-tab-${item}`} type="button" role="tab" aria-selected={open && activeTab === item} aria-controls={`${prefix}-panel`} tabIndex={activeTab === item ? 0 : -1} onClick={() => choose(item)} onKeyDown={event => onTabKey(event, index)}>
          <TranslatedText>{tr(item === "results" ? "Results" : "Messages")}</TranslatedText>
        </button>)}
      </div>
      <div className="workspace-output-summary" aria-live="polite">
        {busy ? <span><TranslatedText>{tr("Running…")}</TranslatedText></span> : result && <>
          {label === "Correct" ? <span className="status success"><TranslatedText>{t("Correct")}</TranslatedText></span> : label && <Status value={label} />}
          {result.executionTimeMs != null && <span className="workspace-output-time">{result.executionTimeMs.toFixed(0)} <TranslatedText>{tr("ms")}</TranslatedText></span>}
        </>}
        {canSubmit && latest && onSubmitQuestion && <SubmitQuestionButton key={`${latest.id}-${stale}`} attempt={latest} onSubmit={onSubmitQuestion} submitted={!stale && alreadySubmitted} busy={busy || submitBusy} retry={failedSubmission} stale={stale} />}
      </div>
    </header>
    {open && <div className="workspace-output-body" id={`${prefix}-panel`} role="tabpanel" aria-labelledby={`${prefix}-tab-${activeTab}`} tabIndex={0}>
      {busy ? <p className="workspace-output-messages muted"><TranslatedText>{tr("Running…")}</TranslatedText></p>
        : !result ? <p className="workspace-output-empty muted"><TranslatedText>{tr("Run your SQL to see results and messages here.")}</TranslatedText></p>
        : activeTab === "results" && grid ? <div className="workspace-output-grid">
          <DataGrid table={grid.name === "Result" ? { ...grid, name: tr("Result") } : grid} />
          {grid.rowCount != null && grid.rowCount > grid.rows.length && <small className="muted"><TranslatedText>{tr("Showing {value0} of {value1} rows", { value0: grid.rows.length, value1: grid.rowCount })}</TranslatedText></small>}
        </div>
        : <div className="workspace-output-messages">
          {counts.map((count, index) => <p key={index}>{rowsAffected(count)}</p>)}
          {isError(result) ? <div className="workspace-output-error" role="alert"><SqlErrorMessage message={result.message} /></div>
            : !counts.length && <p><TranslatedText>{tr("Commands completed successfully.")}</TranslatedText></p>}
          {finishedAt && <p className="workspace-output-completion"><TranslatedText>{tr("Completion time: {value0}", { value0: finishedAt })}</TranslatedText></p>}
        </div>}
    </div>}
  </section>;
}
