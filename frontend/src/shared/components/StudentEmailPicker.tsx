import {useEffect,useState} from "react";
import {useLanguage} from "@/shared/context/LanguageContext";
import {TranslatedText} from "@/shared/ui/TranslatedText";
import {parseStudentEmails,validStudentIdentifier} from "@/data/examinations";

export function StudentEmailPicker({emails,onChange,disabled=false,autoFocus=false}:{emails:string[];onChange:(emails:string[])=>void;disabled?:boolean;autoFocus?:boolean}) {
  const {t}=useLanguage("teacher");
  const [text,setText]=useState(()=>emails.join("\n"));
  useEffect(()=>{if(JSON.stringify(parseStudentEmails(text))!==JSON.stringify(emails))setText(emails.join("\n"));},[emails]);
  const invalid=emails.filter(email=>!validStudentIdentifier(email));
  return <div className="student-email-picker">
    <label className="teacher-field"><span><TranslatedText>{t("Student ID or student email")}</TranslatedText></span>
      <textarea rows={3} disabled={disabled} autoFocus={autoFocus} value={text} placeholder={t("One student ID or email per line, or paste a comma-separated list")}
        onChange={event=>{setText(event.target.value);onChange(parseStudentEmails(event.target.value));}}/>
    </label>
    <small className={invalid.length?"teacher-state-failed":"muted"}><TranslatedText>{invalid.length?t("{value0} invalid student IDs or emails",{value0:invalid.length}):t("{value0} entries · each student is counted once",{value0:emails.length})}</TranslatedText></small>
    <p className="muted"><TranslatedText>{t("Use an active student account. Student IDs, emails and class members are combined without duplicates.")}</TranslatedText></p>
  </div>;
}
