import { useEffect, useMemo, useRef, useState } from "react";
import { ChevronDown, Settings2, X } from "lucide-react";
import { useLanguage } from "@/shared/context/LanguageContext";
import type { DatabaseRelationDescription, DataTable, WorkspacePart } from "@/data/models";
import { teacherService } from "@/shared/api/teacherService";
import { WORKSPACE_PARTS, WORKSPACE_PART_LABELS } from "@/shared/lib/workspace/workspaceParts";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import { Loading } from "@/shared/ui/ui";
import { ProblemMarkdown } from "@/shared/components/ProblemMarkdown";
import { BuilderDatabaseReference } from "@/features/teacher/builder/BuilderDatabaseReference";
import "@/styles/builder-problem-settings.css";

export type BuilderProblemDetail = {
  questionCatalog?: Partial<Record<WorkspacePart, QuestionOption[]>>;
  triggerExercise?: { questions: { id: string; name: string; description: string; answer: string; difficulty?: string; category?: string }[] };
  ddlExercise?: { questions: { id: string; name: string; description: string; answer: string; difficulty?: string; category?: string }[] };
  dmlExercise?: { questions: { id: string; name: string; description: string; answer: string; difficulty?: string; category?: string }[] };
  description?: string; requirements?: string; schema?: string; seedData?: string;
  databaseDescription?: DatabaseRelationDescription[];
  testCases?: { seedData: string; isHidden: boolean }[];
  sqlSolutions?: { id: string; name: string; description?: string; query: string; enabled: boolean; timeLimitMs: number; difficulty?: string; category?: string }[];
  referenceSolution?: string; expectedOutputs?: DataTable[];
};

export type AutoQuestionGroupRule = { id: string; difficulty: string; category: string; count: number; };
export type SharedQuestionRule = { id: string; difficulty: string; category: string; questionId: string; part?: WorkspacePart; };
export type ExamVariant = {
  code: string;
  questions: Partial<Record<WorkspacePart, string[]>>;
};
export type QuestionSelection = Partial<Record<WorkspacePart, string[]>> & {
  _autoRules?: Partial<Record<WorkspacePart, AutoQuestionGroupRule[]>>;
  _variants?: ExamVariant[];
  _variantCount?: number;
  _variantSuffix?: string;
  _variantPrefix?: string;
  _sharedRules?: Partial<Record<WorkspacePart, SharedQuestionRule[]>>;
  _sharedQuestions?: Partial<Record<WorkspacePart, string[]>>;
};
export type QuestionOption = { id: string; name: string; description?: string; kind?: "schema" | "seed"; timeLimitMs?: number; difficulty?: string; category?: string };
export function catalogOf(detail: BuilderProblemDetail | null): Record<WorkspacePart, QuestionOption[]> {
  const enrich = (list: QuestionOption[], part: WorkspacePart): QuestionOption[] => {
    return list.map(q => {
      let diff = q.difficulty?.trim() || "Dễ";
      let cat = q.category?.trim() || "";
      if (q.kind === "schema") {
        diff = q.difficulty?.trim() || "Dễ";
        cat = q.category?.trim() || "DDL - Tạo bảng (CREATE TABLE)";
      } else if (q.kind === "seed") {
        diff = q.difficulty?.trim() || "Dễ";
        cat = q.category?.trim() || "DML - Thêm dòng dữ liệu (INSERT)";
      } else if (!cat) {
        if (part === "DDL") {
          const lower = (q.name + " " + (q.description || "")).toLowerCase();
          if (lower.includes("drop") || lower.includes("truncate")) {
            cat = "DDL - Xóa đối tượng (DROP/TRUNCATE)";
          } else if (lower.includes("constraint") || lower.includes("khóa") || lower.includes("ràng buộc")) {
            cat = "DDL - Ràng buộc toàn vẹn (CONSTRAINT)";
          } else {
            cat = "DDL - Sửa cấu trúc (ALTER TABLE)";
          }
        } else if (part === "DML") {
          const lower = (q.name + " " + (q.description || "")).toLowerCase();
          if (lower.includes("update") || lower.includes("cập nhật")) {
            cat = "DML - Cập nhật dữ liệu (UPDATE)";
          } else if (lower.includes("delete") || lower.includes("xóa")) {
            cat = "DML - Xóa dòng dữ liệu (DELETE)";
          } else {
            cat = "DML - Thêm dòng dữ liệu (INSERT)";
          }
        } else if (part === "SQL") {
          const lower = (q.name + " " + (q.description || "")).toLowerCase();
          if (lower.includes("join") || lower.includes("kết nối")) {
            cat = "DQL - Kết nối nhiều bảng (JOIN)";
          } else if (lower.includes("group by") || lower.includes("gom nhóm") || lower.includes("having")) {
            cat = "DQL - Gom nhóm & Tổng hợp (GROUP BY/HAVING)";
          } else if (lower.includes("subquery") || lower.includes("truy vấn con")) {
            cat = "DQL - Truy vấn con (SUBQUERY)";
          } else {
            cat = "DQL - Truy vấn cơ bản (SELECT/WHERE)";
          }
        } else if (part === "TRIGGER") {
          cat = "Trigger - Kiểm tra ràng buộc nâng cao";
        }
      }
      return { ...q, difficulty: diff, category: cat };
    });
  };
  if (detail?.questionCatalog) return {
    DDL: enrich(detail.questionCatalog.DDL || [], "DDL"),
    DML: enrich(detail.questionCatalog.DML || [], "DML"),
    SQL: enrich(detail.questionCatalog.SQL || [], "SQL"),
    TRIGGER: enrich(detail.questionCatalog.TRIGGER || [], "TRIGGER")
  };
  const schema = detail?.schema?.trim();
  const seed = detail?.testCases?.some(dataset => dataset.seedData.trim()) || detail?.seedData?.trim();
  const queries = (detail?.sqlSolutions || []).filter(solution => solution.query.trim());
  return {
    DDL: enrich([...(schema ? [{ id: "__base_ddl__", name: "create_tables.sql", kind: "schema" as const, description: "Create all tables and declare their primary and foreign keys.", difficulty: "Dễ", category: "DDL - Tạo bảng (CREATE TABLE)" }] : []), ...(detail?.ddlExercise?.questions || []).filter(question => question.answer.trim())], "DDL"),
    DML: enrich([...(schema && seed ? [{ id: "__base_dml__", name: "insert_data.sql", kind: "seed" as const, description: "Insert the original sample data into all tables.", difficulty: "Dễ", category: "DML - Thêm dòng dữ liệu (INSERT)" }] : []), ...(detail?.dmlExercise?.questions || []).filter(question => question.answer.trim())], "DML"),
    TRIGGER: enrich((detail?.triggerExercise?.questions || []).filter(question => question.answer.trim()), "TRIGGER"),
    SQL: enrich(queries.length ? queries : (detail?.referenceSolution?.trim() ? [{ id: "__base_sql__", name: "query.sql" }] : []), "SQL"),
  };
}

export function BuilderProblemSettings({ problemId, title, parts, closing, onClose, onClosed, onPartsChange, onAdd, questionSelection, onQuestionSelectionChange, visibility = { databaseStructureVisible: true, databaseDescriptionVisible: true }, onVisibilityChange, datasetVisibility = {}, onDatasetVisibilityChange }: {
  questionSelection?: QuestionSelection;
  onQuestionSelectionChange?: (selection: QuestionSelection) => void;
  datasetVisibility?: Record<string, boolean>;
  onDatasetVisibilityChange?: (visibility: Record<string, boolean>) => void;
  visibility?: { databaseStructureVisible: boolean; databaseDescriptionVisible: boolean };
  onVisibilityChange?: (visibility: { databaseStructureVisible: boolean; databaseDescriptionVisible: boolean }) => void;
  problemId: string; title: string; parts: WorkspacePart[]; closing: boolean;
  onClose: () => void; onClosed: () => void; onPartsChange: (parts: WorkspacePart[]) => void; onAdd?: () => void;
}) {
  const { t: tr } = useLanguage("teacher");
  const [detail, setDetail] = useState<BuilderProblemDetail | null>(null);
  const [error, setError] = useState("");
  const [attempt, setAttempt] = useState(0);
  const [expanded, setExpanded] = useState<WorkspacePart[]>(["DDL"]);
  const closeButton = useRef<HTMLButtonElement>(null);
  useEffect(() => { closeButton.current?.focus(); }, []);
  useEffect(() => {
    let active = true;
    setDetail(null); setError("");
    teacherService.getProblem(problemId).then(data => { if (active) setDetail(data); })
      .catch(reason => { if (active) setError(reason instanceof Error ? reason.message : tr("Could not load problem details.")); });
    return () => { active = false; };
  }, [problemId, attempt]);
  const catalog = useMemo(() => catalogOf(detail), [detail]);
  const datasets = detail?.testCases?.length ? detail.testCases : detail?.seedData ? [{ seedData: detail.seedData, isHidden: false }] : [];
  const selectedIds = (part: WorkspacePart) => questionSelection?.[part] ?? (() => {
    const custom = catalog[part].filter(question => !question.kind);
    return (custom.length ? custom : catalog[part]).map(question => question.id);
  })();
  useEffect(() => {
    if (detail && onAdd && onQuestionSelectionChange && !Object.keys(questionSelection || {}).length) {
      onQuestionSelectionChange?.(Object.fromEntries(WORKSPACE_PARTS.map(part => [part, catalog[part].map(question => question.id)])));
      onPartsChange(parts.filter(part => catalog[part].length > 0));
    }
  }, [detail, catalog, onAdd, questionSelection, onQuestionSelectionChange, onPartsChange, parts]);
  const importHasNoSample = parts.includes("DML") && questionSelection?.DML?.includes("__base_dml__")
    && !datasets.some((dataset, index) => (datasetVisibility[String(index + 1)] ?? !dataset.isHidden) && dataset.seedData.trim());
  function chooseQuestion(part: WorkspacePart, id: string, checked: boolean) {
    const selected = new Set(parts.includes(part) ? selectedIds(part) : []);
    if (checked) selected.add(id); else selected.delete(id);
    onQuestionSelectionChange?.({ ...questionSelection, [part]: catalog[part].filter(question => selected.has(question.id)).map(question => question.id) });
    onPartsChange(WORKSPACE_PARTS.filter(value => value === part ? selected.size > 0 : parts.includes(value)));
  }
  return <aside id="builder-problem-settings" className={`teacher-student-preview-panel builder-problem-settings${closing ? " is-closing" : ""}`} aria-labelledby="builder-problem-settings-title"
    onKeyDown={event => { if (event.key === "Escape") { event.stopPropagation(); onClose(); } }}
    onAnimationEnd={event => { if (event.target === event.currentTarget && event.animationName === "teacher-preview-panel-out") onClosed(); }}>
    <header className="teacher-student-preview-heading"><h2 id="builder-problem-settings-title"><Settings2 size={18} aria-hidden="true" /><TranslatedText>{tr("Problem settings")}</TranslatedText></h2><button ref={closeButton} type="button" className="icon-button" aria-label={tr("Close settings")} onClick={onClose}><X size={18} /></button></header>
    <div className="builder-problem-settings-scroll">
      <h3 className="builder-problem-settings-name">{title}</h3>
      <p className="muted"><TranslatedText>{tr("Choose the questions students will solve in this activity.")}</TranslatedText></p>
      {importHasNoSample && <p className="teacher-state-failed" role="alert"><TranslatedText>{tr("Keep at least one dataset visible for the data import question.")}</TranslatedText></p>}
      {!parts.length && <p className="teacher-state-failed" role="alert"><TranslatedText>{tr("Select at least one section.")}</TranslatedText></p>}
      {error && <div role="alert"><p>{error}</p><button type="button" className="button" onClick={() => setAttempt(value => value + 1)}><TranslatedText>{tr("Retry")}</TranslatedText></button></div>}
      {!detail && !error && <Loading variant="inline" rows={6} label={tr("Loading problem details…")} />}
      {detail && <BuilderDatabaseReference schema={detail.schema} datasets={datasets} visibility={visibility} onVisibilityChange={onVisibilityChange} datasetVisibility={datasetVisibility} onDatasetVisibilityChange={onDatasetVisibilityChange} />}
      {WORKSPACE_PARTS.map(part => {
        const open = expanded.includes(part);
        const label = tr(WORKSPACE_PART_LABELS[part]);
        const choices = catalog[part];
        const selectedCount = parts.includes(part) ? choices.filter(question => selectedIds(part).includes(question.id)).length : 0;
        return <section className={`builder-settings-section${parts.includes(part) ? " is-enabled" : ""}`} key={part}>
          <div className="builder-settings-section-heading">
            <input type="checkbox" className="builder-settings-part-toggle" aria-label={label} checked={parts.includes(part)}
              ref={node => { if (node) node.indeterminate = selectedCount > 0 && selectedCount < choices.length; }}
              disabled={!detail || (!choices.length && !parts.includes(part))}
              onChange={event => { if (event.target.checked && !selectedCount) onQuestionSelectionChange?.({ ...questionSelection, [part]: choices.map(question => question.id) }); onPartsChange(WORKSPACE_PARTS.filter(value => value === part ? event.target.checked : parts.includes(value))); }} />
            <button type="button" aria-expanded={open} aria-controls={`builder-settings-${part}`} onClick={() => setExpanded(previous => open ? previous.filter(value => value !== part) : [...previous, part])}><strong><TranslatedText>{label}</TranslatedText></strong><span className="builder-settings-question-count"><TranslatedText>{tr("{value0}/{value1} questions", { value0: selectedCount, value1: choices.length })}</TranslatedText></span><ChevronDown size={17} className={open ? "is-expanded" : ""} aria-hidden="true" /></button>
          </div>
          <div className={`builder-settings-fold${open ? " is-expanded" : ""}`} ref={element => { if (element) element.inert = !open; }}>
            <div className="builder-settings-fold-inner"><div className="builder-settings-content" id={`builder-settings-${part}`}>
              {detail && <div className="builder-settings-questions">
                {choices.length > 0 ? <>
                  <button className="builder-settings-select-all" type="button" disabled={selectedCount === choices.length} onClick={() => {
                    onQuestionSelectionChange?.({ ...questionSelection, [part]: choices.map(question => question.id) });
                    onPartsChange(WORKSPACE_PARTS.filter(value => value === part || parts.includes(value)));
                  }}><TranslatedText>{tr("Select all questions")}</TranslatedText></button>
                  {choices.map((question, index) => <label className="builder-settings-question" key={question.id}>
                    <input type="checkbox" aria-label={tr("Select question {value0}", { value0: question.name })}
                      checked={parts.includes(part) && selectedIds(part).includes(question.id)} onChange={event => chooseQuestion(part, question.id, event.target.checked)} />
                    <span><span className="builder-settings-question-title">{index + 1}. <TranslatedText>{question.kind === "schema" ? tr("Create tables and keys") : question.kind === "seed" ? tr("Import original data") : question.name}</TranslatedText>
                      {question.kind && <small><TranslatedText>{tr("From original database")}</TranslatedText></small>}</span>
                      {question.description && <ProblemMarkdown>{tr(question.description)}</ProblemMarkdown>}
                    </span>
                  </label>)}
                </> : <p className="muted"><TranslatedText>{tr("No questions are available in this section.")}</TranslatedText></p>}
              </div>}

            </div></div>
          </div>
        </section>;
      })}
    </div>
    <footer className="builder-problem-settings-footer">{onAdd ? <button type="button" className="button primary" disabled={!detail || !parts.length || importHasNoSample} onClick={onAdd}><TranslatedText>{tr("Add problem")}</TranslatedText></button> : <button type="button" className="button primary" disabled={importHasNoSample} onClick={onClose}><TranslatedText>{tr("Done")}</TranslatedText></button>}</footer>
  </aside>;
}
