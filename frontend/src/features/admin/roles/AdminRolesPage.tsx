import React, { useCallback, useEffect, useMemo, useState } from "react";
import { adminService, type AdminUser } from "@/features/admin/adminService";
import { roleService } from "./roleService";
import { buildPermissionGroups } from "./permissionCatalog";
import { RoleEditor } from "./RoleEditor";
import { UserRoleAssignmentPanel } from "./UserRoleAssignmentPanel";
import type { RoleForm, RoleGroup, UserRoleAssignment } from "./types";
import "./roles.css";
import { notify } from "@/shared/ui/notify";
import { Loading } from "@/shared/ui/ui";

const NEW_ROLE = "new";

const getErrorMessage = (error: unknown) =>
  error instanceof Error ? error.message : "Không thể hoàn tất thao tác.";

export const AdminRolesPage: React.FC = () => {
  const [activeTab, setActiveTab] = useState<"roles" | "users">("roles");

  const [customRoles, setCustomRoles] = useState<RoleGroup[]>([]);
  const [systemRoles, setSystemRoles] = useState<RoleGroup[]>([]);
  const [serverPermissions, setServerPermissions] = useState<Record<string, string>>({});
  const [selectedRoleId, setSelectedRoleId] = useState<string>(NEW_ROLE);
  const [template, setTemplate] = useState<string[] | undefined>();
  const [editorDirty, setEditorDirty] = useState(false);

  const [users, setUsers] = useState<AdminUser[]>([]);
  const [selectedUserId, setSelectedUserId] = useState("");
  const [assignment, setAssignment] = useState<UserRoleAssignment | null>(null);

  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  const groups = useMemo(() => buildPermissionGroups(serverPermissions), [serverPermissions]);

  useEffect(() => {
    let active = true;
    Promise.all([roleService.getCatalog(), adminService.getUsers()])
      .then(([catalog, userList]) => {
        if (!active) return;
        setCustomRoles(catalog.customRoles);
        setSystemRoles(catalog.systemRoles);
        setServerPermissions(catalog.permissions);
        setUsers(userList);
        setSelectedRoleId(catalog.customRoles[0]?.id ?? NEW_ROLE);
      })
      .catch((e) => active && setError(getErrorMessage(e)))
      .finally(() => active && setLoading(false));
    return () => { active = false; };
  }, []);

  useEffect(() => {
    let active = true;
    if (!selectedUserId) { setAssignment(null); return; }
    roleService.getUserAssignment(selectedUserId)
      .then((data) => active && setAssignment(data))
      .catch((e) => active && setError(getErrorMessage(e)));
    return () => { active = false; };
  }, [selectedUserId]);

  const allRoles = useMemo(() => [...customRoles, ...systemRoles], [customRoles, systemRoles]);
  const selectedRole = selectedRoleId === NEW_ROLE ? null : allRoles.find((role) => role.id === selectedRoleId) || null;

  const clearMessages = () => setError("");

  /** Ask before throwing away unsaved role edits. */
  const confirmDiscard = () => !editorDirty || window.confirm("Bạn có thay đổi chưa lưu ở vai trò đang mở. Bỏ thay đổi?");

  const openRole = (id: string) => {
    if (id !== selectedRoleId && !confirmDiscard()) return;
    setSelectedRoleId(id);
    setTemplate(undefined);
    clearMessages();
  };

  const switchTab = (tab: "roles" | "users") => {
    if (tab === activeTab) return;
    if (activeTab === "roles" && !confirmDiscard()) return;
    setActiveTab(tab);
    clearMessages();
  };

  const showRoleFromUser = (id: string) => {
    setActiveTab("roles");
    setSelectedRoleId(id);
    setTemplate(undefined);
    clearMessages();
  };

  const duplicate = (permissions: string[]) => {
    if (!confirmDiscard()) return;
    setSelectedRoleId(NEW_ROLE);
    setTemplate([...permissions]);
    notify.info("Đã sao chép quyền. Hãy đặt tên và tạo vai trò mới.");
    setError("");
  };

  const refreshAssignment = async () => {
    if (selectedUserId) setAssignment(await roleService.getUserAssignment(selectedUserId));
  };

  const saveRole = async (form: RoleForm) => {
    setBusy(true);
    clearMessages();
    try {
      if (form.id) {
        const saved = await roleService.updateRole(form.id, form);
        const replace = (list: RoleGroup[]) => list.map((role) => (role.id === saved.id ? saved : role));
        if (saved.isSystem) setSystemRoles(replace); else setCustomRoles(replace);
        notify.success(`Đã cập nhật “${saved.name}”.`);
      } else {
        const saved = await roleService.createRole(form);
        setCustomRoles((current) => [...current, saved]);
        setSelectedRoleId(saved.id);
        setTemplate(undefined);
        notify.success(`Đã tạo vai trò “${saved.name}”.`);
      }
      await refreshAssignment();
    } catch (e) {
      notify.fail("Không lưu được vai trò.", e);
    } finally {
      setBusy(false);
    }
  };

  const saveUserRoles = async (userId: string, roleIds: string[], directPermissions: string[]) => {
    setBusy(true);
    clearMessages();
    try {
      setAssignment(await roleService.saveUserRoles(userId, roleIds, directPermissions));
      notify.success("Đã cập nhật vai trò và quyền riêng của người dùng.");
    } catch (e) {
      notify.fail("Không lưu được vai trò và quyền của người dùng.", e);
    } finally {
      setBusy(false);
    }
  };

  const saveStudentCode = async (userId: string, code: string) => {
    setBusy(true);
    clearMessages();
    try {
      await roleService.saveStudentCode?.(userId, code);
      notify.success("Đã lưu mã số sinh viên.");
    } catch (e) {
      notify.fail("Không lưu được mã số sinh viên.", e);
    } finally {
      setBusy(false);
    }
  };

  const onDirtyChange = useCallback((dirty: boolean) => setEditorDirty(dirty), []);

  const roleButton = (role: RoleGroup) => (
    <button
      type="button"
      key={role.id}
      className={`role-nav-item ${selectedRoleId === role.id ? "is-selected" : ""}`}
      disabled={busy}
      aria-current={selectedRoleId === role.id ? "true" : undefined}
      onClick={() => openRole(role.id)}
    >
      <span className="role-nav-text">
        <span className="role-nav-name">{role.name}</span>
        <span className="role-nav-count">{role.readOnly ? "Toàn quyền" : `${role.permissions.length} quyền`}{role.isSystem && <em className="role-nav-tag">Mặc định</em>}</span>
      </span>
    </button>
  );

  const startNewRole = () => {
    if ((selectedRoleId !== NEW_ROLE || template) && !confirmDiscard()) return;
    setSelectedRoleId(NEW_ROLE);
    setTemplate(undefined);
    clearMessages();
  };

  return (
    <div className="admin-page roles-page">
      <div className="roles-tabs" role="tablist" aria-label="Phân quyền">
        <button type="button" role="tab" aria-selected={activeTab === "roles"} className={activeTab === "roles" ? "is-active" : ""} onClick={() => switchTab("roles")}>
          Vai trò
          <span className="roles-tab-count">{loading ? "…" : customRoles.length + systemRoles.length}</span>
        </button>
        <button type="button" role="tab" aria-selected={activeTab === "users"} className={activeTab === "users" ? "is-active" : ""} onClick={() => switchTab("users")}>
          Gán cho người dùng
          <span className="roles-tab-count">{loading ? "…" : users.length}</span>
        </button>
      </div>

      {error && <p role="alert" className="roles-banner is-error">{error}</p>}

      {loading ? (
        <Loading variant="detail" label="Đang tải vai trò và quyền…" />
      ) : activeTab === "roles" ? (
        <div className="roles-main-layout">
          <aside className="roles-nav-pane roles-card">
            <button type="button" className={`button primary role-create-btn${selectedRoleId === NEW_ROLE ? " is-active" : ""}`} disabled={busy} onClick={startNewRole}>
              Tạo vai trò mới
            </button>

            <div className="role-nav-group">
              {customRoles.length === 0 && <div className="role-nav-empty">Chưa có vai trò tự tạo.</div>}
              {[...customRoles, ...systemRoles].map(roleButton)}
            </div>
          </aside>

          <section className="roles-editor-pane roles-card">
            <RoleEditor
              role={selectedRole}
              groups={groups}
              template={template}
              onSave={saveRole}
              onDuplicate={duplicate}
              onDirtyChange={onDirtyChange}
              busy={busy}
            />
          </section>
        </div>
      ) : (
        <UserRoleAssignmentPanel
          users={users}
          groups={groups}
          systemRoles={systemRoles}
          customRoles={customRoles}
          selectedUserId={selectedUserId}
          onSelectUser={setSelectedUserId}
          assignment={assignment}
          onSaveRoles={saveUserRoles}
          onSaveStudentCode={saveStudentCode}
          onOpenRole={showRoleFromUser}
          busy={busy}
        />
      )}
    </div>
  );
};
