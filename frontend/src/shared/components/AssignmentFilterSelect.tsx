import { useEffect, useId, useLayoutEffect, useRef, useState, type ReactNode } from "react";
import { createPortal } from "react-dom";
import { Check, ChevronDown } from "lucide-react";

type Option = { value: string; label: string; disabled?: boolean };

/** Keep focus on the control; render the menu outside the scrolling table. */
export function AssignmentFilterSelect({ label, value, options, icon, active, disabled, onChange, className = "", triggerId, labelledBy, exactLabel=false, required=false }: {
  label: string; value: string; options: Option[]; icon: ReactNode;
  className?: string; triggerId?: string; labelledBy?: string; exactLabel?: boolean; required?:boolean; active: boolean; disabled: boolean; onChange: (value: string) => void;
}) {
  const id = useId();
  const trigger = useRef<HTMLButtonElement>(null);
  const [portalHost,setPortalHost]=useState<HTMLElement|null>(null);
  useLayoutEffect(()=>{setPortalHost(trigger.current?.closest("dialog") ?? document.body);},[]);
  const menu = useRef<HTMLDivElement>(null);
  const typeahead = useRef({ text: "", time: 0 });
  const [open, setOpen] = useState(false);
  const [cursor, setCursor] = useState(0);
  const [position, setPosition] = useState({ left: 0, top: 0, width: 0, maxHeight: 300 });
  const shown = open && !disabled;
  const selected = options.findIndex(option => option.value === value);
  const current = options[selected]?.label ?? value;
  const activeIndex = Math.min(cursor, options.length - 1);

  function show(index = Math.max(0, selected)) {
    setCursor(index);
    setOpen(true);
  }
  function choose(index: number) {
    const option = options[index];
    if (!option || option.disabled) return;
    onChange(option.value);
    setOpen(false);
    trigger.current?.focus({ preventScroll: true });
  }

  useEffect(() => { if (disabled) setOpen(false); }, [disabled]);
  useLayoutEffect(() => {
    if (!shown) return;
    const place = () => {
      const rect = trigger.current?.getBoundingClientRect();
      if (!rect) return;
      const width = Math.min(Math.max(rect.width, 220), window.innerWidth - 24);
      const below = window.innerHeight - rect.bottom - 16;
      const above = rect.top - 16;
      const height = Math.min(300, options.length * 40 + 12);
      const upward = below < height && above > below;
      const maxHeight = Math.max(0, Math.min(300, upward ? above : below));
      setPosition({
        left: Math.max(12, Math.min(rect.left, window.innerWidth - width - 12)),
        top: upward ? rect.top - Math.min(height, maxHeight) - 6 : rect.bottom + 6,
        width, maxHeight,
      });
    };
    place();
    window.addEventListener("resize", place);
    window.addEventListener("scroll", place, true);
    return () => {
      window.removeEventListener("resize", place);
      window.removeEventListener("scroll", place, true);
    };
  }, [shown, options.length]);
  useEffect(() => {
    if (!shown) return;
    const outside = (event: PointerEvent) => {
      if (!trigger.current?.contains(event.target as Node) && !menu.current?.contains(event.target as Node)) setOpen(false);
    };
    const blur = () => setOpen(false);
    document.addEventListener("pointerdown", outside);
    window.addEventListener("blur", blur);
    return () => { document.removeEventListener("pointerdown", outside); window.removeEventListener("blur", blur); };
  }, [shown]);
  useEffect(() => {
    if (shown) menu.current?.querySelectorAll<HTMLElement>('[role="option"]')[activeIndex]?.scrollIntoView?.({ block: "nearest" });
  }, [shown, activeIndex]);

  return <>
    <button ref={trigger} type="button" role="combobox" id={triggerId} className={`u027-select${active ? " is-active" : ""} ${className}`}
      disabled={disabled} aria-required={required || undefined} aria-labelledby={labelledBy} aria-label={exactLabel?label:`${label}: ${current}`} aria-expanded={shown} aria-haspopup="listbox"
      aria-controls={shown ? id : undefined} aria-activedescendant={shown && activeIndex >= 0 ? `${id}-${activeIndex}` : undefined}
      onBlur={event => { if (!menu.current?.contains(event.relatedTarget as Node | null)) setOpen(false); }}
      onClick={() => shown ? setOpen(false) : show()}
      onKeyDown={event => {
        if (event.nativeEvent.isComposing || event.keyCode === 229) return;
        if (event.key === "Escape" || event.key === "Tab") { setOpen(false); if (event.key === "Escape" && shown) event.preventDefault(); return; }
        if (event.key === "ArrowDown" || event.key === "ArrowUp") {
          event.preventDefault();
          if (!shown) show();
          else setCursor((activeIndex + (event.key === "ArrowDown" ? 1 : -1) + options.length) % options.length);
          return;
        }
        if (event.key === "Home" || event.key === "End") { event.preventDefault(); show(event.key === "Home" ? 0 : options.length - 1); return; }
        if (event.key === "Enter" || event.key === " ") {
          event.preventDefault(); if (shown) choose(activeIndex); else show(); return;
        }
        if (event.key.length === 1 && !event.ctrlKey && !event.metaKey && !event.altKey) {
          event.preventDefault();
          const time = Date.now();
          const text = (time - typeahead.current.time < 700 ? typeahead.current.text : "") + event.key;
          typeahead.current = { text, time };
          const index = options.findIndex(option => option.label.toLocaleLowerCase().startsWith(text.toLocaleLowerCase()));
          if (index >= 0) show(index);
        }
      }}>
      {icon}<span className="u027-select-value">{current}</span><ChevronDown className="u027-select-chevron" size={15} aria-hidden="true" />
    </button>
    {shown && portalHost && createPortal(<div ref={menu} className="assignment-filters-u027 u027-select-popover" style={position}>
      <div id={id} className="u027-select-menu" role="listbox" aria-label={label}>
        {options.map((option, index) => <div key={option.value} id={`${id}-${index}`} role="option" aria-disabled={option.disabled || undefined} aria-selected={option.value === value}
          className={`u027-select-option${index === activeIndex ? " is-focused" : ""}`} onPointerMove={() => setCursor(index)}
          onMouseDown={event => event.preventDefault()} onClick={() => choose(index)}>
          <span>{option.label}</span>{option.value === value && <Check size={15} aria-hidden="true" />}
        </div>)}
      </div>
    </div>, portalHost)}
  </>;
}
