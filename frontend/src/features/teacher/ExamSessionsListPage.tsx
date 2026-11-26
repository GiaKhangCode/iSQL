import {ListModeSwitch} from "@/features/teacher/components/ListModeSwitch";
import {apiFetch} from "@/shared/api/apiClient";
import { useListOrder } from "@/shared/components/ListSortSelect";
import { useListDateFilters } from "@/shared/components/ListDateFilters";
import { matchesListSearch } from "@/shared/lib/listSearch";
import { assignmentClassNames } from "./filters/AssignmentFilters";
import { Table, TableRow } from "@/shared/ui/Table";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import { useLanguage } from "@/shared/context/LanguageContext";
import { useMemo, useState, useEffect, useRef } from "react";
import { Link, useLocation, useNavigate, useSearchParams } from "react-router-dom";
import { useLoad } from "@/shared/hooks/useLoad";
import { ErrorState, Status } from "@/shared/ui/ui";
import { teacherService } from "@/shared/api/teacherService";
import { ListFilter } from "lucide-react";
import { TeacherListFilters } from "@/shared/components/TeacherListFilters";
import { TeacherListPagination } from "@/shared/components/TeacherListPagination";
import { TeacherListLoading } from "@/shared/components/TeacherListLoading";
import { parseServerDateTime } from "@/shared/lib/datetime/serverDateTime";
import { examSessions, type SessionPlan } from "@/shared/api/examSessions";
import { notify } from "@/shared/ui/notify";

type ExamSession = {
  id: string; title: string; classes: string; problems: number; opens?: string;
  closes?: string; submitted: string; status: string; isExamSession: boolean;
  shortDescription: string; eligibleStudents: number;
};
const examSessionStatus = (status: string) => status === "Scheduled" ? "Upcoming" : status === "Open" ? "Live" : status;
const dateParts = (value?: string, locale = "en-US") => {
  if (!value) return "—";
  const parsed = parseServerDateTime(value);
  return Number.isFinite(parsed.getTime()) ? {
    date: parsed.toLocaleDateString(locale),
    time: parsed.toLocaleTimeString(locale, { hour: "numeric", minute: "2-digit" }),
  } : "—";
};
const date = (value?: string, locale = "en-US") => {
  const parts = dateParts(value, locale);
  return typeof parts === "string" ? parts : `${parts.date} · ${parts.time}`;
};
const tableDateTime = (value?: string, locale = "en-US") => {
  const parts = dateParts(value, locale);
  return typeof parts === "string" ? parts : <span className="teacher-exam-session-datetime"><span>{parts.date}</span><small>{parts.time}</small></span>;
};
const duration = (start?: string, end?: string) => {
  if (!start || !end) return "—";
  const minutes = Math.round((parseServerDateTime(end).getTime() - parseServerDateTime(start).getTime()) / 60000);
  return Number.isFinite(minutes) && minutes > 0 ? `${minutes} min` : "—";
};

export function ExamSessionsListPage({onBackToExaminations}:{onBackToExaminations?:()=>void} = {}) {
  const order=useListOrder();
  const dates = useListDateFilters();
  const navigate=useNavigate();
  const [params,setParams]=useSearchParams();
  const examinationId=params.get("examinationId") || "";
  const requestedSearch=params.get("search") || "";
  const root=useLocation().pathname.startsWith("/admin/")?"/admin":useLocation().pathname.startsWith("/office/")?"/office":"/teacher";
  const { t: tr, locale: uiLocale } = useLanguage("teacher");
  const { data, loading, error } = useLoad(teacherService.getAssignments);
  const [search, setSearch] = useState(requestedSearch);
  useEffect(()=>{setSearch(requestedSearch);setStatus("All statuses");setClassFilter("");},[requestedSearch,examinationId]);
  const [classFilter, setClassFilter] = useState("");
  const [status, setStatus] = useState("All statuses");
  const [selectedId, setSelectedId] = useState("");
  const [actionsTarget, setActionsTarget] = useState<HTMLDivElement | null>(null);
  const [page, setPage] = useState(1);
  useEffect(()=>setPage(1),[dates.key,order.value]);
  const tableScrollRef = useRef<HTMLDivElement>(null);
  const examSessionList = useMemo(() => ((data || []) as (ExamSession & {examinationId?:string})[]).filter(item => item.isExamSession && (examinationId ? item.examinationId===examinationId : Boolean(item.examinationId))), [data,examinationId]);
  const filteredRows = examSessionList.filter(item => dates.matches(item) && matchesListSearch(search,item.id,item.title,item.classes,item.shortDescription) && (!classFilter || assignmentClassNames(item.classes).includes(classFilter)) && (status === "All statuses" || examSessionStatus(item.status) === status));
  const filtered=order.apply(filteredRows,item=>item.opens||item.id);
  const pageCount = Math.max(1, Math.ceil(filtered.length / 15));
  const currentPage = Math.min(page, pageCount);
  const firstIndex = (currentPage - 1) * 15;
  const pageExamSessions = filtered.slice(firstIndex, firstIndex + 15);
  const selected = pageExamSessions.find(item => item.id === selectedId) || pageExamSessions[0];
  useEffect(() => {
    setPage(1);
    setSelectedId("");
  }, [search, status, classFilter]);
  useEffect(() => {
    tableScrollRef.current?.scrollTo({ top: 0 });
  }, [currentPage, search, status, classFilter]);
  const [plan,setPlan]=useState<SessionPlan|null>(null);
  const [confirmBusy,setConfirmBusy]=useState(false);const [planError,setPlanError]=useState("");
  useEffect(()=>{let active=true;setPlan(null);setPlanError("");if(selected?.id)examSessions.plan(selected.id).then(value=>{if(active)setPlan(value);}).catch(error=>{if(active)setPlanError(error.message);});return()=>{active=false;};},[selected?.id]);
  const confirmSelected=async()=>{if(!selected||!plan||!window.confirm(`Xác nhận ${selected.title}: ${plan.students.length} sinh viên, ${plan.classes.length} lớp? Đề, lịch và phân công giám thị sẽ bị khóa.`))return;setConfirmBusy(true);setPlanError("");try{setPlan(await examSessions.confirm(selected.id));notify.success("Đã xác nhận ca thi",selected.title);}catch(error){notify.fail("Không thể xác nhận ca thi.",error);}finally{setConfirmBusy(false);}};
  const reopenSelected=async()=>{if(!selected)return;const reason=window.prompt("Lý do mở lại ca thi (ít nhất 5 ký tự):")?.trim();if(!reason)return;setConfirmBusy(true);setPlanError("");try{setPlan(await examSessions.reopen(selected.id,reason));notify.success("Đã mở lại ca thi",selected.title);}catch(error){notify.fail("Không thể mở lại ca thi.",error);}finally{setConfirmBusy(false);}};
  const editPath=(item:ExamSession)=>`${root}/sessions/${item.id}/edit`;
  const chooseExamSession=(item:ExamSession)=>{if(selected?.id===item.id)navigate(editPath(item));else setSelectedId(item.id);};
  return <section className="teacher-page teacher-list-page teacher-exam-session-page compact-teacher-exam-sessions">
    <h1 className="sr-only"><TranslatedText>{tr("Exam sessions")}</TranslatedText></h1>
    {loading ? <TeacherListLoading label={tr("Loading…")} /> : error ? <ErrorState title={tr("Could not load exam sessions")} message={tr(error)} onRetry={() => window.location.reload()} /> : <div className="teacher-list-detail-layout teacher-filter-layout">
        <div className="teacher-list-detail-main">
          <TeacherListFilters key={examinationId} defaultOpen={false} order={order} suggestions={filtered.map(item=>({id:item.id,title:item.title,description:`${item.classes} · ${tr(examSessionStatus(item.status))}`}))} dates={dates} search={search} onSearchChange={setSearch} searchLabel={tr("Search exam sessions…")}
            onReset={()=>setParams(previous=>{const next=new URLSearchParams(previous);next.delete("examinationId");next.delete("search");return next;},{replace:true})}
            actionsTarget={actionsTarget}
            primaryAction={onBackToExaminations?<ListModeSwitch rooms collapseSecondary labels={["Examinations","Exam sessions"]} onChange={next=>{if(!next)onBackToExaminations();}}/>:undefined}
            filters={[{ key: "status", label: tr("STATUS"), value: status, defaultValue: "All statuses", onChange: setStatus,
              icon: <ListFilter size={17} aria-hidden="true" />, options: ["All statuses", "Live", "Upcoming", "Closed", "Draft"].map(value => ({ value, label: tr(value) })) },{key:"class",label:tr("CLASS"),value:classFilter,defaultValue:"",onChange:setClassFilter,icon:<ListFilter size={17}/>,options:[{value:"",label:tr("All classes")},...Array.from(new Set(examSessionList.flatMap(item=>assignmentClassNames(item.classes)))).sort().map(value=>({value,label:value}))]}]} />
          <div ref={tableScrollRef} className="teacher-table-scroll teacher-list-table-scroll sticky-list-table-wrap"><Table className="teacher-table teacher-exam-session-list-table sticky-list-table"><thead><tr><th><TranslatedText>{tr("ID")}</TranslatedText></th><th><TranslatedText>{tr("EXAM SESSION")}</TranslatedText></th><th><TranslatedText>{tr("AUDIENCE")}</TranslatedText></th><th><TranslatedText>{tr("STARTS")}</TranslatedText></th><th><TranslatedText>{tr("ENDS")}</TranslatedText></th><th><TranslatedText>{tr("STATUS")}</TranslatedText></th></tr></thead><tbody>
          {pageExamSessions.map((item,index) => <TableRow selected={selected?.id === item.id} key={item.id} className={selected?.id === item.id ? "is-selected" : ""} onClick={() => chooseExamSession(item)} onDoubleClick={() => navigate(editPath(item))}>
            <td data-label={tr("ID")}>{firstIndex + index + 1}</td>
            <td data-label={tr("EXAM SESSION")}><button type="button" className="teacher-selectable-row-title" onClick={event=>{event.stopPropagation();chooseExamSession(item);}}>{item.title}</button></td>
            <td data-label={tr("AUDIENCE")}>{item.classes}</td>
            <td data-label={tr("STARTS")}>{tableDateTime(item.opens, uiLocale)}</td>
            <td data-label={tr("ENDS")}>{tableDateTime(item.closes, uiLocale)}</td>
            <td data-label={tr("STATUS")}><Status value={examSessionStatus(item.status)} /></td>
          </TableRow>)}
          {!filtered.length && <tr><td className="teacher-empty-row" colSpan={6}><TranslatedText>{tr(examSessionList.length ? "No exam sessions match these filters." : "No exam sessions yet")}</TranslatedText></td></tr>}
        </tbody></Table></div>
          <TeacherListPagination
            summary={<><TranslatedText>{tr("Showing")}</TranslatedText> {filtered.length ? firstIndex + 1 : 0}–{firstIndex + pageExamSessions.length} <TranslatedText>{tr("of")}</TranslatedText> {filtered.length} <TranslatedText>{tr("exam-sessions")}</TranslatedText></>}
            paginationLabel={tr("Exam sessions pagination")}
            pageIndicator={<><TranslatedText>{tr("Page")}</TranslatedText> {currentPage} <TranslatedText>{tr("of")}</TranslatedText> {pageCount}</>}
            previousLabel={<TranslatedText>{tr("← Previous")}</TranslatedText>}
            nextLabel={<TranslatedText>{tr("Next →")}</TranslatedText>}
            page={currentPage}
            pageCount={pageCount}
            onPageChange={nextPage => { setPage(nextPage); setSelectedId(""); }}
          />
        </div>
        <div className="teacher-filter-side"><div ref={setActionsTarget} className="teacher-filter-actions" />
        <aside className="teacher-list-detail-panel">{selected ? <><span className="teacher-detail-eyebrow"><TranslatedText>{tr("EXAM SESSION DETAILS")}</TranslatedText></span><h2>{selected.title}</h2><p className="muted">{selected.shortDescription}</p><p className="muted">{selected.classes}</p>
          <dl className="teacher-list-detail-facts"><div><dt><TranslatedText>{tr("Starts")}</TranslatedText></dt><dd>{date(selected.opens, uiLocale)}</dd></div><div><dt><TranslatedText>{tr("Ends")}</TranslatedText></dt><dd>{date(selected.closes, uiLocale)}</dd></div><div><dt><TranslatedText>{tr("Duration")}</TranslatedText></dt><dd><TranslatedText>{tr(duration(selected.opens, selected.closes))}</TranslatedText></dd></div><div><dt><TranslatedText>{tr("Problems")}</TranslatedText></dt><dd>{selected.problems}</dd></div><div><dt><TranslatedText>{tr("Eligible students")}</TranslatedText></dt><dd>{selected.eligibleStudents}</dd></div><div><dt><TranslatedText>{tr("Problem submitters / eligible")}</TranslatedText></dt><dd>{selected.submitted}</dd></div></dl>
          {plan&&!plan.confirmedAt&&<button type="button" className="button" disabled={confirmBusy} onClick={()=>void confirmSelected()}><TranslatedText>{tr("Confirm exam session")}</TranslatedText></button>}
          {plan?.confirmedAt&&<button type="button" className="button" disabled={confirmBusy} onClick={()=>void reopenSelected()}><TranslatedText>{tr("Reopen exam session")}</TranslatedText></button>}
          {planError&&<p role="alert">{planError}</p>}
          <div className="teacher-list-detail-actions">{plan?.confirmedAt?<Link className="button primary" to={`${root}/examinations/${plan.examinationId}/admissions?sessionId=${selected.id}`}><TranslatedText>{tr("Issue / manage credentials")}</TranslatedText></Link>:<Link className="button primary" to={editPath(selected)}><TranslatedText>{tr("Edit")}</TranslatedText></Link>}<Link className="button" to={`${root}/results/activity/${encodeURIComponent(selected.id)}`}><TranslatedText>{tr("View submissions")}</TranslatedText></Link></div>
        </> : <p className="muted"><TranslatedText>{tr("Select an exam session to view details.")}</TranslatedText></p>}</aside>
        </div>
      </div>}
  </section>;
}
