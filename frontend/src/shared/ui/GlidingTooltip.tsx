// Lucian UX/UI Prompt #076 · MIT License (c) 2026 Seokwoooo · https://lucianlabs.dev/uxui-prompt/#076
import { useEffect, useId, useLayoutEffect, useRef, useState, type AriaRole, type CSSProperties, type ReactNode } from "react";
import { createPortal } from "react-dom";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import "@/shared/ui/GlidingTooltip.css";

/** Put data-tooltip and optional data-shortcut on controls inside this group. */
export function GlidingTooltipGroup({ children, className = "", label, side = "below", as: Root = "div", role = "toolbar", multiline = false }: {
  children: ReactNode; className?: string; label: string; side?: "below" | "above";
  as?: "div" | "nav"; role?: AriaRole; multiline?: boolean;
}) {
  const id = useId();
  const root = useRef<HTMLDivElement>(null);
  const tip = useRef<HTMLDivElement>(null);
  const current = useRef<HTMLElement | null>(null);
  const candidate = useRef<HTMLElement | null>(null);
  const visible = useRef(false);
  const warm = useRef(false);
  const timers = useRef<{ show?: number; hide?: number; cool?: number }>({});
  const [content, setContent] = useState({ label: "", shortcut: "", type: "", shown: false, glide: false });
  const [position, setPosition] = useState({ x: 0, y: 0, arrow: 20, below: true });
  const [host, setHost] = useState<HTMLElement | null>(null);

  const describe = (button: HTMLElement | null, add: boolean) => {
    if (!button) return;
    const ids = (button.getAttribute("aria-describedby") ?? "").split(/\s+/).filter(value => value && value !== id);
    if (add) ids.push(id);
    if (ids.length) button.setAttribute("aria-describedby", ids.join(" "));
    else button.removeAttribute("aria-describedby");
  };
  const hide = (immediate = false) => {
    window.clearTimeout(timers.current.show);
    window.clearTimeout(timers.current.hide);
    candidate.current = null;
    const go = () => {
      describe(current.current, false);
      current.current = null;
      visible.current = false;
      setContent(previous => ({ ...previous, shown: false }));
      window.clearTimeout(timers.current.cool);
      timers.current.cool = window.setTimeout(() => { warm.current = false; }, 300);
    };
    if (immediate) go();
    else timers.current.hide = window.setTimeout(go, 80);
  };
  const show = (button: HTMLElement, keyboard = false) => {
    if (button.matches(":disabled") || button.getAttribute("aria-disabled") === "true") return;
    window.clearTimeout(timers.current.hide);
    window.clearTimeout(timers.current.cool);
    if (candidate.current === button || current.current === button) return;
    window.clearTimeout(timers.current.show);
    candidate.current = button;
    const go = () => {
      if (!button.isConnected) return;
      describe(current.current, false);
      describe(button, true);
      current.current = button;
      candidate.current = null;
      setContent({ label: button.dataset.tooltip ?? "", shortcut: button.dataset.shortcut ?? "", type: button.dataset.tooltipType ?? "", shown: true, glide: visible.current });
      visible.current = true;
      warm.current = true;
    };
    if (keyboard || warm.current || button.dataset.tooltipType === "error") go();
    else timers.current.show = window.setTimeout(go, 450);
  };
  const control = (target: EventTarget | null) => target instanceof Element ? target.closest<HTMLElement>("[data-tooltip]") : null;

  useLayoutEffect(() => {
    // Stay in the modal's top layer when the expanded editor uses <dialog>.
    setHost(root.current?.closest("dialog") ?? document.body);
  }, []);
  useLayoutEffect(() => {
    if (!content.shown || !tip.current || !root.current || !current.current) return;
    const place = () => {
      const button = current.current;
      if (!button || !tip.current || !root.current) return;
      const bounds = button.getBoundingClientRect();
      const group = root.current.getBoundingClientRect();
      const width = tip.current.offsetWidth;
      const height = tip.current.offsetHeight;
      const viewportWidth = document.documentElement.clientWidth || window.innerWidth;
      const viewportHeight = window.innerHeight;
      const center = bounds.left + bounds.width / 2;
      const min = Math.max(8, group.left + 8);
      const max = Math.min(viewportWidth - 8, group.right - 8);
      const x = Math.max(8, Math.min(viewportWidth - width - 8, Math.max(min, Math.min(max - width, center - width / 2))));
      let below = (button.dataset.tooltipSide ?? side) === "below";
      if (below && bounds.bottom + height + 8 > viewportHeight - 8) below = false;
      else if (!below && bounds.top - height - 8 < 8) below = true;
      const y = Math.max(8, Math.min(viewportHeight - height - 8, below ? bounds.bottom + 8 : bounds.top - height - 8));
      const next = { x, y, arrow: Math.max(8, Math.min(width - 8, center - x)), below };
      setPosition(previous => Object.keys(next).every(key => previous[key as keyof typeof next] === next[key as keyof typeof next]) ? previous : next);
    };
    place();
    window.addEventListener("resize", place);
    window.addEventListener("scroll", place, true);
    const observer = new ResizeObserver(place);
    observer.observe(tip.current);
    observer.observe(root.current);
    return () => { window.removeEventListener("resize", place); window.removeEventListener("scroll", place, true); observer.disconnect(); };
  }, [content, host, side]);
  useLayoutEffect(() => {
    const button = current.current;
    if (button && visible.current) setContent(previous => previous.label === button.dataset.tooltip && previous.shortcut === (button.dataset.shortcut ?? "") ? previous : { ...previous, label: button.dataset.tooltip ?? "", shortcut: button.dataset.shortcut ?? "" });
  }, [children]);
  useEffect(() => () => {
    Object.values(timers.current).forEach(timer => window.clearTimeout(timer));
    describe(current.current, false);
  }, [id]);

  return <Root ref={root} className={`u076 ${className}`} role={role} aria-label={label}
    onPointerOver={event => {
      if (event.pointerType !== "mouse") return;
      const button = control(event.target);
      if (button && button !== control(event.relatedTarget)) show(button);
    }} onPointerOut={event => {
      if (event.pointerType !== "mouse") return;
      const button = control(event.target);
      if (button && button !== control(event.relatedTarget) && !(document.activeElement === button && button.matches(":focus-visible"))) hide();
    }} onFocus={event => { const button = control(event.target); if (button?.matches(":focus-visible")) show(button, true); }}
    onBlur={event => { if (control(event.target)) hide(); }}
    onClick={() => hide(true)}
    onKeyDownCapture={event => {
      if (event.key === "Escape" && (visible.current || candidate.current)) { event.preventDefault(); event.stopPropagation(); hide(true); }
    }}>
    {children}
    {host && createPortal(<div className="u076-layer">
      <div ref={tip} id={id} role="tooltip" aria-hidden={!content.shown}
        className={`u076-tip ${position.below ? "below" : "above"}${content.shown ? " on" : ""}${content.glide ? " glide" : ""}${multiline ? " multiline" : ""}${content.type ? ` is-${content.type}` : ""}`}
        style={{ "--x": `${position.x}px`, "--y": `${position.y}px`, "--ax": `${position.arrow}px` } as CSSProperties}>
        <div className="u076-box"><span><TranslatedText>{content.label}</TranslatedText></span><kbd>{content.shortcut}</kbd></div><i className="u076-arr" />
      </div>
    </div>, host)}
  </Root>;
}
