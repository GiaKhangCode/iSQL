import { Key, Link2, Table as TableIcon } from "lucide-react";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import { useLanguage } from "@/shared/context/LanguageContext";
import type { SchemaTable } from "@/shared/lib/sql/parseDatabaseSchema";
import "@/features/teacher/problem-editor/TeacherSchemaComparison.css";

export type SchemaChange = {
  name: string;
  change: "created" | "modified" | "dropped";
  before: SchemaTable | null;
  table: SchemaTable | null;
};

function SchemaSnapshot({ name, table, emptyMessage, showConstraints = true }: {
  name: string;
  table: SchemaTable | null;
  emptyMessage: string;
  showConstraints?: boolean;
}) {
  const { t: tr } = useLanguage("teacher");
  return <article className="teacher-schema-snapshot" aria-label={name}>
    <header>
      <strong><TableIcon size={14} aria-hidden="true" />{name}</strong>
      {table && <span><TranslatedText>{tr("{value0} columns", { value0: table.columns.length })}</TranslatedText></span>}
    </header>
    {table ? <div className="teacher-schema-snapshot-columns">
      {table.columns.map(column => {
        const isPk = table.primaryKeys.some(key => key.toLowerCase() === column.name.toLowerCase());
        const foreignKey = table.foreignKeys.find(key => key.columns.some(name => name.toLowerCase() === column.name.toLowerCase()));
        const foreignKeyIndex = foreignKey?.columns.findIndex(name => name.toLowerCase() === column.name.toLowerCase()) ?? -1;
        const target = foreignKey ? `${foreignKey.referencedTable}.${foreignKey.referencedColumns[foreignKeyIndex] || foreignKey.referencedColumns[0] || ""}` : "";
        return <div className="teacher-schema-snapshot-column" key={column.name} title={column.constraints}>
          <div className="teacher-schema-snapshot-column-name">
            {isPk && <span className="teacher-schema-key-badge is-pk" title={tr("Primary key (PK)")}><Key size={10} aria-hidden="true" /> PK</span>}
            {foreignKey && <span className="teacher-schema-key-badge is-fk" title={tr("Foreign key (FK)")}><Link2 size={10} aria-hidden="true" /> FK</span>}
            <strong>{column.name}</strong>
          </div>
          <span className="teacher-schema-snapshot-type"><TranslatedText>{column.type || tr("Unknown type")}</TranslatedText></span>
          {target && <small className="teacher-schema-snapshot-reference">→ {target}</small>}
          {showConstraints && foreignKey && (foreignKey.isDisabled || foreignKey.isNotTrusted) && <small className="comparison-different"><TranslatedText>{tr(foreignKey.isDisabled ? "Foreign key disabled" : "Foreign key not trusted for existing data")}</TranslatedText></small>}
          {showConstraints && column.constraints && <small className="review-schema-constraints">{column.constraints}</small>}
        </div>;
      })}
      {showConstraints && Boolean(table.constraints?.length) && <p className="review-schema-constraints">{table.constraints.join(" · ")}</p>}
    </div> : <p className="teacher-schema-snapshot-empty"><TranslatedText>{emptyMessage}</TranslatedText></p>}
  </article>;
}

export function TeacherSchemaComparison({ changes, beforeLabel, afterLabel, showConstraints }: { changes: SchemaChange[]; beforeLabel?: string; afterLabel?: string; showConstraints?: boolean }) {
  const { t: tr } = useLanguage("teacher");
  return <div className="teacher-schema-comparison">
    {changes.map(change => <div className="teacher-schema-comparison-pair" key={change.name}>
      <section className="teacher-schema-comparison-before" aria-label={beforeLabel || tr("Before changes")}>
        <h4><TranslatedText>{beforeLabel || tr("Before changes")}</TranslatedText></h4>
        <SchemaSnapshot name={change.name} table={change.before} showConstraints={showConstraints} emptyMessage={tr(beforeLabel ? "Table not present." : "This table does not exist yet.")} />
      </section>
      <section className="teacher-schema-comparison-after" aria-label={afterLabel || tr("After changes")}>
        <h4><TranslatedText>{afterLabel || tr("After changes")}</TranslatedText></h4>
        <SchemaSnapshot name={change.name} table={change.table} showConstraints={showConstraints} emptyMessage={tr(afterLabel ? "Table not present." : "This table has been dropped.")} />
      </section>
    </div>)}
  </div>;
}
