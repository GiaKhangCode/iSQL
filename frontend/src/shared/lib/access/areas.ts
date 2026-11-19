/**
 * Khu vực giao diện (student / teacher / office / admin).
 *
 * Khu vực là THUỘC TÍNH CỦA VAI TRÒ (access_roles.area), backend trả danh sách khu vực mà người dùng
 * được vào ở GET /api/auth/permissions (`areas`). Không suy ra từ tên vai trò hay cột users.role.
 * Khu vực đang xem được xác định bằng đường dẫn (/admin, /office, /teacher, còn lại là sinh viên);
 * "khu vực ưu tiên" chỉ dùng để quyết định trang chủ khi vào "/" hoặc sau khi đăng nhập.
 */
export type Area = "student" | "teacher" | "office" | "admin";

/** Thứ tự chọn khu vực mặc định khi người dùng chưa chọn (khu vực quản lý trước, sinh viên sau cùng). */
export const AREA_PRIORITY: readonly Area[] = ["admin", "office", "teacher", "student"];

export const AREA_LABELS: Record<Area, string> = {
  student: "Student",
  teacher: "Lecturer",
  office: "Faculty office",
  admin: "Admin",
};

const HOME: Record<Area, string> = {
  student: "/dashboard",
  teacher: "/teacher/problems",
  office: "/office/examinations",
  admin: "/admin/overview",
};

export function isArea(value: unknown): value is Area {
  return typeof value === "string" && (AREA_PRIORITY as readonly string[]).includes(value);
}

/** Giữ lại các giá trị hợp lệ, theo thứ tự ưu tiên, không trùng. */
export function normalizeAreas(values: unknown): Area[] {
  const list = Array.isArray(values) ? values : [];
  return AREA_PRIORITY.filter((area) => list.includes(area));
}

export function homeForArea(area: Area | null | undefined): string {
  return area ? HOME[area] : "/login";
}

/** Khu vực ưu tiên: lựa chọn đã lưu nếu còn được phép, ngược lại theo AREA_PRIORITY. */
export function pickArea(areas: readonly Area[], preferred?: string | null): Area | null {
  if (preferred && isArea(preferred) && areas.includes(preferred)) return preferred;
  return AREA_PRIORITY.find((area) => areas.includes(area)) ?? null;
}

/** Khu vực mà một đường dẫn thuộc về. */
export function areaOfPath(pathname: string): Area {
  if (/^\/admin(\/|$)/.test(pathname)) return "admin";
  if (/^\/office(\/|$)/.test(pathname)) return "office";
  if (/^\/teacher(\/|$)/.test(pathname)) return "teacher";
  return "student";
}

const storageKey = (userId: string) => `sql-practice:area:${userId}`;

export function readPreferredArea(userId: string | undefined): Area | null {
  if (!userId) return null;
  try {
    const value = window.localStorage.getItem(storageKey(userId));
    return isArea(value) ? value : null;
  } catch {
    return null;
  }
}

export function writePreferredArea(userId: string | undefined, area: Area): void {
  if (!userId) return;
  try {
    window.localStorage.setItem(storageKey(userId), area);
  } catch {
    /* localStorage có thể bị chặn: bỏ qua, chỉ mất tiện ích nhớ lựa chọn */
  }
}
