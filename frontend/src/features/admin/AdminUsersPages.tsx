import { Select } from "@/shared/ui/Select";
import { useListOrder } from "@/shared/components/ListSortSelect";
import { useListDateFilters } from "@/shared/components/ListDateFilters";
import { matchesListSearch } from "@/shared/lib/listSearch";
import { Table, TableRow } from "@/shared/ui/Table";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import { useLanguage } from "@/shared/context/LanguageContext";
import { useEffect, useMemo, useRef, useState, type FormEvent } from "react";
import { Link, useLocation, useNavigate, useParams } from "react-router-dom";
import { Dialog, ErrorState, Status, Loading } from "@/shared/ui/ui";
import { adminService, type AdminUser } from "@/features/admin/adminService";
import { ListFilter, Upload, Download } from "lucide-react";
import { TeacherListFilters } from "@/shared/components/TeacherListFilters";
import { TeacherListPagination } from "@/shared/components/TeacherListPagination";
import { TeacherListLoading } from "@/shared/components/TeacherListLoading";

import { PageIntro, Field, Notice, AddUserDialog, BulkImportDialog } from "@/features/admin/AdminShared";

export function AdminUsersPage() {
  const { t: tr } = useLanguage("admin");
  const location = useLocation();
  const navigate = useNavigate();
  const [users, setUsers] = useState<AdminUser[]>([]);
  const [selectedEmail, setSelectedEmail] = useState("");
  const order = useListOrder();
  const dates = useListDateFilters();
  const [query, setQuery] = useState("");
  const [roleFilter, setRoleFilter] = useState("All roles");
  const [statusFilter, setStatusFilter] = useState("All statuses");
  const [page, setPage] = useState(1);
  const [actionsTarget, setActionsTarget] = useState<HTMLDivElement | null>(null);
  const tableScrollRef = useRef<HTMLDivElement>(null);
  const pageSize = 15;
  const [dialog, setDialog] = useState(false);
  const [bulkImportDialog, setBulkImportDialog] = useState(false);
  const [notice, setNotice] = useState("");
  const [loading, setLoading] = useState(true);
  const [pendingRole, setPendingRole] = useState<string | null>(null);
  const [pendingRequestCount, setPendingRequestCount] = useState(0);
  const [loadError, setLoadError] = useState("");

  useEffect(() => {
    setPage(1);
  }, [dates.key, order.value]);

  useEffect(() => {
    adminService.getUsers().then(data => {
      setUsers(data);
      if (data.length > 0) {
        setSelectedEmail(current => current || data[0].email);
      }
      setLoading(false);
    }).catch(err => {
      setLoadError(err instanceof Error ? err.message : "Failed to load users.");
      setLoading(false);
    });

    adminService.getLecturerRequests().then(requests => {
      setPendingRequestCount(requests.length);
    }).catch(console.error);
  }, []);

  const filteredRows = useMemo(() => users.filter((user) => {
    const matchesQuery = matchesListSearch(query, user.id, user.name, user.email);
    return dates.matches(user) && matchesQuery && (roleFilter === "All roles" || user.role === roleFilter) && (statusFilter === "All statuses" || user.status === statusFilter);
  }), [users, query, roleFilter, statusFilter, dates.key]);

  const filtered = order.apply(filteredRows, item => item.id || "");
  const pageCount = Math.max(1, Math.ceil(filtered.length / pageSize));
  const currentPage = Math.min(page, pageCount);
  const firstIndex = (currentPage - 1) * pageSize;
  const pageUsers = filtered.slice(firstIndex, firstIndex + pageSize);
  const selected = pageUsers.find((user) => user.email === selectedEmail) || pageUsers[0];

  useEffect(() => {
    if (loading) return;
    const state = location.state as { selectedEmail?: string; notice?: string } | null;
    if (state?.selectedEmail) {
      setSelectedEmail(state.selectedEmail);
      const userIndex = filtered.findIndex(user => user.email === state.selectedEmail);
      if (userIndex >= 0) setPage(Math.floor(userIndex / pageSize) + 1);
    }
    if (state?.notice) setNotice(state.notice);
    if (state) window.history.replaceState({}, document.title);
  }, [location.state, loading]);

  useEffect(() => {
    setPendingRole(null);
  }, [selected?.email]);

  useEffect(() => {
    setPage(1);
    setSelectedEmail("");
  }, [query, roleFilter, statusFilter]);

  useEffect(() => {
    tableScrollRef.current?.scrollTo({ top: 0 });
  }, [currentPage, query, roleFilter, statusFilter]);

  async function handleUpdateRole(userToUpdate: AdminUser, newRole: string) {
    try {
      const updated = await adminService.updateUser(userToUpdate.email, { ...userToUpdate, role: newRole });
      setUsers(all => all.map(u => u.email === updated.email ? updated : u));
    } catch (err: any) {
      setNotice(err.message || "Failed to update role");
    }
  }

  function exportCsv() {
    const csv = ["Name,Email,Role,Status", ...users.map((u) => [u.name, u.email, u.role, u.status].join(","))].join("\n");
    const url = URL.createObjectURL(new Blob([csv], { type: "text/csv" }));
    const link = document.createElement("a");
    link.href = url;
    link.download = "uit-sql-users.csv";
    link.click();
    URL.revokeObjectURL(url);
  }

  const chooseUser = (user: AdminUser) => {
    if (selected?.email === user.email) {
      navigate(`/admin/users/${encodeURIComponent(user.email)}/edit`);
    } else {
      setSelectedEmail(user.email);
    }
  };

  if (loading) {
    return (
      <section className="teacher-page teacher-list-page compact-teacher-contests admin-users-page">
        <h1 className="sr-only"><TranslatedText>{tr("Users")}</TranslatedText></h1>
        <TeacherListLoading
          label={tr("Loading users…")}
          createLabel={tr("Add user")}
          onCreate={() => setDialog(true)}
          columns={5}
        />
      </section>
    );
  }

  if (loadError) {
    return (
      <section className="teacher-page teacher-list-page compact-teacher-contests admin-users-page">
        <h1 className="sr-only"><TranslatedText>{tr("Users")}</TranslatedText></h1>
        <ErrorState
          title={tr("Could not load users")}
          message={tr(loadError)}
          onRetry={() => window.location.reload()}
        />
      </section>
    );
  }

  return (
    <section className="teacher-page teacher-list-page compact-teacher-contests admin-users-page">
      <h1 className="sr-only"><TranslatedText>{tr("Users")}</TranslatedText></h1>
      {pendingRequestCount > 0 && (
        <div className="admin-pending-banner">
          <span>
            {pendingRequestCount}{" "}
            <TranslatedText>
              {tr("lecturer registrations are awaiting approval. Lecturer access stays locked until approved.")}
            </TranslatedText>
          </span>
          <Link className="text-button" to="/admin/users/approvals">
            <TranslatedText>{tr("Review requests →")}</TranslatedText>
          </Link>
        </div>
      )}
      {notice && <p className="teacher-form-message" role="status"><TranslatedText>{tr(notice)}</TranslatedText></p>}
      <div className="teacher-list-detail-layout teacher-filter-layout">
        <div className="teacher-list-detail-main">
          <TeacherListFilters
            order={order}
            dates={dates}
            suggestions={filtered.map((user, index) => ({
              id: user.id || String(index),
              title: user.name,
              value: user.email,
              description: `${user.email} · ${tr(user.role)}`,
            }))}
            search={query}
            onSearchChange={setQuery}
            searchLabel={tr("Search name or email...")}
            actionsTarget={actionsTarget}
            createLabel={tr("Add user")}
            onCreate={() => setDialog(true)}
            secondaryAction={
              <div className="admin-user-secondary-actions">
                <button
                  type="button"
                  className="button admin-action-btn"
                  onClick={() => setBulkImportDialog(true)}
                >
                  <Upload size={14} aria-hidden="true" />
                  <TranslatedText>{tr("Import Users")}</TranslatedText>
                </button>
                <button
                  type="button"
                  className="button admin-action-btn"
                  onClick={exportCsv}
                  disabled={users.length === 0}
                >
                  <Download size={14} aria-hidden="true" />
                  <TranslatedText>{tr("Export CSV")}</TranslatedText>
                </button>
              </div>
            }
            filters={[
              {
                key: "role",
                label: tr("ROLE"),
                value: roleFilter,
                defaultValue: "All roles",
                onChange: setRoleFilter,
                icon: <ListFilter size={17} aria-hidden="true" />,
                options: [
                  { value: "All roles", label: tr("All roles") },
                  { value: "Student", label: tr("Student") },
                  { value: "Lecturer", label: tr("Lecturer") },
                  { value: "Faculty office", label: tr("Faculty office") },
                  { value: "Admin", label: tr("Admin") },
                ],
              },
              {
                key: "status",
                label: tr("STATUS"),
                value: statusFilter,
                defaultValue: "All statuses",
                onChange: setStatusFilter,
                icon: <ListFilter size={17} aria-hidden="true" />,
                options: [
                  { value: "All statuses", label: tr("All statuses") },
                  { value: "Active", label: tr("Active") },
                  { value: "Inactive", label: tr("Inactive") },
                  { value: "Pending", label: tr("Pending") },
                ],
              },
            ]}
            onReset={() => {
              setQuery("");
              setRoleFilter("All roles");
              setStatusFilter("All statuses");
            }}
          />
          <div ref={tableScrollRef} className="teacher-table-scroll teacher-list-table-scroll sticky-list-table-wrap">
            <Table className="teacher-table teacher-user-list-table sticky-list-table">
              <thead>
                <tr>
                  <th><TranslatedText>{tr("ID")}</TranslatedText></th>
                  <th><TranslatedText>{tr("NAME / EMAIL")}</TranslatedText></th>
                  <th><TranslatedText>{tr("ROLE")}</TranslatedText></th>
                  <th><TranslatedText>{tr("STATUS")}</TranslatedText></th>
                  <th><TranslatedText>{tr("LAST ACTIVE")}</TranslatedText></th>
                </tr>
              </thead>
              <tbody>
                {pageUsers.map((user, index) => (
                  <TableRow
                    selected={selected?.email === user.email}
                    key={user.email}
                    className={`teacher-selectable-list-row${selected?.email === user.email ? " is-selected" : ""}`}
                    onClick={() => chooseUser(user)}
                    onDoubleClick={() => navigate(`/admin/users/${encodeURIComponent(user.email)}/edit`)}
                  >
                    <td data-label="ID">{firstIndex + index + 1}</td>
                    <td data-label={tr("NAME / EMAIL")}>
                      <button
                        type="button"
                        className="teacher-selectable-row-title"
                        onClick={(event) => {
                          event.stopPropagation();
                          chooseUser(user);
                        }}
                      >
                        {user.name}
                      </button>
                      <small className="teacher-row-subtext">{user.email}</small>
                    </td>
                    <td data-label={tr("ROLE")}><TranslatedText>{tr(user.role)}</TranslatedText></td>
                    <td data-label={tr("STATUS")}><Status value={user.status} /></td>
                    <td data-label={tr("LAST ACTIVE")}>{user.lastActive || "—"}</td>
                  </TableRow>
                ))}
                {!filtered.length && (
                  <tr>
                    <td className="teacher-empty-row" colSpan={5}>
                      <TranslatedText>{users.length === 0 ? tr("No accounts yet") : tr("No matching accounts")}</TranslatedText>
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
                {filtered.length ? firstIndex + 1 : 0}–{firstIndex + pageUsers.length}{" "}
                <TranslatedText>{tr("of")}</TranslatedText> {filtered.length}{" "}
                <TranslatedText>{tr("accounts")}</TranslatedText>
              </>
            }
            paginationLabel={tr("User pages")}
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
              setSelectedEmail("");
            }}
          />
        </div>
        <div className="teacher-filter-side">
          <div ref={setActionsTarget} className="teacher-filter-actions" />
          <aside className="teacher-list-detail-panel">
            {selected ? (
              <>
                <span className="teacher-detail-eyebrow">
                  <TranslatedText>{tr("ACCOUNT DETAILS")}</TranslatedText>
                </span>
                <h2>{selected.name}</h2>
                <p className="muted">{selected.email}</p>
                <dl className="teacher-list-detail-facts">
                  <div>
                    <dt><TranslatedText>{tr("Role")}</TranslatedText></dt>
                    <dd>
                      <Select
                        value={pendingRole ?? selected.role}
                        onChange={(e) => setPendingRole(e.target.value)}
                        className="admin-user-role-select"
                      >
                        <option value="Student">{tr("Student")}</option>
                        <option value="Lecturer">{tr("Lecturer")}</option>
                        <option value="Faculty office">{tr("Faculty office")}</option>
                        <option value="Admin">{tr("Admin")}</option>
                      </Select>
                    </dd>
                  </div>
                  {pendingRole && pendingRole !== selected.role && (
                    <div className="admin-user-save-role-row">
                      <button
                        type="button"
                        className="button primary"
                        onClick={() => {
                          handleUpdateRole(selected, pendingRole);
                          setPendingRole(null);
                        }}
                      >
                        <TranslatedText>{tr("Save role")}</TranslatedText>
                      </button>
                    </div>
                  )}
                  <div>
                    <dt><TranslatedText>{tr("Status")}</TranslatedText></dt>
                    <dd><Status value={selected.status} /></dd>
                  </div>
                  <div>
                    <dt><TranslatedText>{tr("Joined")}</TranslatedText></dt>
                    <dd>{selected.joined || "—"}</dd>
                  </div>
                  <div>
                    <dt><TranslatedText>{tr("Last active")}</TranslatedText></dt>
                    <dd>{selected.lastActive || "—"}</dd>
                  </div>
                </dl>
                {selected.detail && <p className="muted admin-user-detail-notes">{selected.detail}</p>}
                <div className="teacher-list-detail-actions">
                  <Link className="button primary" to={`/admin/users/${encodeURIComponent(selected.email)}/edit`}>
                    <TranslatedText>{tr("Edit account")}</TranslatedText>
                  </Link>
                </div>
              </>
            ) : (
              <p className="muted"><TranslatedText>{tr("Select a user to view details.")}</TranslatedText></p>
            )}
          </aside>
        </div>
      </div>
      {dialog && (
        <AddUserDialog
          onClose={() => setDialog(false)}
          onAdd={(user) => {
            setUsers((all) => [user, ...all]);
            setSelectedEmail(user.email);
            setPage(1);
            setDialog(false);
            setNotice(`${user.name} was added successfully.`);
          }}
        />
      )}
      {bulkImportDialog && (
        <BulkImportDialog
          onClose={() => setBulkImportDialog(false)}
          onSuccess={(msg) => {
            setBulkImportDialog(false);
            setNotice(msg);
            adminService.getUsers().then(setUsers);
          }}
        />
      )}
    </section>
  );
}

export function AdminEditAccountPage() {
  const { t: tr } = useLanguage("admin");
  const { email: encodedEmail = "" } = useParams();
  const email = decodeURIComponent(encodedEmail);
  const navigate = useNavigate();

  const [currentUser, setCurrentUser] = useState<AdminUser | null>(null);
  const [name, setName] = useState("");
  const [accountEmail, setAccountEmail] = useState("");
  const [role, setRole] = useState<AdminUser["role"]>("Student");
  const [status, setStatus] = useState<AdminUser["status"]>("Active");
  const [notice, setNotice] = useState("");
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [loadError, setLoadError] = useState("");
  const [confirmDeactivationSave, setConfirmDeactivationSave] = useState(false);

  useEffect(() => {
    adminService.getUsers().then(users => {
      const found = users.find(u => u.email === email);
      if (found) {
        setCurrentUser(found);
        setName(found.name);
        setAccountEmail(found.email);
        setRole(found.role);
        setStatus(found.status);
      }
    }).catch(err => setLoadError(err instanceof Error ? err.message : "Could not load account.")).finally(() => setLoading(false));
  }, [email]);

  if (loading) return <div className="admin-page"><Loading variant="form" sidebar={false} label={tr("Loading account…")} /></div>;
  if (loadError) return <div className="admin-page"><PageIntro title={tr("Could not load account")} /><Notice><TranslatedText>{tr(loadError)}</TranslatedText></Notice><Link className="button admin-button" to="/admin/users"><TranslatedText>{tr("Back to users")}</TranslatedText></Link></div>;
  if (!currentUser) return <div className="admin-page"><PageIntro title={tr("Account not found")} /><p className="admin-muted"><TranslatedText>{tr("This account may have been removed or its email has changed.")}</TranslatedText></p><Link className="button admin-button" to="/admin/users"><TranslatedText>{tr("Back to users")}</TranslatedText></Link></div>;

  async function persistAccount() {
    if (!currentUser) return;
    const nextName = name.trim();
    const nextEmail = accountEmail.trim().toLowerCase();
    if (!nextName || !nextEmail) { setNotice("Name and email are required."); return; }

    setConfirmDeactivationSave(false);
    setSaving(true);
    try {
      await adminService.updateUser(currentUser.email, {
        name: nextName,
        email: nextEmail,
        role,
        status
      });
      navigate("/admin/users", { state: { selectedEmail: nextEmail, notice: `Account updated for ${nextName}.` } });
    } catch (err: any) {
      setNotice(err.message || "Failed to update account.");
      setSaving(false);
    }
  }

  function save(event: FormEvent) {
    event.preventDefault();
    if (currentUser?.status !== "Inactive" && status === "Inactive") {
      setConfirmDeactivationSave(true);
      return;
    }
    void persistAccount();
  }

  return <div className="admin-page">
    <div className="admin-edit-layout admin-account-edit-layout">
      <form className="admin-edit-form" onSubmit={save}>
        <div className="admin-account-form-header">
          <div><h1><TranslatedText>{tr("Account information")}</TranslatedText></h1><p className="admin-muted"><TranslatedText>{tr("Update this user’s profile, role, and sign-in status.")}</TranslatedText></p></div>
          <Link className="button admin-button" to="/admin/users"><TranslatedText>{tr("Back to users")}</TranslatedText></Link>
        </div>
        <div className="admin-account-fields">
          <Field label={tr("FULL NAME")}><input value={name} onChange={(event) => setName(event.target.value)} required /></Field>
          <Field label={tr("EMAIL ADDRESS")}><input type="email" value={accountEmail} onChange={(event) => setAccountEmail(event.target.value)} required /></Field>
          <Field label={tr("ROLE")}><Select value={role} onChange={(event) => setRole(event.target.value as AdminUser["role"])}><option value="Student">{tr("Student")}</option><option value="Lecturer">{tr("Lecturer")}</option><option value="Faculty office">{tr("Faculty office")}</option><option value="Admin">{tr("Admin")}</option></Select></Field>
          <Field label={tr("ACCOUNT STATUS")}><Select value={status} onChange={(event) => setStatus(event.target.value as AdminUser["status"])}><option value="Active">{tr("Active")}</option><option value="Inactive">{tr("Inactive")}</option><option value="Pending">{tr("Pending")}</option></Select></Field>
        </div>
        {notice && <Notice><TranslatedText>{tr(notice)}</TranslatedText></Notice>}
        <div className="admin-edit-actions"><Link className="button admin-button" to="/admin/users"><TranslatedText>{tr("Cancel")}</TranslatedText></Link><button className="button primary admin-button" disabled={saving}><TranslatedText>{saving ? tr("Saving...") : tr("Save changes")}</TranslatedText></button></div>
      </form>
    </div>
    {confirmDeactivationSave && <Dialog title={tr("Deactivate account")} onClose={() => setConfirmDeactivationSave(false)} className="admin-dialog">
      <div className="admin-dialog-form">
        <p><TranslatedText>{tr("Save these changes and deactivate")}</TranslatedText> <strong>{currentUser.name}</strong> ({currentUser.email}<TranslatedText>{tr(")? They will no longer be able to sign in. Their classes and submissions will be retained.")}</TranslatedText></p>
        <div className="admin-dialog-actions"><button type="button" className="button" onClick={() => setConfirmDeactivationSave(false)}><TranslatedText>{tr("Cancel")}</TranslatedText></button><button type="button" className="button admin-reject-button" onClick={() => void persistAccount()}><TranslatedText>{tr("Save and deactivate")}</TranslatedText></button></div>
      </div>
    </Dialog>}
  </div>;
}
