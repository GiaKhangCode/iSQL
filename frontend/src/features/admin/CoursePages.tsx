import { Select } from "@/shared/ui/Select";
import { useListOrder } from "@/shared/components/ListSortSelect";
import { matchesListSearch } from "@/shared/lib/listSearch";
import { useListDateFilters } from "@/shared/components/ListDateFilters";
import { Table, TableRow } from "@/shared/ui/Table";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import { useLanguage } from "@/shared/context/LanguageContext";
import { useEffect, useRef, useState, type FormEvent } from "react";
import { Link, useLocation, useNavigate, useParams } from "react-router-dom";
import { Dialog, ErrorState, Loading, Status } from "@/shared/ui/ui";
import { type AdminClass } from "@/data/adminPolicy";
import { adminService, lecturerOptionsFor, type InstructorOption } from "@/features/admin/adminService";
import { teacherService } from "@/shared/api/teacherService";
import type { TeacherClassMember } from "@/data/teacherTypes";
import { academicTerms, formatAcademicTerm } from "@/shared/lib/datetime/academicTerms";
import { ListFilter } from "lucide-react";
import { TeacherListFilters } from "@/shared/components/TeacherListFilters";
import { TeacherListPagination } from "@/shared/components/TeacherListPagination";
import { TeacherListLoading } from "@/shared/components/TeacherListLoading";

import { Field, Notice, BulkImportMembersDialog } from "@/features/admin/AdminShared";

function CourseDialog({ onClose, onCreate, error, busy }: { onClose: () => void; onCreate: (id: string, course: string, term: string) => void; error: string; busy: boolean }) {
  const { t: tr } = useLanguage("admin");
  const [newClassForm, setNewClassForm] = useState({
    id: "",
    course: "",
    term: ""
  });

  return (
    <Dialog title={tr("Create a new class")} onClose={onClose}>
      <form className="teacher-manage-dialog" onSubmit={(e) => { e.preventDefault(); if (newClassForm.id.trim() && newClassForm.course.trim() && newClassForm.term) onCreate(newClassForm.id.trim(), newClassForm.course.trim(), newClassForm.term); }}>
        <p className="tiny muted"><TranslatedText>{tr("Set up a new class or section")}</TranslatedText></p>

        <label className="teacher-field" style={{ marginTop: "1rem" }}>
          <span><TranslatedText>{tr("CLASS CODE")}</TranslatedText></span>
          <input required autoFocus value={newClassForm.id} onChange={e => setNewClassForm({...newClassForm, id: e.target.value})} placeholder={tr("E.g. IS207.R14")} />
        </label>

        <label className="teacher-field">
          <span><TranslatedText>{tr("COURSE NAME")}</TranslatedText></span>
          <input required value={newClassForm.course} onChange={e => setNewClassForm({...newClassForm, course: e.target.value})} placeholder={tr("E.g. Web Development")} />
        </label>

        <label className="teacher-field">
          <span><TranslatedText>{tr("TERM")}</TranslatedText></span>
          <Select required value={newClassForm.term} onChange={e => setNewClassForm({...newClassForm, term: e.target.value})}>
            <option value="" disabled>{tr("Select a term...")}</option>
            {academicTerms().map(t => (
              <option key={t} value={t}>{formatAcademicTerm(t)}</option>
            ))}
          </Select>
        </label>

        <div style={{ marginTop: "1rem", display: "flex", gap: "0.5rem" }}>
          <button type="button" className="button" onClick={onClose}><TranslatedText>{tr("Cancel")}</TranslatedText></button>
          <button className="button primary" disabled={busy} type="submit"><TranslatedText>{busy ? tr("Creating…") : tr("Create class")}</TranslatedText></button>
        </div>
        {error && <p className="admin-notice" role="alert"><TranslatedText>{tr(error)}</TranslatedText></p>}
      </form>
    </Dialog>
  );
}

export function AdminCoursesPage() {
  const { t: tr } = useLanguage("admin");
  const location = useLocation();
  const navigate = useNavigate();
  const [classes, setClasses] = useState<AdminClass[]>([]);
  const [selectedId, setSelectedId] = useState("");
  const order = useListOrder();
  const dates = useListDateFilters();
  const [query, setQuery] = useState("");
  const [page, setPage] = useState(1);
  const pageSize = 15;
  const [semester, setSemester] = useState("All semesters");
  const [dialog, setDialog] = useState(false);
  const [notice, setNotice] = useState("");
  const [createError, setCreateError] = useState("");
  const [creating, setCreating] = useState(false);
  const [loadError, setLoadError] = useState("");
  const [loading, setLoading] = useState(true);
  const [actionsTarget, setActionsTarget] = useState<HTMLDivElement | null>(null);
  const tableScrollRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    setPage(1);
  }, [dates.key, order.value]);

  useEffect(() => {
    adminService.getClasses().then((classesData) => {
      setClasses(classesData);
      if (classesData.length > 0 && !selectedId) {
        setSelectedId(classesData[0].id);
      }
    }).catch((error) => setLoadError(error instanceof Error ? error.message : "Could not load classes.")).finally(() => setLoading(false));
  }, []);

  const practicalLecturer = (item: AdminClass & { practice_lecturer?: string }) =>
    item.practice_lecturer || item.lecturer;

  const visibleRows = classes.filter((item) =>
    dates.matches(item) && matchesListSearch(query, item.course, item.id, practicalLecturer(item)) &&
    (semester === "All semesters" || item.semester === semester));
  const visible = order.apply(visibleRows, item => item.id);
  const semesters = [...new Set(classes.map((item) => item.semester).filter(Boolean))].sort().reverse();
  const pageCount = Math.max(1, Math.ceil(visible.length / pageSize));
  const currentPage = Math.min(page, pageCount);
  const firstIndex = (currentPage - 1) * pageSize;
  const pageClasses = visible.slice(firstIndex, firstIndex + pageSize);
  const selected = pageClasses.find(item => item.id === selectedId) || pageClasses[0];

  useEffect(() => {
    if (loading) return;
    const state = location.state as { selectedId?: string; notice?: string } | null;
    if (state?.selectedId) {
      setSelectedId(state.selectedId);
      const index = visible.findIndex(item => item.id === state.selectedId);
      if (index >= 0) setPage(Math.floor(index / pageSize) + 1);
    }
    if (state?.notice) setNotice(state.notice);
    if (state) window.history.replaceState({}, document.title);
  }, [location.state, loading]);

  useEffect(() => {
    setPage(1);
    setSelectedId("");
  }, [query, semester]);

  useEffect(() => {
    tableScrollRef.current?.scrollTo({ top: 0 });
  }, [currentPage, query, semester]);

  const chooseClass = (item: AdminClass) => {
    if (selected?.id === item.id) {
      navigate(`/admin/courses/classes/${encodeURIComponent(item.id)}/edit`);
    } else {
      setSelectedId(item.id);
    }
  };

  if (loading) {
    return (
      <section className="teacher-page teacher-list-page compact-teacher-exam-sessions admin-courses-page">
        <h1 className="sr-only"><TranslatedText>{tr("Courses & classes")}</TranslatedText></h1>
        <TeacherListLoading
          label={tr("Loading courses and classes…")}
          createLabel={tr("Add class")}
          onCreate={() => { setCreateError(""); setDialog(true); }}
          columns={5}
        />
      </section>
    );
  }

  if (loadError) {
    return (
      <section className="teacher-page teacher-list-page compact-teacher-exam-sessions admin-courses-page">
        <h1 className="sr-only"><TranslatedText>{tr("Courses & classes")}</TranslatedText></h1>
        <ErrorState
          title={tr("Could not load courses")}
          message={tr(loadError)}
          onRetry={() => window.location.reload()}
        />
      </section>
    );
  }

  return (
    <section className="teacher-page teacher-list-page compact-teacher-exam-sessions admin-courses-page">
      <h1 className="sr-only"><TranslatedText>{tr("Courses & classes")}</TranslatedText></h1>
      {notice && <p className="teacher-form-message" role="status"><TranslatedText>{tr(notice)}</TranslatedText></p>}
      <div className="teacher-list-detail-layout teacher-filter-layout">
        <div className="teacher-list-detail-main">
          <TeacherListFilters
            order={order}
            dates={dates}
            suggestions={visible.map(item => ({
              id: item.id,
              title: item.course,
              value: item.id,
              description: `${item.id} · ${practicalLecturer(item)}`
            }))}
            search={query}
            onSearchChange={setQuery}
            searchLabel={tr("Search course or class...")}
            actionsTarget={actionsTarget}
            createLabel={tr("Add class")}
            onCreate={() => { setCreateError(""); setDialog(true); }}
            filters={[
              {
                key: "semester",
                label: tr("SEMESTER"),
                value: semester,
                defaultValue: "All semesters",
                onChange: setSemester,
                icon: <ListFilter size={17} aria-hidden="true" />,
                options: [
                  { value: "All semesters", label: tr("All semesters") },
                  ...semesters.map(term => ({ value: term, label: formatAcademicTerm(term) }))
                ]
              }
            ]}
            onReset={() => {
              setQuery("");
              setSemester("All semesters");
            }}
          />
          <div ref={tableScrollRef} className="teacher-table-scroll teacher-list-table-scroll sticky-list-table-wrap">
            <Table className="teacher-table teacher-course-list-table sticky-list-table">
              <thead>
                <tr>
                  <th><TranslatedText>{tr("ID")}</TranslatedText></th>
                  <th><TranslatedText>{tr("COURSE / CLASS")}</TranslatedText></th>
                  <th><TranslatedText>{tr("LECTURER")}</TranslatedText></th>
                  <th><TranslatedText>{tr("STUDENTS")}</TranslatedText></th>
                  <th><TranslatedText>{tr("STATUS")}</TranslatedText></th>
                </tr>
              </thead>
              <tbody>
                {pageClasses.map((item, index) => (
                  <TableRow
                    selected={item.id === selected?.id}
                    key={item.id}
                    className={`teacher-selectable-list-row${item.id === selected?.id ? " is-selected" : ""}`}
                    onClick={() => chooseClass(item)}
                    onDoubleClick={() => navigate(`/admin/courses/classes/${encodeURIComponent(item.id)}/edit`)}
                  >
                    <td data-label="ID">{firstIndex + index + 1}</td>
                    <td data-label={tr("COURSE / CLASS")}>
                      <button
                        type="button"
                        className="teacher-selectable-row-title"
                        onClick={(event) => {
                          event.stopPropagation();
                          chooseClass(item);
                        }}
                      >
                        {item.course}
                      </button>
                      <small className="teacher-row-subtext">{item.id}</small>
                    </td>
                    <td data-label={tr("LECTURER")} className={practicalLecturer(item) === "Unassigned" ? "admin-warning" : ""}>
                      {practicalLecturer(item) === "Unassigned" ? <TranslatedText>{tr("· Lecturer needed")}</TranslatedText> : practicalLecturer(item)}
                    </td>
                    <td data-label={tr("STUDENTS")}>{item.students}</td>
                    <td data-label={tr("STATUS")}><Status value={item.status} /></td>
                  </TableRow>
                ))}
                {!visible.length && (
                  <tr>
                    <td className="teacher-empty-row" colSpan={5}>
                      <TranslatedText>{classes.length === 0 ? tr("No classes yet. Add a class to get started.") : tr("No classes match these filters.")}</TranslatedText>
                    </td>
                  </tr>
                )}
              </tbody>
            </Table>
          </div>
          <TeacherListPagination
            summary={
              <>
                <TranslatedText>{tr("Showing")}</TranslatedText>{" "}
                {visible.length ? firstIndex + 1 : 0}–{firstIndex + pageClasses.length}{" "}
                <TranslatedText>{tr("of")}</TranslatedText> {visible.length}{" "}
                <TranslatedText>{tr("classes")}</TranslatedText>
              </>
            }
            paginationLabel={tr("Course and class pages")}
            pageIndicator={
              <>
                <TranslatedText>{tr("Page")}</TranslatedText> {currentPage}{" "}
                <TranslatedText>{tr("of")}</TranslatedText> {pageCount}
              </>
            }
            previousLabel={<TranslatedText>{tr("← Previous")}</TranslatedText>}
            nextLabel={<TranslatedText>{tr("Next →")}</TranslatedText>}
            page={currentPage}
            pageCount={pageCount}
            onPageChange={(nextPage) => {
              setPage(nextPage);
              setSelectedId("");
            }}
          />
        </div>
        <div className="teacher-filter-side">
          <div ref={setActionsTarget} className="teacher-filter-actions" />
          <aside className="teacher-list-detail-panel">
            {selected ? (
              <>
                <span className="teacher-detail-eyebrow">
                  <TranslatedText>{tr("CLASS DETAILS")}</TranslatedText>
                </span>
                <h2>{selected.id}</h2>
                <p className="muted">{selected.course.split(" · ")[1] || selected.course}</p>
                <dl className="teacher-list-detail-facts">
                  <div>
                    <dt><TranslatedText>{tr("SEMESTER")}</TranslatedText></dt>
                    <dd>
                      <Select
                        value={selected.semester}
                        onChange={async (event) => {
                          const newSemester = event.target.value;
                          try {
                            const res = await adminService.updateClass(selected.id, { semester: newSemester });
                            setClasses(all => all.map(c => c.id === selected.id ? res : c));
                            setNotice(`${selected.id} semester updated.`);
                          } catch (error) {
                            setNotice(error instanceof Error ? error.message : "Could not update semester.");
                          }
                        }}
                        className="admin-class-semester-select"
                      >
                        {academicTerms(selected.semester).map(t => (
                          <option key={t} value={t}>{formatAcademicTerm(t)}</option>
                        ))}
                      </Select>
                    </dd>
                  </div>
                  <div>
                    <dt><TranslatedText>{tr("STATUS")}</TranslatedText></dt>
                    <dd><Status value={selected.status} /></dd>
                  </div>
                  <div>
                    <dt><TranslatedText>{tr("LECTURER")}</TranslatedText></dt>
                    <dd className={practicalLecturer(selected) === "Unassigned" ? "admin-warning" : ""}>
                      {practicalLecturer(selected) === "Unassigned" ? <TranslatedText>{tr("· Lecturer needed")}</TranslatedText> : practicalLecturer(selected)}
                    </dd>
                  </div>
                  <div>
                    <dt><TranslatedText>{tr("CLASS DATES")}</TranslatedText></dt>
                    <dd>{selected.startDate && selected.endDate ? `${selected.startDate} – ${selected.endDate}` : selected.dates || "—"}</dd>
                  </div>
                  <div>
                    <dt><TranslatedText>{tr("STUDENTS")}</TranslatedText></dt>
                    <dd>{selected.students}</dd>
                  </div>
                </dl>
                <div className="teacher-list-detail-actions">
                  <Link className="button primary" to={`/admin/courses/classes/${encodeURIComponent(selected.id)}/edit`}>
                    <TranslatedText>{tr("Edit class")}</TranslatedText>
                  </Link>
                </div>
              </>
            ) : (
              <p className="muted"><TranslatedText>{tr("Select a class to view details.")}</TranslatedText></p>
            )}
          </aside>
        </div>
      </div>
      {dialog && (
        <CourseDialog
          error={createError}
          busy={creating}
          onClose={() => setDialog(false)}
          onCreate={async (id, course, term) => {
            if (classes.some((item) => item.id.toLowerCase() === id.toLowerCase())) {
              setCreateError(`A class with ID ${id} already exists.`);
              return;
            }
            setCreating(true);
            setCreateError("");
            try {
              const item = await adminService.createClass({ id, course, semester: term, lecturerId: "Unassigned" });
              setClasses((all) => [item, ...all]);
              setSelectedId(id);
              setQuery("");
              setSemester("All semesters");
              setPage(1);
              setDialog(false);
              setNotice(`${id} was added.`);
            } catch (err) {
              setCreateError(err instanceof Error ? err.message : "Could not create class.");
            } finally {
              setCreating(false);
            }
          }}
        />
      )}
    </section>
  );
}

export function AdminEditClassPage() {
  const { t: tr } = useLanguage("admin");
  const { classId: routeId = "" } = useParams();
  const classId = decodeURIComponent(routeId);
  const navigate = useNavigate();
  const [record, setRecord] = useState<AdminClass | null>(null);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState("");
  const [course, setCourse] = useState("");
  const [semester, setSemester] = useState("");
  const [startDate, setStartDate] = useState("");
  const [endDate, setEndDate] = useState("");
  const [lecturer, setLecturer] = useState("Unassigned");
  const [status, setStatus] = useState<AdminClass["status"]>("Draft");
  const [notice, setNotice] = useState("");
  const [lecturers, setLecturers] = useState<InstructorOption[]>([]);
  const [saving, setSaving] = useState(false);
  const [members, setMembers] = useState<TeacherClassMember[]>([]);
  const [students, setStudents] = useState<TeacherClassMember[]>([]);
  const [membersLoading, setMembersLoading] = useState(true);
  const [membersError, setMembersError] = useState("");
  const [membersNotice, setMembersNotice] = useState("");
  const [memberSearch, setMemberSearch] = useState("");
  const [memberTab, setMemberTab] = useState<"remove" | "add">("remove");
  const [bulkImportDialog, setBulkImportDialog] = useState(false);
  const [memberBusyId, setMemberBusyId] = useState("");

  useEffect(() => {
    Promise.all([
      adminService.getClasses(),
      adminService.getInstructors()
    ]).then(([classes, instructors]) => {
      const cls = classes.find(c => c.id === classId);
      if (cls) {
        setRecord(cls);
        setCourse(cls.course);
        setSemester(cls.semester);
        setStartDate(cls.startDate || "");
        setEndDate(cls.endDate || "");
        setLecturer(cls.lecturerId || "Unassigned");
        setStatus(cls.status as AdminClass["status"]);
      }
      setLecturers(lecturerOptionsFor(instructors, cls));
      setLoading(false);
    }).catch((error) => { setLoadError(error instanceof Error ? error.message : "Could not load class details."); setLoading(false); });
  }, [classId]);

  useEffect(() => {
    let active = true;
    setMembersLoading(true);
    setMembersError("");
    Promise.all([teacherService.getClassMembers(classId), teacherService.getAllStudents()])
      .then(([classMembers, allStudents]) => {
        if (!active) return;
        setMembers(classMembers);
        setStudents(allStudents);
      })
      .catch((error) => {
        if (active) setMembersError(error instanceof Error ? error.message : "Could not load class members.");
      })
      .finally(() => { if (active) setMembersLoading(false); });
    return () => { active = false; };
  }, [classId]);

  if (loading) return <div className="admin-page"><Loading variant="detail" label={tr("Loading class details…")} /></div>;
  if (loadError) return <div className="admin-page"><Notice><TranslatedText>{tr(loadError)}</TranslatedText></Notice></div>;
  if (!record) return <div className="admin-page"><p className="admin-muted"><TranslatedText>{tr("This class may have been removed.")}</TranslatedText></p></div>;

  const currentRecord = record;

  const enrolledIds = new Set(members.map((member) => member.id).filter(Boolean));
  const normalizedMemberSearch = memberSearch.trim().toLowerCase();
  const filteredMembers = members.filter((member) =>
    `${member.name} ${member.id || ""} ${member.email || ""}`.toLowerCase().includes(normalizedMemberSearch),
  );
  const availableStudents = students.filter((student) =>
    student.id && !enrolledIds.has(student.id) &&
    `${student.name} ${student.id} ${student.email || ""}`.toLowerCase().includes(normalizedMemberSearch),
  );
  const availableStudentCount = students.filter((student) => student.id && !enrolledIds.has(student.id)).length;

  async function changeMembership(student: TeacherClassMember, action: "add" | "remove") {
    if (!student.id || memberBusyId) return;
    setMemberBusyId(student.id);
    setMembersError("");
    setMembersNotice("");
    try {
      if (action === "add") {
        const added = await teacherService.addClassMember(classId, { student_id: student.id });
        setMembers((current) => [added, ...current]);
        setMembersNotice(`${student.name} added to this class.`);
      } else {
        await teacherService.removeClassMember(classId, student.id);
        setMembers((current) => current.filter((member) => member.id !== student.id));
        setMembersNotice(`${student.name} removed from this class.`);
      }
      setRecord((current) => current ? { ...current, students: Math.max(0, current.students + (action === "add" ? 1 : -1)) } : current);
    } catch (error) {
      setMembersError(error instanceof Error ? error.message : `Could not ${action} this student.`);
    } finally {
      setMemberBusyId("");
    }
  }

  async function save(event: FormEvent) {
    event.preventDefault();
    if (saving) return;
    if (!startDate.trim() || !endDate.trim()) { setNotice("Class dates are required."); return; }
    if (endDate < startDate) { setNotice("End date must be after the start date."); return; }
    setSaving(true);
    setNotice("");
    try {
      await adminService.updateClass(currentRecord.id, { course, semester, lecturerId: lecturer, status, startDate, endDate });
      navigate("/admin/courses", { state: { selectedId: currentRecord.id, notice: `${currentRecord.id} class details saved.` } });
    } catch (err) {
      setNotice(err instanceof Error ? err.message : "Could not save class details.");
    } finally {
      setSaving(false);
    }
  }

  return <div className="admin-page">
    <div className="admin-edit-layout admin-class-edit-layout">
    <form className="admin-edit-form admin-class-form" onSubmit={save}>
      <h2>{currentRecord.id} <TranslatedText>{tr("· Class details")}</TranslatedText></h2>
      <Field label={tr("CLASS ID")}><input value={currentRecord.id} readOnly /><small className="admin-muted"><TranslatedText>{tr("Class IDs stay fixed so existing student work keeps its link.")}</TranslatedText></small></Field>
      <Field label={tr("COURSE")}><input value={course} onChange={(event) => setCourse(event.target.value)} /></Field>
      <Field label={tr("SEMESTER")}><Select value={semester} onChange={(event) => setSemester(event.target.value)}>
        {academicTerms(semester).map(t => (
          <option key={t} value={t}>{formatAcademicTerm(t)}</option>
        ))}
      </Select></Field>
      <Field label={tr("LECTURER")}><Select value={lecturer} onChange={(event) => setLecturer(event.target.value)}><option value="Unassigned">{tr("Unassigned")}</option>{lecturers.map((l) => <option key={l.id} value={l.id}>{l.name}</option>)}</Select></Field>
      <Field label={tr("CLASS STATUS")}><Select value={status} onChange={(event) => setStatus(event.target.value as AdminClass["status"])}><option value="Draft">{tr("Draft")}</option><option value="Active">{tr("Active")}</option><option value="Archived">{tr("Archived")}</option></Select></Field>
      {notice && <Notice><TranslatedText>{tr(notice)}</TranslatedText></Notice>}
      <div className="admin-class-form-bottom">
        <div className="admin-class-date-row">
          <Field label={tr("START DATE")}><input type="date" value={startDate} onChange={(event) => setStartDate(event.target.value)} required /></Field>
          <Field label={tr("END DATE")}><input type="date" value={endDate} onChange={(event) => setEndDate(event.target.value)} required /></Field>
        </div>
        <div className="admin-edit-actions admin-class-inline-actions"><Link className="button admin-button" to="/admin/courses"><TranslatedText>{tr("Cancel")}</TranslatedText></Link><button className="button primary admin-button" disabled={saving}><TranslatedText>{saving ? tr("Saving…") : tr("Save class")}</TranslatedText></button></div>
      </div>
    </form>
    <aside className="admin-edit-form admin-edit-members" aria-labelledby="admin-class-members-title">
      <div className="admin-members-heading">
        <div><h2 id="admin-class-members-title"><TranslatedText>{tr("Manage members")}</TranslatedText></h2><p className="admin-muted"><TranslatedText>{tr("Add students to or remove them from this class.")}</TranslatedText></p></div>
      </div>
      {membersError && <Notice><TranslatedText>{tr(membersError)}</TranslatedText></Notice>}
      {membersNotice && <p className="admin-member-notice" role="status"><TranslatedText>{tr(membersNotice)}</TranslatedText></p>}
      {membersLoading ? <Loading variant="table" columns={4} label={tr("Loading class members…")} /> : <>
        <Field label={tr("SEARCH STUDENTS")}><input value={memberSearch} onChange={(event) => setMemberSearch(event.target.value)} placeholder={tr("Search by name, ID, or email...")} /></Field>
        <div className="admin-member-tabs" role="tablist" aria-label={tr("Manage class members")}>
          <button id="remove-students-tab" type="button" role="tab" aria-selected={memberTab === "remove"} aria-controls="remove-students-panel" className={memberTab === "remove" ? "is-active" : ""} onClick={() => setMemberTab("remove")}><TranslatedText>{tr("Remove students")}</TranslatedText> <span>{members.length}</span></button>
          <button id="add-students-tab" type="button" role="tab" aria-selected={memberTab === "add"} aria-controls="add-students-panel" className={memberTab === "add" ? "is-active" : ""} onClick={() => setMemberTab("add")}><TranslatedText>{tr("Add students")}</TranslatedText> <span>{availableStudentCount}</span></button>
          <button id="import-students-tab" type="button" role="tab" aria-selected={false} className="" onClick={() => setBulkImportDialog(true)}><TranslatedText>{tr("Import CSV")}</TranslatedText> <span>+</span></button>
        </div>
        {memberTab === "remove" ? <section id="remove-students-panel" className="admin-member-list" role="tabpanel" aria-labelledby="remove-students-tab">
          {filteredMembers.map((member) => <div className="admin-member-row" key={member.id || member.email}>
            <div><strong>{member.name}</strong><small>{member.email}</small></div>
            <button className="admin-member-remove" type="button" disabled={Boolean(memberBusyId)} onClick={() => void changeMembership(member, "remove")}><TranslatedText>{memberBusyId === member.id ? "…" : tr("Remove")}</TranslatedText></button>
          </div>)}
          {!filteredMembers.length && <p className="admin-member-empty"><TranslatedText>{members.length ? tr("No enrolled students match this search.") : tr("No students are enrolled yet.")}</TranslatedText></p>}
        </section> : <section id="add-students-panel" className="admin-member-list" role="tabpanel" aria-labelledby="add-students-tab">
          {availableStudents.map((student) => <div className="admin-member-row" key={student.id}>
            <div><strong>{student.name}</strong><small>{student.email}</small></div>
            <button className="admin-member-add" type="button" disabled={Boolean(memberBusyId)} onClick={() => void changeMembership(student, "add")}><TranslatedText>{memberBusyId === student.id ? "…" : tr("Add")}</TranslatedText></button>
          </div>)}
          {!availableStudents.length && <p className="admin-member-empty"><TranslatedText>{students.every((student) => !student.id || enrolledIds.has(student.id)) ? tr("All students are already in this class.") : tr("No available students match this search.")}</TranslatedText></p>}
        </section>}
      </>}
    </aside>
    </div>
    {bulkImportDialog && <BulkImportMembersDialog classId={classId} onClose={() => setBulkImportDialog(false)} onSuccess={(msg) => { setBulkImportDialog(false); setMembersNotice(msg); teacherService.getClassMembers(classId).then((data) => setMembers(data)).catch(() => {}); }} />}
  </div>;
}
