import { TranslatedText } from "@/shared/ui/TranslatedText";
import { useMemo, useState } from "react";
import { ChevronDown, Database, Eye, EyeOff } from "lucide-react";
import { useLanguage } from "@/shared/context/LanguageContext";
import { DatabaseDiagram } from "@/shared/components/DatabaseDiagram";
import { DataGrid } from "@/shared/ui/ui";
import { parseDatabaseSchema } from "@/shared/lib/sql/parseDatabaseSchema";
import { parseSeedData } from "@/shared/lib/sql/parseSeedData";

export function BuilderDatabaseReference({ schema = "", datasets, visibility, onVisibilityChange, datasetVisibility, onDatasetVisibilityChange, readOnly = false }: {
  schema?: string;
  datasets: { seedData: string; isHidden: boolean }[];
  visibility: { databaseStructureVisible: boolean; databaseDescriptionVisible: boolean };
  onVisibilityChange?: (value: { databaseStructureVisible: boolean; databaseDescriptionVisible: boolean }) => void;
  datasetVisibility: Record<string, boolean>;
  onDatasetVisibilityChange?: (value: Record<string, boolean>) => void;
  readOnly?: boolean;
}) {
  const { t: tr } = useLanguage("teacher");
  const [open, setOpen] = useState(false);
  const [tab, setTab] = useState<"schema" | "data">("schema");
  const tables = useMemo(() => open ? parseDatabaseSchema(schema) : [], [schema, open]);
  const samples = useMemo(() => open && tab === "data" ? datasets.map(dataset => parseSeedData(dataset.seedData, tables)) : [], [datasets, tables, open, tab]);
  return <section className="builder-database-reference">
    <button type="button" className="builder-database-heading" aria-expanded={open} aria-controls="builder-database-body" onClick={() => setOpen(current => !current)}>
      <Database size={15} aria-hidden="true" /><strong><TranslatedText>{tr("Database schema")}</TranslatedText></strong><ChevronDown size={16} className={open ? "is-expanded" : ""} aria-hidden="true" />
    </button>
    <div id="builder-database-body" hidden={!open}>
      {open && <>
      <div className="builder-database-tabs" role="tablist" aria-label={tr("Database reference")}>
        {([['schema','Table structure'],['data','Initial data']] as const).map(([value,label], index) => <button key={value} type="button" role="tab" id={`builder-db-${value}`} aria-controls="builder-db-panel" aria-selected={tab === value} tabIndex={tab === value ? 0 : -1}
          onClick={() => setTab(value)} onKeyDown={event => {
            if (!['ArrowLeft','ArrowRight','Home','End'].includes(event.key)) return;
            event.preventDefault();
            const next = event.key === 'Home' ? 'schema' : event.key === 'End' ? 'data' : index === 0 ? 'data' : 'schema';
            setTab(next); document.getElementById(`builder-db-${next}`)?.focus();
          }}><TranslatedText>{tr(label)}</TranslatedText></button>)}
      </div>
      <div className="builder-settings-visibility" role="group" aria-label={tr("Student problem visibility")}>
        {([['databaseStructureVisible','Hide Database structure','Show Database structure'],['databaseDescriptionVisible','Hide database description','Show database description']] as const).map(([field,hide,show]) => <button key={field} type="button" className="button teacher-small-button" aria-pressed={!visibility[field]} disabled={readOnly} onClick={() => !readOnly && onVisibilityChange?.({...visibility,[field]:!visibility[field]})}>
          {visibility[field] ? <Eye size={14} aria-hidden="true"/> : <EyeOff size={14} aria-hidden="true"/>}<TranslatedText>{tr(visibility[field] ? hide : show)}</TranslatedText>
        </button>)}
      </div>
      <div role="tabpanel" id="builder-db-panel" aria-labelledby={`builder-db-${tab}`}>
        {tab === 'schema' ? tables.length ? <DatabaseDiagram tables={tables} allowDownload={false}/> : <p className="muted"><TranslatedText>{tr("No database structure yet.")}</TranslatedText></p>
          : datasets.length ? datasets.map((dataset,index) => {
            const visible = datasetVisibility[String(index+1)] ?? !dataset.isHidden;
            return <section className="builder-settings-data" key={index}>
              <div className="builder-settings-dataset-heading"><h4><TranslatedText>{tr("Dataset {value0}",{value0:index+1})}</TranslatedText></h4>
                <button type="button" className="button teacher-small-button" aria-label={tr(visible ? "Hide Dataset {value0}" : "Show Dataset {value0}",{value0:index+1})} aria-pressed={!visible} disabled={readOnly} onClick={() => !readOnly && onDatasetVisibilityChange?.({...datasetVisibility,[String(index+1)]:!visible})}>
                  {visible ? <Eye size={14} aria-hidden="true"/> : <EyeOff size={14} aria-hidden="true"/>}<TranslatedText>{tr(visible ? "Hide dataset" : "Show dataset")}</TranslatedText>
                </button>
              </div>
              {!visible && <p className="builder-settings-badge"><TranslatedText>{tr("Hidden from students")}</TranslatedText></p>}
              {samples[index].map((table,number) => <div key={number}><h5>{table.name}</h5><DataGrid table={table}/></div>)}
              {!samples[index].length && <p className="muted"><TranslatedText>{tr("No INSERT rows to preview in this dataset.")}</TranslatedText></p>}
            </section>;
          }) : <p className="muted"><TranslatedText>{tr("No datasets yet.")}</TranslatedText></p>}
      </div>
      </>}
    </div>
  </section>;
}
