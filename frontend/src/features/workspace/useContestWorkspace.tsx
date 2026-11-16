import { useLanguage } from "@/shared/context/LanguageContext";
import { useRef } from "react";
import { useAuth } from "@/app/providers/AuthContext";
import { workspaceDraftKey } from "@/shared/lib/workspace/workspaceParts";
import { useEffect, useState } from "react";
import { useParams, useNavigate } from "react-router-dom";
import { studentApi } from "@/shared/api/studentApi";
import type { Contest } from "@/data/models";
import type { ContestSchema } from "@/features/workspace/types";
import { ContestCardsView, ContestDiagramCanvas } from "@/features/workspace/components/ContestWorkspaceSchemaViews";
import { parseServerDateTime } from "@/shared/lib/datetime/serverDateTime";
import "@/styles/contest-exam.css";

export function useContestWorkspace() {
  const { t: tr } = useLanguage("workspace");
  const { session } = useAuth();
  const hydratedDraftKey = useRef<string | null>(null);
  const { contestId = "" } = useParams();
  const navigate = useNavigate();
  const draftKey = workspaceDraftKey(session?.id || "anonymous", contestId, "exam");

  // Contest data
  const [contest, setContest] = useState<Contest | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  // Time & Timer
  const [now, setNow] = useState(Date.now());
  const [timeRemaining, setTimeRemaining] = useState(0);

  // Navigation tab: ddl | dml | queries
  const [activeTab, setActiveTab] = useState<"ddl" | "dml" | "queries">("ddl");

  // Step 1: DDL
  const [ddlScript, setDdlScript] = useState("");
  const [ddlRunning, setDdlRunning] = useState(false);
  const [ddlLog, setDdlLog] = useState<{ status: string; messages: string[]; error?: string } | null>(null);

  // Schema Inspector & Database Diagram
  const [schemaData, setSchemaData] = useState<ContestSchema | null>(null);
  const [schemaLoading, setSchemaLoading] = useState(false);
  const [schemaVisible, setSchemaVisible] = useState(true);
  const [schemaViewMode, setSchemaViewMode] = useState<"diagram" | "cards">("diagram");
  const [showFullscreenDiagram, setShowFullscreenDiagram] = useState(false);
  const [collapsedCards, setCollapsedCards] = useState<Record<string, boolean>>({});

  // Step 2: DML Seed
  const [dmlRunning, setDmlRunning] = useState(false);
  const [dmlResult, setDmlResult] = useState<{ status: string; message: string; isError?: boolean } | null>(null);

  // Step 3: SQL Queries
  const [activeQuestionIndex, setActiveQuestionIndex] = useState(0);
  const [queryAnswers, setQueryAnswers] = useState<Record<string, string>>({});
  const [queryRunning, setQueryRunning] = useState(false);
  const [queryResult, setQueryResult] = useState<{
    columns: string[];
    rows: any[][];
    rowCount: number;
    error?: string;
  } | null>(null);

  // Submission & Scorecard
  const [submitting, setSubmitting] = useState(false);
  const [showConfirmModal, setShowConfirmModal] = useState(false);
  const [showScorecardModal, setShowScorecardModal] = useState(false);
  const [scorecard, setScorecard] = useState<any | null>(null);

  // Load contest & setup database
  useEffect(() => {
    let isMounted = true;
    async function loadContest() {
      try {
        setLoading(true);
        const contestOrExam = await studentApi.getContestOrExam?.(contestId);
        const found = contestOrExam || (await studentApi.getContests()).find((c) => c.id === contestId);
        if (!found) {
          throw new Error("The contest was not found or is unavailable.");
        }
        if (found.examType !== "comprehensive") {
          throw new Error("This contest does not use the comprehensive exam format (DDL + DML + SQL).");
        }
        if (isMounted) setContest(found);

        // Set up the student exam database
        await studentApi.setupContestDb(contestId);

        // Load the initial table structure
        const schema = await studentApi.inspectContestSchema(contestId);
        if (isMounted) setSchemaData(schema);

        setDdlScript("");
        setQueryAnswers({});
        // Restore the locally saved draft, if available
        const savedDraft = localStorage.getItem(draftKey);
        if (savedDraft) {
          try {
            const parsed = JSON.parse(savedDraft);
            if (parsed.ddlScript && isMounted) setDdlScript(parsed.ddlScript);
            if (parsed.queryAnswers && isMounted) setQueryAnswers(parsed.queryAnswers);
          } catch {
            // ignore JSON parse error
          }
        }
        if (isMounted) hydratedDraftKey.current = draftKey;
      } catch (err: any) {
        if (isMounted) setError(err.message || "Unable to load the exam workspace.");
      } finally {
        if (isMounted) setLoading(false);
      }
    }
    loadContest();
    return () => {
      isMounted = false;
    };
  }, [contestId, draftKey]);

  // Timer countdown
  useEffect(() => {
    const timer = setInterval(() => {
      const current = Date.now();
      setNow(current);
      if (contest?.closesAt) {
        const end = parseServerDateTime(contest.closesAt).getTime();
        setTimeRemaining(Math.max(0, end - current));
      }
    }, 1000);
    return () => clearInterval(timer);
  }, [contest]);

  // Auto-save draft to localStorage whenever ddlScript or queryAnswers change
  useEffect(() => {
    if (!contestId || loading || error || hydratedDraftKey.current !== draftKey) return;
    const draft = { ddlScript, queryAnswers };
    localStorage.setItem(draftKey, JSON.stringify(draft));
  }, [contestId, draftKey, ddlScript, queryAnswers, loading, error]);

  // Refresh Schema Inspector
  const refreshSchema = async () => {
    if (!contestId) return;
    try {
      setSchemaLoading(true);
      const schema = await studentApi.inspectContestSchema(contestId);
      setSchemaData(schema);
    } catch (e) {
      console.error("Unable to refresh the schema:", e);
    } finally {
      setSchemaLoading(false);
    }
  };

  // Step 1: Execute DDL
  const handleRunDdl = async () => {
    if (!contestId || !ddlScript.trim()) {
      setDdlLog({ status: "Error", messages: [], error: "Enter a DDL script before running it." });
      return;
    }
    try {
      setDdlRunning(true);
      setDdlLog(null);
      const res = await studentApi.runContestDdl(contestId, ddlScript);
      setDdlLog({ status: "Success", messages: res.messages });
      // Refresh the schema inspector
      await refreshSchema();
    } catch (err: any) {
      setDdlLog({ status: "Error", messages: [], error: err.message || "Unable to execute the DDL script." });
    } finally {
      setDdlRunning(false);
    }
  };

  // Step 2: Seed DML Data
  const handleSeedDml = async () => {
    if (!contestId) return;
    try {
      setDmlRunning(true);
      setDmlResult(null);
      const res = await studentApi.seedContestDml(contestId);
      setDmlResult({ status: "Success", message: res.message });
      // Refresh table row counts
      await refreshSchema();
    } catch (err: any) {
      setDmlResult({ status: "Error", message: err.message || "Unable to load the sample data.", isError: true });
    } finally {
      setDmlRunning(false);
    }
  };

  // Step 3: Run Query
  const handleRunQuery = async () => {
    if (!contest) return;
    const activeQuestion = contest.problemDetails[activeQuestionIndex];
    if (!activeQuestion) return;
    const query = queryAnswers[activeQuestion.id] || "";
    if (!query.trim()) {
      setQueryResult({ columns: [], rows: [], rowCount: 0, error: "Enter a SQL query." });
      return;
    }
    try {
      setQueryRunning(true);
      setQueryResult(null);
      const res = await studentApi.runContestQuery(contestId, query);
      setQueryResult(res);
    } catch (err: any) {
      setQueryResult({ columns: [], rows: [], rowCount: 0, error: err.message || "Unable to execute the SQL query." });
    } finally {
      setQueryRunning(false);
    }
  };

  // Final Submit
  const handleSubmitExam = async () => {
    setShowConfirmModal(false);
    try {
      setSubmitting(true);
      const result = await studentApi.submitContestExam(contestId, ddlScript, queryAnswers);
      setScorecard(result.scorecard);
      setShowScorecardModal(true);
      // Clear the draft after successful submission
      localStorage.removeItem(draftKey);
    } catch (err: any) {
      alert(err.message || "Unable to submit the exam. Please try again.");
    } finally {
      setSubmitting(false);
    }
  };

  const toggleCardCollapse = (tableName: string) => {
    setCollapsedCards((prev) => ({ ...prev, [tableName]: !prev[tableName] }));
  };

  const renderDiagramCanvas = (isModal: boolean = false) => <ContestDiagramCanvas schemaData={schemaData} isModal={isModal} tr={tr} />;

  const renderCardsView = () => <ContestCardsView schemaData={schemaData} collapsedCards={collapsedCards} toggleCardCollapse={toggleCardCollapse} tr={tr} />;

  return { contestId, navigate, contest, setContest, loading, setLoading, error, setError, now, setNow, timeRemaining, setTimeRemaining, activeTab, setActiveTab, ddlScript, setDdlScript, ddlRunning, setDdlRunning, ddlLog, setDdlLog, schemaData, setSchemaData, schemaLoading, setSchemaLoading, schemaVisible, setSchemaVisible, schemaViewMode, setSchemaViewMode, showFullscreenDiagram, setShowFullscreenDiagram, collapsedCards, setCollapsedCards, dmlRunning, setDmlRunning, dmlResult, setDmlResult, activeQuestionIndex, setActiveQuestionIndex, queryAnswers, setQueryAnswers, queryRunning, setQueryRunning, queryResult, setQueryResult, submitting, setSubmitting, showConfirmModal, setShowConfirmModal, showScorecardModal, setShowScorecardModal, scorecard, setScorecard, refreshSchema, handleRunDdl, handleSeedDml, handleRunQuery, handleSubmitExam, toggleCardCollapse, renderDiagramCanvas, renderCardsView };
}


export type ContestWorkspaceController = ReturnType<typeof useContestWorkspace>;

