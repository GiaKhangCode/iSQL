import type { ListOrder } from "@/shared/components/ListSortSelect";
import type { ListDates } from "@/shared/components/ListDateFilters";
import { matchesListSearch } from "@/shared/lib/listSearch";
// Lucian UX/UI Prompt #027 · MIT License (c) 2026 Seokwoooo · https://lucianlabs.dev/uxui-prompt/#027
import { useId, useMemo, useRef, useState } from "react";
import { BookOpen, History, ListFilter, Search, X } from "lucide-react";
import { useLanguage } from "@/shared/context/LanguageContext";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import { TeacherListFilters } from "@/shared/components/TeacherListFilters";

type SearchableAssignment = { id: string; title: string; classes: string; status: string };
type Props = {
  order?:ListOrder;
  dates?: ListDates;
  assignments: SearchableAssignment[];
  search: string;
  onSearchChange: (value: string) => void;
  classFilter: string;
  onClassChange: (value: string) => void;
  statusFilter: string;
  onStatusChange: (value: string) => void;
  onCreate: () => void;
  actionsTarget?: HTMLElement | null;
};

export const assignmentClassNames = (classes: string) => classes.split(",").map(name => name.trim()).filter(Boolean);
export const matchesAssignmentSearch = (item: SearchableAssignment, query: string) => matchesListSearch(query,item.id,item.title,item.classes);

function Highlight({ text, query }: { text: string; query: string }) {
  const value = query.trim();
  const index = text.toLocaleLowerCase().indexOf(value.toLocaleLowerCase());
  if (!value || index < 0) return <>{text}</>;
  return <>{text.slice(0, index)}<mark>{text.slice(index, index + value.length)}</mark>{text.slice(index + value.length)}</>;
}

export function AssignmentFilters({ order, dates, assignments, search, onSearchChange, classFilter, onClassChange, statusFilter, onStatusChange, onCreate, actionsTarget }: Props) {
  const { t } = useLanguage("teacher");
  const uid = useId();
  const [suggesting, setSuggesting] = useState(false);
  const [recent, setRecent] = useState<string[]>([]);
  const [active, setActive] = useState(-1);
  const inputRef = useRef<HTMLInputElement>(null);
  const triggerRef = useRef<HTMLButtonElement>(null);
  const classes = useMemo(() => Array.from(new Set(assignments.flatMap(item => assignmentClassNames(item.classes))))
    .filter(name => name !== "No classes").sort((a, b) => a.localeCompare(b, undefined, { numeric: true })), [assignments]);
  const suggestions = assignments.filter(item =>
    (classFilter === "All classes" || assignmentClassNames(item.classes).includes(classFilter)) &&
    (statusFilter === "All statuses" || item.status === statusFilter) && matchesAssignmentSearch(item, search),
  ).slice(0, 4);
  const activeIndex = active >= 0 && active < suggestions.length ? active : -1;

  function remember(value: string) {
    const query = value.trim();
    if (query) setRecent(current => [query, ...current.filter(item => item !== query)].slice(0, 4));
  }
  function closeSuggestions() {
    remember(search);
    setSuggesting(false);
    setActive(-1);
    triggerRef.current?.focus();
  }
  function changeSearch(value: string) {
    onSearchChange(value);
    setActive(-1);
    setSuggesting(true);
  }
  function choose(value: string) {
    onSearchChange(value);
    remember(value);
    setActive(-1);
    inputRef.current?.focus();
    setSuggesting(false);
  }

  return <TeacherListFilters order={order} dates={dates} search={search} onSearchChange={onSearchChange} searchLabel={t("Search assignments…")}
    createLabel={t("New assignment")} onCreate={onCreate} actionsTarget={actionsTarget} triggerRef={triggerRef}
    onOpenChange={() => { setSuggesting(false); setActive(-1); remember(search); }} onReset={() => setActive(-1)}
    filters={[
      { key: "class", label: t("CLASS"), value: classFilter, defaultValue: "All classes", icon: <BookOpen size={17} aria-hidden="true" />,
        options: [{ value: "All classes", label: t("All classes") }, ...classes.map(name => ({ value: name, label: name }))], onChange: value => { onClassChange(value); setActive(-1); } },
      { key: "status", label: t("STATUS"), value: statusFilter, defaultValue: "All statuses", icon: <ListFilter size={17} aria-hidden="true" />,
        options: ["All statuses", "Open", "Scheduled", "Closed", "Draft"].map(status => ({ value: status, label: t(status) })), onChange: value => { onStatusChange(value); setActive(-1); } },
    ]}
    renderSearch={({ open: filtersOpen }) => {
      const open = filtersOpen && suggesting;
      return <div className="u027-search"
        onBlur={event => {
          if (!event.currentTarget.contains(event.relatedTarget as Node | null)) {
            setSuggesting(false);
            setActive(-1);
            remember(search);
          }
        }}
        onKeyDown={event => { if (event.key === "Escape" && !event.nativeEvent.isComposing) { event.preventDefault(); closeSuggestions(); } }}>
        <div className="u027-field">
          <Search className="u027-search-icon" size={16} aria-hidden="true" />
          <input ref={inputRef} id={`${uid}-input`} className="u027-input" value={search} onChange={event => changeSearch(event.target.value)}
            role="combobox" aria-label={t("Search assignments…")} aria-autocomplete="list" aria-expanded={open}
            aria-controls={`${uid}-results`} aria-activedescendant={open && activeIndex >= 0 ? `${uid}-option-${activeIndex}` : undefined}
            placeholder={t("Search by assignment or class…")} autoComplete="off" tabIndex={filtersOpen ? 0 : -1}
            onFocus={() => setSuggesting(true)}
            onKeyDown={event => {
              if (event.nativeEvent.isComposing || event.keyCode === 229) return;
              if ((event.key === "ArrowDown" || event.key === "ArrowUp") && suggestions.length) {
                event.preventDefault(); setSuggesting(true);
                setActive(event.key === "ArrowDown" ? (activeIndex + 1) % suggestions.length : (activeIndex < 0 ? suggestions.length - 1 : (activeIndex - 1 + suggestions.length) % suggestions.length));
              }
              if (event.key === "Enter") {
                event.preventDefault();
                if (open && activeIndex >= 0) choose(suggestions[activeIndex].title);
                else { remember(search); setSuggesting(false); }
              }
            }} />
          {search && <button className="u027-close" type="button" tabIndex={filtersOpen ? 0 : -1} aria-label={t("Clear assignment search")} onClick={() => { changeSearch(""); inputRef.current?.focus(); }}><X size={14} aria-hidden="true" /></button>}
        </div>
        <div className={`u027-pop${open ? " is-visible" : ""}`} aria-hidden={!open}>
          {recent.length > 0 && <><p className="u027-section"><History size={13} aria-hidden="true" /><TranslatedText>{t("Recent searches")}</TranslatedText></p>
            <div className="u027-recent">{recent.map(value => <button key={value} type="button" tabIndex={open ? 0 : -1} onClick={() => choose(value)}>{value}</button>)}</div></>}
          <p className="u027-section"><TranslatedText>{t(search.trim() ? "Matching assignments" : "Suggested assignments")}</TranslatedText></p>
          <ul id={`${uid}-results`} className="u027-results" role="listbox" aria-label={t("Assignment suggestions")}>
            {suggestions.map((item, index) => <li key={item.id} id={`${uid}-option-${index}`} role="option" aria-selected={activeIndex === index}
              className="u027-option" style={{ transitionDelay: `${index * 40}ms` }}
              onPointerMove={() => setActive(index)} onMouseDown={event => event.preventDefault()} onClick={() => choose(item.title)}>
              <span className="u027-option-icon"><BookOpen size={16} aria-hidden="true" /></span>
              <span className="u027-option-copy"><b><Highlight text={item.title} query={search} /></b><small><Highlight text={item.classes} query={search} /> · <TranslatedText>{t(item.status)}</TranslatedText></small></span>
            </li>)}
          </ul>
          {!suggestions.length && <p className="u027-empty"><TranslatedText>{t("No assignments match these filters.")}</TranslatedText></p>}
          <p className="u027-hint"><TranslatedText>{t("↑ ↓ to choose · Enter to apply · Esc to close")}</TranslatedText></p>
        </div>
      </div>;
    }} />;
}
