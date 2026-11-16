import { Table2 } from "lucide-react";
import { Table } from "@/shared/ui/Table";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import { useLanguage } from "@/shared/context/LanguageContext";
import { parseSeedData } from "@/shared/lib/sql/parseSeedData";

export type DatasetDataPreviewVariant = "problem-editor" | "database-library";

export function DatasetDataPreview({ datasetNumber, seedData, variant }: { datasetNumber: number; seedData: string; variant: DatasetDataPreviewVariant }) {
  const { t: tr } = useLanguage("teacher");
  const tables = parseSeedData(seedData);
  const rowCount = tables.reduce((total, table) => total + table.rows.length, 0);
  const library = variant === "database-library";

  return (
    <section className={library ? "teacher-database-library-preview" : "teacher-dataset-preview"} aria-label={tr("Dataset {value0} preview", { value0: datasetNumber })} aria-live="polite">
      <div className={library ? "teacher-database-library-preview-heading" : "teacher-dataset-preview-heading"}>
        <div className={library ? "teacher-database-library-preview-title" : "teacher-dataset-preview-title"}><Table2 size={library ? 14 : 15} aria-hidden="true" /><strong><TranslatedText>{tr("Dataset {value0} preview", { value0: datasetNumber })}</TranslatedText></strong></div>
        {tables.length > 0 && <span><TranslatedText>{tr("{value0} tables · {value1} rows", { value0: tables.length, value1: rowCount })}</TranslatedText></span>}
      </div>
      {tables.length > 0 ? (
        <div className={library ? "teacher-database-library-preview-tables" : "teacher-dataset-preview-tables"}>
          {tables.map((table, tableIndex) => (
            <section className={library ? "teacher-database-library-preview-table" : "teacher-dataset-preview-table-card"} key={`${table.name}-${tableIndex}`}>
              <div className={library ? "teacher-database-library-preview-table-heading" : "teacher-dataset-preview-table-title"}>{library ? <strong>{table.name}</strong> : <h4>{table.name}</h4>}<span><TranslatedText>{tr("{value0} rows", { value0: table.rows.length })}</TranslatedText></span></div>
              <div className={library ? "teacher-database-library-preview-scroll" : "teacher-dataset-preview-scroll"}>
                <Table>
                  <thead><tr>{table.columns.map((column) => <th scope="col" key={column}>{column}</th>)}</tr></thead>
                  <tbody>{table.rows.map((row, rowIndex) => <tr key={rowIndex}>{row.map((value, columnIndex) => <td key={`${table.columns[columnIndex]}-${columnIndex}`}>{value}</td>)}</tr>)}</tbody>
                </Table>
              </div>
            </section>
          ))}
        </div>
      ) : (
        <p className={library ? "teacher-database-library-preview-empty" : "teacher-dataset-preview-empty"}><TranslatedText>{tr("No INSERT rows to preview in this dataset.")}</TranslatedText></p>
      )}
    </section>
  );
}
