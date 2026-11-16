import { type TeacherDatabaseLibraryDataset, type TeacherDatabaseLibraryEntry, type TeacherDatabaseLibraryEntryInput } from "@/shared/api/teacherService";
import { SqlFileNameInput } from "@/features/teacher/problem-editor/SqlFileNameInput";

export const blankEntry: TeacherDatabaseLibraryEntryInput = {
  title: "",
  createTableFileName: "database_createtable.sql",
  createTableSql: "",
  datasets: [{ fileName: "database_insertdata.sql", sql: "" }],
};

export function scriptFileNames(title: string) {
  const base = title.toLowerCase().replace(/đ/g, "d").normalize("NFKD").replace(/[\u0300-\u036f]/g, "").replace(/[^a-z0-9]/g, "") || "database";
  return {
    createTableFileName: `${base}_createtable.sql`,
  };
}

export function datasetFileNameForTitle(title: string, index: number) {
  const base = title.toLowerCase().replace(/đ/g, "d").normalize("NFKD").replace(/[\u0300-\u036f]/g, "").replace(/[^a-z0-9]/g, "") || "database";
  return index === 0 ? `${base}_insertdata.sql` : `${base}_dataset_${index + 1}.sql`;
}

export function fileNameFromEntry(entry: TeacherDatabaseLibraryEntry) {
  const defaults = scriptFileNames(entry.title);
  return {
    createTableFileName: entry.createTableFileName || defaults.createTableFileName,
  };
}

export function datasetsFromEntry(entry: TeacherDatabaseLibraryEntry): TeacherDatabaseLibraryDataset[] {
  if (Array.isArray(entry.datasets) && entry.datasets.length) {
    return entry.datasets.map((dataset, index) => ({
      fileName: dataset.fileName || (index === 0 ? entry.insertIntoFileName : undefined) || datasetFileNameForTitle(entry.title, index),
      sql: dataset.sql || "",
      isHidden: dataset.isHidden || false,
    }));
  }
  return [{
    fileName: entry.insertIntoFileName || datasetFileNameForTitle(entry.title, 0),
    sql: entry.insertIntoSql || "",
  }];
}

export function SqlFileNameField({ label, value, onChange, compact = false }: { label: string; value: string; onChange: (value: string) => void; compact?: boolean }) {
  return (
    <label className={`teacher-database-library-file-field${compact ? " is-compact" : ""}`}>
      <span>{label}</span>
      <SqlFileNameInput label={label} value={value} onChange={onChange} />
    </label>
  );
}
