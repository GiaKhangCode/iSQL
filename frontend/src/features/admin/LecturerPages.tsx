import {Select} from "@/shared/ui/Select";
import { Table, TableRow } from "@/shared/ui/Table";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import { useLanguage } from "@/shared/context/LanguageContext";
import { useEffect, useState } from "react";
import { Link, useLocation } from "react-router-dom";
import { Dialog, Loading } from "@/shared/ui/ui";
import { adminCapabilities, defaultAdminRolePolicy, type AdminClass, type AdminRoleName } from "@/data/adminPolicy";
import { adminService } from "@/features/admin/adminService";

import { PageIntro, Split, Field, Notice, AddUserDialog, BulkImportDialog, BulkImportMembersDialog, AdminListPagination } from "@/features/admin/AdminShared";

function RejectLecturerDialog({
  request,
  onClose,
  onReject,
}: {
  request: any;
  onClose: () => void;
  onReject: (reason: string) => void;
}) {
  const { t: tr } = useLanguage("admin");
  const [reason, setReason] = useState("");
  return <Dialog title={tr("Reject lecturer request")} onClose={onClose} className="admin-reject-dialog">
    <form className="admin-dialog-form" onSubmit={(event) => { event.preventDefault(); onReject(reason.trim()); }}>
      <p className="admin-muted">{request.name} · {request.email}</p>
      <Field label={tr("REASON (OPTIONAL, SENT TO APPLICANT)")}>
        <textarea rows={3} value={reason} onChange={(event) => setReason(event.target.value)} placeholder={tr("Explain why this request was declined…")} />
      </Field>
      <div className="admin-dialog-actions">
        <button type="button" className="button admin-button" onClick={onClose}><TranslatedText>{tr("Cancel")}</TranslatedText></button>
        <button className="button admin-button admin-reject-button"><TranslatedText>{tr("Reject request")}</TranslatedText></button>
      </div>
    </form>
  </Dialog>;
}

export function AdminLecturerApprovalsPage() {
  const { t: tr } = useLanguage("admin");
  const [requests, setRequests] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState("");
  const [selectedEmail, setSelectedEmail] = useState("");
  const [rejecting, setRejecting] = useState<any | null>(null);
  const [notice, setNotice] = useState("");

  useEffect(() => {
    adminService.getLecturerRequests().then(data => {
      setRequests(data);
      if (data.length > 0) setSelectedEmail(data[0].email);
      setLoading(false);
    }).catch(err => {
      setLoadError(err instanceof Error ? err.message : "Could not load lecturer requests.");
      setLoading(false);
    });
  }, []);

  const selected = requests.find((request) => request.email === selectedEmail) || requests[0];

  async function resolveRequest(request: any, approved: boolean, reason = "") {
    try {
      if (approved) {
        await adminService.approveLecturerRequest(request.id);
        setNotice(`${request.name} was approved as a lecturer.`);
      } else {
        await adminService.rejectLecturerRequest(request.id);
        setNotice(`${request.name}'s request was rejected.${reason ? " The reason was not sent because the server does not accept it." : ""}`);
      }
      const next = requests.filter((item) => item.email !== request.email);
      setRequests(next);
      setSelectedEmail(next[0]?.email || "");
      setRejecting(null);
    } catch (err: any) {
      setNotice(err.message || "Action failed.");
      setRejecting(null);
    }
  }

  return <div className="admin-page admin-approvals-page">
    <PageIntro title={tr("Lecturer approvals")} sub={tr("People & access / {value0} pending lecturer requests", { value0: requests.length })} />
    <div className="admin-section-tabs">
      <Link to="/admin/users"><TranslatedText>{tr("All users")}</TranslatedText></Link>
      <Link className="active" to="/admin/users/approvals"><TranslatedText>{tr("Lecturer approvals (")}</TranslatedText>{requests.length})</Link>
    </div>
    {notice && <Notice><TranslatedText>{tr(notice)}</TranslatedText></Notice>}
    {loading ? <Loading variant="table" columns={4} label={tr("Loading lecturer requests…")} /> : loadError ? <p className="admin-notice" role="alert"><TranslatedText>{tr(loadError)}</TranslatedText></p> :
    <Split main={<>
      <div className="admin-table-wrap sticky-list-table-wrap"><Table className="admin-table sticky-list-table admin-approval-table"><thead><tr><th><TranslatedText>{tr("NAME / EMAIL")}</TranslatedText></th><th><TranslatedText>{tr("DEPARTMENT")}</TranslatedText></th><th><TranslatedText>{tr("SUBMITTED")}</TranslatedText></th><th><TranslatedText>{tr("STATUS")}</TranslatedText></th></tr></thead><tbody>
        {requests.map((request) => <TableRow selected={request.email === selected?.email} key={request.email} className={request.email === selected?.email ? "is-selected" : ""} onClick={() => setSelectedEmail(request.email)}>
          <td data-label={tr("NAME / EMAIL")}><button className="admin-row-link" type="button" onClick={() => setSelectedEmail(request.email)}>{request.name}</button><small>{request.email}</small></td>
          <td data-label={tr("DEPARTMENT")}>{request.department}</td><td data-label={tr("SUBMITTED")}>{request.submitted}</td><td data-label={tr("STATUS")} className="admin-warning"><TranslatedText>{tr("Pending")}</TranslatedText></td>
        </TableRow>)}
        {!requests.length && <tr><td colSpan={4} className="admin-muted"><TranslatedText>{tr("No lecturer registrations are waiting for approval.")}</TranslatedText></td></tr>}
      </tbody></Table></div>
      <p className="admin-table-footer"><TranslatedText>{tr("Showing")}</TranslatedText> {requests.length} <TranslatedText>{tr("pending requests")}</TranslatedText></p>
    </>} side={selected ? <>
      <h2><TranslatedText>{tr("Request details")}</TranslatedText></h2><h3 className="admin-detail-title">{selected.name}</h3><p className="admin-muted">{selected.email}</p><div className="admin-detail-divider" />
      <Field label={tr("DEPARTMENT")}><input value={selected.department} readOnly /></Field>
      <p className="admin-warning admin-detail-status"><TranslatedText>{tr("Pending · Submitted")}</TranslatedText> {selected.submitted}</p>
      <p className="admin-muted"><TranslatedText>{tr("Registered as Lecturer")}</TranslatedText><br /><TranslatedText>{tr("Lecturer access stays locked until approved.")}</TranslatedText></p>
      <button className="button primary admin-button" type="button" onClick={() => resolveRequest(selected, true)}><TranslatedText>{tr("Approve lecturer")}</TranslatedText></button>
      <div className="admin-detail-divider" />
      <button className="admin-danger-link" type="button" onClick={() => setRejecting(selected)}><TranslatedText>{tr("Reject request")}</TranslatedText></button>
      <p className="admin-muted"><TranslatedText>{tr("Lecturer access stays locked. The server does not send the entered reason.")}</TranslatedText></p>
    </> : <><h2><TranslatedText>{tr("Request details")}</TranslatedText></h2><p className="admin-muted"><TranslatedText>{tr("There are no pending lecturer requests.")}</TranslatedText></p></>} />}
    {rejecting && <RejectLecturerDialog request={rejecting} onClose={() => setRejecting(null)} onReject={(reason) => resolveRequest(rejecting, false, reason)} />}
  </div>;
}

export function AdminRolesPage() {
  const { t: tr } = useLanguage("admin");
  const [selectedRole, setSelectedRole] = useState<AdminRoleName>("Lecturer");
  const policy = defaultAdminRolePolicy;
  const roleInfo: Record<AdminRoleName, { title: string; description: string }> = {
    Student: { title: "Learning access", description: "Run and submit SQL for assigned work, and practice from the shared problem library." },
    Lecturer: { title: "Teaching access", description: "Create problems, assign work and review results in assigned classes." },
    "Faculty office": { title: "Examination administration", description: "Plan examination sessions, allocate classes and students, and manage admission credentials." },
    Admin: { title: "System access", description: "Manage accounts, courses, classes, the Practice catalog, and platform activity." },
  };
  const enabledCount = adminCapabilities.filter(({ key }) => policy.permissions[key]?.[selectedRole]).length;
  return <div className="admin-page admin-roles-page">
    <h1 className="sr-only"><TranslatedText>{tr("Roles & permissions")}</TranslatedText></h1>
    <Split main={<div className="admin-table-wrap sticky-list-table-wrap"><Table className="admin-table sticky-list-table admin-permissions-table"><thead><tr><th><TranslatedText>{tr("CAPABILITY")}</TranslatedText></th><th><TranslatedText>{tr("STUDENT")}</TranslatedText></th><th><TranslatedText>{tr("LECTURER")}</TranslatedText></th><th><TranslatedText>{tr("Faculty office")}</TranslatedText></th><th><TranslatedText>{tr("ADMIN")}</TranslatedText></th></tr></thead><tbody>{adminCapabilities.map(({ key, label }) => <tr key={key}><td data-label={tr("CAPABILITY")}><TranslatedText>{tr(label)}</TranslatedText></td>{(["Student", "Lecturer", "Faculty office", "Admin"] as const).map((role) => {
      const enabled = policy.permissions[key]?.[role] ?? false;
      return <td data-label={tr(role.toUpperCase())} key={role}><span className={enabled ? "admin-success" : "admin-muted"}><TranslatedText>{enabled ? tr("Allowed") : "—"}</TranslatedText></span></td>;
    })}</tr>)}</tbody></Table></div>} side={<>
      <div className="admin-role-tabs">{(["Student", "Lecturer", "Faculty office", "Admin"] as const).map((role) => <button type="button" key={role} className={selectedRole === role ? "active" : ""} onClick={() => setSelectedRole(role)}><TranslatedText>{tr(role)}</TranslatedText></button>)}</div>
      <h3 className="admin-detail-title"><TranslatedText>{tr(roleInfo[selectedRole].title)}</TranslatedText></h3><p className="admin-muted"><TranslatedText>{tr(roleInfo[selectedRole].description)}</TranslatedText></p><div className="admin-detail-divider" /><h3 className="admin-subheading"><TranslatedText>{tr("Effective access")}</TranslatedText></h3>
      <p className="admin-role-summary">{enabledCount} <TranslatedText>{tr("of")}</TranslatedText> {adminCapabilities.length} <TranslatedText>{tr("capabilities enabled")}</TranslatedText></p>
      <dl className="teacher-list-detail-facts"><div><dt><TranslatedText>{tr("Class access")}</TranslatedText></dt><dd><TranslatedText>{tr(policy.scopes[selectedRole].classAccess)}</TranslatedText></dd></div><div><dt><TranslatedText>{tr("Problem access")}</TranslatedText></dt><dd><TranslatedText>{tr(policy.scopes[selectedRole].problemAccess)}</TranslatedText></dd></div></dl>
      <p className="admin-muted"><TranslatedText>{tr("This guide describes intended product roles. The server enforces access; these detailed capability labels are not fetched from it.")}</TranslatedText></p>
    </>} />
  </div>;
}

export function AdminLecturersPage() {
  const { t: tr } = useLanguage("admin");
  const location = useLocation();
  const state = location.state as { selectedId?: string } | null;
  const initialSelectedId = state?.selectedId || "";

  const [assignments, setAssignments] = useState<AdminClass[]>([]);
  const [selectedId, setSelectedId] = useState(initialSelectedId);
  const [lecturer, setLecturer] = useState("Unassigned");
  const [notice, setNotice] = useState("");
  const [lecturers, setLecturers] = useState<{name: string, id: string}[]>([]);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState("");
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    Promise.all([
      adminService.getClasses(),
      adminService.getUsers()
    ]).then(([classes, users]) => {
      const sorted = [...classes];
      setAssignments(sorted);
      if (sorted.length > 0 && !selectedId) {
        setSelectedId(sorted[0].id);
        setLecturer(sorted[0].lecturerId || "Unassigned");
      } else if (selectedId) {
        const cls = sorted.find(c => c.id === selectedId);
        if (cls) setLecturer(cls.lecturerId || "Unassigned");
      }
      setLecturers(users.filter((user: any) => user.role.toLowerCase() === "lecturer" || user.role === "instructor").map((user: any) => ({ name: user.name, id: user.id })));
    }).catch((error) => setLoadError(error instanceof Error ? error.message : "Could not load classes and lecturers.")).finally(() => setLoading(false));
  }, []);

  const selected = assignments.find((item) => item.id === selectedId);

  return <div className="admin-page">
    <PageIntro title={tr("Lecturer assignment")} sub={tr("Class ownership / {value0}", { value0: selected?.semester || "" })}>
      <button className="button primary admin-button" disabled={!selected || saving || lecturer === (selected.lecturerId || "Unassigned")} onClick={async () => {
        if (!selected) return;
        setSaving(true);
        setNotice("");
        try {
          const updated = await adminService.updateClass(selectedId, { lecturerId: lecturer === "Unassigned" ? "Unassigned" : lecturer });
          setAssignments((all) => all.map((item) => item.id === selectedId ? updated : item));
          setNotice(`${updated.lecturer} assigned to ${selectedId}.`);
        } catch (err) {
          setNotice(err instanceof Error ? err.message : "Could not assign lecturer.");
        } finally {
          setSaving(false);
        }
      }}><TranslatedText>{saving ? tr("Saving…") : tr("Save assignment")}</TranslatedText></button>
    </PageIntro>{notice && <Notice><TranslatedText>{tr(notice)}</TranslatedText></Notice>}
    {loading ? <Loading variant="table" columns={4} label={tr("Loading classes and lecturers…")} /> : loadError ? <p className="admin-notice" role="alert"><TranslatedText>{tr(loadError)}</TranslatedText></p> : assignments.length === 0 ? <p className="admin-muted"><TranslatedText>{tr("No classes are available for lecturer assignment.")}</TranslatedText></p> :
    <Split main={<div className="admin-table-wrap sticky-list-table-wrap"><Table className="admin-table sticky-list-table admin-lecturer-table"><thead><tr><th><TranslatedText>{tr("CLASS")}</TranslatedText></th><th><TranslatedText>{tr("CURRENT LECTURER")}</TranslatedText></th><th><TranslatedText>{tr("STATUS")}</TranslatedText></th></tr></thead><tbody>{assignments.map((item) => <TableRow selected={item.id === selectedId} key={item.id} className={item.id === selectedId ? "is-selected" : ""} onClick={() => { setSelectedId(item.id); setLecturer(item.lecturerId || "Unassigned"); }}><td data-label={tr("CLASS")}><b>{item.id}</b></td><td data-label={tr("CURRENT LECTURER")} className={item.lecturer === "Unassigned" ? "admin-warning" : ""}>{item.lecturer}</td><td data-label={tr("STATUS")}><TranslatedText>{item.lecturer === "Unassigned" ? tr("Needs lecturer") : tr("Assigned")}</TranslatedText></td></TableRow>)}</tbody></Table></div>} side={selected ? <>
      <h2><TranslatedText>{tr("Assign")}</TranslatedText> {selected.id}</h2><p className="admin-muted">{selected.course.split(" · ")[1] || selected.course} · {selected.semester} · {selected.students} <TranslatedText>{tr("student")}</TranslatedText><TranslatedText>{selected.students === 1 ? "" : tr("s")}</TranslatedText></p><Field label={tr("LECTURER")}><Select value={lecturer} onChange={(e) => setLecturer(e.target.value)}><option value="Unassigned">{tr("Unassigned")}</option>{lecturers.map((l) => <option key={l.id} value={l.id}>{l.name}</option>)}</Select></Field><div className="admin-detail-divider" /><h3 className="admin-subheading"><TranslatedText>{tr("Access preview")}</TranslatedText></h3><p className="admin-muted"><TranslatedText>{tr("The selected lecturer can manage assignments, view class results and review submissions after assignment is saved.")}</TranslatedText></p>
    </> : <h2><TranslatedText>{tr("No class selected")}</TranslatedText></h2>} />}
  </div>;
}
