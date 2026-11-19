import { apiFetch } from "@/shared/api/apiClient";

export interface AdminUser {
  id?: string;
  name: string;
  email: string;
  /** Loại danh tính: người học hay nhân sự. Quyền nằm ở `roles` (vai trò động), không ở đây. */
  account_type: "student" | "staff";
  roles?: { id: string; name: string }[];
  status: "Active" | "Inactive" | "Pending" | string;
  lastActive: string;
  joined: string;
  detail: string;
}

/** A role that can be chosen when creating an account (`GET /api/admin/users/role-options`). */
export interface RoleOption {
  id: string;
  name: string;
  isSuper: boolean;
}

export const ACCOUNT_TYPE_LABELS: Record<AdminUser["account_type"], string> = {
  student: "Student",
  staff: "Staff",
};

/** Tên các vai trò của một tài khoản, hoặc nhãn loại tài khoản khi chưa có vai trò nào. */
export function userRoleNames(user: Pick<AdminUser, "account_type" | "roles">): string {
  const names = (user.roles || []).map((role) => role.name);
  return names.length ? names.join(", ") : ACCOUNT_TYPE_LABELS[user.account_type] || "—";
}

/** A person who may be assigned to teach a class (active account holding `class.teach`). */
export interface InstructorOption {
  id: string;
  name: string;
  email?: string;
  department?: string | null;
}

/**
 * Lecturer picker options for one class: everyone who may teach, plus the class's current lecturer
 * even if they no longer may (the backend keeps existing assignments, so the select must still show them).
 */
export function lecturerOptionsFor(
  eligible: InstructorOption[],
  current?: { lecturerId?: string; lecturer?: string } | null,
): InstructorOption[] {
  const id = current?.lecturerId;
  if (!id || id === "Unassigned" || eligible.some((option) => option.id === id)) return eligible;
  return [{ id, name: current?.lecturer || id }, ...eligible];
}

export const adminService = {
  async getUsers(): Promise<AdminUser[]> {
    return apiFetch("/api/admin/users");
  },

  /** Roles selectable when creating an account, sorted by name. */
  async getRoleOptions(): Promise<RoleOption[]> {
    return apiFetch("/api/admin/users/role-options");
  },

  /** People who may be assigned to teach a class, sorted by name (`GET /api/admin/instructors`). */
  async getInstructors(): Promise<InstructorOption[]> {
    return apiFetch("/api/admin/instructors");
  },

  async updateUser(email: string, data: Partial<AdminUser>): Promise<AdminUser> {
    return apiFetch(`/api/admin/users/${encodeURIComponent(email)}`, {
      method: "PUT",
      body: JSON.stringify(data),
    });
  },

  async createUser(data: any): Promise<AdminUser> {
    return apiFetch("/api/admin/users", {
      method: "POST",
      body: JSON.stringify(data),
    });
  },

  async importUsers(file: File): Promise<any> {
    const formData = new FormData();
    formData.append("file", file);

    return apiFetch("/api/admin/users/import", {
      method: "POST",
      body: formData,
    });
  },

  async getClasses(): Promise<any[]> {
    return apiFetch("/api/admin/classes");
  },

  async createClass(data: any): Promise<any> {
    return apiFetch("/api/admin/classes", {
      method: "POST",
      body: JSON.stringify(data),
    });
  },

  async updateClass(id: string, data: any): Promise<any> {
    return apiFetch(`/api/admin/classes/${encodeURIComponent(id)}`, {
      method: "PUT",
      body: JSON.stringify(data),
    });
  },

  async getLecturerRequests(): Promise<any[]> {
    return apiFetch("/api/admin/users/lecturer-requests");
  },

  async approveLecturerRequest(id: string): Promise<any> {
    return apiFetch(`/api/admin/users/lecturer-requests/${encodeURIComponent(id)}/approve`, {
      method: "POST"
    });
  },

  async rejectLecturerRequest(id: string): Promise<any> {
    return apiFetch(`/api/admin/users/lecturer-requests/${encodeURIComponent(id)}/reject`, {
      method: "POST"
    });
  },

  async getOverviewStats(): Promise<any> {
    return apiFetch("/api/admin/overview/stats");
  },

  async getActivities(): Promise<any[]> {
    return apiFetch("/api/admin/overview/activities");
  },

  async getErrors(): Promise<any[]> {
    return apiFetch("/api/admin/overview/errors");
  }
};
