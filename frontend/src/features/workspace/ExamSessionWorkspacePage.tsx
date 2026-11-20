import { TranslatedText } from "@/shared/ui/TranslatedText";
import { useLanguage } from "@/shared/context/LanguageContext";
import { Link } from "react-router-dom";
import { ArrowLeft, Clock, RefreshCw, Send, AlertCircle } from "lucide-react";
import { Dialog, Loading } from "@/shared/ui/ui";
import "@/styles/exam-session-workspace.css";
import { useExamSessionWorkspace } from "@/features/workspace/useExamSessionWorkspace";
import { ExamSessionDdlWorkspace } from "@/features/workspace/DdlWorkspace";
import { ExamSessionDmlWorkspace } from "@/features/workspace/DmlWorkspace";
import { ExamSessionSqlWorkspace } from "@/features/workspace/ExamSessionSqlWorkspace";
import { formatCountdown } from "@/shared/lib/datetime/formatCountdown";

export function ExamSessionWorkspacePage() {
  const { t: tr } = useLanguage("workspace");
  const controller = useExamSessionWorkspace();
  const {
    navigate,
    examSession,
    loading,
    error,
    timeRemaining,
    activeTab,
    setActiveTab,
    schemaData,
    schemaLoading,
    showFullscreenDiagram,
    setShowFullscreenDiagram,
    activeQuestionIndex,
    submitting,
    showConfirmModal,
    setShowConfirmModal,
    showScorecardModal,
    setShowScorecardModal,
    scorecard,
    refreshSchema,
    handleSubmitExam,
    renderDiagramCanvas
  } = controller;
  if (loading) {
    return (
      <div className="exam-session-exam-container" style={{ justifyContent: "center", alignItems: "center" }}>
        <Loading variant="workspace" label={tr("Setting up the exam workspace and your database...")} />
      </div>
    );
  }

  if (error || !examSession) {
    return (
      <div className="exam-session-exam-container" style={{ justifyContent: "center", alignItems: "center", padding: 24 }}>
        <AlertCircle size={48} color="#ef4444" style={{ marginBottom: 16 }} />
        <h2><TranslatedText>{tr("Exam workspace unavailable")}</TranslatedText></h2>
        <p className="muted" style={{ margin: "8px 0 20px" }}><TranslatedText>{tr(error) || tr("The exam session does not exist or has ended.")}</TranslatedText></p>
        <Link to="/examinations" className="exam-session-btn-primary">
          <ArrowLeft size={16} /> <TranslatedText>{tr("Back to examinations")}</TranslatedText>
        </Link>
      </div>
    );
  }

  const returnUrl = examSession ? `/examinations/${examSession.id}` : "/examinations";

  const isTimeCritical = timeRemaining < 300000 && timeRemaining > 0; // Less than five minutes remaining
  const activeQuestion = examSession.problemDetails[activeQuestionIndex];

  return (
    <div className="exam-session-exam-container">
      {/* --- EXAM TOP HEADER --- */}
      <header className="exam-session-exam-header">
        <div className="exam-session-exam-header-left">
          <Link to={returnUrl} className="exam-session-exam-back-btn" title={tr("Leave exam")}>
            <ArrowLeft size={15} /> <TranslatedText>{tr("Leave exam")}</TranslatedText>
          </Link>
          <div className="exam-session-exam-title-group">
            <h1>
              {examSession.title}
              <span className="exam-session-exam-badge"><TranslatedText>{tr("Comprehensive exam")}</TranslatedText></span>
            </h1>
          </div>
          {schemaData?.schemaName && (
            <div className="exam-session-db-pill" title={tr("Your personal database schema on Azure")}>
              <span className="exam-session-db-pulse" />
              <span><TranslatedText>{tr("DB: [")}</TranslatedText>{schemaData.schemaName}]</span>
            </div>
          )}
        </div>

        <div className="exam-session-exam-header-right">
          <div className={`exam-session-timer-pill ${isTimeCritical ? "warning" : ""}`} title={tr("Time remaining")}>
            <Clock size={15} />
            <span>{formatCountdown(timeRemaining)}</span>
          </div>

          <button
            type="button"
            className="exam-session-submit-btn"
            disabled={submitting}
            onClick={() => setShowConfirmModal(true)}
          >
            <Send size={15} />
            <span><TranslatedText>{submitting ? tr("Submitting...") : tr("Submit exam")}</TranslatedText></span>
          </button>
        </div>
      </header>

      {/* --- STEP TABS NAV --- */}
      <nav className="exam-session-steps-nav">
        <button
          type="button"
          className={`exam-session-step-tab ${activeTab === "ddl" ? "active" : ""}`}
          onClick={() => setActiveTab("ddl")}
        >
          <span className="exam-session-step-number">1</span>
          <span><TranslatedText>{tr("🏗️ 1. Schema design (DDL)")}</TranslatedText></span>
          <span className="exam-session-step-points">{examSession.ddlPoints ?? 30} <TranslatedText>{tr("pts")}</TranslatedText></span>
        </button>

        <button
          type="button"
          className={`exam-session-step-tab ${activeTab === "dml" ? "active" : ""}`}
          onClick={() => setActiveTab("dml")}
        >
          <span className="exam-session-step-number">2</span>
          <span><TranslatedText>{tr("📥 2. Sample data (DML)")}</TranslatedText></span>
          <span className="exam-session-step-points">{examSession.dmlPoints ?? 20} <TranslatedText>{tr("pts")}</TranslatedText></span>
        </button>

        <button
          type="button"
          className={`exam-session-step-tab ${activeTab === "queries" ? "active" : ""}`}
          onClick={() => setActiveTab("queries")}
        >
          <span className="exam-session-step-number">3</span>
          <span><TranslatedText>{tr("🔍 3. Data queries (DQL)")}</TranslatedText></span>
          <span className="exam-session-step-points">{examSession.sqlPoints ?? 50} <TranslatedText>{tr("pts")}</TranslatedText></span>
        </button>
      </nav>

      {/* --- EXAM BODY CONTENT --- */}
      <main className="exam-session-exam-body">
        {/* ==================================================== */}
        {/* TAB 1: DDL STAGE */}
        {/* ==================================================== */}
        {activeTab === "ddl" && <ExamSessionDdlWorkspace state={{ ...controller, examSession }} />}

        {/* ==================================================== */}
        {/* TAB 2: DML STAGE */}
        {/* ==================================================== */}
        {activeTab === "dml" && <ExamSessionDmlWorkspace state={{ ...controller, examSession }} />}

        {/* ==================================================== */}
        {/* TAB 3: SQL QUERIES STAGE */}
        {/* ==================================================== */}
        {activeTab === "queries" && <ExamSessionSqlWorkspace state={{ ...controller, examSession }} />}
      </main>

      {/* --- FULLSCREEN ERD DIAGRAM MODAL --- */}
      {showFullscreenDiagram && (
        <Dialog
          title={tr("Database relationship diagram (ERD) — [{value0}]", { value0: schemaData?.schemaName ?? "Personal database" })}
          onClose={() => setShowFullscreenDiagram(false)}
        >
          <div className="exam-session-fullscreen-erd">
            <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: 12, borderBottom: "1px solid var(--border)", paddingBottom: 8 }}>
              <div style={{ fontSize: 13, color: "var(--muted)" }}>
                <TranslatedText>{tr("Total:")}</TranslatedText> <b>{schemaData?.tableCount ?? 0}</b> <TranslatedText>{tr("tables and")}</TranslatedText> <b>{schemaData?.relationships?.length ?? 0}</b> <TranslatedText>{tr("foreign key relationships.")}</TranslatedText>
              </div>
              <button
                type="button"
                className="exam-session-btn-secondary"
                disabled={schemaLoading}
                onClick={refreshSchema}
              >
                <RefreshCw size={13} className={schemaLoading ? "spinning" : ""} /> <TranslatedText>{tr("Refresh schema")}</TranslatedText>
              </button>
            </div>
            {renderDiagramCanvas(true)}
          </div>
        </Dialog>
      )}

      {/* --- CONFIRM SUBMIT MODAL --- */}
      {showConfirmModal && (
        <Dialog title={tr("Confirm exam submission")} onClose={() => setShowConfirmModal(false)}>
          <div style={{ padding: "12px 0 20px" }}>
            <p style={{ margin: "0 0 12px 0", fontSize: 14 }}>
              <TranslatedText>{tr("Are you sure you want to submit your work for")}</TranslatedText> <b>{examSession.title}</b>?
            </p>
            <div style={{ background: "var(--subtle)", padding: 12, borderRadius: 8, fontSize: 13, lineHeight: 1.6 }}>
              <div>• <b><TranslatedText>{tr("DDL section:")}</TranslatedText></b> <TranslatedText>{tr("Table structures, data types, primary keys and foreign keys will be compared (")}</TranslatedText>{examSession.ddlPoints ?? 30} <TranslatedText>{tr("pts).")}</TranslatedText></div>
              <div>• <b><TranslatedText>{tr("DML section:")}</TranslatedText></b> <TranslatedText>{tr("Compatibility with the sample data will be checked (")}</TranslatedText>{examSession.dmlPoints ?? 20} <TranslatedText>{tr("pts).")}</TranslatedText></div>
              <div>• <b><TranslatedText>{tr("DQL section:")}</TranslatedText></b> <TranslatedText>{tr("All SQL queries will be graded automatically (")}</TranslatedText>{examSession.sqlPoints ?? 50} <TranslatedText>{tr("pts).")}</TranslatedText></div>
            </div>
            <p style={{ marginTop: 14, fontSize: 13, color: "var(--muted)" }}>
              <TranslatedText>{tr("You will receive a detailed scorecard after submitting.")}</TranslatedText>
            </p>
          </div>
          <div style={{ display: "flex", justifyContent: "flex-end", gap: 10 }}>
            <button type="button" className="exam-session-btn-secondary" onClick={() => setShowConfirmModal(false)}>
              <TranslatedText>{tr("Continue working")}</TranslatedText>
            </button>
            <button type="button" className="exam-session-submit-btn" onClick={handleSubmitExam}>
              <Send size={14} /> <TranslatedText>{tr("Confirm submission")}</TranslatedText>
            </button>
          </div>
        </Dialog>
      )}

      {/* --- FINAL SCORECARD MODAL --- */}
      {showScorecardModal && scorecard && (
        <Dialog
          title={tr("Comprehensive exam scorecard")}
          onClose={() => {
            setShowScorecardModal(false);
            navigate(returnUrl);
          }}
        >
          <div className="scorecard-modal-content">
            <div className="scorecard-hero">
              <div className="scorecard-total-badge">
                {scorecard.total_score} <span>/ {scorecard.max_score}</span>
              </div>
              <div className={`scorecard-status-pill ${scorecard.passed ? "pass" : "partial"}`}>
                <TranslatedText>{scorecard.passed ? tr("✓ PASSED") : tr("PARTIALLY COMPLETED")}</TranslatedText>
              </div>
            </div>

            <div className="scorecard-sections">
              {/* DDL Section */}
              <div className="scorecard-section-card">
                <div className="scorecard-section-header">
                  <span><TranslatedText>{tr("🏗️ 1. Schema design score (DDL)")}</TranslatedText></span>
                  <span className="scorecard-section-score">
                    {scorecard.ddl.score} / {scorecard.ddl.max_score} <TranslatedText>{tr("pts")}</TranslatedText>
                  </span>
                </div>
                <div style={{ fontSize: 13 }}>{scorecard.ddl.feedback}</div>
                {scorecard.ddl.details && scorecard.ddl.details.length > 0 && (
                  <ul className="scorecard-detail-list">
                    {scorecard.ddl.details.map((d: string, i: number) => (
                      <li key={i}><TranslatedText>{tr(d)}</TranslatedText></li>
                    ))}
                  </ul>
                )}
              </div>

              {/* DML Section */}
              <div className="scorecard-section-card">
                <div className="scorecard-section-header">
                  <span><TranslatedText>{tr("📥 2. Sample data score (DML)")}</TranslatedText></span>
                  <span className="scorecard-section-score">
                    {scorecard.dml.score} / {scorecard.dml.max_score} <TranslatedText>{tr("pts")}</TranslatedText>
                  </span>
                </div>
                <div style={{ fontSize: 13 }}>{scorecard.dml.feedback}</div>
                {scorecard.dml.details && scorecard.dml.details.length > 0 && (
                  <ul className="scorecard-detail-list">
                    {scorecard.dml.details.map((d: string, i: number) => (
                      <li key={i}><TranslatedText>{tr(d)}</TranslatedText></li>
                    ))}
                  </ul>
                )}
              </div>

              {/* Queries Section */}
              <div className="scorecard-section-card">
                <div className="scorecard-section-header">
                  <span><TranslatedText>{tr("🔍 3. Data query score (DQL)")}</TranslatedText></span>
                  <span className="scorecard-section-score">
                    {scorecard.queries.score} / {scorecard.queries.max_score} <TranslatedText>{tr("pts")}</TranslatedText>
                  </span>
                </div>
                <div style={{ display: "flex", flexDirection: "column", gap: 8, marginTop: 8 }}>
                  {scorecard.queries.questions.map((q: any, i: number) => (
                    <div
                      key={i}
                      style={{
                        display: "flex",
                        justifyContent: "space-between",
                        alignItems: "center",
                        background: "var(--surface)",
                        padding: "8px 12px",
                        borderRadius: 6,
                        border: "1px solid var(--border)",
                        fontSize: 13
                      }}
                    >
                      <div>
                        <b>{q.title}</b>: <span className="muted"><TranslatedText>{tr(q.message)}</TranslatedText></span>
                      </div>
                      <div style={{ fontWeight: 600, color: q.score > 0 ? "#22c55e" : "#ef4444" }}>
                        {q.score} / {q.max_score} <TranslatedText>{tr("pts")}</TranslatedText>
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            </div>

            <div style={{ display: "flex", justifyContent: "flex-end", marginTop: 12 }}>
              <button
                type="button"
                className="exam-session-btn-primary"
                onClick={() => {
                  setShowScorecardModal(false);
                  navigate(returnUrl);
                }}
              >
                <TranslatedText>{tr("Finish & return to exam session")}</TranslatedText>
              </button>
            </div>
          </div>
        </Dialog>
      )}
    </div>
  );
}

export default ExamSessionWorkspacePage;
