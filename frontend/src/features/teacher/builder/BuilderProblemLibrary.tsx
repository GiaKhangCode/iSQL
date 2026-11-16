import {TranslatedText} from "@/shared/ui/TranslatedText";
import { useEffect, useRef, useState } from "react";
import { Library, Plus, Search, X } from "lucide-react";
import { useLanguage } from "@/shared/context/LanguageContext";

type LibraryProblem = { id: string; title: string; number?: string | number; topics?: string | string[] };

export function BuilderProblemLibrary({ problems, closing, onAdd, onClose, onClosed }: {
  problems: LibraryProblem[]; closing: boolean; onAdd: (id: string) => void; onClose: () => void; onClosed: () => void;
}) {
  const { t } = useLanguage("teacher");
  const [query, setQuery] = useState("");
  const search = useRef<HTMLInputElement>(null);
  useEffect(() => { search.current?.focus({preventScroll:true}); }, []);
  const term = query.trim().toLocaleLowerCase();
  const results = problems.filter(problem => `${problem.number ?? ""} ${problem.title} ${problem.topics ?? ""}`.toLocaleLowerCase().includes(term));
  return <aside id="builder-problem-library" className={`teacher-student-preview-panel builder-problem-settings builder-problem-library${closing ? " is-closing" : ""}`} aria-labelledby="builder-library-title"
    onKeyDown={event => { if (event.key === "Escape") { event.stopPropagation(); onClose(); } }}
    onAnimationEnd={event => { if (event.target === event.currentTarget && event.animationName === "teacher-preview-panel-out") onClosed(); }}>
    <header className="teacher-student-preview-heading">
      <h2 id="builder-library-title"><Library size={18} aria-hidden="true" /><TranslatedText>{t("Problem library")}</TranslatedText></h2>
      <button type="button" className="icon-button" aria-label={t("Close library")} onClick={onClose}><X size={18} aria-hidden="true" /></button>
    </header>
    <div className="builder-library-search">
      <label><Search size={15} aria-hidden="true" /><input ref={search} type="search" value={query} onChange={event => setQuery(event.target.value)} placeholder={t("Search problems…")} aria-label={t("Search problems…")} /></label>
    </div>
    <div className="builder-problem-settings-scroll teacher-add-problem-list">
      {results.map(problem => <div key={problem.id}>
        <span><small>{problem.number}</small><b>{problem.title}</b>{problem.topics?.length ? <small>{Array.isArray(problem.topics) ? problem.topics.join(" · ") : problem.topics}</small> : null}</span>
        <button className="button teacher-small-button" type="button" aria-label={t("Add {value0}", { value0: problem.title })} onClick={() => { onAdd(problem.id); search.current?.focus({preventScroll:true}); }}><Plus size={14} aria-hidden="true" /><TranslatedText>{t("Add")}</TranslatedText></button>
      </div>)}
      {!results.length && <p className="muted"><TranslatedText>{t(problems.length ? "No matching problems." : "All available problems are already included.")}</TranslatedText></p>}
    </div>
    <footer className="builder-problem-settings-footer"><button type="button" className="button primary" onClick={onClose}><TranslatedText>{t("Done")}</TranslatedText></button></footer>
  </aside>;
}
