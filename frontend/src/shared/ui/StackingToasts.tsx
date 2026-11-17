// Lucian UX/UI Prompt #070 · MIT License (c) 2026 Seokwoooo · https://lucianlabs.dev/uxui-prompt/#070
import { useEffect, useLayoutEffect, useRef, useState, useSyncExternalStore, type CSSProperties, type ReactNode } from "react";
import { createPortal } from "react-dom";
import { Check, MessageSquare, TriangleAlert, X } from "lucide-react";
import { useLanguage } from "@/shared/context/LanguageContext";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import "@/shared/ui/StackingToasts.css";
import { toastHistory } from "@/shared/ui/toastHistory";

export type ToastOptions = {
  title: string;
  description?: string;
  variant?: "success" | "error" | "info" | "loading";
  /** Milliseconds at the front of the stack. Zero keeps the toast until dismissed. */
  duration?: number;
  action?: { label: string; onClick: () => void };
};
type Toast = ToastOptions & { id: string; revision: number; leaving?: number; historyOwner:string };
let items: Toast[] = [];
let sequence = 0;
const listeners = new Set<() => void>();
const snapshot = () => items;
const subscribe = (listener: () => void) => { listeners.add(listener); return () => { listeners.delete(listener); }; };
function commit(next: Toast[]) { items = next; listeners.forEach(listener => listener()); }

/** Mount ToastProvider once, then call toast.show/update/dismiss from any page. */
export const toast = {
  show(options: ToastOptions) {
    const id = `toast-${Date.now()}-${++sequence}`;
    commit([{ ...options, id, revision: 0, historyOwner:toastHistory.currentOwner() }, ...items]);
    return id;
  },
  update(id: string, options: Partial<ToastOptions>) {
    commit(items.map(item => item.id === id && item.leaving === undefined ? { ...item, ...options, revision: item.revision + 1 } : item));
  },
  dismiss(id: string, direction = 0) {
    if (!items.some(item => item.id === id && item.leaving === undefined)) return;
    const item=items.find(item=>item.id===id)!;
    if(item.historyOwner===toastHistory.currentOwner())toastHistory.add(item);
    commit(items.map(item => item.id === id ? { ...item, leaving: direction } : item));
    window.setTimeout(() => commit(items.filter(item => item.id !== id)), 450);
  },
};

function ToastCard({ item, index, y, expanded, paused, onHeight, onDismiss }: {
  item: Toast; index: number; y: number; expanded: boolean; paused: boolean;
  onHeight: (id: string, height: number) => void; onDismiss: (id: string, direction?: number) => void;
}) {
  const { t } = useLanguage();
  const element = useRef<HTMLDivElement>(null);
  const bar = useRef<HTMLSpanElement>(null);
  const interaction = useRef({ paused, dragging: false, start: 0, dx: 0, moved: false });
  interaction.current.paused = paused || index > 0 || item.leaving !== undefined;
  const [dragX, setDragX] = useState<number | null>(null);
  const variant = item.variant ?? "info";
  const back = !expanded && index > 0;
  const duration = item.duration ?? 4500;

  useLayoutEffect(() => {
    const node = element.current;
    if (!node) return;
    node.inert = back || item.leaving !== undefined;
  }, [back, item.leaving]);
  useLayoutEffect(() => {
    const node = element.current;
    if (!node) return;
    const measure = () => onHeight(item.id, node.offsetHeight || 68);
    measure();
    const observer = new ResizeObserver(measure);
    observer.observe(node);
    return () => observer.disconnect();
  }, [item.id, onHeight]);
  useEffect(() => {
    let left = duration, previous: number | undefined, frame: number;
    if (bar.current) bar.current.style.setProperty("--left", "1");
    if (variant === "loading" || duration <= 0) return;
    const tick = (now: number) => {
      const elapsed = previous === undefined ? 0 : now - previous;
      previous = now;
      if (!interaction.current.paused && !interaction.current.dragging && !document.hidden) {
        left -= elapsed;
        bar.current?.style.setProperty("--left", String(Math.max(0, left / duration)));
        if (left <= 0) { toast.dismiss(item.id); return; }
      }
      frame = requestAnimationFrame(tick);
    };
    frame = requestAnimationFrame(tick);
    const visibilityChanged = () => { previous = undefined; };
    document.addEventListener("visibilitychange", visibilityChanged);
    return () => { cancelAnimationFrame(frame); document.removeEventListener("visibilitychange", visibilityChanged); };
  }, [item.id, item.revision, variant, duration]);

  const style = {
    "--y": `${y}px`, "--s": expanded ? 1 : Math.max(0.1, 1 - index * 0.06),
    "--o": dragX !== null ? Math.max(0.2, 1 - Math.abs(dragX) / 260) : !expanded && index > 2 ? 0 : 1,
    "--dx": `${dragX ?? 0}px`, "--dir": item.leaving || 1,
    zIndex: item.leaving !== undefined ? 51 : 50 - index,
  } as CSSProperties;
  const endDrag = (cancelled: boolean) => {
    const drag = interaction.current;
    if (!drag.dragging) return;
    drag.dragging = false;
    if (!cancelled && Math.abs(drag.dx) > 90) onDismiss(item.id, drag.dx > 0 ? 1 : -1);
    setDragX(null);
  };
  return <div ref={element} data-toast-id={item.id} data-variant={variant}
    className={`u070-t${back ? " back" : ""}${dragX !== null ? " drag" : ""}${item.leaving !== undefined ? ` leave${item.leaving ? " side" : ""}` : ""}`}
    style={style} role="group" aria-label={t(item.title)} aria-busy={variant === "loading" || undefined}
    onPointerDown={event => {
      if (event.button !== 0 || (event.target as HTMLElement).closest("button")) return;
      Object.assign(interaction.current, { dragging: true, start: event.clientX, dx: 0, moved: false });
      event.currentTarget.setPointerCapture(event.pointerId);
      setDragX(0);
    }}
    onPointerMove={event => {
      const drag = interaction.current;
      if (!drag.dragging) return;
      drag.dx = event.clientX - drag.start;
      if (Math.abs(drag.dx) > 5) drag.moved = true;
      setDragX(drag.dx);
    }} onPointerUp={() => endDrag(false)} onPointerCancel={() => endDrag(true)}
    onClick={event => { if (interaction.current.moved) { event.stopPropagation(); interaction.current.moved = false; } }}>
    <span className="u070-ic" aria-hidden="true">{variant === "loading" ? <i /> : variant === "success" ? <Check /> : variant === "error" ? <TriangleAlert /> : <MessageSquare />}</span>
    <span className="u070-tx"><b><TranslatedText>{t(item.title)}</TranslatedText></b>{item.description && <small title={item.description}>{item.description}</small>}</span>
    {item.action && <button type="button" className="u070-act" onClick={() => { onDismiss(item.id); item.action?.onClick(); }}><TranslatedText>{t(item.action.label)}</TranslatedText></button>}
    <button type="button" className="u070-x" aria-label={t("Dismiss notification")} onClick={() => onDismiss(item.id)}><X aria-hidden="true" /></button>
    {variant !== "loading" && duration > 0 && <span ref={bar} className="u070-bar" aria-hidden="true" />}
  </div>;
}

export function ToastProvider({ children }: { children: ReactNode }) {
  const list = useSyncExternalStore(subscribe, snapshot, snapshot);
  const { t } = useLanguage();
  const [hovered, setHovered] = useState(false);
  const [focused, setFocused] = useState(false);
  const [toggled, setToggled] = useState(false);
  const expanded = hovered || focused || toggled;
  const [heights, setHeights] = useState<Record<string, number>>({});
  const [announcement, setAnnouncement] = useState<Toast | null>(null);
  const [host, setHost] = useState<HTMLElement | null>(() => typeof document === "undefined" ? null : document.body);
  const region = useRef<HTMLDivElement>(null);
  const measure = useRef((id: string, height: number) => setHeights(previous => previous[id] === height ? previous : { ...previous, [id]: height })).current;
  const active = list.filter(item => item.leaving === undefined);
  const newest = active[0];
  useEffect(() => { if (newest) setAnnouncement(newest); }, [newest]);
  useEffect(() => {
    // Saving from "Save and exit" must remain visible above its modal backdrop.
    const syncHost = () => {
      const dialogs = document.querySelectorAll<HTMLDialogElement>("dialog[open]");
      const next = dialogs.item(dialogs.length - 1) ?? document.body;
      setHost(previous => previous === next ? previous : next);
    };
    syncHost();
    const observer = new window.MutationObserver(syncHost);
    observer.observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ["open"] });
    return () => observer.disconnect();
  }, []);
  useEffect(() => { if (!list.length) { setHovered(false); setFocused(false); setToggled(false); setHeights({}); } }, [list.length]);
  useLayoutEffect(() => {
    if (expanded && region.current) region.current.scrollTop = region.current.scrollHeight;
  }, [expanded, list.length]);
  const dismiss = (id: string, direction = 0) => {
    const focusedCard = (document.activeElement as HTMLElement | null)?.closest("[data-toast-id]");
    if (focusedCard?.getAttribute("data-toast-id") === id) region.current?.focus({ preventScroll: true });
    toast.dismiss(id, direction);
  };
  let offset = 0;
  const positions = new Map<string, { index: number; y: number }>();
  active.forEach((item, index) => {
    positions.set(item.id, { index, y: expanded ? -offset : -index * 14 });
    offset += (heights[item.id] ?? 68) + 8;
  });
  const height = expanded ? Math.max(68, offset - 8) : (newest ? (heights[newest.id] ?? 68) + Math.min(active.length - 1, 2) * 14 : 0);
  return <>{children}{host && createPortal(
    <div className={`u070${expanded ? " is-expanded" : ""}`}>
      <div className="u070-live" role="status" aria-atomic="true"><TranslatedText>{announcement?.variant !== "error" && announcement && t(announcement.title)}</TranslatedText></div>
      <div className="u070-live" role="alert" aria-atomic="true"><TranslatedText>{announcement?.variant === "error" && t(announcement.title)}</TranslatedText></div>
      {list.length > 0 && <div ref={region} className="u070-zone" role="region" aria-label={t("Notifications")} tabIndex={-1}
        onPointerEnter={event => { if (event.pointerType === "mouse") setHovered(true); }}
        onPointerLeave={event => { if (event.pointerType === "mouse") setHovered(false); }}
        onFocus={() => setFocused(true)} onBlur={event => { if (!event.currentTarget.contains(event.relatedTarget)) { setFocused(false); setToggled(false); } }}
        onClick={event => { if (!(event.target as HTMLElement).closest("button") && active.length > 1) setToggled(value => !value); }}
        onKeyDown={event => {
          if (event.key !== "Escape") return;
          const id = (event.target as HTMLElement).closest("[data-toast-id]")?.getAttribute("data-toast-id") ?? newest?.id;
          if (id) { event.stopPropagation(); event.preventDefault(); dismiss(id); }
        }}>
        <div className="u070-stack" style={{ height }}>
          {list.map(item => {
            const position = positions.get(item.id) ?? { index: 0, y: 0 };
            return <ToastCard key={item.id} item={item} {...position} expanded={expanded} paused={expanded} onHeight={measure} onDismiss={dismiss} />;
          })}
        </div>
      </div>}
    </div>, host)}</>;
}
