import { useEffect, useRef, useState } from "react";
import { ChevronDown, Play, Trash2 } from "lucide-react";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import { useLanguage } from "@/shared/context/LanguageContext";
import type { DataTable } from "@/data/models";
import type { TeacherSqlSolution } from "@/data/teacherTypes";
import { teacherService } from "@/shared/api/teacherService";
import { ProblemSqlEditor } from "@/features/teacher/problem-editor/ProblemSqlEditor";
import { TeacherExerciseCollection } from "@/features/teacher/problem-editor/TeacherExerciseCollection";
import { TeacherQuestionCard } from "@/features/teacher/problem-editor/TeacherQuestionCard";
import { SqlFileNameInput } from "@/features/teacher/problem-editor/SqlFileNameInput";
import { TeacherRunResult } from "@/features/teacher/problem-editor/TeacherRunResult";
import { TeacherResultTable } from "@/features/teacher/problem-editor/TeacherResultTable";
import { QuestionTagSelectors } from "@/features/teacher/problem-editor/QuestionTagSelectors";

type DatasetResult = {
  dataset: number;
  timeMs?: number;
  error?: string;
  table?: DataTable;
};
type Run = { running: boolean; source: string; results: DatasetResult[] };

export function initialSqlSolutions(solutions?: TeacherSqlSolution[] | null, legacyQuery = "", legacyId: string = crypto.randomUUID()): TeacherSqlSolution[] {
  return solutions?.length ? solutions.map(solution => ({ ...solution, enabled: solution.enabled !== false, description: solution.description || "", setupSql: solution.setupSql || "", expectedOutputs: solution.expectedOutputs || [], expectedOutputVisible: solution.expectedOutputVisible !== false })) : [{ id: legacyId, name: "DQL1.sql", description: "", expectedOutputs: [], expectedOutputVisible: true, query: legacyQuery, enabled: true, timeLimitMs: 5000 }];
}

function TimeLimitInput({ value, onChange, id, disabled = false }: { value: number; onChange: (value: number) => void; id: string; disabled?: boolean }) {
  const [draft, setDraft] = useState(String(value));
  useEffect(() => setDraft(String(value)), [value]);
  return <input id={id} type="number" min={100} max={60000} step={100} value={draft} disabled={disabled} readOnly={disabled}
    onChange={(event) => {
      if (disabled) return;
      setDraft(event.target.value);
      const next = event.target.valueAsNumber;
      if (Number.isFinite(next) && next >= 100 && next <= 60000) onChange(Math.round(next));
    }}
    onBlur={() => {
      if (disabled) return;
      const next = Math.round(Math.min(60000, Math.max(100, Number(draft) || value)));
      setDraft(String(next));
      if (next !== value) onChange(next);
    }}
  />;
}

export function TeacherSqlSolutions({ solutions, schema, datasets, onChange,
  onExpectedOutputsChange, onRunningChange, readOnly = false }: {
  solutions: TeacherSqlSolution[];
  schema: string;
  datasets: { seedData: string; isHidden?: boolean }[];
  onChange: (solutions: TeacherSqlSolution[]) => void;
  onExpectedOutputsChange: (outputs: DataTable[]) => void;
  onRunningChange: (running: boolean) => void;
  readOnly?: boolean;
}) {
  const { t: tr, locale } = useLanguage("teacher");
  const [expandedSetups, setExpandedSetups] = useState<Record<string,boolean>>({});
  const [runs, setRuns] = useState<Record<string, Run>>({});
  const mounted = useRef(false);
  const latest = useRef({ solutions, schema, datasets, onExpectedOutputsChange, onChange });
  latest.current = { solutions, schema, datasets, onExpectedOutputsChange, onChange };
  const formatMs = (value: number) => new Intl.NumberFormat(locale, { maximumFractionDigits: 3 }).format(value);
  useEffect(() => {
    mounted.current = true;
    return () => { mounted.current = false; };
  }, []);
  useEffect(() => onRunningChange(Object.values(runs).some((run) => run.running)), [runs, onRunningChange]);
  useEffect(() => () => onRunningChange(false), [onRunningChange]);

  function change(id: string, patch: Partial<TeacherSqlSolution>) {
    if (readOnly) return;
    onChange(solutions.map((solution) => solution.id === id ? { ...solution, ...patch, ...((patch.query !== undefined && patch.query !== solution.query || patch.setupSql !== undefined && patch.setupSql !== solution.setupSql) ? { expectedOutputs: [] } : {}) } : solution));
  }
  function sourceOf(solution: TeacherSqlSolution) {
    return JSON.stringify([schema, datasets.map((dataset) => dataset.seedData), solution.query, solution.setupSql || ""]);
  }
  async function runSolution(solution: TeacherSqlSolution) {
    if (Object.values(runs).some(run => run.running) || !solution.query.trim()) return;
    const source = sourceOf(solution);
    const results: DatasetResult[] = [];
    setRuns((current) => ({ ...current, [solution.id]: { running: true, source, results: [] } }));
    try {
      for (const [index, dataset] of datasets.entries()) {
        try {
          const result = await teacherService.validateSolution({ database: "SQL Server", schema,
            seedData: dataset.seedData, referenceSolution: solution.query, setupSql: solution.setupSql || "" });
          results.push(result.status === "Success"
            ? { dataset: index + 1, timeMs: result.executionTimeMs, table: result.table }
            : { dataset: index + 1, error: result.message || tr("Run failed") });
        } catch (error) {
          results.push({ dataset: index + 1, error: error instanceof Error ? error.message : tr("Run failed") });
        }
        if (!mounted.current) return;
        setRuns((current) => ({ ...current, [solution.id]: { running: true, source, results: [...results] } }));
      }
    } finally {
      if (mounted.current) {
        const current = latest.current;
        const currentSolution = current.solutions.find((item) => item.id === solution.id);
        const sourceIsCurrent = currentSolution
          && source === JSON.stringify([current.schema, current.datasets.map((dataset) => dataset.seedData), currentSolution.query, currentSolution.setupSql || ""]);
        if (sourceIsCurrent && datasets.length > 0 && results.length === datasets.length
          && results.every((result) => result.table && !result.error)) {
          const outputs = results.map((result) => result.table!);
          current.onChange(current.solutions.map(item => item.id === solution.id ? { ...item, expectedOutputs: outputs } : item));
          if (current.solutions[0]?.id === solution.id) current.onExpectedOutputsChange(outputs);
        }
        setRuns((current) => ({ ...current, [solution.id]: { running: false, source, results: [...results] } }));
      }
    }
  }

  return <TeacherExerciseCollection id="teacher-dql-exercise" title={tr("DQL questions")} addLabel="Add question" count={solutions.length} disabled={readOnly}
    onAdd={() => {
      if (readOnly) return;
      const names = new Set(solutions.map(item => item.name));
      let number = solutions.length + 1;
      while (names.has(`DQL${number}.sql`)) number++;
      onChange([...solutions, { id: crypto.randomUUID(), name: `DQL${number}.sql`, description: "", query: "", enabled: true, timeLimitMs: 5000, expectedOutputs: [], expectedOutputVisible: true }]);
    }}>
    {solutions.map((solution, index) => {
      const run = runs[solution.id];
      const stale = Boolean(run && run.source !== sourceOf(solution));
      const times = (run?.results || []).flatMap((result) => typeof result.timeMs === "number" ? [result.timeMs] : []);
      return <TeacherQuestionCard key={solution.id} id={`teacher-sql-question-${solution.id}`} number={index + 1}
        descriptionText={solution.description || ""}
        description={<label className="database-description-question-body"><TranslatedText>{tr("Question description")}</TranslatedText>
          <textarea aria-label={tr("Question description")} rows={3} maxLength={20000} value={solution.description || ""} placeholder={tr("Describe what students must query for this question.")}
            disabled={readOnly} readOnly={readOnly}
            onChange={event => change(solution.id, { description: event.target.value })} />
        </label>}
          fileName={<SqlFileNameInput label={tr("Question {value0} file name", { value0: index + 1 })}
          value={solution.name} disabled={readOnly || run?.running} onChange={name => change(solution.id, { name })} />}
        tagControls={<QuestionTagSelectors
          part="DQL"
          difficulty={solution.difficulty}
          category={solution.category}
          disabled={readOnly || run?.running}
          onDifficultyChange={diff => change(solution.id, { difficulty: diff })}
          onCategoryChange={cat => change(solution.id, { category: cat })}
        />}
        actions={!readOnly && solutions.length > 1 && <button type="button" className="icon-button" disabled={run?.running} aria-label={tr("Delete solution {value0}", { value0: index + 1 })} title={tr("Delete") } onClick={() => {
              if ((solution.query.trim() || solution.description?.trim()) && !window.confirm(tr("Delete this solution and its question?"))) return;
              onChange(solutions.filter(item => item.id !== solution.id));
            }}><Trash2 size={15} /></button>}>

        <details className="teacher-stage-setup" open={expandedSetups[solution.id] ?? Boolean(solution.setupSql?.trim())}
          onToggle={event => {const open=event.currentTarget.open;setExpandedSetups(current=>current[solution.id]===open?current:{...current,[solution.id]:open});}}>
          <summary><ChevronDown size={14} aria-hidden="true"/><TranslatedText>{tr("SQL setup before this question (optional)")}</TranslatedText></summary>
          <ProblemSqlEditor label={tr("SQL setup before this question (optional)")} value={solution.setupSql || ""}
            readOnly={readOnly}
            placeholder={tr("-- Example: SELECT * INTO SANPHAM1 FROM SANPHAM;")}
            onChange={setupSql=>change(solution.id,{setupSql})}/>
          <span className="teacher-stage-setup-hint"><TranslatedText>{tr("Runs after loading the starting database and before this question. Each question runs independently.")}</TranslatedText></span>
        </details>
        <div id={index === 0 ? "database-dql-sql-input" : undefined} className={index === 0 ? "teacher-problem-navigation-target" : undefined}>
          <div className="teacher-question-answer-label"><TranslatedText>{tr("Answer SQL")}</TranslatedText></div>
          <ProblemSqlEditor value={solution.query} label={tr("Solution {value0} SQL", { value0: index + 1 })}
            readOnly={readOnly}
            placeholder={tr("-- Example SQL Server query\nSELECT CustomerId, FullName\nFROM Customers\nORDER BY CustomerId;")}
            onChange={(query) => change(solution.id, { query })} />
        </div>
        <div className="teacher-solution-run-row">
          <span className="teacher-solution-timing" aria-live="polite">
            <TranslatedText>{tr(run?.running ? "Running…" : stale ? "Changed since last run" : times.length ? "Slowest dataset" : "Not run yet")}</TranslatedText>
            {!stale && times.length > 0 && <strong>{formatMs(Math.max(...times))} <TranslatedText>{tr("ms")}</TranslatedText></strong>}
          </span>
          <button className="button primary" type="button" disabled={Object.values(runs).some(run => run.running) || !solution.query.trim()}
            onClick={() => void runSolution(solution)}><Play size={14} aria-hidden="true" /><TranslatedText>{tr(run?.running ? "Running…" : "Run")}</TranslatedText></button>
          <label className="teacher-solution-limit" htmlFor={`solution-limit-${solution.id}`}>
            <TranslatedText>{tr("Student time limit")}</TranslatedText>
            <TimeLimitInput id={`solution-limit-${solution.id}`} value={solution.timeLimitMs} disabled={readOnly} onChange={(timeLimitMs) => change(solution.id, { timeLimitMs })} />
            <span><TranslatedText>{tr("ms")}</TranslatedText></span>
          </label>
        </div>
        {run && !stale && run.results.map((result) => <TeacherRunResult key={result.dataset}
          dataset={result.dataset} error={result.error} className="teacher-solution-output"
          summary={<>{result.table && <TranslatedText>{tr("{value0} rows", { value0: result.table.rows.length })}</TranslatedText>}
            {typeof result.timeMs === "number" && <> · {formatMs(result.timeMs)} <TranslatedText>{tr("ms")}</TranslatedText></>}
          </>}
          toggleLabel={tr("View query result")}>
          {result.table && <TeacherResultTable name={tr("Query result")} table={result.table} />}
        </TeacherRunResult>)}
      </TeacherQuestionCard>;
    })}
  </TeacherExerciseCollection>;
}
