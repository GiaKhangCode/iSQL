import { TranslatedText } from "@/shared/ui/TranslatedText";
import { useLanguage } from "@/shared/context/LanguageContext";
import CodeMirror from "@uiw/react-codemirror";
import { sqlEditorExtensions } from "@/shared/components/sqlEditorExtensions";

import "@/styles/workspace.css";
import "@/styles/workspace-viewport.css";
import { DataGrid, Loading } from "@/shared/ui/ui";
import { ProblemMarkdown } from "@/shared/components/ProblemMarkdown";
import { AlertCircle, Play, LayoutGrid } from "lucide-react";
import { type ContestWorkspaceController } from "@/features/workspace/useContestWorkspace";
import type { Contest } from "@/data/models";

import "@/features/workspace/components/WorkspaceReadingPane.css";
export function ContestSqlWorkspace({ state }: { state: ContestWorkspaceController & { contest: Contest } }) {
  const { t: tr } = useLanguage("workspace");
  const {
    contest,
    error,
    setShowFullscreenDiagram,
    activeQuestionIndex,
    setActiveQuestionIndex,
    queryAnswers,
    setQueryAnswers,
    queryRunning,
    queryResult,
    setQueryResult,
    handleRunQuery
  } = state;
  const activeQuestion = contest.problemDetails[activeQuestionIndex];
  return (
          <div className="contest-queries-layout">
            {/* Questions sidebar */}
            <aside className="contest-questions-sidebar">
              <div className="contest-questions-header">
                <span><TranslatedText>{tr("Questions (")}</TranslatedText>{contest.problemDetails.length})</span>
              </div>
              <div className="contest-questions-list">
                {contest.problemDetails.map((q, idx) => {
                  const hasAnswer = Boolean(queryAnswers[q.id]?.trim());
                  const isActive = idx === activeQuestionIndex;
                  return (
                    <button
                      key={q.id}
                      type="button"
                      className={`contest-question-btn ${isActive ? "active" : ""}`}
                      onClick={() => {
                        setActiveQuestionIndex(idx);
                        setQueryResult(null);
                      }}
                    >
                      <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
                        <span className={`contest-question-status-dot ${hasAnswer ? "answered" : ""}`} />
                        <span><TranslatedText><TranslatedText>{tr("Question")}</TranslatedText></TranslatedText> {idx + 1}. {q.title}</span>
                      </div>
                      <span style={{ fontSize: 11, opacity: 0.7 }}>{q.points ?? 10} <TranslatedText>{tr("pts")}</TranslatedText></span>
                    </button>
                  );
                })}
              </div>
            </aside>

            {/* Query Workspace */}
            <div className="contest-query-workspace">
              {activeQuestion ? (
                <>
                  <div className="contest-prompt-pane">
                    <h2>
                      <span><TranslatedText><TranslatedText>{tr("Question")}</TranslatedText></TranslatedText> {activeQuestionIndex + 1}: {activeQuestion.title}</span>
                      <span className="contest-prompt-points">{activeQuestion.points ?? 10} <TranslatedText>{tr("points")}</TranslatedText></span>
                    </h2>
                    <div style={{ fontSize: 13, lineHeight: 1.6, color: "var(--text)" }}>
                      <ProblemMarkdown>{activeQuestion.description || tr("No description available.")}</ProblemMarkdown>
                    </div>
                  </div>

                  <div className="contest-query-editor-area">
                    <div className="contest-editor-header">
                      <span><TranslatedText>{tr("Your SQL query")}</TranslatedText></span>
                      <div className="contest-editor-actions">
                        <button
                          type="button"
                          className="contest-btn-secondary"
                          onClick={() => setShowFullscreenDiagram(true)}
                          title={tr("View the database diagram while writing your query")}
                        >
                          <LayoutGrid size={13} />
                          <span><TranslatedText>{tr("View database diagram")}</TranslatedText></span>
                        </button>
                        <button
                          type="button"
                          className="contest-btn-primary"
                          disabled={queryRunning}
                          onClick={handleRunQuery}
                        >
                          <Play size={13} />
                          <span><TranslatedText>{queryRunning ? tr("Running...") : tr("▶ Run query")}</TranslatedText></span>
                        </button>
                      </div>
                    </div>

                    <div style={{ flex: 1, overflow: "auto" }}>
                      <CodeMirror
                        value={queryAnswers[activeQuestion.id] || ""}
                        height="100%"
                        extensions={sqlEditorExtensions}
                        onChange={(val) => {
                          setQueryAnswers((prev) => ({ ...prev, [activeQuestion.id]: val }));
                        }}
                        placeholder={`-- Write your query for question ${activeQuestionIndex + 1} here...\nSELECT ...`}
                        basicSetup={{ foldGutter: true, highlightActiveLine: true }}
                      />
                    </div>

                    {/* Result table pane */}
                    <div className="contest-query-result-pane">
                      <div className="contest-result-header">
                        <span><TranslatedText>{tr("Exam query results")}</TranslatedText></span>
                        {queryResult && !queryResult.error && (
                          <span style={{ color: "#22c55e" }}>
                            <TranslatedText>{tr("✓ Returned")}</TranslatedText> {queryResult.rowCount} <TranslatedText>{tr("rows")}</TranslatedText>
                          </span>
                        )}
                      </div>
                      <div style={{ flex: 1, overflow: "auto", padding: 12 }}>
                        {queryRunning && <Loading variant="inline" rows={4} label={tr("Executing the query on the exam database...")} />}
                        {queryResult?.error && (
                          <div style={{ color: "#ef4444", padding: 12, background: "rgba(239, 68, 68, 0.08)", borderRadius: 6, fontSize: 13 }}>
                            <AlertCircle size={16} style={{ display: "inline", verticalAlign: "middle", marginRight: 6 }} />
                            <span><TranslatedText>{tr(queryResult.error)}</TranslatedText></span>
                          </div>
                        )}
                        {queryResult && !queryResult.error && queryResult.columns.length > 0 && (
                          <DataGrid
                            table={{
                              name: "Query results",
                              columns: queryResult.columns,
                              rows: queryResult.rows,
                            }}
                          />
                        )}
                        {!queryRunning && !queryResult && (
                          <div style={{ color: "var(--muted)", fontSize: 13, textAlign: "center", padding: 20 }}>
                            <TranslatedText>{tr("Click \"Run query\" to check the results from your database.")}</TranslatedText>
                          </div>
                        )}
                      </div>
                    </div>
                  </div>
                </>
              ) : (
                <div style={{ padding: 40, textAlign: "center", color: "var(--muted)" }}>
                  <TranslatedText>{tr("No questions available.")}</TranslatedText>
                </div>
              )}
            </div>
          </div>
  );
}
