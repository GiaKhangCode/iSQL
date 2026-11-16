import { TranslatedText } from "@/shared/ui/TranslatedText";
// Lucian UX/UI Prompt #039 · MIT License (c) 2026 Seokwoooo · https://lucianlabs.dev/uxui-prompt/#039
import { useLayoutEffect, useRef, useState, type CSSProperties, type ReactNode } from "react";
import { useLanguage } from "@/shared/context/LanguageContext";
import "@/features/workspace/components/WorkspacePaneTabs.css";

export type WorkspacePane = "Description" | "Database" | "Run" | "Submission";
const panes: WorkspacePane[] = ["Description", "Database", "Run", "Submission"];

export function WorkspacePaneTabs({ value, onChange, prefix }: { value: WorkspacePane; onChange: (pane: WorkspacePane) => void; prefix: string }) {
  const { t: tr } = useLanguage("workspace");
  const root = useRef<HTMLDivElement>(null);
  const measured = useRef(false);
  const [indicator, setIndicator] = useState({ x: 0, width: 0 });
  function measure(animate: boolean) {
    const node = root.current;
    if (!node) return;
    const active = node.querySelector<HTMLButtonElement>('[aria-selected="true"]');
    if (!active?.offsetWidth) return;
    // Hidden stages are first measured when opened. Resizing should reposition
    // the pill directly; only selecting another tab gets the spring animation.
    node.dataset.ready = animate && measured.current ? "true" : "false";
    measured.current = true;
    setIndicator(previous => previous.x === active.offsetLeft && previous.width === active.offsetWidth ? previous : { x: active.offsetLeft, width: active.offsetWidth });
  }
  useLayoutEffect(() => { measure(true); }, [value, tr]);
  useLayoutEffect(() => {
    const node = root.current;
    if (!node) return;
    const observer = new ResizeObserver(() => measure(false));
    observer.observe(node);
    node.querySelectorAll("button").forEach(button => observer.observe(button));
    return () => observer.disconnect();
  }, []);
  return <div className="pane-tabs workspace-pane-tabs u039-workspace-tabs"><div ref={root} className="u039-tabs" role="tablist" aria-label={tr("Problem information")}>
    <span className="workspace-pane-indicator" aria-hidden="true" style={{ "--tab-x": `${indicator.x}px`, "--tab-width": `${indicator.width}px` } as CSSProperties} />
    {panes.map((pane, index) => <button key={pane} id={`${prefix}-tab-${pane}`} type="button" role="tab" aria-selected={value === pane} aria-controls={`${prefix}-panel`} tabIndex={value === pane ? 0 : -1} onClick={() => onChange(pane)} onKeyDown={event => {
      const next = event.key === "Home" ? 0 : event.key === "End" ? panes.length - 1 : event.key === "ArrowRight" ? (index + 1) % panes.length : event.key === "ArrowLeft" ? (index + panes.length - 1) % panes.length : -1;
      if (next < 0) return;
      event.preventDefault();
      onChange(panes[next]);
      root.current?.querySelectorAll<HTMLButtonElement>("button")[next]?.focus();
    }}><TranslatedText>{tr(pane === "Run" ? "Recent run" : pane)}</TranslatedText></button>)}
  </div></div>;
}

/** Animate the existing panel's sections; never clone forms or submitted work. */
export function WorkspacePanePanel({ value, prefix, children }: { value: WorkspacePane; prefix: string; children: ReactNode }) {
  const root = useRef<HTMLDivElement>(null);
  const previous = useRef(value);
  const scrollPositions = useRef<Partial<Record<WorkspacePane, number>>>({});
  useLayoutEffect(() => {
    const node = root.current;
    const from = previous.current;
    previous.current = value;
    if (!node || from === value) return;
    node.scrollTop = scrollPositions.current[value] || 0;
    const media = window.matchMedia?.("(prefers-reduced-motion: reduce)");
    if (media?.matches) return;
    const direction = panes.indexOf(value) > panes.indexOf(from) ? 1 : -1;
    // The reference uses 70% travel for short music rows. Long SQL documents
    // use a shorter entrance while keeping its spring, fade and 45ms stagger.
    const sections = [...node.children].flatMap(child => child.matches(".workspace-database-content, .workspace-submission-content") ? [...child.children] : [child]);
    const animations: Animation[] = [];
    sections.filter(child => (child as HTMLElement).offsetHeight > 0).slice(0, 6).forEach((child, index) => {
      if (typeof child.animate !== "function") return;
      const delay = index * 45;
      animations.push(child.animate([{ transform: `translateX(${direction * 20}px)` }, { transform: "translateX(0)" }], { duration: 550, delay, easing: "cubic-bezier(.34,1.45,.64,1)", fill: "backwards" }));
      animations.push(child.animate([{ opacity: 0 }, { opacity: 1 }], { duration: 350, delay, easing: "cubic-bezier(.2,.8,.2,1)", fill: "backwards" }));
    });
    const finish = () => { if (media?.matches) animations.forEach(animation => animation.cancel()); };
    media?.addEventListener("change", finish);
    return () => { animations.forEach(animation => animation.cancel()); media?.removeEventListener("change", finish); };
  }, [value]);
  return <div ref={root} className="problem-scroll u039-workspace-panel" id={`${prefix}-panel`} role="tabpanel" aria-labelledby={`${prefix}-tab-${value}`} tabIndex={0} onScroll={event => { scrollPositions.current[value] = event.currentTarget.scrollTop; }}>{children}</div>;
}
