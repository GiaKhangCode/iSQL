import { TranslatedText } from "@/shared/ui/TranslatedText";
import { useLanguage } from "@/shared/context/LanguageContext";
import { useEffect, useMemo, useRef, useState } from "react";
import { useNavigate, useSearchParams } from "react-router-dom";
import { AppHeader } from "@/app/shells/AppHeader";
import { ProblemQuestionNavigation } from "@/shared/components/ProblemQuestionNavigation";
import { useAuth } from "@/app/providers/AuthContext";
import type { Problem, Submission, WorkspacePart } from "@/data/models";
import { studentApi } from "@/shared/api/studentApi";
import type { QueryResult } from "@/shared/types/queryResult";
import { storage } from "@/shared/api/storage";
import { workspaceDraftKey, workspaceParts, stageScriptKey, type StagePart } from "@/shared/lib/workspace/workspaceParts";
import { Dialog } from "@/shared/ui/ui";
import { WorkspaceExamFinishButton, useWorkspaceExam } from "@/features/workspace/components/WorkspaceExamContext";
import { DdlWorkspace } from "@/features/workspace/DdlWorkspace";
import { TriggerWorkspace } from "@/features/workspace/TriggerWorkspace";
import { DmlWorkspace } from "@/features/workspace/DmlWorkspace";
import { SqlWorkspace } from "@/features/workspace/SqlWorkspace";
import { WorkspaceNavigation } from "@/features/workspace/components/WorkspaceNavigation";
import { ProblemWorkspaceLayoutProvider } from "@/features/workspace/components/ProblemWorkspaceLayoutContext";
import type { WorkspaceAttempt } from "@/features/workspace/components/WorkspaceSubmissionPanel";
import { questionReference, workspaceReference } from "@/shared/lib/workspace/workspaceReference";
import { readQuestionDrafts, writeQuestionDrafts } from "@/shared/lib/sql/sqlQuestionDrafts";
import { GlidingTooltipGroup } from "@/shared/ui/GlidingTooltip";
import { readLayouts, workspaceKey } from "@/shared/lib/workspace/workspacePreferences";
import "@/styles/problem-workspace.css";
import "@/styles/workspace-viewport.css";
import { WorkspaceExitDialog } from "@/features/workspace/components/WorkspaceExitDialog";
import { useWorkspaceContextTitle } from "@/features/workspace/useWorkspaceContextTitle";
import { readRunHistory, saveRunHistory, readWorkspaceSelection } from "@/shared/lib/workspace/workspaceRunHistory";
import { notify } from "@/shared/ui/notify";
import { WorkspacePaneTitle, WorkspaceTitleProvider, useWorkspaceTitle } from "@/features/workspace/components/WorkspaceTitle";
import { ArrowLeft } from "lucide-react";
import { WorkspaceHeaderStatus } from "@/features/workspace/components/WorkspaceHeaderStatus";
import { WorkspaceRunSlotProvider } from "@/features/workspace/components/WorkspaceRunSlot";

function readSubmittedWorkspaceScripts(value: string | undefined) {
  if (!value) return null;
  const markers = [...value.matchAll(/(?:^|\r?\n)-- (DDL|DML|SQL|TRIGGER)\r?\n/g)];
  if (!markers.length) return null;
  const scripts = { ddl: "", dml: "", sql: "", trigger: "" };
  markers.forEach((marker, index) => {
    const start = (marker.index ?? 0) + marker[0].length;
    const end = markers[index + 1]?.index ?? value.length;
    const part = marker[1].toLowerCase() as keyof typeof scripts;
    scripts[part] = value.slice(start, end).replace(/\s+$/, "");
  });
  return scripts;
}

function readSavedSubmissionSnapshot(value: string | null) {
  if (!value) return null;
  try {
    const saved = JSON.parse(value);
    if ([saved.ddl, saved.dml, saved.sql].every(script => typeof script === "string")) return {...saved,trigger:typeof saved.trigger === "string" ? saved.trigger : ""} as { ddl: string; dml: string; sql: string; trigger:string };
  } catch { /* Ignore invalid local snapshots and restore from submission history. */ }
  return null;
}

export function ProblemWorkspace({ problem }: { problem: Problem }) {
  const { t: tr } = useLanguage("workspace");
  const { session } = useAuth();
  const exam = useWorkspaceExam();
  const navigate = useNavigate();
  const [confirmSubmit, setConfirmSubmit] = useState(false);
  const [confirmExit, setConfirmExit] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [questionSubmitting,setQuestionSubmitting] = useState(false);
  const [reviewMode, setReviewMode] = useState(false);
  const [editedSinceSubmission, setEditedSinceSubmission] = useState(false);
  const [historyLoaded, setHistoryLoaded] = useState(Boolean(exam?.enabled));
  const [submissionSignal, setSubmissionSignal] = useState(0);
  const [submissionReceipt, setSubmissionReceipt] = useState<QueryResult | null>(null);
  const [historySubmissionsUsed, setHistorySubmissionsUsed] = useState(0);
  const submissionCounter = useRef(0);
  const [params] = useSearchParams();
  const source = params.get("source") === "Assignments" ? "Assignments" : params.get("source") === "ExamSession" ? "ExamSession" : "Practice";
  const context = params.get("context") || source;
  const draftKey = workspaceDraftKey(session?.id || "anonymous", problem.id, context);
  const [savedSelection] = useState(() => readWorkspaceSelection(draftKey + ":selection"));
  const [submissionAttempts, setSubmissionAttempts] = useState<WorkspaceAttempt[]>(() => [...new Map([...readRunHistory(draftKey + ":runs"),...readRunHistory(draftKey + ":SQL:runs")].map(attempt => [attempt.id,attempt])).values()].sort((a,b) => b.id-a.id));
  useEffect(() => { saveRunHistory(draftKey + ":runs",submissionAttempts); },[draftKey,submissionAttempts]);
  const parts = workspaceParts(problem.workspaceParts);
  const sqlOnly = parts.length === 1 && parts[0] === "SQL";
  const [runSlot, setRunSlot] = useState<HTMLElement | null>(null);
  const activityTitle = useWorkspaceContextTitle(source, context, params.get("contextTitle"), !sqlOnly);
  const [focus, setFocus] = useState(false);
  const [active, setActive] = useState<WorkspacePart>(parts.includes(savedSelection.part as WorkspacePart) ? savedSelection.part as WorkspacePart : parts[0] || "SQL");
  const [selectedSolutionId, setSelectedSolutionId] = useState(problem.sqlChecks?.find(question => question.id === savedSelection.SQL)?.id || problem.sqlChecks?.[0]?.id || "");
  const [selectedStageQuestionIds, setSelectedStageQuestionIds] = useState({ TRIGGER: problem.triggerChecks?.find(question=>question.id===savedSelection.TRIGGER)?.id || problem.triggerChecks?.[0]?.id || "", DDL: problem.ddlChecks?.find(question => question.id === savedSelection.DDL)?.id || problem.ddlChecks?.[0]?.id || "", DML: problem.dmlChecks?.find(question => question.id === savedSelection.DML)?.id || problem.dmlChecks?.[0]?.id || "" });
  useEffect(() => { storage.set(draftKey + ":selection",JSON.stringify({part:active,...selectedStageQuestionIds,SQL:selectedSolutionId})); },[draftKey,active,selectedStageQuestionIds,selectedSolutionId]);
  const [sqlBusy, setSqlBusy] = useState(false);
  const layoutKey = workspaceKey(session?.id || "anonymous", "preferences", "layouts");
  const [preferences, setPreferences] = useState<ReturnType<typeof readLayouts>>(() => ({ ...readLayouts(layoutKey), layout: "Default" }));
  const [scripts, setScripts] = useState(() => {
    const restored = exam?.draft(problem.id);
    if (exam?.enabled || restored) return { ddl: restored?.ddl || "", dml: restored?.dml || "", trigger:restored?.trigger || "" };
    try {
      const saved = JSON.parse(storage.get(draftKey) || "{}");
      return { trigger:typeof saved.trigger === "string" ? saved.trigger : "", ddl: typeof saved.ddl === "string" ? saved.ddl : "", dml: typeof saved.dml === "string" ? saved.dml : "" };
    } catch { return { ddl: "", dml: "", trigger:"" }; }
  });
  const [results, setResults] = useState<Partial<Record<WorkspacePart, QueryResult>>>({});
  const [errors, setErrors] = useState<Partial<Record<WorkspacePart, string>>>({});
  const [busy, setBusy] = useState(false);
  const databaseView = useMemo(() => workspaceReference(problem), [problem]);
  const stageChecks = (part: StagePart) => part === "DDL" ? problem.ddlChecks || [] : part === "TRIGGER" ? problem.triggerChecks || [] : problem.dmlChecks || [];
  const stageQuestionId = (part: StagePart) => selectedStageQuestionIds[part] || stageChecks(part)[0]?.id || "";
  const stageDraft = (part: StagePart, id: string) => {
    const key = stageScriptKey(part);
    const checks = stageChecks(part);
    if (!checks.length) return scripts[key];
    return readQuestionDrafts(scripts[key], checks.map(check => check.id), { keepTrailing: true })[id] || "";
  };
  function updateStageDraft(part: StagePart, id: string, value: string) {
    const key = stageScriptKey(part);
    const checks = stageChecks(part);
    const ids = checks.map(check => check.id);
    if (!ids.length) { updateScripts({ [key]: value }); return; }
    const answers = readQuestionDrafts(scripts[key], ids, { keepTrailing: true });
    updateScripts({ [key]: writeQuestionDrafts({ ...answers, [id]: value }, ids) });
  }
  const stageDatabase = (part: StagePart) => {
    const question = stageChecks(part).find(question => question.id === stageQuestionId(part));
    return questionReference(databaseView, question?.kind === "schema" ? "structure" : question?.startFrom);
  };
  useEffect(() => { storage.set(draftKey, JSON.stringify(scripts)); }, [draftKey, scripts]);
  useEffect(() => { storage.set(layoutKey, JSON.stringify(preferences)); }, [layoutKey, preferences]);
  const syncDraft = exam?.update;
  useEffect(() => { syncDraft?.(problem.id, scripts); }, [syncDraft, problem.id]);
  function updateScripts(change: Partial<typeof scripts>) {
    if (reviewMode || submitting || (!historyLoaded && !exam?.enabled)) return;
    setEditedSinceSubmission(true);
    setScripts(value => ({ ...value, ...change }));
    exam?.update(problem.id, change);
  }
  const activityClosed = Boolean(exam?.closed);
  const submissionsUsed = submissionReceipt?.submissionsUsed ?? exam?.submissionCounts[problem.id] ?? historySubmissionsUsed;
  const maxSubmissions = submissionReceipt?.maxSubmissions ?? exam?.maxSubmissions ?? null;
  const submissionLimitReached = maxSubmissions != null && submissionsUsed >= maxSubmissions;
  const isReadOnly = reviewMode || submitting || activityClosed || (!historyLoaded && !exam?.enabled);
  const exitSaved = reviewMode || (!editedSinceSubmission && submissionAttempts.some(attempt => attempt.kind === "submit"));
  const exitBlockedReason = activityClosed ? "Assignment deadline has passed" : submissionLimitReached ? "Submission limit reached" : source === "ExamSession" && params.get("closed") === "1" ? "Exam session submissions are closed" : undefined;
  const exitDestination = source === "ExamSession" ? "/examinations" : source === "Assignments" ? `/assignments/work/${encodeURIComponent(context)}` : "/dashboard";
  useEffect(() => {
    if (exam?.enabled || sqlOnly) return;
    let activeRequest = true;
    if (typeof studentApi.getSubmissions !== "function") {
      setHistoryLoaded(true);
      return () => { activeRequest = false; };
    }
    studentApi.getSubmissions({ source, includeQuestionUpdates: true }).then((response) => {
      if (!activeRequest || !Array.isArray(response)) return;
      const matching = (response as Submission[])
        .filter(item => item.problemId === problem.id && item.context === context && [source, source === "Assignments" ? "Assignment" : source === "ExamSession" ? "ExamSession" : "Practice"].includes(item.source))
        .sort((a, b) => Date.parse(b.submittedAt) - Date.parse(a.submittedAt));
      setHistorySubmissionsUsed(matching.filter(item => !item.questionOnly).length);
      if (matching.length) {
        if (!matching[0].questionOnly) {
        const snapshot = readSavedSubmissionSnapshot(storage.get(draftKey + ":submitted"));
        const expectedSubmissionText = snapshot && parts.map(part => `-- ${part}\n${snapshot[part === "SQL" ? "sql" : stageScriptKey(part)]}`).join("\n\n");
        const submittedScripts = snapshot && matching[0].query === expectedSubmissionText ? snapshot : readSubmittedWorkspaceScripts(matching[0].query);
        if (submittedScripts) {
          setScripts({ ddl: submittedScripts.ddl, dml: submittedScripts.dml, trigger:submittedScripts.trigger });
          storage.set(draftKey + ":SQL", submittedScripts.sql);
          setEditedSinceSubmission(false);
        }
        setReviewMode(true);
        setSubmissionSignal(value => value + 1);
        }
        setSubmissionAttempts(previous => [...matching.slice(0, 100).map((item, index) => ({
          id: matching.length - index,
          kind: "submit" as const,
          part:item.questionOnly ? item.questionPart : undefined,
          script:item.submittedScript || item.query,
          result: {
            questionId:item.questionOnly ? item.questionId : undefined,
            questionOnly:item.questionOnly,
            status: item.result,
            message: item.feedback || (item.result === "Pending review" ? "Your work has been submitted for teacher review." : "Previously submitted work."),
            score: item.score,
            scoreVisible: item.scoreVisible,
            maxScore: item.maxScore,
            sections: item.sections,
          },
        })), ...previous.filter(attempt => attempt.kind === "run")]);
      }
    }).catch(() => {}).finally(() => { if (activeRequest) setHistoryLoaded(true); });
    return () => { activeRequest = false; };
  }, [problem.id, source, context, exam?.enabled, draftKey, sqlOnly]);
  async function submitQuestion(attempt: WorkspaceAttempt) {
    if (questionSubmitting || submitting || isReadOnly || !attempt.part || !attempt.result.questionId || !attempt.script?.trim()) return;
    setQuestionSubmitting(true);
    try {
      const result=await studentApi.submitWorkspaceQuestion(problem.id,{part:attempt.part,solutionId:attempt.result.questionId,script:attempt.script,source,context});
      if(result.alreadySubmitted){notify.info(tr("This question was already submitted."));return result;}
      setSubmissionAttempts(previous => [{id:Date.now() + ++submissionCounter.current,kind:"submit" as const,part:attempt.part,result,script:attempt.script},...previous].slice(0,200));
      setSubmissionSignal(value => value + 1);
      notify.success(tr("Question submitted"));
    } catch(error) {notify.fail(tr("Unable to submit this question."), error, tr);}
    finally {setQuestionSubmitting(false);}
  }
  async function submitWork(exitAfter = false) {
    if (submitting || questionSubmitting || busy || sqlBusy) return;
    setSubmitting(true); 
    try {
      const submittedQuery = storage.get(draftKey + ":SQL") || "";
      const result = await studentApi.submitWorkspace(problem.id, { part: parts[0] || "SQL", ...scripts, query: submittedQuery, source, context });
      storage.set(draftKey + ":submitted", JSON.stringify({ ...scripts, sql: submittedQuery }));
      setSubmissionReceipt(result);
      setHistorySubmissionsUsed(previous => previous + 1);
      setReviewMode(true);
      setEditedSinceSubmission(false);
      setSubmissionAttempts(previous => [{ id: Date.now() + ++submissionCounter.current, kind: "submit" as const, result }, ...previous].slice(0, 60));
      setSubmissionSignal(value => value + 1);
      setConfirmSubmit(false);
      notify.success(tr("Submission received"));
      if (exitAfter) { setConfirmExit(false); navigate(exitDestination); }
      else if (result.id) navigate(`/submissions?submission=${encodeURIComponent(result.id)}`);
    } catch (error) { notify.fail(tr("Could not submit your work."), error, tr); }
    finally { setSubmitting(false); }
  }
  async function run(part: StagePart, selection?: string, questionId?: string) {
    if (busy || isReadOnly) return;
    setBusy(true); setErrors(previous => ({ ...previous, [part]: "" }));
    const script = selection ?? (questionId ? stageDraft(part, questionId) : scripts[stageScriptKey(part)]);
    try {
      const response = await studentApi.runWorkspace(problem.id, { part, solutionId: questionId || undefined, ddl: part === "DDL" ? script : "", dml: part === "DML" ? script : "", trigger:part === "TRIGGER" ? script : "", query: "", source, context });
      const result: QueryResult = { ...response, part, questionId: questionId || response.questionId, ...(part === "DDL" ? {
        actualSchema: response.schemaTables || [], expectedSchema: problem.databaseStructureVisible !== false ? databaseView.schemaTables : undefined,
      } : { actualTables: response.tables || [], expectedTables: databaseView.tables }) };
      setResults(previous => ({ ...previous, [part]: result }));
      setSubmissionAttempts(previous => [{ id: Date.now() + ++submissionCounter.current, kind: "run" as const, part, result, script }, ...previous].slice(0, 60));
    } catch (error) {
      const result = { status: "Error", message: error instanceof Error ? error.message : "Unable to run your work.", part, questionId };
      setResults(previous => ({ ...previous, [part]: result }));
      setSubmissionAttempts(previous => [{ id: Date.now() + ++submissionCounter.current, kind: "run" as const, part, result, script }, ...previous].slice(0, 60));
    } finally { setBusy(false); }
  }
  const questionGroups = {DDL:problem.ddlChecks || [],DML:problem.dmlChecks || [],SQL:problem.sqlChecks || [],TRIGGER:problem.triggerChecks || []};
  const isQuestionDone = (part: WorkspacePart, id: string) => {
    if (part === "SQL") {
      const rawSql = storage.get(draftKey + ":SQL") || "";
      const checks = problem.sqlChecks || [];
      if (checks.length > 1 || rawSql.startsWith("-- solution:")) {
        const answers = readQuestionDrafts(rawSql, checks.map(c => c.id));
        if (answers[id]?.trim().length > 0) return true;
      } else if (rawSql.trim().length > 0) {
        return true;
      }
    } else {
      if (stageDraft(part as StagePart, id)?.trim().length > 0) return true;
    }
    return submissionAttempts.some(a => a.result?.questionId === id && a.result?.status !== "Error");
  };
  function renderStage(part:StagePart) {
    const id=stageQuestionId(part);
    const question=stageChecks(part).find(question=>question.id===id);
    const Editor=part==="DDL"?DdlWorkspace:part==="DML"?DmlWorkspace:TriggerWorkspace;
    return <Editor database={stageDatabase(part)} problem={problem} value={stageDraft(part,id)} questionId={id}
      questionDescription={question?.description} questionStartFrom={question?.startFrom}
      onQuestionChange={id=>{setSelectedStageQuestionIds(previous=>({...previous,[part]:id}));setResults(previous=>({...previous,[part]:undefined}));}}
      questions={stageChecks(part)} questionGroups={questionGroups} selectedQuestionIds={{...selectedStageQuestionIds,SQL:selectedSolutionId}}
      onSubmitQuestion={!isReadOnly?submitQuestion:undefined}
      onChange={value=>updateStageDraft(part,id,value)} onReset={()=>{updateStageDraft(part,id,"");setResults(previous=>({...previous,[part]:undefined}));setErrors(previous=>({...previous,[part]:""}));}}
      onRun={selection=>run(part,selection,stageChecks(part).length?id:undefined)} busy={busy}
      result={results[part]?.questionId===id || !stageChecks(part).length?results[part]:undefined} error={errors[part]}
      readOnly={isReadOnly} submitted={reviewMode} submissionAttempts={submissionAttempts} submissionBusy={submitting || questionSubmitting}
      submissionSignal={submissionSignal} submissionsUsed={submissionsUsed} maxSubmissions={maxSubmissions}/>;
  }
  if (sqlOnly) return <SqlWorkspace problem={problem} />;
  if (!parts.length) return <p role="alert"><TranslatedText>{tr("No practice sections have been configured.")}</TranslatedText></p>;
  return <WorkspaceTitleProvider value={activityTitle}><WorkspaceRunSlotProvider value={{ node: runSlot, activePart: active }}><ProblemWorkspaceLayoutProvider value={{ preferences, setPreferences, focus, setFocus }}><div className="problem-workspace">
    <AppHeader
      workspaceProblemNavigation={<ProblemQuestionNavigation header part={active} questions={questionGroups[active]} selectedId={active === "SQL" ? selectedSolutionId : stageQuestionId(active)} disabled={busy || sqlBusy || submitting || questionSubmitting} variantCode={problem.assignedVariantCode} onChange={id => {
        if (active === "SQL") setSelectedSolutionId(id);
        else {setSelectedStageQuestionIds(previous=>({...previous,[active]:id}));setResults(previous=>({...previous,[active]:undefined}));}
      }} />}
      workspace={{ title: problem.title, number: problem.number, topic: problem.topic || "SQL", source, context: activityTitle || problem.title }}
      onWorkspaceExit={!exam?.enabled ? () => { if (!(busy || sqlBusy || submitting || questionSubmitting || !historyLoaded)) setConfirmExit(true); } : undefined}
      workspaceExit={!exam?.enabled ? <button type="button" className="button workspace-exit-button" title={tr("Submit your current work before leaving the workspace.")} disabled={busy || sqlBusy || submitting || questionSubmitting || !historyLoaded} onClick={() => setConfirmExit(true)}><ArrowLeft size={15} aria-hidden="true" /><TranslatedText>{tr("Exit")}</TranslatedText></button> : undefined}
      workspaceActions={<><WorkspaceNavigation parts={parts} active={active} onChange={setActive} questionGroups={questionGroups} selectedQuestionIds={{...selectedStageQuestionIds,SQL:selectedSolutionId}} onQuestionChange={(part, id) => {
        if (part === "SQL") setSelectedSolutionId(id);
        else {setSelectedStageQuestionIds(previous=>({...previous,[part]:id}));setResults(previous=>({...previous,[part]:undefined}));}
      }} isQuestionDone={isQuestionDone} disabled={busy || sqlBusy || submitting || questionSubmitting} /><div className="workspace-run-slot" ref={setRunSlot} /><WorkspaceHeaderStatus submissionsUsed={submissionsUsed} maxSubmissions={maxSubmissions} /><WorkspaceExamFinishButton />{!exam?.enabled && <GlidingTooltipGroup label={tr("Workspace actions")} role="group" multiline className="workspace-action-tooltips"><button data-tooltip={tr(activityClosed ? "Assignment deadline has passed" : reviewMode ? "Continue editing your submitted scripts. Submit again to update your work." : "Submit DDL, DML and DQL together. Results are shown separately.")} className={`button ${reviewMode ? "" : "primary"} workspace-submit-exit`} disabled={busy || sqlBusy || submitting || questionSubmitting || activityClosed || (!reviewMode && submissionLimitReached) || !historyLoaded} title={activityClosed ? tr("Assignment deadline has passed") : submissionLimitReached && !reviewMode ? tr("Submission limit reached") : undefined} onClick={() => reviewMode ? setReviewMode(false) : setConfirmSubmit(true)}><TranslatedText>{tr(activityClosed ? "Submissions closed" : reviewMode ? "Edit again" : "Submit")}</TranslatedText></button></GlidingTooltipGroup>}</>}
    />
    <main id="main-content" className="problem-workspace-body">
    {parts.map(part => <div key={part} id={`workspace-stage-${part}`} className="problem-workspace-stage" hidden={active !== part}>
      {part !== "SQL" ? renderStage(part)
        : <SqlWorkspace onRunAttempt={attempt => setSubmissionAttempts(previous => [attempt,...previous].slice(0,200))} onSubmitQuestion={!isReadOnly ? submitQuestion : undefined} selectedQuestionIds={{...selectedStageQuestionIds,SQL:selectedSolutionId}} problem={problem} selectedSolutionId={selectedSolutionId} onSolutionChange={setSelectedSolutionId} onBusyChange={setSqlBusy} embedded setupScripts={scripts} database={databaseView} readOnly={isReadOnly} submitted={reviewMode} onUserEdit={() => setEditedSinceSubmission(true)} submissionAttempts={submissionAttempts} submissionBusy={submitting || questionSubmitting} submissionSignal={submissionSignal} submissionsUsed={submissionsUsed} maxSubmissions={maxSubmissions} />}
    </div>)}
    </main>
    {confirmSubmit && <Dialog title={tr("Submit work?")} onClose={() => { if (!submitting) setConfirmSubmit(false); }}><p><TranslatedText>{tr("Submit all enabled sections of this problem for automatic grading?")}</TranslatedText></p><div className="workspace-finish-actions"><button className="button" disabled={submitting} onClick={() => setConfirmSubmit(false)}><TranslatedText>{tr("Cancel")}</TranslatedText></button><button className="button primary" disabled={submitting} onClick={() => void submitWork()}><TranslatedText>{tr(submitting ? "Submitting…" : "Submit")}</TranslatedText></button></div></Dialog>}
    {confirmExit && <WorkspaceExitDialog saved={exitSaved} blockedReason={exitSaved ? undefined : exitBlockedReason} busy={submitting} onClose={() => setConfirmExit(false)} onLeaveDraft={() => navigate(exitDestination)} onConfirm={() => { if (exitSaved || exitBlockedReason) navigate(exitDestination); else void submitWork(true); }} />}
  </div></ProblemWorkspaceLayoutProvider></WorkspaceRunSlotProvider></WorkspaceTitleProvider>;
}
