import { useListOrder, ListSortSelect } from "@/shared/components/ListSortSelect";
import { ListSearchInput } from "@/shared/components/ListSearchInput";
import { useListDateFilters, ListDateFilters } from "@/shared/components/ListDateFilters";
import { matchesListSearch } from "@/shared/lib/listSearch";
import { formatAcademicTerm } from "@/shared/lib/datetime/academicTerms";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import { useLanguage } from "@/shared/context/LanguageContext";
import { useState, useEffect } from "react";
import { Link, useSearchParams } from "react-router-dom";
import { studentApi } from "@/features/student/studentApi";
import { type Deadline } from "@/data/models";
import { useLoad } from "@/shared/hooks/useLoad";
import { Dialog, Empty, ErrorState, Loading, Status } from "@/shared/ui/ui";
import { Calendar } from "@/shared/components/Calendar";
const CLASS_PAGE_SIZE = 6;

function dateLabel(date: string, locale: string) {
  return new Date(date + "T00:00:00Z").toLocaleDateString(locale, {
    month: "short",
    day: "numeric",
    timeZone: "UTC",
  });
}
function AssignmentCalendar({
  items,
  onOpen,
}: {
  items: Deadline[];
  onOpen: () => void;
}) {
  const { t: tr, locale: uiLocale } = useLanguage("student");
  return (
    <div className="assignment-calendar-content">
      <Calendar items={items} />
      <h3><TranslatedText>{tr("Upcoming deadlines ·")}</TranslatedText> {items.length}</h3>
      <div
        className="assignment-deadline-scroll"
        tabIndex={0}
        aria-label={tr("Upcoming assignment deadlines")}
      >
        {items.map((item) => (
          <Link key={item.id} to={item.to} onClick={onOpen}>
            <small>
              {dateLabel(item.date, uiLocale)} · {item.time}
            </small>
            <b>{item.title}</b>
            <small>{item.context}</small>
          </Link>
        ))}
      </div>
    </div>
  );
}
export function AssignmentsPage() {
  const order=useListOrder();
  const dates=useListDateFilters();
  const { t: tr, locale: uiLocale } = useLanguage("student");
  const { data, loading, error } = useLoad(studentApi.getAssignments);
  const [search, setSearch] = useState("");
  const [classPage, setClassPage] = useState(1);
  useEffect(()=>setClassPage(1),[order.value,dates.key]);
  const [params, setParams] = useSearchParams();
  const [calendarOpen, setCalendarOpen] = useState(false);
  if (loading) return <section className="page assignments-page compact-tables-page compact-student-assignments"><Loading variant="cards" columns={2} rows={4} sidebar label={tr("Loading assignments…")} /></section>;
  if (error || !data) return <section className="page assignments-page compact-tables-page compact-student-assignments"><ErrorState title={tr("Assignments unavailable")} message={tr(error) || tr("Could not load assignments.")} onRetry={() => window.location.reload()} /></section>;
  const assignmentDeadlines = data.deadlines.filter(
    (item) => item.kind === "Assignment",
  );
  const selectedWork = data.assignments.find(
    (a) => a.id === params.get("work"),
  );
  const cards = data.classes.map((c) => ({
    ...c,
    context: c.code,
    term: c.code.includes(" · ") ? c.code.slice(c.code.lastIndexOf(" · ") + 3) : c.code,
    description: c.lecturer + " · " + tr("Lecturer"),
    work: data.assignments.filter((a) => a.classIds.includes(c.id)),
  }));
  const filteredRows = cards.filter((c) =>
    dates.matches(c) && matchesListSearch(search,c.id,c.name,c.context,c.description),
  );
  const filtered=order.apply(filteredRows,item=>item.createdAt || item.name || "");
  const classPageCount = Math.max(1, Math.ceil(filtered.length / CLASS_PAGE_SIZE));
  const currentClassPage = Math.min(classPage, classPageCount);
  const pageClasses = filtered.slice((currentClassPage - 1) * CLASS_PAGE_SIZE, currentClassPage * CLASS_PAGE_SIZE);
  return (
    <section className="page assignments-page compact-tables-page compact-student-assignments">
      <h1 className="sr-only"><TranslatedText>{tr("Assignments")}</TranslatedText></h1>
      <div className="assignment-mobile-deadlines">
        <b>
          <TranslatedText>{tr("Deadlines · Next:")}</TranslatedText>{" "}
          <TranslatedText>{assignmentDeadlines[0] ? dateLabel(assignmentDeadlines[0].date, uiLocale) : tr("None")}</TranslatedText>
        </b>
        <button className="text-button" onClick={() => setCalendarOpen(true)}>
          <TranslatedText>{tr("Calendar ›")}</TranslatedText>
        </button>
      </div>
      <div className="assignments-layout">
        <div className="assignment-class-panel">
          <div className="scope-toolbar">
            <h2 className="class-list-label"><TranslatedText>{tr("Class List")}</TranslatedText></h2>
            <label className="sr-only" htmlFor="scope-search">
              <TranslatedText>{tr("Search classes")}</TranslatedText>
            </label>
            <ListSearchInput value={search} onChange={value=>{setSearch(value);setClassPage(1);}} label={tr("Search classes")} items={filtered.map(c=>({id:c.id,title:c.name,value:c.id,description:c.context}))}/>
            <ListSortSelect order={order}/><ListDateFilters dates={dates}/>
            <nav className="class-list-pagination" aria-label={tr("Class pages")}>
              <button type="button" className="button" disabled={currentClassPage === 1} onClick={() => setClassPage(currentClassPage - 1)}><TranslatedText>{tr("← Previous")}</TranslatedText></button>
              <span aria-live="polite"><TranslatedText>{tr("Page")}</TranslatedText> {currentClassPage} <TranslatedText>{tr("of")}</TranslatedText> {classPageCount}</span>
              <button type="button" className="button" disabled={currentClassPage === classPageCount} onClick={() => setClassPage(currentClassPage + 1)}><TranslatedText>{tr("Next →")}</TranslatedText></button>
            </nav>
          </div>
          <div
            className="class-grid-scroll"
            tabIndex={0}
            aria-label={tr("Class list")}
          >
            <div className="class-grid">
              {pageClasses.map((c) => {
                const nowMs = Date.now();
                const pending = c.work
                  .filter((a) => a.status !== "Solved" && (!a.closes || Date.parse(a.closes) >= nowMs))
                  .sort((a, b) => a.date.localeCompare(b.date));
                return (
                  <Link
                    className="class-card"
                    key={c.id}
                    to={"/assignments/classes/" + c.id}
                  >
                    <div className="class-card-top">
                      <span className="class-glyph">{c.name[0]}</span>
                    </div>
                    <div className="class-card-identity">
                      <small className="class-card-term">{formatAcademicTerm(c.term)}</small>
                      <h2 title={c.name}>{c.name}</h2>
                      <p>{c.description}</p>
                      <span className="class-mobile-summary">
                        {pending.length} <TranslatedText>{tr("pending")}</TranslatedText>
                      </span>
                    </div>
                    <div className="class-card-footer">
                      <div>
                        <b>
                          {pending.length} <TranslatedText>{tr("pending assignment")}</TranslatedText>
                          <TranslatedText>{pending.length === 1 ? "" : tr("s")}</TranslatedText>
                        </b>
                        <small>
                          <span className="class-next-prefix"><TranslatedText>{tr("Next due ·")}</TranslatedText> </span>
                          <TranslatedText>{pending[0] ? dateLabel(pending[0].date, uiLocale) +
                              " · " +
                              pending[0].time : tr("None")}</TranslatedText>
                        </small>
                      </div>
                      <span className="class-desktop-arrow">↗</span>
                      <span className="class-mobile-arrow">›</span>
                    </div>
                  </Link>
                );
              })}
            </div>
            {!filtered.length && (
              <Empty title={tr("No matching classes")} />
            )}
          </div>
        </div>
        <aside className="deadline-panel assignment-deadline-panel">
          <AssignmentCalendar
            items={assignmentDeadlines}
            onOpen={() => setCalendarOpen(false)}
          />
        </aside>
      </div>
      {calendarOpen && (
        <Dialog
          title={tr("Calendar")}
          className="assignment-calendar-dialog"
          onClose={() => setCalendarOpen(false)}
        >
          <AssignmentCalendar
            items={assignmentDeadlines}
            onOpen={() => setCalendarOpen(false)}
          />
        </Dialog>
      )}
      {selectedWork && (
        <Dialog
          title={selectedWork.title}
          onClose={() => setParams({})}
        >
          <Status value={selectedWork.status} />
          <p className="tiny muted">
            {data.classes.filter((c) => selectedWork.classIds.includes(c.id)).map((c) => c.name).join(", ")}
            <TranslatedText>{tr("· Due")}</TranslatedText>{dateLabel(selectedWork.date, uiLocale)} {selectedWork.time}
          </p>
          {selectedWork.problemIds.map((id) => (
            <Link
              className="assigned-problem"
              key={id}
              to={
                "/workspace/" +
                id +
                "?source=Assignments&context=" +
                encodeURIComponent(selectedWork.id) +
                "&contextTitle=" +
                encodeURIComponent(selectedWork.title)
              }
            >
              <TranslatedText>{data.problems?.find((p) => p.id === id)?.title || tr("SQL problem")}</TranslatedText>
              <span><TranslatedText>{tr("Open problem →")}</TranslatedText></span>
            </Link>
          ))}
        </Dialog>
      )}
    </section>
  );
}
