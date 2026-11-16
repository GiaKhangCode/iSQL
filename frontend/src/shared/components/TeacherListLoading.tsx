import { useState, type CSSProperties, type ReactNode } from "react";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import { TeacherListFilters } from "@/shared/components/TeacherListFilters";
import { SkeletonLine } from "@/shared/ui/LoadingSkeleton";

/** Reserve the table and detail layout while the first request is pending. */
export function TeacherListLoading({ label, createLabel, createTo, onCreate, columns = 6, secondaryAction, filterColumns = 0 }: {
  label: string; createLabel?: string; createTo?: string; onCreate?: () => void; columns?: number; secondaryAction?: ReactNode; filterColumns?: number;
}) {
  const [actionsTarget, setActionsTarget] = useState<HTMLDivElement | null>(null);
  return <div className="teacher-list-detail-layout teacher-filter-layout teacher-list-loading" style={{ "--teacher-loading-columns": columns } as CSSProperties}>
    <span className="sr-only" role="status"><TranslatedText>{label}</TranslatedText></span>
    <div className="teacher-list-detail-main" aria-busy="true">
      {filterColumns > 0 ? <div className="teacher-loading-filters" aria-hidden="true" style={{ gridTemplateColumns: `repeat(${filterColumns}, minmax(0, 1fr))` }}>
        {Array.from({ length: filterColumns }, (_, index) => <SkeletonLine key={index} />)}
      </div> : <TeacherListFilters search="" onSearchChange={() => {}} searchLabel={label} disabled
        actionsTarget={actionsTarget} createLabel={createLabel} createTo={createTo} onCreate={onCreate} secondaryAction={secondaryAction} />}
      <div className="teacher-loading-table" aria-hidden="true">
        <div className="teacher-loading-row teacher-loading-head">{Array.from({ length: columns }, (_, index) => <SkeletonLine className="teacher-skeleton-line" key={index} />)}</div>
        {Array.from({ length: 7 }, (_, row) => <div className="teacher-loading-row" key={row}>
          {Array.from({ length: columns }, (_, column) => <SkeletonLine className="teacher-skeleton-line" key={column} />)}
        </div>)}
      </div>
      <div className="teacher-list-footer teacher-loading-footer" aria-hidden="true"><SkeletonLine className="teacher-skeleton-line" /><SkeletonLine className="teacher-skeleton-line" /></div>
    </div>
    <div className="teacher-filter-side">
      {!filterColumns && <div ref={setActionsTarget} className="teacher-filter-actions" />}
      <aside className="teacher-list-detail-panel teacher-loading-detail" aria-hidden="true">
        <SkeletonLine className="teacher-skeleton-line teacher-loading-eyebrow" />
        <SkeletonLine className="teacher-skeleton-line teacher-loading-title" />
        <SkeletonLine className="teacher-skeleton-line teacher-loading-summary" />
        <div className="teacher-loading-facts">{Array.from({ length: 6 }, (_, index) => <div key={index}><SkeletonLine className="teacher-skeleton-line" /><SkeletonLine className="teacher-skeleton-line" /></div>)}</div>
        <div className="teacher-loading-buttons"><SkeletonLine className="teacher-skeleton-line" /><SkeletonLine className="teacher-skeleton-line" /></div>
      </aside>
    </div>
  </div>;
}
