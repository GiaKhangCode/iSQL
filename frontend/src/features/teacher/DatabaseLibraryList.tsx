import {TranslatedText} from "@/shared/ui/TranslatedText";
import { useListOrder } from "@/shared/components/ListSortSelect";
import { useListDateFilters } from "@/shared/components/ListDateFilters";
import { matchesListSearch } from "@/shared/lib/listSearch";
import { useEffect, useMemo, useRef, useState } from "react";
import { Table, TableRow } from "@/shared/ui/Table";
import { TeacherListFilters } from "@/shared/components/TeacherListFilters";
import { TeacherListPagination } from "@/shared/components/TeacherListPagination";
import { useLanguage } from "@/shared/context/LanguageContext";
import { parseServerDateTime } from "@/shared/lib/datetime/serverDateTime";
import type { TeacherDatabaseLibraryEntry } from "@/shared/api/teacherService";
import { datasetsFromEntry } from "./DatabaseLibraryParts";
import "@/styles/problem-library-filters.css";

export function DatabaseLibraryList({ entries, onNew, onEdit, initialSearch = "" }: {
  entries: TeacherDatabaseLibraryEntry[]; onNew: () => void; onEdit: (entry: TeacherDatabaseLibraryEntry) => void; initialSearch?: string;
}) {
  const order=useListOrder();
  const dates = useListDateFilters();
  const { t, locale } = useLanguage("teacher");
  const [search, setSearch] = useState(initialSearch);
  const [selectedId, setSelectedId] = useState("");
  const [page, setPage] = useState(1);
  useEffect(()=>setPage(1),[dates.key,order.value]);
  const [actionsTarget, setActionsTarget] = useState<HTMLDivElement | null>(null);
  const scroll = useRef<HTMLDivElement>(null);
  // Map each entry to its permanent natural sequential ID (1, 2, 3...) based on creation time
  const naturalIdMap = useMemo(() => {
    const sorted = [...entries].sort((a, b) => {
      const tA = parseServerDateTime(a.createdAt || "").getTime();
      const tB = parseServerDateTime(b.createdAt || "").getTime();
      return tA - tB || a.id.localeCompare(b.id);
    });
    const map = new Map<string, number>();
    sorted.forEach((item, idx) => {
      map.set(item.id, idx + 1);
    });
    return map;
  }, [entries]);

  const filteredRows = entries.filter(entry => dates.matches(entry) && matchesListSearch(search,entry.title,entry.createTableFileName));
  const filtered = order.apply(filteredRows, item => naturalIdMap.get(item.id) ?? 0);
  const pageCount = Math.max(1, Math.ceil(filtered.length / 15));
  const currentPage = Math.min(page, pageCount);
  const first = (currentPage - 1) * 15;
  const rows = filtered.slice(first, first + 15);
  const selected = rows.find(entry => entry.id === selectedId) || rows[0];
  const date = (value: string) => {
    const parsed = parseServerDateTime(value);
    return Number.isFinite(parsed.getTime()) ? parsed.toLocaleDateString(locale, {day:"2-digit",month:"2-digit",year:"numeric"}) : "—";
  };
  useEffect(() => { scroll.current?.scrollTo?.({top:0}); }, [search, currentPage]);
  const choose=(entry:TeacherDatabaseLibraryEntry)=>{if(selected?.id===entry.id)onEdit(entry);else setSelectedId(entry.id);};
  return <section className="teacher-page teacher-library-page compact-teacher-library teacher-database-list-page">
    <h1 className="sr-only"><TranslatedText>{t("Database library")}</TranslatedText></h1>
    <div className="teacher-list-detail-layout teacher-filter-layout">
      <div className="teacher-list-detail-main">
        <TeacherListFilters order={order} suggestions={filtered.map(item=>({id:item.id,title:item.title,description:item.createTableFileName||"SQL Server"}))} dates={dates} search={search} onSearchChange={value => {setSearch(value);setPage(1);setSelectedId("");}} searchLabel={t("Search databases…")} actionsTarget={actionsTarget} createLabel={t("New database")} onCreate={onNew} />
        <div ref={scroll} className="teacher-table-scroll teacher-list-table-scroll sticky-list-table-wrap">
          <Table className="teacher-table teacher-database-tracking-table sticky-list-table">
            <thead><tr><th>ID</th><th><TranslatedText>{t("Database")}</TranslatedText></th><th><TranslatedText>{t("DML datasets")}</TranslatedText></th><th><TranslatedText>{t("Updated date")}</TranslatedText></th></tr></thead>
            <tbody>{rows.map((entry, index) => <TableRow key={entry.id} selected={selected?.id === entry.id} className="teacher-selectable-list-row" onClick={() => choose(entry)} onDoubleClick={() => onEdit(entry)}>
              <td data-label="ID">{naturalIdMap.get(entry.id) ?? (first + index + 1)}</td>
              <td data-label={t("Database")}><button type="button" className="teacher-selectable-row-title teacher-library-title" onClick={event=>{event.stopPropagation();choose(entry);}}>{entry.title}</button></td>
              <td data-label={t("DML datasets")}>{datasetsFromEntry(entry).filter(dataset => dataset.sql.trim()).length}</td>
              <td data-label={t("Updated date")}>{date(entry.updatedAt || entry.createdAt)}</td>
            </TableRow>)}
            {!rows.length && <tr><td colSpan={4} className="teacher-empty-row"><TranslatedText>{t(entries.length ? "No databases match this search." : "No databases yet. Create your first database to get started.")}</TranslatedText></td></tr>}</tbody>
          </Table>
        </div>
        <TeacherListPagination summary={t("Showing {start}–{end} of {total} databases", {start:rows.length?first+1:0,end:first+rows.length,total:filtered.length})} paginationLabel={t("Database library pagination")} pageIndicator={t("Page {page} of {total}",{page:currentPage,total:pageCount})} previousLabel={t("← Previous")} nextLabel={t("Next →")} page={currentPage} pageCount={pageCount} onPageChange={value => {setPage(value);setSelectedId("");}} />
      </div>
      <div className="teacher-filter-side">
        <div ref={setActionsTarget} className="teacher-filter-actions" />
        <aside className="teacher-list-detail-panel teacher-problem-details-panel">
          <div className="teacher-problem-detail-body">
            <span className="teacher-detail-eyebrow"><TranslatedText>{t("Database details")}</TranslatedText></span>
            {selected ? <><h2>{selected.title}</h2><p className="muted">{selected.createTableFileName || "SQL Server"}</p>
              <dl className="teacher-list-detail-facts">
                <div><dt><TranslatedText>{t("Database")}</TranslatedText></dt><dd>SQL Server</dd></div>
                <div><dt><TranslatedText>{t("DML datasets")}</TranslatedText></dt><dd>{datasetsFromEntry(selected).filter(dataset => dataset.sql.trim()).length}</dd></div>
                <div><dt><TranslatedText>{t("Created date")}</TranslatedText></dt><dd>{date(selected.createdAt)}</dd></div>
                <div><dt><TranslatedText>{t("Updated date")}</TranslatedText></dt><dd>{date(selected.updatedAt || selected.createdAt)}</dd></div>
              </dl></> : <p className="muted"><TranslatedText>{t("Select a database to view its details.")}</TranslatedText></p>}
          </div>
          {selected && <div className="teacher-list-detail-actions"><button type="button" className="button primary" onClick={() => onEdit(selected)}><TranslatedText>{t("Edit")}</TranslatedText></button></div>}
        </aside>
      </div>
    </div>
  </section>;
}
