import { useEffect, useState } from "react";
import { studentApi } from "@/shared/api/studentApi";
import {Select} from "@/shared/ui/Select";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import type { Problem, ExpectedOutputSample, WorkspacePart } from "@/data/models";
import { useLanguage } from "@/shared/context/LanguageContext";
import { ProblemMarkdown } from "@/shared/components/ProblemMarkdown";
import { mergeLegacyDatabaseDescription } from "@/shared/lib/markdown-richtext/legacyDatabaseDescription";
import { ProblemExpectedOutput } from "@/shared/components/ProblemExpectedOutput";
import { ArrowLeft, ArrowRight, ChevronRight, Database, FileText } from "lucide-react";
import { WORKSPACE_PART_LABELS } from "@/shared/lib/workspace/workspaceParts";
import "@/styles/problem-question-content.css";

export type ProblemQuestion = {
  id: string; description?: string;
  kind?: "schema" | "seed";
  startFrom?: "empty" | "structure" | "provided";
  hasSetup?: boolean;
  gradingMode?: "exact" | "behavior";
  expectedSamples?: ExpectedOutputSample[];
  /** false = giảng viên tắt hiện kết quả mong đợi của câu này. */
  expectedVisible?: boolean;
};

/** Nơi xin backend tính bảng "Kết quả mong đợi" khi câu chưa có bảng lưu sẵn (chỉ trang làm bài của sinh viên). */
export type ExpectedOutputSource = { problemId: string; source: "Practice" | "Assignments" | "ExamSession"; context: string };

const expectedCache = new Map<string, ExpectedOutputSample[]>();

function useComputedExpectedSamples(source: ExpectedOutputSource | undefined, question: ProblemQuestion | undefined, part: WorkspacePart) {
  const key = source && question && part === "SQL" && !question.expectedSamples?.length && question.expectedVisible !== false
    ? `${source.problemId}:${source.context}:${question.id}` : null;
  const [state, setState] = useState<{ key: string; samples: ExpectedOutputSample[] } | null>(null);
  useEffect(() => {
    if (!key || !source || !question) return;
    const cached = expectedCache.get(key);
    if (cached) { setState({ key, samples: cached }); return; }
    let active = true;
    studentApi.getExpectedOutput(source.problemId, { solutionId: question.id, source: source.source, context: source.context })
      .then(result => { if (result.samples.length) expectedCache.set(key, result.samples); if (active) setState({ key, samples: result.samples }); })
      .catch(() => { if (active) setState({ key, samples: [] }); });
    return () => { active = false; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key]);
  return state && state.key === key ? state.samples : undefined;
}

export function ProblemQuestionContent({ problem, part, questions = [], selectedId, onQuestionChange, disabled = false, showNavigation = true, expectedOutputSource }: {
  expectedOutputSource?: ExpectedOutputSource;
  problem: Pick<Problem, "title" | "description" | "databaseDescription" | "databaseDescriptionVisible" | "expected" | "expectedSamples">;
  part: WorkspacePart;
  questions?: ProblemQuestion[];
  selectedId?: string;
  onQuestionChange?: (id: string) => void;
  disabled?: boolean;
  showNavigation?: boolean;
}) {
  const { t: tr } = useLanguage("workspace");
  const index = Math.max(0, questions.findIndex(item => item.id === selectedId));
  const question = questions[index];
  const computedSamples = useComputedExpectedSamples(expectedOutputSource, question, part);
  const useStatementAsQuestion = Boolean(question && !question.description?.trim() && problem.description?.trim());
  const mode = question?.startFrom ?? (part === "DDL" ? "empty" : "structure");
  const mergedStatement = mergeLegacyDatabaseDescription(problem.description || "",problem.databaseDescription,problem.databaseDescriptionVisible !== false);
  const contextStatement = useStatementAsQuestion ? mergedStatement.slice((problem.description || "").length).trim() : mergedStatement;
  const context = <ProblemMarkdown>{contextStatement}</ProblemMarkdown>;
  const hasContext = Boolean(contextStatement.trim());
  return <div className="problem-question-content">
    <h1>{problem.title}</h1>
    {question && showNavigation && <div className="problem-question-heading">
      <span className="problem-question-counter"><TranslatedText>{tr("Question")}</TranslatedText> <strong>{String(index + 1).padStart(2, "0")}</strong><span>/ {String(questions.length).padStart(2, "0")}</span></span>
      {onQuestionChange && questions.length > 1 ? <label className="workspace-question-picker">
        <Select aria-label={tr("Choose question")} value={question.id} disabled={disabled} onChange={event => onQuestionChange(event.target.value)}>
          {questions.map((item, number) => <option key={item.id} value={item.id}>{tr("Question {value0} {value1}",{value0:number + 1,value1:WORKSPACE_PART_LABELS[part]})}</option>)}
        </Select>
      </label> : <span className="problem-question-file"><TranslatedText>{tr("Question {value0} {value1}",{value0:index + 1,value1:WORKSPACE_PART_LABELS[part]})}</TranslatedText></span>}
      {onQuestionChange && questions.length > 1 && <div className="problem-question-stepper" role="group" aria-label={tr("Choose question")}>
        <button type="button" aria-label={tr("Previous question")} title={tr("Previous question")} disabled={disabled || index === 0} onClick={() => onQuestionChange(questions[index - 1].id)}><ArrowLeft size={15} aria-hidden="true" /></button>
        <button type="button" aria-label={tr("Next question")} title={tr("Next question")} disabled={disabled || index === questions.length - 1} onClick={() => onQuestionChange(questions[index + 1].id)}><ArrowRight size={15} aria-hidden="true" /></button>
      </div>}
    </div>}
    {question ? <section className="problem-active-question" aria-label={tr("Current question")}>
      <ProblemMarkdown>{question.kind ? tr(question.description || "") : question.description?.trim() || problem.description?.trim() || tr("No question description has been provided.")}</ProblemMarkdown>
    </section> : context}
    {part === "TRIGGER" && <p className="problem-stage-guidance"><TranslatedText>{tr(question?.gradingMode === "behavior" ? "Trigger grading fires hidden test scenarios and compares the resulting data with the reference. Code formatting and names do not matter." : "Trigger grading uses exact reference code, allowing only outer whitespace and Windows newline differences.")}</TranslatedText></p>}
    {(part !== "SQL" || question?.hasSetup) && <p className="problem-stage-guidance">
      <span><Database size={13} aria-hidden="true" /><TranslatedText>{tr("Initial state")}</TranslatedText>: <TranslatedText>{tr(question?.hasSetup ? "Prepared database for this question" : mode === "empty" ? "Empty database" : mode === "structure" ? "Table structure only" : "Original database")}</TranslatedText></span>
    </p>}
    {part === "SQL" && <ProblemExpectedOutput table={question ? undefined : problem.expected} samples={question ? (question.expectedSamples?.length ? question.expectedSamples : computedSamples) : problem.expectedSamples} />}
    {question && hasContext && <details className="problem-question-context" open>
      <summary><FileText size={14} aria-hidden="true" /><span><TranslatedText>{tr("General statement")}</TranslatedText></span><ChevronRight size={15} aria-hidden="true" /></summary>
      <div>{context}</div>
    </details>}
  </div>;
}
