import { Table as TableIcon } from "lucide-react";
import { Table } from "@/shared/ui/Table";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import { useLanguage } from "@/shared/context/LanguageContext";
import "@/features/teacher/problem-editor/TeacherSchemaComparison.css";
import "@/features/teacher/problem-editor/TeacherDataComparison.css";

export function TeacherResultTable({ name, table, changedRows = [] }: {
  name: string;
  table: { columns: string[]; rows: unknown[][]; rowCount?: number };
  changedRows?: boolean[];
}) {
  const { t: tr } = useLanguage("teacher");
  return <article className="teacher-schema-snapshot teacher-data-snapshot" aria-label={name}>
    <header><strong><TableIcon size={14} aria-hidden="true" />{name}</strong>
      <span><TranslatedText>{table.rowCount != null && table.rowCount > table.rows.length ? tr("Showing {value0} of {value1} rows", {value0:table.rows.length,value1:table.rowCount}) : tr("{value0} rows", { value0: table.rows.length })}</TranslatedText></span>
    </header>
    <div className="teacher-data-snapshot-scroll">
      <Table density="compact" aria-label={name}>
        <thead><tr>{table.columns.map((column, index) => <th scope="col" key={index}>{column}</th>)}</tr></thead>
        <tbody>{table.rows.length ? table.rows.map((row, rowIndex) => <tr key={rowIndex} className={changedRows[rowIndex] ? "is-changed" : undefined}>
          {row.map((value, columnIndex) => <td key={columnIndex}>{value === null
            ? <code className="teacher-data-null">NULL</code> : String(value)}</td>)}
        </tr>) : <tr><td colSpan={Math.max(1, table.columns.length)} className="teacher-data-empty"><TranslatedText>{tr("This table has no rows.")}</TranslatedText></td></tr>}</tbody>
      </Table>
    </div>
  </article>;
}
