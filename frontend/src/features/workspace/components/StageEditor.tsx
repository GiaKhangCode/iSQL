import { useEffect, useMemo, useRef, useState, type CSSProperties } from "react";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import { useLanguage } from "@/shared/context/LanguageContext";
import CodeMirror from "@uiw/react-codemirror";
import { sqlEditorExtensions } from "@/shared/components/sqlEditorExtensions";
import { DataGrid, Dialog } from "@/shared/ui/ui";
import { ProblemQuestionContent, type ProblemQuestion } from "@/shared/components/ProblemQuestionContent";
import { workspaceReference } from "@/shared/lib/workspace/workspaceReference";
import { Play, RotateCcw } from "lucide-react";
import type { Problem, WorkspacePart } from "@/data/models";
import type { QueryResult } from "@/shared/types/queryResult";
import { useProblemWorkspaceLayout } from "@/features/workspace/components/ProblemWorkspaceLayoutContext";
import { WorkspaceResizeHandle } from "@/features/workspace/components/WorkspaceResizeHandle";
import { WorkspaceOutputPanel } from "@/features/workspace/components/WorkspaceOutputPanel";
import { WorkspaceDatabasePanel, type DatabaseView, type WorkspaceDatabaseState } from "@/shared/components/WorkspaceDatabasePanel";
import { useCurrentDatabase } from "@/shared/components/useCurrentDatabase";
import { WorkspaceSubmissionPanel, type WorkspaceAttempt } from "@/features/workspace/components/WorkspaceSubmissionPanel";
import { defaults, type PaneSizes } from "@/shared/lib/workspace/workspacePreferences";
import { WorkspacePanePanel, WorkspacePaneTabs, type WorkspacePane } from "@/features/workspace/components/WorkspacePaneTabs";
import "@/features/workspace/components/WorkspaceReadingPane.css";
import { WorkspaceSubmissionLock } from "@/features/workspace/components/WorkspaceSubmissionLock";
import { useSearchParams } from "react-router-dom";
import { studentApi } from "@/shared/api/studentApi";
import { usePreparedDatabase } from "@/shared/components/usePreparedDatabase";
import { useWorkspacePane } from "@/features/workspace/components/useWorkspacePane";
import { useAuth } from "@/app/providers/AuthContext";
import { workspaceDraftKey, WORKSPACE_PART_LABELS } from "@/shared/lib/workspace/workspaceParts";
import { WorkspacePaneTitle } from "@/features/workspace/components/WorkspaceTitle";
import { WorkspaceRunActions } from "@/features/workspace/components/WorkspaceRunSlot";

export type StageEditorProps = {
  problem: Problem; value: string; onChange: (value: string) => void;
  onRun: (selection?: string) => void; onReset?: () => void; busy: boolean; result?: QueryResult | null; error?: string;
  database: WorkspaceDatabaseState;
  readOnly?: boolean; submitted?: boolean; submissionAttempts?: WorkspaceAttempt[]; submissionBusy?: boolean;
  submissionNotice?: string; submissionSignal?: number; submissionsUsed?: number; maxSubmissions?: number | null;
  questionId?: string; questionDescription?: string; questionStartFrom?: "empty" | "structure" | "provided";
  questions?: ProblemQuestion[]; onQuestionChange?: (id: string) => void;
  questionGroups?: Partial<Record<WorkspacePart,ProblemQuestion[]>>;
  selectedQuestionIds?: Partial<Record<WorkspacePart,string>>;
  onSubmitQuestion?: (attempt:WorkspaceAttempt) => void | QueryResult | Promise<void | QueryResult>;
};

export function StageEditor({ problem, value, onChange, onRun, onReset, busy, result, error, part, placeholder, database, readOnly = false, submitted = false, submissionAttempts = [], submissionBusy = false, submissionNotice = "", submissionSignal = 0, submissionsUsed, maxSubmissions, questionId, questionDescription, questionStartFrom, questions, onQuestionChange, questionGroups, selectedQuestionIds, onSubmitQuestion }: StageEditorProps & {
  part: "DDL" | "DML" | "TRIGGER"; description: string; placeholder: string;
}) {
  const { t: tr } = useLanguage("workspace");
  const layoutContext = useProblemWorkspaceLayout();
  if (!layoutContext) throw new Error("StageEditor must be rendered inside ProblemWorkspaceLayoutProvider.");
  const { preferences, setPreferences, focus } = layoutContext;
  const { layout } = preferences;
  const { problemWidth, secondBoundary, editorHeight } = preferences.sizes[layout];
  const startFrom = questionStartFrom ?? (part === "DDL" ? "empty" : "structure");
  const currentQuestions = questions ?? (questionId ? [{ id: questionId, description: questionDescription, startFrom }] : []);
  const questionKind = (currentQuestions.find(question => question.id === questionId) as ProblemQuestion | undefined)?.kind;
  const questionLabel = questionId ? tr("Question {value0} {value1}",{value0:Math.max(0,currentQuestions.findIndex(question => question.id === questionId)) + 1,value1:WORKSPACE_PART_LABELS[part]}) : part;
  const targetTables = useMemo(() => part === "DML" && (!questionId || questionKind === "seed") ? workspaceReference(problem).tables : [], [problem, part, questionId, questionKind]);
  const [params] = useSearchParams();
  const {session} = useAuth();
  const source = params.get("source") === "ExamSession" ? "ExamSession" : params.get("source") === "Assignments" ? "Assignments" : "Practice";
  const context = params.get("context") || source;
  const [problemTab, setProblemTab] = useWorkspacePane(workspaceDraftKey(session?.id || "anonymous",problem.id,context) + `:pane:${part}`);
  const hasSetup = Boolean(currentQuestions.find(question => question.id === questionId)?.hasSetup);
  const prepared = usePreparedDatabase(hasSetup && questionId ? `${problem.id}:${context}:${part}:${questionId}` : null, problemTab === "Database", () =>
    studentApi.getQuestionDatabase(problem.id, {part,solutionId:questionId!,source,context}));
  const fullSchema = useMemo(() => workspaceReference(problem).schemaTables, [problem]);
  const referenceSchema = prepared.data?.schemaTables.length ? prepared.data.schemaTables : fullSchema;
  const [databaseView, setDatabaseView] = useState<DatabaseView>();
  const { current: currentDatabase, loading: currentLoading, error: currentError, reset: resetCurrent } = useCurrentDatabase(result, questionId || part, {
    load: () => studentApi.getCurrentDatabase(problem.id, { source, context }),
    active: part === "DDL" && problemTab === "Database" && (databaseView === "current" || databaseView === undefined),
    explicit: databaseView === "current",
  });
  const [confirmReset, setConfirmReset] = useState(false);
  const [resizing, setResizing] = useState(false);
  const [selected, setSelected] = useState("");
  const workspaceLayout = useRef<HTMLDivElement | null>(null);
  const editorPane = useRef<HTMLElement | null>(null);
  const [outputOpen, setOutputOpen] = useState(false);
  // Chạy xong (hoặc đang chạy) thì mở panel kết quả dưới editor.
  useEffect(() => { if (result || error) setOutputOpen(true); }, [result, error]);
  useEffect(() => { if (busy) setOutputOpen(true); }, [busy]);
  const previousQuestion = useRef(questionId);
  useEffect(() => { if (previousQuestion.current !== questionId) setProblemTab("Description"); previousQuestion.current = questionId; }, [questionId,setProblemTab]);
  useEffect(() => { if (layout === "Debug") setProblemTab("Database"); }, [layout]);
  useEffect(() => { if (submissionSignal) setProblemTab("Submission"); }, [submissionSignal]);
  function updateSizes(change: Partial<PaneSizes>) {
    setPreferences(previous => ({ ...previous, sizes: { ...previous.sizes, [layout]: { ...previous.sizes[layout], ...change } } }));
  }
  return <div ref={workspaceLayout} className={`stage-workspace${resizing ? " is-resizing" : ""}${focus ? " focus-mode" : ""}`} style={{ "--problem-pane-width": `${problemWidth}%` } as CSSProperties}>
    <aside id={`stage-problem-pane-${part}`} className="stage-problem problem-pane workspace-reading-pane" aria-label={tr("Problem details")}>
      <WorkspacePaneTitle />
      <WorkspacePaneTabs value={problemTab} onChange={setProblemTab} prefix={`stage-${part}`} />
      <WorkspacePanePanel value={problemTab} prefix={`stage-${part}`}>
        {problemTab === "Submission" ? <WorkspaceSubmissionPanel currentScript={value} onRunCurrent={()=>onRun()} questionGroups={questionGroups || {[part]:currentQuestions}} selectedQuestionIds={selectedQuestionIds} onSubmitQuestion={onSubmitQuestion} mode="submit" part={part} questionId={questionId} attempts={submissionAttempts} busy={busy || submissionBusy} notice={submissionNotice} submissionsUsed={submissionsUsed} maxSubmissions={maxSubmissions} /> : problemTab === "Database" ? <WorkspaceDatabasePanel database={prepared.data || database} result={result} loading={prepared.loading} error={prepared.error} onRetry={prepared.retry} prepared={hasSetup} questionName={questionLabel} structureVisible={problem.databaseStructureVisible} questionStartFrom={questionId ? startFrom : undefined} questionKind={questionKind} referenceSchema={referenceSchema} {...(part === "DDL" ? { current: currentDatabase, currentLoading, currentError, onResetCurrent: async () => { await studentApi.resetStudentDatabase(problem.id, { source, context }); resetCurrent(); }, view: databaseView, onViewChange: setDatabaseView, preferCurrent: true } : {})} /> : <>
          <ProblemQuestionContent problem={problem} part={part} questions={currentQuestions} selectedId={questionId} showNavigation={false} onQuestionChange={onQuestionChange} disabled={busy || submissionBusy} />
          {targetTables.length > 0 && <details className="stage-target-dataset" open>
            <summary><TranslatedText>{tr("Target dataset")}</TranslatedText></summary>
            <p className="muted"><TranslatedText>{tr("Recreate these rows with INSERT statements.")}</TranslatedText></p>
            {targetTables.map((table, index) => <section className="workspace-database-table" key={`${table.name}-${index}`}><header><strong>{table.name}</strong></header><DataGrid table={table} /></section>)}
          </details>}
        </>}
      </WorkspacePanePanel>
    </aside>
    <WorkspaceResizeHandle axis="vertical" containerRef={workspaceLayout} value={problemWidth} min={18} max={layout === "Note-taking" ? secondBoundary - 26 : 50} label={tr("Resize problem and editor panes")} controls={`stage-problem-pane-${part} stage-editor-pane-${part}`} onResize={value => updateSizes({ problemWidth: value })} onResizeStart={() => setResizing(true)} onResizeEnd={() => setResizing(false)} onReset={() => updateSizes({ problemWidth: defaults[layout].problemWidth })} />
    <section className={`editor-pane stage-code-panel${submitted ? " is-submission-locked" : ""}`} id={`stage-editor-pane-${part}`} ref={editorPane} style={{ "--editor-pane-height": `${editorHeight}%` } as CSSProperties} aria-label={tr("{value0} work", { value0: part })}>
      <div className="editor-toolbar stage-editor-toolbar">
        <span className="editor-dialect">SQL Server</span>
        <button type="button" className="icon-button" aria-label={tr("Reset {value0} script", { value0: part })} title={readOnly ? tr("Submitted work is read-only") : tr("Reset {value0} script", { value0: part })} disabled={readOnly} onClick={() => setConfirmReset(true)}><RotateCcw size={16} /></button>
        <b className="stage-editor-file" title={questionLabel}>{questionLabel}</b>
        {submitted ? <WorkspaceSubmissionLock /> : readOnly && <small className="muted"><TranslatedText>{tr("View only")}</TranslatedText></small>}
      </div>
      <div className="stage-code"><CodeMirror value={value} height="100%" readOnly={readOnly} editable={!readOnly} extensions={sqlEditorExtensions} onChange={next => { if (!readOnly) onChange(next); }} onUpdate={update => {
        const selection = update.state.selection.main;
        setSelected(update.state.sliceDoc(selection.from, selection.to));
      }} placeholder={tr(placeholder)} aria-label={tr("{value0} editor", { value0: part })} /></div>
      <WorkspaceRunActions part={part}>
        <div className="stage-run-actions">
          <button type="button" className="button" onClick={() => onRun(selected)} disabled={readOnly || busy || !selected.trim()} title={!selected.trim() ? tr("Select SQL text in the editor first") : undefined}><TranslatedText>{tr("Run selected")}</TranslatedText></button>
          <button type="button" className="button primary" onClick={() => onRun()} disabled={readOnly || busy || !value.trim()}><Play size={15} /><TranslatedText>{busy ? tr("Running…") : tr("Run all")}</TranslatedText></button>
        </div>
      </WorkspaceRunActions>
      {outputOpen && <WorkspaceResizeHandle axis="horizontal" containerRef={editorPane} value={editorHeight} min={30} max={75} label={tr("Resize editor and output")} controls={`stage-editor-pane-${part}`} onResize={value => updateSizes({ editorHeight: value })} onResizeStart={() => setResizing(true)} onResizeEnd={() => setResizing(false)} onReset={() => updateSizes({ editorHeight: defaults[layout].editorHeight })} />}
      <WorkspaceOutputPanel open={outputOpen} onOpenChange={setOutputOpen} busy={busy} part={part} questionId={questionId} attempts={submissionAttempts} currentScript={value} onSubmitQuestion={onSubmitQuestion} submitBusy={submissionBusy} />
    </section>
    {confirmReset && !readOnly && <Dialog title={tr("Reset {value0} script?", { value0: part })} onClose={() => setConfirmReset(false)}>
      <p><TranslatedText>{tr("Your current SQL draft will be cleared from this browser.")}</TranslatedText></p>
      <div className="dialog-actions"><button className="button" type="button" onClick={() => setConfirmReset(false)}><TranslatedText>{tr("Cancel")}</TranslatedText></button><button className="button primary" type="button" onClick={() => { if (onReset) onReset(); else onChange(""); setConfirmReset(false); }}><TranslatedText>{tr("Clear script")}</TranslatedText></button></div>
    </Dialog>}
  </div>;
}
