import { TranslatedText } from "@/shared/ui/TranslatedText";
import { LanguageSwitch } from "@/shared/ui/LanguageSwitch";
import { useLanguage } from "@/shared/context/LanguageContext";
import { useState } from "react";
import { APP_NAME } from "@/data/models";

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

export function AuthLayout({
  register = false,
  title,
  subtitle,
  showTeacherNote = true,
  centered = false,
  compact = false,
  children,
}: {
  register?: boolean;
  title?: string;
  subtitle?: string;
  showTeacherNote?: boolean;
  centered?: boolean;
  compact?: boolean;
  children: React.ReactNode;
}) {
  const { t: tr } = useLanguage("auth");
  return (
    <main
      id="main-content"
      className={`auth-page${centered ? " auth-page--centered" : " auth-page--split"}${compact ? " auth-page--compact" : ""}${register ? " auth-page--register" : ""}`}
    >
      {!centered && (
      <section className="auth-brand-panel" aria-label={tr("{value0} introduction", { value0: APP_NAME })}>
        <div className="auth-wordmark"><img src="/favicon.svg" width="32" height="32" alt="" /><b>{APP_NAME}</b></div>
        <div className="auth-pitch">
          <div className="auth-pitch-copy"><span className="auth-eyebrow"><TranslatedText>{tr("SQL PRACTICE AT UIT")}</TranslatedText></span><h2><TranslatedText>{tr("Write queries.")}</TranslatedText><br /><span><TranslatedText>{tr("Understand data.")}</TranslatedText></span></h2><p><TranslatedText>{tr("Practice on real schemas, see the result of every query, and build your SQL skills one problem at a time.")}</TranslatedText></p></div>
          <div className="editor-preview" aria-label={tr("SQL editor preview")}>
            <div className="preview-bar"><b>join-orders.sql</b><span><TranslatedText>{tr("Run")}</TranslatedText></span></div>
            <pre><code><i>1</i>  <em>-- Orders per customer</em>{"\n"}<i>2</i>  <strong>SELECT</strong> c.name, COUNT(o.id) <strong>AS</strong> orders{"\n"}<i>3</i>  <strong>FROM</strong> customers c{"\n"}<i>4</i>  <strong>LEFT JOIN</strong> orders o <strong>ON</strong> o.customer_id = c.id{"\n"}<i>5</i>  <strong>GROUP BY</strong> c.name{"\n"}<i>6</i>  <strong>ORDER BY</strong> orders <strong>DESC</strong>;</code></pre>
            <div className="preview-result">
              <table aria-label={tr("Sample query result")}>
                <thead><tr><th scope="col">name</th><th scope="col">orders</th></tr></thead>
                <tbody>
                  <tr><td>Nguyen An</td><td>4</td></tr>
                  <tr><td>Tran Binh</td><td>3</td></tr>
                  <tr><td>Le Chi</td><td>0</td></tr>
                </tbody>
              </table>
              <b><TranslatedText>{tr("Accepted · 3 rows · 12 ms")}</TranslatedText></b>
            </div>
          </div>
        </div>
        <small>{APP_NAME} · <TranslatedText>{tr("Write queries. Understand data.")}</TranslatedText></small>
      </section>
      )}
      <section className="auth-form-panel">
        <div className="auth-form-wrap">
          <div className="u267-auth-language"><LanguageSwitch /></div>
          <div className="auth-heading">
            <span className="auth-form-eyebrow">{APP_NAME} <TranslatedText>{tr("ACCOUNT")}</TranslatedText></span>
            <h1><TranslatedText>{title ?? (register ? tr("Create your account") : tr("Welcome back"))}</TranslatedText></h1>
            <p>
              <TranslatedText>{tr(subtitle) ?? (register ? tr("Register with your student email to start practicing SQL.") : tr("Sign in to continue practicing SQL."))}</TranslatedText>
            </p>
          </div>
          {children}
          {showTeacherNote && (
            <p className="auth-note">
              <TranslatedText>{tr("Teacher accounts are created by an administrator.")}</TranslatedText>
            </p>
          )}
        </div>
      </section>
    </main>
  );
}

export function RecoveryUnavailable() {
  const { t: tr } = useLanguage("auth");
  return (
    <p className="form-message" role="status">
      <TranslatedText>{tr("Password recovery is not connected yet. Ask an administrator to reset your account password.")}</TranslatedText>
    </p>
  );
}
