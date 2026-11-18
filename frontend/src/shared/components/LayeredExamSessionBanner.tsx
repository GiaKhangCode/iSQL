// Lucian UX/UI Prompt #049 · MIT License (c) 2026 Seokwoooo · https://lucianlabs.dev/uxui-prompt/#049
import { useId, useRef, useState, type ReactNode, type PointerEvent } from "react";
import { contestBannerClass, resolveContestBannerUrl } from "@/shared/lib/contest/contestBanner";
import "@/shared/components/LayeredContestBanner.css";

export function LayeredContestBanner({ id, bannerUrl, className, label, onOpen, sentinel = false, children }: {
  id: string;
  bannerUrl?: string | null;
  className: string;
  label: string;
  onOpen?: () => void;
  sentinel?: boolean;
  children: ReactNode;
}) {
  const gradientId = `contest-sky-${useId().replace(/:/g, "")}`;
  const [open, setOpen] = useState(false);
  const quietFocus = useRef(false);
  const imageUrl = resolveContestBannerUrl(bannerUrl);
  const Tag = onOpen ? "button" : "header";

  function move(event: PointerEvent<HTMLElement>) {
    if (event.pointerType !== "mouse" || window.matchMedia("(prefers-reduced-motion: reduce)").matches) return;
    const rect = event.currentTarget.getBoundingClientRect();
    event.currentTarget.style.setProperty("--px", ((event.clientX - rect.left) / rect.width * 2 - 1).toFixed(3));
    event.currentTarget.style.setProperty("--py", ((event.clientY - rect.top) / rect.height * 2 - 1).toFixed(3));
  }

  return <Tag className={`u049 ${className} ${contestBannerClass(id)}${open ? " is-open" : ""}${imageUrl ? " has-image" : ""}`}
    type={onOpen ? "button" : undefined} tabIndex={sentinel ? -1 : 0} aria-hidden={sentinel || undefined} aria-label={label}
    onPointerEnter={(event) => { if (event.pointerType === "mouse") setOpen(true); }} onPointerMove={move}
    onPointerLeave={(event) => {
      if (event.pointerType !== "mouse") return;
      event.currentTarget.style.setProperty("--px", "0");
      event.currentTarget.style.setProperty("--py", "0");
      if (!event.currentTarget.contains(document.activeElement)) setOpen(false);
    }}
    onFocus={() => { if (!quietFocus.current) setOpen(true); }}
    onBlur={(event) => { if (!event.currentTarget.contains(event.relatedTarget) && !event.currentTarget.matches(":hover")) setOpen(false); }}
    onClick={() => { if (onOpen) onOpen(); else setOpen((value) => !value); }}
    onKeyDown={(event) => {
      if (event.key === "Escape") {
        quietFocus.current = true;
        event.currentTarget.focus();
        quietFocus.current = false;
        setOpen(false);
      } else if (!onOpen && (event.key === "Enter" || event.key === " ")) {
        event.preventDefault();
        setOpen((value) => !value);
      }
    }}>
    <div className="u049-art" aria-hidden="true">
      {imageUrl ? <div className="u049-image u049-l1" style={{ backgroundImage: `url(${JSON.stringify(imageUrl)})` }} /> : <>
        <svg className="u049-l1" viewBox="0 0 360 500" preserveAspectRatio="xMidYMid slice"><defs><linearGradient id={gradientId} x1="0" y1="0" x2="0" y2="1"><stop offset="0" className="u049-sky-start" /><stop offset=".55" className="u049-sky-mid" /><stop offset="1" className="u049-sky-end" /></linearGradient></defs><rect width="360" height="500" fill={`url(#${gradientId})`} /><circle cx="236" cy="210" r="62" className="u049-sun" /><circle cx="236" cy="210" r="96" className="u049-sun" opacity=".18" /></svg>
        <svg className="u049-l2" viewBox="0 0 360 500" preserveAspectRatio="xMidYMid slice"><path d="M-10 300 C 60 250 110 270 160 245 S 260 200 370 260 V500 H-10Z" className="u049-hill-back" /><path d="M-10 330 C 80 300 120 320 190 300 S 300 290 370 310 V500 H-10Z" className="u049-hill-mid" opacity=".9" /></svg>
        <svg className="u049-l3" viewBox="0 0 360 500" preserveAspectRatio="xMidYMid slice"><path d="M-10 370 C 70 330 140 380 210 350 S 320 340 370 360 V500 H-10Z" className="u049-hill-front" /><path d="M40 368 l8 -26 l8 26 M70 360 l10 -34 l10 34" className="u049-trees" /></svg>
      </>}
    </div>
    <div className="u049-shade" aria-hidden="true" />
    {children}
  </Tag>;
}
