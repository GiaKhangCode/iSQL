import {Select} from "@/shared/ui/Select";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import { useState } from "react";
import CodeMirror from "@uiw/react-codemirror";
import { ChevronDown, Copy, Check } from "lucide-react";
import type { Submission, WorkspacePart } from "@/data/models";
import { WORKSPACE_PART_LABELS } from "@/shared/lib/workspace/workspaceParts";
import { readQuestionDrafts } from "@/shared/lib/sql/sqlQuestionDrafts";
import { sqlEditorExtensions } from "@/shared/components/sqlEditorExtensions";
import { useLanguage } from "@/shared/context/LanguageContext";
import { DataGrid, Status } from "@/shared/ui/ui";
import { SqlErrorMessage } from "@/shared/components/SqlErrorMessage";
import "@/shared/components/SubmissionQuestions.css";

/** Read this attempt's immutable snapshot; never combine it with live drafts. */
export function submissionQuestionGroups(submission: Submission) {
  const script = submission.query || "";
  const trimmed = script.trimStart();
  if (trimmed.startsWith("{")) {
    try {
      const parsed = JSON.parse(trimmed) as Record<string, any>;
      if (parsed && typeof parsed === "object" && ("ddl" in parsed || "dml" in parsed || "query" in parsed || "trigger" in parsed || "questions" in parsed)) {
        const partKeys: [WorkspacePart, string][] = [["DDL", "ddl"], ["DML", "dml"], ["SQL", "query"], ["TRIGGER", "trigger"]];
        const qMap = (parsed.questions && typeof parsed.questions === "object") ? parsed.questions : {};
        const groups = partKeys
          .map(([part, key]) => {
            const result = submission.sections?.find(section => section.part === part);
            const authoredIds = result?.questions?.map(q => q.id) || [];
            const partQ = (qMap[part] && typeof qMap[part] === "object") ? qMap[part] as Record<string, string> : {};
            const partText = typeof parsed[key] === "string" ? parsed[key] : "";
            const hasContent = Boolean(result) || Object.values(partQ).some(v => String(v || "").trim()) || partText.trim().length > 0;
            if (!hasContent) return null;
            const fallbackAnswers = readQuestionDrafts(partText, authoredIds.length ? authoredIds : ["__single__"]);
            const ids = [...new Set([...authoredIds, ...Object.keys(partQ), ...Object.keys(fallbackAnswers)])];
            return {
              part,
              result,
              questions: ids.map(id => ({
                id,
                script: (partQ[id] ?? fallbackAnswers[id] ?? "") as string,
                result: result?.questions?.find(question => question.id === id),
              })),
            };
          })
          .filter((g): g is NonNullable<typeof g> => Boolean(g));
        if (groups.length) return groups;
      }
    } catch {
      // Fallback to marker parsing when query_text is plain SQL
    }
  }
  const markers = [...script.matchAll(/^-- (DDL|DML|SQL|TRIGGER)\r?\n/gm)];
  const parts = markers.length ? markers.map((marker, index) => ({
    part: marker[1] as WorkspacePart,
    script: script.slice(marker.index! + marker[0].length, markers[index + 1]?.index ?? script.length),
  })) : [{ part: "SQL" as WorkspacePart, script }];
  return parts.map(({ part, script }) => {
    const result = submission.sections?.find(section => section.part === part);
    const authoredIds = result?.questions?.map(question => question.id) || [];
    const answers = readQuestionDrafts(script, authoredIds.length ? authoredIds : ["__single__"]);
    const ids = [...new Set([...(result?.questions?.map(question => question.id) || []), ...Object.keys(answers)])];
    return { part, result, questions: ids.map(id => ({
      id, script: answers[id] || "", result: result?.questions?.find(question => question.id === id),
    })) };
  });
}

export function SubmissionQuestions({ submission }: { submission: Submission }) {
  const { t: tr } = useLanguage("workspace");
  const groups = submissionQuestionGroups(submission);
  const [part, setPart] = useState(groups[0]?.part || "SQL");
  const [questionIndex, setQuestionIndex] = useState(0);
  const [copied, setCopied] = useState(false);
  const group = groups.find(group => group.part === part) || groups[0];
  const question = group?.questions[questionIndex] || group?.questions[0];
  if (!group || !question) return null;
  const partLabel = WORKSPACE_PART_LABELS[group.part];
  const visible = submission.scoreVisible !== false;
  const comparisons = group.result?.comparisons?.filter(item => item.question == null || item.question === questionIndex + 1) || [];
  const label = (index: number) => tr("Question {value0} {value1}", { value0: index + 1, value1: partLabel });
  return <section className="submission-questions" aria-label={tr("Submitted SQL")}>
    <nav className="submission-part-tabs" aria-label={tr("Query type")}>
      {groups.map(group => <button key={group.part} type="button" aria-pressed={group.part === part} onClick={() => { setPart(group.part); setQuestionIndex(0); setCopied(false); }}>
        {WORKSPACE_PART_LABELS[group.part]}<span>{group.questions.length}</span>
      </button>)}
    </nav>
    <div className="submission-question-picker">
      <label><span><TranslatedText>{tr("Question")}</TranslatedText></span><Select aria-label={tr("Question")} value={questionIndex} onChange={event => {setQuestionIndex(Number(event.target.value));setCopied(false);}}>
        {group.questions.map((question, index) => <option key={question.id} value={index}>{label(index)}</option>)}
      </Select><ChevronDown size={16} aria-hidden="true"/></label>
      {visible && question.result && <Status value={question.result.status}/>}
    </div>
    {question.result?.description && <p className="submission-question-prompt">{question.result.description}</p>}
    <div className="submission-question-code-heading"><strong><TranslatedText>{tr("Submitted SQL")}</TranslatedText></strong><button type="button" className="text-button" disabled={!question.script} onClick={async () => {
      try {await navigator.clipboard.writeText(question.script);setCopied(true);} catch {setCopied(false);}
    }}>{copied?<Check size={14}/>:<Copy size={14}/>} <TranslatedText>{tr(copied?"Copied":"Copy")}</TranslatedText></button></div>
    {question.script.trim() ? <div className="workspace-submission-code"><CodeMirror value={question.script} extensions={sqlEditorExtensions} readOnly editable={false} minHeight="140px" maxHeight="420px" aria-label={`${tr("Submitted SQL")} · ${label(questionIndex)}`} basicSetup={{lineNumbers:true,foldGutter:false,highlightActiveLine:false,highlightActiveLineGutter:false}}/></div> : <p className="submission-question-empty"><TranslatedText>{tr("No answer was submitted for this question.")}</TranslatedText></p>}
    {visible && question.result && <div className="submission-question-verdict">
      <strong><TranslatedText>{tr("Result")}</TranslatedText> <span>{Math.round(question.result.ratio * 100)}%</span></strong>
      {question.result.datasets?.map(dataset => <div key={dataset.dataset}><small><TranslatedText>{tr("Dataset")}</TranslatedText> {dataset.dataset}</small>{dataset.runtimeError?<SqlErrorMessage message={dataset.message}/>:<p><TranslatedText>{tr(dataset.message)}</TranslatedText></p>}</div>)}
    </div>}
    {visible && comparisons.map((comparison,index) => comparison.actualTable && <details className="submission-question-output" key={index}><summary><TranslatedText>{tr("Dataset")}</TranslatedText> {comparison.dataset} · <TranslatedText>{tr("Your result")}</TranslatedText></summary><DataGrid table={comparison.actualTable}/></details>)}
  </section>;
}
