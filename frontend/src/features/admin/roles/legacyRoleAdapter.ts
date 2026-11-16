/**
 * Adapter between the role UI and the FastAPI capability endpoints (`/api/admin/access`).
 * The backend stores each role as a plain list of capability codes, so the UI works with the same list.
 */
import { apiFetch } from "@/shared/api/apiClient";
import type { IRoleService, RoleForm, RoleGroup, RolesCatalog, UserRoleAssignment } from "./types";

interface ApiRole { id: string; name: string; permissions: string[] }
interface ApiCatalog { roles: ApiRole[]; permissions: Record<string, string> }
interface ApiMembership { actor: string; studentCode?: string | null; role_ids: string[]; direct_permissions?: string[]; permissions: string[] }

const ADMIN_ROLE_ID = "actor:admin";

function systemRoleName(name: string, id: string): string {
  if (id === "actor:instructor") return "Giảng viên";
  if (id === "actor:student") return "Sinh viên";
  if (id === "actor:faculty_office") return "Giáo vụ khoa";
  return name.replace(/^Mặc định\s*·\s*/i, "").trim();
}

function toRole(role: ApiRole): RoleGroup {
  const isSystem = role.id.startsWith("actor:");
  return { id: role.id, name: isSystem ? systemRoleName(role.name, role.id) : role.name, isSystem, permissions: [...(role.permissions || [])].sort() };
}

function toAssignment(userId: string, data: ApiMembership): UserRoleAssignment {
  return { userId, actor: data.actor, studentCode: data.studentCode, roleGroupIds: data.role_ids || [], directPermissions: data.direct_permissions || [], effectivePermissions: data.permissions || [] };
}

export const legacyRoleService: IRoleService = {
  async getCatalog(): Promise<RolesCatalog> {
    const data = await apiFetch<ApiCatalog>("/api/admin/access");
    const customRoles: RoleGroup[] = [];
    const systemRoles: RoleGroup[] = [];
    for (const role of data.roles || []) {
      (role.id.startsWith("actor:") ? systemRoles : customRoles).push(toRole(role));
    }
    if (!systemRoles.some((role) => role.id === ADMIN_ROLE_ID)) {
      systemRoles.push({ id: ADMIN_ROLE_ID, name: "Quản trị viên", isSystem: true, readOnly: true, permissions: Object.keys(data.permissions || {}).sort() });
    }
    return { customRoles, systemRoles, permissions: data.permissions || {} };
  },

  async createRole(payload: RoleForm): Promise<RoleGroup> {
    const role = await apiFetch<ApiRole>("/api/admin/access/roles", {
      method: "POST",
      body: JSON.stringify({ name: payload.name.trim(), permissions: [...payload.permissions].sort() }),
    });
    return toRole(role);
  },

  async updateRole(id: string, payload: RoleForm): Promise<RoleGroup> {
    const role = await apiFetch<ApiRole>(`/api/admin/access/roles/${encodeURIComponent(id)}`, {
      method: "PUT",
      body: JSON.stringify({ name: payload.name.trim(), permissions: [...payload.permissions].sort() }),
    });
    return toRole(role);
  },

  async getUserAssignment(userId: string): Promise<UserRoleAssignment> {
    return toAssignment(userId, await apiFetch<ApiMembership>(`/api/admin/access/users/${encodeURIComponent(userId)}`));
  },

  async saveUserRoles(userId: string, roleGroupIds: string[], directPermissions?: string[]): Promise<UserRoleAssignment> {
    const data = await apiFetch<ApiMembership>(`/api/admin/access/users/${encodeURIComponent(userId)}`, {
      method: "PUT",
      body: JSON.stringify(directPermissions === undefined ? { role_ids: roleGroupIds } : { role_ids: roleGroupIds, direct_permissions: [...directPermissions].sort() }),
    });
    return toAssignment(userId, data);
  },

  async saveStudentCode(userId: string, studentCode: string): Promise<void> {
    await apiFetch(`/api/admin/access/users/${encodeURIComponent(userId)}/student-code`, {
      method: "PUT",
      body: JSON.stringify({ student_code: studentCode }),
    });
  },
};
