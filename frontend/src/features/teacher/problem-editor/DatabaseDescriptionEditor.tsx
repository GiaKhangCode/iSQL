import { TranslatedText } from "@/shared/ui/TranslatedText";
import { useState } from "react";
import { ChevronDown, Plus, Trash2 } from "lucide-react";
import type { DatabaseRelationDescription } from "@/data/models";
import { useLanguage } from "@/shared/context/LanguageContext";
import "@/styles/database-description.css";

export function DatabaseDescriptionEditor({ rows, onChange }: {
  rows: DatabaseRelationDescription[];
  onChange: (rows: DatabaseRelationDescription[]) => void;
}) {
  const { t: tr } = useLanguage("teacher");
  const [expanded, setExpanded] = useState(true);
  const displayedRows = rows.length ? rows : [{ tableName: "", attributes: "", predicate: "" }];
  const updateRow = (index: number, field: keyof DatabaseRelationDescription, value: string) =>
    onChange(displayedRows.map((row, i) => i === index ? { ...row, [field]: value } : row));
  return <section className="database-description-editor">
    <header className="database-description-heading">
      <button type="button" className="database-description-collapse" aria-expanded={expanded} aria-controls="database-description-fields" onClick={() => setExpanded(!expanded)}>
        <ChevronDown size={18} className={expanded ? "is-expanded" : ""} aria-hidden="true" />
        <strong><TranslatedText>{tr("Database description")}</TranslatedText></strong>
      </button>
    </header>
    <div id="database-description-fields" className={`database-description-fold${expanded ? " is-expanded" : ""}`} ref={element => { if (element) element.inert = !expanded; }}>
      <div className="database-description-fold-inner"><div className="database-description-fields">
        {displayedRows.map((row, index) => <section className="database-description-row teacher-relation-card" key={index} aria-labelledby={`database-relation-${index}`}>
          <header className="teacher-relation-header">
            <h4 id={`database-relation-${index}`}><TranslatedText>{tr("Table")}</TranslatedText> {index + 1}</h4>
            <div className="database-description-row-actions"><button type="button" className="icon-button" aria-label={`${tr("Remove table")} ${index + 1}`} onClick={() => onChange(displayedRows.filter((_, i) => i !== index))}><Trash2 size={15} /></button></div>
          </header>
          <div className="database-description-inputs">
            <label><TranslatedText>{tr("Table name")}</TranslatedText><input value={row.tableName} maxLength={255} placeholder={tr("e.g. CUSTOMERS")} onChange={event => updateRow(index, "tableName", event.target.value)} /></label>
            <label><TranslatedText>{tr("Attributes")}</TranslatedText><textarea rows={1} value={row.attributes} maxLength={10000} placeholder={tr("e.g. CUSTOMER_ID, FULL_NAME")} onChange={event => updateRow(index, "attributes", event.target.value)} /></label>
          </div>
          <label><TranslatedText>{tr("Predicate")}</TranslatedText><textarea rows={2} value={row.predicate} maxLength={20000} placeholder={tr("Describe the meaning of this table and its attributes.")} onChange={event => updateRow(index, "predicate", event.target.value)} /></label>
        </section>)}
        <button type="button" className="button teacher-small-button" disabled={displayedRows.length >= 100} onClick={() => onChange([...displayedRows, { tableName: "", attributes: "", predicate: "" }])}><Plus size={15} aria-hidden="true" /><TranslatedText>{tr("Add table description")}</TranslatedText></button>
      </div></div>
    </div>
  </section>;
}
