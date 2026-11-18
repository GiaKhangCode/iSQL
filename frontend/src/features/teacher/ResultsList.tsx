import { ListModeSwitch } from "@/features/teacher/components/ListModeSwitch";
import { useListOrder } from "@/shared/components/ListSortSelect";
import { useListDateFilters } from "@/shared/components/ListDateFilters";
import { matchesListSearch } from "@/shared/lib/listSearch";
import { newestFirst } from "@/shared/lib/sql/listOrder";
import { Table, TableRow } from "@/shared/ui/Table";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import { useLanguage } from "@/shared/context/LanguageContext";
import { useEffect, useMemo, useState, useRef } from "react";
import { Link, useNavigate, useSearchParams, useLocation } from "react-router-dom";
import { ErrorState } from "@/shared/ui/ui";
import { TeacherPageIntro } from "@/features/teacher/components/TeacherPageParts";
import { BookOpen, ListFilter } from "lucide-react";
import { TeacherListFilters } from "@/shared/components/TeacherListFilters";
import { TeacherListPagination } from "@/shared/components/TeacherListPagination";
import { TeacherListLoading } from "@/shared/components/TeacherListLoading";
import { assignmentClassNames } from "@/features/teacher/filters/AssignmentFilters";

export function ResultsList({isExamSession,assignments,loading,error,onSwitchKind}:{isExamSession:boolean;assignments?:any[];loading:boolean;error:string;onSwitchKind?:()=>void}) {
  const order=useListOrder();
  const dates = useListDateFilters();
  const { t: tr } = useLanguage("teacher");
  const [searchParams] = useSearchParams();
  const [search, setSearch] = useState(() => searchParams.get("search") || "");
  const [classFilter, setClassFilter] = useState("All classes");
  const [statusFilter, setStatusFilter] = useState("All");
  const [selectedResultId, setSelectedResultId] = useState("");
  const [page, setPage] = useState(1);
  const [actionsTarget,setActionsTarget]=useState<HTMLDivElement|null>(null);
  useEffect(()=>setPage(1),[dates.key,order.value]);
  const tableScrollRef = useRef<HTMLDivElement>(null);
  const navigate = useNavigate();
  const root=useLocation().pathname.startsWith("/admin/")?"/admin":useLocation().pathname.startsWith("/office/")?"/office":"/teacher";

  const classOptions = useMemo(() => Array.from(new Set((assignments || []).filter(item=>(isExamSession ? Boolean(item.isExamSession || item.examinationId) : !item.isExamSession && !item.examinationId)).flatMap(item => assignmentClassNames(item.classes))))
    .filter(name => name !== "No classes").sort((a, b) => a.localeCompare(b, undefined, { numeric: true })), [assignments,isExamSession]);

  const filteredRows = useMemo(() => {
    if (!assignments) return [];
    return newestFirst(assignments, item=>item.createdAt).filter((item: any) => {
      return dates.matches(item) && (isExamSession ? Boolean(item.isExamSession || item.examinationId) : !item.isExamSession && !item.examinationId) && matchesListSearch(search,item.id,item.title,item.classes) &&
      (classFilter === "All classes" || assignmentClassNames(item.classes).includes(classFilter)) &&
      (statusFilter === "All" || (statusFilter === "Awaiting review" ? item.awaiting > 0 : item.awaiting === 0));
    });
  }, [search, classFilter, statusFilter, assignments, isExamSession, dates.key]);

  const filtered=order.apply(filteredRows,item=>item.id);
  const pageCount = Math.max(1, Math.ceil(filtered.length / 15));
  const currentPage = Math.min(page, pageCount);
  const firstIndex = (currentPage - 1) * 15;
  const pageResults = filtered.slice(firstIndex, firstIndex + 15);
  const selectedResult = pageResults.find((item: any) => item.id === selectedResultId) || pageResults[0] || null;
  useEffect(() => { setPage(1); setSelectedResultId(""); }, [search, classFilter, statusFilter]);
  useEffect(() => { tableScrollRef.current?.scrollTo({ top: 0 }); }, [currentPage, search, classFilter, statusFilter]);

  function chooseResult(activity: any) {
    if (selectedResult?.id === activity.id) openResultDetails(activity);
    else setSelectedResultId(activity.id);
  }

  function openResultDetails(activity: any) {
    navigate(`${root}/results/activity/${encodeURIComponent(activity.id)}`);
  }

  if (loading) return <section className="teacher-page teacher-results-page compact-teacher-results"><TeacherListLoading columns={6} filterColumns={3} label={tr("Loading results…")} /></section>;
  if (error) return <div className="teacher-page"><TeacherPageIntro compact title={tr("Results")} context={tr("Results unavailable")} /><ErrorState title={tr("Could not load results")} message={tr(error)} onRetry={() => window.location.reload()} /></div>;

  return (
    <section className="teacher-page teacher-results-page compact-teacher-results">
      <h1 className="sr-only"><TranslatedText>{tr("Results")}</TranslatedText></h1>

      <div className="teacher-list-detail-layout teacher-results-detail-layout teacher-filter-layout">
        <div className="teacher-list-detail-main teacher-results-table-wrap">
          <TeacherListFilters order={order} suggestions={filtered.map(item=>({id:item.id,title:item.title,description:item.classes}))} dates={dates} actionsTarget={actionsTarget} search={search} onSearchChange={setSearch} searchLabel={tr(isExamSession?"Search exams…":"Search assignments…")}
            primaryAction={onSwitchKind && <ListModeSwitch rooms={isExamSession} labels={["Assignments","Examinations"]} nextKind={isExamSession?"assignments":"exams"} onChange={()=>onSwitchKind()}/>}
            filters={[
              { key: "class", label: tr("CLASS"), value: classFilter, defaultValue: "All classes", onChange: setClassFilter,
                icon: <BookOpen size={17} aria-hidden="true" />, options: ["All classes", ...classOptions].map(value => ({ value, label: tr(value) })) },
              { key: "review", label: tr("REVIEW STATUS"), value: statusFilter, defaultValue: "All", onChange: setStatusFilter,
                icon: <ListFilter size={17} aria-hidden="true" />, options: ["All", "Awaiting review", "Reviewed"].map(value => ({ value, label: tr(value) })) },
            ]} />
          <div ref={tableScrollRef} className="teacher-table-scroll teacher-list-table-scroll sticky-list-table-wrap">
            <Table className="teacher-table teacher-results-table teacher-activity-results-table sticky-list-table">
              <thead>
                <tr>
                  <th>ID</th><th><TranslatedText>{tr(isExamSession ? "Examinations" : "Assignments")}</TranslatedText></th><th><TranslatedText>{tr("CLASSES")}</TranslatedText></th><th><TranslatedText>{tr("SUBMITTED")}</TranslatedText></th><th><TranslatedText>{tr("AVG SCORE")}</TranslatedText></th><th><TranslatedText>{tr("AWAITING REVIEW")}</TranslatedText></th>
                </tr>
              </thead>
              <tbody>
                {pageResults.map((item, index) => (
                  <TableRow selected={selectedResult?.id === item.id}
                    className={`teacher-selectable-list-row${selectedResult?.id === item.id ? " is-selected" : ""}`}
                    key={item.id}
                    onClick={() => chooseResult(item)}
                    onDoubleClick={() => openResultDetails(item)}
                  >
                    <td data-label="ID"><Link className="teacher-result-id-link" to={`${root}/results/activity/${encodeURIComponent(item.id)}`} aria-label={tr("Open results for {value0}", {value0:item.title})} onClick={event=>event.stopPropagation()}>{firstIndex + index + 1}</Link></td>
                    <td data-label={tr(isExamSession ? "Examinations" : "Assignments")}><button className="teacher-selectable-row-title teacher-list-row-title" type="button" aria-label={tr("Show details for {value0}", { value0: item.title })} onClick={(event) => { event.stopPropagation(); chooseResult(item); }}>{item.title}</button></td>

                    <td data-label={tr("CLASSES")}>{item.classes}</td>
                    <td data-label={tr("SUBMITTED")}>{item.submitted}</td>
                    <td data-label={tr("AVG SCORE")}>{item.average}</td>
                    <td data-label={tr("AWAITING REVIEW")} className={item.awaiting ? "teacher-state-warning" : ""}>{item.awaiting}</td>

                  </TableRow>
                ))}
                {!filtered.length && <tr><td className="teacher-empty-row" colSpan={6}><TranslatedText>{tr("No results match these filters.")}</TranslatedText></td></tr>}
              </tbody>
            </Table>
          </div>
          <TeacherListPagination
            summary={<><TranslatedText>{tr("Showing")}</TranslatedText> {filtered.length ? firstIndex + 1 : 0}–{firstIndex + pageResults.length} <TranslatedText>{tr("of")}</TranslatedText> {filtered.length} <TranslatedText>{tr("items")}</TranslatedText></>}
            paginationLabel={tr("Results pagination")}
            pageIndicator={<><TranslatedText>{tr("Page")}</TranslatedText> {currentPage} <TranslatedText>{tr("of")}</TranslatedText> {pageCount}</>}
            previousLabel={<TranslatedText>{tr("← Previous")}</TranslatedText>}
            nextLabel={<TranslatedText>{tr("Next →")}</TranslatedText>}
            page={currentPage}
            pageCount={pageCount}
            onPageChange={nextPage => { setPage(nextPage); setSelectedResultId(""); }}
          />
        </div>
        <div className="teacher-filter-side">
        <div ref={setActionsTarget} className="teacher-filter-actions"/>
        <aside className="teacher-list-detail-panel">
          {selectedResult ? <>
            <span className="teacher-detail-eyebrow"><TranslatedText>{tr("RESULT DETAILS")}</TranslatedText></span>
            <h2>{selectedResult.title}</h2>
            <p className="muted"><TranslatedText>{selectedResult.isExamSession ? tr("Examination") : tr("Assignment")}</TranslatedText> · {selectedResult.classes}</p>
            <dl className="teacher-list-detail-facts">
              <div><dt><TranslatedText>{tr("Submitted")}</TranslatedText></dt><dd>{selectedResult.submitted}</dd></div>
              <div><dt><TranslatedText>{tr("Average score")}</TranslatedText></dt><dd>{selectedResult.average}</dd></div>
              <div><dt><TranslatedText>{tr("Awaiting review")}</TranslatedText></dt><dd>{selectedResult.awaiting}</dd></div>
            </dl>
            <button className="button primary teacher-list-detail-edit" type="button" onClick={() => openResultDetails(selectedResult)}><TranslatedText>{tr("View submissions")}</TranslatedText></button>
          </> : <p className="muted"><TranslatedText>{tr("Select a result to view its details.")}</TranslatedText></p>}
        </aside>
        </div>
      </div>
    </section>
  );
}
