import { TranslatedText } from "@/shared/ui/TranslatedText";
import { useRef } from "react";
import { useLanguage } from "@/shared/context/LanguageContext";
import "@/features/teacher/submissions/SubmissionLimitControl.css";

export const DEFAULT_SUBMISSION_LIMIT = 2;

export function SubmissionLimitControl({ value, disabled = false, compact = false, onChange }: { value: number | null; disabled?: boolean; compact?: boolean; onChange: (value: number | null) => void }) {
  const { t } = useLanguage("teacher");
  const limited = value != null;
  const pointerStart = useRef<number | null>(null);
  const dragged = useRef(false);
  return <div className={`teacher-submission-limit-control${compact ? " is-compact" : ""}`} role="group" aria-label={t("Submission limit")}>
    <span className={!limited || compact ? "is-selected" : ""}><TranslatedText>{t(compact && limited ? "Limited" : "Unlimited")}</TranslatedText></span>
    <button type="button" role="switch" className="teacher-limit-switch" aria-label={t("Submission limit")} aria-checked={limited} disabled={disabled}
      onPointerDown={event => { if (event.button !== 0) return; dragged.current = false; pointerStart.current = event.clientX; event.currentTarget.setPointerCapture(event.pointerId); }}
      onPointerUp={event => { const start = pointerStart.current; pointerStart.current = null; if (start == null || Math.abs(event.clientX - start) < 8) return; dragged.current = true; onChange(event.clientX > start ? value ?? DEFAULT_SUBMISSION_LIMIT : null); }}
      onPointerCancel={() => { pointerStart.current = null; dragged.current = false; }}
      onLostPointerCapture={() => { pointerStart.current = null; }}
      onKeyDown={event => { if (event.key !== "ArrowLeft" && event.key !== "ArrowRight") return; event.preventDefault(); onChange(event.key === "ArrowRight" ? value ?? DEFAULT_SUBMISSION_LIMIT : null); }}
      onClick={event => { if (dragged.current && event.detail !== 0) { dragged.current = false; return; } dragged.current = false; onChange(limited ? null : DEFAULT_SUBMISSION_LIMIT); }}><span aria-hidden="true" /></button>
    {!compact && <span className={limited ? "is-selected" : ""}><TranslatedText>{t("Number of submissions")}</TranslatedText></span>}
    <span className="teacher-limit-value" data-open={limited} aria-hidden={!limited}><input type="number" min="1" max="100" step="1" aria-label={t("Submission limit")} disabled={disabled || !limited} tabIndex={limited ? 0 : -1} value={value ?? DEFAULT_SUBMISSION_LIMIT} onChange={event => onChange(event.target.value ? Number(event.target.value) : DEFAULT_SUBMISSION_LIMIT)} /></span>
  </div>;
}
