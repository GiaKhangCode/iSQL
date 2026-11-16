import { Table } from "@/shared/ui/Table";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import { useLanguage } from "@/shared/context/LanguageContext";
import { useEffect, useId, useRef, type ReactNode } from "react";
import { X } from "lucide-react";
import type { DataTable } from "@/data/models";
import { LoadingSkeleton, type LoadingSkeletonProps } from "@/shared/ui/LoadingSkeleton";
export function Status({
  value,
  tone: explicitTone,
}: {
  value: string;
  tone?: "neutral" | "warning";
}) {
  const { t } = useLanguage();
  const tone =
    explicitTone ||
    (/Accepted|Solved|Active|Live|Easy|Open/.test(value)
      ? "success"
      : /Wrong|Runtime|Hard|Rejected|Locked|Bị khóa|Bị cấm thi/.test(value)
        ? "danger"
        : /Time Limit|Medium|Upcoming|Partial|Needs review/.test(value)
          ? "warning"
          : /In progress|Scheduled/.test(value)
            ? "accent"
            : "neutral");
  return <span className={"status " + tone}><TranslatedText>{t(value)}</TranslatedText></span>;
}
export function Loading({
  label = "Loading data…",
  ...layout
}: Omit<LoadingSkeletonProps, "label"> & { label?: string }) {
  const { t } = useLanguage();
  return <LoadingSkeleton label={t(label)} {...layout} />;
}
export function Empty({
  title = "No results",
  children,
}: {
  title?: string;
  children?: ReactNode;
}) {
  const { t } = useLanguage();
  return (
    <div className="empty-state">
      <h3><TranslatedText>{t(title)}</TranslatedText></h3>
      {children && <p>{children}</p>}
    </div>
  );
}
export function ErrorState({
  title,
  message,
  onRetry,
}: {
  title: string;
  message: string;
  onRetry?: () => void;
}) {
  const { t: tr } = useLanguage("common");
  const { t } = useLanguage();
  return (
    <div className="empty-state" role="alert">
      <h2><TranslatedText>{title}</TranslatedText></h2>
      <p><TranslatedText>{tr(message)}</TranslatedText></p>
      {onRetry && <button className="button" type="button" onClick={onRetry}><TranslatedText>{t("Try again")}</TranslatedText></button>}
    </div>
  );
}
export function DataGrid({ table }: { table: DataTable }) {
  const { t: tr } = useLanguage("common");
  return (
    <div className="table-scroll" tabIndex={0} aria-label={table.name}>
      <Table className="data-table">
        <caption className="sr-only">{table.name}</caption>
        <thead>
          <tr>
            {table.columns.map((c) => (
              <th key={c}><TranslatedText>{tr(c)}</TranslatedText></th>
            ))}
          </tr>
        </thead>
        <tbody>
          {table.rows.map((r, i) => (
            <tr key={i}>
              {r.map((v, j) => (
                <td key={j}><TranslatedText>{tr(v)}</TranslatedText></td>
              ))}
            </tr>
          ))}
        </tbody>
      </Table>
    </div>
  );
}
export function Dialog({
  title,
  children,
  onClose,
  className = "",
  closeOnBackdrop = true,
  closeOnCancel = true,
  headerActions,
  hideClose = false,
}: {
  title: string;
  children: ReactNode;
  onClose: () => void;
  className?: string;
  closeOnBackdrop?: boolean;
  closeOnCancel?: boolean;
  headerActions?: ReactNode;
  hideClose?: boolean;
}) {
  const { t: tr } = useLanguage("common");
  const dialog = useRef<HTMLDialogElement>(null);
  const isMouseDownOnBackdrop = useRef(false);
  const titleId = useId();
  useEffect(() => {
    const previous = document.activeElement as HTMLElement | null;
    const previousScroll = { x: window.scrollX, y: window.scrollY };
    dialog.current?.showModal();
    return () => {
      previous?.focus({ preventScroll: true });
      window.scrollTo(previousScroll.x, previousScroll.y);
    };
  }, []);
  return (
    <dialog
      ref={dialog}
      className={"dialog " + className}
      aria-labelledby={titleId}
      onCancel={(e) => {
        e.preventDefault();
        if (closeOnCancel) onClose();
      }}
      onMouseDown={(e) => {
        if (closeOnBackdrop && e.target === e.currentTarget) {
          const bounds = e.currentTarget.getBoundingClientRect();
          const isOutside = (
            e.clientX < bounds.left ||
            e.clientX > bounds.right ||
            e.clientY < bounds.top ||
            e.clientY > bounds.bottom
          );
          isMouseDownOnBackdrop.current = isOutside;
        } else {
          isMouseDownOnBackdrop.current = false;
        }
      }}
      onMouseUp={(e) => {
        if (e.target !== e.currentTarget) {
          isMouseDownOnBackdrop.current = false;
        }
      }}
      onClick={(e) => {
        if (closeOnBackdrop && isMouseDownOnBackdrop.current && e.target === e.currentTarget) {
          const bounds = e.currentTarget.getBoundingClientRect();
          if (
            e.clientX < bounds.left ||
            e.clientX > bounds.right ||
            e.clientY < bounds.top ||
            e.clientY > bounds.bottom
          ) {
            onClose();
          }
        }
        isMouseDownOnBackdrop.current = false;
      }}
    >
      <div className="section-heading">
        <h2 id={titleId}><TranslatedText>{title}</TranslatedText></h2>
        <TranslatedText>{tr(headerActions)}</TranslatedText>
        {!hideClose && <button
          className="icon-button"
          onClick={onClose}
          aria-label={tr("Close dialog")}
        >
          <X size={18} />
        </button>}
      </div>
      {children}
    </dialog>
  );
}
export function PageHeading({ title, sub }: { title: string; sub: string }) {
  const { t: tr } = useLanguage("common");
  return (
    <div className="page-heading">
      <h1><TranslatedText>{title}</TranslatedText></h1>
      <p><TranslatedText>{tr(sub)}</TranslatedText></p>
    </div>
  );
}
