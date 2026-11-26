import type { DataTable } from "@/data/models";
import type { QueryResult } from "@/shared/types/queryResult";
import type { SchemaTable } from "@/shared/lib/sql/parseDatabaseSchema";
import type { WorkspaceDatabaseState } from "@/shared/types/workspaceDatabase";
import { DataGrid, Loading } from "@/shared/ui/ui";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import { useLanguage } from "@/shared/context/LanguageContext";
import { useState } from "react";
import { RotateCcw } from "lucide-react";
import { DatabaseDiagram } from "@/shared/components/DatabaseDiagram";
import { SqlErrorMessage } from "@/shared/components/SqlErrorMessage";

export type { WorkspaceDatabaseState } from "@/shared/types/workspaceDatabase";

export function ExecutionFeedback({ result, error }: { result?: QueryResult | null; error?: string }) {
  if (!error && result?.status !== "Error") return null;
  return <div className="workspace-execution-feedback is-error" role="alert">
    <SqlErrorMessage message={error || result?.message || result?.status || ""} />
  </div>;
}

export type DatabaseView = "reference" | "current";

export function WorkspaceDatabasePanel({ database, result, error, currentLoading = false, currentError = "", onResetCurrent, structureVisible = true, questionStartFrom, questionKind, loading = false, onRetry, prepared = false, questionName, referenceSchema, current, view, onViewChange, preferCurrent = false }: {
  loading?: boolean; onRetry?: () => void; prepared?: boolean; questionName?: string;
  /** Đang đọc lại schema của sinh viên / lỗi khi đọc (chỉ dùng cho chế độ "Hiện tại"). */
  currentLoading?: boolean; currentError?: string;
  /** Làm lại từ đầu schema của sinh viên (xóa mọi bảng). Chỉ hiện ở chế độ "Hiện tại". */
  onResetCurrent?: () => Promise<void>;
  questionKind?: "schema" | "seed";
  database: WorkspaceDatabaseState; result?: QueryResult | null; error?: string;
  structureVisible?: boolean;
  questionStartFrom?: "empty" | "structure" | "provided";
  /** Full reference structure, independent of the question's starting state. */
  referenceSchema?: SchemaTable[];
  /** The student's own sandbox after their last run (null = not run yet). Enables the reference/current switch. */
  current?: WorkspaceDatabaseState | null;
  view?: DatabaseView; onViewChange?: (view: DatabaseView) => void;
  /** Open on the student's own database once it exists (DDL work). */
  preferCurrent?: boolean;
}) {
  const { t: tr } = useLanguage("workspace");
  const [confirmingReset, setConfirmingReset] = useState(false);
  const [resetting, setResetting] = useState(false);
  if (loading) return <Loading variant="table" columns={3} rows={5} label={tr("Loading this question's starting database…")} />;
  const loadingCurrent = currentLoading && !current;
  if (error && onRetry) return <div className="workspace-database-content"><div role="alert"><SqlErrorMessage message={error} /></div><button className="button" onClick={onRetry}><TranslatedText>{tr("Retry")}</TranslatedText></button></div>;
  // Reference view always shows the database diagram; switchable allows toggling Reference/Current.
  const switchable = Boolean(onViewChange);
  const effective: DatabaseView = !switchable ? "reference" : view ?? (preferCurrent && current ? "current" : "reference");
  const showingCurrent = effective === "current";
  const schemaTables = showingCurrent ? current?.schemaTables ?? [] : referenceSchema?.length ? referenceSchema : database.schemaTables;
  const shown = showingCurrent ? current : database;
  const dataTables = shown?.tables ?? [];
  const caption = showingCurrent ? (currentError ? "Your current database, as of your last run" : "Your current database")
    : prepared ? "Starting database for this question, including instructor preparation."
    : questionKind === "schema" ? "Reference structure to create"
    : switchable ? "Reference database structure"
    : questionStartFrom === "empty" ? "Question starts with an empty database" : questionStartFrom === "structure" ? "Question starts with the original structure and no rows" : questionStartFrom === "provided" ? "Question starts with the original structure and data" : "Original database for the current question";
  return <div className="workspace-database-content">
    {(questionName || switchable) && <div className="workspace-database-toolbar">
      {questionName && <strong className="workspace-database-question-name">{questionName}</strong>}
      {switchable && <div className="workspace-database-view-switch" role="group" aria-label={tr("Database view")}>
        {(["reference", "current"] as const).map(option => <button key={option} type="button" aria-pressed={effective === option} onClick={() => onViewChange?.(option)}>
          <TranslatedText>{tr(option === "reference" ? "Reference" : "Current")}</TranslatedText>
        </button>)}
      </div>}
    </div>}
    <div className="workspace-database-caption-row">
      <p className="muted tiny"><TranslatedText>{tr(caption)}</TranslatedText></p>
      {showingCurrent && onResetCurrent && !confirmingReset && <button type="button" className="workspace-database-reset-button" title={tr("Reset my database")} onClick={() => setConfirmingReset(true)}>
        <RotateCcw size={12} aria-hidden="true" /><TranslatedText>{tr("Start over")}</TranslatedText>
      </button>}
    </div>
    {showingCurrent && onResetCurrent && confirmingReset && <div className="workspace-database-reset workspace-database-reset-confirm" role="alert">
      <span><TranslatedText>{tr("Delete every table in your database and start over?")}</TranslatedText></span>
      <button type="button" className="button teacher-small-button" disabled={resetting} onClick={() => setConfirmingReset(false)}><TranslatedText>{tr("Cancel")}</TranslatedText></button>
      <button type="button" className="button teacher-small-button teacher-danger-button" disabled={resetting} onClick={async () => {
        setResetting(true);
        try { await onResetCurrent(); } finally { setResetting(false); setConfirmingReset(false); }
      }}><TranslatedText>{tr("Delete and start over")}</TranslatedText></button>
    </div>}
    {showingCurrent && currentError && <p className="muted tiny" role="alert"><TranslatedText>{tr("Could not refresh your database. Showing the last known state.")}</TranslatedText></p>}
    {showingCurrent && <ExecutionFeedback result={result} error={error} />}
    {(showingCurrent ? current?.stale : database.stale) && <p className="muted tiny"><TranslatedText>{tr("Showing the last successful run. Run your updated script to refresh the database.")}</TranslatedText></p>}
    {(!showingCurrent || structureVisible) && (schemaTables.length ? <DatabaseDiagram tables={schemaTables} allowDownload={false} /> : <p className="muted"><TranslatedText>{tr(loadingCurrent ? "Reading your database…" : showingCurrent ? "Your database has no tables yet." : "No database structure is available.")}</TranslatedText></p>)}
    {questionKind !== "schema" && !(!showingCurrent && questionStartFrom === "empty") && !(showingCurrent && !dataTables.length) && <section className="workspace-database-data">
      <h3><TranslatedText>{tr("Table data")}</TranslatedText></h3>
      {dataTables.length ? dataTables.map((table, index) => <section className="workspace-database-table" key={`${table.name}-${index}`}>
        <header><strong>{table.name}</strong><small>{table.rows.length} <TranslatedText>{tr("rows")}</TranslatedText></small></header>
        <DataGrid table={table} />
      </section>) : <p className="muted tiny"><TranslatedText>{tr("No visible sample data.")}</TranslatedText></p>}
    </section>}
  </div>;
}
