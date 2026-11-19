import {Select} from "@/shared/ui/Select";

import {formatPoints} from "@/shared/lib/sql/formatPoints";

import { TranslatedText } from "@/shared/ui/TranslatedText";

import { useEffect, useRef, useState } from "react";

import { Link, useLocation, useNavigate, useParams } from "react-router-dom";

import { ArrowLeft, Check, ChevronRight, Play, Save } from "lucide-react";

import CodeMirror from "@uiw/react-codemirror";

import { useLanguage } from "@/shared/context/LanguageContext";

import { useLoad } from "@/shared/hooks/useLoad";

import { ErrorState, Loading, Status } from "@/shared/ui/ui";

import { useAuth } from "@/app/providers/AuthContext";

import { ProblemMarkdown } from "@/shared/components/ProblemMarkdown";

import { sqlEditorExtensions } from "@/shared/components/sqlEditorExtensions";

import { teacherService } from "@/shared/api/teacherService";

import type { ReviewDataset, ReviewResult, ReviewSection } from "@/data/submissionReview";

import type { WorkspacePart } from "@/data/models";

import { WORKSPACE_PART_LABELS } from "@/shared/lib/workspace/workspaceParts";

import { submissionTimeLabel } from "@/shared/lib/datetime/serverDateTime";

import { TeacherField } from "@/features/teacher/components/TeacherPageParts";

import { TeacherSchemaComparison, type SchemaChange } from "@/features/teacher/problem-editor/TeacherSchemaComparison";

import { TeacherDataComparison, type DataChange } from "@/features/teacher/problem-editor/TeacherDataComparison";

import "@/features/teacher/submissions/SubmissionReviewPage.css";



function snapshots<T extends { name: string }>(expected: T[], actual: T[]) {

  const before = new Map(expected.map(table => [table.name.toLowerCase(), table]));

  const after = new Map(actual.map(table => [table.name.toLowerCase(), table]));

  return [...new Set([...before.keys(), ...after.keys()])].map(key => ({

    name: (before.get(key) || after.get(key))!.name, before: before.get(key) || null, table: after.get(key) || null,

    change: before.has(key) ? after.has(key) ? "modified" as const : "dropped" as const : "created" as const,

  }));

}



function DatasetOutput({ dataset }: { dataset: ReviewDataset }) {

  const { t: tr } = useLanguage("teacher");

  if (dataset.scenarios?.length) return <ul className="review-trigger-scenarios">{dataset.scenarios.map(item => <li key={item.scenario}><b><TranslatedText>{tr("Scenario {value0}", { value0: item.scenario })}</TranslatedText></b>: <TranslatedText>{tr(item.matched ? "Matches the reference result." : item.message || "The data after the trigger fired differs from the reference.")}</TranslatedText></li>)}</ul>;
  if (dataset.actualTriggers || dataset.expectedTriggers) return <div className="review-sql-pair">{[[tr("Reference result"),dataset.expectedTriggers || []],[tr("Student result"),dataset.actualTriggers || []]].map(([label,triggers])=><section key={String(label)}><h3>{String(label)}</h3>{(triggers as NonNullable<ReviewDataset["actualTriggers"]>).map(trigger=><p key={trigger.name}>{trigger.name} → {trigger.table}<TranslatedText>{trigger.isDisabled?` · ${tr("Disabled")}`:""}</TranslatedText></p>)}</section>)}</div>;

  if (dataset.actualSchema || dataset.expectedSchema) return <TeacherSchemaComparison showConstraints changes={snapshots(dataset.expectedSchema || [], dataset.actualSchema || []) as SchemaChange[]} beforeLabel={tr("Reference result")} afterLabel={tr("Student result")} />;

  const actual = dataset.actualTables || (dataset.actualTable ? [dataset.actualTable] : []);

  const expected = dataset.expectedTables || (dataset.expectedTable ? [dataset.expectedTable] : []);

  // DQL result names are captions, not table identities.

  const changes = dataset.actualTable || dataset.expectedTable ? [{name:tr("Query result"),before:dataset.expectedTable || null,table:dataset.actualTable || null,change:"modified" as const}] : snapshots(expected, actual);

  return changes.length ? <TeacherDataComparison changes={changes as DataChange[]} beforeLabel={tr("Reference result")} afterLabel={tr("Student result")} /> : <p className="muted"><TranslatedText>{tr("No tables returned.")}</TranslatedText></p>;

}



function ReviewOutput({ result, fresh = false }: { result?: ReviewResult | null; fresh?: boolean }) {

  const { t: tr } = useLanguage("teacher");

  if (!result) return <p className="review-empty-output"><TranslatedText>{tr("No detailed result was saved. Run a comparison to inspect this answer.")}</TranslatedText></p>;

  const rows = result.datasets || result.comparisons || ((result.actualSchema || result.actualTables) ? [{dataset:1,...result}] : []);

  return <div className="review-output">

    {fresh && <p className="review-inspection-note"><TranslatedText>{tr("Compared with the current reference; the saved score is unchanged.")}</TranslatedText></p>}

    {!rows.length && <p className="muted"><TranslatedText>{tr(result.message || "No detailed result was saved. Run a comparison to inspect this answer.")}</TranslatedText></p>}

    {rows.map((row, index) => {

      const hasOutput = Boolean(row.actualTriggers || row.expectedTriggers || row.actualSchema || row.expectedSchema || row.actualTables || row.expectedTables || row.actualTable || row.expectedTable);

      const status = row.runtimeError ? "Runtime Error" : (row.matched ?? row.correct) ? "Matched" : "Different";

      const header = <><span><TranslatedText>{tr("Dataset")}</TranslatedText> {row.dataset}</span><Status value={status} tone={status === "Different" ? "warning" : undefined} />{row.message && <small><TranslatedText>{tr(row.message)}</TranslatedText></small>}</>;

      return hasOutput ? <details className="review-dataset" key={row.dataset} open={index === 0}><summary>{header}<ChevronRight size={14} /></summary><DatasetOutput dataset={row} /></details> : <div className="review-dataset-summary" key={row.dataset}>{header}</div>;

    })}

  </div>;

}



export function ManualReviewPage() {

  const { t: tr, locale } = useLanguage("teacher");

  const { session, permissions } = useAuth();
  // Chỉ người giữ results.grade mới sửa được điểm; còn lại (giáo vụ, trưởng bộ môn, admin) chỉ xem.
  const canGrade = Boolean(permissions?.includes("results.grade"));

  const { submissionId, activityId, studentId, problemId } = useParams();

  const location = useLocation();

  const root=location.pathname.startsWith("/admin/")?"/admin":location.pathname.startsWith("/office/")?"/office":"/teacher";

  const examReview = location.pathname.startsWith("/teacher/exams/");
  const reviewBase = examReview ? "/teacher/exams/review" : `${root}/results/review`;
  const listPath = examReview ? "/teacher/exams" : `${root}/results?kind=exams`;

  const navigate = useNavigate();

  const { data: submission, loading, error, mutate: setSubmission } = useLoad(() => submissionId ? teacherService.getSubmission(submissionId) : teacherService.getStudentWork(activityId!, studentId!, problemId!), [submissionId, activityId, studentId, problemId]);

  const [part, setPart] = useState<WorkspacePart>("DDL");

  const [questionIds, setQuestionIds] = useState<Partial<Record<WorkspacePart, string>>>({});

  const [finalScore, setFinalScore] = useState("");

  const [feedback, setFeedback] = useState("");

  const [saveError, setSaveError] = useState("");

  const [saved, setSaved] = useState(false);

  const [isSaving, setIsSaving] = useState(false);

  const [inspections, setInspections] = useState<Record<string, ReviewResult>>({});

  const [inspecting, setInspecting] = useState("");

  const [inspectionErrors, setInspectionErrors] = useState<Record<string, string>>({});

  const activeSubmission = useRef(submissionId);

  activeSubmission.current = submissionId || submission?.id;

  useEffect(() => {

    setPart(submission?.reviewSections?.[0]?.part || "SQL"); setQuestionIds({}); setInspections({}); setInspectionErrors({}); setSaved(false); setSaveError("");

  }, [submission?.id]);

  useEffect(() => {

    if (submission) { setFinalScore(submission.finalScore == null ? "" : String(submission.finalScore)); setFeedback(submission.feedback || ""); }

  }, [submission]);

  async function saveReview() {

    if (!submission || submission.isSubmitted === false || isSaving) return;

    const score = Number(finalScore);

    if (!finalScore.trim() || !Number.isFinite(score) || score < 0 || score > submission.maxScore) {

      setSaveError(tr("Enter a score from 0 to {value0}.", {value0:submission.maxScore})); return;

    }

    setSaveError(""); setSaved(false); setIsSaving(true);

    try {

      const updated = await teacherService.updateSubmissionReview(submission.id, score, feedback);

      if (activeSubmission.current === updated.id) { await setSubmission(updated); setSaved(true); }

    } catch (err) { if (activeSubmission.current === submission.id) setSaveError(err instanceof Error ? err.message : tr("Failed to save review.")); }

    finally { setIsSaving(false); }

  }

  const returnToState = typeof location.state?.returnTo === "string" && (location.state.returnTo.startsWith(`${root}/`) || location.state.returnTo.startsWith("/teacher/") || location.state.returnTo.startsWith("/office/")) ? location.state.returnTo : null;

  const defaultBack = submission?.activityId ? (examReview ? `/teacher/exams/${encodeURIComponent(submission.activityId)}/grade` : `${root}/results/activity/${encodeURIComponent(submission.activityId)}`) : listPath;

  const back = returnToState || defaultBack;

  if (loading) return <section className="teacher-page teacher-review-workspace"><Loading variant="detail" label={tr("Loading submission…")} /></section>;

  if (error || !submission) return <section className="teacher-page"><Link to={returnToState || listPath}><TranslatedText>{tr("Back to results")}</TranslatedText></Link><ErrorState title={tr("Error loading submission.")} message={error} /></section>;

  const sections: ReviewSection[] = submission.reviewSections?.length ? submission.reviewSections : [{part:"SQL",questions:[{id:"legacy",name:"query.sql",description:"",studentSql:submission.query,referenceSql:submission.referenceSolution || "",setupDdl:"",setupDml:""}]}];

  const section = sections.find(item => item.part === part) || sections[0];

  const question = section.questions.find(item => item.id === questionIds[section.part]) || section.questions[0];

  const questionIndex = section.questions.indexOf(question);

  const inspectionKey = `${submission.id}:${section.part}:${question?.id}`;

  const history = submission.attemptHistory || [];

  const isReadOnlyReviewer =
    !canGrade ||
    root === "/office" ||
    submission.canEditReview === false ||
    (Boolean(submission.regradeAssignment?.completedAt) &&
      session?.id !== submission.regradeAssignment?.reviewerId);

  const dirty = finalScore !== (submission.finalScore == null ? "" : String(submission.finalScore)) || feedback !== (submission.feedback || "");

  async function inspect() {

    if (!question || submission?.isSubmitted === false || inspecting) return;

    setInspecting(inspectionKey); setInspectionErrors(previous => ({...previous,[inspectionKey]:""}));

    try {

      const value = await teacherService.inspectSubmission(submission!.id, section.part, question.id);

      setInspections(previous => ({...previous,[inspectionKey]:value}));

    } catch (err) { setInspectionErrors(previous => ({...previous,[inspectionKey]:err instanceof Error ? err.message : tr("Comparison failed.")})); }

    finally { setInspecting(""); }

  }

  return <section className="teacher-page teacher-review-workspace">

    <Link className="review-back-link" to={back}><ArrowLeft size={15} /><TranslatedText>{submission.activityTitle || tr("Back to results")}</TranslatedText></Link>

    <header className="review-page-heading"><div><p className="muted"><TranslatedText>{tr("Review submission")}</TranslatedText> · {submission.student}</p><h1>{submission.problem}</h1><p className="muted">{(submission.submittedAt ? submissionTimeLabel(submission.submittedAt, locale) : "Sinh viên chưa nộp bài")} · #{submission.id.slice(0,8)}</p></div>

      <div className="review-attempt-control"><Status value={submission.status} />{history.length > 1 && <label><span><TranslatedText>{tr("Attempt")}</TranslatedText></span><Select aria-label={tr("Attempt")} disabled={isSaving || submission.isSubmitted === false} value={submission.id} onChange={event => navigate(`${reviewBase}/${encodeURIComponent(event.target.value)}`,{state:{returnTo:back}})}>{history.map(item => <option key={item.id} value={item.id}>{tr("Attempt")} {item.number} · {item.finalScore ?? item.autoScore} / {formatPoints(submission.maxScore)}</option>)}</Select></label>}</div>

    </header>

    <div className="review-workspace-grid">

      <main className="review-answer-panel">

        <nav className="review-section-tabs" aria-label={tr("Query type")}>{sections.map(item => <button key={item.part} type="button" aria-pressed={section.part === item.part} onClick={() => setPart(item.part)}><strong>{WORKSPACE_PART_LABELS[item.part]}</strong><span>{item.questions.length}</span>{item.status && <i className={`review-result-dot ${item.status === "Accepted" ? "is-correct" : "is-different"}`} title={tr(item.status)} />}</button>)}</nav>

        <div className="review-answer-layout">

          <nav className="review-question-list" aria-label={tr("Questions")}>{section.questions.map((item,index) => {
            const itemStatus = inspections[`${submission.id}:${section.part}:${item.id}`]?.status || item.result?.status;
            return <button type="button" key={item.id} aria-current={question?.id === item.id ? "true" : undefined} onClick={() => setQuestionIds(previous => ({...previous,[section.part]:item.id}))}><span className="review-question-number">{String(index + 1).padStart(2,"0")}</span><span><strong>{item.name}</strong><small><TranslatedText>{tr(item.description || "No question description has been provided.")}</TranslatedText></small></span>{itemStatus && <i title={tr(itemStatus)} className={`review-result-dot ${itemStatus === "Accepted" ? "is-correct" : "is-different"}`} />}</button>;
          })}</nav>

          {question && (() => {
            const displayStatus = inspections[inspectionKey]?.status || question.result?.status;
            return <article className="review-question-detail" key={inspectionKey}>

            <header className="review-question-title"><span>{WORKSPACE_PART_LABELS[section.part]} · <TranslatedText>{tr("Question")}</TranslatedText> {questionIndex + 1} / {section.questions.length}</span>{displayStatus && <Status value={displayStatus} />}</header>

            <div className="review-question-prompt"><ProblemMarkdown>{tr(question.description || "No question description has been provided.")}</ProblemMarkdown></div>

            {question.sourceSubmittedAt && <p className="review-inspection-note"><TranslatedText>{tr("This snapshot had no answer for this question. Showing the saved answer submitted at {value0}.",{value0:submissionTimeLabel(question.sourceSubmittedAt,locale)})}</TranslatedText></p>}

            {(question.setupSql || question.setupDdl || question.setupDml) && <details className="review-setup"><summary><TranslatedText>{tr("SQL preparation for this question")}</TranslatedText></summary><pre>{[question.setupSql,question.setupDdl,question.setupDml].filter(Boolean).join("\n\n")}</pre></details>}

            <div className="review-sql-pair">{([[tr("Student SQL"),question.studentSql],[tr("Current reference SQL"),question.referenceSql]] as const).map(([label,value]) => <section key={label}><h2>{label}</h2>{value.trim() ? <CodeMirror value={value} readOnly editable={false} extensions={sqlEditorExtensions} minHeight="160px" maxHeight="420px" basicSetup={{lineNumbers:true,foldGutter:false,highlightActiveLine:false,highlightActiveLineGutter:false}} aria-label={label} /> : <p className="review-no-answer"><TranslatedText>{tr(label === tr("Student SQL") ? "No answer was submitted for this question." : "No reference solution provided")}</TranslatedText></p>}</section>)}</div>

            <section className="review-results-section"><header><h2><TranslatedText>{tr(inspections[inspectionKey] ? "Latest comparison" : "Saved automatic result")}</TranslatedText></h2><button className="button" type="button" disabled={submission.isSubmitted === false || Boolean(inspecting) || !question.studentSql.trim() || !question.referenceSql.trim()} onClick={() => void inspect()}><Play size={14} /><TranslatedText>{tr(inspecting === inspectionKey ? "Comparing…" : "Run comparison")}</TranslatedText></button></header>

              {inspectionErrors[inspectionKey] && <p className="teacher-state-error" role="alert">{inspectionErrors[inspectionKey]}</p>}

              <ReviewOutput result={inspections[inspectionKey] || question.result} fresh={Boolean(inspections[inspectionKey])} />

            </section>

          </article>;
          })()}

        </div>

      </main>

      <aside className="review-grading-panel"><h2><TranslatedText>{tr("Grading")}</TranslatedText></h2><dl><div><dt><TranslatedText>{tr("Student")}</TranslatedText></dt><dd>{submission.student}</dd></div><div><dt><TranslatedText>{tr("Automatic score")}</TranslatedText></dt><dd>{formatPoints(submission.autoScore)} <small>/ {formatPoints(submission.maxScore)}</small></dd></div>{(submission.regradeAssignment?.previousEvaluatedScore != null || submission.previousEvaluatedScore != null) && <div><dt>Điểm trước phúc khảo</dt><dd>{formatPoints(submission.regradeAssignment?.previousEvaluatedScore ?? submission.previousEvaluatedScore)} <small>/ {formatPoints(submission.maxScore)}</small></dd></div>}{submission.regradeAssignment && <div><dt>Phúc khảo</dt><dd>{submission.regradeAssignment.reviewerName}{submission.regradeAssignment.completedAt ? " · Đã chấm" : " · Chờ chấm"}</dd></div>}{submission.appealReason && <div><dt>Lý do phúc khảo</dt><dd>{submission.appealReason}</dd></div>}{submission.regradeAssignment?.note && <div><dt>Ghi chú phân công</dt><dd>{submission.regradeAssignment.note}</dd></div>}</dl>

        <TeacherField label={tr("FINAL SCORE")}><div className="review-score-input"><input aria-label={tr("Final score")} type="number" min="0" max={submission.maxScore} step="any" value={finalScore} onChange={event => {setFinalScore(event.target.value);setSaved(false);}} readOnly={isReadOnlyReviewer} disabled={isReadOnlyReviewer || isSaving || submission.isSubmitted === false} placeholder="0" /><span>/ {formatPoints(submission.maxScore)}</span></div></TeacherField>

        {!isReadOnlyReviewer && submission.autoScore != null && <button type="button" className="review-use-auto-score" disabled={isSaving || submission.isSubmitted === false} onClick={() => {setFinalScore(String(submission.autoScore));setSaved(false);}}><TranslatedText>{tr("Use automatic score")}</TranslatedText></button>}

        <TeacherField label={tr("FEEDBACK")}><textarea aria-label={tr("Feedback")} rows={6} value={feedback} readOnly={isReadOnlyReviewer} disabled={isReadOnlyReviewer || isSaving || submission.isSubmitted === false} placeholder={isReadOnlyReviewer ? "Chưa có nhận xét." : tr("Feedback for the student…")} onChange={event => {setFeedback(event.target.value);setSaved(false);}} /></TeacherField>

        {saveError && <p role="alert" className="teacher-state-error"><TranslatedText>{tr(saveError)}</TranslatedText></p>}

        {isReadOnlyReviewer ? (
          <p className="muted tiny">{root === "/office" ? "Giáo vụ khoa chỉ có quyền xem điểm đã chấm hoặc điểm sau khi phúc khảo." : !canGrade ? "Tài khoản của bạn chỉ có quyền xem điểm đã chấm hoặc điểm sau khi phúc khảo." : submission.canEditReview === false && !submission.regradeAssignment ? "Lần nộp này chỉ được xem để đối chiếu (không thuộc bài được phân công chấm phúc khảo)." : submission.regradeAssignment && !submission.regradeAssignment.completedAt ? "Bài nộp này đang được phân công chấm phúc khảo cho giảng viên khác." : "Bài nộp đã được chấm phúc khảo xong, không thể ghi đè."}</p>
        ) : (
          <>
            <button type="button" className="button primary review-save-button" onClick={() => void saveReview()} disabled={submission.isSubmitted === false || isSaving || !finalScore.trim() || (!dirty && submission.finalScore != null && submission.status !== "Awaiting regrade" && !(Boolean(submission.regradeAssignment) && !submission.regradeAssignment?.completedAt))}><Save size={15} /><TranslatedText>{tr(isSaving ? "Saving..." : "Save review")}</TranslatedText></button>

            <p role="status" className={`review-save-state${saved ? " is-saved" : ""}`}>{saved ? <><Check size={14} /><TranslatedText>{tr("Review saved")}</TranslatedText></> : dirty ? tr("Unsaved changes") : submission.finalScore != null ? tr("Saved in database") : tr("No manual changes yet.")}</p>
          </>
        )}

        {Boolean(submission.examEvents?.length) && <details className="review-exam-log"><summary><TranslatedText>{tr("Exam log · {value0} departures",{value0:submission.departureCount || 0})}</TranslatedText></summary><ol>{submission.examEvents!.map(event => <li key={event.id}><time>{submissionTimeLabel(event.at.endsWith('Z') ? event.at : event.at+'Z',locale)}</time><p><TranslatedText>{tr(({focus_lost:"Left the exam page",focus_returned:"Returned to the exam page",external_paste:"Pasted external content",clipboard_panel:"Pasted an internal copy using Windows clipboard",leave_attempt:"Attempted to leave the exam"} as Record<string,string>)[event.kind] || event.kind)}</TranslatedText><TranslatedText>{event.durationMs != null && event.durationMs > 0 ? tr(" · {value0} seconds",{value0:(event.durationMs/1000).toFixed(1)}) : ''}</TranslatedText></p></li>)}</ol></details>}

      </aside>

    </div>

  </section>;

}
