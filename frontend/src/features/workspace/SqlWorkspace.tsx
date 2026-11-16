import {usePreparedDatabase} from "@/shared/components/usePreparedDatabase";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import { useLanguage } from "@/shared/context/LanguageContext";
import { readQuestionDrafts, writeQuestionDrafts } from "@/shared/lib/sql/sqlQuestionDrafts";
import { workspaceDraftKey } from "@/shared/lib/workspace/workspaceParts";
import { useEffect, useMemo, useRef, useState, type CSSProperties, type KeyboardEvent as ReactKeyboardEvent, type PointerEvent as ReactPointerEvent } from "react";
import { Link, useNavigate, useSearchParams } from "react-router-dom";
import CodeMirror from "@uiw/react-codemirror";
import { EditorView } from "@codemirror/view";
import { Star, RotateCcw, Maximize2, Minimize2, Braces } from "lucide-react";
import { indentRange } from "@codemirror/language";
import { sqlEditorExtensions } from "@/shared/components/sqlEditorExtensions";
import { useAuth } from "@/app/providers/AuthContext";
import { clamp as clampPaneSize, defaults, readLayouts, workspaceKey, type PaneSizes } from "@/shared/lib/workspace/workspacePreferences";
import "@/styles/workspace.css";
import "@/styles/workspace-viewport.css";
import { AppHeader } from "@/app/shells/AppHeader";
import { ProblemQuestionNavigation } from "@/shared/components/ProblemQuestionNavigation";
import { WorkspaceNavigation } from "@/features/workspace/components/WorkspaceNavigation";
import { DataGrid, Dialog, Loading } from "@/shared/ui/ui";
import { ProblemQuestionContent } from "@/shared/components/ProblemQuestionContent";
import { ProblemMarkdown } from "@/shared/components/ProblemMarkdown";
import { studentApi } from "@/shared/api/studentApi";
import type { QueryResult } from "@/shared/types/queryResult";
import { storage } from "@/shared/api/storage";
import type { Problem, Submission, WorkspacePart } from "@/data/models";
import { workspaceReference } from "@/shared/lib/workspace/workspaceReference";
import { GlidingTooltipGroup } from "@/shared/ui/GlidingTooltip";
import { AlertCircle, Play, LayoutGrid } from "lucide-react";
import { type ContestWorkspaceController } from "@/features/workspace/useContestWorkspace";
import type { Contest } from "@/data/models";
import { useProblemWorkspaceLayout } from "@/features/workspace/components/ProblemWorkspaceLayoutContext";
import { WorkspaceResizeHandle } from "@/features/workspace/components/WorkspaceResizeHandle";
import { WorkspaceDatabasePanel, type DatabaseView, type WorkspaceDatabaseState } from "@/shared/components/WorkspaceDatabasePanel";
import { useCurrentDatabase } from "@/shared/components/useCurrentDatabase";
import { WorkspaceSubmissionPanel, type WorkspaceAttempt } from "@/features/workspace/components/WorkspaceSubmissionPanel";
import { readRunHistory, saveRunHistory, readWorkspaceSelection } from "@/shared/lib/workspace/workspaceRunHistory";
import { useWorkspacePane } from "@/features/workspace/components/useWorkspacePane";

import { WorkspaceEditorStatus } from "@/features/workspace/components/WorkspaceEditorStatus";
import { WorkspacePanePanel, WorkspacePaneTabs, type WorkspacePane } from "@/features/workspace/components/WorkspacePaneTabs";
import "@/features/workspace/components/WorkspaceReadingPane.css";
import { WorkspaceExitDialog } from "@/features/workspace/components/WorkspaceExitDialog";
import { WorkspaceSubmissionLock } from "@/features/workspace/components/WorkspaceSubmissionLock";
import { useWorkspaceContextTitle } from "@/features/workspace/useWorkspaceContextTitle";
import { useWorkspaceExam, WorkspaceExamFinishButton } from "@/features/workspace/components/WorkspaceExamContext";

const DEFAULT_EDITOR_HEIGHT = 58;

const MIN_PROBLEM_WIDTH = 18;

const MAX_PROBLEM_WIDTH = 50;

const MIN_EDITOR_HEIGHT = 30;

const MAX_EDITOR_HEIGHT = 75;


export function SqlWorkspace({ problem, selectedSolutionId, onSolutionChange, onBusyChange, embedded = false, setupScripts, database, readOnly: parentReadOnly = false, submitted: parentSubmitted = false, onUserEdit, submissionAttempts: externalAttempts, submissionBusy = false, submissionNotice = "", submissionSignal = 0, submissionsUsed: parentSubmissionsUsed, maxSubmissions: parentMaxSubmissions, onRunAttempt, onSubmitQuestion, selectedQuestionIds }: {
  selectedSolutionId?: string; onSolutionChange?: (id: string) => void; onBusyChange?: (busy: boolean) => void;
  problem: Problem; database?: WorkspaceDatabaseState; embedded?: boolean; setupScripts?: { ddl: string; dml: string };
  readOnly?: boolean; submitted?: boolean; onUserEdit?: () => void; submissionAttempts?: WorkspaceAttempt[]; submissionBusy?: boolean; submissionNotice?: string;
  submissionSignal?: number; submissionsUsed?: number; maxSubmissions?: number | null;
  onRunAttempt?: (attempt:WorkspaceAttempt) => void; onSubmitQuestion?: (attempt:WorkspaceAttempt) => void | QueryResult | Promise<void | QueryResult>;
  selectedQuestionIds?: Partial<Record<WorkspacePart,string>>;
}) {
  const { t: tr } = useLanguage("workspace");
  const { session } = useAuth();
  const exam = useWorkspaceExam();
  const layoutKey = workspaceKey(session?.id || "anonymous", "preferences", "layouts");
  const notesKey = workspaceKey(session?.id || "anonymous", problem.id, "notes");
  const sharedLayout = useProblemWorkspaceLayout();
  const [localPreferences, setLocalPreferences] = useState<ReturnType<typeof readLayouts>>(() => ({ ...readLayouts(layoutKey), layout: "Default" }));
  const preferences = sharedLayout?.preferences ?? localPreferences;
  const setPreferences = sharedLayout?.setPreferences ?? setLocalPreferences;
  const layout = preferences.layout;
  const [localFocus] = useState(false);
  const focus = sharedLayout?.focus ?? localFocus;
  const [focusHeight, setFocusHeight] = useState(DEFAULT_EDITOR_HEIGHT);
  const [notes, setNotes] = useState(() => storage.get(notesKey) || "");
  const { problemWidth, secondBoundary } = preferences.sizes[layout];
  const editorHeight = focus ? focusHeight : preferences.sizes[layout].editorHeight;
  const threeColumns = !focus && (layout === "Leet" || layout === "Debug");
  function updateSizes(change: Partial<PaneSizes>) {
    setPreferences(value => ({ ...value, sizes: { ...value.sizes, [value.layout]: { ...value.sizes[value.layout], ...change } } }));
  }
  function setProblemWidth(value: number | ((previous: number) => number)) {
    updateSizes({ problemWidth: typeof value === "function" ? value(problemWidth) : value });
  }
  function setEditorHeight(value: number | ((previous: number) => number)) {
    const next = typeof value === "function" ? value(editorHeight) : value;
    if (focus) setFocusHeight(next); else updateSizes({ editorHeight: next });
  }
  const [params] = useSearchParams();
  const source: Submission["source"] =
    params.get("source") === "Assignments"
      ? "Assignments"
      : params.get("source") === "Contests"
        ? "Contests"
        : "Practice";
  const context = params.get("context") || source;
  const contextTitle = useWorkspaceContextTitle(source, context, params.get("contextTitle"), !embedded);
  const sqlDraftKey = workspaceDraftKey(session?.id || "anonymous", problem.id, context) + ":SQL";
  const contestMode = source === "Contests";
  const navigate = useNavigate();
  const contestId = params.get("contest") || (contestMode ? context : "");
  useEffect(() => {
    if (!contestMode || !contestId) return;
    let cancelled = false;
    studentApi.getContests().then((contests) => {
      if (!cancelled && contests.find((item) => item.id === contestId)?.examType === "comprehensive") {
        navigate(`/contests/${encodeURIComponent(contestId)}/exam`, { replace: true });
      }
    }).catch(() => {});
    return () => { cancelled = true; };
  }, [contestMode, contestId, navigate]);
  const contestClosed = contestMode && params.get("closed") === "1";
  const [favorite, setFavorite] = useState(false);
  const [favoriteBusy, setFavoriteBusy] = useState(false);
  const [expanded, setExpanded] = useState(false);
  const [resizing, setResizing] = useState<"vertical" | "horizontal" | "secondary" | null>(null);
  const [cursor, setCursor] = useState({ line: 1, column: 1 });
  const editor = useRef<EditorView | null>(null);
  const workspaceLayout = useRef<HTMLDivElement | null>(null);
  const editorResults = useRef<HTMLDivElement | null>(null);
  const activeResize = useRef<"vertical" | "horizontal" | "secondary" | null>(null);
  const expandTrigger = useRef<HTMLButtonElement>(null);
  const [allCode, setAllCode] = useState(
    () => (exam?.draft(problem.id)?.query ?? (exam?.enabled ? "" : undefined)) ?? storage.get(sqlDraftKey) ?? (!setupScripts && source === "Practice" ? studentApi.getDraft(problem.id) : undefined) ?? (problem.draft || ""),
  );
  const checks = problem.sqlChecks || [];
  const selectionKey = workspaceDraftKey(session?.id || "anonymous",problem.id,context) + ":selection";
  const [localQuestionId, setLocalQuestionId] = useState(() => checks.find(check => check.id === readWorkspaceSelection(selectionKey).SQL)?.id || checks[0]?.id || "");
  const selectedQuestionId = selectedSolutionId ?? localQuestionId;
  const setSelectedQuestionId = onSolutionChange ?? setLocalQuestionId;
  const question = checks.find(check => check.id === selectedQuestionId) || checks[0];
  const questionLabel = tr("Question {value0} {value1}",{value0:Math.max(0,checks.findIndex(check => check.id === question?.id)) + 1,value1:"DQL"});
  useEffect(() => { if (!embedded) storage.set(selectionKey,JSON.stringify({...readWorkspaceSelection(selectionKey),SQL:question?.id || "",part:"SQL"})); },[selectionKey,question?.id,embedded]);
  const multiQuestion = checks.length > 1 || allCode.startsWith("-- solution:");
  const answers = readQuestionDrafts(allCode, checks.map(check => check.id));
  const submissionCode = checks.length > 0 && multiQuestion ? writeQuestionDrafts(answers, checks.map(check => check.id)) : allCode;
  const code = multiQuestion && question ? answers[question.id] || "" : allCode;
  function setCode(value: string) {
    setAllCode(previous => multiQuestion && question
      ? writeQuestionDrafts({ ...readQuestionDrafts(previous, checks.map(check => check.id)), [question.id]: value }, checks.map(check => check.id))
      : value);
  }
  const syncDraft = exam?.update;
  useEffect(() => { syncDraft?.(problem.id, { query: submissionCode }); }, [syncDraft, problem.id, submissionCode]);
  const [selected, setSelected] = useState("");
  const [problemTab, setProblemTab] = useWorkspacePane(workspaceDraftKey(session?.id || "anonymous",problem.id,context) + ":pane:SQL");
  const [resultTab, setResultTab] = useState("Run result");
  const [mobileTab, setMobileTab] = useState("Problem");
  const [busy, setBusy] = useState(false);
  useEffect(() => { onBusyChange?.(busy); }, [busy, onBusyChange]);
  useEffect(() => () => onBusyChange?.(false), [onBusyChange]);
  const [attempts, setAttempts] = useState<WorkspaceAttempt[]>(() => embedded ? [] : readRunHistory(sqlDraftKey + ":runs"));
  useEffect(() => {if (!embedded) saveRunHistory(sqlDraftKey + ":runs",attempts);},[sqlDraftKey,attempts,embedded]);
  const [questionSubmitting,setQuestionSubmitting] = useState(false);
  const attemptCounter = useRef(0);
  const [result, setResult] = useState<QueryResult | null>(null);
  const [lastSubmit, setLastSubmit] = useState<QueryResult | null>(null);
  const [reviewMode, setReviewMode] = useState(false);
  const [hasSubmitted, setHasSubmitted] = useState(false);
  const [editedSinceSubmission, setEditedSinceSubmission] = useState(false);
  const [historyLoaded, setHistoryLoaded] = useState(Boolean(embedded || exam?.enabled));
  const [historySubmissionsUsed, setHistorySubmissionsUsed] = useState(0);
  const [confirmExit, setConfirmExit] = useState(false);
  const [exitError, setExitError] = useState("");
  const activityClosed = Boolean(exam?.closed);
  const submissionsUsed = parentSubmissionsUsed ?? lastSubmit?.submissionsUsed ?? exam?.submissionCounts[problem.id] ?? historySubmissionsUsed;
  const maxSubmissions = parentMaxSubmissions ?? lastSubmit?.maxSubmissions ?? exam?.maxSubmissions ?? null;
  const submissionLimitReached = maxSubmissions != null && submissionsUsed >= maxSubmissions;
  const isSubmitted = parentSubmitted || reviewMode;
  const isReadOnly = parentReadOnly || reviewMode || activityClosed || (busy && resultTab === "Submissions") || (!embedded && !historyLoaded && !exam?.enabled);
  const exitSaved = reviewMode || (hasSubmitted && !editedSinceSubmission);
  const exitBlockedReason = activityClosed ? "Assignment deadline has passed" : submissionLimitReached ? "Submission limit reached" : contestClosed ? "Contest submissions are closed" : undefined;
  const exitDestination = source === "Contests" ? `/contests/${encodeURIComponent(context)}` : source === "Assignments" ? `/assignments/work/${encodeURIComponent(context)}` : "/dashboard";
  const [confirmResetQuery, setConfirmResetQuery] = useState(false);
  const [notice, setNotice] = useState("");
  const previousQuestionId = useRef(question?.id);
  useEffect(() => {
    if (previousQuestionId.current === question?.id) return;
    previousQuestionId.current = question?.id;
    setSelected(""); setResult(null); setNotice(""); setProblemTab("Description");
  }, [question?.id]);
  useEffect(() => {
    if (focus) { setExpanded(false); setMobileTab("SQL"); }
  }, [focus]);
  useEffect(() => { if (layout === "Debug") setProblemTab("Database"); }, [layout]);
  const alive = useRef(true);
  useEffect(() => {
    alive.current = true;
    return () => {
      alive.current = false;
    };
  }, []);
  useEffect(() => {
    storage.set(sqlDraftKey, submissionCode);
    if (!setupScripts && source === "Practice") studentApi.saveDraft(problem.id, submissionCode);
  }, [submissionCode, problem.id, sqlDraftKey, Boolean(setupScripts)]);
  useEffect(() => { if (!sharedLayout) storage.set(layoutKey, JSON.stringify(preferences)); }, [layoutKey, preferences, sharedLayout]);
  useEffect(() => {
    const node = workspaceLayout.current;
    if (!node) return;
    const observer = new ResizeObserver(() => editor.current?.requestMeasure());
    const editorNode = node.querySelector(".sql-editor");
    if (editorNode) observer.observe(editorNode);
    return () => observer.disconnect();
  }, []);
  useEffect(() => {
    let active = true;
    studentApi.getPreferences().then((preferences) => {
      if (active) setFavorite(preferences.favorites.includes(problem.id));
    }).catch(() => { if (active) setNotice("Could not load saved problems."); });
    return () => { active = false; };
  }, [problem.id]);
  useEffect(() => {
    if (embedded || exam?.enabled) return;
    let active = true;
    if (typeof studentApi.getSubmissions !== "function") {
      setHistoryLoaded(true);
      return () => { active = false; };
    }
    studentApi.getSubmissions({ source, includeQuestionUpdates: true }).then((response) => {
      if (!active || !Array.isArray(response)) return;
      const matching = (response as Submission[])
        .filter(item => item.problemId === problem.id && item.context === context && [source, source === "Assignments" ? "Assignment" : source === "Contests" ? "Contest" : "Practice"].includes(item.source))
        .sort((a, b) => Date.parse(b.submittedAt) - Date.parse(a.submittedAt));
      setHistorySubmissionsUsed(matching.filter(item => !item.questionOnly).length);
      if (matching.length) {
        const latest = matching[0];
        if (!latest.questionOnly) {
        setReviewMode(true);
        setHasSubmitted(true);
        setEditedSinceSubmission(false);
        setProblemTab("Submission");
        setLastSubmit({
          status: latest.result,
          message: latest.feedback || (latest.result === "Pending review" ? "Your work has been submitted for teacher review." : "Previously submitted work."),
          score: latest.score,
          scoreVisible: latest.scoreVisible,
          maxScore: latest.maxScore,
        });
          if (typeof latest.query === "string") setAllCode(latest.query);
        }
        setAttempts(previous => [...matching.slice(0, 100).map((item, index) => ({
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
        })),...previous.filter(attempt => attempt.kind === "run")]);
      }
    }).catch(() => {}).finally(() => { if (active) setHistoryLoaded(true); });
    return () => { active = false; };
  }, [embedded, exam?.enabled, problem.id, source, context]);
  async function submitQuestionAttempt(attempt:WorkspaceAttempt) {
    if (onSubmitQuestion) return onSubmitQuestion(attempt);
    if (questionSubmitting || isReadOnly || !attempt.result.questionId || !attempt.script?.trim()) return;
    setQuestionSubmitting(true);setNotice("");
    try {
      const value=await studentApi.submitWorkspaceQuestion(problem.id,{part:"SQL",solutionId:attempt.result.questionId,script:attempt.script,source,context});
      if(value.alreadySubmitted)return value;
      setAttempts(previous => [{id:Date.now() + ++attemptCounter.current,kind:"submit" as const,part:"SQL" as const,result:value,script:attempt.script},...previous].slice(0,200));
      setProblemTab("Submission");
    } catch(error) {setNotice(error instanceof Error ? error.message : tr("Unable to submit this question."));}
    finally {setQuestionSubmitting(false);}
  }
  useEffect(() => {
    if (!submissionSignal) return;
    setProblemTab("Submission");
    if (embedded) {
      const submittedQuery = storage.get(sqlDraftKey);
      if (submittedQuery !== null) setAllCode(submittedQuery);
    }
  }, [submissionSignal, embedded, sqlDraftKey]);
  async function toggleSaved() {
    if (favoriteBusy) return;
    setFavoriteBusy(true);
    try {
      const response = await studentApi.toggleFavorite(problem.id);
      setFavorite(response.status === "added");
    } catch (error) {
      setNotice(error instanceof Error ? error.message : "Could not update saved problems.");
    } finally {
      setFavoriteBusy(false);
    }
  }
  useEffect(() => {
    function escape(e: KeyboardEvent) {
      if (e.key === "Escape") {
        if (expanded) {
          setExpanded(false);
          expandTrigger.current?.focus();
          return;
        }
      }
    }
    document.addEventListener("keydown", escape);
    return () => {
      document.removeEventListener("keydown", escape);
    };
  }, [expanded]);
  async function run(kind: "selected" | "all" | "submit", exitAfter = false) {
    if (busy || questionSubmitting || isReadOnly || (!historyLoaded && !embedded) || (kind === "submit" && (setupScripts || exam?.enabled))) return;
    setBusy(true);
    setExpanded(false);
    setNotice("");
    setExitError("");
    setResultTab(kind === "submit" ? "Submissions" : "Run result");
    setMobileTab("Problem");
    setProblemTab(kind === "submit" ? "Submission" : "Run");
    try {
      const value =
        kind === "submit"
          ? await studentApi.submitSolution(
              problem.id,
              submissionCode,
              "SQL Server",
              source,
              context,
            )
          : setupScripts
            ? await studentApi.runWorkspace(problem.id, { part: "SQL", query: kind === "selected" ? selected : code, solutionId: question?.id, ddl: "", dml: "", source, context })
            : await studentApi.runQuery(problem.id, kind === "selected" ? selected : code, "SQL Server", source, context, question?.id);
      if (alive.current) {
        value.part = "SQL";
        value.questionId = kind !== "submit" ? question?.id : undefined;
        const sample = (question ? question.expectedSamples : problem.expectedSamples)?.[0];
        if (kind !== "submit" && value.table && !value.comparisons?.length && (sample || (!question && problem.expected))) {
          value.comparisons = [{ dataset: sample?.dataset || 1, actualTable: value.table, expectedTable: sample?.table || problem.expected }];
        }
        if (kind === "submit") { setLastSubmit(value); setReviewMode(true); setHasSubmitted(true); setEditedSinceSubmission(false); setHistorySubmissionsUsed(previous => previous + 1); }
        else setResult(value);
        const attempt: WorkspaceAttempt = { id: Date.now() + ++attemptCounter.current, kind: kind === "submit" ? "submit" : "run", part: "SQL", result: value, script:kind === "selected" ? selected : code };
        if (kind !== "submit" && onRunAttempt) onRunAttempt(attempt); else setAttempts(previous => [attempt, ...previous].slice(0, 200));
        setMobileTab("Problem");
        if (kind === "submit" && exitAfter) { setConfirmExit(false); navigate(exitDestination); }
        else if (kind === "submit" && value.id) navigate(`/submissions?submission=${encodeURIComponent(value.id)}`);
      }
    } catch (error) {
      if (alive.current) {
        const message = error instanceof Error ? error.message : "The SQL runner is unavailable. Try again.";
        if (exitAfter) setExitError(message);
        const attempt: WorkspaceAttempt = { id: Date.now() + ++attemptCounter.current, kind: kind === "submit" ? "submit" : "run", part: "SQL", result: { status: "Error", message, questionId: question?.id } };
        if (kind !== "submit" && onRunAttempt) onRunAttempt(attempt); else setAttempts(previous => [attempt, ...previous].slice(0, 200));
      }
    } finally {
      if (alive.current) setBusy(false);
    }
  }
  function resizeFromPointer(
    orientation: "vertical" | "horizontal" | "secondary",
    clientX: number,
    clientY: number,
  ) {
    if (orientation === "vertical") {
      const bounds = workspaceLayout.current?.getBoundingClientRect();
      if (!bounds?.width) return;
      setProblemWidth(
        clampPaneSize(
          ((clientX - bounds.left) / bounds.width) * 100,
          MIN_PROBLEM_WIDTH,
          layout === "Note-taking" ? secondBoundary - 26 : MAX_PROBLEM_WIDTH,
        ),
      );
      return;
    }
    if (orientation === "secondary") {
      const bounds = workspaceLayout.current?.getBoundingClientRect();
      if (bounds?.width) updateSizes({ secondBoundary: clampPaneSize(((clientX - bounds.left) / bounds.width) * 100, problemWidth + 26, 82) });
      return;
    }
    const bounds = editorResults.current?.getBoundingClientRect();
    if (!bounds?.height) return;
    setEditorHeight(
      clampPaneSize(
        ((clientY - bounds.top) / bounds.height) * 100,
        MIN_EDITOR_HEIGHT,
        MAX_EDITOR_HEIGHT,
      ),
    );
  }
  function startResize(
    orientation: "vertical" | "horizontal" | "secondary",
    event: ReactPointerEvent<HTMLDivElement>,
  ) {
    if (event.button !== 0) return;
    event.preventDefault();
    event.currentTarget.focus();
    event.currentTarget.setPointerCapture(event.pointerId);
    activeResize.current = orientation;
    setResizing(orientation);
    resizeFromPointer(orientation, event.clientX, event.clientY);
  }
  function moveResize(
    orientation: "vertical" | "horizontal" | "secondary",
    event: ReactPointerEvent<HTMLDivElement>,
  ) {
    if (activeResize.current !== orientation) return;
    resizeFromPointer(orientation, event.clientX, event.clientY);
  }
  function stopResize(event: ReactPointerEvent<HTMLDivElement>) {
    if (event.currentTarget.hasPointerCapture(event.pointerId)) {
      event.currentTarget.releasePointerCapture(event.pointerId);
    }
    activeResize.current = null;
    setResizing(null);
  }
  function resizeWithKeyboard(
    orientation: "vertical" | "horizontal" | "secondary",
    event: ReactKeyboardEvent<HTMLDivElement>,
  ) {
    const direction = orientation !== "horizontal"
      ? event.key === "ArrowLeft" ? -1 : event.key === "ArrowRight" ? 1 : 0
      : event.key === "ArrowUp" ? -1 : event.key === "ArrowDown" ? 1 : 0;
    if (!direction) return;
    event.preventDefault();
    if (orientation === "vertical") {
      setProblemWidth((value) =>
        clampPaneSize(value + direction * 2, MIN_PROBLEM_WIDTH, layout === "Note-taking" ? secondBoundary - 26 : MAX_PROBLEM_WIDTH),
      );
    } else if (orientation === "secondary") {
      updateSizes({ secondBoundary: clampPaneSize(secondBoundary + direction * 2, problemWidth + 26, 82) });
    } else {
      setEditorHeight((value) =>
        clampPaneSize(value + direction * 2, MIN_EDITOR_HEIGHT, MAX_EDITOR_HEIGHT),
      );
    }
  }
  const hasSetup = Boolean(question?.hasSetup);
  const prepared = usePreparedDatabase(hasSetup && question ? `${problem.id}:${context}:SQL:${question.id}` : null,
    problemTab === "Database", () => studentApi.getQuestionDatabase(problem.id,{part:"SQL",solutionId:question!.id,source,context}));
  const referenceDatabase = useMemo(() => database || workspaceReference(problem), [database, problem]);
  const currentDatabase = useCurrentDatabase(result, question?.id || "SQL");
  const [databaseView, setDatabaseView] = useState<DatabaseView>();
  return (
    <div className={"workspace unified-output" + (!embedded ? " workspace-standalone" : "") + (expanded ? " editor-expanded" : "") + (focus ? " focus-mode" : "") + (resizing ? ` is-resizing resizing-${resizing === "secondary" ? "vertical" : resizing}` : "")}>
      {!embedded && (
      <AppHeader
        workspaceProblemNavigation={<ProblemQuestionNavigation header part="SQL" questions={checks} selectedId={question?.id} onChange={setSelectedQuestionId} disabled={busy || submissionBusy} />}
        workspaceActions={<><WorkspaceNavigation parts={["SQL"]} active="SQL" onChange={() => {}} questionGroups={{SQL:checks}} selectedQuestionIds={{SQL:question?.id}} onQuestionChange={(_,id)=>setSelectedQuestionId(id)} isQuestionDone={(_,id)=>Boolean(answers[id]?.trim() || attempts.some(a=>a.result?.questionId===id))} disabled={busy || submissionBusy} /><WorkspaceExamFinishButton />{!exam?.enabled && <GlidingTooltipGroup label={tr("Workspace actions")} role="group" multiline>
          {!setupScripts && !exam?.enabled && (<button
                  className="button primary workspace-submit-current"
                  data-tooltip={tr(reviewMode ? "Continue editing your submitted scripts. Submit again to update your work." : "Submit all DQL answers for grading.")}
                  disabled={busy || !historyLoaded || contestClosed || activityClosed || (!reviewMode && submissionLimitReached)}
                  title={activityClosed ? tr("Assignment deadline has passed") : submissionLimitReached && !reviewMode ? tr("Submission limit reached") : contestClosed ? tr("Contest submissions are closed") : undefined}
                  onClick={() => reviewMode ? setReviewMode(false) : void run("submit")}
                >
                  <TranslatedText>{activityClosed || contestClosed ? tr("Submissions closed") : reviewMode ? tr("Edit again") : busy && resultTab === "Submissions" ? tr("Submitting…") : tr("Submit")}</TranslatedText>
                </button>)}
          <button data-tooltip={tr("Submit your current work before leaving the workspace.")} type="button" className="button workspace-submit-exit" disabled={busy || !historyLoaded} onClick={() => { setExitError(""); setConfirmExit(true); }}><TranslatedText>{tr("Exit")}</TranslatedText></button>
        </GlidingTooltipGroup>}</>}
        workspace={{
          title: problem.title,
          number: problem.number,
          topic: problem.topic || "Uncategorized",
          source,
          context: contextTitle || problem.title,
        }}
      />
      )}
      <div id={embedded ? undefined : "main-content"} role={embedded ? undefined : "main"} className="workspace-main">
        <div
          className="workspace-mobile-tabs"
          role="tablist"
          aria-label={tr("Workspace pane")}
        >
          {(focus ? ["SQL"] : layout === "Note-taking" ? ["Problem", "SQL", "Notes"] : ["Problem", "SQL"]).map((t) => (
            <button
              key={t}
              role="tab"
              aria-selected={mobileTab === t}
              className={mobileTab === t ? "active" : ""}
              onClick={() => setMobileTab(t)}
            >
              <TranslatedText>{tr(t)}</TranslatedText>
            </button>
          ))}
        </div>
        <div
          ref={workspaceLayout}
          className={`workspace-layout layout-${layout.toLowerCase()}`}
          style={{
            "--problem-pane-width": `${problemWidth}%`,
            "--middle-pane-width": `${secondBoundary - problemWidth}%`,
            "--last-pane-width": `${100 - secondBoundary}%`,
            "--editor-pane-height": `${editorHeight}%`,
          } as CSSProperties}
        >
          <section
            id="workspace-problem-pane"
            className={
              "problem-pane mobile-pane workspace-reading-pane" +
              (mobileTab === "Problem" ? " mobile-visible" : "")
            }
            aria-label={tr("Problem description")}
          >
            <WorkspacePaneTabs value={problemTab} onChange={setProblemTab} prefix="stage-SQL" />
            <WorkspacePanePanel value={problemTab} prefix="stage-SQL">
              {problemTab === "Description" ? (
                <ProblemQuestionContent problem={problem} part="SQL" questions={checks} selectedId={question?.id} showNavigation={false} onQuestionChange={setSelectedQuestionId} disabled={busy || submissionBusy} />
              ) : problemTab === "Database" ? (
                <WorkspaceDatabasePanel database={prepared.data || referenceDatabase} result={result} prepared={hasSetup} loading={prepared.loading} error={prepared.error} onRetry={prepared.retry} questionName={questionLabel} structureVisible={problem.databaseStructureVisible} current={currentDatabase} view={databaseView} onViewChange={setDatabaseView} />
              ) : <WorkspaceSubmissionPanel currentScript={code} onRunCurrent={()=>void run("all")} questionGroups={{DDL:problem.ddlChecks || [],DML:problem.dmlChecks || [],SQL:checks,TRIGGER:problem.triggerChecks || []}} selectedQuestionIds={selectedQuestionIds || {SQL:question?.id}} onSubmitQuestion={!exam?.enabled && !isReadOnly ? submitQuestionAttempt : undefined} mode={problemTab === "Run" ? "run" : "submit"} part="SQL" questionId={question?.id} submissionsUsed={source !== "Practice" ? submissionsUsed : undefined} maxSubmissions={maxSubmissions} attempts={externalAttempts ? [...externalAttempts, ...attempts].sort((a, b) => b.id - a.id) : attempts} busy={busy || submissionBusy || questionSubmitting} notice={submissionNotice || notice} />}
            </WorkspacePanePanel>
            <div className="workspace-problem-actions" style={{ display: "none" }}>
              <button
                className="save-problem"
                aria-pressed={favorite}
                disabled={favoriteBusy}
                onClick={() => void toggleSaved()}
              >
                <Star size={15} fill={favorite ? "currentColor" : "none"} />
                <TranslatedText>{favorite ? tr("Saved") : tr("Save")}</TranslatedText>
              </button>
            </div>
          </section>
          <WorkspaceResizeHandle
            axis="vertical"
            containerRef={workspaceLayout}
            value={problemWidth}
            min={MIN_PROBLEM_WIDTH}
            max={layout === "Note-taking" ? secondBoundary - 26 : MAX_PROBLEM_WIDTH}
            label={tr("Resize problem and editor panes")}
            controls="workspace-problem-pane workspace-editor-results"
            valueText={`Problem pane ${Math.round(problemWidth)} percent`}
            onResize={setProblemWidth}
            onResizeStart={() => setResizing("vertical")}
            onResizeEnd={() => setResizing(null)}
            onReset={() => setProblemWidth(clampPaneSize(defaults[layout].problemWidth, MIN_PROBLEM_WIDTH, layout === "Note-taking" ? secondBoundary - 26 : MAX_PROBLEM_WIDTH))}
          />
          <div id="workspace-editor-results" className="editor-results" ref={editorResults}>
            <section
              id="workspace-editor-pane"
              className={
                "editor-pane mobile-pane" + (isSubmitted ? " is-submission-locked" : "") +
                (mobileTab === "SQL" ? " mobile-visible" : "")
              }
              aria-label={tr("SQL editor")}
            >
              <div className="editor-toolbar">
                <span className="editor-dialect">SQL Server</span>
                <button className="icon-button" type="button" aria-label={tr("Reset SQL query")} title={isReadOnly ? tr("Submitted work is read-only") : tr("Reset SQL query")} disabled={isReadOnly} onClick={() => setConfirmResetQuery(true)}><RotateCcw size={16} /></button>
                <b>{questionLabel}</b>
                {isSubmitted ? <WorkspaceSubmissionLock /> : isReadOnly && <small className="muted"><TranslatedText>{tr("View only")}</TranslatedText></small>}
                <button
                  className="icon-button format-query"
                  disabled={busy || isReadOnly}
                  aria-label={tr("Format SQL indentation")}
                  title={tr("Format SQL indentation")}
                  onClick={() => {
                    const view = editor.current;
                    if (view) {
                      view.dispatch({
                        changes: indentRange(
                          view.state,
                          0,
                          view.state.doc.length,
                        ),
                      });
                      view.focus();
                    }
                  }}
                >
                  <Braces size={17} />
                </button>
                <small className="muted"><TranslatedText>{tr("Draft saved locally")}</TranslatedText></small>
                <button
                  className="icon-button expand-editor"
                  ref={expandTrigger}
                  aria-label={
                    expanded ? tr("Restore editor layout") : tr("Expand SQL editor")
                  }
                  aria-pressed={expanded}
                  onClick={() => {
                    setExpanded((value) => !value);
                    setMobileTab("SQL");
                  }}
                >
                  {expanded ? <Minimize2 size={16} /> : <Maximize2 size={16} />}
                </button>
              </div>
              <div className="sql-editor">
                <CodeMirror
                  value={code}
                  height="100%"
                  readOnly={isReadOnly}
                  editable={!isReadOnly}
                  extensions={sqlEditorExtensions}
                  onChange={value => { if (!isReadOnly) { setCode(value); setEditedSinceSubmission(true); onUserEdit?.(); } }}
                  onCreateEditor={(view) => {
                    editor.current = view;
                  }}
                  onUpdate={(v) => {
                    const selection = v.state.selection.main;
                    const line = v.state.doc.lineAt(selection.head);
                    setCursor({
                      line: line.number,
                      column: selection.head - line.from + 1,
                    });
                    setSelected(v.state.sliceDoc(selection.from, selection.to));
                  }}
                  aria-label={tr("SQL query")}
                  basicSetup={{ foldGutter: false, highlightActiveLine: true }}
                />
              </div>
              <div className="editor-actions stage-editor-actions">
                <WorkspaceEditorStatus {...cursor} executionTimeMs={result?.executionTimeMs} submissionsUsed={submissionsUsed} maxSubmissions={maxSubmissions} />
                <div className="stage-run-actions">
                  <button
                    type="button"
                    className="button"
                    disabled={isReadOnly || busy || !selected.trim()}
                    title={
                      !selected.trim() ? tr("Select SQL text in the editor first") : undefined
                    }
                    onClick={() => void run("selected")}
                  >
                    <TranslatedText>{tr("Run selected")}</TranslatedText>
                  </button>
                  <button
                    type="button"
                    className="button primary workspace-run-query"
                    disabled={isReadOnly || busy || !code.trim()}
                    onClick={() => void run("all")}
                  >
                    <Play size={15} /><TranslatedText>{busy ? tr("Running…") : tr("Run DQL")}</TranslatedText>
                  </button>
                </div>
              </div>
            </section>
          </div>
          <div className="workspace-splitter workspace-splitter-vertical notes-splitter" role="separator" aria-label={tr("Resize notes pane")} aria-orientation="vertical" aria-valuemin={problemWidth + 26} aria-valuemax={82} aria-valuenow={secondBoundary} tabIndex={0}
            onPointerDown={event => startResize("secondary", event)} onPointerMove={event => moveResize("secondary", event)} onPointerUp={stopResize} onPointerCancel={stopResize}
            onLostPointerCapture={() => { activeResize.current = null; setResizing(null); }} onKeyDown={event => resizeWithKeyboard("secondary", event)} onDoubleClick={() => updateSizes({ secondBoundary: clampPaneSize(defaults[layout].secondBoundary, problemWidth + 26, 82) })} />
          <section className={`notes-pane mobile-pane${mobileTab === "Notes" ? " mobile-visible" : ""}`} aria-label={tr("Notes")}>
            <div className="pane-tabs"><b><TranslatedText>{tr("Notes")}</TranslatedText></b><small className="muted"><TranslatedText>{tr("Saved locally")}</TranslatedText></small></div>
            <textarea aria-label={tr("Problem notes")} placeholder={tr("Write your approach, observations or SQL reminders...")} value={notes} onChange={event => { setNotes(event.target.value); storage.set(notesKey, event.target.value); }} />
          </section>
        </div>
      </div>
      {confirmResetQuery && !isReadOnly && <Dialog title={tr("Reset SQL query?")} onClose={() => setConfirmResetQuery(false)}><p><TranslatedText>{tr("Your current SQL draft will be cleared from this browser.")}</TranslatedText></p><div className="dialog-actions"><button className="button" type="button" onClick={() => setConfirmResetQuery(false)}><TranslatedText>{tr("Cancel")}</TranslatedText></button><button className="button primary" type="button" onClick={() => { setCode(""); setEditedSinceSubmission(true); onUserEdit?.(); setResult(null); setAttempts(previous => previous.filter(attempt => attempt.kind === "submit")); setConfirmResetQuery(false); }}><TranslatedText>{tr("Clear query")}</TranslatedText></button></div></Dialog>}
      {!embedded && !exam?.enabled && confirmExit && <WorkspaceExitDialog saved={exitSaved} blockedReason={exitSaved ? undefined : exitBlockedReason} busy={busy} error={exitError} onClose={() => setConfirmExit(false)} onConfirm={() => { if (exitSaved || exitBlockedReason) navigate(exitDestination); else void run("submit", true); }} />}
    </div>
  );
}

export { ContestSqlWorkspace } from "@/features/workspace/ContestSqlWorkspace";
