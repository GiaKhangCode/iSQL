import { useEffect, useState, type ReactNode } from "react";
import { Search, X } from "lucide-react";
import { teacherService, type AudienceStudent } from "@/shared/api/teacherService";
import { useLanguage } from "@/shared/context/LanguageContext";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import { Loading } from "@/shared/ui/ui";

function focusSearch(node:HTMLInputElement|null) {
  node?.focus({preventScroll:true});
}

export function StudentSearchPicker({emails,classIds=[],onChange,onClose,searchActions}:{emails:string[];classIds?:string[];onChange:(emails:string[])=>void;onClose:()=>void;searchActions?:ReactNode}) {
  const {t}=useLanguage("teacher");
  const [search,setSearch]=useState("");
  const [students,setStudents]=useState<AudienceStudent[]>([]);
  const [total,setTotal]=useState(0);
  const [loading,setLoading]=useState(true);
  const [error,setError]=useState("");
  const [retry,setRetry]=useState(0);
  useEffect(()=>{
    let active=true;
    setLoading(true);setError("");
    const timer=window.setTimeout(()=>teacherService.searchAudienceStudents(search,classIds).then(result=>{
      if(active){setStudents(result.items);setTotal(result.total);}
    }).catch(cause=>{if(active)setError(cause instanceof Error?cause.message:t("Could not load students."));})
      .finally(()=>{if(active)setLoading(false);}),200);
    return ()=>{active=false;window.clearTimeout(timer);};
  },[search,retry,classIds.join("|")]);
  const matches=(student:AudienceStudent,value:string)=>[student.email.toLowerCase(),student.id.toLowerCase(),student.studentNumber].includes(value.toLowerCase());
  return <section className="teacher-class-options teacher-student-search-panel" aria-label={t("Add individual students")} onKeyDown={event=>{if(event.key==="Escape"){event.preventDefault();onClose();}}}>
    <div className="teacher-class-search-toolbar">
      <div className="teacher-class-search-input"><Search size={16} aria-hidden="true" />
        <input ref={focusSearch} type="search" value={search} maxLength={100} placeholder={t("Search by student ID, email or name…")} aria-label={t("Search students")} onChange={event=>setSearch(event.target.value)}/>
        {search && <button className="icon-button" type="button" aria-label={t("Clear student search")} onClick={()=>setSearch("")}><X size={14} aria-hidden="true" /></button>}
        {searchActions}
      </div>
      {!loading && <small><TranslatedText>{t("{value0} students",{value0:total})}</TranslatedText></small>}
    </div>

    {loading ? <Loading variant="inline" label={t("Loading students…")} /> : error ? <p className="teacher-student-search-error" role="alert">{error} <button type="button" className="text-button" onClick={()=>setRetry(value=>value+1)}><TranslatedText>{t("Try again")}</TranslatedText></button></p> : <>
      <div className="teacher-class-results teacher-student-results" style={{"--audience-columns":Math.max(1,Math.min(5,students.length))} as import("react").CSSProperties}>{students.map(student=>{
        const checked=emails.some(value=>matches(student,value));
        const covered=Boolean(student.coveredClasses?.length);
        return <label key={student.id} className={covered?"is-covered":checked?"is-selected":""}><input type="checkbox" checked={covered || checked} disabled={covered} onChange={()=>{if(!covered)onChange(checked?emails.filter(value=>!matches(student,value)):[...emails,student.email.toLowerCase()]);}}/>
          <span><b>{student.name}</b><small>{student.studentNumber && `${student.studentNumber} · `}{student.email}</small>{covered && <small><TranslatedText>{t("Already included in {value0}",{value0:student.coveredClasses!.join(", ")})}</TranslatedText></small>}</span>
        </label>;
      })}</div>
      {!students.length && <p className="muted teacher-class-empty"><TranslatedText>{t("No matching active students.")}</TranslatedText></p>}
      {total>students.length && <small className="muted"><TranslatedText>{t("Showing the first {value0} results. Refine your search to find a student.",{value0:students.length})}</TranslatedText></small>}
    </>}
  </section>;
}
