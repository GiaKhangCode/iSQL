import { forwardRef, type TableHTMLAttributes, type HTMLAttributes } from "react";

type TableProps = TableHTMLAttributes<HTMLTableElement> & {
  /** Inherit each screen's column widths, scrolling and responsive layout. */
  density?: "inherit" | "compact" | "comfortable";
};

export const Table = forwardRef<HTMLTableElement, TableProps>(function Table({ className = "", density = "inherit", ...props }, ref) {
  return <table ref={ref} {...props} className={`app-table ${className}`} data-density={density} />;
});

/** A selected row keeps native table semantics and the page's existing actions. */
export const TableRow = forwardRef<HTMLTableRowElement, HTMLAttributes<HTMLTableRowElement> & { selected: boolean }>(
  function TableRow({ selected, className = "", ...props }, ref) {
    return <tr ref={ref} {...props} aria-selected={selected} className={`app-table-row${selected ? " is-selected" : ""} ${className}`} />;
  },
);
