import type { CSSProperties, ReactNode } from "react";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import "@/shared/ui/LoadingSkeleton.css";

export type LoadingVariant = "list" | "table" | "cards" | "dashboard" | "detail" | "form" | "workspace" | "inline";
export type LoadingSkeletonProps = {
  label: string;
  variant?: LoadingVariant;
  columns?: number;
  rows?: number;
  sidebar?: boolean;
  metrics?: number;
};

export function SkeletonLine({ className = "", width, style }: { className?: string; width?: string; style?: CSSProperties }) {
  return <span className={`skeleton-line ${className}`} style={{ width, ...style }} />;
}

function Paragraph({ lines = 4 }: { lines?: number }) {
  return <div className="loading-skeleton__paragraph">{Array.from({ length: lines }, (_, index) =>
    <SkeletonLine key={index} width={index === lines - 1 ? "64%" : index % 2 ? "92%" : "100%"} />)}</div>;
}

function Toolbar() {
  return <div className="loading-skeleton__toolbar"><SkeletonLine className="loading-skeleton__search" /><SkeletonLine className="loading-skeleton__button" /><SkeletonLine className="loading-skeleton__button" /></div>;
}

export function SkeletonTable({ columns = 5, rows = 6 }: { columns?: number; rows?: number }) {
  return <div className="loading-skeleton__table" style={{ "--skeleton-columns": columns } as CSSProperties}>
    <div className="loading-skeleton__table-head">{Array.from({ length: columns }, (_, index) => <SkeletonLine key={index} width="68%" />)}</div>
    <div className="loading-skeleton__table-body">{Array.from({ length: rows }, (_, row) =>
      <div className="loading-skeleton__table-row" key={row}>{Array.from({ length: columns }, (_, column) =>
        <div key={column}><SkeletonLine width={column === 0 ? "84%" : row % 2 ? "58%" : "72%"} />{column === 0 && <SkeletonLine width="50%" />}</div>)}</div>)}</div>
    <div className="loading-skeleton__table-footer"><SkeletonLine width="26%" /><SkeletonLine width="34%" /></div>
  </div>;
}

export function SkeletonDetails() {
  return <div className="loading-skeleton__panel loading-skeleton__details">
    <SkeletonLine className="loading-skeleton__eyebrow" width="46%" />
    <SkeletonLine className="loading-skeleton__title" width="82%" />
    <Paragraph lines={2} />
    <div className="loading-skeleton__facts">{Array.from({ length: 5 }, (_, index) =>
      <div key={index}><SkeletonLine width="45%" /><SkeletonLine width="25%" /></div>)}</div>
    <SkeletonLine className="loading-skeleton__button" />
  </div>;
}

function Reading() {
  return <div className="loading-skeleton__panel loading-skeleton__reading">
    <SkeletonLine className="loading-skeleton__eyebrow" width="24%" />
    <SkeletonLine className="loading-skeleton__title" width="68%" />
    <div className="loading-skeleton__meta"><SkeletonLine /><SkeletonLine /><SkeletonLine /></div>
    <Paragraph lines={5} /><SkeletonLine className="loading-skeleton__heading" width="38%" /><Paragraph lines={4} />
  </div>;
}

function Form() {
  return <div className="loading-skeleton__panel loading-skeleton__form">
    <SkeletonLine className="loading-skeleton__title" width="52%" />
    <div className="loading-skeleton__fields">{Array.from({ length: 6 }, (_, index) => <div key={index}>
      <SkeletonLine className="loading-skeleton__eyebrow" width="38%" /><SkeletonLine className="loading-skeleton__field" />
    </div>)}</div>
    <SkeletonLine className="loading-skeleton__heading" width="30%" /><SkeletonLine className="loading-skeleton__editor" />
    <div className="loading-skeleton__meta"><SkeletonLine className="loading-skeleton__button" /><SkeletonLine className="loading-skeleton__button" /></div>
  </div>;
}

function Cards({ columns, count }: { columns: number; count: number }) {
  return <div className="loading-skeleton__cards" style={{ "--skeleton-card-columns": columns } as CSSProperties}>
    {Array.from({ length: count }, (_, index) => <div className="loading-skeleton__panel loading-skeleton__card" key={index}>
      <SkeletonLine className="loading-skeleton__cover" /><SkeletonLine className="loading-skeleton__heading" width="76%" />
      <Paragraph lines={2} /><div className="loading-skeleton__meta"><SkeletonLine /><SkeletonLine /></div>
    </div>)}
  </div>;
}

function Calendar() {
  return <div className="loading-skeleton__panel loading-skeleton__calendar">
    <SkeletonLine className="loading-skeleton__heading" width="52%" />
    <div className="loading-skeleton__calendar-grid">{Array.from({ length: 35 }, (_, index) => <SkeletonLine key={index} />)}</div>
    <SkeletonLine className="loading-skeleton__heading" width="68%" />
    {Array.from({ length: 3 }, (_, index) => <Paragraph key={index} lines={2} />)}
  </div>;
}

function Deadlines() {
  return <div className="loading-skeleton__panel loading-skeleton__deadlines">
    <SkeletonLine className="loading-skeleton__heading" width="48%" />
    {Array.from({ length: 3 }, (_, index) => <div className="loading-skeleton__deadline" key={index}>
      <SkeletonLine /><Paragraph lines={2} />
    </div>)}
    <div className="loading-skeleton__week">{Array.from({ length: 7 }, (_, index) => <SkeletonLine key={index} />)}</div>
  </div>;
}

export function LoadingSkeleton({ label, variant = "detail", columns, rows, sidebar, metrics = 0 }: LoadingSkeletonProps) {
  const split = (main: ReactNode, side = <SkeletonDetails />) => <div className={`loading-skeleton__split${sidebar === false ? " loading-skeleton__split--single" : ""}`}>
    {main}{sidebar !== false && side}
  </div>;
  let visual;
  switch (variant) {
    case "list": visual = <><Toolbar />{split(<SkeletonTable columns={columns} rows={rows} />)}</>; break;
    case "table": visual = <SkeletonTable columns={columns} rows={rows} />; break;
    case "cards": visual = <><Toolbar />{sidebar ? split(<Cards columns={columns ?? 2} count={rows ?? 4} />, <Calendar />) : <Cards columns={columns ?? 3} count={rows ?? 6} />}</>; break;
    case "form": visual = split(<Form />); break;
    case "detail": visual = split(<Reading />); break;
    case "inline": visual = <Paragraph lines={rows ?? 3} />; break;
    case "workspace": visual = <>
      <Toolbar /><div className="loading-skeleton__workspace"><Reading /><div className="loading-skeleton__coding">
        <div className="loading-skeleton__panel loading-skeleton__code"><Toolbar />{Array.from({ length: 13 }, (_, index) =>
          <SkeletonLine key={index} width={`${[72, 48, 62, 38, 56][index % 5]}%`} />)}</div>
        <SkeletonTable columns={columns ?? 4} rows={3} />
      </div></div>
    </>; break;
    case "dashboard": visual = metrics > 0 ? split(
      <div className="loading-skeleton__stack"><SkeletonTable columns={2} rows={3} /><SkeletonTable columns={4} rows={4} /></div>,
      <div className="loading-skeleton__stack"><div className="loading-skeleton__metrics">{Array.from({ length: metrics }, (_, index) => <div className="loading-skeleton__panel" key={index}>
        <SkeletonLine width="60%" /><SkeletonLine className="loading-skeleton__title" width="38%" />
      </div>)}</div><SkeletonDetails /></div>,
    ) : <>
      {split(<div className="loading-skeleton__panel loading-skeleton__activity">
        <SkeletonLine className="loading-skeleton__heading" width="34%" /><div className="loading-skeleton__activity-body">
          <div className="loading-skeleton__activity-stats">{Array.from({ length: 2 }, (_, index) => <SkeletonLine className="loading-skeleton__title" key={index} />)}</div>
          <div className="loading-skeleton__heatmap">{Array.from({ length: 182 }, (_, index) => <SkeletonLine key={index} />)}</div>
        </div>
      </div>, <Deadlines />)}
      {split(<SkeletonTable columns={4} rows={4} />)}
    </>; break;
  }
  return <div className={`loading-skeleton loading-skeleton--${variant}`} role="status">
    <span className="sr-only"><TranslatedText>{label}</TranslatedText></span>
    <div className="loading-skeleton__visual" aria-hidden="true">{visual}</div>
  </div>;
}
