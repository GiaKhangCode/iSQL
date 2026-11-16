// Clipboard history belongs to the exam/browser session, not a React effect.
// Keep SQL locally; no clipboard contents are transmitted to the server.
export function examClipboard(key: string) {
  let entries: string[] = [];
  try { const saved = JSON.parse(sessionStorage.getItem(key) || "[]"); if (Array.isArray(saved)) entries = saved.filter(item => typeof item === "string"); } catch { /* Storage may be unavailable. */ }
  const normalize = (text: string) => text.replace(/\r\n?/g, "\n");
  return {
    remember(text: string) {
      if (!text || text.length > 200000) return;
      const value = normalize(text);
      entries = [...entries.filter(item => item !== value), value].slice(-100);
      while (entries.join("").length > 500000) entries.shift();
      try { sessionStorage.setItem(key, JSON.stringify(entries)); } catch { /* In-memory history remains usable. */ }
    },
    contains: (text: string) => Boolean(text) && entries.includes(normalize(text)),
    clear() { entries = []; try {sessionStorage.removeItem(key);} catch { /* No storage. */ } },
  };
}

export function monitorExamInteractions(clipboard: ReturnType<typeof examClipboard>,
  violation: (kind: "focus_lost" | "external_paste") => void,
  audit: (kind: "focus_returned" | "clipboard_panel", durationMs: number) => void) {
  let awayAt: number | null = null;
  let clipboardUntil = 0;
  let clipboardBlur = false;
  let clipboardBlurAt = 0;
  let explicitClipboard = false;
  let timer: ReturnType<typeof setTimeout> | undefined;
  const cancel = () => {clearTimeout(timer);timer = undefined;};
  const lose = () => {
    if (awayAt == null) {awayAt = clipboardBlur ? clipboardBlurAt : Date.now();violation("focus_lost");}
    clipboardBlur = false;clipboardUntil = 0;explicitClipboard = false;
  };
  const blur = () => {
    if (Date.now() < clipboardUntil && !document.hidden) {
      clipboardBlur = true;
      clipboardBlurAt = Date.now();
      cancel();timer = setTimeout(lose, 30000);
    } else lose();
  };
  const resume = () => {
    if (document.hidden) return;
    cancel();
    if (clipboardBlur) {
      // Windows can consume V before the page receives it. A Meta-only opening
      // gets a short paste window after focus returns, not an immediate fault.
      // Other Windows shortcuts still count if no matching internal paste arrives.
      timer = setTimeout(() => {
        if (!clipboardBlur) return;
        const duration = Math.max(0,Date.now()-clipboardBlurAt);
        if (explicitClipboard) audit("clipboard_panel",duration);
        else {violation("focus_lost");audit("focus_returned",duration);}
        clipboardBlur = false;clipboardUntil = 0;explicitClipboard = false;
      },250);
      return;
    }
    if (awayAt != null) {audit("focus_returned", Math.max(0,Date.now()-awayAt));awayAt = null;}
  };
  const keydown = (event: KeyboardEvent) => {
    if (event.key === "Meta" || event.code === "MetaLeft" || event.code === "MetaRight") {
      clipboardUntil = Date.now()+30000;explicitClipboard = false;
    } else if (event.metaKey && (event.code === "KeyV" || event.key.toLowerCase() === "v")) {
      clipboardUntil = Date.now()+30000;explicitClipboard = true;
    }
    else resume();
  };
  const visibility = () => {if (document.hidden) {cancel();lose();} else resume();};
  const copy = (event: ClipboardEvent) => {
    const target = document.activeElement;
    const selected = target instanceof window.HTMLTextAreaElement || target instanceof window.HTMLInputElement
      ? target.value.slice(target.selectionStart ?? 0,target.selectionEnd ?? 0) : window.getSelection()?.toString() || "";
    const remember = () => clipboard.remember(event.clipboardData?.getData("text/plain") || selected);
    remember();queueMicrotask(remember);
  };
  const paste = (event: ClipboardEvent) => {
    const text = event.clipboardData?.getData("text/plain") || "";
    cancel();
    if (clipboard.contains(text)) {
      if (clipboardBlur) audit("clipboard_panel",Math.max(0,Date.now()-clipboardBlurAt));
    } else {event.preventDefault();event.stopImmediatePropagation();violation("external_paste");}
    clipboardBlur = false;clipboardUntil = 0;explicitClipboard = false;
  };
  const drop = (event: DragEvent) => {event.preventDefault();event.stopImmediatePropagation();violation("external_paste");};
  window.addEventListener("blur",blur);window.addEventListener("focus",resume);
  document.addEventListener("visibilitychange",visibility);
  document.addEventListener("keydown",keydown,true);document.addEventListener("pointerdown",resume,true);
  document.addEventListener("copy",copy);document.addEventListener("cut",copy);
  document.addEventListener("paste",paste,true);document.addEventListener("drop",drop,true);
  return () => {
    cancel();window.removeEventListener("blur",blur);window.removeEventListener("focus",resume);
    document.removeEventListener("visibilitychange",visibility);
    document.removeEventListener("keydown",keydown,true);document.removeEventListener("pointerdown",resume,true);
    document.removeEventListener("copy",copy);document.removeEventListener("cut",copy);
    document.removeEventListener("paste",paste,true);document.removeEventListener("drop",drop,true);
  };
}
