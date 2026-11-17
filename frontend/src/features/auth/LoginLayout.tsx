// Lucian UX/UI Prompt #216 · MIT License (c) 2026 Seokwoooo · https://lucianlabs.dev/uxui-prompt/#216
import { useEffect, useState, type CSSProperties, type ReactNode } from "react";
import { ArrowRight, Check, CheckCheck, Code2, Moon, Play, Sun, Zap } from "lucide-react";
import { LanguageSwitch } from "@/shared/ui/LanguageSwitch";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import { useLanguage } from "@/shared/context/LanguageContext";
import { useTheme } from "@/shared/context/ThemeContext";
import { APP_NAME } from "@/data/models";
import "@/features/auth/LoginLayout.css";

const delay = (ms: number) => ({ "--u216-delay": `${ms}ms` }) as CSSProperties;

export function LoginLayout({ children }: { children: ReactNode }) {
  const { t } = useLanguage("auth");
  const { dark, toggle } = useTheme();
  const [entered, setEntered] = useState(false);

  useEffect(() => {
    const frame = requestAnimationFrame(() => setEntered(true));
    return () => cancelAnimationFrame(frame);
  }, []);

  return (
    <main id="main-content" className={`u216-login${entered ? " is-in" : ""}`}>
      <section className="u216-hero" aria-label={t("{value0} introduction", { value0: APP_NAME })}>
        <div className="u216-brand u216-enter u216-nav" style={delay(0)}>
          <img src="/favicon.svg" width="38" height="38" alt="" /><b>{APP_NAME}</b>
          <span className="u216-brand-divider" aria-hidden="true" />
          <span className="u216-brand-caption"><TranslatedText>{t("SQL PRACTICE")}</TranslatedText></span>
        </div>

        <div className="u216-hero-content">
          <div className="u216-badge u216-enter" style={delay(180)}>
            <span aria-hidden="true" /><TranslatedText>{t("SQL PRACTICE AT UIT")}</TranslatedText>
          </div>
          <h2 className="u216-headline" aria-label={`${t("Write queries.")} ${t("Understand data.")}`}>
            {["Write queries.", "Understand data."].map((line, lineIndex) => (
              <span className="u216-line" key={line} aria-hidden="true">
                {t(line).split(" ").map((word, wordIndex) => (
                  <span className="u216-word" key={wordIndex}>
                    <span style={delay(300 + lineIndex * 210 + wordIndex * 70)}>{word}</span>{" "}
                  </span>
                ))}
              </span>
            ))}
          </h2>
          <p className="u216-description u216-enter" style={delay(620)}>
            <TranslatedText>{t("Practice on real schemas, see the result of every query, and build your SQL skills one problem at a time.")}</TranslatedText>
          </p>
          <div className="u216-features u216-enter" style={delay(760)}>
            <span><Code2 size={15} aria-hidden="true" /><TranslatedText>{t("Real schemas")}</TranslatedText></span>
            <span><Zap size={15} aria-hidden="true" /><TranslatedText>{t("Instant feedback")}</TranslatedText></span>
          </div>

          <div className="u216-editor u216-enter" style={delay(900)} role="group" aria-label={t("SQL editor preview")}>
            <div className="u216-editor-bar">
              <span className="u216-window-dots" aria-hidden="true"><i /><i /><i /></span>
              <span className="u216-file"><Code2 size={13} aria-hidden="true" />join-orders.sql</span>
              <span className="u216-run"><Play size={11} fill="currentColor" aria-hidden="true" /><TranslatedText>{t("Run")}</TranslatedText></span>
            </div>
            <pre><code><i>1</i>  <em>-- Orders per customer</em>{"\n"}<i>2</i>  <strong>SELECT</strong> c.name, COUNT(o.id) <strong>AS</strong> orders{"\n"}<i>3</i>  <strong>FROM</strong> customers c{"\n"}<i>4</i>  <strong>LEFT JOIN</strong> orders o <strong>ON</strong> o.customer_id = c.id{"\n"}<i>5</i>  <strong>GROUP BY</strong> c.name{"\n"}<i>6</i>  <strong>ORDER BY</strong> orders <strong>DESC</strong>;</code></pre>
            <div className="u216-results">
              <div className="u216-result-label"><span><CheckCheck size={14} aria-hidden="true" /><TranslatedText>{t("Query result")}</TranslatedText></span><span><TranslatedText>{t("3 rows")}</TranslatedText></span></div>
              <table aria-label={t("Sample query result")}>
                <thead><tr><th scope="col">name</th><th scope="col">orders</th></tr></thead>
                <tbody><tr><td>Nguyen An</td><td>4</td></tr><tr><td>Tran Binh</td><td>3</td></tr><tr><td>Le Chi</td><td>0</td></tr></tbody>
              </table>
            </div>
            <div className="u216-editor-status"><Check size={13} aria-hidden="true" /><TranslatedText>{t("Accepted · 3 rows · 12 ms")}</TranslatedText></div>
          </div>
        </div>
        <footer className="u216-hero-footer u216-enter" style={delay(1000)}><span><TranslatedText>{t("{value0} · UIT", { value0: APP_NAME })}</TranslatedText></span><span><TranslatedText>{t("One query at a time.")}</TranslatedText><ArrowRight size={13} aria-hidden="true" /></span></footer>
      </section>

      <section className="u216-form-panel" aria-labelledby="u216-login-title">
        <header className="u216-toolbar u216-enter u216-nav" style={delay(0)}>
          <div className="u216-mobile-brand"><img src="/favicon.svg" width="32" height="32" alt="" /><b>{APP_NAME}</b></div>
          <div className="u216-preferences">
            <button type="button" className="u216-theme" aria-label={t(dark ? "Switch to light mode" : "Switch to dark mode")} onClick={event => {
              const rect = event.currentTarget.getBoundingClientRect();
              toggle({ x: rect.left + rect.width / 2, y: rect.top + rect.height / 2 });
            }}>{dark ? <Sun size={18} aria-hidden="true" /> : <Moon size={18} aria-hidden="true" />}</button>
            <LanguageSwitch />
          </div>
        </header>
        <div className="u216-form-content u216-enter" style={delay(300)}>
          <div className="u216-form-icon" aria-hidden="true"><img src="/favicon.svg" width="40" height="40" alt="" /></div>
          <div className="u216-form-heading">
            <span>{APP_NAME} <TranslatedText>{t("ACCOUNT")}</TranslatedText></span>
            <h1 id="u216-login-title"><TranslatedText>{t("Welcome back")}</TranslatedText><span className="u216-heading-dot" aria-hidden="true">.</span></h1>
            <p><TranslatedText>{t("Sign in to continue practicing SQL.")}</TranslatedText></p>
          </div>
          {children}
        </div>
        <footer className="u216-form-footer u216-enter" style={delay(760)}>{APP_NAME} · <TranslatedText>{t("Write queries. Understand data.")}</TranslatedText></footer>
      </section>
    </main>
  );
}
