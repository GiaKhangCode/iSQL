import { useState } from "react";
import { Plus, Table2, Trash2 } from "lucide-react";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import { useLanguage } from "@/shared/context/LanguageContext";
import { DatasetDataPreview } from "@/shared/components/DatasetDataPreview";
import { ProblemSqlEditor } from "@/features/teacher/problem-editor/ProblemSqlEditor";
import { SqlFileNameInput } from "@/features/teacher/problem-editor/SqlFileNameInput";
import type { ContentEditorVariant } from "@/features/teacher/content-editor/SchemaPanel";

export type DatasetDraft = { fileName: string; sql: string; isHidden?: boolean };

/**
 * Initial-dataset editor shared by the problem editor and the database library.
 * Pages keep their own storage model (testCases + localStorage names vs. library datasets)
 * and react to `onEdit` / `onAdd` / `onRemove`; the panel owns the UI and the preview toggle.
 * Remount it (via `key`) when the edited entry changes so the open preview resets.
 */
export function DatasetsPanel({ variant, datasets, onEdit, onAdd, onRemove, showHiddenToggle = false }: {
  variant: ContentEditorVariant;
  datasets: DatasetDraft[];
  onEdit: (index: number, patch: Partial<DatasetDraft>) => void;
  onAdd: () => void;
  onRemove: (index: number) => void;
  /** Dataset visibility is an activity setting for problems; only the library edits it directly. */
  showHiddenToggle?: boolean;
}) {
  const { t: tr } = useLanguage("teacher");
  const [previewIndex, setPreviewIndex] = useState<number | null>(null);
  const isLibrary = variant === "library";

  function togglePreview(index: number) {
    setPreviewIndex(current => current === index ? null : index);
  }

  function remove(index: number) {
    if (datasets[index]?.sql.trim() && !window.confirm(tr("Dataset {value0} contains SQL data. Delete it?", { value0: index + 1 }))) return;
    setPreviewIndex(current => current === index ? null : current !== null && current > index ? current - 1 : current);
    onRemove(index);
  }

  if (isLibrary) return <div className="teacher-database-library-datasets">
    {datasets.map((dataset, index) => (
      <div className="teacher-database-library-dataset" key={index}>
        <div className="teacher-database-library-dataset-heading">
          <div className="teacher-database-library-dataset-identity">
            <strong><TranslatedText>{tr("Dataset {value0}", { value0: index + 1 })}</TranslatedText></strong>
          </div>
          <div className="teacher-database-library-dataset-actions">
            <button className={`button teacher-database-library-preview-toggle${previewIndex === index ? " is-active" : ""}`} type="button" aria-expanded={previewIndex === index} onClick={() => togglePreview(index)}>
              <Table2 size={13} aria-hidden="true" />
              <TranslatedText>{tr(previewIndex === index ? "Hide preview" : "Preview data")}</TranslatedText>
            </button>
            {index === 0 && <button className="button teacher-database-library-add-dataset" type="button" onClick={onAdd}><Plus size={13} aria-hidden="true" /><TranslatedText>{tr("Add dataset")}</TranslatedText></button>}
            {index > 0 && <button className="button teacher-database-library-remove-dataset" type="button" aria-label={tr("Delete dataset {value0}", { value0: index + 1 })} onClick={() => remove(index)}><Trash2 size={13} aria-hidden="true" /><TranslatedText>{tr("Delete")}</TranslatedText></button>}
          </div>
        </div>
        <ProblemSqlEditor className="teacher-database-library-editor" label={tr("Dataset {value0} SQL", { value0: index + 1 })}
          value={dataset.sql} placeholder={tr("Write INSERT INTO statements…")} onChange={value => onEdit(index, { sql: value })} />
        {showHiddenToggle && <label className="library-hidden-dataset"><input type="checkbox" checked={dataset.isHidden || false} onChange={event => onEdit(index, { isHidden: event.target.checked })} /><TranslatedText>{tr("Hidden dataset")}</TranslatedText></label>}
        {previewIndex === index && <DatasetDataPreview datasetNumber={index + 1} seedData={dataset.sql} variant="database-library" />}
      </div>
    ))}
  </div>;

  return <>
    <div className="teacher-dataset-list">
      {datasets.map((dataset, index) => (
        <fieldset className="teacher-dataset-card" key={index}>
          <legend>
            <span className="teacher-dataset-number"><TranslatedText>{tr("Dataset")}</TranslatedText> {index + 1}</span>
            <SqlFileNameInput label={tr("Dataset {value0} file name", { value0: index + 1 })} value={dataset.fileName}
              onChange={name => onEdit(index, { fileName: name })} />
          </legend>
          {datasets.length > 1 && <button type="button" className="icon-button teacher-dataset-delete"
            aria-label={`${tr("Delete")}: ${tr("Dataset")} ${index + 1}`} title={tr("Delete")}
            onClick={() => remove(index)}><Trash2 size={15} aria-hidden="true" /></button>}
          <div className="teacher-dataset-sql-label"><TranslatedText>{tr("Initial data SQL")}</TranslatedText></div>
          <ProblemSqlEditor value={dataset.sql} label={tr("Test dataset {value0} SQL", { value0: index + 1 })}
            placeholder={tr("-- Example SQL Server test data\nINSERT INTO Customers (CustomerId, FullName)\nVALUES (1, N'Nguyen Van An'),\n       (2, N'Tran Thi Binh');")}
            onChange={value => onEdit(index, { sql: value })} />
          <div className="teacher-dataset-card-actions">
            <button type="button"
              className={`button teacher-small-button teacher-dataset-preview-toggle${previewIndex === index ? " is-active" : ""}`}
              aria-expanded={previewIndex === index} onClick={() => togglePreview(index)}>
              <Table2 size={14} aria-hidden="true" />
              <TranslatedText>{tr(previewIndex === index ? "Hide preview" : "Preview data")}</TranslatedText>
            </button>
          </div>
          {previewIndex === index && <DatasetDataPreview datasetNumber={index + 1} seedData={dataset.sql} variant="problem-editor" />}
        </fieldset>
      ))}
    </div>
    <button className="button teacher-small-button teacher-dataset-add" type="button" onClick={onAdd}>
      <Plus size={14} aria-hidden="true" /><TranslatedText>{tr("Add dataset")}</TranslatedText>
    </button>
    <p className="teacher-seed-summary"><TranslatedText>{tr("Each dataset contains seed SQL loaded before checking a solution. Hidden datasets are not shown to students.")}</TranslatedText></p>
  </>;
}
