import type { ReactNode } from "react";
import { ChevronDown } from "lucide-react";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import { useLanguage } from "@/shared/context/LanguageContext";

export function TeacherRunResult({ dataset, summary, error, toggleLabel, className = "", children }: {
  dataset: number;
  summary?: ReactNode;
  error?: string;
  toggleLabel?: string;
  className?: string;
  children?: ReactNode;
}) {
  const { t: tr } = useLanguage("teacher");
  const status = <span className="teacher-stage-run-summary" role={error ? "alert" : "status"}>
    <strong><TranslatedText>{tr("Dataset")}</TranslatedText> {dataset}</strong>{" · "}
    {error || summary || <TranslatedText>{tr("Run complete")}</TranslatedText>}
  </span>;
  const classes = `teacher-stage-run-result${error ? " is-error" : ""}${className ? ` ${className}` : ""}`;
  return children && !error ? <details className={`${classes} teacher-stage-changed-tables`}>
    <summary className="teacher-stage-result-header">
      {status}
      <span className="teacher-stage-result-toggle"><ChevronDown size={14} aria-hidden="true" />
        <TranslatedText>{toggleLabel}</TranslatedText>
      </span>
    </summary>
    <div className="teacher-stage-result-content">{children}</div>
  </details> : <div className={classes}>{status}</div>;
}
