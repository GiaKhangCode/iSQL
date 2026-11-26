import type { TeacherProblem, TeacherStageQuestion, TeacherSqlSolution } from "@/data/teacherTypes";
import type { TeacherDatabaseLibraryEntry } from "@/shared/api/teacherService";

export const LIBRARY_SECTIONS = ["schema", "datasets", "statement", "DDL", "DML", "SQL", "TRIGGER"] as const;
export type LibrarySection = typeof LIBRARY_SECTIONS[number];
export type LibraryImportOptions = { sections: LibrarySection[]; mode: "append" | "replace" };
export const DEFAULT_LIBRARY_IMPORT: LibraryImportOptions = { sections: ["schema", "datasets"], mode: "append" };

export function libraryDatasets(entry: TeacherDatabaseLibraryEntry) {
  return entry.datasets?.length ? entry.datasets : [{fileName:entry.insertIntoFileName || "seed_1.sql",sql:entry.insertIntoSql || "",isHidden:false}];
}

export function librarySectionCount(entry: TeacherDatabaseLibraryEntry, section: LibrarySection): number {
  if (section === "schema") return entry.createTableSql.trim() ? 1 : 0;
  if (section === "datasets") return libraryDatasets(entry).length;
  if (section === "statement") return entry.bundle?.statement.trim() ? 1 : 0;
  if (section === "SQL") return (entry.bundle?.sqlSolutions || []).filter(authoredSql).length;
  return (entry.bundle?.[exerciseKey(section)].questions || []).filter(authoredStage).length;
}

const exerciseKey = (section: "DDL" | "DML" | "TRIGGER") => section === "DDL" ? "ddlExercise" : section === "DML" ? "dmlExercise" : "triggerExercise";
const authoredStage = (question: TeacherStageQuestion) => Boolean(question.answer.trim() || question.description.trim());
const authoredSql = (question: TeacherSqlSolution) => Boolean(question.query.trim() || question.description?.trim());

function copyQuestions<T extends {id:string}>(incoming:T[], newId:()=>string) {
  return incoming.map(question=>{
    const copy=structuredClone(question) as T & {name?:string};
    copy.id=newId();
    // Thư viện cũ còn lưu tên câu hỏi; câu hỏi không còn tên nên bỏ trường này khi sao chép.
    delete copy.name;
    return copy as T;
  });
}

/** Import an independent snapshot. IDs never link the target back to the library. */
export function importLibraryBundle(problem:TeacherProblem, entry:TeacherDatabaseLibraryEntry, options:LibraryImportOptions, newId=()=>crypto.randomUUID()):{problem:TeacherProblem;datasetNames?:string[]} {
  const next=structuredClone(problem);
  const selected=new Set(options.sections);
  const changedDatabase=selected.has("schema") || selected.has("datasets");
  let datasetNames:string[]|undefined;
  if(selected.has("schema")) {
    next.schema=entry.createTableSql;
    next.schemaFileName=entry.createTableFileName || "database.sql";
  }
  if(selected.has("datasets")) {
    const datasets=libraryDatasets(entry);
    next.testCases=datasets.map(dataset=>({seedData:dataset.sql,isHidden:dataset.isHidden || false}));
    next.seedData=datasets[0]?.sql || "";
    datasetNames=datasets.map(dataset=>dataset.fileName);
  }
  if(selected.has("statement") && entry.bundle) {
    next.statement=entry.bundle.statement;
    next.databaseDescription=[];
  }
  for(const section of ["DDL","DML","TRIGGER"] as const) {
    if(!selected.has(section) || !entry.bundle) continue;
    const key=exerciseKey(section);
    const existing=options.mode==="append" ? (next[key]?.questions || []).filter(authoredStage) : [];
    next[key]={questions:[...existing,...copyQuestions(entry.bundle[key].questions.filter(authoredStage),newId)]};
    if(next[key]!.questions.length>50) throw new Error("Mỗi loại đề chỉ được có tối đa 50 câu hỏi. Hãy chọn thay thế hoặc giảm số câu trước khi nhập.");
  }
  if(selected.has("SQL") && entry.bundle) {
    const existing=options.mode==="append" ? (next.sqlSolutions || []).filter(authoredSql) : [];
    const incoming=copyQuestions(entry.bundle.sqlSolutions.filter(authoredSql),newId).map(question=>({...question,expectedOutputs:[]}));
    next.sqlSolutions=[...existing,...incoming];
    if(next.sqlSolutions.length>50) throw new Error("Mỗi loại đề chỉ được có tối đa 50 câu hỏi. Hãy chọn thay thế hoặc giảm số câu trước khi nhập.");
    next.referenceSolution=next.sqlSolutions.find(question=>question.enabled)?.query || "";
    next.expectedOutputs=[];
  }
  if(changedDatabase) {
    next.expectedOutputs=[];
    next.sqlSolutions=next.sqlSolutions?.map(question=>({...question,expectedOutputs:[]}));
    next.expectedColumns=[];
    next.expectedRows=[];
  }
  return {problem:next,datasetNames};
}
