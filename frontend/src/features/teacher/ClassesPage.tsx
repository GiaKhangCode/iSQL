import { useListOrder } from "@/shared/components/ListSortSelect";
import { useListDateFilters } from "@/shared/components/ListDateFilters";
import { matchesListSearch } from "@/shared/lib/listSearch";
import { formatAcademicTerm, normalizeAcademicTerm } from "@/shared/lib/datetime/academicTerms";
import { Table, TableRow } from "@/shared/ui/Table";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import { useLanguage } from "@/shared/context/LanguageContext";
import { useEffect, useMemo, useState, useRef } from "react";
import { Dialog, ErrorState } from "@/shared/ui/ui";
import {
  type TeacherClass,
  type TeacherClassMember,
} from "@/data/teacherTypes";
import { teacherService } from "@/shared/api/teacherService";


import { CalendarDays } from "lucide-react";
import { TeacherListFilters } from "@/shared/components/TeacherListFilters";
import { TeacherListPagination } from "@/shared/components/TeacherListPagination";
import { TeacherListLoading } from "@/shared/components/TeacherListLoading";

export function ClassesPage() {

  const [filterActionsTarget,setFilterActionsTarget]=useState<HTMLDivElement|null>(null);
  const order=useListOrder();
  const dates = useListDateFilters();
  const { t: tr } = useLanguage("teacher");
  const [classes, setClasses] = useState<TeacherClass[]>([]);
  const [classesLoading, setClassesLoading] = useState(true);
  const [classesError, setClassesError] = useState("");
  const [statusFilter, setStatusFilter] = useState("");
  const [term, setTerm] = useState("All terms");
  const [search, setSearch] = useState("");
  const [selectedClassId, setSelectedClassId] = useState("");
  const [page, setPage] = useState(1);
  useEffect(()=>setPage(1),[dates.key,order.value]);
  const tableScrollRef = useRef<HTMLDivElement>(null);
  const [studentSearch, setStudentSearch] = useState("");
  const [classDetailsOpen, setClassDetailsOpen] = useState(false);
  const [detailTab, setDetailTab] = useState<"assignments" | "members">("assignments");
  const [classActivities, setClassActivities] = useState<any[]>([]);
  const [detailMembers, setDetailMembers] = useState<TeacherClassMember[]>([]);
  const [detailError, setDetailError] = useState("");

  useEffect(() => {
    teacherService.getClasses().then((data) => {
      setClasses(data);
      setSelectedClassId(data[0]?.id || "");
    }).catch((error) => setClassesError(error instanceof Error ? error.message : "Could not load classes.")).finally(() => setClassesLoading(false));
  }, []);

  useEffect(() => {
    if (!classDetailsOpen || !selectedClassId) return;
    let active = true;
    setDetailError("");
    setClassActivities([]);
    setDetailMembers([]);
    Promise.all([teacherService.getAssignments(), teacherService.getClassMembers(selectedClassId)])
      .then(([activities, classMembers]) => {
        if (!active) return;
        setClassActivities(activities.filter((item: any) => item.classIds?.includes(selectedClassId)));
        setDetailMembers(classMembers);
      })
      .catch(() => { if (active) setDetailError("Could not load class activity or members."); });
    return () => { active = false; };
  }, [classDetailsOpen, selectedClassId]);

  const termOptions = useMemo(
    () => Array.from(new Set(classes.map((classInfo) => classInfo.term))).sort((a, b) => {
      const termA = /^HK([12])_(\d{4})/.exec(normalizeAcademicTerm(a));
      const termB = /^HK([12])_(\d{4})/.exec(normalizeAcademicTerm(b));
      if (!termA || !termB) return a.localeCompare(b);
      return Number(termB[2]) - Number(termA[2]) || Number(termB[1]) - Number(termA[1]);
    }),
    [classes],
  );
  const visibleClassesRows = useMemo(() => classes.filter((classInfo) => {
    const matchesTerm = term === "All terms" || classInfo.term === term;
    const matchesSearch = matchesListSearch(search,classInfo.id,classInfo.course,formatAcademicTerm(classInfo.term));
    return dates.matches(classInfo) && matchesTerm && matchesSearch && (!statusFilter || classInfo.status === statusFilter);
  }), [classes, search, term, statusFilter, dates.key]);
  const visibleClasses=order.apply(visibleClassesRows,classInfo=>classInfo.id);
  const pageCount = Math.max(1, Math.ceil(visibleClasses.length / 15));
  const currentPage = Math.min(page, pageCount);
  const firstIndex = (currentPage - 1) * 15;
  const pageClasses = visibleClasses.slice(firstIndex, firstIndex + 15);
  const selectedClass = pageClasses.find((classInfo) => classInfo.id === selectedClassId) || pageClasses[0] || null;
  useEffect(() => { setPage(1); setSelectedClassId(""); }, [search, term, statusFilter]);
  useEffect(() => { tableScrollRef.current?.scrollTo({ top: 0 }); }, [currentPage, search, term, statusFilter]);
  const assignments = classActivities.filter(item => !item.isContest);
  const contests = classActivities.filter(item => item.isContest);
  const filteredStudents = useMemo(() => detailMembers.filter((student) =>
    `${student.name} ${student.id}`.toLowerCase().includes(studentSearch.toLowerCase()),
  ), [detailMembers, studentSearch]);

  useEffect(() => {
    if (selectedClass && selectedClassId !== selectedClass.id) setSelectedClassId(selectedClass.id);
  }, [selectedClass, selectedClassId]);

  function selectClass(classInfo: TeacherClass) {
    if (selectedClass?.id === classInfo.id) openClassDetails();
    setSelectedClassId(classInfo.id);
    setStudentSearch("");
  }

  function openClassDetails() {
    setDetailTab("assignments");
    setClassDetailsOpen(true);
  }



  return (
    <section className="teacher-page teacher-classes-page compact-teacher-classes">
      <h1 className="sr-only"><TranslatedText>{tr("Classes")}</TranslatedText></h1>

      {classesLoading ? <TeacherListLoading label={tr("Loading classes…")} columns={4} filterColumns={2} /> : classesError ? <ErrorState title={tr("Could not load classes")} message={tr(classesError)} onRetry={() => window.location.reload()} /> : classes.length === 0 ? <div className="teacher-empty-state">
        <div><h2><TranslatedText>{tr("No classes yet")}</TranslatedText></h2><p><TranslatedText>{tr("No classes have been assigned to you yet.")}</TranslatedText></p></div>
      </div> : <>
      <div className="teacher-classes-layout teacher-list-detail-layout teacher-filter-layout">
        <div className="teacher-classes-main teacher-list-detail-main">
          <TeacherListFilters order={order} suggestions={visibleClasses.map(item=>({id:item.id,title:item.course,value:item.id,description:`${item.id} · ${formatAcademicTerm(item.term)}`}))} dates={dates} actionsTarget={filterActionsTarget} search={search} onSearchChange={setSearch} searchLabel={tr("Search classes...")}
            filters={[{ key: "term", label: tr("ACADEMIC TERM"), value: term, defaultValue: "All terms", onChange: setTerm,
              icon: <CalendarDays size={17} aria-hidden="true" />, options: ["All terms", ...termOptions].map(value => ({ value, label: value === "All terms" ? tr(value) : formatAcademicTerm(value) })) },{key:"status",label:tr("STATUS"),value:statusFilter,defaultValue:"",onChange:setStatusFilter,icon:<CalendarDays size={17}/>,options:[{value:"",label:tr("All statuses")},...Array.from(new Set(classes.map(item=>item.status))).sort().map(value=>({value,label:tr(value)}))]}]} />
          <div ref={tableScrollRef} className="teacher-table-scroll teacher-list-table-scroll sticky-list-table-wrap">
            <Table className="teacher-table teacher-classes-table sticky-list-table">
              <thead>
                <tr>
                  <th><TranslatedText>{tr("Class")}</TranslatedText></th>
                  <th><TranslatedText>{tr("Term")}</TranslatedText></th>
                  <th><TranslatedText>{tr("Members")}</TranslatedText></th>
                  <th><TranslatedText>{tr("Status")}</TranslatedText></th>
                </tr>
              </thead>
              <tbody>
                {pageClasses.map((classInfo) => (
                  <TableRow selected={selectedClass?.id === classInfo.id}
                    className={`teacher-class-row${selectedClass?.id === classInfo.id ? " is-selected" : ""}`}
                    key={classInfo.id}
                    onClick={() => selectClass(classInfo)}
                    onDoubleClick={() => { selectClass(classInfo); openClassDetails(); }}
                  >
                    <td data-label={tr("Class")}>
                      <button
                        className="teacher-table-link teacher-class-identity"
                        type="button"
                        aria-label={tr("Show summary for {value0}", { value0: classInfo.id })}
                        aria-current={selectedClass?.id === classInfo.id ? "true" : undefined}
                        onClick={(event) => {
                          event.stopPropagation();
                          selectClass(classInfo);
                        }}
                      >
                        <span>{classInfo.id}</span>
                        <small>{classInfo.course}</small>
                      </button>
                    </td>
                    <td data-label={tr("Term")}>{formatAcademicTerm(classInfo.term)}</td>
                    <td data-label={tr("Members")}>{classInfo.students}</td>
                    <td data-label={tr("Status")} className={classInfo.status === "Active" ? "teacher-state-success" : "muted"}><TranslatedText>{tr(classInfo.status)}</TranslatedText></td>
                  </TableRow>
                ))}
                {!visibleClasses.length && (
                  <tr><td colSpan={4} className="muted"><TranslatedText>{tr("No classes match this term and search.")}</TranslatedText></td></tr>
                )}
              </tbody>
            </Table>
          </div>
          <TeacherListPagination
            summary={<><TranslatedText>{tr("Showing")}</TranslatedText> {visibleClasses.length ? firstIndex + 1 : 0}–{firstIndex + pageClasses.length} <TranslatedText>{tr("of")}</TranslatedText> {visibleClasses.length} <TranslatedText>{tr("classes")}</TranslatedText></>}
            paginationLabel={tr("Classes pagination")}
            pageIndicator={<><TranslatedText>{tr("Page")}</TranslatedText> {currentPage} <TranslatedText>{tr("of")}</TranslatedText> {pageCount}</>}
            previousLabel={<TranslatedText>{tr("← Previous")}</TranslatedText>}
            nextLabel={<TranslatedText>{tr("Next →")}</TranslatedText>}
            page={currentPage}
            pageCount={pageCount}
            onPageChange={nextPage => { setPage(nextPage); setSelectedClassId(""); }}
          />
        </div>

        <div className="teacher-filter-side">
        <div ref={setFilterActionsTarget} className="teacher-filter-actions"/>
        <aside className="teacher-class-details teacher-list-detail-panel">
          {selectedClass ? <>
            <div className="teacher-class-detail-heading">
              <div>
                <span className="teacher-detail-eyebrow"><TranslatedText>{tr("CLASS SUMMARY")}</TranslatedText></span>
                <h2>{selectedClass.id}</h2>
                <p className="muted">{selectedClass.course} · {formatAcademicTerm(selectedClass.term)}</p>
              </div>
              <span className={selectedClass.status === "Active" ? "teacher-state-success" : "muted"}><TranslatedText>{tr(selectedClass.status)}</TranslatedText></span>
            </div>

            <p className="teacher-class-summary-students">{selectedClass.students} <TranslatedText>{tr("students")}</TranslatedText></p>
            <button className="button primary teacher-class-detail-button" type="button" onClick={openClassDetails}>
              <TranslatedText>{tr("View class details")}</TranslatedText>
            </button>
          </> : <p className="muted"><TranslatedText>{tr("Select a class to view its assignments and student progress.")}</TranslatedText></p>}
        </aside>
        </div>
      </div>
      </>}

      {classDetailsOpen && selectedClass && (
        <Dialog
          className="teacher-class-detail-dialog-modal"
          title={tr("{value0} · Class details", { value0: selectedClass.id })}
          onClose={() => setClassDetailsOpen(false)}
        >
          <div className="teacher-class-detail-dialog-content">
            <p className="muted">{selectedClass.course} · {formatAcademicTerm(selectedClass.term)}</p>
            {(selectedClass.startDate && selectedClass.endDate) && (
              <p className="muted" style={{ fontSize: "0.85rem", marginTop: "-0.5rem" }}>
                {selectedClass.startDate} <TranslatedText>{tr("to")}</TranslatedText> {selectedClass.endDate}
              </p>
            )}
            <p className="teacher-class-detail-counts">{selectedClass.students} <TranslatedText>{tr("students ·")}</TranslatedText> {assignments.length} <TranslatedText>{tr("assignments ·")}</TranslatedText> {contests.length} <TranslatedText>{tr("contests")}</TranslatedText></p>
            {detailError && <p role="alert" className="teacher-state-failed"><TranslatedText>{tr(detailError)}</TranslatedText></p>}

            <div className="teacher-class-detail-tabs" role="tablist" aria-label={tr("Class details")}>
              <button id="class-assignments-tab" type="button" role="tab" aria-selected={detailTab === "assignments"} aria-controls="class-assignments-panel" className={detailTab === "assignments" ? "is-active" : ""} onClick={() => setDetailTab("assignments")}>
                <TranslatedText>{tr("Class assignments")}</TranslatedText> <span>{assignments.length}</span>
              </button>
              <button id="class-members-tab" type="button" role="tab" aria-selected={detailTab === "members"} aria-controls="class-members-panel" className={detailTab === "members" ? "is-active" : ""} onClick={() => setDetailTab("members")}>
                <TranslatedText>{tr("Class members")}</TranslatedText> <span>{detailMembers.length}</span>
              </button>
            </div>

            <section id="class-assignments-panel" className="teacher-class-detail-section" role="tabpanel" aria-labelledby="class-assignments-tab" hidden={detailTab !== "assignments"}>
              {assignments.length ? assignments.map((assignment) => (
                <article className="teacher-class-assignment" key={assignment.id}>
                  <div className="teacher-class-assignment-topline">
                    <div>
                      <strong>{assignment.title}</strong>
                      <small><TranslatedText>{tr("Due")}</TranslatedText> {assignment.due || "—"} · <TranslatedText>{tr(assignment.status)}</TranslatedText></small>
                    </div>
                    <b>{assignment.submitted}</b>
                  </div>
                </article>
              )) : <p className="muted"><TranslatedText>{tr("No assignment progress is available for this class yet.")}</TranslatedText></p>}
            </section>

            <section id="class-members-panel" className="teacher-class-detail-section" role="tabpanel" aria-labelledby="class-members-tab" hidden={detailTab !== "members"}>
              <label className="teacher-student-search">
                <span className="sr-only"><TranslatedText>{tr("Search students")}</TranslatedText></span>
                <input
                  value={studentSearch}
                  onChange={(event) => setStudentSearch(event.target.value)}
                  placeholder={tr("Search students...")}
                />
              </label>
              <div className="teacher-class-student-list">
                {filteredStudents.map((student) => (
                  <div className="teacher-class-student-row" key={student.id}>
                    <div className="teacher-class-student-name">
                      <strong>{student.name}</strong>
                      <small>{student.email}</small>
                    </div>
                    <div className="teacher-class-student-stat"><small><TranslatedText>{tr(student.role)}</TranslatedText></small></div>
                  </div>
                ))}
                {!filteredStudents.length && <p className="muted"><TranslatedText>{tr("No students match this search.")}</TranslatedText></p>}
              </div>
            </section>
          </div>
        </Dialog>
      )}

    </section>
  );
}
