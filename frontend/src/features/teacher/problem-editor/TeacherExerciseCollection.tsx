import { TranslatedText } from "@/shared/ui/TranslatedText";
import { useState, type ReactNode } from "react";
import { ChevronDown, Plus } from "lucide-react";
import { useLanguage } from "@/shared/context/LanguageContext";
import "@/styles/database-description.css";

export function TeacherExerciseCollection({ id, title, addLabel, count, limit = 50, disabled = false, onAdd, children }: {
  id: string;
  title: string;
  addLabel: string;
  count: number;
  limit?: number;
  disabled?: boolean;
  onAdd: () => void;
  children: ReactNode;
}) {
  const { t: tr } = useLanguage("teacher");
  const [expanded, setExpanded] = useState(true);
  const fieldsId = `${id}-questions`;
  return <section className="database-description-editor teacher-exercise-collection">
    <header className="database-description-heading">
      <button type="button" className="database-description-collapse" aria-expanded={expanded} aria-controls={fieldsId} onClick={() => setExpanded(value => !value)}>
        <ChevronDown size={18} className={expanded ? "is-expanded" : ""} aria-hidden="true" />
        <strong>{title}</strong>
        <span className="teacher-exercise-count">{count}</span>
      </button>
    </header>
    <div id={fieldsId} className={`database-description-fold${expanded ? " is-expanded" : ""}`} ref={element => { if (element) element.inert = !expanded; }}>
      <div className="database-description-fold-inner">
        <div className="database-description-fields">
          {children}
          <button type="button" className="button teacher-small-button" disabled={disabled || count >= limit} onClick={onAdd}>
            <Plus size={15} aria-hidden="true" /><TranslatedText>{tr(addLabel)}</TranslatedText>
          </button>
        </div>
      </div>
    </div>
  </section>;
}
