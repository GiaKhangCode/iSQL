import { apiFetch } from "@/shared/api/apiClient";
import { Link, useInRouterContext } from "react-router-dom";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import { useEffect, useId, useLayoutEffect, useRef, useState, useSyncExternalStore, type ReactNode } from "react";
import { Bell, Check, Info, MailOpen, Star, Trash2, TriangleAlert, X } from "lucide-react";
import { useLanguage } from "@/shared/context/LanguageContext";
import { toastHistory, type ServerNotification } from "@/shared/ui/toastHistory";
import "@/shared/ui/ToastHistoryBell.css";

function NotificationLink({ to, className, onClick, children }: { to: string; className?: string; onClick?: () => void; children: ReactNode }) {
  const inRouter = useInRouterContext();
  if (inRouter) {
    return <Link to={to} className={className} onClick={onClick}>{children}</Link>;
  }
  return <a href={to} className={className} onClick={onClick}>{children}</a>;
}

export function ToastHistoryBell({ userId, sharedNotifications }: { userId: string; sharedNotifications?: boolean }) {
  const { t, locale } = useLanguage("common");
  const list = useSyncExternalStore(toastHistory.subscribe, toastHistory.snapshot, toastHistory.snapshot);
  const [open, setOpen] = useState(false), [ring, setRing] = useState(0);
  const [tab, setTab] = useState<"all" | "starred">("all");

  useEffect(() => {
    if (!userId) return;
    let active = true;
    const load = () =>
      apiFetch<ServerNotification[]>("/api/notifications")
        .then(data => {
          if (active && Array.isArray(data)) {
            toastHistory.syncServer(data);
          }
        })
        .catch(() => {});
    void load();
    const timer = setInterval(load, 30000);
    return () => {
      active = false;
      clearInterval(timer);
    };
  }, [userId]);

  const unread = list.filter(item => !item.read).length;
  const visible = tab === "starred" ? list.filter(item => item.starred) : list;
  const anchor = useRef<HTMLDivElement>(null), trigger = useRef<HTMLButtonElement>(null);
  const previous = useRef(list);
  const id = useId();

  useLayoutEffect(() => {
    toastHistory.setOwner(userId);
    previous.current = toastHistory.snapshot();
    setOpen(false);
    setTab("all");
    return () => toastHistory.setOwner("");
  }, [userId]);

  useEffect(() => {
    if (list[0] && !previous.current.some(item => item.id === list[0].id)) setRing(value => value + 1);
    previous.current = list;
  }, [list]);

  useEffect(() => {
    if (!open) return;
    const outside = (event: PointerEvent) => {
      if (!anchor.current?.contains(event.target as Node)) setOpen(false);
    };
    const escape = (event: KeyboardEvent) => {
      if (event.key === "Escape") {
        setOpen(false);
        trigger.current?.focus();
      }
    };
    document.addEventListener("pointerdown", outside);
    document.addEventListener("keydown", escape);
    return () => {
      document.removeEventListener("pointerdown", outside);
      document.removeEventListener("keydown", escape);
    };
  }, [open]);

  return (
    <div className="toast-history-anchor" ref={anchor}>
      <button
        ref={trigger}
        type="button"
        className="toast-history-trigger"
        aria-label={`${t("Notification history")} (${unread})`}
        title={t("Notification history")}
        aria-expanded={open}
        aria-controls={id}
        onClick={() => {
          if (!open) setRing(value => value + 1);
          setOpen(value => !value);
        }}
      >
        <Bell key={ring} size={21} className={ring ? "toast-history-ring" : ""} aria-hidden="true" />
        {unread > 0 && <span className="toast-history-count" aria-hidden="true">{unread > 99 ? "99+" : unread}</span>}
      </button>
      {open && (
        <section id={id} className="toast-history-panel" aria-label={t("Notification history")}>
          <header>
            <h2><TranslatedText>{t("Notification history")}</TranslatedText></h2>
            {list.length > 0 && (
              <button type="button" className="text-button" onClick={() => toastHistory.clear()}>
                <TranslatedText>{t("Clear history")}</TranslatedText>
              </button>
            )}
            <button
              type="button"
              className="icon-button"
              aria-label={t("Close")}
              onClick={() => {
                setOpen(false);
                trigger.current?.focus();
              }}
            >
              <X size={16} />
            </button>
          </header>
          <div className="toast-history-tabs" role="tablist" aria-label={t("Notifications")}>
            <button type="button" role="tab" aria-selected={tab === "all"} onClick={() => setTab("all")}>
              <TranslatedText>{t("All notifications")}</TranslatedText>
            </button>
            <button type="button" role="tab" aria-selected={tab === "starred"} onClick={() => setTab("starred")}>
              <Star size={13} />
              <TranslatedText>{t("Important notifications")}</TranslatedText> ({list.filter(item => item.starred).length})
            </button>
          </div>
          <div className="toast-history-list" role="tabpanel">
            {!visible.length && (
              <p className="toast-history-empty">
                <TranslatedText>{t(tab === "starred" ? "No important notifications yet." : "Expired notifications will appear here.")}</TranslatedText>
              </p>
            )}
            {visible.map(item => (
              <article key={item.id} className={`toast-history-item is-${item.variant}${item.read ? " is-read" : " is-unread"}`}>
                <span className="toast-history-icon" aria-hidden="true">
                  {item.variant === "error" ? <TriangleAlert size={17} /> : item.variant === "success" ? <Check size={17} /> : <Info size={17} />}
                </span>
                <div>
                  {item.path ? (
                    <NotificationLink
                      to={item.path}
                      className="toast-history-item-link"
                      onClick={() => {
                        setOpen(false);
                        if (!item.read) toastHistory.markRead(item.id);
                      }}
                    >
                      <h3><TranslatedText>{t(item.title)}</TranslatedText></h3>
                      {item.description && <p>{item.description}</p>}
                    </NotificationLink>
                  ) : (
                    <>
                      <h3><TranslatedText>{t(item.title)}</TranslatedText></h3>
                      {item.description && <p>{item.description}</p>}
                    </>
                  )}
                  <time dateTime={item.createdAt}>{new Date(item.createdAt).toLocaleString(locale)}</time>
                </div>
                <div className="toast-history-item-actions">
                  <button
                    type="button"
                    className="icon-button"
                    disabled={item.read === true}
                    aria-label={t("Mark as read")}
                    title={t("Mark as read")}
                    onClick={() => toastHistory.markRead(item.id)}
                  >
                    <MailOpen size={14} />
                  </button>
                  <button
                    type="button"
                    className="icon-button"
                    aria-pressed={item.starred === true}
                    aria-label={t(item.starred ? "Remove important mark" : "Mark important")}
                    title={t(item.starred ? "Remove important mark" : "Mark important")}
                    onClick={() => toastHistory.toggleStar(item.id)}
                  >
                    <Star size={14} fill={item.starred ? "currentColor" : "none"} />
                  </button>
                  <button
                    type="button"
                    className="icon-button"
                    aria-label={t("Delete notification {value0}", { value0: t(item.title) })}
                    title={t("Delete")}
                    onClick={() => toastHistory.remove(item.id)}
                  >
                    <Trash2 size={14} />
                  </button>
                </div>
              </article>
            ))}
          </div>
        </section>
      )}
    </div>
  );
}
