import {Select} from "@/shared/ui/Select";
import { Table, TableRow } from "@/shared/ui/Table";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import { useLanguage } from "@/shared/context/LanguageContext";
import { useCallback, useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { useAuth } from "@/app/providers/AuthContext";
import { Loading } from "@/shared/ui/ui";
import { type AdminClass } from "@/data/adminPolicy";
import { adminService, userRoleNames, type AdminUser, type NamedCount, type OverviewAttention, type SandboxSummary, type ServiceHealth, type UpcomingExam } from "@/features/admin/adminService";
import { SandboxCleanupPanel } from "@/features/admin/SandboxCleanupPanel";
import { localTime, parseServerDateTime } from "@/shared/lib/datetime/serverDateTime";
import { formatActivityAction } from "@/features/admin/activityText";

import { PageIntro, Split, Field, Notice, AddUserDialog, BulkImportDialog, BulkImportMembersDialog, AdminListPagination } from "@/features/admin/AdminShared";


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
    <h3 className="admin-detail-title">{formatActivityAction(activity.action)}</h3>
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

const SERVICE_NAMES: Record<ServiceHealth["key"], string> = {
  mysql: "MySQL database",
  sqlserver: "SQL Server (grading)",
  redis: "Redis (cache and queue)",
};

function OverviewNamesPanel({ title, hint, data, usersLink, onClose }: { title: string; hint: string; data: NamedCount; usersLink: boolean; onClose: () => void }) {
  const { t: tr } = useLanguage("admin");
  return <div className="admin-overview-details">
    <div className="admin-overview-panel-header"><h2><TranslatedText>{tr(title)}</TranslatedText></h2><button type="button" className="text-button" onClick={onClose}><TranslatedText>{tr("Close")}</TranslatedText></button></div>
    <p className="admin-muted">{tr(hint)}</p>
    {data.names.length ? <ul className="admin-overview-list">{data.names.map((name, index) => <li key={index}>{name}</li>)}</ul> : <p className="admin-muted"><TranslatedText>{tr("Nothing here.")}</TranslatedText></p>}
    {data.count > data.names.length && <p className="admin-muted">{tr("and {count} more", { count: data.count - data.names.length })}</p>}
    {usersLink && <Link className="button admin-button" to="/admin/users"><TranslatedText>{tr("Open users")}</TranslatedText></Link>}
  </div>;
}

export function AdminOverviewPage() {
  const { t: tr, locale: uiLocale } = useLanguage("admin");
  const { permissions } = useAuth();
  const canCleanSandbox = Boolean(permissions?.includes("sandbox.cleanup"));
  const canManageUsers = Boolean(permissions?.includes("user.manage"));
  const [notice, setNotice] = useState("");
  const [attention, setAttention] = useState<OverviewAttention | null>(null);
  const [activities, setActivities] = useState<any[]>([]);
  // Kiểm tra dịch vụ và đọc SQL Server có thể chậm nên tải riêng, không chặn cả trang.
  const [health, setHealth] = useState<ServiceHealth[] | null>(null);
  const [healthError, setHealthError] = useState("");
  const [exams, setExams] = useState<UpcomingExam[] | null>(null);
  const [sandbox, setSandbox] = useState<SandboxSummary | null>(null);
  const [sandboxError, setSandboxError] = useState("");
  const [selection, setSelection] = useState<{ type: "attention" | "activity"; id: string } | null>(null);
  const selectedActivity = selection?.type === "activity" ? activities.find(item => item.id === selection.id) : undefined;
  const [activityPage, setActivityPage] = useState(1);
  const pageSize = 10;
  const currentActivityPage = Math.min(activityPage, Math.max(1, Math.ceil(activities.length / pageSize)));
  const visibleActivities = activities.slice((currentActivityPage - 1) * pageSize, currentActivityPage * pageSize);
  const [loading, setLoading] = useState(true);

  const loadHealth = useCallback(async () => {
    setHealth(null); setHealthError("");
    try { setHealth(await adminService.getHealth()); }
    catch (err) { setHealth([]); setHealthError(err instanceof Error ? err.message : "Could not check the services."); }
  }, []);

  const loadSandbox = useCallback(async () => {
    setSandbox(null); setSandboxError("");
    try { setSandbox(await adminService.getSandbox()); }
    catch (err) { setSandboxError(err instanceof Error ? err.message : "Could not read schemas."); }
  }, []);

  useEffect(() => {
    Promise.all([adminService.getAttention(), adminService.getActivities()]).then(([attentionData, activitiesData]) => {
      setAttention(attentionData);
      setActivities(activitiesData);
      setLoading(false);
    }).catch(err => {
      console.error(err);
      setNotice("Failed to load overview data.");
      setLoading(false);
    });
    void loadHealth();
    adminService.getUpcomingExams().then(setExams).catch(() => setExams([]));
  }, [loadHealth]);

  useEffect(() => { if (canCleanSandbox) void loadSandbox(); }, [canCleanSandbox, loadSandbox]);

  const pageClass = "admin-page compact-tables-page admin-overview-page" + (selection ? " has-overview-selection" : "");
  if (loading) {
    return <div className={pageClass}><h1 className="sr-only"><TranslatedText>{tr("System overview")}</TranslatedText></h1><Loading variant="dashboard" metrics={4} label={tr("Loading overview…")} /></div>;
  }
  if (notice || !attention) return <div className={pageClass}><h1 className="sr-only"><TranslatedText>{tr("System overview")}</TranslatedText></h1><p className="admin-notice" role="alert"><TranslatedText>{tr(notice)}</TranslatedText></p></div>;

  const attentionItems: { id: string; title: string; count: number | null; unit: string; warn: boolean }[] = [
    { id: "no-role", title: "Accounts without a role", count: attention.usersWithoutRole.count, unit: "account(s)", warn: attention.usersWithoutRole.count > 0 },
    { id: "access-managers", title: "People who can manage roles", count: attention.accessManagers.count, unit: "person(s)", warn: attention.accessManagers.count <= 1 },
    ...(attention.unassignedClasses == null ? [] : [{ id: "unassigned", title: "Classes without a lecturer", count: attention.unassignedClasses, unit: "class(es)", warn: attention.unassignedClasses > 0 }]),
    ...(canCleanSandbox ? [{ id: "sandbox", title: "Student schemas on SQL Server", count: sandbox ? sandbox.totals.student + sandbox.totals.temporary : null, unit: "schema(s)", warn: false }] : []),
  ];
  const closeSelection = () => setSelection(null);
  const selectedAttention = selection?.type === "attention" ? selection.id : "";
  const examTime = (value: string | null) => value ? parseServerDateTime(value).toLocaleString(uiLocale) : "—";

  const side = selectedAttention === "unassigned" ? <OverviewUnassignedClassesPanel onClose={closeSelection} />
    : selectedAttention === "no-role" ? <OverviewNamesPanel title="Accounts without a role" hint="These active accounts cannot open any page. Give them a role." data={attention.usersWithoutRole} usersLink={canManageUsers} onClose={closeSelection} />
    : selectedAttention === "access-managers" ? <OverviewNamesPanel title="People who can manage roles" hint="Keep at least two people with this permission, so access can still be managed if one account is unavailable." data={attention.accessManagers} usersLink={canManageUsers} onClose={closeSelection} />
    : selectedAttention === "sandbox" ? <SandboxCleanupPanel summary={sandbox} error={sandboxError} onReload={loadSandbox} onClose={closeSelection} />
    : selectedActivity ? <OverviewActivityPanel key={selectedActivity.id} activity={selectedActivity} onClose={closeSelection} />
    : <>
      <div className="admin-overview-panel-header"><h2><TranslatedText>{tr("System status")}</TranslatedText></h2><button type="button" className="text-button" disabled={health === null} onClick={() => void loadHealth()}><TranslatedText>{tr("Check again")}</TranslatedText></button></div>
      {health === null ? <p className="admin-muted"><TranslatedText>{tr("Checking services…")}</TranslatedText></p> : healthError ? <p className="admin-warning" role="alert">{healthError}</p>
        : <ul className="admin-overview-list">{health.map(item => <li key={item.key}>
          <div><strong>{tr(SERVICE_NAMES[item.key] || item.key)}</strong><span>{item.ok ? tr("Responded in {ms} ms", { ms: item.ms }) : item.detail}</span></div>
          <span className={"status " + (item.ok ? "success" : "danger")}><TranslatedText>{tr(item.ok ? "Working" : "Unreachable")}</TranslatedText></span>
        </li>)}</ul>}
      <div className="admin-detail-divider" />
      <h3 className="admin-subheading"><TranslatedText>{tr("Exams in the next 24 hours")}</TranslatedText></h3>
      {exams === null ? <p className="admin-muted"><TranslatedText>{tr("Loading…")}</TranslatedText></p> : exams.length ? <ul className="admin-overview-list">{exams.map(exam => <li key={exam.id}>
        <div><strong>{exam.title}</strong><span>{examTime(exam.opens)} – {examTime(exam.closes)}</span><span>{tr("{count} candidates", { count: exam.students })}</span></div>
        <span className={"status " + (exam.status === "Live" ? "warning" : "neutral")}><TranslatedText>{tr(exam.status === "Live" ? "In progress now" : "Upcoming")}</TranslatedText></span>
      </li>)}</ul> : <p className="admin-muted"><TranslatedText>{tr("No exam in the next 24 hours.")}</TranslatedText></p>}
    </>;

  return <div className={pageClass}>
    <h1 className="sr-only"><TranslatedText>{tr("System overview")}</TranslatedText></h1>
    <Split className="admin-overview-split" main={<>
      <section className="admin-attention">
        <div className="admin-overview-table-heading">
          <h2 className="admin-section-heading"><TranslatedText>{tr("Needs attention")}</TranslatedText></h2>
        </div>
        <div className="compact-table-scroll" role="region" aria-label={tr("Needs attention table")} tabIndex={0}>
        <Table className="admin-table admin-attention-table"><thead><tr><th><TranslatedText>{tr("ITEM")}</TranslatedText></th><th><TranslatedText>{tr("COUNT")}</TranslatedText></th></tr></thead><tbody>
          {attentionItems.map(item => {
            const isSelected = selectedAttention === item.id;
            const selectRow = () => setSelection({ type: "attention", id: item.id });
            return <TableRow selected={isSelected} key={item.id} className={isSelected ? "is-selected" : ""} onClick={selectRow}>
              <td data-label={tr("ITEM")}><button type="button" className="admin-overview-row-button" aria-pressed={isSelected} onClick={selectRow}><TranslatedText>{tr(item.title)}</TranslatedText></button></td>
              <td data-label={tr("COUNT")} className={item.warn ? "admin-warning" : ""}>{item.count ?? "…"} <TranslatedText>{tr(item.unit)}</TranslatedText></td>
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
    </>} side={side} />
  </div>;
}
