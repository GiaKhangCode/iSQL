import { useEffect, useId, useRef, useState } from "react";
import { ChevronDown } from "lucide-react";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import "./MultiSelect.css";

type Option = { value: string; label: string; hint?: string };

/**
 * Dropdown with checkboxes. An empty selection means "everything" and is shown as `allLabel`;
 * ticking the first row clears the selection.
 */
export function MultiSelect({ label, allLabel, countLabel, options, values, onChange, disabled = false, className = "" }: {
  label: string; allLabel: string; countLabel: (count: number) => string; options: Option[]; values: string[];
  onChange: (values: string[]) => void; disabled?: boolean; className?: string;
}) {
  const [open, setOpen] = useState(false);
  const root = useRef<HTMLSpanElement>(null);
  const trigger = useRef<HTMLButtonElement>(null);
  const listId = useId();

  useEffect(() => {
    if (!open) return;
    const away = (event: PointerEvent) => { if (!root.current?.contains(event.target as Node)) setOpen(false); };
    document.addEventListener("pointerdown", away);
    return () => document.removeEventListener("pointerdown", away);
  }, [open]);
  useEffect(() => { if (disabled) setOpen(false); }, [disabled]);

  const known = values.filter(value => options.some(option => option.value === value));
  const summary = known.length === 0 ? allLabel : known.length === 1 ? options.find(option => option.value === known[0])!.label : countLabel(known.length);
  const toggle = (value: string) => onChange(known.includes(value) ? known.filter(item => item !== value) : [...known, value]);

  return <span ref={root} className={`app-select multi-select ${className}`}
    onKeyDown={event => { if (event.key === "Escape" && open) { event.stopPropagation(); setOpen(false); trigger.current?.focus(); } }}
    onBlur={event => { if (open && !root.current?.contains(event.relatedTarget as Node | null)) setOpen(false); }}>
    <button ref={trigger} type="button" className="u027-select" aria-haspopup="listbox" aria-expanded={open} aria-controls={open ? listId : undefined}
      aria-label={`${label}: ${summary}`} disabled={disabled} onClick={() => setOpen(value => !value)}>
      <span className="u027-select-value"><TranslatedText>{summary}</TranslatedText></span>
      <ChevronDown className="u027-select-chevron" size={15} aria-hidden="true" />
    </button>
    {open && <div id={listId} className="multi-select-popover" role="group" aria-label={label}>
      <label className="multi-select-option is-all">
        <input type="checkbox" checked={known.length === 0} onChange={() => onChange([])} />
        <span><TranslatedText>{allLabel}</TranslatedText></span>
      </label>
      {options.map(option => <label className="multi-select-option" key={option.value} title={option.hint ? `${option.label} — ${option.hint}` : option.label}>
        <input type="checkbox" checked={known.includes(option.value)} onChange={() => toggle(option.value)} />
        <span><TranslatedText>{option.label}</TranslatedText>{option.hint && <small>{option.hint}</small>}</span>
      </label>)}
    </div>}
  </span>;
}
