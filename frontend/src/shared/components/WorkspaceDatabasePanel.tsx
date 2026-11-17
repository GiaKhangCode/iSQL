import type { DataTable } from "@/data/models";
import type { QueryResult } from "@/shared/types/queryResult";
import type { SchemaTable } from "@/shared/lib/sql/parseDatabaseSchema";
import type { WorkspaceDatabaseState } from "@/shared/types/workspaceDatabase";
import { DataGrid, Loading } from "@/shared/ui/ui";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import { useLanguage } from "@/shared/context/LanguageContext";
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

export function WorkspaceDatabasePanel({ database, result, error, structureVisible = true, questionStartFrom, questionKind, loading = false, onRetry, prepared = false, questionName, referenceSchema, current, view, onViewChange, preferCurrent = false }: {
  loading?: boolean; onRetry?: () => void; prepared?: boolean; questionName?: string;
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
  if (loading) return <Loading variant="table" columns={3} rows={5} label={tr("Loading this question's starting database…")} />;
  if (error && onRetry) return <div className="workspace-database-content"><div role="alert"><SqlErrorMessage message={error} /></div><button className="button" onClick={onRetry}><TranslatedText>{tr("Retry")}</TranslatedText></button></div>;
  // Reference view always shows the database diagram; switchable allows toggling Reference/Current.
  const switchable = Boolean(onViewChange);
  const effective: DatabaseView = !switchable ? "reference" : view ?? (preferCurrent && current ? "current" : "reference");
  const showingCurrent = effective === "current";
  const schemaTables = showingCurrent ? current?.schemaTables ?? [] : referenceSchema?.length ? referenceSchema : database.schemaTables;
  const shown = showingCurrent ? current : database;
  const dataTables = shown?.tables ?? [];
  const caption = showingCurrent ? "Your current database, as of your last run"
    : prepared ? "Starting database for this question, including instructor preparation."
    : questionKind === "schema" ? "Reference structure to create"
    : switchable ? "Reference database structure"
    : questionStartFrom === "empty" ? "Question starts with an empty database" : questionStartFrom === "structure" ? "Question starts with the original structure and no rows" : questionStartFrom === "provided" ? "Question starts with the original structure and data" : "Original database for the current question";
  return <div className="workspace-database-content">
    {questionName && <strong className="workspace-database-question-name">{questionName}</strong>}
    {switchable && <div className="workspace-database-view-switch" role="group" aria-label={tr("Database view")}>
      {(["reference", "current"] as const).map(option => <button key={option} type="button" className="button teacher-small-button" aria-pressed={effective === option} onClick={() => onViewChange?.(option)}>
        <TranslatedText>{tr(option === "reference" ? "Reference" : "Current")}</TranslatedText>
      </button>)}
    </div>}
    <p className="muted tiny"><TranslatedText>{tr(caption)}</TranslatedText></p>
    {showingCurrent && <ExecutionFeedback result={result} error={error} />}
    {(showingCurrent ? current?.stale : database.stale) && <p className="muted tiny"><TranslatedText>{tr("Showing the last successful run. Run your updated script to refresh the database.")}</TranslatedText></p>}
    {(!showingCurrent || structureVisible) && (schemaTables.length ? <DatabaseDiagram tables={schemaTables} allowDownload={false} /> : <p className="muted"><TranslatedText>{tr(showingCurrent ? "Run your script to see your current database structure." : "No database structure is available.")}</TranslatedText></p>)}
    {questionKind !== "schema" && !(!showingCurrent && questionStartFrom === "empty") && !(showingCurrent && !dataTables.length) && <section className="workspace-database-data">
      <h3><TranslatedText>{tr("Table data")}</TranslatedText></h3>
      {dataTables.length ? dataTables.map((table, index) => <section className="workspace-database-table" key={`${table.name}-${index}`}>
        <header><strong>{table.name}</strong><small>{table.rows.length} <TranslatedText>{tr("rows")}</TranslatedText></small></header>
        <DataGrid table={table} />
      </section>) : <p className="muted tiny"><TranslatedText>{tr("No visible sample data.")}</TranslatedText></p>}
    </section>}
  </div>;
}
