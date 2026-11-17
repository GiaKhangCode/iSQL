import {Select} from "@/shared/ui/Select";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import type { TeacherProblem } from "@/data/teacherTypes";
import type { TeacherDatabaseLibraryEntry } from "@/shared/api/teacherService";
import { useLanguage } from "@/shared/context/LanguageContext";
import { LIBRARY_SECTIONS, librarySectionCount, type LibraryImportOptions, type LibrarySection } from "@/shared/lib/sql/libraryBundleImport";
import "@/styles/library-import.css";

const labels:Record<LibrarySection,string>={schema:"Database structure",datasets:"Initial datasets",statement:"Shared statement and table predicates",DDL:"DDL",DML:"DML",SQL:"DQL",TRIGGER:"Trigger"};
export function LibraryImportSelection({entry,problem,value,onChange}:{entry:TeacherDatabaseLibraryEntry;problem:TeacherProblem;value:LibraryImportOptions;onChange:(value:LibraryImportOptions)=>void}) {
  const {t}=useLanguage("teacher");
  const existing:Record<LibrarySection,number>={schema:problem.schema.trim()?1:0,datasets:problem.testCases?.length || (problem.seedData?1:0),statement:problem.statement.trim()?1:0,
    DDL:problem.ddlExercise?.questions.filter(q=>q.answer.trim() || q.description.trim()).length || 0,
    DML:problem.dmlExercise?.questions.filter(q=>q.answer.trim() || q.description.trim()).length || 0,
    SQL:problem.sqlSolutions?.filter(q=>q.query.trim() || q.description?.trim()).length || 0,
    TRIGGER:problem.triggerExercise?.questions.filter(q=>q.answer.trim() || q.description.trim()).length || 0};
  return <section className="library-import-selection" aria-label={t("Choose content to import")}>
    <h3><TranslatedText>{t("Choose content to import")}</TranslatedText></h3>
    <p><TranslatedText>{t("Only selected sections will change. Structure, datasets and statement replace the current content.")}</TranslatedText></p>
    <div className="library-import-sections">{LIBRARY_SECTIONS.map(section=>{
      const count=librarySectionCount(entry,section);
      return <label key={section} className={count ? "" : "is-unavailable"}>
        <input type="checkbox" disabled={!count} checked={count>0 && value.sections.includes(section)} onChange={event=>onChange({...value,sections:event.target.checked?[...value.sections,section]:value.sections.filter(item=>item!==section)})}/>
        <span><TranslatedText>{t(labels[section])}</TranslatedText></span><small><TranslatedText>{t("{value0} available · {value1} current",{value0:count,value1:existing[section]})}</TranslatedText></small>
      </label>;
    })}</div>
    <label className="library-import-mode"><TranslatedText>{t("Question handling")}</TranslatedText>
      <Select value={value.mode} onChange={event=>onChange({...value,mode:event.target.value as LibraryImportOptions["mode"]})}>
        <option value="append">{t("Append imported questions")}</option><option value="replace">{t("Replace selected question groups")}</option>
      </Select>
    </label>
    <p><TranslatedText>{t("Imported questions receive new IDs. Run imported SQL answers again to refresh expected results.")}</TranslatedText></p>
  </section>;
}
