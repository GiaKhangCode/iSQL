import { useLanguage } from "@/shared/context/LanguageContext";
import { useState } from "react";
import { useSearchParams } from "react-router-dom";
import { teacherService } from "@/shared/api/teacherService";
import { useLoad } from "@/shared/hooks/useLoad";
import { ResultsList } from "@/features/teacher/ResultsList";

export function ResultsDashboardPage({defaultKind="assignments",onlyExams=false}:{defaultKind?:"assignments"|"exams";onlyExams?:boolean}={}) {
  const {t}=useLanguage("teacher");
  const [params,setParams]=useSearchParams();
  const [kind,setKind]=useState<"assignments"|"exams">(()=>onlyExams || (params.get("kind") || defaultKind)==="exams"?"exams":"assignments");
  const {data:assignments,loading,error}=useLoad(teacherService.getResultActivities);
  const switchKind=()=>{
    const nextKind=kind==="assignments"?"exams":"assignments";
    setKind(nextKind);
    setParams(previous=>{const next=new URLSearchParams(previous);next.set("kind",nextKind);return next;},{replace:true});
  };
  return <div className="teacher-page teacher-results-dashboard">
    {onlyExams ? <ResultsList isExamSession assignments={assignments} loading={loading} error={error}/> : (["assignments","exams"] as const).map(tab=><div key={tab} id={`results-panel-${tab}`} role="region" aria-label={t(tab==="assignments"?"Assignments":"Examinations")} hidden={kind!==tab}>
      <ResultsList isExamSession={tab==="exams"} assignments={assignments} loading={loading} error={error} onSwitchKind={switchKind}/>
    </div>)}
  </div>;
}
