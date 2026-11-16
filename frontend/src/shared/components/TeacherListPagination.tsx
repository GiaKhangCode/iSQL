import type { ReactNode } from "react";

export function TeacherListPagination({
  summary,
  paginationLabel,
  pageIndicator,
  previousLabel,
  nextLabel,
  page,
  pageCount,
  onPageChange,
}: {
  summary: ReactNode;
  paginationLabel: string;
  pageIndicator: ReactNode;
  previousLabel: ReactNode;
  nextLabel: ReactNode;
  page: number;
  pageCount: number;
  onPageChange: (page: number) => void;
}) {
  return (
    <div className="teacher-list-footer teacher-library-pagination">
      <span>{summary}</span>
      <nav aria-label={paginationLabel}>
        <button className="button" type="button" disabled={page === 1} onClick={() => onPageChange(page - 1)}>{previousLabel}</button>
        <span>{pageIndicator}</span>
        <button className="button" type="button" disabled={page === pageCount} onClick={() => onPageChange(page + 1)}>{nextLabel}</button>
      </nav>
    </div>
  );
}
