import React, { useEffect, useMemo, useState } from "react";
import type { AdminUser } from "@/features/admin/adminService";
import { Field } from "@/features/admin/AdminShared";
import { EffectivePermissionsPreview } from "./EffectivePermissionsPreview";
import { PermissionPicker } from "./PermissionPicker";
import { permissionSources, sameSet, type PermissionGroup } from "./permissionCatalog";
import type { RoleGroup, UserRoleAssignment } from "./types";

export interface UserRoleAssignmentPanelProps {
  users: AdminUser[];
  groups: PermissionGroup[];
  systemRoles: RoleGroup[];
  customRoles: RoleGroup[];
  selectedUserId: string;
  onSelectUser: (userId: string) => void;
  assignment: UserRoleAssignment | null;
  onSaveRoles: (userId: string, roleGroupIds: string[], directPermissions: string[]) => Promise<void>;
  onSaveStudentCode: (userId: string, code: string) => Promise<void>;
  onOpenRole: (roleId: string) => void;
  busy?: boolean;
}

const ACTOR_LABELS: Record<string, string> = {
  student: "Sinh viên",
  instructor: "Giảng viên",
  faculty_office: "Giáo vụ khoa",
  admin: "Quản trị viên",
};

const initialsOf = (name?: string) => (name || "?").trim().split(/\s+/).slice(-2).map((part) => part.charAt(0)).join("").toUpperCase();

const normalize = (text: string) => text.normalize("NFD").replace(/[̀-ͯ]/g, "").replace(/đ/g, "d").toLowerCase();

export const UserRoleAssignmentPanel: React.FC<UserRoleAssignmentPanelProps> = ({
  users, groups, systemRoles, customRoles, selectedUserId, onSelectUser, assignment, onSaveRoles, onSaveStudentCode, onOpenRole, busy = false,
}) => {
  const [search, setSearch] = useState("");
  const [actorFilter, setActorFilter] = useState("all");
  const [roleQuery, setRoleQuery] = useState("");
  const [assigned, setAssigned] = useState<string[]>([]);
  const [direct, setDirect] = useState<string[]>([]);
  const [studentCode, setStudentCode] = useState("");

  useEffect(() => {
    setAssigned(assignment?.roleGroupIds || []);
    setDirect(assignment?.directPermissions || []);
    setStudentCode(assignment?.studentCode || "");
  }, [assignment]);

  const filteredUsers = useMemo(() => {
    const q = normalize(search.trim());
    return users.filter((user) => {
      if (!user.id) return false;
      if (actorFilter !== "all" && (user.role || "").toLowerCase() !== actorFilter) return false;
      return !q || normalize(`${user.name || ""} ${user.email || ""}`).includes(q);
    });
  }, [users, search, actorFilter]);

  const selectedUser = users.find((user) => user.id === selectedUserId);
  const actor = assignment?.actor || (selectedUser?.role || "").toLowerCase();
  const actorRole = systemRoles.find((role) => role.id === `actor:${actor}`) || null;
  const isAdmin = actor === "admin";
  const savedRoleIds = assignment?.roleGroupIds || [];
  const savedDirect = assignment?.directPermissions || [];
  const dirty = Boolean(assignment) && (!sameSet(assigned, savedRoleIds) || !sameSet(direct, savedDirect));

  // Preview follows the checkboxes, so the admin sees the result before saving.
  const rolesInEffect = useMemo(
    () => [
      ...(actorRole ? [actorRole] : []),
      ...customRoles.filter((role) => assigned.includes(role.id)),
      ...(direct.length ? [{ id: "direct", name: "Quyền riêng", permissions: direct }] : []),
    ],
    [actorRole, customRoles, assigned, direct],
  );
  const sources = useMemo(() => permissionSources(rolesInEffect), [rolesInEffect]);
  // Saved state shows what the server reports (inactive accounts hold nothing); unsaved state previews the new roles.
  const granted = useMemo(
    () => (isAdmin ? groups.flatMap((g) => g.permissions.map((p) => p.code)) : dirty ? [...sources.keys()] : assignment?.effectivePermissions || []),
    [isAdmin, groups, dirty, sources, assignment],
  );

  const shownRoles = useMemo(() => {
    const q = normalize(roleQuery.trim());
    return customRoles.filter((role) => !q || normalize(role.name).includes(q));
  }, [customRoles, roleQuery]);

  const toggleRole = (id: string) => {
    if (busy) return;
    setAssigned((current) => (current.includes(id) ? current.filter((item) => item !== id) : [...current, id]));
  };

  const addedDirect = direct.filter((code) => !savedDirect.includes(code)).length;
  const removedDirect = savedDirect.filter((code) => !direct.includes(code)).length;
  const added = assigned.filter((id) => !savedRoleIds.includes(id)).map((id) => customRoles.find((r) => r.id === id)?.name).filter(Boolean);
  const removed = savedRoleIds.filter((id) => !assigned.includes(id)).map((id) => customRoles.find((r) => r.id === id)?.name).filter(Boolean);

  return (
    <div className="user-assignment-layout">
      <aside className="user-search-pane roles-card">
        <div className="user-search-box">
          <label className="user-search-field">
            <input
              type="search"
              className="user-search-input"
              value={search}
              placeholder="Tìm theo tên hoặc email…"
              aria-label="Tìm người dùng"
              onChange={(event) => setSearch(event.target.value)}
            />
          </label>
          <div className="user-filter-chips" role="group" aria-label="Lọc theo loại tài khoản">
            {[["all", "Tất cả"], ...Object.entries(ACTOR_LABELS)].map(([value, label]) => (
              <button key={value} type="button" className={`user-filter-chip${actorFilter === value ? " is-active" : ""}`} aria-pressed={actorFilter === value} onClick={() => setActorFilter(value)}>
                {label}
              </button>
            ))}
          </div>
        </div>

        <div className="user-results-list" role="listbox" aria-label="Danh sách người dùng">
          {filteredUsers.length === 0 ? (
            <div className="user-empty-search">Không tìm thấy người dùng phù hợp.</div>
          ) : (
            filteredUsers.slice(0, 50).map((user) => (
              <button
                type="button"
                key={user.id}
                role="option"
                aria-selected={user.id === selectedUserId}
                className={`user-list-item ${user.id === selectedUserId ? "is-selected" : ""}`}
                onClick={() => user.id && onSelectUser(user.id)}
              >
                <span className="user-avatar" aria-hidden="true">{initialsOf(user.name)}</span>
                <div className="user-item-info">
                  <span className="user-item-name">{user.name}</span>
                  <span className="user-item-email">{user.email}</span>
                </div>
                <span className="user-item-actor">{ACTOR_LABELS[(user.role || "").toLowerCase()] || user.role || "User"}</span>
              </button>
            ))
          )}
          {filteredUsers.length > 50 && <div className="user-empty-search">Hiển thị 50 kết quả đầu — hãy thu hẹp bằng ô tìm kiếm.</div>}
        </div>
      </aside>

      <section className="user-detail-pane roles-card">
        {!selectedUserId || !selectedUser ? (
          <div className="user-placeholder"><span>Chọn một người dùng ở danh sách bên trái để xem và gán vai trò.</span></div>
        ) : (
          <div className="user-form-content">
            <div className="user-profile-header">
              <span className="user-avatar is-large" aria-hidden="true">{initialsOf(selectedUser.name)}</span>
              <div className="user-profile-id">
                <h3 className="user-profile-name">{selectedUser.name}</h3>
                <span className="user-profile-email">{selectedUser.email}</span>
              </div>
              <span className="user-profile-actor-pill">{ACTOR_LABELS[actor] || actor}</span>
            </div>

            {actor === "student" && (
              <div className="student-code-row">
                <Field label="Mã số sinh viên (MSSV)">
                  <div className="student-code-input-wrap">
                    <input inputMode="numeric" pattern="[0-9]{6,20}" maxLength={20} value={studentCode} disabled={busy} placeholder="6–20 chữ số" onChange={(event) => setStudentCode(event.target.value)} />
                    <button type="button" className="button" disabled={busy || !/^\d{6,20}$/.test(studentCode)} onClick={() => onSaveStudentCode(selectedUserId, studentCode)}>
                      Lưu MSSV
                    </button>
                  </div>
                </Field>
              </div>
            )}

            <div className="assigned-roles-section">
              <div className="assigned-roles-head">
                <h4>Vai trò</h4>
                <span className="assigned-roles-hint">Một người có thể giữ nhiều vai trò; quyền được cộng dồn.</span>
              </div>

              {customRoles.length > 6 && (
                <input type="search" className="user-search-input" value={roleQuery} placeholder="Tìm vai trò…" aria-label="Tìm vai trò" onChange={(event) => setRoleQuery(event.target.value)} />
              )}
              <div className="assigned-roles-grid">
                {/* The role that comes with the account type is always on; the others are toggled. */}
                <label className="assigned-role-card is-on is-locked" title="Vai trò theo loại tài khoản, luôn được áp dụng">
                  <input type="checkbox" className="permission-checkbox" checked disabled readOnly />
                  <span className="assigned-role-body">
                    <span className="assigned-role-name">{ACTOR_LABELS[actor] || actor} <em>Mặc định</em></span>
                    <span className="assigned-role-meta">{isAdmin ? "Toàn quyền" : `${actorRole?.permissions.length ?? 0} quyền`}</span>
                  </span>
                  {actorRole && !isAdmin && <button type="button" className="role-link" onClick={(event) => { event.preventDefault(); onOpenRole(actorRole.id); }}>Xem quyền</button>}
                </label>
                {!isAdmin && shownRoles.map((role) => {
                  const on = assigned.includes(role.id);
                  return (
                    <label key={role.id} className={`assigned-role-card${on ? " is-on" : ""}`}>
                      <input type="checkbox" className="permission-checkbox" checked={on} disabled={busy} onChange={() => toggleRole(role.id)} />
                      <span className="assigned-role-body">
                        <span className="assigned-role-name">{role.name}</span>
                        <span className="assigned-role-meta">{role.permissions.length} quyền</span>
                      </span>
                      <button type="button" className="role-link" onClick={(event) => { event.preventDefault(); onOpenRole(role.id); }}>Xem quyền</button>
                    </label>
                  );
                })}
              </div>
              {isAdmin && <p className="assigned-role-empty">Quản trị viên đã có toàn bộ quyền nên không cần gán thêm vai trò.</p>}
              {!isAdmin && customRoles.length === 0 && <p className="assigned-role-empty">Chưa có vai trò nào khác. Hãy tạo ở tab “Vai trò”.</p>}

              {!isAdmin && (
                <details className="direct-permissions" open={direct.length > 0 || undefined}>
                  <summary>
                    <span className="direct-permissions-title">Quyền riêng cho tài khoản này</span>
                    <span className="direct-permissions-count">{direct.length ? `${direct.length} quyền` : "Chưa có"}</span>
                  </summary>
                  <p className="direct-permissions-hint">Chỉ áp dụng cho {selectedUser.name}, cộng thêm vào vai trò ở trên mà không cần tạo vai trò mới.</p>
                  <PermissionPicker groups={groups} value={direct} onChange={setDirect} readOnly={busy} />
                </details>
              )}

              {!isAdmin && (
                <div className="assigned-save-bar">
                  <span className="assigned-save-summary" role="status">
                    {dirty
                      ? <>{added.length > 0 && <>Thêm: <strong>{added.join(", ")}</strong>. </>}{removed.length > 0 && <>Gỡ: <strong>{removed.join(", ")}</strong>. </>}{(addedDirect > 0 || removedDirect > 0) && <>Quyền riêng: <strong>{addedDirect > 0 ? `+${addedDirect}` : ""}{addedDirect > 0 && removedDirect > 0 ? " / " : ""}{removedDirect > 0 ? `−${removedDirect}` : ""}</strong>. </>}Chưa lưu.</>
                      : `${savedRoleIds.length} vai trò thêm · ${savedDirect.length} quyền riêng.`}
                  </span>
                  <span className="role-editor-buttons">
                    <button type="button" className="button" disabled={busy || !dirty} onClick={() => { setAssigned(savedRoleIds); setDirect(savedDirect); }}>Hoàn tác</button>
                    <button type="button" className="button primary" disabled={busy || !dirty} onClick={() => onSaveRoles(selectedUserId, assigned, direct)}>
                      {busy ? "Đang lưu…" : "Lưu phân quyền"}
                    </button>
                  </span>
                </div>
              )}
            </div>

            {assignment && (
              <EffectivePermissionsPreview
                groups={groups}
                granted={granted}
                sources={sources}
                fullAccess={isAdmin}
                title={dirty ? "Quyền hiệu lực sau khi lưu" : "Quyền hiệu lực"}
              />
            )}
          </div>
        )}
      </section>
    </div>
  );
};
