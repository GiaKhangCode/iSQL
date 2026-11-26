import { useListOrder } from "@/shared/components/ListSortSelect";
import { useListDateFilters } from "@/shared/components/ListDateFilters";
import { Table, TableRow } from "@/shared/ui/Table";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import { useLanguage } from "@/shared/context/LanguageContext";
import { useMemo, useState, useEffect, useRef } from "react";
import { Link, useNavigate } from "react-router-dom";
import { Dialog } from "@/shared/ui/ui";
import { teacherService } from "@/shared/api/teacherService";
import { AssignmentFilters, assignmentClassNames, matchesAssignmentSearch } from "@/features/teacher/filters/AssignmentFilters";
import { TeacherListLoading } from "@/shared/components/TeacherListLoading";
import { TeacherListPagination } from "@/shared/components/TeacherListPagination";
import "@/styles/problem-library-filters.css";
import { TeacherEmptyState } from "@/features/teacher/components/TeacherEmptyState";
import { parseServerDateTime } from "@/shared/lib/datetime/serverDateTime";
import { notify } from "@/shared/ui/notify";

type AssignmentRow = { id: string; title: string; classes: string; createdAt?: string; problems: number; closes?: string | null; submitted: string; eligibleStudents: number; status: "Open" | "Scheduled" | "Closed" | "Draft" };

export function AssignmentsListPage() {
  const order=useListOrder();
  const dates=useListDateFilters();
  const { t: tr, locale } = useLanguage("teacher");
  const navigate = useNavigate();
  const [assignments, setAssignments] = useState<AssignmentRow[]>([]);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState("");
  
  useEffect(() => {
    async function loadAssignments() {
      try {
        const data = await teacherService.getAssignments();
        setAssignments(data.filter((item: any) => !item.isExamSession));
      } catch(e) {
        setLoadError(e instanceof Error ? e.message : "Could not load assignments.");
      } finally {
        setLoading(false);
      }
    }
    loadAssignments();
  }, []);
  const [search, setSearch] = useState("");
  const [classFilter, setClassFilter] = useState("All classes");
  const [statusFilter, setStatusFilter] = useState("All statuses");
  const [selectedAssignmentId, setSelectedAssignmentId] = useState("");
  const [filterActionsTarget, setFilterActionsTarget] = useState<HTMLDivElement | null>(null);
  const [page, setPage] = useState(1);
  useEffect(()=>setPage(1),[dates.key,order.value]);
  const tableScrollRef = useRef<HTMLDivElement>(null);
  const [confirmAssignment, setConfirmAssignment] = useState<AssignmentRow | null>(null);
  const [deleteBusy, setDeleteBusy] = useState(false);
  const filteredRows = useMemo(() => assignments.filter((item) =>
    dates.matches(item) && matchesAssignmentSearch(item, search) &&
    (classFilter === "All classes" || assignmentClassNames(item.classes).includes(classFilter)) &&
    (statusFilter === "All statuses" || item.status === statusFilter),
  ), [assignments, search, classFilter, statusFilter, dates.key]);
  const filtered = order.apply(filteredRows, item => item.createdAt || item.title || "");
  const pageCount = Math.max(1, Math.ceil(filtered.length / 15));
  const currentPage = Math.min(page, pageCount);
  const firstIndex = (currentPage - 1) * 15;
  const pageAssignments = filtered.slice(firstIndex, firstIndex + 15);
  const selectedAssignment = pageAssignments.find((item) => item.id === selectedAssignmentId) || pageAssignments[0] || null;
  const displayDate = (value?: string | null) => {
    const date = value ? parseServerDateTime(value) : null;
    return date && Number.isFinite(date.getTime())
      ? date.toLocaleDateString(locale, { timeZone: "Asia/Ho_Chi_Minh", day: "2-digit", month: "2-digit", year: "numeric" }) : "—";
  };
  useEffect(() => {
    setPage(1);
    setSelectedAssignmentId("");
  }, [search, classFilter, statusFilter]);
  useEffect(() => {
    tableScrollRef.current?.scrollTo({ top: 0 });
  }, [currentPage, search, classFilter, statusFilter]);

  const chooseAssignment=(item:AssignmentRow)=>{if(selectedAssignment?.id===item.id)navigate(`/teacher/assignments/${item.id}/edit`);else setSelectedAssignmentId(item.id);};
  async function removeAssignment() {
    if (!confirmAssignment || deleteBusy) return;
    const assignment = confirmAssignment;
    setDeleteBusy(true);
    try {
      await teacherService.deleteAssignment(assignment.id);
      setAssignments((current) => current.filter((item) => item.id !== assignment.id));
      if (selectedAssignmentId === assignment.id) setSelectedAssignmentId("");
      notify.success(tr(`${assignment.title} was deleted.`));
      setConfirmAssignment(null);
    } catch (e) {
      notify.fail(tr("Could not delete assignment."), e, tr);
    } finally {
      setDeleteBusy(false);
    }
  }

  return (
    <section className="teacher-page teacher-list-page teacher-assignment-page compact-teacher-assignments">
      <h1 className="sr-only"><TranslatedText>{tr("Assignments")}</TranslatedText></h1>
      {loading ? <TeacherListLoading label={tr("Loading assignments…")} createLabel={tr("New assignment")} createTo="/teacher/assignments/new" /> : loadError ? <div className="empty-state" role="alert"><TranslatedText>{tr(loadError)}</TranslatedText></div> : assignments.length === 0 ? <TeacherEmptyState title={tr("No assignments yet")} description={tr("Group problems from your library into an assignment, choose the classes, and set a due date.")} primaryLabel={tr("New assignment")} primaryTo="/teacher/assignments/new" secondaryLabel={tr("Open problem library")} secondaryTo="/teacher/problems" /> : <>
        <div className="teacher-list-detail-layout teacher-assignment-layout">
          <div className="teacher-list-detail-main">
            <AssignmentFilters order={order} dates={dates} assignments={assignments} search={search} onSearchChange={setSearch}
              classFilter={classFilter} onClassChange={setClassFilter} statusFilter={statusFilter} onStatusChange={setStatusFilter}
              onCreate={() => navigate("/teacher/assignments/new")} actionsTarget={filterActionsTarget} />
            <div ref={tableScrollRef} className="teacher-table-scroll teacher-list-table-scroll sticky-list-table-wrap">
              <Table className="teacher-table teacher-assignment-list-table sticky-list-table">
                <thead><tr><th>ID</th><th><TranslatedText>{tr("ASSIGNMENT")}</TranslatedText></th><th><TranslatedText>{tr("CLASSES")}</TranslatedText></th><th><TranslatedText>{tr("Created date")}</TranslatedText></th><th><TranslatedText>{tr("DUE DATE")}</TranslatedText></th><th><TranslatedText>{tr("STATUS")}</TranslatedText></th></tr></thead>
                <tbody>{pageAssignments.map((item, index) => <TableRow selected={selectedAssignment?.id === item.id}
                  className={`teacher-selectable-list-row${selectedAssignment?.id === item.id ? " is-selected" : ""}`}
                  key={item.id}
                  onClick={() => chooseAssignment(item)}
                  onDoubleClick={() => navigate(`/teacher/assignments/${item.id}/edit`)}
                >
                  <td data-label="ID">{firstIndex + index + 1}</td>
                  <td data-label={tr("ASSIGNMENT")}><button className="teacher-selectable-row-title teacher-list-row-title" type="button" aria-label={tr("Show details for {value0}", { value0: item.title })} onClick={(event) => { event.stopPropagation(); chooseAssignment(item); }}>{item.title}</button></td><td data-label={tr("CLASSES")}>{item.classes}</td><td data-label={tr("Created date")}>{displayDate(item.createdAt)}</td><td data-label={tr("DUE")}>{displayDate(item.closes)}</td>
                  <td data-label={tr("STATUS")} className={item.status === "Open" ? "teacher-state-success" : item.status === "Scheduled" ? "teacher-state-warning" : ""}><TranslatedText>{tr(item.status)}</TranslatedText></td>
                </TableRow>)}
                {!filtered.length && <tr><td className="teacher-empty-row" colSpan={6}><TranslatedText>{tr("No assignments match these filters.")}</TranslatedText></td></tr>}</tbody>
              </Table>
            </div>
            <TeacherListPagination
              summary={<><TranslatedText>{tr("Showing")}</TranslatedText> {filtered.length ? firstIndex + 1 : 0}–{firstIndex + pageAssignments.length} <TranslatedText>{tr("of")}</TranslatedText> {filtered.length} <TranslatedText>{tr("assignments")}</TranslatedText></>}
              paginationLabel={tr("Assignments pagination")}
              pageIndicator={<><TranslatedText>{tr("Page")}</TranslatedText> {currentPage} <TranslatedText>{tr("of")}</TranslatedText> {pageCount}</>}
              previousLabel={<TranslatedText>{tr("← Previous")}</TranslatedText>}
              nextLabel={<TranslatedText>{tr("Next →")}</TranslatedText>}
              page={currentPage}
              pageCount={pageCount}
              onPageChange={nextPage => { setPage(nextPage); setSelectedAssignmentId(""); }}
            />
          </div>
          <div className="teacher-assignment-side">
          <div ref={setFilterActionsTarget} className="teacher-assignment-actions" />
          <aside className="teacher-list-detail-panel">
            {selectedAssignment ? <>
              <span className="teacher-detail-eyebrow"><TranslatedText>{tr("ASSIGNMENT DETAILS")}</TranslatedText></span>
              <h2>{selectedAssignment.title}</h2>
              <p className="muted">{selectedAssignment.classes}</p>
              <dl className="teacher-list-detail-facts">
                <div><dt><TranslatedText>{tr("Assigned students")}</TranslatedText></dt><dd>{selectedAssignment.eligibleStudents}</dd></div>
                <div><dt><TranslatedText>{tr("Problems")}</TranslatedText></dt><dd>{selectedAssignment.problems}</dd></div>
                <div><dt><TranslatedText>{tr("Due date")}</TranslatedText></dt><dd>{displayDate(selectedAssignment.closes)}</dd></div>
                <div><dt><TranslatedText>{tr("Submitted")}</TranslatedText></dt><dd>{selectedAssignment.submitted}</dd></div>
                <div><dt><TranslatedText>{tr("Status")}</TranslatedText></dt><dd><TranslatedText>{tr(selectedAssignment.status)}</TranslatedText></dd></div>
              </dl>
              <div className="teacher-list-detail-actions">
                <Link className="button primary" to={`/teacher/assignments/${selectedAssignment.id}/edit`}><TranslatedText>{tr("Edit")}</TranslatedText></Link>
                <Link className="button" to={`/teacher/results?search=${encodeURIComponent(selectedAssignment.title)}`}><TranslatedText>{tr("Results")}</TranslatedText></Link>
                <button className="button teacher-danger-button" onClick={() => setConfirmAssignment(selectedAssignment)}><TranslatedText>{tr("Delete")}</TranslatedText></button>
              </div>
            </> : <p className="muted"><TranslatedText>{tr("Select an assignment to view its details.")}</TranslatedText></p>}
          </aside>
          </div>
        </div>
      </>}
      {confirmAssignment && <Dialog title={tr("Delete assignment")} onClose={() => setConfirmAssignment(null)}>
        <div className="teacher-preview-dialog">
          <p><TranslatedText>{tr("Delete")}</TranslatedText> <strong>{confirmAssignment.title}</strong> <TranslatedText>{tr("from its assigned classes?")}</TranslatedText></p>
          <p className="muted"><TranslatedText>{tr("Students will no longer see this assignment. Existing problem submissions remain in their history.")}</TranslatedText></p>
          <div className="teacher-dialog-actions"><button className="button" type="button" onClick={() => setConfirmAssignment(null)}><TranslatedText>{tr("Cancel")}</TranslatedText></button><button className="button teacher-danger-button" type="button" disabled={deleteBusy} onClick={() => void removeAssignment()}><TranslatedText>{deleteBusy ? tr("Deleting…") : tr("Delete assignment")}</TranslatedText></button></div>
        </div>
      </Dialog>}
    </section>
  );
}
