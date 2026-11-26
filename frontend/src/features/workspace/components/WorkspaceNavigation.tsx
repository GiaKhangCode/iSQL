import { useState, useRef, useEffect, useLayoutEffect, useId } from "react";
import { createPortal } from "react-dom";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import { useLanguage } from "@/shared/context/LanguageContext";
import type { WorkspacePart } from "@/data/models";
import { WORKSPACE_PART_LABELS, WORKSPACE_PART_TOOLTIPS } from "@/shared/lib/workspace/workspaceParts";
import { GlidingTooltipGroup } from "@/shared/ui/GlidingTooltip";
import { ArrowLeft, ArrowRight, ChevronDown } from "lucide-react";

export function WorkspaceNavigation({
  parts, active, onChange, questionGroups, selectedQuestionIds, onQuestionChange, isQuestionDone, disabled = false,
}: {
  parts: WorkspacePart[];
  active: WorkspacePart;
  onChange: (part: WorkspacePart) => void;
  questionGroups?: Record<string, { id: string; description?: string }[]>;
  selectedQuestionIds?: Record<string, string>;
  onQuestionChange?: (part: WorkspacePart, id: string) => void;
  isQuestionDone?: (part: WorkspacePart, id: string) => boolean;
  disabled?: boolean;
}) {
  const { t: tr } = useLanguage("workspace");
  const menuId = useId();
  const [dropdownOpen, setDropdownOpen] = useState(false);
  const [cursor, setCursor] = useState(0);
  const trigger = useRef<HTMLButtonElement>(null);
  const menu = useRef<HTMLDivElement>(null);
  const restoreFocus = useRef(false);
  const [position, setPosition] = useState({ left: 0, top: 0, width: 320, maxHeight: 280 });
  const questions = questionGroups?.[active] || [];
  const currentIndex = Math.max(0, questions.findIndex(q => q.id === selectedQuestionIds?.[active]));
  const selectedId = questions[currentIndex]?.id;
  const shown = dropdownOpen && !disabled && questions.length > 0;
  const questionLabel = (index: number) => tr("Question {value0} {value1}", { value0: index + 1, value1: "" }).trim();

  function choose(id: string) {
    restoreFocus.current = true;
    onQuestionChange?.(active, id);
    setDropdownOpen(false);
  }

  useLayoutEffect(() => {
    if (restoreFocus.current) {
      trigger.current?.focus({ preventScroll: true });
      restoreFocus.current = false;
    }
  }, [selectedId, dropdownOpen]);

  useEffect(() => { setDropdownOpen(false); }, [active, selectedId, disabled, questions.length]);

  useLayoutEffect(() => {
    if (!shown) return;
    const place = () => {
      const rect = trigger.current?.getBoundingClientRect();
      if (!rect) return;
      const width = Math.min(320, window.innerWidth - 24);
      const below = window.innerHeight - rect.bottom - 18;
      const above = rect.top - 18;
      const height = Math.min(280, menu.current?.scrollHeight || 280);
      const upward = below < height && above > below;
      const maxHeight = Math.max(0, Math.min(280, upward ? above : below));
      setPosition({
        left: Math.max(12, Math.min(rect.left, window.innerWidth - width - 12)),
        top: upward ? rect.top - Math.min(height, maxHeight) - 6 : rect.bottom + 6,
        width, maxHeight,
      });
    };
    place();
    // The expanding section moves the trigger even when the viewport stays still.
    const observer = new ResizeObserver(place);
    const nav = trigger.current?.closest("nav");
    if (nav) observer.observe(nav);
    nav?.querySelectorAll(".workspace-tab-group").forEach(group => observer.observe(group));
    window.addEventListener("resize", place);
    window.addEventListener("scroll", place, true);
    return () => {
      observer.disconnect();
      window.removeEventListener("resize", place);
      window.removeEventListener("scroll", place, true);
    };
  }, [shown, questions.length]);

  useEffect(() => {
    if (!shown) return;
    const outside = (event: Event) => {
      if (!trigger.current?.contains(event.target as Node) && !menu.current?.contains(event.target as Node)) setDropdownOpen(false);
    };
    document.addEventListener("pointerdown", outside);
    document.addEventListener("focusin", outside);
    return () => {
      document.removeEventListener("pointerdown", outside);
      document.removeEventListener("focusin", outside);
    };
  }, [shown]);

  useEffect(() => {
    if (shown) menu.current?.querySelectorAll<HTMLElement>('[role="option"]')[cursor]?.scrollIntoView?.({ block: "nearest" });
  }, [shown, cursor]);

  return (
    <GlidingTooltipGroup as="nav" role="navigation" className="problem-workspace-tabs" label={tr("Practice sections")} multiline>
      {parts.map(part => {
        const isActive = active === part;
        const group = questionGroups?.[part] || [];
        const index = Math.max(0, group.findIndex(q => q.id === selectedQuestionIds?.[part]));
        return (
          <div key={part} className="workspace-tab-group">
            <button type="button" aria-current={isActive ? "page" : undefined}
              className={`workspace-stage-tab ${isActive ? "active" : ""}`}
              onClick={() => onChange(part)} aria-controls={`workspace-stage-${part}`}
              data-tooltip={tr(WORKSPACE_PART_TOOLTIPS[part])}>
              <b><TranslatedText>{tr(WORKSPACE_PART_LABELS[part])}</TranslatedText></b>
            </button>
            {group.length > 0 && <div className={`workspace-question-slide ${isActive ? "is-open" : ""}`} aria-hidden={!isActive}>
              <div ref={node => { if (node) node.inert = !isActive; }}>
                <div className="workspace-question-strip" role="group" aria-label={`${tr("Choose question")} ${WORKSPACE_PART_LABELS[part]}`}>
                  {group.map((q, idx) => {
                    if (Math.abs(idx - index) > 1) return null;
                    const selected = idx === index;
                    const done = Boolean(isQuestionDone?.(part, q.id));
                    const label = questionLabel(idx);
                    return <button key={q.id} type="button" ref={isActive && selected ? trigger : undefined}
                      className={`workspace-question-pill ${selected ? "active" : "compact"} ${done ? "done" : ""}`}
                      role={selected ? "combobox" : undefined}
                      aria-label={`${label} ${WORKSPACE_PART_LABELS[part]}${done ? ` · ${tr("Done")}` : ""}`}
                      aria-expanded={selected ? isActive && shown : undefined}
                      aria-haspopup={selected ? "listbox" : undefined}
                      aria-controls={isActive && selected && shown ? menuId : undefined}
                      aria-activedescendant={isActive && selected && shown ? `${menuId}-${cursor}` : undefined}
                      disabled={disabled} title={q.description || label}
                      onBlur={() => { if (isActive && selected) setDropdownOpen(false); }}
                      onClick={() => {
                        if (selected) { setCursor(index); setDropdownOpen(open => !open); }
                        else onQuestionChange?.(part, q.id);
                      }}
                      onKeyDown={event => {
                        if (!isActive || !selected) return;
                        if (event.key === "Escape" || event.key === "Tab") { setDropdownOpen(false); return; }
                        if (["ArrowDown", "ArrowUp", "Home", "End"].includes(event.key)) {
                          event.preventDefault();
                          setCursor(event.key === "Home" ? 0 : event.key === "End" ? group.length - 1 : shown ? (cursor + (event.key === "ArrowDown" ? 1 : -1) + group.length) % group.length : index);
                          setDropdownOpen(true);
                        } else if (shown && (event.key === "Enter" || event.key === " ")) {
                          event.preventDefault();
                          choose(group[cursor].id);
                        }
                      }}>
                      <span>{label}</span>{selected && <ChevronDown size={12} aria-hidden="true" className={`workspace-pill-chevron ${isActive && shown ? "open" : ""}`} />}
                    </button>;
                  })}
                </div>
              </div>
            </div>}
          </div>
        );
      })}
      {questions.length > 1 && <div className="workspace-question-steppers" role="group" aria-label={tr("Choose question")}>
        <button type="button" className="workspace-question-stepper-btn" aria-label={tr("Previous question")}
          disabled={disabled || currentIndex === 0} onClick={() => onQuestionChange?.(active, questions[currentIndex - 1].id)}>
          <ArrowLeft size={14} aria-hidden="true" />
        </button>
        <button type="button" className="workspace-question-stepper-btn" aria-label={tr("Next question")}
          disabled={disabled || currentIndex === questions.length - 1} onClick={() => onQuestionChange?.(active, questions[currentIndex + 1].id)}>
          <ArrowRight size={14} aria-hidden="true" />
        </button>
      </div>}
      {shown && createPortal(
        <div ref={menu} className="workspace-question-dropdown" style={position} onMouseDown={event => event.preventDefault()}>
          <div className="workspace-question-dropdown-header">{WORKSPACE_PART_LABELS[active]} · {tr("All questions")} ({questions.length})</div>
          <div id={menuId} className="workspace-question-dropdown-list" role="listbox" aria-label={`${tr("Choose question")} ${WORKSPACE_PART_LABELS[active]}`}>
            {questions.map((item, idx) => <button key={item.id} id={`${menuId}-${idx}`} type="button" role="option" tabIndex={-1}
              aria-selected={idx === currentIndex}
              className={`workspace-question-dropdown-item ${idx === currentIndex ? "active" : ""} ${idx === cursor ? "focused" : ""} ${isQuestionDone?.(active, item.id) ? "done" : ""}`}
              onPointerMove={() => setCursor(idx)} onClick={() => choose(item.id)}>
              <span className="dropdown-item-num">{questionLabel(idx)}</span>
              <span className="dropdown-item-desc" title={item.description}>{item.description}</span>
              {isQuestionDone?.(active, item.id) && <span className="dropdown-item-done-badge">{tr("Done")}</span>}
            </button>)}
          </div>
        </div>, document.body,
      )}
    </GlidingTooltipGroup>
  );
}
