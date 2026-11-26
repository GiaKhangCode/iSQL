import { TranslatedText } from "@/shared/ui/TranslatedText";
import { useLanguage } from "@/shared/context/LanguageContext";
import { useState } from "react";

export type Errors = Record<string, string>;

export function PasswordField({
  id,
  label,
  value,
  onChange,
  error,
  autoComplete,
  placeholder,
}: {
  id: string;
  label: string;
  value: string;
  onChange: (v: string) => void;
  error?: string;
  autoComplete: string;
  placeholder?: string;
}) {
  const { t: tr } = useLanguage("auth");
  const [visible, setVisible] = useState(false);
  return (
    <label className="field" htmlFor={id}>
      <TranslatedText>{tr(label)}</TranslatedText>
      <span className="password-input">
        <input
          id={id}
          type={visible ? "text" : "password"}
          value={value}
          placeholder={tr(placeholder)}
          autoComplete={autoComplete}
          onChange={(e) => onChange(e.target.value)}
          aria-invalid={!!error}
          aria-describedby={error ? id + "-error" : undefined}
        />
        <button
          type="button"
          className="icon-button"
          onClick={() => setVisible((v) => !v)}
          aria-label={
            visible
              ? "Hide " + label.toLowerCase()
              : "Show " + label.toLowerCase()
          }
        >
          <TranslatedText>{visible ? tr("Hide") : tr("Show")}</TranslatedText>
        </button>
      </span>
      {error && (
        <small id={id + "-error"} className="field-error">
          <TranslatedText>{tr(error)}</TranslatedText>
        </small>
      )}
    </label>
  );
}
