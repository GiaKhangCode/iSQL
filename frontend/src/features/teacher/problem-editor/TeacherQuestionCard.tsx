import { useState, type ReactNode } from "react";
import { ChevronDown } from "lucide-react";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import { ProblemMarkdown } from "@/shared/components/ProblemMarkdown";
import { useLanguage } from "@/shared/context/LanguageContext";

export function TeacherQuestionCard({
  id,
  number,
  fileName,
  tagControls,
  description,
  descriptionText,
  actions,
  children
}: {
  id: string;
  number: number;
  fileName: ReactNode;
  tagControls?: ReactNode;
  description: ReactNode;
  descriptionText: string;
  actions?: ReactNode;
  children: ReactNode;
}) {
  const { t: tr } = useLanguage("teacher");
  const [expanded, setExpanded] = useState(true);
  const bodyId = `${id}-body`;
  const toggleLabel = tr(expanded ? "Hide SQL for question {value0}" : "Show SQL for question {value0}", { value0: number });

  return (
    <section className={`database-description-row teacher-exercise-question${expanded ? "" : " is-collapsed"}`} aria-labelledby={id}>
      <header className="teacher-question-header">
        <h3 id={id}>
          <button
            type="button"
            className="teacher-question-toggle"
            aria-label={toggleLabel}
            title={toggleLabel}
            aria-expanded={expanded}
            aria-controls={bodyId}
            onClick={() => setExpanded(current => !current)}
          >
            <ChevronDown size={15} aria-hidden="true" />
            <span><TranslatedText>{tr("Question")}</TranslatedText> {number}</span>
          </button>
        </h3>
        {fileName}
        {tagControls && <div className="teacher-question-tag-controls">{tagControls}</div>}
        <div className="database-description-row-actions">{actions}</div>
      </header>
      <div
        className="teacher-question-description-editor"
        aria-hidden={!expanded}
        ref={element => { if (element) element.inert = !expanded; }}
      >
        <div>{description}</div>
      </div>
      {descriptionText.trim() && (
        <div className="teacher-question-description-preview" hidden={expanded}>
          <ProblemMarkdown>{descriptionText}</ProblemMarkdown>
        </div>
      )}
      <div id={bodyId} className="teacher-question-body" hidden={!expanded}>{children}</div>
    </section>
  );
}
