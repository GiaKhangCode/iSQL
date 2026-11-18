import { useLanguage } from "@/shared/context/LanguageContext";
import { useRef } from "react";
import { useAuth } from "@/app/providers/AuthContext";
import { workspaceDraftKey } from "@/shared/lib/workspace/workspaceParts";
import { useEffect, useState } from "react";
import { useParams, useNavigate } from "react-router-dom";
import { studentApi } from "@/shared/api/studentApi";
import type { ExamSession } from "@/data/models";
import type { ExamSessionSchema } from "@/features/workspace/types";
import { ExamSessionCardsView, ExamSessionDiagramCanvas } from "@/features/workspace/components/ExamSessionWorkspaceSchemaViews";
import { parseServerDateTime } from "@/shared/lib/datetime/serverDateTime";
import "@/styles/exam-session-workspace.css";

export function useExamSessionWorkspace() {
  const { t: tr } = useLanguage("workspace");
  const { session } = useAuth();
  const hydratedDraftKey = useRef<string | null>(null);
  const { examSessionId = "" } = useParams();
  const navigate = useNavigate();
  const draftKey = workspaceDraftKey(session?.id || "anonymous", examSessionId, "exam");

  // Exam session data
  const [examSession, setExamSession] = useState<ExamSession | null>(null);
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
  const [schemaData, setSchemaData] = useState<ExamSessionSchema | null>(null);
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

  // Load exam session & setup database
  useEffect(() => {
    let isMounted = true;
    async function loadExamSession() {
      try {
        setLoading(true);
        const examSessionOrExam = await studentApi.getExamSessionOrExam?.(examSessionId);
        const found = examSessionOrExam;
        if (!found) {
          throw new Error("The exam session was not found or is unavailable.");
        }
        if (found.examType !== "comprehensive") {
          throw new Error("This exam session does not use the comprehensive exam format (DDL + DML + SQL).");
        }
        if (isMounted) setExamSession(found);

        // Set up the student exam database
        await studentApi.setupExamSessionDb(examSessionId);

        // Load the initial table structure
        const schema = await studentApi.inspectExamSessionSchema(examSessionId);
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
    loadExamSession();
    return () => {
      isMounted = false;
    };
  }, [examSessionId, draftKey]);

  // Timer countdown
  useEffect(() => {
    const timer = setInterval(() => {
      const current = Date.now();
      setNow(current);
      if (examSession?.closesAt) {
        const end = parseServerDateTime(examSession.closesAt).getTime();
        setTimeRemaining(Math.max(0, end - current));
      }
    }, 1000);
    return () => clearInterval(timer);
  }, [examSession]);

  // Auto-save draft to localStorage whenever ddlScript or queryAnswers change
  useEffect(() => {
    if (!examSessionId || loading || error || hydratedDraftKey.current !== draftKey) return;
    const draft = { ddlScript, queryAnswers };
    localStorage.setItem(draftKey, JSON.stringify(draft));
  }, [examSessionId, draftKey, ddlScript, queryAnswers, loading, error]);

  // Refresh Schema Inspector
  const refreshSchema = async () => {
    if (!examSessionId) return;
    try {
      setSchemaLoading(true);
      const schema = await studentApi.inspectExamSessionSchema(examSessionId);
      setSchemaData(schema);
    } catch (e) {
      console.error("Unable to refresh the schema:", e);
    } finally {
      setSchemaLoading(false);
    }
  };

  // Step 1: Execute DDL
  const handleRunDdl = async () => {
    if (!examSessionId || !ddlScript.trim()) {
      setDdlLog({ status: "Error", messages: [], error: "Enter a DDL script before running it." });
      return;
    }
    try {
      setDdlRunning(true);
      setDdlLog(null);
      const res = await studentApi.runExamSessionDdl(examSessionId, ddlScript);
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
    if (!examSessionId) return;
    try {
      setDmlRunning(true);
      setDmlResult(null);
      const res = await studentApi.seedExamSessionDml(examSessionId);
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
    if (!examSession) return;
    const activeQuestion = examSession.problemDetails[activeQuestionIndex];
    if (!activeQuestion) return;
    const query = queryAnswers[activeQuestion.id] || "";
    if (!query.trim()) {
      setQueryResult({ columns: [], rows: [], rowCount: 0, error: "Enter a SQL query." });
      return;
    }
    try {
      setQueryRunning(true);
      setQueryResult(null);
      const res = await studentApi.runExamSessionQuery(examSessionId, query);
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
      const result = await studentApi.submitExamSessionExam(examSessionId, ddlScript, queryAnswers);
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

  const renderDiagramCanvas = (isModal: boolean = false) => <ExamSessionDiagramCanvas schemaData={schemaData} isModal={isModal} tr={tr} />;

  const renderCardsView = () => <ExamSessionCardsView schemaData={schemaData} collapsedCards={collapsedCards} toggleCardCollapse={toggleCardCollapse} tr={tr} />;

  return { examSessionId, navigate, examSession, setExamSession, loading, setLoading, error, setError, now, setNow, timeRemaining, setTimeRemaining, activeTab, setActiveTab, ddlScript, setDdlScript, ddlRunning, setDdlRunning, ddlLog, setDdlLog, schemaData, setSchemaData, schemaLoading, setSchemaLoading, schemaVisible, setSchemaVisible, schemaViewMode, setSchemaViewMode, showFullscreenDiagram, setShowFullscreenDiagram, collapsedCards, setCollapsedCards, dmlRunning, setDmlRunning, dmlResult, setDmlResult, activeQuestionIndex, setActiveQuestionIndex, queryAnswers, setQueryAnswers, queryRunning, setQueryRunning, queryResult, setQueryResult, submitting, setSubmitting, showConfirmModal, setShowConfirmModal, showScorecardModal, setShowScorecardModal, scorecard, setScorecard, refreshSchema, handleRunDdl, handleSeedDml, handleRunQuery, handleSubmitExam, toggleCardCollapse, renderDiagramCanvas, renderCardsView };
}


export type ExamSessionWorkspaceController = ReturnType<typeof useExamSessionWorkspace>;

