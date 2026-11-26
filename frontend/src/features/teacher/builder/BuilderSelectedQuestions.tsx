import { SlidingDisclosure } from "@/shared/ui/SlidingDisclosure";
import {useEffect, useId, useState} from "react";
import {ListChecks, ChevronDown} from "lucide-react";
import type {WorkspacePart} from "@/data/models";
import {useLanguage} from "@/shared/context/LanguageContext";
import {TranslatedText} from "@/shared/ui/TranslatedText";
import {ProblemMarkdown} from "@/shared/components/ProblemMarkdown";
import {WORKSPACE_PARTS, WORKSPACE_PART_LABELS} from "@/shared/lib/workspace/workspaceParts";
import {catalogOf, type BuilderProblemDetail, type QuestionSelection} from "@/features/teacher/builder/BuilderProblemSettings";

export function selectedQuestions(detail: BuilderProblemDetail | null, parts: WorkspacePart[], selection?: QuestionSelection) {
  const catalog = catalogOf(detail);
  return Object.fromEntries(WORKSPACE_PARTS.map(part => {
    const custom = catalog[part].filter(question => !question.kind);
    const rawVal = selection?.[part];
    let ids: string[];
    if (Array.isArray(rawVal)) {
      ids = rawVal;
    } else if (rawVal && typeof rawVal === "object" && Array.isArray((rawVal as any).questionIds)) {
      ids = (rawVal as any).questionIds;
    } else if (rawVal && typeof rawVal === "object" && Array.isArray((rawVal as any).ids)) {
      ids = (rawVal as any).ids;
    } else {
      ids = (custom.length ? custom : catalog[part]).map(question => question.id);
    }
    return [part, parts.includes(part) ? catalog[part].filter(question => Array.isArray(ids) && ids.includes(question.id)) : []];
  })) as ReturnType<typeof catalogOf>;
}

export function BuilderSelectedQuestions({detail, parts, selection, total, error, onRetry, open, onToggle}: {
  detail: BuilderProblemDetail | null; parts: WorkspacePart[]; selection?: QuestionSelection;
  total: number; error?: string; onRetry: () => void; open: boolean; onToggle: () => void;
}) {
  const {t, locale} = useLanguage("teacher");
  const contentId = useId();
  const [part, setPart] = useState<WorkspacePart>("DDL");
  const selected = selectedQuestions(detail, parts, selection);
  const available = WORKSPACE_PARTS.filter(value => selected[value].length);
  const count = available.reduce((sum, value) => sum + selected[value].length, 0);
  useEffect(() => {if (!available.includes(part) && available.length) setPart(available[0]);}, [available.join("|"), part]);
  const score = new Intl.NumberFormat(locale, {maximumFractionDigits: 4}).format(total ? 10 / total : 0);
  return <div className="builder-selected-questions">
    <button type="button" className="button teacher-small-button builder-selected-toggle" aria-expanded={open} aria-controls={contentId} onClick={onToggle}>
      <ListChecks size={15}/><TranslatedText>{t("Selected questions")}</TranslatedText>{detail && <span className="builder-selected-count">{count}</span>}<ChevronDown size={14} className={open ? "is-expanded" : ""}/>
    </button>
    <div className="builder-selected-disclosure"><SlidingDisclosure open={open}><section id={contentId} className="builder-selected-content" aria-label={t("Selected questions")}>
      {error ? <div role="alert"><p>{error}</p><button type="button" className="button" onClick={onRetry}><TranslatedText>{t("Retry")}</TranslatedText></button></div> : !detail ? <p className="muted"><TranslatedText>{t("Loading problem details…")}</TranslatedText></p> : <>
        <div className="builder-selected-tabs" role="tablist" aria-label={t("Question type")}>
          {WORKSPACE_PARTS.map(value => <button type="button" role="tab" key={value} aria-selected={part === value} disabled={!selected[value].length} onClick={() => setPart(value)}>{WORKSPACE_PART_LABELS[value]}<span>{selected[value].length}</span></button>)}
        </div>
        {count > 0 && <div className="builder-selected-score"><TranslatedText>{t("{value0} points per question", {value0: score})}</TranslatedText></div>}
        {count ? <div role="tabpanel" className="builder-selected-list">
          {selected[part].map((question, index) => <article key={question.id}>
            <header aria-label={t("Question {value0}", {value0: index + 1})}><span>{String(index + 1).padStart(2, "0")}</span></header>
            <ProblemMarkdown>{t(question.description || (question.kind === "schema" ? "Create tables and keys" : question.kind === "seed" ? "Import original data" : "Question"))}</ProblemMarkdown>
          </article>)}
        </div> : <p className="muted"><TranslatedText>{t("No questions selected.")}</TranslatedText></p>}
      </>}
    </section></SlidingDisclosure></div>
  </div>;
}
