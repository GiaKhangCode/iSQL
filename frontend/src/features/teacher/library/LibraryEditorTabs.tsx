import { useLayoutEffect, useRef, type ReactNode } from "react";

export function LibraryEditorTabs<T extends string>({ tabs, value, onChange, label, prefix, panelId, secondary = false, disabled = false }: {
  tabs: { id: T; label: ReactNode }[]; value: T; onChange: (value: T) => void;
  label: string; prefix: string; panelId: string; secondary?: boolean; disabled?: boolean;
}) {
  const bar = useRef<HTMLDivElement>(null);
  const indicator = useRef<HTMLSpanElement>(null);
  useLayoutEffect(() => {
    const element = bar.current;
    if (!element) return;
    const sync = () => {
      const selected = element.querySelector<HTMLButtonElement>('[aria-selected="true"]');
      if (!selected || !indicator.current) return;
      indicator.current.style.width = `${selected.offsetWidth}px`;
      indicator.current.style.transform = `translateX(${selected.offsetLeft}px)`;
    };
    sync();
    const observer = new ResizeObserver(sync);
    observer.observe(element);
    element.querySelectorAll("button").forEach(button => observer.observe(button));
    return () => observer.disconnect();
  });
  return <div ref={bar} className={secondary ? "library-structure-tabs" : "library-part-tabs"} role="tablist" aria-label={label}>
    <span ref={indicator} className="library-tab-indicator" aria-hidden="true" />
    {tabs.map((tab, index) => <button key={tab.id} id={`${prefix}-${tab.id}`} type="button" role="tab"
      aria-selected={value === tab.id} aria-controls={panelId} tabIndex={value === tab.id ? 0 : -1} disabled={disabled}
      onClick={() => onChange(tab.id)} onKeyDown={event => {
        const next = event.key === "ArrowRight" ? (index + 1) % tabs.length : event.key === "ArrowLeft" ? (index + tabs.length - 1) % tabs.length : event.key === "Home" ? 0 : event.key === "End" ? tabs.length - 1 : null;
        if (next === null) return;
        event.preventDefault();
        onChange(tabs[next].id);
        const button = bar.current?.querySelectorAll<HTMLButtonElement>('[role="tab"]')[next];
        button?.focus({ preventScroll: true });
        button?.scrollIntoView?.({ block: "nearest", inline: "nearest" });
      }}>{tab.label}</button>)}
  </div>;
}

export function LibraryEditorPanel({ motionKey, order, labelledBy, children }: {
  motionKey: string; order: number; labelledBy: string; children: ReactNode;
}) {
  const panel = useRef<HTMLDivElement>(null);
  const previous = useRef({ key: motionKey, order });
  useLayoutEffect(() => {
    const old = previous.current;
    previous.current = { key: motionKey, order };
    if (old.key === motionKey || window.matchMedia?.("(prefers-reduced-motion: reduce)").matches) return;
    const animation = panel.current?.animate?.([
      { opacity: 0.4, transform: `translateX(${order >= old.order ? 14 : -14}px)` },
      { opacity: 1, transform: "translateX(0)" },
    ], { duration: 220, easing: "cubic-bezier(.22,.61,.36,1)" });
    return () => animation?.cancel();
  }, [motionKey, order]);
  return <div ref={panel} id="library-content-panel" className="library-content-panel" role="tabpanel" aria-labelledby={labelledBy}>{children}</div>;
}
