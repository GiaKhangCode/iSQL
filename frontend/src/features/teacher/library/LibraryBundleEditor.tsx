import { TranslatedText } from "@/shared/ui/TranslatedText";
import { useState } from "react";
import type { DatabaseLibraryBundle, TeacherDatabaseLibraryDataset } from "@/shared/api/teacherService";
import type { WorkspacePart } from "@/data/models";
import { TeacherStageExercises, initialStageExercise } from "@/features/teacher/problem-editor/TeacherStageExercises";
import { TeacherSqlSolutions, initialSqlSolutions } from "@/features/teacher/problem-editor/TeacherSqlSolutions";
import { ProblemRichTextEditor } from "@/features/teacher/problem-editor/ProblemRichTextEditor";
import { Dialog } from "@/shared/ui/ui";
import { useLanguage } from "@/shared/context/LanguageContext";

export function emptyLibraryBundle():DatabaseLibraryBundle {
  return {version:1,statement:"",ddlExercise:initialStageExercise("DDL"),dmlExercise:initialStageExercise("DML"),triggerExercise:initialStageExercise("TRIGGER"),sqlSolutions:initialSqlSolutions([],"")};
}

export function invalidateLibraryOutputs(bundle?:DatabaseLibraryBundle) {
  return bundle ? {...bundle,sqlSolutions:bundle.sqlSolutions.map(solution=>({...solution,expectedOutputs:[]}))} : bundle;
}

export function LibraryStatement({value,onChange}:{value:string;onChange:(value:string)=>void}) {
  const {t}=useLanguage("teacher");
  const [expanded,setExpanded]=useState(false);
  return <details className="library-statement"><summary><TranslatedText>{t("Shared statement and table predicates")}</TranslatedText></summary>
    <ProblemRichTextEditor label={t("Problem statement")} value={value} onChange={onChange} onOpenExpanded={()=>setExpanded(true)}/>
    {expanded && <Dialog title={t("Problem statement")} className="teacher-rich-text-dialog" onClose={()=>setExpanded(false)}>
      <ProblemRichTextEditor label={t("Problem statement")} value={value} onChange={onChange} onOpenExpanded={()=>setExpanded(false)}/>
    </Dialog>}
  </details>;
}

export function LibraryBundleEditor({part,bundle,schema,datasets,onChange,onRunningChange}:{part:WorkspacePart;bundle:DatabaseLibraryBundle;schema:string;datasets:TeacherDatabaseLibraryDataset[];onChange:(bundle:DatabaseLibraryBundle)=>void;onRunningChange:(running:boolean)=>void}) {
  const data=datasets.map(dataset=>({seedData:dataset.sql,isHidden:dataset.isHidden || false}));
  if (part==="SQL") return <TeacherSqlSolutions solutions={bundle.sqlSolutions} schema={schema} datasets={data}
    onChange={sqlSolutions=>onChange({...bundle,sqlSolutions})} onExpectedOutputsChange={()=>{}} onRunningChange={onRunningChange}/>;
  const key=part==="DDL"?"ddlExercise":part==="DML"?"dmlExercise":"triggerExercise";
  return <TeacherStageExercises part={part} exercise={bundle[key]} schema={schema} datasets={data}
    onChange={exercise=>onChange({...bundle,[key]:exercise})} onRunningChange={onRunningChange}/>;
}
