import {formatPoints} from "@/shared/lib/sql/formatPoints";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import { useEffect, useId, useLayoutEffect, useRef, useState, type ReactNode } from "react";
import { DataGrid, Loading, Status } from "@/shared/ui/ui";
import { useLanguage } from "@/shared/context/LanguageContext";
import type { DataTable, WorkspacePart } from "@/data/models";
import type { SchemaTable } from "@/shared/lib/sql/parseDatabaseSchema";
import type { QueryResult } from "@/shared/types/queryResult";
import type { WorkspaceAttempt } from "@/shared/types/workspaceAttempt";
export type { WorkspaceAttempt } from "@/shared/types/workspaceAttempt";
import type { ProblemQuestion } from "@/shared/components/ProblemQuestionContent";
import { WORKSPACE_PARTS, WORKSPACE_PART_LABELS } from "@/shared/lib/workspace/workspaceParts";
import { SqlErrorMessage } from "@/shared/components/SqlErrorMessage";
import "@/features/workspace/components/WorkspaceSubmissionPanel.css";
import { submittedQuestionScript } from "@/shared/lib/sql/sqlQuestionDrafts";
import CodeMirror from "@uiw/react-codemirror";
import { sqlEditorExtensions } from "@/shared/components/sqlEditorExtensions";

type SubmitQuestion = (attempt:WorkspaceAttempt) => void | QueryResult | Promise<void | QueryResult>;

export function SubmitQuestionButton({attempt,onSubmit,submitted,busy,retry=false,stale=false}:{attempt:WorkspaceAttempt;onSubmit:SubmitQuestion;submitted:boolean;busy:boolean;retry?:boolean;stale?:boolean}) {
  const {t:tr}=useLanguage("workspace");
  const [bubble,setBubble]=useState(false);
  const timer=useRef<number>();
  useEffect(()=>()=>window.clearTimeout(timer.current),[]);
  function showBubble(){window.clearTimeout(timer.current);setBubble(true);timer.current=window.setTimeout(()=>setBubble(false),2200);}
  return <span className="workspace-question-submit-wrap"><button type="button" className="button workspace-question-submit" disabled={busy || stale} title={stale?tr("Run updated SQL first"):undefined} onClick={async()=>{const result=await onSubmit(attempt);if(result?.alreadySubmitted)showBubble();}}><TranslatedText>{tr(retry?"Resubmit question":"Submit this question")}</TranslatedText></button>{bubble && <span className="workspace-already-submitted" role="status"><TranslatedText>{tr("Already submitted")}</TranslatedText></span>}</span>;
}

function AttemptCode({script,submitted}:{script:string;submitted:boolean}) {
  const {t:tr}=useLanguage("workspace");
  const [open,setOpen]=useState(false);
  return <details className="workspace-submission-code" onToggle={event=>setOpen(event.currentTarget.open)}><summary><TranslatedText>{tr(submitted?"Submitted SQL":"Executed SQL")}</TranslatedText></summary>{open && <CodeMirror value={script} readOnly editable={false} extensions={sqlEditorExtensions} minHeight="70px" maxHeight="260px" basicSetup={{lineNumbers:true,foldGutter:false,highlightActiveLine:false,highlightActiveLineGutter:false}} aria-label={tr(submitted?"Submitted SQL":"Executed SQL")}/>}</details>;
}

function ResultTable({ table }: { table: DataTable }) {
  const { t: tr } = useLanguage("workspace");
  return <><DataGrid table={table.name === "Result" ? {...table,name:tr("Result")} : table} />{table.rowCount != null && table.rowCount > table.rows.length && <small className="muted"><TranslatedText>{tr("Showing {value0} of {value1} rows", { value0: table.rows.length, value1: table.rowCount })}</TranslatedText></small>}</>;
}

function SchemaResult({ tables }: { tables: SchemaTable[] }) {
  const { t: tr } = useLanguage("workspace");
  return <>{tables.length ? tables.map(table => <section key={table.name} className="workspace-schema-result"><strong>{table.name}</strong><DataGrid table={{ name: table.name, columns: [tr("Column"), tr("Type"), tr("Constraints")], rows: table.columns.map(column => [column.name, column.type, column.constraints]) }} />
    {Boolean(table.primaryKeys?.length) && <p><code>PK</code> · {table.primaryKeys.join(", ")}</p>}
    {table.constraints?.map((constraint,index)=><p key={`constraint-${index}`}>{constraint}</p>)}
    {table.foreignKeys?.map((key, index) => <p key={index}><code>FK</code> · {key.columns.join(", ")} → {key.referencedTable} ({key.referencedColumns.join(", ")})</p>)}
  </section>) : <p className="muted"><TranslatedText>{tr("No tables created.")}</TranslatedText></p>}</>;
}

/** Kết quả của sinh viên; câu DQL kèm thêm kết quả mong đợi (chạy từ đáp án) khi backend trả về, tức là khi giảng viên
 *  cho hiện kết quả mong đợi và bộ dữ liệu không bị ẩn. */
function ResultOutput({ children, expected }: { children: ReactNode; expected?: DataTable }) {
  const { t: tr } = useLanguage("workspace");
  return <div className="workspace-result-comparison">
    <section><h4><TranslatedText>{tr("Your result")}</TranslatedText></h4>{children}</section>
    {expected && <section className="workspace-expected-result"><h4><TranslatedText>{tr("Expected result")}</TranslatedText></h4><ResultTable table={expected.name === "Expected" ? { ...expected, name: tr("Expected result") } : expected} /></section>}
  </div>;
}

function SectionOutput({ result }: { result: QueryResult }) {
  const { t: tr } = useLanguage("workspace");
  const empty = <p className="muted"><TranslatedText>{tr("No result returned.")}</TranslatedText></p>;
  if (result.part === "TRIGGER") return <>{result.triggers?.map(trigger=><p key={trigger.name}>{trigger.name} → {trigger.table}<TranslatedText>{trigger.isDisabled?` · ${tr("Disabled")}`:""}</TranslatedText></p>)}{result.tables?.map((table,index)=><ResultTable key={index} table={table}/>)}</>;
  if (result.part === "DDL" && result.schemaChanges) return result.schemaChanges.length ? <>{result.schemaChanges.map(change => <section key={change.name}><h3><TranslatedText>{tr(change.change === "created" ? "Created table" : change.change === "dropped" ? "Dropped table" : "Modified table")}</TranslatedText> · {change.name}</h3>{change.table ? <ResultOutput><SchemaResult tables={[change.table]} /></ResultOutput> : <p className="muted"><TranslatedText>{tr("This table has been dropped.")}</TranslatedText></p>}</section>)}</> : <p className="muted"><TranslatedText>{tr("No table structure changes.")}</TranslatedText></p>;
  if (result.part === "DML" && result.dataChanges) return result.dataChanges.length ? <>{result.dataChanges.map(change => <section key={change.name}><h3><TranslatedText>{tr(change.change === "created" ? "Created table" : change.change === "dropped" ? "Dropped table" : "Modified table")}</TranslatedText> · {change.name}</h3>{change.table ? <ResultOutput><ResultTable table={change.table} /></ResultOutput> : <p className="muted"><TranslatedText>{tr("This table has been dropped.")}</TranslatedText></p>}</section>)}</> : <p className="muted"><TranslatedText>{tr("No table data changes.")}</TranslatedText></p>;
  if (result.part === "DDL") return result.actualSchema ? <ResultOutput><SchemaResult tables={result.actualSchema} /></ResultOutput> : null;
  if (result.part === "DML") {
    const actual = result.actualTables || result.tables || [];
    return <>{actual.map((table, index) => <section key={`${table.name}-${index}`}><h3>{table.name}</h3><ResultOutput><ResultTable table={table} /></ResultOutput></section>)}</>;
  }
  if (result.comparisons?.length) return <>{result.comparisons.map((item, index) => <section key={index} className="workspace-query-comparison"><header><strong><TranslatedText>{item.question ? `${tr("Question")} ${item.question} · ` : ""}</TranslatedText><TranslatedText>{tr("Dataset")}</TranslatedText> {item.dataset}</strong>{item.matched != null && <span className={item.matched ? "comparison-match" : "comparison-different"}><TranslatedText>{tr(item.matched ? "Matched" : "Different")}</TranslatedText></span>}</header><ResultOutput expected={item.expectedTable}>{item.actualTable ? <ResultTable table={item.actualTable} /> : empty}</ResultOutput></section>)}</>;
  return <>{result.table && <ResultTable table={result.table} />}{result.tables?.map((table, index) => <ResultTable key={index} table={table} />)}</>;
}

type QuestionGroups = Partial<Record<WorkspacePart,ProblemQuestion[]>>;

function ResultKindTabs({parts,value,onChange,prefix}:{parts:WorkspacePart[];value:WorkspacePart;onChange:(part:WorkspacePart)=>void;prefix:string}) {
  const {t:tr}=useLanguage("workspace");
  const root=useRef<HTMLElement>(null);
  const [indicator,setIndicator]=useState({left:0,width:0});
  useLayoutEffect(() => {
    const update=() => {const active=root.current?.querySelector<HTMLElement>('[aria-selected="true"]');if(active)setIndicator({left:active.offsetLeft,width:active.offsetWidth});};
    update();const observer=new ResizeObserver(update);if(root.current)observer.observe(root.current);return()=>observer.disconnect();
  },[value,parts.join(",")]);
  return <nav ref={root} className="workspace-result-kind-tabs" role="tablist" aria-label={tr("Query type")}>
    <span className="workspace-result-kind-indicator" aria-hidden="true" style={{width:indicator.width,transform:`translateX(${indicator.left}px)`}} />
    {parts.map((part,index)=><button key={part} type="button" role="tab" aria-selected={part===value} aria-controls={`${prefix}-results`} tabIndex={part===value?0:-1} id={`${prefix}-${part}`} onClick={()=>onChange(part)} onKeyDown={event=>{
      const next=event.key==="ArrowRight"?(index+1)%parts.length:event.key==="ArrowLeft"?(index+parts.length-1)%parts.length:event.key==="Home"?0:event.key==="End"?parts.length-1:-1;
      if(next<0)return;event.preventDefault();onChange(parts[next]);root.current?.querySelectorAll<HTMLButtonElement>("button")[next]?.focus();
    }}>{WORKSPACE_PART_LABELS[part]}</button>)}
  </nav>;
}

export function WorkspaceSubmissionPanel({attempts,part,questionId,busy,notice,submissionsUsed,maxSubmissions,mode="submit",onSubmitQuestion,questionGroups,selectedQuestionIds,currentScript,onRunCurrent}: {
  currentScript?:string;onRunCurrent?:()=>void;
  attempts:WorkspaceAttempt[];part?:WorkspacePart;questionId?:string;busy:boolean;notice:string;submissionsUsed?:number;maxSubmissions?:number|null;
  mode?:"run"|"submit";onSubmitQuestion?:SubmitQuestion;questionGroups?:QuestionGroups;selectedQuestionIds?:Partial<Record<WorkspacePart,string>>;
}) {
  const {t:tr}=useLanguage("workspace");
  const prefix=useId();
  const parts=WORKSPACE_PARTS.filter(item=>item===part || questionGroups?.[item]?.length || attempts.some(attempt=>attempt.part===item));
  if(!parts.length)parts.push("SQL");
  const [resultPart,setResultPart]=useState<WorkspacePart>(part || parts[0]);
  const effectivePart=parts.includes(resultPart)?resultPart:parts[0];
  const questions=questionGroups?.[effectivePart]?.length ? questionGroups[effectivePart]! : [{id:effectivePart===part?questionId||"":"",name:""}];
  const [openQuestion,setOpenQuestion]=useState(questionId || selectedQuestionIds?.[part || parts[0]] || questions[0]?.id || "");
  const activeRow=useRef<HTMLElement|null>(null);
  useEffect(()=>{if(part)setResultPart(part);setOpenQuestion(questionId || selectedQuestionIds?.[part || parts[0]] || questionGroups?.[part || parts[0]]?.[0]?.id || "");},[part,questionId]);
  useEffect(()=>{activeRow.current?.scrollIntoView?.({block:"nearest",behavior:window.matchMedia?.("(prefers-reduced-motion: reduce)").matches?"auto":"smooth"});},[effectivePart,openQuestion,mode]);
  const choosePart=(next:WorkspacePart)=>{setResultPart(next);setOpenQuestion(selectedQuestionIds?.[next] || questionGroups?.[next]?.[0]?.id || "");};
  const matching=(id:string)=>attempts.filter(attempt=>attempt.kind===mode && (!attempt.part || attempt.part===effectivePart) && (!attempt.result.questionId || attempt.result.questionId===id) &&
    !(mode==="submit" && !attempt.result.questionId && attempt.script!==undefined && !submittedQuestionScript(attempt.script,effectivePart,id).trim()));
  function renderAttempt(attempt:WorkspaceAttempt,id:string,label:string) {
    const overall=attempt.result;
    const savedScript=attempt.kind==="submit" && !overall.questionId ? submittedQuestionScript(attempt.script || overall.submittedScript || "",effectivePart,id) : attempt.script || overall.submittedScript;
    // Nộp từng câu trong kỳ thi: backend vẫn gửi đúng/sai (sections) dù điểm bị ẩn, nên chỉ ẩn khi không có kết quả nào.
    const questionResultShown=Boolean(overall.questionOnly && overall.sections?.length);
    const hidden=overall.scoreVisible===false && attempt.kind==="submit" && !questionResultShown;
    const section=hidden?undefined:overall.sections?.find(item=>item.part===effectivePart);
    const result=section || overall;
    const questionResult=!hidden && id?result.questions?.find(item=>item.id===id):undefined;
    const legacyMessage=!section && overall.sections==null && attempt.kind==="submit" ? overall.message.match(new RegExp(`(?:^|\\s)${effectivePart}: ([\\s\\S]*?)(?= (?:DDL|DML|SQL|Score):|$)`))?.[1]:undefined;
    return <article className="workspace-attempt" key={attempt.id}>
      <header><strong><TranslatedText>{tr(attempt.kind==="submit"?(overall.questionOnly?"Saved question":"Submission"):"Run result")}</TranslatedText></strong></header>
      {attempt.kind==="submit" && overall.scoreVisible!=null && !(questionResultShown && overall.score==null) && <p className="workspace-overall-score"><strong><TranslatedText>{tr(overall.questionOnly?"Provisional score":"Overall score")}</TranslatedText></strong> · <TranslatedText>{hidden?tr("Score hidden by instructor"):overall.score==null?tr("Awaiting grading"):`${formatPoints(overall.score)}/${formatPoints(overall.maxScore??100)}`}</TranslatedText></p>}
      {result.executionTimeMs!=null && <p className="workspace-execution-time"><TranslatedText>{tr("Execution time")}</TranslatedText> · {result.executionTimeMs.toFixed(2)} <TranslatedText>{tr("ms")}</TranslatedText></p>}
      {savedScript && <AttemptCode script={savedScript} submitted={attempt.kind==="submit"}/>}
      {!hidden && questionResult ? <section className="workspace-question-grade">
        <h3>{label} · {Math.round(questionResult.ratio*100)}%</h3>
        {questionResult.datasets.length ? questionResult.datasets.map(item=><div key={item.dataset} className={item.correct?"comparison-match":"comparison-different"}>
          <p><TranslatedText>{tr("Dataset")}</TranslatedText> {item.dataset}{questionResult.datasets.length>1 && <> · <TranslatedText>{tr(item.runtimeError?"Runtime Error":item.correct?"Accepted":"Wrong Answer")}</TranslatedText></>}</p>
          {item.runtimeError?<SqlErrorMessage message={item.message}/>:<p><TranslatedText>{tr(item.message)}</TranslatedText></p>}
        </div>) : /error|time limit/i.test(questionResult.status) ? <div role="alert"><SqlErrorMessage message={questionResult.message || tr(questionResult.status)}/></div>
          : questionResult.message ? <p><TranslatedText>{tr(questionResult.message)}</TranslatedText></p> : null}
        {Boolean(questionResult.comparisons?.length) && <SectionOutput result={{...result,part:effectivePart,comparisons:questionResult.comparisons}}/>}
      </section> : !hidden && (/error|time limit/i.test(result.status)?<div role="alert"><SqlErrorMessage message={legacyMessage || result.message}/></div>:<p><TranslatedText>{tr(legacyMessage || result.message)}</TranslatedText></p>)}
      {!hidden && !questionResult && <SectionOutput result={{...result,part:effectivePart}}/>}
    </article>;
  }
  return <div className="workspace-submission-content" aria-live="polite">
    <ResultKindTabs parts={parts} value={effectivePart} onChange={choosePart} prefix={prefix}/>
    {mode==="submit" && submissionsUsed!=null && <p className="muted tiny"><TranslatedText>{tr("Whole-problem submissions")}</TranslatedText> · {submissionsUsed} / <TranslatedText>{maxSubmissions??tr("Unlimited")}</TranslatedText></p>}
    {mode==="submit" && onSubmitQuestion && <p className="muted tiny workspace-save-hint"><TranslatedText>{tr("Save individual answers here. Submit the whole problem from the top toolbar to record an official attempt.")}</TranslatedText></p>}
    {busy && <Loading variant="table" columns={3} rows={3} label={tr("Running…")}/>}
    {notice && <div role="status" className="form-message"><SqlErrorMessage message={notice}/></div>}
    <div id={`${prefix}-results`} role="tabpanel" aria-labelledby={`${prefix}-${effectivePart}`} className="workspace-question-results">
      {questions.map((question,index)=>{
        const records=matching(question.id);
        const visible=mode==="run"?records.slice(0,1):records;
        const open=openQuestion===question.id;
        const label=tr("Question {value0} {value1}",{value0:index+1,value1:WORKSPACE_PART_LABELS[effectivePart]});
        const latest=records[0]?.result;
        const submitted=attempts.find(attempt=>attempt.kind==="submit" && (!attempt.part || attempt.part===effectivePart) && attempt.result.questionId===question.id);
        const failedSubmission=Boolean(submitted && (submitted.result.sections?.find(section=>section.part===effectivePart)?.questions?.find(item=>item.id===question.id)?.datasets.some(item=>item.runtimeError) || /error|runtime/i.test(submitted.result.sections?.find(section=>section.part===effectivePart)?.questions?.find(item=>item.id===question.id)?.status || submitted.result.status)));
        const staleRun=mode==="run" && effectivePart===part && question.id===questionId && currentScript!==undefined && currentScript.trim()!==records[0]?.script?.trim();
        const alreadySubmitted=!failedSubmission && Boolean(submitted?.script?.trim() && submitted.script.trim()===records[0]?.script?.trim());
        const savedSection=latest?.sections?.find(item=>item.part===effectivePart);
        const status=latest?.scoreVisible===false && !latest.sections?.length?"Submitted":savedSection?.questions?.find(item=>item.id===question.id)?.status || latest?.status;
        return <section ref={node=>{if(open)activeRow.current=node;}} key={question.id} className={`workspace-question-result-group${open?" is-open":""}`}>
          <div className="workspace-question-result-header"><button type="button" className="workspace-question-result-toggle" aria-expanded={open} aria-controls={`${prefix}-question-${index}`} onClick={()=>setOpenQuestion(open?"__closed__":question.id)}><span className="workspace-question-result-chevron" aria-hidden="true">›</span><strong>{label}</strong>{status?<Status value={status}/>:<span className="muted"><TranslatedText>{tr(mode==="run"?"Not run yet":"Not submitted")}</TranslatedText></span>}</button>{(mode==="run" || /error|runtime/i.test(status || "")) && onSubmitQuestion && records[0]?.script?.trim() && records[0].result.questionId && <SubmitQuestionButton key={`${records[0].id}-${staleRun}`} attempt={records[0]} onSubmit={onSubmitQuestion} submitted={!staleRun && mode==="run" && alreadySubmitted} busy={busy} retry={mode==="submit" || failedSubmission} stale={staleRun}/>}</div>
          <div className="workspace-question-result-slide" id={`${prefix}-question-${index}`} aria-hidden={!open}><div ref={node=>{if(node)node.inert=!open;}}>{visible.length?visible.map((attempt,attemptIndex)=>attemptIndex===0?renderAttempt(attempt,question.id,label):<details className="workspace-older-submission" key={attempt.id}><summary><TranslatedText>{tr("Earlier submission")}</TranslatedText> {visible.length-attemptIndex}</summary>{renderAttempt(attempt,question.id,label)}</details>):!busy && <p className="muted tiny"><TranslatedText>{tr(mode==="run"?"Run this question to see its result here.":"Your submission history will appear here.")}</TranslatedText></p>}</div></div>
        </section>;
      })}
    </div>
  </div>;
}
