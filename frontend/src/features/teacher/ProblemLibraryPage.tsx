import { useListOrder } from "@/shared/components/ListSortSelect";
import { useListDateFilters } from "@/shared/components/ListDateFilters";
import { matchesListSearch } from "@/shared/lib/listSearch";
import { newestFirst } from "@/shared/lib/sql/listOrder";
import { Table, TableRow } from "@/shared/ui/Table";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import { useLanguage } from "@/shared/context/LanguageContext";
import { LanguageText } from "@/shared/ui/LanguageSwitch";
import { useMemo, useState, useEffect, useRef } from "react";
import { Link, useNavigate } from "react-router-dom";
import { ErrorState } from "@/shared/ui/ui";
import { teacherService } from "@/shared/api/teacherService";
import { parseServerDateTime } from "@/shared/lib/datetime/serverDateTime";
import { TeacherListFilters } from "@/shared/components/TeacherListFilters";
import { TeacherListLoading } from "@/shared/components/TeacherListLoading";
import { TeacherListPagination } from "@/shared/components/TeacherListPagination";
import "@/styles/problem-library-filters.css";
import { TeacherEmptyState } from "@/features/teacher/components/TeacherEmptyState";

type ProblemRow = {
  id: string;
  number: string;
  title: string;
  difficulty?: "Easy" | "Medium" | "Hard" | string;
  topics: string;
  database: string;
  createdAt: string | null;
};

export function ProblemLibraryPage() {
  const dates = useListDateFilters();
  const { t, locale: uiLocale } = useLanguage("teacher");
  const navigate=useNavigate();
  const [problems, setProblemState] = useState<ProblemRow[]>([]);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState("");

  useEffect(() => {
    async function load() {
      try {
        const data = await teacherService.getAllProblems();
        const mapped = data.map((p: any) => ({
          id: p.id,
          number: p.number,
          title: p.title,
          topics: (p.topics && p.topics.length > 0) ? p.topics.join(" · ") : (p.topic || ""),
          database: p.databaseType || "SQL Server",
          createdAt: p.createdAt || null,
        }));
        setProblemState(newestFirst(mapped,(item:any)=>item.createdAt));
      } catch (e) {
        setLoadError(e instanceof Error ? e.message : "Could not load problems.");
      } finally {
        setLoading(false);
      }
    }
    load();
  }, []);
  const [search, setSearch] = useState("");
  const order=useListOrder();
  const [selectedProblemId, setSelectedProblemId] = useState("");
  const [problemActionsTarget, setProblemActionsTarget] = useState<HTMLDivElement | null>(null);
  const [page, setPage] = useState(1);
  useEffect(()=>setPage(1),[dates.key]);
  const tableScrollRef = useRef<HTMLDivElement>(null);
  const filtered = useMemo(() => {
    const rows = problems.filter(problem =>
      dates.matches(problem) && matchesListSearch(search, problem.number, problem.title, problem.topics),
    );
    return order.apply(rows,item=>item.number);
  }, [problems, search, order.value, dates.key]);
  const createdDate = (value: string | null) => {
    if (!value) return "—";
    const date = parseServerDateTime(value);
    return Number.isFinite(date.getTime()) ? date.toLocaleDateString(uiLocale, { day: "2-digit", month: "2-digit", year: "numeric" }) : "—";
  };
  const pageCount = Math.max(1, Math.ceil(filtered.length / 15));
  const currentPage = Math.min(page, pageCount);
  const firstIndex = (currentPage - 1) * 15;
  const pageProblems = filtered.slice(firstIndex, firstIndex + 15);
  const selectedProblem = pageProblems.find((problem) => problem.id === selectedProblemId) || pageProblems[0] || null;

  useEffect(() => {
    setPage(1);
    setSelectedProblemId("");
  }, [search, order.value]);
  useEffect(() => {
    tableScrollRef.current?.scrollTo({ top: 0 });
  }, [currentPage, search, order.value]);

  const chooseProblem=(problem:ProblemRow)=>{
    if(selectedProblem?.id===problem.id)navigate(`/teacher/problems/${problem.id}/edit`);
    else setSelectedProblemId(problem.id);
  };
  return (
    <section className="teacher-page teacher-library-page compact-teacher-library">
      <h1 className="sr-only"><TranslatedText>{t("Problem library")}</TranslatedText></h1>
      {loading ? (
        <TeacherListLoading label={t("Loading problems…")} columns={3} createLabel={t("New question set")} createTo="/teacher/problems/new"  />
      ) : loadError ? <ErrorState title={t("Could not load problems")} message={t(loadError)} onRetry={() => window.location.reload()} /> : problems.length === 0 ? (
        <TeacherEmptyState
          title={t("No problems yet")}
          description={t("Create your first SQL problem with a schema, seed data and a reference solution. You can reuse it in any assignment or exam session.")}
          primaryLabel={t("New question set")}
          primaryTo="/teacher/problems/new"
        />
      ) : <>
        <div className="teacher-list-detail-layout teacher-filter-layout">
          <div className="teacher-list-detail-main">
            <TeacherListFilters order={order} suggestions={filtered.map(item=>({id:item.id,title:item.title,description:`${item.number} · ${item.topics}`}))} dates={dates} search={search} onSearchChange={setSearch} searchLabel={t("Search ID, title or topic…")}
              actionsTarget={problemActionsTarget} createLabel={t("New question set")} createTo="/teacher/problems/new"
              />
            <div ref={tableScrollRef} className="teacher-table-scroll teacher-list-table-scroll sticky-list-table-wrap">
              <Table className="teacher-table teacher-library-table teacher-library-column-table sticky-list-table">
                <thead><tr>
                  <th aria-sort={order.value==="ascending"?"ascending":"descending"}>ID</th>
                  <th><TranslatedText>{t("Problem")}</TranslatedText></th>
                  <th><TranslatedText>{t("Created date")}</TranslatedText></th>
                </tr></thead>
                <tbody>{pageProblems.map((problem) => <TableRow selected={selectedProblem?.id === problem.id}
                  className={`teacher-selectable-list-row${selectedProblem?.id === problem.id ? " is-selected" : ""}`}
                  key={problem.id}
                  onClick={() => chooseProblem(problem)}
                  onDoubleClick={() => navigate(`/teacher/problems/${problem.id}/edit`)}
                >
                  <td data-label="ID">{problem.number}</td>
                  <td data-label={t("Problem")}><button className="teacher-selectable-row-title teacher-library-title" type="button" aria-label={t("Show details for {title}", { title: problem.title })} onClick={(event) => { event.stopPropagation(); chooseProblem(problem); }}>{problem.title}</button></td>
                  <td data-label={t("Created date")} className="teacher-problem-created">{problem.createdAt ? <time dateTime={problem.createdAt}>{createdDate(problem.createdAt)}</time> : "—"}</td>
                </TableRow>)}
                {!filtered.length && <tr><td className="teacher-empty-row" colSpan={3}><TranslatedText>{t("No problems match these filters.")}</TranslatedText></td></tr>}
                </tbody>
              </Table>
            </div>
            <TeacherListPagination
              summary={<TranslatedText>{t("Showing {start}–{end} of {total} problems", { start: filtered.length ? firstIndex + 1 : 0, end: firstIndex + pageProblems.length, total: filtered.length })}</TranslatedText>}
              paginationLabel={t("Problem library pagination")}
              pageIndicator={<TranslatedText>{t("Page {page} of {total}", { page: currentPage, total: pageCount })}</TranslatedText>}
              previousLabel={<TranslatedText>{t("← Previous")}</TranslatedText>}
              nextLabel={<TranslatedText>{t("Next →")}</TranslatedText>}
              page={currentPage}
              pageCount={pageCount}
              onPageChange={nextPage => { setPage(nextPage); setSelectedProblemId(""); }}
            />
          </div>
          <div className="teacher-filter-side">
          <div ref={setProblemActionsTarget} className="teacher-filter-actions" />
          <aside className="teacher-list-detail-panel teacher-problem-details-panel">
            {selectedProblem ? <>
              <div className="teacher-problem-detail-body">
              <span className="teacher-detail-eyebrow"><TranslatedText>{t("PROBLEM DETAILS")}</TranslatedText></span>
              <h2>{selectedProblem.number} · {selectedProblem.title}</h2>
              <p className="muted">{selectedProblem.topics}</p>
              <dl className="teacher-list-detail-facts">
                <div><dt><TranslatedText>{t("Database")}</TranslatedText></dt><dd>{selectedProblem.database}</dd></div>
              </dl>
              </div>
              <div className="teacher-list-detail-actions">
                <Link className="button primary" to={selectedProblem.id === "new" ? "/teacher/problems/new" : `/teacher/problems/${selectedProblem.id}/edit`}><LanguageText text="Edit" order={2} /></Link>
              </div>
            </> : <p className="muted"><TranslatedText>{t("Select a problem to view its details.")}</TranslatedText></p>}
          </aside>
          </div>
        </div>
      </>}

    </section>
  );
}
