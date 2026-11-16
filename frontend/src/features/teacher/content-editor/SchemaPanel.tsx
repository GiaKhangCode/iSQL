import { Play } from "lucide-react";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import { useLanguage } from "@/shared/context/LanguageContext";
import { parseDatabaseSchema } from "@/shared/lib/sql/parseDatabaseSchema";
import { TeacherSchemaDiagram } from "@/features/teacher/problem-editor/TeacherSchemaDiagram";
import { ProblemSqlEditor } from "@/features/teacher/problem-editor/ProblemSqlEditor";
import { SqlFileNameField } from "@/features/teacher/DatabaseLibraryParts";

export type SchemaDiagramState = { source: string; tables: ReturnType<typeof parseDatabaseSchema> } | null;

export function buildSchemaDiagram(sql: string): SchemaDiagramState {
  return { source: sql, tables: parseDatabaseSchema(sql) };
}

/** Which page hosts the panel. Only markup/CSS hooks differ; behaviour is shared. */
export type ContentEditorVariant = "library" | "problem";

/**
 * Table-structure editor shared by the problem editor and the database library:
 * CREATE TABLE script, file name, "Run" and the relationship diagram.
 * The caller owns the wrapper element (ids, tabpanel role, `hidden`) and the diagram state.
 */
export function SchemaPanel({ variant, sql, onSqlChange, fileName, onFileNameChange, diagram, onRun }: {
  variant: ContentEditorVariant;
  sql: string;
  onSqlChange: (value: string) => void;
  fileName: string;
  onFileNameChange: (value: string) => void;
  diagram: SchemaDiagramState;
  onRun: () => void;
}) {
  const { t: tr } = useLanguage("teacher");
  const isLibrary = variant === "library";
  const diagramIsCurrent = diagram?.source === sql;

  const editor = isLibrary
    ? <ProblemSqlEditor className="teacher-database-library-editor" label={tr("Create table script")} value={sql}
        placeholder={tr("Write CREATE TABLE statements…")} onChange={onSqlChange} />
    : <div id="database-sql-input" className="teacher-problem-navigation-target">
        <ProblemSqlEditor label={tr("Database structure SQL")} value={sql} onChange={onSqlChange}
          placeholder={tr("-- Example SQL Server DDL\nCREATE TABLE Customers (\n    CustomerId INT PRIMARY KEY,\n    FullName NVARCHAR(100) NOT NULL\n);")} />
      </div>;

  const fileNameField = isLibrary
    ? <SqlFileNameField compact label={tr("Table file name")} value={fileName} onChange={onFileNameChange} />
    : <label className="teacher-schema-filename">
        <input aria-label={tr("Database file name")} value={(fileName || "database.sql").replace(/\.sql$/i, "")}
          onChange={event => {
            const base = event.target.value.replace(/[<>:"/\\|?*\u0000-\u001f]/g, "").replace(/[. ]+$/g, "");
            onFileNameChange(base ? `${base}.sql` : "");
          }} />
        <span>.sql</span>
      </label>;

  return <>
    {editor}
    <div className="teacher-schema-run-row">
      <p><TranslatedText>{tr("Preview tables, keys, and relationships from this database script.")}</TranslatedText></p>
      <div className={isLibrary ? "library-schema-run-actions" : "teacher-schema-run-actions"}>
        {fileNameField}
        <button className="button primary" type="button" onClick={onRun} disabled={!sql.trim()}>
          <Play size={14} aria-hidden="true" />
          <TranslatedText>{isLibrary ? tr("Run") : tr("Run database structure")}</TranslatedText>
        </button>
      </div>
    </div>
    <div className="teacher-schema-diagram-result" aria-live="polite">
      {!diagram && <p className="teacher-schema-diagram-message"><TranslatedText>{tr("Run DDL to preview tables and key relationships.")}</TranslatedText></p>}
      {diagram && !diagramIsCurrent && <p className="teacher-schema-diagram-message is-stale"><TranslatedText>{tr("Database structure changed since the last preview. Run DDL to refresh the diagram.")}</TranslatedText></p>}
      {diagramIsCurrent && diagram.tables.length === 0 && <p className="teacher-schema-diagram-message is-error"><TranslatedText>{tr("No CREATE TABLE statements found. Add at least one table, then run DDL again.")}</TranslatedText></p>}
      {diagramIsCurrent && diagram.tables.length > 0 && <TeacherSchemaDiagram tables={diagram.tables} />}
    </div>
  </>;
}
