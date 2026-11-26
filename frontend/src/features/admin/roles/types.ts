/**
 * Role & permission management types.
 * A permission is a capability code enforced by the backend (e.g. "problem.create"); a role is a named set of them.
 */
export interface RoleGroup {
  id: string;
  name: string;
  /** Built-in role tied to an account type (student, instructor, ...). Its permissions apply to every account of that type. */
  isSystem?: boolean;
  /** The role cannot be edited. */
  readOnly?: boolean;
  /** Number of accounts holding the role. */
  userCount?: number;
  permissions: string[];
}

export interface RoleForm {
  id?: string;
  name: string;
  permissions: string[];
}

export interface UserRoleAssignment {
  userId: string;
  /** Loại tài khoản (student | staff), không phải tên vai trò. */
  actor: string;
  studentCode?: string | null;
  /** Additional (custom) roles assigned to the user. */
  roleGroupIds: string[];
  /** Permissions granted to this account alone, outside any role. */
  directPermissions: string[];
  /** Everything the user can do now: all assigned roles plus direct permissions. */
  effectivePermissions: string[];
}

export interface RolesCatalog {
  customRoles: RoleGroup[];
  systemRoles: RoleGroup[];
  /** Every permission the backend enforces: code -> description. */
  permissions: Record<string, string>;
}

export interface IRoleService {
  getCatalog(): Promise<RolesCatalog>;
  createRole(payload: RoleForm): Promise<RoleGroup>;
  updateRole(id: string, payload: RoleForm): Promise<RoleGroup>;
  getUserAssignment(userId: string): Promise<UserRoleAssignment>;
  saveUserRoles(userId: string, roleGroupIds: string[], directPermissions?: string[]): Promise<UserRoleAssignment>;
  saveStudentCode?(userId: string, studentCode: string): Promise<void>;
}
