import { useEffect, useState } from "react";
import type { WorkspacePart } from "@/data/models";
import { useLanguage } from "@/shared/context/LanguageContext";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import {
  SCORING_CUSTOM,
  SCORING_EQUAL,
  formatPoints,
  questionPoint,
  withQuestionPoint,
  type ScoringMode,
} from "@/features/teacher/builder/questionScoring";
import type { QuestionSelection } from "@/features/teacher/builder/BuilderProblemSettings";
import "@/features/teacher/builder/BuilderScoring.css";

/** Chọn cách tính điểm: chia đều cho mọi câu, hoặc giảng viên nhập điểm từng câu. */
export function ScoringModeControl({ value, onChange, disabled = false }: {
  value: ScoringMode;
  onChange: (mode: ScoringMode) => void;
  disabled?: boolean;
}) {
  const { t } = useLanguage("teacher");
  const options: [ScoringMode, string][] = [[SCORING_EQUAL, "Equal scoring"], [SCORING_CUSTOM, "Custom points"]];
  return (
    <div className="builder-scoring-mode" role="radiogroup" aria-label={t("Scoring mode")}>
      {options.map(([mode, label]) => (
        <button
          key={mode}
          type="button"
          role="radio"
          aria-checked={value === mode}
          className={value === mode ? "is-active" : ""}
          disabled={disabled}
          onClick={() => value !== mode && onChange(mode)}
        >
          <TranslatedText>{t(label)}</TranslatedText>
        </button>
      ))}
    </div>
  );
}

/** Tổng điểm hiện tại ở chế độ tùy chỉnh, ví dụ "Tổng: 9.5/10 điểm" (đỏ khi lệch). */
export function CustomScoreTotal({ total, target = 10 }: { total: number; target?: number }) {
  const { t } = useLanguage("teacher");
  const ok = Math.abs(total - target) < 0.001;
  return (
    <strong className={`builder-custom-total${ok ? "" : " is-off"}`}>
      <TranslatedText>{t("Total: {value0}/{value1} points", { value0: formatPoints(total), value1: target })}</TranslatedText>
    </strong>
  );
}

/** Ô nhập điểm của một nhóm câu hỏi (điểm mỗi câu) hoặc của một câu chung. Trống = chưa có điểm. */
export function PointsField({ value, label, onChange, disabled = false }: {
  value: string | number | undefined;
  label: string;
  onChange: (value: string) => void;
  disabled?: boolean;
}) {
  const { t } = useLanguage("teacher");
  const text = value === undefined ? "" : String(value);
  const missing = !(Number(text.replace(",", ".")) > 0);
  return (
    <span className={`builder-question-points${missing ? " is-missing" : ""}`}>
      <input type="text" inputMode="decimal" value={text} disabled={disabled} placeholder="—" aria-label={label} onChange={event => onChange(event.target.value)} />
      <span aria-hidden="true"><TranslatedText>{t("pts")}</TranslatedText></span>
    </span>
  );
}

/** Ô nhập điểm của một câu; ghi vào `_questionPoints` của cấu hình chọn câu. Để trống = chưa có điểm. */
export function QuestionPointInput({ selection, part, questionId, questionName, onChange, disabled = false }: {
  selection?: QuestionSelection;
  part: WorkspacePart;
  questionId: string;
  questionName: string;
  onChange: (selection: QuestionSelection) => void;
  disabled?: boolean;
}) {
  const { t } = useLanguage("teacher");
  const stored = questionPoint(selection, part, questionId);
  const [text, setText] = useState(stored === undefined ? "" : formatPoints(stored));

  // Đồng bộ khi điểm đổi từ nơi khác (đổi mã đề, tải lại) mà không ghi đè chữ đang gõ dở.
  useEffect(() => {
    const typed = Number(text.replace(",", "."));
    const same = stored === undefined ? text.trim() === "" : Number.isFinite(typed) && Math.abs(typed - stored) < 0.001;
    if (!same) setText(stored === undefined ? "" : formatPoints(stored));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [stored]);

  return (
    <label className={`builder-question-points${stored === undefined ? " is-missing" : ""}`} onClick={event => event.stopPropagation()}>
      <input
        type="text"
        inputMode="decimal"
        value={text}
        disabled={disabled}
        placeholder="—"
        aria-label={t("Points for {value0}", { value0: questionName })}
        onChange={event => {
          setText(event.target.value);
          onChange(withQuestionPoint(selection || {}, part, questionId, event.target.value));
        }}
      />
      <span aria-hidden="true"><TranslatedText>{t("pts")}</TranslatedText></span>
    </label>
  );
}
