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

import type { ReviewDataset, ReviewResult, ReviewSection, TeacherSubmissionDetail } from "@/data/submissionReview";

import type { WorkspacePart } from "@/data/models";

import { WORKSPACE_PART_LABELS } from "@/shared/lib/workspace/workspaceParts";

import { submissionTimeLabel } from "@/shared/lib/datetime/serverDateTime";

import { TeacherField } from "@/features/teacher/components/TeacherPageParts";

import { TeacherSchemaComparison, type SchemaChange } from "@/features/teacher/problem-editor/TeacherSchemaComparison";

import { TeacherDataComparison, type DataChange } from "@/features/teacher/problem-editor/TeacherDataComparison";

import "@/features/teacher/submissions/SubmissionReviewPage.css";
import { notify } from "@/shared/ui/notify";



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



type QuestionDraft = { score: string; feedback: string };

/** Giá trị ban đầu của các ô chấm theo câu. Người phúc khảo chỉ thấy bản của chính mình (chưa chấm thì để trống). */
function savedDrafts(submission: TeacherSubmissionDetail, viewerId?: string): Record<string, QuestionDraft> {
  const isRegrader = Boolean(viewerId) && submission.regradeAssignment?.reviewerId === viewerId;
  const own = submission.grades?.regrade;
  return Object.fromEntries(Object.keys(submission.questionMaxScores || {}).map(key => {
    const score = isRegrader ? own?.questionScores?.[key] : submission.questionGrades?.[key]?.score;
    const feedback = isRegrader ? own?.questionFeedback?.[key] : submission.questionGrades?.[key]?.feedback;
    return [key, { score: score == null ? "" : String(score), feedback: feedback || "" }];
  }));
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

  

  // Bài thi: điểm và nhận xét đang nhập của từng câu, khóa "PHẦN:mã câu".
  const [drafts, setDrafts] = useState<Record<string, QuestionDraft>>({});

  const [saved, setSaved] = useState(false);

  const [isSaving, setIsSaving] = useState(false);

  const [inspections, setInspections] = useState<Record<string, ReviewResult>>({});

  const [inspecting, setInspecting] = useState("");

  const [inspectionErrors, setInspectionErrors] = useState<Record<string, string>>({});

  const activeSubmission = useRef(submissionId);

  activeSubmission.current = submissionId || submission?.id;

  useEffect(() => {

    setPart(submission?.reviewSections?.[0]?.part || "SQL"); setQuestionIds({}); setInspections({}); setInspectionErrors({}); setSaved(false);

  }, [submission?.id]);

  useEffect(() => {

    if (submission) { setFinalScore(submission.finalScore == null ? "" : String(submission.finalScore)); setFeedback(submission.feedback || ""); setDrafts(savedDrafts(submission, session?.id)); }

  }, [submission, session?.id]);

  const questionMax = submission?.questionMaxScores || {};
  const questionKeys = Object.keys(questionMax);
  const byQuestion = questionKeys.length > 0;
  const validScore = (key: string) => {
    const text = drafts[key]?.score.trim() ?? "";
    const value = Number(text);
    return text !== "" && Number.isFinite(value) && value >= 0 && value <= questionMax[key];
  };
  const gradedKeys = questionKeys.filter(validScore);
  const questionTotal = Math.round(gradedKeys.reduce((sum, key) => sum + Number(drafts[key].score), 0) * 100) / 100;
  const savedQuestionDrafts = submission ? savedDrafts(submission, session?.id) : {};
  const questionDirty = questionKeys.some(key => (drafts[key]?.score ?? "") !== (savedQuestionDrafts[key]?.score ?? "") || (drafts[key]?.feedback ?? "") !== (savedQuestionDrafts[key]?.feedback ?? ""));

  function editDraft(key: string, change: Partial<QuestionDraft>) {
    setDrafts(previous => ({ ...previous, [key]: { ...{ score: "", feedback: "" }, ...previous[key], ...change } }));
    setSaved(false);
  }

  async function saveReview() {

    if (!submission || submission.isSubmitted === false || isSaving) return;

    const score = Number(finalScore);

    if (byQuestion) {

      if (gradedKeys.length < questionKeys.length) { notify.error(`Chưa chấm đủ: còn ${questionKeys.length - gradedKeys.length} câu chưa có điểm hợp lệ.`); return; }

    } else if (!finalScore.trim() || !Number.isFinite(score) || score < 0 || score > submission.maxScore) {

      notify.error(tr("Enter a score from 0 to {value0}.", {value0:submission.maxScore})); return;

    }

    setSaved(false); setIsSaving(true);

    try {

      const updated = byQuestion
        ? await teacherService.updateSubmissionQuestionReview(
            submission.id,
            Object.fromEntries(questionKeys.map(key => [key, Number(drafts[key].score)])),
            Object.fromEntries(questionKeys.map(key => [key, drafts[key].feedback.trim()]).filter(([, text]) => text)),
          )
        : await teacherService.updateSubmissionReview(submission.id, score, feedback);

      if (activeSubmission.current === updated.id) { await setSubmission(updated); setSaved(true); notify.success(tr("Review saved")); }

    } catch (err) { if (activeSubmission.current === submission.id) notify.fail(tr("Failed to save review."), err, tr); }

    finally { setIsSaving(false); }

  }

  const returnToState = typeof location.state?.returnTo === "string" && (location.state.returnTo.startsWith(`${root}/`) || location.state.returnTo.startsWith("/teacher/") || location.state.returnTo.startsWith("/office/")) ? location.state.returnTo : null;

  const defaultBack = submission?.activityId ? (examReview ? `/teacher/exams/${encodeURIComponent(submission.activityId)}/grade` : `${root}/results/activity/${encodeURIComponent(submission.activityId)}`) : listPath;

  const back = returnToState || defaultBack;

  if (loading) return <section className="teacher-page teacher-review-workspace"><Loading variant="detail" label={tr("Loading submission…")} /></section>;

  if (error || !submission) return <section className="teacher-page"><Link to={returnToState || listPath}><TranslatedText>{tr("Back to results")}</TranslatedText></Link><ErrorState title={tr("Error loading submission.")} message={error} /></section>;

  const sections: ReviewSection[] = submission.reviewSections?.length ? submission.reviewSections : [{part:"SQL",questions:[{id:"legacy",description:"",studentSql:submission.query,referenceSql:submission.referenceSolution || "",setupDdl:"",setupDml:""}]}];

  const section = sections.find(item => item.part === part) || sections[0];

  const question = section.questions.find(item => item.id === questionIds[section.part]) || section.questions[0];

  const questionIndex = section.questions.indexOf(question);

  const inspectionKey = `${submission.id}:${section.part}:${question?.id}`;

  const history = submission.attemptHistory || [];
  // Lượt có điểm tự động cao nhất; bằng điểm thì lấy lượt làm sau cùng.
  const bestAttemptId = history.reduce<{ id: string; score: number } | null>((best, item) => !best || item.autoScore >= best.score ? { id: item.id, score: item.autoScore } : best, null)?.id;

  const isReadOnlyReviewer =
    !canGrade ||
    root === "/office" ||
    submission.canEditReview === false ||
    (Boolean(submission.regradeAssignment?.completedAt) &&
      session?.id !== submission.regradeAssignment?.reviewerId);

  const dirty = byQuestion
    ? questionDirty
    : finalScore !== (submission.finalScore == null ? "" : String(submission.finalScore)) || feedback !== (submission.feedback || "");

  const regradePending = Boolean(submission.regradeAssignment) && !submission.regradeAssignment?.completedAt;
  // Điểm cuối hiển thị khi chấm theo câu: đang nhập (hoặc phúc khảo chưa chấm) thì là tổng đang nhập, còn lại là điểm đã lưu.
  const showRunningTotal = !isReadOnlyReviewer && (dirty || submission.finalScore == null || (regradePending && session?.id === submission.regradeAssignment?.reviewerId));
  const totalText = showRunningTotal ? (gradedKeys.length ? formatPoints(questionTotal) : "—") : submission.finalScore != null ? formatPoints(submission.finalScore) : "—";
  const saveBlocked = byQuestion ? gradedKeys.length < questionKeys.length : !finalScore.trim();

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

<div className="review-workspace-grid">

      <main className="review-answer-panel">

        <nav className="review-section-tabs" aria-label={tr("Query type")}>{sections.map(item => <button key={item.part} type="button" aria-pressed={section.part === item.part} onClick={() => setPart(item.part)}><strong>{WORKSPACE_PART_LABELS[item.part]}</strong><span>{item.questions.length}</span>{item.status && <i className={`review-result-dot ${item.status === "Accepted" ? "is-correct" : "is-different"}`} title={tr(item.status)} />}</button>)}</nav>

        <div className="review-answer-layout">

          <nav className="review-question-list" aria-label={tr("Questions")}>{section.questions.map((item,index) => {
            const itemStatus = inspections[`${submission.id}:${section.part}:${item.id}`]?.status || item.result?.status;
            const itemKey = `${section.part}:${item.id}`;
            return <button type="button" key={item.id} aria-current={question?.id === item.id ? "true" : undefined} onClick={() => setQuestionIds(previous => ({...previous,[section.part]:item.id}))}><span className="review-question-number">{String(index + 1).padStart(2,"0")}</span><span><strong>{tr("Question {value0}", { value0: index + 1 })}{itemKey in questionMax ? ` · ${validScore(itemKey) ? formatPoints(Number(drafts[itemKey].score)) : "—"}/${formatPoints(questionMax[itemKey])}` : ""}</strong><small><TranslatedText>{tr(item.description || "No question description has been provided.")}</TranslatedText></small></span>{itemStatus && <i title={tr(itemStatus)} className={`review-result-dot ${itemStatus === "Accepted" ? "is-correct" : "is-different"}`} />}</button>;
          })}</nav>

          {question && (() => {
            const displayStatus = inspections[inspectionKey]?.status || question.result?.status;
            return <article className="review-question-detail" key={inspectionKey}>

            <header className="review-question-title"><span>{WORKSPACE_PART_LABELS[section.part]} · <TranslatedText>{tr("Question")}</TranslatedText> {questionIndex + 1} / {section.questions.length}</span>{displayStatus && <Status value={displayStatus} />}</header>

            <div className="review-question-prompt"><ProblemMarkdown>{tr(question.description || "No question description has been provided.")}</ProblemMarkdown></div>

            {question.sourceSubmittedAt && <p className="review-inspection-note"><TranslatedText>{tr("This snapshot had no answer for this question. Showing the saved answer submitted at {value0}.",{value0:submissionTimeLabel(question.sourceSubmittedAt,locale)})}</TranslatedText></p>}

            {(question.setupSql || question.setupDdl || question.setupDml) && <details className="review-setup"><summary><TranslatedText>{tr("SQL preparation for this question")}</TranslatedText></summary><pre>{[question.setupSql,question.setupDdl,question.setupDml].filter(Boolean).join("\n\n")}</pre></details>}

            <div className="review-sql-pair">{([[tr("Student SQL"),question.studentSql],[tr("Current reference SQL"),question.referenceSql]] as const).map(([label,value]) => <section key={label}><h2>{label}</h2>{value.trim() ? <CodeMirror value={value} readOnly editable={false} extensions={sqlEditorExtensions} minHeight="160px" maxHeight="420px" basicSetup={{lineNumbers:true,foldGutter:false,highlightActiveLine:false,highlightActiveLineGutter:false}} aria-label={label} /> : <p className="review-no-answer"><TranslatedText>{tr(label === tr("Student SQL") ? "No answer was submitted for this question." : "No reference solution provided")}</TranslatedText></p>}</section>)}</div>

            {inspectionKey && `${section.part}:${question.id}` in questionMax && (() => {
              const key = `${section.part}:${question.id}`;
              const draft = drafts[key] || { score: "", feedback: "" };
              const invalid = draft.score.trim() !== "" && !validScore(key);
              const locked = isReadOnlyReviewer || isSaving || submission.isSubmitted === false;
              const others = submission.regradeAssignment ? ([
                ["Chấm lần đầu", submission.grades?.initial],
                ["Phúc khảo", session?.id !== submission.regradeAssignment.reviewerId ? submission.grades?.regrade : null],
              ] as const) : [];
              return <section className="review-question-grading" aria-label="Chấm câu này">
                <header><h2>Chấm câu này</h2><div className="review-score-input"><input aria-label="Điểm câu" type="number" min="0" max={questionMax[key]} step="any" value={draft.score} disabled={locked} aria-invalid={invalid || undefined} placeholder="—" onChange={event => editDraft(key, { score: event.target.value })} /><span>/ {formatPoints(questionMax[key])}</span></div></header>
                {invalid && <p className="teacher-state-error" role="alert">Nhập từ 0 đến {formatPoints(questionMax[key])}.</p>}
                <textarea aria-label="Nhận xét câu" rows={3} maxLength={5000} value={draft.feedback} disabled={locked} placeholder={isReadOnlyReviewer ? "Chưa có nhận xét." : "Nhận xét cho câu này…"} onChange={event => editDraft(key, { feedback: event.target.value })} />
                {others.map(([label, entry]) => entry && entry.questionScores?.[key] != null && <p className="review-question-other-grade" key={label}><b>{label}{entry.graderName ? ` · ${entry.graderName}` : ""}: {formatPoints(entry.questionScores[key])}</b>{entry.questionFeedback?.[key] ? ` — ${entry.questionFeedback[key]}` : ""}</p>)}
              </section>;
            })()}

            <section className="review-results-section"><header><h2><TranslatedText>{tr(inspections[inspectionKey] ? "Latest comparison" : "Saved automatic result")}</TranslatedText></h2><button className="button" type="button" disabled={submission.isSubmitted === false || Boolean(inspecting) || !question.studentSql.trim() || !question.referenceSql.trim()} onClick={() => void inspect()}><Play size={14} /><TranslatedText>{tr(inspecting === inspectionKey ? "Comparing…" : "Run comparison")}</TranslatedText></button></header>

              {inspectionErrors[inspectionKey] && <p className="teacher-state-error" role="alert">{inspectionErrors[inspectionKey]}</p>}

              <ReviewOutput result={inspections[inspectionKey] || question.result} fresh={Boolean(inspections[inspectionKey])} />

            </section>

          </article>;
          })()}

        </div>

      </main>

      <aside className="review-grading-panel"><header className="review-panel-title"><h2>{submission.problem}</h2></header><dl><div><dt><TranslatedText>{tr("Status")}</TranslatedText></dt><dd><Status value={submission.status} /></dd></div><div><dt><TranslatedText>{tr("Student")}</TranslatedText></dt><dd>{submission.student}</dd></div><div><dt><TranslatedText>{tr("Submitted at")}</TranslatedText></dt><dd>{submission.submittedAt ? submissionTimeLabel(submission.submittedAt, locale) : "Chưa nộp"}</dd></div>{history.length > 1 && <div><dt><TranslatedText>{tr("Attempt")}</TranslatedText></dt><dd><Select aria-label={tr("Attempt")} disabled={isSaving || submission.isSubmitted === false} value={submission.id} onChange={event => navigate(`${reviewBase}/${encodeURIComponent(event.target.value)}`,{state:{returnTo:back}})}>{history.map(item => <option key={item.id} value={item.id}>{tr("Attempt")} {item.number} · {item.finalScore ?? item.autoScore} / {formatPoints(submission.maxScore)}{item.id === bestAttemptId ? ` · ${tr("Highest score")}` : ""}</option>)}</Select></dd></div>}<div><dt><TranslatedText>{tr("Automatic score")}</TranslatedText></dt><dd>{formatPoints(submission.autoScore)} <small>/ {formatPoints(submission.maxScore)}</small></dd></div>{(submission.regradeAssignment?.previousEvaluatedScore != null || submission.previousEvaluatedScore != null) && <div><dt>Điểm trước phúc khảo</dt><dd>{formatPoints(submission.regradeAssignment?.previousEvaluatedScore ?? submission.previousEvaluatedScore)} <small>/ {formatPoints(submission.maxScore)}</small></dd></div>}{submission.regradeAssignment && <div><dt>Phúc khảo</dt><dd>{submission.regradeAssignment.reviewerName}{submission.regradeAssignment.completedAt ? " · Đã chấm" : " · Chờ chấm"}</dd></div>}{submission.regradeAssignment && submission.grades?.initial && <div><dt>Chấm lần đầu</dt><dd>{submission.grades.initial.score != null ? <>{formatPoints(submission.grades.initial.score)} <small>/ {formatPoints(submission.maxScore)}{submission.grades.initial.graderName ? ` · ${submission.grades.initial.graderName}` : ""}</small></> : "Chưa có điểm"}{submission.grades.initial.feedback && <small style={{display:"block",whiteSpace:"pre-wrap"}}>{submission.grades.initial.feedback}</small>}</dd></div>}{submission.regradeAssignment && submission.grades?.regrade && session?.id !== submission.regradeAssignment.reviewerId && <div><dt>Điểm phúc khảo</dt><dd>{submission.grades.regrade.score != null ? <>{formatPoints(submission.grades.regrade.score)} <small>/ {formatPoints(submission.maxScore)}{submission.grades.regrade.graderName ? ` · ${submission.grades.regrade.graderName}` : ""}</small></> : "Chưa có điểm"}{submission.grades.regrade.feedback && <small style={{display:"block",whiteSpace:"pre-wrap"}}>{submission.grades.regrade.feedback}</small>}</dd></div>}{submission.regradeAssignment?.note && <div><dt>Ghi chú phân công</dt><dd>{submission.regradeAssignment.note}</dd></div>}</dl>

        {byQuestion ? <div className="review-question-total"><span>{tr("FINAL SCORE")}</span><p><strong>{totalText}</strong> / {formatPoints(submission.maxScore)}</p><small>Đã chấm {gradedKeys.length}/{questionKeys.length} câu · tổng điểm các câu</small></div> : <><TeacherField label={tr("FINAL SCORE")}><div className="review-score-input"><input aria-label={tr("Final score")} type="number" min="0" max={submission.maxScore} step="any" value={finalScore} onChange={event => {setFinalScore(event.target.value);setSaved(false);}} readOnly={isReadOnlyReviewer} disabled={isReadOnlyReviewer || isSaving || submission.isSubmitted === false} placeholder="0" /><span>/ {formatPoints(submission.maxScore)}</span></div></TeacherField>

        {!isReadOnlyReviewer && submission.autoScore != null && <button type="button" className="review-use-auto-score" disabled={isSaving || submission.isSubmitted === false} onClick={() => {setFinalScore(String(submission.autoScore));setSaved(false);}}><TranslatedText>{tr("Use automatic score")}</TranslatedText></button>}

        <TeacherField label={tr("FEEDBACK")}><textarea aria-label={tr("Feedback")} rows={6} value={feedback} readOnly={isReadOnlyReviewer} disabled={isReadOnlyReviewer || isSaving || submission.isSubmitted === false} placeholder={isReadOnlyReviewer ? "Chưa có nhận xét." : tr("Feedback for the student…")} onChange={event => {setFeedback(event.target.value);setSaved(false);}} /></TeacherField></>}


        {isReadOnlyReviewer ? (
          <p className="muted tiny">{root === "/office" ? "Giáo vụ khoa chỉ có quyền xem điểm đã chấm hoặc điểm sau khi phúc khảo." : !canGrade ? "Tài khoản của bạn chỉ có quyền xem điểm đã chấm hoặc điểm sau khi phúc khảo." : submission.canEditReview === false && !submission.regradeAssignment ? "Lần nộp này chỉ được xem để đối chiếu (không thuộc bài được phân công chấm phúc khảo)." : submission.regradeAssignment && !submission.regradeAssignment.completedAt ? "Bài nộp này đang được phân công chấm phúc khảo cho giảng viên khác." : "Bài nộp đã được chấm phúc khảo xong, không thể ghi đè."}</p>
        ) : (
          <>
            <button type="button" className="button primary review-save-button" onClick={() => void saveReview()} disabled={submission.isSubmitted === false || isSaving || saveBlocked || (!dirty && submission.finalScore != null && submission.status !== "Awaiting regrade" && !regradePending)}><Save size={15} /><TranslatedText>{tr(isSaving ? "Saving..." : "Save review")}</TranslatedText></button>

            <p role="status" className={`review-save-state${saved ? " is-saved" : ""}`}>{saved ? <><Check size={14} /><TranslatedText>{tr("Review saved")}</TranslatedText></> : dirty ? tr("Unsaved changes") : submission.finalScore != null ? tr("Saved in database") : tr("No manual changes yet.")}</p>
          </>
        )}

        {Boolean(submission.examEvents?.length) && <details className="review-exam-log"><summary><TranslatedText>{tr("Exam log · {value0} departures",{value0:submission.departureCount || 0})}</TranslatedText></summary><ol>{submission.examEvents!.map(event => <li key={event.id}><time>{submissionTimeLabel(event.at.endsWith('Z') ? event.at : event.at+'Z',locale)}</time><p><TranslatedText>{tr(({focus_lost:"Left the exam page",focus_returned:"Returned to the exam page",external_paste:"Pasted external content",clipboard_panel:"Pasted an internal copy using Windows clipboard",leave_attempt:"Attempted to leave the exam"} as Record<string,string>)[event.kind] || event.kind)}</TranslatedText><TranslatedText>{event.durationMs != null && event.durationMs > 0 ? tr(" · {value0} seconds",{value0:(event.durationMs/1000).toFixed(1)}) : ''}</TranslatedText></p></li>)}</ol></details>}{Boolean(submission.gradeHistory?.length) && <details className="review-exam-log"><summary>Lịch sử chấm · {submission.gradeHistory!.length} lần</summary><ol>{submission.gradeHistory!.map((entry, index) => <li key={index}><time>{entry.gradedAt ? submissionTimeLabel(entry.gradedAt.endsWith('Z') ? entry.gradedAt : entry.gradedAt+'Z',locale) : ""}</time><p>{entry.kind === "regrade" ? "Phúc khảo" : "Chấm lần đầu"}{entry.graderName ? ` · ${entry.graderName}` : ""} · {entry.score != null ? `${formatPoints(entry.score)} / ${formatPoints(submission.maxScore)}` : "Chưa có điểm"}{entry.feedback ? ` · ${entry.feedback}` : ""}</p></li>)}</ol></details>}{Boolean(submission.questionSubmissions?.length) && <details className="review-exam-log"><summary>Nộp từng câu · {submission.questionSubmissions!.length} lần</summary><ol>{submission.questionSubmissions!.map(entry => <li key={entry.id}><time>{entry.submittedAt ? submissionTimeLabel(entry.submittedAt.endsWith('Z') ? entry.submittedAt : entry.submittedAt+'Z',locale) : ""}</time><p>{entry.part} · {entry.questionId} · {entry.status === "Accepted" ? "Đúng" : "Sai"}{entry.total > 0 ? ` (${entry.passed}/${entry.total})` : ""}</p></li>)}</ol></details>}

      </aside>

    </div>

  </section>;

}
