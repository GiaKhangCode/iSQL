import {Select} from "@/shared/ui/Select";
import { Table, TableRow } from "@/shared/ui/Table";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import { useLanguage } from "@/shared/context/LanguageContext";
import { useEffect, useState } from "react";
import { Loading } from "@/shared/ui/ui";
import { type AdminClass, type AdminLecturerRequest } from "@/data/adminPolicy";
import { adminService, userRoleNames, type AdminUser } from "@/features/admin/adminService";
import { localTime, parseServerDateTime } from "@/shared/lib/datetime/serverDateTime";

import { PageIntro, Split, Field, Notice, AddUserDialog, BulkImportDialog, BulkImportMembersDialog, AdminListPagination } from "@/features/admin/AdminShared";


function formatActivityAction(action: string): string {
  if (!action) return "Hoạt động không xác định";

  // access:save_role:<name>
  if (action.startsWith("access:save_role:")) {
    const roleName = action.slice("access:save_role:".length);
    return `Lưu vai trò: ${roleName}`;
  }

  // access:assign_roles:<user_id>
  if (action.startsWith("access:assign_roles:")) {
    return "Phân quyền người dùng";
  }

  // identity:set_student_code:<user_id>
  if (action.startsWith("identity:set_student_code:")) {
    return "Cập nhật mã số sinh viên";
  }

  // labs:save:<room>
  if (action.startsWith("labs:save:")) {
    const room = action.slice("labs:save:".length);
    return `Cập nhật phòng thực hành: ${room}`;
  }

  // exam_admission:duplicate_login:<id>:generation=<gen>
  if (action.startsWith("exam_admission:duplicate_login:")) {
    return "Cảnh báo: Đăng nhập thi trùng thiết bị";
  }

  // exam_admission:reissue_export:<id>:generation=<gen>(:reason)?
  if (action.startsWith("exam_admission:reissue_export:")) {
    const parts = action.split(":");
    const reason = parts.length > 4 ? parts.slice(4).join(":") : "";
    return reason ? `Cấp lại và xuất tài khoản thi (${reason})` : "Cấp lại và xuất tài khoản thi";
  }

  // exam_admission:reissue:<id>:generation=<gen>(:reason)?
  if (action.startsWith("exam_admission:reissue:")) {
    const parts = action.split(":");
    const reason = parts.length > 4 ? parts.slice(4).join(":") : "";
    return reason ? `Cấp lại mật khẩu thi (${reason})` : "Cấp lại mật khẩu thi";
  }

  // exam_admission:export:<id>:generation=<gen>
  if (action.startsWith("exam_admission:export:")) {
    return "Xuất danh sách tài khoản thi";
  }

  // exam_rooms:save:<session_id>
  if (action.startsWith("exam_rooms:save:")) {
    return "Lưu phân bổ phòng thi";
  }

  // exam_rooms:confirm:<session_id>
  if (action.startsWith("exam_rooms:confirm:")) {
    return "Xác nhận chốt phòng thi";
  }

  // exam_rooms:reopen:<session_id>(:reason)?
  if (action.startsWith("exam_rooms:reopen:")) {
    const parts = action.split(":");
    const reason = parts.length > 3 ? parts.slice(3).join(":") : "";
    return reason ? `Mở lại phân bổ phòng thi (${reason})` : "Mở lại phân bổ phòng thi";
  }

  // exam_rooms:add_students:<session_id>:count=<count>
  if (action.startsWith("exam_rooms:add_students:")) {
    const match = /count=(\d+)/.exec(action);
    return match ? `Thêm ${match[1]} sinh viên vào phòng thi` : "Thêm sinh viên vào phòng thi";
  }

  // proctor:unlock:<session_id>:<student_id>
  if (action.startsWith("proctor:unlock:")) {
    return "Giám thị mở khóa bài thi cho sinh viên";
  }

  // proctor:add_student:<session_id>:<student_id>
  if (action.startsWith("proctor:add_student:")) {
    return "Giám thị thêm sinh viên vào ca thi";
  }

  // Saved examination <id> with <N> sessions
  const savedExamMatch = /^Saved examination\s+([\w-]+)\s+with\s+(\d+)\s+sessions$/i.exec(action);
  if (savedExamMatch) {
    return `Lưu kỳ thi (${savedExamMatch[2]} ca thi)`;
  }

  // Updated examination session <id>
  if (/^Updated examination session/i.test(action)) {
    return "Cập nhật thông tin ca thi";
  }

  // Moved classes <classes> from session <X> to session <Y> of examination <id>
  const moveClassMatch = /^Moved classes\s+(.+?)\s+from session\s+(\w+)\s+to session\s+(\w+)/i.exec(action);
  if (moveClassMatch) {
    return `Chuyển lớp ${moveClassMatch[1]} từ ca ${moveClassMatch[2]} sang ca ${moveClassMatch[3]}`;
  }

  // Removed examination sessions <ids> from <id>
  if (/^Removed examination sessions/i.test(action)) {
    return "Xóa ca thi khỏi kỳ thi";
  }

  // content:share:<kind>:<id>:<target>:edit=<can_edit>
  if (action.startsWith("content:share:")) {
    return "Chia sẻ nội dung trong thư viện";
  }

  // content:revoke:<kind>:<id>:<target>
  if (action.startsWith("content:revoke:")) {
    return "Thu hồi chia sẻ nội dung";
  }

  // content:copy:<kind>:<id>
  if (action.startsWith("content:copy:")) {
    return "Sao chép bài tập từ thư viện";
  }

  // results:publish:<id>
  if (action.startsWith("results:publish:")) {
    return "Công bố kết quả thi / bài tập";
  }

  // Created class <id>
  const createClassMatch = /^Created class\s+(.+)$/i.exec(action);
  if (createClassMatch) {
    return `Tạo lớp học ${createClassMatch[1]}`;
  }

  // Updated class <id>
  const updateClassMatch = /^Updated class\s+(.+)$/i.exec(action);
  if (updateClassMatch) {
    return `Cập nhật lớp học ${updateClassMatch[1]}`;
  }

  // Changed role of <user> from <prev> to <new>
  const roleChangeMatch = /^Changed role of\s+(.+?)\s+from\s+(.+?)\s+to\s+(.+)$/i.exec(action);
  if (roleChangeMatch) {
    return `Đổi vai trò người dùng thành ${roleChangeMatch[3]}`;
  }

  // Created user <user>
  const createUserMatch = /^Created user\s+(.+)$/i.exec(action);
  if (createUserMatch) {
    return `Tạo tài khoản: ${createUserMatch[1]}`;
  }

  // Updated user <user>
  const updateUserMatch = /^Updated user\s+(.+)$/i.exec(action);
  if (updateUserMatch) {
    return `Cập nhật tài khoản: ${updateUserMatch[1]}`;
  }

  // Deactivated user <user>
  const deactUserMatch = /^Deactivated user\s+(.+)$/i.exec(action);
  if (deactUserMatch) {
    return `Vô hiệu hóa tài khoản: ${deactUserMatch[1]}`;
  }

  // Activated user <user>
  const actUserMatch = /^Activated user\s+(.+)$/i.exec(action);
  if (actUserMatch) {
    return `Kích hoạt tài khoản: ${actUserMatch[1]}`;
  }

  // Approved lecturer <user>
  const appLectMatch = /^Approved lecturer\s+(.+)$/i.exec(action);
  if (appLectMatch) {
    return `Phê duyệt giảng viên: ${appLectMatch[1]}`;
  }

  // Rejected lecturer <user>
  const rejLectMatch = /^Rejected lecturer\s+(.+)$/i.exec(action);
  if (rejLectMatch) {
    return `Từ chối duyệt giảng viên: ${rejLectMatch[1]}`;
  }

  return action;
}

function formatGradingError(errorType: string): string {
  if (!errorType) return "Lỗi không xác định";
  const lower = errorType.toLowerCase();
  if (lower.includes("runtime")) return "Lỗi thực thi (Runtime Error)";
  if (lower.includes("syntax")) return "Lỗi cú pháp (Syntax Error)";
  if (lower.includes("timeout") || lower.includes("time limit")) return "Quá thời gian thực thi (Time Limit)";
  if (lower.includes("memory")) return "Quá giới hạn bộ nhớ (Memory Limit)";
  if (lower.includes("wrong answer")) return "Sai kết quả (Wrong Answer)";
  return errorType;
}

function OverviewPagination({ total, page, onPageChange, label }: { total: number; page: number; onPageChange: (page: number) => void; label: string }) {
  const { t: tr } = useLanguage("admin");
  const pageSize = 10;
  const pageCount = Math.max(1, Math.ceil(total / pageSize));
  const currentPage = Math.min(page, pageCount);
  return <nav className="admin-table-pagination" aria-label={tr(label)}>
      <button type="button" className="button admin-button" disabled={currentPage === 1} onClick={() => onPageChange(currentPage - 1)}><TranslatedText>{tr("← Previous")}</TranslatedText></button>
      <span><TranslatedText>{tr("Page")}</TranslatedText> {currentPage} <TranslatedText>{tr("of")}</TranslatedText> {pageCount}</span>
      <button type="button" className="button admin-button" disabled={currentPage === pageCount} onClick={() => onPageChange(currentPage + 1)}><TranslatedText>{tr("Next →")}</TranslatedText></button>
    </nav>;
}

function OverviewLecturerApprovalsPanel({ onClose, onResolved }: { onClose: () => void; onResolved: (pendingCount: number) => void }) {
  const { t: tr } = useLanguage("admin");
  const [requests, setRequests] = useState<(AdminLecturerRequest & { id: string })[]>([]);
  const [selectedId, setSelectedId] = useState("");
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [busy, setBusy] = useState(false);
  const [confirmReject, setConfirmReject] = useState(false);
  const selected = requests.find(request => request.id === selectedId) || requests[0];

  async function loadRequests() {
    setLoading(true);
    setError("");
    try {
      const data = await adminService.getLecturerRequests();
      setRequests(data);
      setSelectedId(data[0]?.id || "");
    } catch (err) {
      setError(err instanceof Error ? err.message : "Could not load lecturer requests.");
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    void loadRequests();
  }, []);

  async function resolveRequest(approved: boolean) {
    if (!selected || busy) return;
    setBusy(true);
    setError("");
    setNotice("");
    try {
      if (approved) await adminService.approveLecturerRequest(selected.id);
      else await adminService.rejectLecturerRequest(selected.id);
      const remaining = requests.filter(request => request.id !== selected.id);
      setRequests(remaining);
      setSelectedId(remaining[0]?.id || "");
      setConfirmReject(false);
      setNotice(approved ? selected.name + " was approved as a lecturer." : selected.name + "'s request was rejected.");
      onResolved(remaining.length);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Could not update this request.");
    } finally {
      setBusy(false);
    }
  }

  return <div className="admin-overview-approvals">
    <div className="admin-overview-panel-header"><h2><TranslatedText>{tr("Lecturer approvals")}</TranslatedText></h2><button type="button" className="text-button" disabled={busy} onClick={onClose}><TranslatedText>{tr("Close")}</TranslatedText></button></div>
    {loading ? <Loading variant="table" columns={4} label={tr("Loading lecturer requests…")} /> : <>
      {notice && <Notice><TranslatedText>{tr(notice)}</TranslatedText></Notice>}
      {error && <p className="admin-warning" role="alert"><TranslatedText>{tr(error)}</TranslatedText></p>}
      {selected ? <>
        <Field label={"PENDING REQUESTS (" + requests.length + ")"}><Select value={selected.id} disabled={busy} onChange={event => { setSelectedId(event.target.value); setConfirmReject(false); setError(""); setNotice(""); }}>{requests.map(request => <option key={request.id} value={request.id}>{request.name} · {request.email}</option>)}</Select></Field>
        <div className="admin-detail-divider" />
        <h3 className="admin-detail-title">{selected.name}</h3>
        <dl className="teacher-list-detail-facts">
          <div><dt><TranslatedText>{tr("Email")}</TranslatedText></dt><dd>{selected.email}</dd></div>
          <div><dt><TranslatedText>{tr("Department")}</TranslatedText></dt><dd>{selected.department || "—"}</dd></div>
          <div><dt><TranslatedText>{tr("Submitted")}</TranslatedText></dt><dd>{selected.submitted || "—"}</dd></div>
        </dl>
        {confirmReject ? <div className="admin-overview-reject-confirm">
          <p><TranslatedText>{tr("Reject the registration for")}</TranslatedText> <strong>{selected.name}</strong>?</p>
          <div className="admin-overview-request-actions"><button type="button" className="button admin-button" disabled={busy} onClick={() => setConfirmReject(false)}><TranslatedText>{tr("Cancel")}</TranslatedText></button><button type="button" className="button admin-button admin-reject-button" disabled={busy} onClick={() => void resolveRequest(false)}><TranslatedText>{busy ? tr("Rejecting…") : tr("Confirm rejection")}</TranslatedText></button></div>
        </div> : <div className="admin-overview-request-actions">
          <button type="button" className="button primary admin-button" disabled={busy} onClick={() => void resolveRequest(true)}><TranslatedText>{busy ? tr("Approving…") : tr("Approve lecturer")}</TranslatedText></button>
          <button type="button" className="button admin-button admin-reject-button" disabled={busy} onClick={() => setConfirmReject(true)}><TranslatedText>{tr("Reject request")}</TranslatedText></button>
        </div>}
      </> : error ? <button type="button" className="button admin-button" onClick={() => void loadRequests()}><TranslatedText>{tr("Retry")}</TranslatedText></button> : <>
        <p className="admin-muted"><TranslatedText>{tr("No pending requests.")}</TranslatedText></p>
        <button type="button" className="text-button" onClick={() => void loadRequests()}><TranslatedText>{tr("Refresh")}</TranslatedText></button>
      </>}
    </>}
  </div>;
}

function OverviewActivityPanel({ activity, onClose }: { activity: any; onClose: () => void }) {
  const { t: tr, locale: uiLocale } = useLanguage("admin");
  const [classes, setClasses] = useState<AdminClass[]>([]);
  const [users, setUsers] = useState<AdminUser[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const classId = /^(?:Created|Updated) class (.+)$/.exec(activity.action)?.[1];
  const relatedClass = classes.find(item => item.id === classId);
  const namedActors = users.filter(user => user.name === activity.by);
  const actor = namedActors.length === 1 ? namedActors[0] : undefined;

  useEffect(() => {
    let cancelled = false;
    Promise.allSettled([adminService.getClasses(), adminService.getUsers()]).then(([classResult, userResult]) => {
      if (cancelled) return;
      if (classResult.status === "fulfilled") setClasses(classResult.value);
      if (userResult.status === "fulfilled") setUsers(userResult.value);
      if (classResult.status === "rejected" || userResult.status === "rejected") setError("Some related information could not be loaded.");
      setLoading(false);
    });
    return () => { cancelled = true; };
  }, []);

  return <div className="admin-overview-details">
    <div className="admin-overview-panel-header"><h2><TranslatedText>{tr("Activity details")}</TranslatedText></h2><button type="button" className="text-button" onClick={onClose}><TranslatedText>{tr("Close")}</TranslatedText></button></div>
    <h3 className="admin-detail-title">{formatActivityAction(activity.action)}</h3>{formatActivityAction(activity.action) !== activity.action && <p className="admin-muted" style={{ fontSize: "11px", marginTop: "4px", wordBreak: "break-all" }}>{activity.action}</p>}
    <dl className="teacher-list-detail-facts">
      <div><dt><TranslatedText>{tr("Performed by")}</TranslatedText></dt><dd><TranslatedText>{activity.by || tr("System")}</TranslatedText></dd></div>
      <div><dt><TranslatedText>{tr("Time")}</TranslatedText></dt><dd><TranslatedText>{activity.time ? parseServerDateTime(activity.time).toLocaleString(uiLocale) : tr("Unknown")}</TranslatedText></dd></div>
    </dl>
    {loading ? <Loading variant="table" columns={4} label={tr("Loading related information…")} /> : <>
      {error && <p className="admin-warning" role="alert"><TranslatedText>{tr(error)}</TranslatedText></p>}
      {classId && <>
        <div className="admin-detail-divider" />
        <h3 className="admin-subheading"><TranslatedText>{tr("Current class information")}</TranslatedText></h3>
        {relatedClass ? <dl className="teacher-list-detail-facts">
          <div><dt><TranslatedText>{tr("Class code")}</TranslatedText></dt><dd>{relatedClass.id}</dd></div>
          <div><dt><TranslatedText>{tr("Course")}</TranslatedText></dt><dd>{relatedClass.course}</dd></div>
          <div><dt><TranslatedText>{tr("Semester")}</TranslatedText></dt><dd>{relatedClass.semester || "—"}</dd></div>
          <div><dt><TranslatedText>{tr("Lecturer")}</TranslatedText></dt><dd>{relatedClass.lecturer}</dd></div>
          <div><dt><TranslatedText>{tr("Students")}</TranslatedText></dt><dd>{relatedClass.students}</dd></div>
          <div><dt><TranslatedText>{tr("Status")}</TranslatedText></dt><dd><TranslatedText>{tr(relatedClass.status)}</TranslatedText></dd></div>
          <div><dt><TranslatedText>{tr("Dates")}</TranslatedText></dt><dd><TranslatedText>{relatedClass.startDate && relatedClass.endDate ? relatedClass.startDate + " – " + relatedClass.endDate : tr("Not specified")}</TranslatedText></dd></div>
        </dl> : <p className="admin-muted"><TranslatedText>{tr("Class")}</TranslatedText> <TranslatedText>{tr(classId)}</TranslatedText><TranslatedText>{tr(": current information is unavailable.")}</TranslatedText></p>}
        <p className="admin-muted admin-overview-context-note"><TranslatedText>{tr("Class information reflects its current state. This activity record does not contain the previous field values.")}</TranslatedText></p>
      </>}
      {actor && <>
        <div className="admin-detail-divider" /><h3 className="admin-subheading"><TranslatedText>{tr("Current account information")}</TranslatedText></h3>
        <dl className="teacher-list-detail-facts"><div><dt><TranslatedText>{tr("Email")}</TranslatedText></dt><dd>{actor.email}</dd></div><div><dt><TranslatedText>{tr("Role")}</TranslatedText></dt><dd>{userRoleNames(actor)}</dd></div><div><dt><TranslatedText>{tr("Status")}</TranslatedText></dt><dd><TranslatedText>{tr(actor.status)}</TranslatedText></dd></div><div><dt><TranslatedText>{tr("Joined")}</TranslatedText></dt><dd>{actor.joined}</dd></div>{actor.detail && <div><dt><TranslatedText>{tr("Department")}</TranslatedText></dt><dd>{actor.detail}</dd></div>}</dl>
      </>}
    </>}
    <details className="admin-overview-record-reference"><summary><TranslatedText>{tr("Record reference")}</TranslatedText></summary><p className="admin-muted">{activity.id}</p></details>
  </div>;
}

function OverviewUnassignedClassesPanel({ onClose }: { onClose: () => void }) {
  const { t: tr } = useLanguage("admin");
  const [classes, setClasses] = useState<AdminClass[]>([]);
  const [selectedId, setSelectedId] = useState("");
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const selected = classes.find(item => item.id === selectedId) || classes[0];

  async function loadClasses() {
    setLoading(true);
    setError("");
    try {
      const data = await adminService.getClasses();
      const unassigned = data.filter(item => item.status !== "Archived" && item.lecturer === "Unassigned");
      setClasses(unassigned);
      setSelectedId(unassigned[0]?.id || "");
    } catch (err) {
      setError(err instanceof Error ? err.message : "Could not load classes.");
    } finally { setLoading(false); }
  }
  useEffect(() => { void loadClasses(); }, []);

  return <div className="admin-overview-details">
    <div className="admin-overview-panel-header"><h2><TranslatedText>{tr("Classes without a lecturer")}</TranslatedText></h2><button type="button" className="text-button" onClick={onClose}><TranslatedText>{tr("Close")}</TranslatedText></button></div>
    {loading ? <Loading variant="table" columns={4} label={tr("Loading classes…")} /> : error ? <><p className="admin-warning" role="alert"><TranslatedText>{tr(error)}</TranslatedText></p><button type="button" className="button admin-button" onClick={() => void loadClasses()}><TranslatedText>{tr("Retry")}</TranslatedText></button></> : selected ? <>
      <Field label={"UNASSIGNED CLASSES (" + classes.length + ")"}><Select value={selected.id} onChange={event => setSelectedId(event.target.value)}>{classes.map(item => <option key={item.id} value={item.id}>{item.id} · {item.course}</option>)}</Select></Field>
      <div className="admin-detail-divider" />
      <h3 className="admin-detail-title">{selected.id}</h3>
      <dl className="teacher-list-detail-facts">
        <div><dt><TranslatedText>{tr("Course")}</TranslatedText></dt><dd>{selected.course}</dd></div>
        <div><dt><TranslatedText>{tr("Semester")}</TranslatedText></dt><dd>{selected.semester || "—"}</dd></div>
        <div><dt><TranslatedText>{tr("Status")}</TranslatedText></dt><dd><TranslatedText>{tr(selected.status)}</TranslatedText></dd></div>
        <div><dt><TranslatedText>{tr("Lecturer")}</TranslatedText></dt><dd className="admin-warning"><TranslatedText>{tr("Unassigned")}</TranslatedText></dd></div>
        <div><dt><TranslatedText>{tr("Students")}</TranslatedText></dt><dd>{selected.students}</dd></div>
        <div><dt><TranslatedText>{tr("Dates")}</TranslatedText></dt><dd>{selected.startDate && selected.endDate ? selected.startDate + " – " + selected.endDate : selected.dates || "—"}</dd></div>
      </dl>
    </> : <p className="admin-muted"><TranslatedText>{tr("All classes have an assigned lecturer.")}</TranslatedText></p>}
  </div>;
}

export function AdminOverviewPage() {
  const { t: tr, locale: uiLocale } = useLanguage("admin");
  const [notice, setNotice] = useState("");
  const [stats, setStats] = useState({ users: 0, classes: 0, pendingRequests: 0, unassignedClasses: 0, submissionsToday: 0, gradingErrors: 0 });
  const [activities, setActivities] = useState<any[]>([]);
  const [errors, setErrors] = useState<any[]>([]);
  const [attentionPage, setAttentionPage] = useState(1);
  const [selection, setSelection] = useState<{ type: "attention" | "activity"; id: string } | null>(null);
  const selectedActivity = selection?.type === "activity" ? activities.find(item => item.id === selection.id) : undefined;
  const [activityPage, setActivityPage] = useState(1);
  const pageSize = 10;
  const attentionItems = [
    { id: "registrations", title: "Lecturer registrations", count: stats.pendingRequests, unit: "pending" },
    { id: "unassigned", title: "Classes without a lecturer", count: stats.unassignedClasses, unit: "class(es)" },
  ];
  const currentAttentionPage = Math.min(attentionPage, Math.max(1, Math.ceil(attentionItems.length / pageSize)));
  const currentActivityPage = Math.min(activityPage, Math.max(1, Math.ceil(activities.length / pageSize)));
  const visibleAttention = attentionItems.slice((currentAttentionPage - 1) * pageSize, currentAttentionPage * pageSize);
  const visibleActivities = activities.slice((currentActivityPage - 1) * pageSize, currentActivityPage * pageSize);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    Promise.all([
      adminService.getOverviewStats(),
      adminService.getActivities(),
      adminService.getErrors()
    ]).then(([statsData, activitiesData, errorsData]) => {
      setStats({
        users: statsData.users,
        classes: statsData.classes,
        pendingRequests: statsData.pendingRequests,
        unassignedClasses: statsData.unassignedClasses,
        submissionsToday: statsData.submissionsToday,
        gradingErrors: statsData.gradingErrors
      });
      setActivities(activitiesData);
      setErrors(errorsData);
      setLoading(false);
    }).catch(err => {
      console.error(err);
      setNotice("Failed to load overview data.");
      setLoading(false);
    });
  }, []);

  if (loading) {
    return <div className={"admin-page compact-tables-page admin-overview-page" + (selection ? " has-overview-selection" : "")}><h1 className="sr-only"><TranslatedText>{tr("System overview")}</TranslatedText></h1><Loading variant="dashboard" metrics={4} label={tr("Loading overview…")} /></div>;
  }
  if (notice) return <div className={"admin-page compact-tables-page admin-overview-page" + (selection ? " has-overview-selection" : "")}><h1 className="sr-only"><TranslatedText>{tr("System overview")}</TranslatedText></h1><p className="admin-notice" role="alert"><TranslatedText>{tr(notice)}</TranslatedText></p></div>;

  return <div className={"admin-page compact-tables-page admin-overview-page" + (selection ? " has-overview-selection" : "")}>
    <h1 className="sr-only"><TranslatedText>{tr("System overview")}</TranslatedText></h1>
    <Split className="admin-overview-split" main={<>
      <section className="admin-attention">
        <div className="admin-overview-table-heading">
          <h2 className="admin-section-heading"><TranslatedText>{tr("Needs attention")}</TranslatedText></h2>
          <div className="admin-overview-table-controls">
            <span className="admin-muted" role="status"><TranslatedText>{tr("Showing")}</TranslatedText> {attentionItems.length === 0 ? 0 : (currentAttentionPage - 1) * pageSize + 1}–{Math.min(currentAttentionPage * pageSize, attentionItems.length)} <TranslatedText>{tr("of")}</TranslatedText> {attentionItems.length.toLocaleString(uiLocale)} <TranslatedText>{tr("items")}</TranslatedText></span>
            <OverviewPagination total={attentionItems.length} page={currentAttentionPage} onPageChange={setAttentionPage} label={tr("Needs attention pages")} />
          </div>
        </div>
        <div className="compact-table-scroll" role="region" aria-label={tr("Needs attention table")} tabIndex={0}>
        <Table className="admin-table admin-attention-table"><thead><tr><th><TranslatedText>{tr("ITEM")}</TranslatedText></th><th><TranslatedText>{tr("COUNT")}</TranslatedText></th></tr></thead><tbody>
          {visibleAttention.map(item => {
            const isSelected = selection?.type === "attention" && selection.id === item.id;
            const selectRow = () => setSelection({ type: "attention", id: item.id });
            return <TableRow selected={isSelected} key={item.id} className={isSelected ? "is-selected" : ""} onClick={selectRow}>
              <td data-label={tr("ITEM")}><button type="button" className="admin-overview-row-button" aria-pressed={isSelected} onClick={selectRow}><TranslatedText>{tr(item.title)}</TranslatedText></button></td>
              <td data-label={tr("COUNT")} className={item.count > 0 ? "admin-warning" : ""}>{item.count} <TranslatedText>{tr(item.unit)}</TranslatedText></td>
            </TableRow>;
          })}
        </tbody></Table>
        </div>
      </section>
      <section className="admin-recent-activity">
        <div className="admin-overview-table-heading">
          <h2 className="admin-section-heading"><TranslatedText>{tr("Recent activity")}</TranslatedText></h2>
          <div className="admin-overview-table-controls">
            <span className="admin-muted" role="status"><TranslatedText>{tr("Showing")}</TranslatedText> {activities.length === 0 ? 0 : (currentActivityPage - 1) * pageSize + 1}–{Math.min(currentActivityPage * pageSize, activities.length)} <TranslatedText>{tr("of")}</TranslatedText> {activities.length.toLocaleString(uiLocale)} <TranslatedText>{tr("items")}</TranslatedText></span>
            <OverviewPagination total={activities.length} page={currentActivityPage} onPageChange={setActivityPage} label={tr("Recent activity pages")} />
          </div>
        </div>
        <div className="compact-table-scroll" role="region" aria-label={tr("Recent activity table")} tabIndex={0}>
        <Table className="admin-table admin-activity-table"><thead><tr><th><TranslatedText>{tr("ACTION")}</TranslatedText></th><th><TranslatedText>{tr("BY")}</TranslatedText></th><th><TranslatedText>{tr("TIME")}</TranslatedText></th></tr></thead><tbody>
          {activities.length > 0 ? visibleActivities.map(item => {
            const isSelected = selection?.type === "activity" && selection.id === item.id;
            const selectRow = () => setSelection({ type: "activity", id: item.id });
            return <TableRow selected={isSelected} key={item.id} className={isSelected ? "is-selected" : ""} onClick={selectRow}>
              <td><button type="button" className="admin-overview-row-button" aria-pressed={isSelected} onClick={selectRow}>{formatActivityAction(item.action)}</button></td><td>{item.by}</td><td><TranslatedText>{item.time ? localTime(item.time) : tr("Unknown")}</TranslatedText></td>
            </TableRow>;
          }) : <tr><td colSpan={3} className="admin-muted"><TranslatedText>{tr("No recent activity")}</TranslatedText></td></tr>}
        </tbody></Table>
        </div>
      </section>
    </>} side={selection?.type === "attention" && selection.id === "registrations" ? <OverviewLecturerApprovalsPanel onClose={() => setSelection(null)} onResolved={pendingCount => {
      setStats(current => ({ ...current, pendingRequests: pendingCount }));
      adminService.getActivities().then(setActivities).catch(console.error);
    }} /> : selection?.type === "attention" && selection.id === "unassigned" ? <OverviewUnassignedClassesPanel onClose={() => setSelection(null)} /> : selectedActivity ? <OverviewActivityPanel key={selectedActivity.id} activity={selectedActivity} onClose={() => setSelection(null)} /> : <>
      <h2><TranslatedText>{tr("Grading health")}</TranslatedText></h2><p className={stats.gradingErrors === 0 ? "admin-health" : "admin-health admin-warning"}><TranslatedText>{stats.gradingErrors === 0 ? tr("Operational") : tr("Needs Attention")}</TranslatedText></p><p className="admin-muted">{stats.submissionsToday} <TranslatedText>{tr("submitted today /")}</TranslatedText> {stats.gradingErrors} <TranslatedText>{tr("need attention")}</TranslatedText></p><div className="admin-detail-divider" /><h3 className="admin-subheading"><TranslatedText>{tr("Recent errors")}</TranslatedText></h3>
      {errors.length > 0 ? errors.map(err => <p key={err.id} className="admin-warning admin-error">#{err.id.substring(0, 8)} · {formatGradingError(err.errorType)}<br />{err.problemTitle}</p>) : <p className="admin-muted"><TranslatedText>{tr("No errors recently")}</TranslatedText></p>}
    </>} />
  </div>;
}
