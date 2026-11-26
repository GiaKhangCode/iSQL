import {useState,type FormEvent} from "react";
import {LockKeyhole,CalendarClock} from "lucide-react";
import {useLanguage} from "@/shared/context/LanguageContext";
import {TranslatedText} from "@/shared/ui/TranslatedText";
import {examAdmission} from "@/shared/api/examAdmission";
import type {ExamSession} from "@/data/models";
import "@/styles/exam-admission.css";
import { notify } from "@/shared/ui/notify";

export function ExamAdmissionForm({examSession,closed,onUnlocked}:{examSession:ExamSession;closed:boolean;onUnlocked:()=>void}) {
  const {t}=useLanguage("student");
  const [username,setUsername]=useState("");const [password,setPassword]=useState("");
  const [busy,setBusy]=useState(false);
  async function submit(event:FormEvent){event.preventDefault();if(busy)return;setBusy(true);
    try{await examAdmission.unlock(examSession.id,username.trim(),password);setPassword("");notify.success(t("Admission confirmed"));onUnlocked();}
    catch(err){notify.fail(t("Could not confirm admission."),err,t);}
    finally{setBusy(false);}
  }
  return <section className="exam-admission-card">
    <header><LockKeyhole size={20}/><span><TranslatedText>{t("Exam admission")}</TranslatedText></span></header>
    <h1>{examSession.title}</h1>
    <p className="exam-admission-schedule"><CalendarClock size={17}/><span>{examSession.date} · {examSession.time}–{examSession.endTime}</span></p>
    {closed?<p><TranslatedText>{t("This examination has ended.")}</TranslatedText></p>:<form onSubmit={submit}>
      <p><TranslatedText>{t("Enter the admission details given by your invigilator. Keep your personal account signed in.")}</TranslatedText></p>
      <label><TranslatedText>{t("Admission username")}</TranslatedText><input value={username} maxLength={100} onChange={e=>setUsername(e.target.value)} autoComplete="off" autoCapitalize="none" spellCheck={false} required disabled={busy}/></label>
      <label><TranslatedText>{t("Admission password")}</TranslatedText><input type="password" value={password} maxLength={72} onChange={e=>setPassword(e.target.value)} autoComplete="off" required disabled={busy}/></label>
      <button className="button primary" disabled={busy||!username.trim()||!password}><TranslatedText>{t(busy?"Confirming…":"Confirm admission")}</TranslatedText></button>
      <small><TranslatedText>{t("Admission is linked to this browser. Ask your invigilator before changing computers.")}</TranslatedText></small>
    </form>}
  </section>;
}
