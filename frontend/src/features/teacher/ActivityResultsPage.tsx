import { useMemo, useState } from "react";
import { apiFetch } from "@/shared/api/apiClient";
import { formatPoints } from "@/shared/lib/sql/formatPoints";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import { Link, useLocation, useParams, useSearchParams } from "react-router-dom";
import { useLanguage } from "@/shared/context/LanguageContext";
import { useLoad } from "@/shared/hooks/useLoad";
import { Dialog, ErrorState, Loading, Status } from "@/shared/ui/ui";
import { Select } from "@/shared/ui/Select";
import { useAuth } from "@/app/providers/AuthContext";
import { regradeAssignments, type RegradeReviewer } from "@/shared/api/regradeAssignments";
import { TeacherListFilters } from "@/shared/components/TeacherListFilters";
import { TeacherListPagination } from "@/shared/components/TeacherListPagination";
import { Table, TableRow } from "@/shared/ui/Table";
import { teacherService } from "@/shared/api/teacherService";
import { WORKSPACE_PART_LABELS } from "@/shared/lib/workspace/workspaceParts";
import { submissionTimeLabel } from "@/shared/lib/datetime/serverDateTime";
import "@/features/teacher/submissions/SubmissionReviewPage.css";
import "./ActivityResultsPage.css";
import { notify } from "@/shared/ui/notify";

export function ActivityResultsPage() {
  const routeParams = useParams();
  const activityId = routeParams.activityId || routeParams.sessionId;
  const { t: tr, locale } = useLanguage("teacher");
  const location = useLocation();
  const root = location.pathname.startsWith("/admin/") ? "/admin" : location.pathname.startsWith("/office/") ? "/office" : "/teacher";
  const [params, setParams] = useSearchParams();

  const { data, loading, error, mutate: setData } = useLoad(async () => {
    const [activity, submissions] = await Promise.all([
      teacherService.getAssignment(activityId!),
      teacherService.getAssignmentSubmissions(activityId!)
    ]);
    return { activity, submissions };
  }, [activityId]);

  const { permissions } = useAuth();
  // Khớp backend: phân công phúc khảo cần examination.manage; xem cả kỳ thi cần examination.manage hoặc results.view_all.
  const canAssignRegrade = Boolean(permissions?.includes("examination.manage"));
  const seesWholeExam = canAssignRegrade || Boolean(permissions?.includes("results.view_all"));
  const [regradeOpen, setRegradeOpen] = useState(false);
  const [reviewers, setReviewers] = useState<RegradeReviewer[]>([]);
  const [reviewersLoading, setReviewersLoading] = useState(false);
  const [selectedReviewerId, setSelectedReviewerId] = useState("");
  const [regradeNote, setRegradeNote] = useState("");
  const [regradeBusy, setRegradeBusy] = useState(false);
  const [regradeError, setRegradeError] = useState("");
  const [filterTarget, setFilterTarget] = useState<HTMLDivElement | null>(null);
  const [selectedKey, setSelectedKey] = useState("");

  const fromTeacherExams = location.pathname.startsWith("/teacher/exams/");
  const reviewBase = fromTeacherExams ? "/teacher/exams/review" : `${root}/results/review`;
  const isExam = Boolean(data?.activity?.examinationId || data?.activity?.isExamSession || location.pathname.includes("/exams/"));
  const exitPath = fromTeacherExams ? "/teacher/exams" : `${root}/results${isExam ? "?kind=exams" : ""}`;

  const search = params.get("search") || "";
  const status = params.get("status") || "All statuses";
  const classFilter = params.get("class") || "";
  const variantFilter = params.get("variant") || "";

  const setFilter = (key: string, value: string) => setParams(previous => {
    const next = new URLSearchParams(previous);
    if (value) next.set(key, value); else next.delete(key);
    if (key !== "page") next.delete("page");
    return next;
  }, { replace: true });

  const availableClasses = useMemo(() => {
    if (!data?.submissions) return [];
    return Array.from(new Set(data.submissions.map(r => r.className).filter(Boolean) as string[])).sort();
  }, [data?.submissions]);

  const availableVariants = useMemo(() => {
    if (!data?.submissions) return [];
    return Array.from(new Set(data.submissions.map(r => r.variantCode).filter(Boolean) as string[])).sort();
  }, [data?.submissions]);

  const rows = (data?.submissions || []).filter(row => {
    const term = search.trim().toLowerCase();
    const matchSearch = !term || `${row.student} ${row.studentCode || ""} ${row.problem}`.toLowerCase().includes(term);
    const matchClass = !classFilter || row.className === classFilter;
    const matchVariant = !variantFilter || row.variantCode === variantFilter;
    const matchStatus = status === "All statuses" || row.status === status;
    return matchSearch && matchClass && matchVariant && matchStatus;
  });

  const totalPages = Math.max(1, Math.ceil(rows.length / 15));
  const page = Math.min(totalPages, Math.max(1, Math.floor(Number(params.get("page")) || 1)));
  const first = (page - 1) * 15;

  const hasMultipleProblems = useMemo(() => {
    if (!data?.submissions?.length) return false;
    return new Set(data.submissions.map(r => r.problemId || r.problem)).size > 1;
  }, [data?.submissions]);

  const studentStats = useMemo(() => {
    if (!data?.submissions?.length) return { submitted: 0, unsubmitted: 0, average: 0 };
    const byStudent = new Map<string, { hasSubmission: boolean; totalScore: number }>();
    for (const row of data.submissions) {
      const sid = row.studentId || row.student;
      const cur = byStudent.get(sid) || { hasSubmission: false, totalScore: 0 };
      if (row.id) {
        cur.hasSubmission = true;
        cur.totalScore += row.score ?? 0;
      }
      byStudent.set(sid, cur);
    }
    let submitted = 0;
    let unsubmitted = 0;
    let scoreSum = 0;
    for (const st of byStudent.values()) {
      if (st.hasSubmission) {
        submitted++;
        scoreSum += st.totalScore;
      } else {
        unsubmitted++;
      }
    }
    return {
      submitted,
      unsubmitted,
      average: submitted > 0 ? (scoreSum / submitted).toFixed(1) : 0,
    };
  }, [data?.submissions]);

  const rowKey = (row: (typeof rows)[number]) => row.id || `${row.studentId}:${row.problemId}`;
  const unsubmittedBase = fromTeacherExams
    ? `/teacher/exams/${encodeURIComponent(activityId!)}`
    : `${root}/results/activity/${encodeURIComponent(activityId!)}`;
  const workLink = (row: (typeof rows)[number]) => row.id
    ? `${reviewBase}/${encodeURIComponent(row.id)}`
    : `${unsubmittedBase}/students/${encodeURIComponent(row.studentId!)}/problems/${encodeURIComponent(row.problemId!)}/review`;
  const visibleRows = rows.slice(first, first + 15);
  const selected = visibleRows.find(row => rowKey(row) === selectedKey) || visibleRows[0];
  if (loading) {
    return (
      <section className="teacher-page teacher-activity-submissions">
        <Loading variant="table" columns={4} label={tr("Loading submissions…")} />
      </section>
    );
  }

  if (error || !data) {
    return (
      <section className="teacher-page">
        <Link to={exitPath}>
          <TranslatedText>{tr(fromTeacherExams ? "Về danh sách kỳ thi" : "Back to results")}</TranslatedText>
        </Link>
        <ErrorState title={tr("Could not load results")} message={error} />
      </section>
    );
  }

  return (
    <section className="teacher-page teacher-activity-submissions compact-tables-page compact-teacher-results grading-page">
      <h1 className="sr-only">{data.activity.title}</h1>
      <div className="teacher-list-detail-layout teacher-filter-layout">
        <div className="teacher-list-detail-main">
          <TeacherListFilters actionsTarget={filterTarget} search={search} onSearchChange={value => setFilter("search", value)} searchLabel="Tìm sinh viên hoặc MSSV…"
            filters={[
              {key:"class",label:"Lớp",value:classFilter,defaultValue:"",icon:null,onChange:value=>setFilter("class",value),options:[{value:"",label:"Tất cả lớp"},...availableClasses.map(value=>({value,label:value}))]},
              {key:"variant",label:"Mã đề",value:variantFilter,defaultValue:"",icon:null,onChange:value=>setFilter("variant",value),options:[{value:"",label:"Tất cả mã đề"},...availableVariants.map(value=>({value,label:value}))]},
              {key:"status",label:"Trạng thái",value:status,defaultValue:"All statuses",icon:null,onChange:value=>setFilter("status",value),options:["All statuses","Not submitted","Needs review","Graded","Awaiting regrade","Regraded"].map(value=>({value,label:tr(value)}))},
            ]}/>
          <div className="teacher-table-scroll teacher-list-table-scroll">
            <Table className="teacher-table">
              <colgroup><col style={{width:"32%"}}/><col style={{width:"19%"}}/><col style={{width:"12%"}}/><col style={{width:"19%"}}/><col style={{width:"18%"}}/></colgroup>
              <thead><tr><th>MSSV / Sinh viên</th><th>Lớp</th><th>Điểm</th><th>Trạng thái</th><th>Thao tác</th></tr></thead>
              <tbody>{visibleRows.map(row => <TableRow key={rowKey(row)} selected={rowKey(row) === (selected && rowKey(selected))} onClick={() => setSelectedKey(rowKey(row))}>
                <td><button type="button" className="submission-select-button" aria-pressed={rowKey(row) === (selected && rowKey(selected))} onClick={() => setSelectedKey(rowKey(row))}><strong>{row.studentCode || "—"}</strong><br/>{row.student}{hasMultipleProblems && <><br/><small className="muted">{row.problem}</small></>}</button></td>
                <td>{row.className || "—"}</td><td>{formatPoints(row.score)}{row.score != null && <small> / {formatPoints(row.maxScore)}</small>}</td><td><Status value={row.status}/></td>
                <td>{<Link className="button teacher-small-button" state={{returnTo:location.pathname + location.search}} to={workLink(row)}>Xem bài làm</Link>}</td>
              </TableRow>)}{!rows.length && <tr><td colSpan={5} className="teacher-empty-row">{isExam && data.submissions.length === 0 ? (!seesWholeExam ? "Không có sinh viên thuộc lớp thực hành của bạn tham gia kỳ thi này." : "Chưa có sinh viên nào trong kỳ thi này.") : "Không có sinh viên phù hợp bộ lọc."}</td></tr>}</tbody>
            </Table>
          </div>
          <TeacherListPagination summary={<>Hiển thị {rows.length ? first + 1 : 0}–{Math.min(first + 15, rows.length)} / {rows.length}</>} paginationLabel="Danh sách sinh viên" pageIndicator={<>Trang {page} trên {totalPages}</>} previousLabel="← Trước" nextLabel="Tiếp →" page={page} pageCount={totalPages} onPageChange={value => setFilter("page", String(value))}/>
        </div>
        <div className="teacher-filter-side">
          <div className="grading-actions">
            <div ref={setFilterTarget} className="teacher-filter-actions"/>
            <Link className="button" to={exitPath}>Thoát</Link>
          </div>
          <aside className="teacher-class-details teacher-list-detail-panel grading-details">
            <div className="grading-detail-body">
              <p className="muted">{data.activity.title}</p>
              {selected ? <>
                <div className="teacher-class-detail-heading"><div><h2>{selected.student}</h2><p>{selected.studentCode || "—"} · {selected.className || "—"}</p></div><Status value={selected.status}/></div>
                <dl><dt>Bài toán</dt><dd>{selected.problem}</dd><dt>Mã đề</dt><dd>{selected.variantCode || "—"}</dd><dt>Loại truy vấn</dt><dd>{selected.parts.map(value => WORKSPACE_PART_LABELS[value]).join(" · ")}</dd><dt>Điểm</dt><dd>{formatPoints(selected.score)} / {formatPoints(selected.maxScore)}</dd><dt>Thời gian nộp</dt><dd>{selected.submittedAt ? submissionTimeLabel(selected.submittedAt, locale) : "Chưa nộp bài"}</dd>{selected.regradeReviewerName && <><dt>Phúc khảo</dt><dd>{selected.regradeReviewerName}{selected.regradeCompletedAt ? " · Đã chấm" : " · Chờ chấm"}{selected.regradeNote ? ` — ${selected.regradeNote}` : ""}</dd></>}</dl>
                {!selected.id && <p className="muted">Sinh viên chưa nộp bài thi.</p>}
              </> : <p>Chọn sinh viên để xem chi tiết.</p>}
              <p className="muted grading-summary">Đã nộp: {studentStats.submitted} · Chưa nộp: {studentStats.unsubmitted} · Điểm TB: {studentStats.average}</p>
            </div>
            <footer className="grading-detail-footer" style={{ display: "grid", gap: 8 }}>
              {canAssignRegrade && Boolean(data.activity?.isExamSession || data.activity?.examinationId || data.activity?.studentOptions?.examMode || isExam) && (
                <button
                  type="button"
                  className="button"
                  disabled={!selected?.id || Boolean(selected?.regradeCompletedAt)}
                  title={selected?.regradeCompletedAt ? "Bài nộp này đã được chấm phúc khảo xong" : undefined}
                  onClick={() => {
                    if (!selected?.id) return;
                    setRegradeOpen(true);
                    setRegradeError("");
                    setSelectedReviewerId(selected.regradeReviewerId || "");
                    setRegradeNote(selected.regradeNote || "");
                    setReviewersLoading(true);
                    void regradeAssignments.reviewers(activityId!, selected.studentId!)
                      .then(list => {
                        setReviewers(list);
                        if ((!selected.regradeReviewerId || !list.some(r => r.id === selected.regradeReviewerId)) && list.length > 0) {
                          setSelectedReviewerId(list[0].id);
                        }
                      })
                      .catch(err => setRegradeError(err instanceof Error ? err.message : "Không tải được danh sách giảng viên."))
                      .finally(() => setReviewersLoading(false));
                  }}
                >
                  {selected?.regradeCompletedAt ? "Đã chấm phúc khảo" : "Phân công phúc khảo"}
                </button>
              )}
              {selected ? <Link className="button primary" state={{returnTo:location.pathname + location.search}} to={workLink(selected)}>Xem bài làm</Link> : <button type="button" className="button primary" disabled>Xem bài làm</button>}
            </footer>
          </aside>
        </div>
      </div>
      {regradeOpen && selected?.id && <Dialog title="Phân công phúc khảo" onClose={() => !regradeBusy && setRegradeOpen(false)}>
        <div style={{ display: "grid", gap: 12 }}>
          <p className="muted tiny">Chọn giảng viên chấm phúc khảo cho bài thi của <strong>{selected.student}</strong> (không bao gồm giảng viên thực hành đứng lớp của sinh viên).</p>
          {reviewersLoading ? <p className="muted">Đang tải danh sách giảng viên…</p> : reviewers.length ? <>
            <label style={{ display: "grid", gap: 6 }}>
              <span className="muted tiny">Giảng viên phúc khảo</span>
              <Select aria-label="Giảng viên phúc khảo" value={selectedReviewerId} onChange={event => setSelectedReviewerId(event.target.value)} disabled={regradeBusy}>
                {reviewers.map(r => <option key={r.id} value={r.id}>{r.name} ({r.email})</option>)}
              </Select>
            </label>
            <label style={{ display: "grid", gap: 6 }}>
              <span className="muted tiny">Ghi chú phân công (không bắt buộc)</span>
              <input
                type="text"
                value={regradeNote}
                disabled={regradeBusy}
                placeholder="Nhập ghi chú gửi giảng viên chấm phúc khảo…"
                onChange={event => setRegradeNote(event.target.value)}
              />
            </label>
          </> : <p className="teacher-state-error">Không có giảng viên hợp lệ để phân công phúc khảo.</p>}
          {regradeError && <p role="alert" className="teacher-state-error">{regradeError}</p>}
          <div style={{ display: "flex", gap: 8, justifyContent: "flex-end", marginTop: 4 }}>
            {selected.regradeReviewerId && <button type="button" className="button" disabled={regradeBusy} onClick={() => {
              if (!selected?.id || regradeBusy) return;
              setRegradeBusy(true);
              setRegradeError("");
              void regradeAssignments.unassign(activityId!, selected.studentId!)
                .then(async () => {
                  if (data) {
                    const fresh = await teacherService.getAssignmentSubmissions(activityId!);
                    setData({ ...data, submissions: fresh });
                  }
                  notify.success("Đã hủy phân công phúc khảo.");
                  setRegradeOpen(false);
                })
                .catch(err => notify.fail("Không thể hủy phân công.", err))
                .finally(() => setRegradeBusy(false));
            }}>Hủy phân công</button>}
            <button type="button" className="button" disabled={regradeBusy} onClick={() => setRegradeOpen(false)}>Đóng</button>
            <button type="button" className="button primary" disabled={regradeBusy || reviewersLoading || !selectedReviewerId} onClick={() => {
              if (!selected?.id || !selectedReviewerId || regradeBusy) return;
              setRegradeBusy(true);
              setRegradeError("");
              void regradeAssignments.assign(activityId!, selected.studentId!, selectedReviewerId, regradeNote)
                .then(res => {
                  if (data) {
                    setData({
                      ...data,
                      submissions: data.submissions.map(row => row.id === selected.id ? {
                        ...row,
                        status: "Awaiting regrade",
                        regradeReviewerId: selectedReviewerId,
                        regradeReviewerName: res.reviewerName,
                        regradeNote: regradeNote.trim() || null,
                        regradeCompletedAt: null,
                      } : row),
                    });
                  }
                  notify.success(`Đã phân công phúc khảo cho ${res.reviewerName}.`);
                  setRegradeOpen(false);
                })
                .catch(err => notify.fail("Không thể phân công phúc khảo.", err))
                .finally(() => setRegradeBusy(false));
            }}>{regradeBusy ? "Đang lưu…" : "Xác nhận phân công"}</button>
          </div>
        </div>
      </Dialog>}
    </section>
  );
}
