import {Select} from "@/shared/ui/Select";
import { Table, TableRow } from "@/shared/ui/Table";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import { useLanguage } from "@/shared/context/LanguageContext";
import { useEffect, useState } from "react";
import { Link, useLocation } from "react-router-dom";
import { Dialog, Loading } from "@/shared/ui/ui";
import { adminCapabilities, defaultAdminRolePolicy, type AdminClass, type AdminRoleName } from "@/data/adminPolicy";
import { adminService, lecturerOptionsFor, type InstructorOption } from "@/features/admin/adminService";

import { PageIntro, Split, Field, AddUserDialog, BulkImportDialog, BulkImportMembersDialog, AdminListPagination } from "@/features/admin/AdminShared";
import { notify } from "@/shared/ui/notify";

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
  const [lecturers, setLecturers] = useState<InstructorOption[]>([]);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState("");
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    Promise.all([
      adminService.getClasses(),
      adminService.getInstructors()
    ]).then(([classes, instructors]) => {
      const sorted = [...classes];
      setAssignments(sorted);
      if (sorted.length > 0 && !selectedId) {
        setSelectedId(sorted[0].id);
        setLecturer(sorted[0].lecturerId || "Unassigned");
      } else if (selectedId) {
        const cls = sorted.find(c => c.id === selectedId);
        if (cls) setLecturer(cls.lecturerId || "Unassigned");
      }
      setLecturers(instructors);
    }).catch((error) => setLoadError(error instanceof Error ? error.message : "Could not load classes and lecturers.")).finally(() => setLoading(false));
  }, []);

  const selected = assignments.find((item) => item.id === selectedId);

  return <div className="admin-page">
    <PageIntro title={tr("Lecturer assignment")} sub={tr("Class ownership / {value0}", { value0: selected?.semester || "" })}>
      <button className="button primary admin-button" disabled={!selected || saving || lecturer === (selected.lecturerId || "Unassigned")} onClick={async () => {
        if (!selected) return;
        setSaving(true);
        try {
          const updated = await adminService.updateClass(selectedId, { lecturerId: lecturer === "Unassigned" ? "Unassigned" : lecturer });
          setAssignments((all) => all.map((item) => item.id === selectedId ? updated : item));
          notify.success(tr(`${updated.lecturer} assigned to ${selectedId}.`));
        } catch (err) {
          notify.fail(tr("Could not assign lecturer."), err, tr);
        } finally {
          setSaving(false);
        }
      }}><TranslatedText>{saving ? tr("Saving…") : tr("Save assignment")}</TranslatedText></button>
    </PageIntro>
    {loading ? <Loading variant="table" columns={4} label={tr("Loading classes and lecturers…")} /> : loadError ? <p className="admin-notice" role="alert"><TranslatedText>{tr(loadError)}</TranslatedText></p> : assignments.length === 0 ? <p className="admin-muted"><TranslatedText>{tr("No classes are available for lecturer assignment.")}</TranslatedText></p> :
    <Split main={<div className="admin-table-wrap sticky-list-table-wrap"><Table className="admin-table sticky-list-table admin-lecturer-table"><thead><tr><th><TranslatedText>{tr("CLASS")}</TranslatedText></th><th><TranslatedText>{tr("CURRENT LECTURER")}</TranslatedText></th><th><TranslatedText>{tr("STATUS")}</TranslatedText></th></tr></thead><tbody>{assignments.map((item) => <TableRow selected={item.id === selectedId} key={item.id} className={item.id === selectedId ? "is-selected" : ""} onClick={() => { setSelectedId(item.id); setLecturer(item.lecturerId || "Unassigned"); }}><td data-label={tr("CLASS")}><b>{item.id}</b></td><td data-label={tr("CURRENT LECTURER")} className={item.lecturer === "Unassigned" ? "admin-warning" : ""}>{item.lecturer}</td><td data-label={tr("STATUS")}><TranslatedText>{item.lecturer === "Unassigned" ? tr("Needs lecturer") : tr("Assigned")}</TranslatedText></td></TableRow>)}</tbody></Table></div>} side={selected ? <>
      <h2><TranslatedText>{tr("Assign")}</TranslatedText> {selected.id}</h2><p className="admin-muted">{selected.course.split(" · ")[1] || selected.course} · {selected.semester} · {selected.students} <TranslatedText>{tr("student")}</TranslatedText><TranslatedText>{selected.students === 1 ? "" : tr("s")}</TranslatedText></p><Field label={tr("LECTURER")}><Select value={lecturer} onChange={(e) => setLecturer(e.target.value)}><option value="Unassigned">{tr("Unassigned")}</option>{lecturerOptionsFor(lecturers, selected).map((l) => <option key={l.id} value={l.id}>{l.name}</option>)}</Select></Field><div className="admin-detail-divider" /><h3 className="admin-subheading"><TranslatedText>{tr("Access preview")}</TranslatedText></h3><p className="admin-muted"><TranslatedText>{tr("The selected lecturer can manage assignments, view class results and review submissions after assignment is saved.")}</TranslatedText></p>
    </> : <h2><TranslatedText>{tr("No class selected")}</TranslatedText></h2>} />}
  </div>;
}
