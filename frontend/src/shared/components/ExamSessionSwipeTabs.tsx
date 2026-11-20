// Lucian UX/UI Prompt #048 · MIT License (c) 2026 Seokwoooo · https://lucianlabs.dev/uxui-prompt/#048
import { useId, useLayoutEffect, useRef, type ReactNode } from "react";
import "@/shared/components/ExamSessionSwipeTabs.css";

type ExamSessionTab = { id: string; label: ReactNode; count?: number };

export function ExamSessionSwipeTabs({ tabs, value, onChange, label, toolbar, aside, className = "", children }: {
  tabs: ExamSessionTab[];
  value: string;
  onChange: (value: string) => void;
  label: string;
  toolbar?: ReactNode;
  aside?: ReactNode;
  className?: string;
  children: (value: string, active: boolean) => ReactNode;
}) {
  const uid = useId();
  const bar = useRef<HTMLDivElement>(null);
  const pages = useRef<HTMLDivElement>(null);
  const line = useRef<HTMLSpanElement>(null);
  const active = useRef(Math.max(0, tabs.findIndex((tab) => tab.id === value)));
  const change = useRef(onChange);
  change.current = onChange;
  const gesture = useRef<{ x: number; y: number; lastX: number; left: number; velocity: number; dragging: boolean } | null>(null);
  const suppressClick = useRef(false);
  const settleTimer = useRef<number>();
  const reducedMotion = () => window.matchMedia("(prefers-reduced-motion: reduce)").matches;

  function edges() {
    const element = bar.current;
    if (!element) return;
    element.parentElement?.style.setProperty("--fl", element.scrollLeft > 4 ? "1" : "0");
    element.parentElement?.style.setProperty("--fr", element.scrollLeft < element.scrollWidth - element.clientWidth - 4 ? "1" : "0");
  }

  function sync() {
    const element = pages.current;
    const buttons = bar.current?.querySelectorAll<HTMLButtonElement>("[role=tab]");
    if (!element || !buttons?.length || !line.current) return;
    const fraction = Math.max(0, Math.min(tabs.length - 1, element.scrollLeft / (element.clientWidth || 1)));
    const index = Math.floor(fraction), progress = fraction - index;
    const a = buttons[index], b = buttons[Math.min(index + 1, tabs.length - 1)];
    const stretch = reducedMotion() ? 0 : Math.sin(progress * Math.PI);
    const left = a.offsetLeft + (b.offsetLeft - a.offsetLeft) * progress + 10 - stretch * 6;
    const width = a.offsetWidth + (b.offsetWidth - a.offsetWidth) * progress - 20 + stretch * 26;
    line.current.style.transform = `translateX(${left.toFixed(1)}px)`;
    line.current.style.width = `${width.toFixed(1)}px`;
    const next = Math.round(fraction);
    if (next !== active.current) {
      active.current = next;
      change.current(tabs[next].id);
      bar.current?.scrollTo({ left: buttons[next].offsetLeft - (bar.current.clientWidth - buttons[next].offsetWidth) / 2, behavior: reducedMotion() ? "auto" : "smooth" });
    }
  }

  function goTo(index: number, focus = false) {
    const next = Math.max(0, Math.min(tabs.length - 1, index));
    if (focus) bar.current?.querySelectorAll<HTMLButtonElement>("[role=tab]")[next]?.focus({ preventScroll: true });
    pages.current?.scrollTo({ left: next * pages.current.clientWidth, behavior: reducedMotion() ? "auto" : "smooth" });
  }

  function finishGesture() {
    const current = gesture.current;
    gesture.current = null;
    if (!current?.dragging || !pages.current) return;
    const fraction = pages.current.scrollLeft / (pages.current.clientWidth || 1);
    goTo(Math.abs(current.velocity) > 4 ? (current.velocity < 0 ? Math.ceil(fraction) : Math.floor(fraction)) : Math.round(fraction));
    window.clearTimeout(settleTimer.current);
    settleTimer.current = window.setTimeout(() => {
      pages.current?.classList.remove("drag");
      suppressClick.current = false;
    }, reducedMotion() ? 0 : 700);
  }

  useLayoutEffect(() => {
    const element = pages.current;
    if (!element) return;
    let previousWidth = -1;
    const resize = () => {
      if (element.clientWidth !== previousWidth) {
        previousWidth = element.clientWidth;
        element.scrollLeft = active.current * previousWidth;
      }
      sync();
      edges();
    };
    resize();
    const observer = new ResizeObserver(resize);
    observer.observe(element);
    if (bar.current) observer.observe(bar.current);
    return () => { observer.disconnect(); window.clearTimeout(settleTimer.current); };
  }, [tabs.map((tab) => `${tab.id}:${tab.count}`).join("|")]);

  // Translated labels can change width without changing the viewport.
  useLayoutEffect(() => { sync(); edges(); });

  return <div className={`u048 exam-session-list-region ${className}`}>
    <div className="exam-session-toolbar">
      <div className="u048-tw"><div className="u048-tabs" ref={bar} role="tablist" aria-label={label} onScroll={edges}>
        {tabs.map((tab, index) => <button key={tab.id} type="button" className="u048-tab" role="tab"
          id={`${uid}-t${index}`} aria-controls={`${uid}-p${index}`} aria-selected={value === tab.id} tabIndex={value === tab.id ? 0 : -1}
          onClick={() => goTo(index)} onKeyDown={(event) => {
            const next = event.key === "ArrowRight" ? index + 1 : event.key === "ArrowLeft" ? index - 1 : event.key === "Home" ? 0 : event.key === "End" ? tabs.length - 1 : null;
            if (next !== null) { event.preventDefault(); goTo(next, true); }
          }}>{tab.label}{tab.count !== undefined && <span className="u048-count">{tab.count}</span>}</button>)}
        <span ref={line} className="u048-line" aria-hidden="true" />
      </div></div>
      {toolbar}
    </div>
    <div className="exam-session-columns">
      <div ref={pages} className="u048-pages" onScroll={sync}
        onClickCapture={(event) => { if (suppressClick.current) { event.preventDefault(); event.stopPropagation(); suppressClick.current = false; } }}
        onPointerDown={(event) => {
          if (event.pointerType !== "mouse" || event.button !== 0 || (event.target as HTMLElement).closest("button, a, input")) return;
          window.clearTimeout(settleTimer.current);
          suppressClick.current = false;
          gesture.current = { x: event.clientX, y: event.clientY, lastX: event.clientX, left: event.currentTarget.scrollLeft, velocity: 0, dragging: false };
        }}
        onPointerMove={(event) => {
          const current = gesture.current;
          if (!current) return;
          const dx = event.clientX - current.x, dy = event.clientY - current.y;
          if (!current.dragging) {
            if (Math.abs(dy) > Math.max(6, Math.abs(dx))) { gesture.current = null; return; }
            if (Math.abs(dx) < 6) return;
            current.dragging = true;
            suppressClick.current = true;
            event.currentTarget.classList.add("drag");
            event.currentTarget.setPointerCapture(event.pointerId);
          }
          event.preventDefault();
          current.velocity = event.clientX - current.lastX;
          current.lastX = event.clientX;
          event.currentTarget.scrollLeft = current.left - dx;
        }}
        onPointerUp={finishGesture} onPointerCancel={finishGesture} onLostPointerCapture={finishGesture}
        onPointerLeave={() => { if (!gesture.current?.dragging) gesture.current = null; }}>
        {tabs.map((tab, index) => <section key={tab.id} className="u048-pg" role="tabpanel" id={`${uid}-p${index}`} aria-labelledby={`${uid}-t${index}`}
          aria-hidden={value !== tab.id} ref={(element) => { if (element) element.inert = value !== tab.id; }}>
          {children(tab.id, value === tab.id)}
        </section>)}
      </div>
      {aside}
    </div>
  </div>;
}
