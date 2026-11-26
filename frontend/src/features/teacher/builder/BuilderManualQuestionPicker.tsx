import { useMemo, useState } from "react";
import { ChevronDown } from "lucide-react";
import type { WorkspacePart } from "@/data/models";
import { useLanguage } from "@/shared/context/LanguageContext";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import { Select } from "@/shared/ui/Select";
import { ProblemMarkdown } from "@/shared/components/ProblemMarkdown";
import { WORKSPACE_PARTS, WORKSPACE_PART_LABELS } from "@/shared/lib/workspace/workspaceParts";
import { questionLabel } from "@/shared/lib/workspace/questionLabel";
import { catalogOf, type BuilderProblemDetail, type QuestionOption, type QuestionSelection } from "@/features/teacher/builder/BuilderProblemSettings";
import "@/features/teacher/builder/BuilderManualQuestionPicker.css";
import { SCORING_CUSTOM, SCORING_EQUAL, plainSelection as plainSelectionOf, type ScoringMode } from "@/features/teacher/builder/questionScoring";
import { QuestionPointInput } from "@/features/teacher/builder/BuilderScoring";

const DIFFICULTY_ALIASES: Record<string, string> = { easy: "dễ", medium: "trung bình", hard: "khó" };
const difficultyKey = (value?: string) => {
  const key = (value || "").trim().toLowerCase();
  return DIFFICULTY_ALIASES[key] || key;
};

/** A selection of explicit question ids only (see plainSelection in questionScoring: points are kept). */
const plainSelection = (selection: QuestionSelection | undefined) => plainSelectionOf(selection) as QuestionSelection;

export function BuilderManualQuestionPicker({ detail, parts, questionSelection, onChange, lockedQuestionSelection, lockedParts, scoringMode = SCORING_EQUAL }: {
  detail: BuilderProblemDetail;
  parts: WorkspacePart[];
  questionSelection?: QuestionSelection;
  onChange: (patch: { questionSelection: QuestionSelection; workspaceParts: WorkspacePart[] }) => void;
  lockedQuestionSelection?: QuestionSelection;
  lockedParts?: WorkspacePart[];
  scoringMode?: ScoringMode;
}) {
  const { t: tr } = useLanguage("teacher");
  const catalog = useMemo(() => catalogOf(detail), [detail]);
  const [activeTab, setActiveTab] = useState<WorkspacePart>(() => WORKSPACE_PARTS.find(part => catalog[part].length > 0) || "DDL");
  const [category, setCategory] = useState("ALL");
  const [difficulty, setDifficulty] = useState("ALL");

  const selectedIds = (part: WorkspacePart): string[] => {
    if (!parts.includes(part)) return [];
    const stored = questionSelection?.[part];
    if (stored) return stored;
    const custom = catalog[part].filter(question => !question.kind);
    return (custom.length ? custom : catalog[part]).map(question => question.id);
  };

  const lockedIdsFor = (part: WorkspacePart): Set<string> => {
    if (!lockedParts || !lockedParts.includes(part)) return new Set();
    const stored = lockedQuestionSelection?.[part];
    if (Array.isArray(stored)) return new Set(stored);
    if (stored && typeof stored === "object" && Array.isArray((stored as any).questionIds)) {
      return new Set((stored as any).questionIds);
    }
    const custom = catalog[part].filter(question => !question.kind);
    return new Set((custom.length ? custom : catalog[part]).map(question => question.id));
  };

  const choices = catalog[activeTab];
  const selected = new Set(selectedIds(activeTab));
  const lockedSet = lockedIdsFor(activeTab);
  const categories = useMemo(() => Array.from(new Set(choices.map(question => question.category || "").filter(Boolean))), [choices]);
  const difficulties = useMemo(() => {
    const seen = new Map<string, string>();
    choices.forEach(question => { if (question.difficulty && !seen.has(difficultyKey(question.difficulty))) seen.set(difficultyKey(question.difficulty), question.difficulty); });
    return Array.from(seen.values());
  }, [choices]);

  const visible = choices.filter(question =>
    (category === "ALL" || question.category === category) && (difficulty === "ALL" || difficultyKey(question.difficulty) === difficultyKey(difficulty)));
  const groups = useMemo(() => {
    const map = new Map<string, QuestionOption[]>();
    visible.forEach(question => map.set(question.category || "", [...(map.get(question.category || "") || []), question]));
    return Array.from(map.entries());
  }, [visible]);

  const [collapsed, setCollapsed] = useState<Set<string>>(new Set());

  function commit(part: WorkspacePart, ids: Set<string>) {
    const merged = new Set([...ids, ...lockedIdsFor(part)]);
    const ordered = catalog[part].filter(question => merged.has(question.id)).map(question => question.id);
    onChange({
      questionSelection: { ...plainSelection(questionSelection), [part]: ordered },
      workspaceParts: WORKSPACE_PARTS.filter(value => value === part ? ordered.length > 0 : parts.includes(value)),
    });
  }
  const setMany = (questions: QuestionOption[], checked: boolean) => {
    const next = new Set(selected);
    questions.forEach(question => {
      if (checked) {
        next.add(question.id);
      } else if (!lockedSet.has(question.id)) {
        next.delete(question.id);
      }
    });
    commit(activeTab, next);
  };

  const totalSelected = WORKSPACE_PARTS.reduce((sum, part) => sum + catalog[part].filter(question => selectedIds(part).includes(question.id)).length, 0);

  return <div className="builder-manual-picker">
    <div className="builder-selected-tabs builder-settings-inline-tabs" role="tablist" aria-label={tr("Problem settings tabs")}>
      {WORKSPACE_PARTS.map(part => {
        const count = catalog[part].filter(question => selectedIds(part).includes(question.id)).length;
        return <button type="button" role="tab" key={part} aria-selected={activeTab === part} onClick={() => { setActiveTab(part); setCategory("ALL"); setDifficulty("ALL"); }}>
          <TranslatedText>{WORKSPACE_PART_LABELS[part]}</TranslatedText><span>{count}/{catalog[part].length}</span>
        </button>;
      })}
    </div>
    {totalSelected === 0 && <p className="teacher-state-failed" role="alert"><TranslatedText>{tr("Select at least one section.")}</TranslatedText></p>}
    {choices.length === 0 ? <p className="muted"><TranslatedText>{tr("No questions are available in this section.")}</TranslatedText></p> : <>
      <div className="builder-manual-picker-toolbar">
        <Select value={category} aria-label={tr("Category")} onChange={event => setCategory(event.target.value)}>
          <option value="ALL">{tr("All categories")}</option>
          {categories.map(name => <option key={name} value={name}>{name}</option>)}
        </Select>
        <Select value={difficulty} aria-label={tr("Difficulty")} onChange={event => setDifficulty(event.target.value)}>
          <option value="ALL">{tr("All difficulties")}</option>
          {difficulties.map(name => <option key={name} value={name}>{name}</option>)}
        </Select>
      </div>
      {groups.length === 0 && <p className="muted"><TranslatedText>{tr("No questions match the filters.")}</TranslatedText></p>}
      {groups.map(([name, questions]) => {
        const picked = questions.filter(question => selected.has(question.id)).length;
        const key = `${activeTab}|${name}`;
        const folded = collapsed.has(key);
        const listId = `builder-manual-group-${activeTab}-${groups.findIndex(([groupName]) => groupName === name)}`;
        return <section className="builder-manual-picker-group" key={name || "none"}>
          <div className="builder-manual-picker-group-heading">
            <input type="checkbox" checked={picked === questions.length} disabled={questions.every(q => lockedSet.has(q.id))} ref={node => { if (node) node.indeterminate = picked > 0 && picked < questions.length; }}
              aria-label={tr("Select all questions")} onChange={event => setMany(questions, event.target.checked)} />
            <button type="button" className="builder-manual-picker-group-toggle" aria-expanded={!folded} aria-controls={listId}
              onClick={() => setCollapsed(previous => { const next = new Set(previous); if (folded) next.delete(key); else next.add(key); return next; })}>
              <strong><TranslatedText>{name || tr("Other")}</TranslatedText></strong>
              <span>{picked}/{questions.length}</span>
              <ChevronDown size={16} className={folded ? "" : "is-expanded"} aria-hidden="true" />
            </button>
          </div>
          {!folded && <ul id={listId}>
            {questions.map((question, questionIndex) => <li key={question.id} className="builder-manual-picker-question-row">
              <label className="builder-manual-picker-question">
                <input type="checkbox" checked={selected.has(question.id)} disabled={lockedSet.has(question.id)} title={lockedSet.has(question.id) ? tr("Published assignment questions cannot be removed") : undefined} aria-label={tr("Select question {value0}", { value0: questionLabel(questionIndex, question.description, tr("Question")) })} onChange={event => setMany([question], event.target.checked)} />
                <span>
                  <span className="builder-manual-picker-question-title">
                    <TranslatedText>{question.kind === "schema" ? tr("Create tables and keys") : question.kind === "seed" ? tr("Import original data") : tr("Question {value0}", { value0: questionIndex + 1 })}</TranslatedText>
                    {question.difficulty && <span className="builder-q-tag difficulty">{question.difficulty}</span>}
                    {question.kind && <small><TranslatedText>{tr("From original database")}</TranslatedText></small>}
                  </span>
                  {question.description && <ProblemMarkdown>{tr(question.description)}</ProblemMarkdown>}
                </span>
              </label>
              {scoringMode === SCORING_CUSTOM && selected.has(question.id) && <QuestionPointInput
                selection={questionSelection}
                part={activeTab}
                questionId={question.id}
                questionName={questionLabel(questionIndex, question.description, tr("Question"))}
                onChange={selection => onChange({ questionSelection: { ...selection, [activeTab]: selectedIds(activeTab) }, workspaceParts: parts })}
              />}
            </li>)}
          </ul>}
        </section>;
      })}
    </>}
  </div>;
}
