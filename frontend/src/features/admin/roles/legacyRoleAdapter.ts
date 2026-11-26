/**
 * Adapter between the role UI and the capability endpoints (`/api/admin/access`).
 * A role is a named set of capability codes. Every role is also an interface area for its holders (the navigation tabs come from the
 * role's permissions). No role holds everything automatically, so every role is editable.
 */
import { apiFetch } from "@/shared/api/apiClient";
import type { IRoleService, RoleForm, RoleGroup, RolesCatalog, UserRoleAssignment } from "./types";

interface ApiRole { id: string; name: string; userCount?: number; permissions: string[] }
interface ApiCatalog { roles: ApiRole[]; permissions: Record<string, string> }
interface ApiMembership { actor: string; studentCode?: string | null; role_ids: string[]; direct_permissions?: string[]; permissions: string[] }

function toRole(role: ApiRole): RoleGroup {
  return {
    id: role.id, name: role.name, userCount: role.userCount,
    permissions: [...(role.permissions || [])].sort(),
  };
}

function toAssignment(userId: string, data: ApiMembership): UserRoleAssignment {
  return { userId, actor: data.actor, studentCode: data.studentCode, roleGroupIds: data.role_ids || [], directPermissions: data.direct_permissions || [], effectivePermissions: data.permissions || [] };
}

export const legacyRoleService: IRoleService = {
  async getCatalog(): Promise<RolesCatalog> {
    const data = await apiFetch<ApiCatalog>("/api/admin/access");
    const customRoles = (data.roles || []).map(toRole);
    return { customRoles, systemRoles: [], permissions: data.permissions || {} };
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
