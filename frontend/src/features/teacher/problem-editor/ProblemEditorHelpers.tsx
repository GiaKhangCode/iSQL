import { DatasetDataPreview as SharedDatasetDataPreview } from "@/shared/components/DatasetDataPreview";
import { type TeacherDatabaseLibraryEntry } from "@/shared/api/teacherService";
import { type TeacherSqlSolution, type TeacherStageExercise, type TeacherStageQuestion } from "@/data/teacherTypes";

export function DatasetDataPreview({ datasetNumber, seedData }: { datasetNumber: number; seedData: string }) {
  return <SharedDatasetDataPreview datasetNumber={datasetNumber} seedData={seedData} variant="problem-editor" />;
}

export function databaseLibraryDatasets(entry: TeacherDatabaseLibraryEntry) {
  const fallbackName = entry.title.toLowerCase().replace(/đ/g, "d").normalize("NFKD").replace(/[\u0300-\u036f]/g, "").replace(/[^a-z0-9]/g, "") || "database";
  return Array.isArray(entry.datasets) && entry.datasets.length
    ? entry.datasets
    : [{ fileName: entry.insertIntoFileName || `${fallbackName}_insertdata.sql`, sql: entry.insertIntoSql || "" }];
}

export function parseProblemTopics(value: unknown): string[] {
  const topics = Array.isArray(value)
    ? value
    : typeof value === "string"
      ? value.split(/[,·]+/)
      : [];

  return [...new Set(topics.map((topic) => String(topic).trim()).filter(Boolean))];
}

export function comparableSolutions(solutions: TeacherSqlSolution[] = []) {
  return solutions.map(solution => [solution.id, solution.query, solution.setupSql || "", solution.description || "", solution.enabled,
    solution.timeLimitMs, solution.expectedOutputVisible !== false, solution.difficulty || "", solution.category || "",
    solution.checkRowOrder ?? null, Boolean(solution.ignoreColumnNames),
    (solution.expectedOutputs || []).map(table => [table.name, table.columns, table.rows])]);
}

export function comparableStageExercise(exercise?: TeacherStageExercise) {
  return (exercise?.questions || []).map((question: TeacherStageQuestion) => [question.id, question.description,
    question.answer, question.enabled, question.startFrom, question.setupDdl, question.setupDml, question.difficulty || "", question.category || "", (question.probes || []).join("\u0000"), Boolean(question.checkColumnOrder)]);
}

export function problemPayloadWasSaved(data: any, payload: any) {
  const actualTopics = parseProblemTopics(data?.topics ?? data?.topic).join("|");
  const expectedTopics = parseProblemTopics(payload?.topics).join("|");
  const actualHints = typeof data?.hint === "string" && data.hint ? data.hint.split("\n") : [];
  const expectedHints = Array.isArray(payload?.hints) ? payload.hints : [];
  const actualTestCases = Array.isArray(data?.testCases) ? data.testCases : [];
  const expectedTestCases = Array.isArray(payload?.testCases) ? payload.testCases : [];
  const testCasesMatch = actualTestCases.length === expectedTestCases.length && expectedTestCases.every((expected: any, index: number) => {
    const actual = actualTestCases[index] || {};
    return (actual.schema || "") === (expected.schema || payload.schema || "")
      && (actual.seedData || "") === (expected.seedData || "")
      && Boolean(actual.isHidden) === Boolean(expected.isHidden);
  });

  return data?.title === payload?.title
    && data?.number === payload?.number
    && actualTopics === expectedTopics
    && data?.description === payload?.statement
    && actualHints.join("\n") === expectedHints.join("\n")
    && (data?.databaseType || "") === (payload?.database || "")
    && (data?.schema || "") === (payload?.schema || "")
    && (data?.schemaFileName || "database.sql") === (payload?.schemaFileName || "database.sql")
    && (data?.referenceSolution || "") === (payload?.referenceSolution || "")
    && JSON.stringify(comparableSolutions(data?.sqlSolutions || [])) === JSON.stringify(comparableSolutions(payload?.sqlSolutions || []))
    && JSON.stringify(comparableStageExercise(data?.triggerExercise)) === JSON.stringify(comparableStageExercise(payload?.triggerExercise))
    && JSON.stringify(comparableStageExercise(data?.ddlExercise)) === JSON.stringify(comparableStageExercise(payload?.ddlExercise))
    && JSON.stringify(comparableStageExercise(data?.dmlExercise)) === JSON.stringify(comparableStageExercise(payload?.dmlExercise))
    && JSON.stringify(data?.databaseDescription || []) === JSON.stringify(payload?.databaseDescription || [])
    && (data?.databaseDescriptionVisible !== false) === (payload?.databaseDescriptionVisible !== false)
    && testCasesMatch;
}

export function getSelectedTextSize(editor: HTMLElement, selection: Selection | null): number | "mixed" | null {
  if (!selection || !selection.rangeCount || selection.isCollapsed) return null;
  const range = selection.getRangeAt(0);
  if (!editor.contains(range.commonAncestorContainer)) return null;

  const sizes = new Set<number>();
  const walker = document.createTreeWalker(editor, NodeFilter.SHOW_TEXT);
  let textNode = walker.nextNode();
  while (textNode) {
    if (textNode.textContent?.trim() && range.intersectsNode(textNode)) {
      const parent = textNode.parentElement;
      const size = parent ? Math.round(parseFloat(window.getComputedStyle(parent).fontSize)) : 0;
      if (size) sizes.add(size);
    }
    textNode = walker.nextNode();
  }

  if (!sizes.size) return null;
  if (sizes.size > 1) return "mixed";
  return [...sizes][0];
}
