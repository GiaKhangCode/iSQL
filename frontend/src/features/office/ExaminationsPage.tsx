import {useLocation as useManagementLocation} from "react-router-dom";
import { useListOrder } from "@/shared/components/ListSortSelect";
import { useListDateFilters } from "@/shared/components/ListDateFilters";
import {useEffect, useMemo, useRef, useState} from "react";
import {Link,useNavigate} from "react-router-dom";
import {CalendarDays, ClipboardList, Plus, Users} from "lucide-react";
import {Table, TableRow} from "@/shared/ui/Table";
import {useLoad} from "@/shared/hooks/useLoad";
import {TranslatedText} from "@/shared/ui/TranslatedText";
import {ErrorState} from "@/shared/ui/ui";
import {useLanguage} from "@/shared/context/LanguageContext";
import {teacherService} from "@/shared/api/teacherService";
import {TeacherListFilters} from "@/shared/components/TeacherListFilters";
import {TeacherListLoading} from "@/shared/components/TeacherListLoading";
import {TeacherListPagination} from "@/shared/components/TeacherListPagination";
import {parseServerDateTime} from "@/shared/lib/datetime/serverDateTime";
import "@/styles/examinations.css";

function formattedDate(value: string, locale: string) {
  const date = new Date(`${value}T12:00:00`);
  return Number.isFinite(date.getTime()) ? date.toLocaleDateString(locale) : value;
}

export function ExaminationsPage() {
  return <div className="office-exam-dashboard">
    <ExaminationList />
  </div>;
}

function ExaminationList() {
  const navigate=useNavigate();
  const order=useListOrder();
  const dates = useListDateFilters();
  const managementRoot=useManagementLocation().pathname.startsWith("/admin/")?"/admin":"/office";
  const {t, locale} = useLanguage("teacher");
  const {data, loading, error} = useLoad(teacherService.getExaminations);
  const [search, setSearch] = useState("");
  const [selectedId, setSelectedId] = useState("");
  const [page,setPage]=useState(1);
  const tableScroll=useRef<HTMLDivElement>(null);
  const examinationsRows = useMemo(() => (data || []).filter(exam =>
    dates.matches(exam) && `${exam.title} ${exam.examDate}`.toLocaleLowerCase().includes(search.trim().toLocaleLowerCase()),
  ), [data, search, dates.key]);
  const examinations=order.apply(examinationsRows,item=>item.examDate||item.createdAt||item.id);
  const pageCount=Math.max(1,Math.ceil(examinations.length/15));
  const currentPage=Math.min(page,pageCount);
  const first=(currentPage-1)*15;
  const rows=examinations.slice(first,first+15);
  const selected = rows.find(exam => exam.id === selectedId) || rows[0];
  useEffect(()=>{setPage(1);setSelectedId("");},[search,dates.key,order.value]);
  useEffect(()=>{tableScroll.current?.scrollTo({top:0});},[currentPage,search,dates.key,order.value]);
  const createdDate=(value:string)=>{
    const date=value?parseServerDateTime(value):null;
    return date && Number.isFinite(date.getTime()) ? date.toLocaleDateString(locale,{timeZone:"Asia/Ho_Chi_Minh",day:"2-digit",month:"2-digit",year:"numeric"}) : "—";
  };
  const [actionsTarget, setActionsTarget] = useState<HTMLDivElement | null>(null);
  const chooseExamination=(exam:{id:string})=>{if(selected?.id===exam.id)navigate(`${managementRoot}/examinations/${exam.id}/edit`);else setSelectedId(exam.id);};
  const sessionsPath=(exam:{id:string;title:string})=>`${managementRoot}/sessions?${new URLSearchParams({examinationId:exam.id,search:exam.title})}`;

  if (loading) return <section className="teacher-page office-examinations-page"><TeacherListLoading label={t("Loading examinations…")} columns={6} createLabel={t("New examination")} createTo={`${managementRoot}/examinations/new`} /></section>;
  if (error) return <section className="teacher-page office-examinations-page"><ErrorState title={t("Could not load examinations")} message={error} onRetry={() => window.location.reload()} /></section>;

  return <section className="teacher-page office-examinations-page compact-teacher-examinations">
    <h1 className="sr-only"><TranslatedText>{t("Examinations")}</TranslatedText></h1>
    <div className="teacher-list-detail-layout teacher-filter-layout office-examination-layout">
      <div className="teacher-list-detail-main">
        <TeacherListFilters order={order} suggestions={examinations.map(item=>({id:item.id,title:item.title,description:item.examDate}))} dates={dates} search={search} onSearchChange={value => {setSearch(value); setSelectedId("");}}
          searchLabel={t("Search examinations…")} actionsTarget={actionsTarget}
          createLabel={t("New examination")}
          createTo={`${managementRoot}/examinations/new`} />
        <div ref={tableScroll} className="teacher-table-scroll teacher-list-table-scroll office-examination-table-scroll sticky-list-table-wrap">
          <Table className="teacher-table office-examination-table sticky-list-table">
            <thead><tr>
              <th>ID</th>
              <th><TranslatedText>{t("EXAMINATION")}</TranslatedText></th>
              <th><TranslatedText>{t("Created date")}</TranslatedText></th>
              <th><TranslatedText>{t("EXAM DATE")}</TranslatedText></th>
              <th><TranslatedText>{t("SESSIONS")}</TranslatedText></th>
              <th><TranslatedText>{t("STATUS")}</TranslatedText></th>
            </tr></thead>
            <tbody>
              {rows.map((exam,index) => {
                const published = exam.sessions.filter(session => session.published).length;
                return <TableRow key={exam.id} selected={selected?.id === exam.id}
                  className="teacher-selectable-list-row" onClick={() => chooseExamination(exam)} onDoubleClick={() => navigate(`${managementRoot}/examinations/${exam.id}/edit`)}>
                  <td data-label="ID">{first+index+1}</td>
                  <td data-label={t("EXAMINATION")}><button type="button" className="teacher-selectable-row-title" onClick={event => {event.stopPropagation();chooseExamination(exam);}}>{exam.title}</button></td>
                  <td data-label={t("Created date")}>{createdDate(exam.createdAt)}</td>
                  <td data-label={t("EXAM DATE")}>{formattedDate(exam.examDate, locale)}</td>
                  <td data-label={t("SESSIONS")}>{exam.sessions.length}</td>
                  <td data-label={t("STATUS")}><span className={`office-examination-status${published ? " is-published" : ""}`}><TranslatedText>{t(published ? "Published" : "Draft")}</TranslatedText></span></td>
                </TableRow>;
              })}
              {!examinations.length && <tr><td className="teacher-empty-row" colSpan={6}><TranslatedText>{t(data?.length ? "No examinations match your search." : "No examinations yet. Create one to generate four session drafts.")}</TranslatedText></td></tr>}
            </tbody>
          </Table>
        </div>
        <TeacherListPagination summary={<><TranslatedText>{t("Showing")}</TranslatedText> {rows.length?first+1:0}–{first+rows.length} <TranslatedText>{t("of")}</TranslatedText> {examinations.length} <TranslatedText>{t("items")}</TranslatedText></>}
          paginationLabel={t("Examinations pagination")} pageIndicator={<><TranslatedText>{t("Page")}</TranslatedText> {currentPage} <TranslatedText>{t("of")}</TranslatedText> {pageCount}</>}
          previousLabel={<TranslatedText>{t("← Previous")}</TranslatedText>} nextLabel={<TranslatedText>{t("Next →")}</TranslatedText>} page={currentPage} pageCount={pageCount} onPageChange={value=>{setPage(value);setSelectedId("");}} />
      </div>
      <div className="teacher-filter-side">
        <div ref={setActionsTarget} className="teacher-filter-actions" />
        <aside className="teacher-list-detail-panel office-examination-detail">
          {selected ? <>
            <div className="office-examination-detail-header"><span className="teacher-detail-eyebrow"><TranslatedText>{t("EXAMINATION DETAILS")}</TranslatedText></span><Link className="button office-examination-info" to={sessionsPath(selected)}><TranslatedText>{t("Session details")}</TranslatedText></Link></div>
            <h2>{selected.title}</h2>
            <p className="muted"><CalendarDays size={14} aria-hidden="true" /> {formattedDate(selected.examDate, locale)}</p>
            <div className="office-examination-sessions">
              {selected.sessions.map(session => <Link className="office-examination-session-row" target="_blank" rel="noopener noreferrer" key={session.id || session.index} to={session.id?`${managementRoot}/sessions/${encodeURIComponent(session.id)}/edit`:`${managementRoot}/examinations/${selected.id}/edit`}>
                <div className="office-examination-session-heading"><strong><TranslatedText>{t("Session {value0}", {value0: session.index})}</TranslatedText></strong><span>{session.starts}–{session.ends}</span></div>
                <div className="office-examination-session-meta">
                  <span><Users size={13} aria-hidden="true" /><TranslatedText>{t("{value0} classes · {value1} students", {value0: session.classIds.length, value1: session.eligibleStudents ?? 0})}</TranslatedText></span>
                  <span className="office-examination-session-status"><ClipboardList size={13} aria-hidden="true" /><TranslatedText>{t(session.published ? "Published" : "Draft session")}</TranslatedText></span>
                </div>
              </Link>)}
            </div>
            <Link className="button office-examination-edit" to={`${managementRoot}/examinations/${selected.id}/edit`}><TranslatedText>{t("Edit")}</TranslatedText></Link>
          </> : <div className="office-examination-empty-detail">
            <CalendarDays size={24} aria-hidden="true" />
            <p><TranslatedText>{t("Select an examination to view its sessions.")}</TranslatedText></p>
            <Link className="button primary" to={`${managementRoot}/examinations/new`}><Plus size={16} /><TranslatedText>{t("New examination")}</TranslatedText></Link>
          </div>}
        </aside>
      </div>
    </div>
  </section>;
}
