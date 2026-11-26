import { Select } from "@/shared/ui/Select";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import { ArrowLeft, ArrowRight } from "lucide-react";
import { useLanguage } from "@/shared/context/LanguageContext";
import type { WorkspacePart } from "@/data/models";
import { WORKSPACE_PART_LABELS } from "@/shared/lib/workspace/workspaceParts";

export function ProblemQuestionNavigation({
  part,
  questions,
  selectedId,
  onChange,
  disabled = false,
  header = false,
  variantCode,
}: {
  part: WorkspacePart;
  questions: { id: string; description?: string }[];
  selectedId?: string;
  onChange: (id: string) => void;
  disabled?: boolean;
  header?: boolean;
  variantCode?: string;
}) {
  const { t } = useLanguage("workspace");
  if (!questions.length && !variantCode) return null;
  const index = Math.max(0, questions.findIndex(question => question.id === selectedId));

  const variantBadge = variantCode ? (
    <span
      className="exam-variant-code-badge"
      style={{
        fontSize: "11px",
        fontWeight: 700,
        background: "rgba(99, 102, 241, 0.12)",
        color: "#4f46e5",
        padding: "2px 8px",
        borderRadius: "4px",
        marginRight: "6px",
        letterSpacing: "0.5px",
        display: "inline-flex",
        alignItems: "center",
      }}
    >
      Mã đề: {variantCode}
    </span>
  ) : null;

  if (header) {
    return (
      <div className="workspace-header-variant-group">
        {variantBadge}
        {questions.length > 0 && (
          <div
            className="workspace-header-questions"
            aria-hidden="true"
            ref={node => { if (node) node.inert = true; }}
            style={{
              position: "absolute",
              width: "1px",
              height: "1px",
              padding: 0,
              margin: "-1px",
              overflow: "hidden",
              clip: "rect(0, 0, 0, 0)",
              whiteSpace: "nowrap",
              border: 0,
            }}
          >
            <span className="problem-question-counter">
              <TranslatedText>{t("Question")}</TranslatedText> <strong>{String(index + 1).padStart(2, "0")}</strong>
              <span>/ {String(questions.length).padStart(2, "0")}</span>
            </span>
            <label className="workspace-question-picker">
              <Select
                aria-label={t("Choose question")}
                value={questions[index]?.id}
                disabled={disabled || questions.length < 2}
                onChange={event => onChange(event.target.value)}
              >
                {questions.map((question, number) => (
                  <option key={question.id} value={question.id}>
                    {t("Question {value0} {value1}", { value0: number + 1, value1: WORKSPACE_PART_LABELS[part] })}
                  </option>
                ))}
              </Select>
            </label>
            {questions.length > 1 && (
              <div className="problem-question-stepper" role="group" aria-label={t("Choose question")}>
                <button
                  type="button"
                  aria-label={t("Previous question")}
                  disabled={disabled || index === 0}
                  onClick={() => onChange(questions[index - 1].id)}
                >
                  <ArrowLeft size={15} aria-hidden="true" />
                </button>
                <button
                  type="button"
                  aria-label={t("Next question")}
                  disabled={disabled || index === questions.length - 1}
                  onClick={() => onChange(questions[index + 1].id)}
                >
                  <ArrowRight size={15} aria-hidden="true" />
                </button>
              </div>
            )}
          </div>
        )}
      </div>
    );
  }

  return (
    <div className="problem-question-heading">
      {variantBadge}
      <span className="problem-question-counter">
        <TranslatedText>{t("Question")}</TranslatedText> <strong>{String(index + 1).padStart(2, "0")}</strong>
        <span>/ {String(questions.length).padStart(2, "0")}</span>
      </span>
      <label className="workspace-question-picker">
        <Select
          aria-label={t("Choose question")}
          value={questions[index]?.id}
          disabled={disabled || questions.length < 2}
          onChange={event => onChange(event.target.value)}
        >
          {questions.map((question, number) => (
            <option key={question.id} value={question.id}>
              {t("Question {value0} {value1}", { value0: number + 1, value1: WORKSPACE_PART_LABELS[part] })}
            </option>
          ))}
        </Select>
      </label>
      {questions.length > 1 && (
        <div className="problem-question-stepper" role="group" aria-label={t("Choose question")}>
          <button
            type="button"
            aria-label={t("Previous question")}
            disabled={disabled || index === 0}
            onClick={() => onChange(questions[index - 1].id)}
          >
            <ArrowLeft size={15} aria-hidden="true" />
          </button>
          <button
            type="button"
            aria-label={t("Next question")}
            disabled={disabled || index === questions.length - 1}
            onClick={() => onChange(questions[index + 1].id)}
          >
            <ArrowRight size={15} aria-hidden="true" />
          </button>
        </div>
      )}
    </div>
  );
}
