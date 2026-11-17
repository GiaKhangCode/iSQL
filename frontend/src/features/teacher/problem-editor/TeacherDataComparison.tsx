import { Table as TableIcon } from "lucide-react";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import { useLanguage } from "@/shared/context/LanguageContext";
import "@/features/teacher/problem-editor/TeacherSchemaComparison.css";
import "@/features/teacher/problem-editor/TeacherDataComparison.css";
import { TeacherResultTable } from "@/features/teacher/problem-editor/TeacherResultTable";

type DataSnapshot = { name: string; columns: string[]; rows: unknown[][] };
export type DataChange = {
  name: string;
  change: "created" | "modified" | "dropped";
  before: DataSnapshot | null;
  table: DataSnapshot | null;
};

function changedRows(table: DataSnapshot, other: DataSnapshot | null) {
  const counts = new Map<string, number>();
  if (other && JSON.stringify(table.columns) === JSON.stringify(other.columns)) {
    for (const row of other.rows) {
      const key = JSON.stringify(row);
      counts.set(key, (counts.get(key) || 0) + 1);
    }
  }
  return table.rows.map(row => {
    const key = JSON.stringify(row), count = counts.get(key) || 0;
    if (!count) return true;
    counts.set(key, count - 1);
    return false;
  });
}

function DataSnapshotView({ name, table, other, emptyMessage }: {
  name: string; table: DataSnapshot | null; other: DataSnapshot | null; emptyMessage: string;
}) {
  if (table) return <TeacherResultTable name={name} table={table} changedRows={changedRows(table, other)} />;
  return <article className="teacher-schema-snapshot teacher-data-snapshot" aria-label={name}>
    <header><strong><TableIcon size={14} aria-hidden="true" />{name}</strong></header>
    <p className="teacher-schema-snapshot-empty"><TranslatedText>{emptyMessage}</TranslatedText></p>
  </article>;
}

function hasAnyDifference(changes: DataChange[]) {
  return changes.some(change => {
    if (!change.before || !change.table) return Boolean(change.before) !== Boolean(change.table);
    return changedRows(change.before, change.table).some(Boolean)
      || changedRows(change.table, change.before).some(Boolean);
  });
}

export function TeacherDataComparison({ changes, beforeLabel, afterLabel }: { changes: DataChange[]; beforeLabel?: string; afterLabel?: string }) {
  const { t: tr } = useLanguage("teacher");
  const hasDiff = beforeLabel ? hasAnyDifference(changes) : true;
  return <div className="teacher-data-comparison">
    <p className="teacher-data-comparison-hint"><TranslatedText>{tr(
      beforeLabel
        ? (hasDiff ? "Highlighted rows differ from the reference." : "All rows match the reference.")
        : "Highlighted rows show data that changed."
    )}</TranslatedText></p>
    <div className="teacher-schema-comparison">{changes.map(change => <div className="teacher-schema-comparison-pair" key={change.name}>
      <section className="teacher-data-comparison-before" aria-label={beforeLabel || tr("Before changes")}>
        <h4><TranslatedText>{beforeLabel || tr("Before changes")}</TranslatedText></h4>
        <DataSnapshotView name={change.name} table={change.before} other={change.table} emptyMessage={tr(beforeLabel ? "Table not present." : "This table does not exist yet.")} />
      </section>
      <section className="teacher-data-comparison-after" aria-label={afterLabel || tr("After changes")}>
        <h4><TranslatedText>{afterLabel || tr("After changes")}</TranslatedText></h4>
        <DataSnapshotView name={change.name} table={change.table} other={change.before} emptyMessage={tr(afterLabel ? "Table not present." : "This table has been dropped.")} />
      </section>
    </div>)}</div>
  </div>;
}
