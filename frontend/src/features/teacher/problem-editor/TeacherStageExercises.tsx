import { useEffect, useState } from "react";
import { ChevronDown, Play, Plus, Trash2, X } from "lucide-react";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import { useLanguage } from "@/shared/context/LanguageContext";
import type { TeacherStageExercise, TeacherStageQuestion } from "@/data/teacherTypes";
import { teacherService } from "@/shared/api/teacherService";
import { ProblemSqlEditor } from "@/features/teacher/problem-editor/ProblemSqlEditor";
import { TeacherExerciseCollection } from "@/features/teacher/problem-editor/TeacherExerciseCollection";
import { TeacherQuestionCard } from "@/features/teacher/problem-editor/TeacherQuestionCard";
import { SqlFileNameInput } from "@/features/teacher/problem-editor/SqlFileNameInput";
import { TeacherSchemaComparison, type SchemaChange } from "@/features/teacher/problem-editor/TeacherSchemaComparison";
import { TeacherDataComparison, type DataChange } from "@/features/teacher/problem-editor/TeacherDataComparison";
import { TeacherRunResult } from "@/features/teacher/problem-editor/TeacherRunResult";
import { QuestionTagSelectors } from "@/features/teacher/problem-editor/QuestionTagSelectors";

type Part = "DDL" | "DML" | "TRIGGER";
type Run = { source: string; running: boolean; results: { dataset: number; summary?: string; error?: string; changes?: SchemaChange[]; triggers?: {name:string;table:string;isDisabled:boolean}[]; dataChanges?: DataChange[]; scenarios?: {scenario:number;ok:boolean;message?:string;changedTables:string[]}[] }[] };

export function initialStageExercise(part: Part, exercise?: TeacherStageExercise | null): TeacherStageExercise {
  if (exercise?.questions?.length) return { questions: exercise.questions.map(question => ({ ...question, enabled: question.enabled !== false })) };
  return { questions: [newStageQuestion(part, 1)] };
}

function newStageQuestion(part: Part, number: number): TeacherStageQuestion {
  return { id: crypto.randomUUID(), name: `${part}${number}.sql`, description: "", answer: "", enabled: true,
    startFrom: number === 1 ? part === "DDL" ? "empty" : part === "TRIGGER" ? "provided" : "structure" : "provided", setupDdl: "", setupDml: "",
    ...(part === "TRIGGER" ? { probes: [] } : {}) };
}

function questionDatasets(question: TeacherStageQuestion, datasets: { seedData: string; isHidden?: boolean }[]) {
  return question.startFrom === "empty" || question.startFrom === "structure" || !datasets.length ? [{ seedData: "" }] : datasets;
}

export function TeacherStageExercises({ part, exercise, schema, datasets, onChange, onRunningChange, readOnly = false }: {
  part: Part;
  exercise: TeacherStageExercise;
  schema: string;
  datasets: { seedData: string; isHidden?: boolean }[];
  onChange: (exercise: TeacherStageExercise) => void;
  onRunningChange: (running: boolean) => void;
  readOnly?: boolean;
}) {
  const { t: tr } = useLanguage("teacher");
  const [runs, setRuns] = useState<Record<string, Run>>({});
  const [expandedSetups, setExpandedSetups] = useState<Record<string, boolean>>({});
  useEffect(() => () => onRunningChange(false), [onRunningChange]);
  const label = part === "DDL" ? tr("DDL exercise") : part === "TRIGGER" ? tr("Trigger exercises") : tr("DML exercise");
  function change(id: string, patch: Partial<TeacherStageQuestion>) {
    if (readOnly) return;
    onChange({ questions: exercise.questions.map(question => question.id === id ? { ...question, ...patch } : question) });
  }
  async function runQuestion(question: TeacherStageQuestion) {
    if (Object.values(runs).some(run => run.running) || !question.answer.trim()) return;
    const targets = questionDatasets(question, datasets);
    const source = JSON.stringify([part, schema, question, targets]);
    const results: Run["results"] = [];
    onRunningChange(true);
    setRuns(current => ({ ...current, [question.id]: { source, running: true, results: [] } }));
    try {
      for (const [index, dataset] of targets.entries()) {
        try {
          const result = await teacherService.validateStage({ part, schema, seedData: dataset.seedData, question });
          const changes: SchemaChange[] | undefined = part === "DDL" ? result.schemaChanges : undefined;
          const dataChanges: DataChange[] | undefined = part === "DML" ? result.dataChanges : undefined;
          const affected = changes || dataChanges;
          const summary = affected?.length
            ? affected.map(change => tr(change.change === "created" ? "Created: {value0}" : change.change === "modified" ? "Modified: {value0}" : "Dropped: {value0}", { value0: change.name })).join(" · ")
            : tr(affected ? part === "DDL" ? "No table structure changes." : "No table data changes." : "Run complete");
          results.push({ dataset: index + 1, summary: part === "TRIGGER" ? tr("Trigger created successfully.") : summary, changes, dataChanges, triggers:result.triggers, scenarios: result.scenarios });
        } catch (error) {
          results.push({ dataset: index + 1, error: error instanceof Error ? error.message : tr("Run failed") });
        }
        setRuns(current => ({ ...current, [question.id]: { source, running: true, results: [...results] } }));
      }
    } finally {
      setRuns(current => ({ ...current, [question.id]: { source, running: false, results: [...results] } }));
      onRunningChange(false);
    }
  }

  return <TeacherExerciseCollection id={`teacher-${part.toLowerCase()}-exercise`} title={label} addLabel="Add question" count={exercise.questions.length} disabled={readOnly}
    onAdd={() => { if (!readOnly) onChange({ questions: [...exercise.questions, newStageQuestion(part, exercise.questions.length + 1)] }); }}>
      {exercise.questions.map((question, index) => {
        const run = runs[question.id];
        const currentSource = JSON.stringify([part, schema, question, questionDatasets(question, datasets)]);
        const stale = Boolean(run && !run.running && run.source !== currentSource);
        const setup = part === "TRIGGER" ? [question.setupDdl, question.setupDml].filter(Boolean).join("\n")
          : part === "DDL" ? question.setupDdl || "" : question.setupDml || "";
        return <TeacherQuestionCard key={question.id} id={`teacher-${part}-question-${question.id}`} number={index + 1}
          descriptionText={question.description}
          description={<label className="database-description-question-body"><TranslatedText>{tr("Question description")}</TranslatedText>
            <textarea aria-label={tr("Question description")} rows={3} maxLength={20000} value={question.description} placeholder={tr("Write the task students need to complete.")}
              disabled={readOnly} readOnly={readOnly}
              onChange={event => change(question.id, { description: event.target.value })} />
          </label>}
          fileName={<SqlFileNameInput label={tr("Question {value0} file name", { value0: index + 1 })}
            value={question.name} disabled={readOnly || run?.running} onChange={name => change(question.id, { name })} />}
          tagControls={<QuestionTagSelectors
            part={part}
            difficulty={question.difficulty}
            category={question.category}
            disabled={readOnly || run?.running}
            onDifficultyChange={diff => change(question.id, { difficulty: diff })}
            onCategoryChange={cat => change(question.id, { category: cat })}
          />}
          actions={!readOnly && exercise.questions.length > 1 && <button type="button" className="icon-button" disabled={run?.running}
              aria-label={`${tr("Delete")}: ${tr("Question")} ${index + 1}`} title={tr("Delete question")}
              onClick={() => {
                if ((question.answer.trim() || question.description.trim() || question.setupDdl?.trim() || question.setupDml?.trim())
                  && !window.confirm(tr("Delete this question and its reference answer?"))) return;
                onChange({ questions: exercise.questions.filter(item => item.id !== question.id) });
              }}><Trash2 size={15} /></button>}>

          <details className="database-description-question-body teacher-stage-setup-field"
            open={expandedSetups[question.id] ?? Boolean(setup.trim())}
            onToggle={event => {
              const open = event.currentTarget.open;
              setExpandedSetups(current => current[question.id] === open ? current : { ...current, [question.id]: open });
            }}>
            <summary><ChevronDown size={14} aria-hidden="true" /><TranslatedText>{tr("SQL setup before this question (optional)")}</TranslatedText></summary>
            <ProblemSqlEditor
              label={tr("SQL setup before this question (optional)")}
              value={setup}
              readOnly={readOnly}
              placeholder={tr(part === "DDL"
                ? "-- Example: ALTER TABLE SANPHAM ADD GHICHU VARCHAR(20);"
                : "-- Example: SELECT * INTO SANPHAM1 FROM SANPHAM;")}
              onChange={setup => change(question.id, part === "TRIGGER" ? { setupDdl: setup, setupDml: "" }
                : part === "DDL" ? { setupDdl: setup } : { setupDml: setup })}
            />
            <span className="teacher-stage-setup-hint"><TranslatedText>{tr("Runs after loading the starting database and before this question. Each question runs independently.")}</TranslatedText></span>
          </details>
          {part === "TRIGGER" && <section className="teacher-trigger-scenarios" aria-label={tr("Hidden test scenarios")}>
            <div className="teacher-question-answer-label"><TranslatedText>{tr("Hidden test scenarios")}</TranslatedText></div>
            <p className="teacher-stage-setup-hint"><TranslatedText>{tr((question.probes || []).length
              ? "Each scenario runs after the trigger is created, on the starting database. Student and reference results are compared table by table, so code formatting and names do not matter. Include a statement that changes several rows."
              : "No scenarios: the trigger is graded by exact reference code, allowing only outer whitespace and Windows newline differences. Add scenarios to grade by behaviour.")}</TranslatedText></p>
            {(question.probes || []).map((probe, probeIndex) => <div key={probeIndex} className="teacher-trigger-scenario">
              <ProblemSqlEditor value={probe} label={tr("Scenario {value0}", { value0: probeIndex + 1 })}
                readOnly={readOnly}
                placeholder={tr("-- Example: INSERT INTO CHITIETDT (MaDT, MaThuoc, SoLuong, ThanhTien) VALUES ('DT012', 'TH003', 4, 6000);")}
                onChange={value => change(question.id, { probes: (question.probes || []).map((item, itemIndex) => itemIndex === probeIndex ? value : item) })} />
              {!readOnly && <button type="button" className="icon-button" disabled={run?.running} aria-label={tr("Remove scenario {value0}", { value0: probeIndex + 1 })}
                title={tr("Remove scenario")} onClick={() => change(question.id, { probes: (question.probes || []).filter((_, itemIndex) => itemIndex !== probeIndex) })}><X size={14} aria-hidden="true" /></button>}
            </div>)}
            {!readOnly && <button type="button" className="button teacher-small-button" disabled={run?.running || (question.probes || []).length >= 20}
              onClick={() => change(question.id, { probes: [...(question.probes || []), ""] })}><Plus size={14} aria-hidden="true" /><TranslatedText>{tr("Add test scenario")}</TranslatedText></button>}
          </section>}
          <div className="teacher-question-answer-label"><TranslatedText>{tr("Answer SQL")}</TranslatedText></div>
          <ProblemSqlEditor value={question.answer} label={tr("Reference answer {value0}", { value0: index + 1 })}
            readOnly={readOnly}
            placeholder={part === "TRIGGER" ? tr("-- Write CREATE TRIGGER name ON table AFTER INSERT AS ...") : part === "DDL" ? tr("-- Enter the expected CREATE, ALTER or DROP statements") : tr("-- Enter the expected INSERT, UPDATE, DELETE or SELECT INTO statements")}
            onChange={answer => change(question.id, { answer })} />
          <div className="teacher-solution-run-row">
            <span className="teacher-solution-timing" aria-live="polite"><TranslatedText>{tr(run?.running ? "Running…" : stale ? "Changed since last run" : run?.results.length ? "Last run" : "Not run yet")}</TranslatedText></span>
            <button className="button primary" type="button" disabled={Object.values(runs).some(item => item.running) || !question.answer.trim()}
              onClick={() => void runQuestion(question)}><Play size={14} aria-hidden="true"/><TranslatedText>{tr(run?.running ? "Running…" : "Run reference")}</TranslatedText></button>
          </div>
          {run && !stale && run.results.map(result => {
            const changes = result.changes || [];
            const dataChanges = result.dataChanges || [];
            const changeCount = part === "TRIGGER" ? result.triggers?.length || 0 : changes.length + dataChanges.length;
            return <TeacherRunResult key={result.dataset} dataset={result.dataset} error={result.error}
              summary={<TranslatedText>{tr(result.summary || "Run complete")}</TranslatedText>}
              toggleLabel={tr(part === "TRIGGER" ? "View created triggers ({value0})" : part === "DDL" ? "View changed tables ({value0})" : "View data changes ({value0})", { value0: changeCount })}>
              {result.triggers?.map(trigger=><p key={trigger.name}>{trigger.name} → {trigger.table}</p>)}
              {result.scenarios?.map(item => <p key={item.scenario} role={item.ok ? undefined : "alert"} className={item.ok ? undefined : "teacher-state-failed"}>
                <TranslatedText>{tr("Scenario {value0}", { value0: item.scenario })}</TranslatedText>: {item.ok
                  ? <TranslatedText>{tr("changes {value0}", { value0: item.changedTables.join(", ") })}</TranslatedText>
                  : <TranslatedText>{tr(item.message || "Scenario failed.")}</TranslatedText>}</p>)}
              {part !== "TRIGGER" && changeCount > 0 && (part === "DDL"
                ? <TeacherSchemaComparison changes={changes} /> : <TeacherDataComparison changes={dataChanges} />)}
            </TeacherRunResult>;
          })}
        </TeacherQuestionCard>;
      })}
  </TeacherExerciseCollection>;
}
