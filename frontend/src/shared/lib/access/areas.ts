/**
 * Khu vực giao diện = VAI TRÒ.
 *
 * Mỗi vai trò người dùng đang giữ (kể cả vai trò tạo thêm ở trang Vai trò) là một khu vực, tên khu vực là tên vai trò.
 * Thanh điều hướng của khu vực gồm các tab mà quyền của chính vai trò đó mở ra: mỗi tab có đúng một mã quyền
 * (NAV_TABS), tick thêm quyền thì tab xuất hiện, bỏ tick thì tab biến mất. Backend chỉ trả danh sách vai trò kèm
 * quyền ở GET /api/auth/permissions (`roles`); không có khu vực cố định nào ở phía backend.
 *
 * Quyền gán riêng (`directPermissions`) không thuộc vai trò nào: những tab mà chỉ quyền gán riêng mở ra được gom vào
 * khu vực "Quyền bổ sung" (id "extra", chỉ có ở client).
 *
 * Đường dẫn trang giữ nguyên (/teacher/..., /office/..., /admin/...). Đường dẫn chỉ là nhóm trang (TabGroup);
 * việc vào trang do mã quyền quyết định, không phụ thuộc người dùng đang ở khu vực nào.
 */

/** Nhóm trang theo tiền tố đường dẫn: /admin, /office, /teacher, còn lại là các trang của sinh viên. */
export type TabGroup = "student" | "teacher" | "office" | "admin";

/** id của khu vực Quyền bổ sung. */
export const EXTRA_AREA = "extra";
export const EXTRA_AREA_LABEL = "Additional permissions";

/** Vai trò như backend trả về ở GET /api/auth/permissions. */
export type AccessRole = { id: string; name: string; permissions: string[] };

/**
 * Một tab trên thanh điều hướng. `prefixes` là các đường dẫn con thuộc tab (mặc định chính `path`).
 * `label` là khóa tiếng Anh, bản tiếng Việt nằm ở i18n/locales/vi/common.ts.
 */
export type NavTab = { label: string; path: string; permission: string; group: TabGroup; prefixes?: string[] };

/** Một khu vực: vai trò (id = id vai trò) hoặc Quyền bổ sung (id = "extra"), cùng các tab của nó. */
export type Area = { id: string; label: string; tabs: NavTab[] };

/**
 * Bảng tab → quyền duy nhất (menu, route, trang chủ khu vực đều lấy từ đây). Thứ tự là thứ tự hiện trên menu:
 * quản trị, giáo vụ, giảng viên, rồi sinh viên. Tên tab không trùng nhau để một vai trò gồm nhiều nhóm vẫn rõ nghĩa.
 */
export const NAV_TABS: readonly NavTab[] = [
  { label: "Overview", path: "/admin/overview", permission: "system.overview", group: "admin" },
  { label: "Users", path: "/admin/users", permission: "user.manage", group: "admin" },
  { label: "Courses", path: "/admin/courses", permission: "class.manage", group: "admin" },
  { label: "Roles", path: "/admin/roles", permission: "access.manage", group: "admin" },

  { label: "Examinations", path: "/office/examinations", permission: "examination.manage", group: "office", prefixes: ["/office/examinations", "/office/sessions"] },
  { label: "Exam results", path: "/office/results", permission: "exam.results", group: "office" },
  { label: "Faculty statistics", path: "/office/statistics", permission: "statistics.faculty", group: "office" },

  { label: "Problems", path: "/teacher/problems", permission: "problem.create", group: "teacher" },
  { label: "Database library", path: "/teacher/database-library", permission: "library.manage", group: "teacher" },
  { label: "Question tags", path: "/teacher/exercise-tags", permission: "tag.manage", group: "teacher" },
  { label: "Manage assignments", path: "/teacher/assignments", permission: "assignment.create", group: "teacher" },
  { label: "Classes", path: "/teacher/classes", permission: "class.teach", group: "teacher" },
  { label: "Proctoring", path: "/teacher/exams", permission: "exam.proctor", group: "teacher" },
  { label: "Grading", path: "/teacher/results", permission: "results.grade", group: "teacher" },
  { label: "Class statistics", path: "/teacher/statistics", permission: "statistics.view", group: "teacher" },
  { label: "Regrade", path: "/teacher/regrade", permission: "results.regrade", group: "teacher" },

  { label: "Dashboard", path: "/dashboard", permission: "practice.solve", group: "student" },
  { label: "My assignments", path: "/assignments", permission: "assignment.do", group: "student" },
  { label: "Submissions", path: "/submissions", permission: "submission.view", group: "student" },
];

/** Các tab mà một tập quyền mở ra, theo thứ tự của NAV_TABS. */
export function tabsFor(permissions: readonly string[]): NavTab[] {
  return NAV_TABS.filter((tab) => permissions.includes(tab.permission));
}

/**
 * Danh sách khu vực của người dùng: mỗi vai trò có ít nhất một tab là một khu vực (theo thứ tự backend trả về),
 * cộng khu vực Quyền bổ sung khi có tab chỉ mở được nhờ quyền gán riêng.
 */
export function buildAreas(roles: readonly AccessRole[], directPermissions: readonly string[]): Area[] {
  const areas: Area[] = [];
  const held = new Set<string>();
  for (const role of roles) {
    role.permissions.forEach((code) => held.add(code));
    const tabs = tabsFor(role.permissions);
    if (tabs.length) areas.push({ id: role.id, label: role.name, tabs });
  }
  const extraTabs = NAV_TABS.filter((tab) => directPermissions.includes(tab.permission) && !held.has(tab.permission));
  if (extraTabs.length) areas.push({ id: EXTRA_AREA, label: EXTRA_AREA_LABEL, tabs: extraTabs });
  return areas;
}

/** Nhóm trang mà một đường dẫn thuộc về. */
export function groupOfPath(pathname: string): TabGroup {
  if (/^\/admin(\/|$)/.test(pathname)) return "admin";
  if (/^\/office(\/|$)/.test(pathname)) return "office";
  if (/^\/teacher(\/|$)/.test(pathname)) return "teacher";
  return "student";
}

/** Đường dẫn có thuộc tab này (chính tab hoặc trang con) hay không. */
export function tabMatchesPath(tab: NavTab, pathname: string): boolean {
  return (tab.prefixes ?? [tab.path]).some((prefix) => pathname === prefix || pathname.startsWith(prefix + "/"));
}

/** Người dùng có tab nào của nhóm trang này không (điều kiện vào cụm route /admin, /office, /teacher). */
export function canOpenGroup(permissions: readonly string[], group: TabGroup): boolean {
  return NAV_TABS.some((tab) => tab.group === group && permissions.includes(tab.permission));
}

/** Tab đầu tiên của một nhóm trang mà quyền mở ra (trang chủ của /admin, /office, /teacher). */
export function firstTabOfGroup(permissions: readonly string[], group: TabGroup): NavTab | null {
  return NAV_TABS.find((tab) => tab.group === group && permissions.includes(tab.permission)) ?? null;
}

/** Trang chủ của khu vực: tab đầu tiên của nó. */
export function homeOfArea(area: Area | null | undefined): string {
  return area?.tabs[0]?.path ?? "/login";
}

/** Khu vực ưu tiên: lựa chọn đã lưu nếu còn tồn tại, ngược lại khu vực đầu tiên (Quyền bổ sung luôn ở cuối). */
export function pickArea(areas: readonly Area[], preferredId?: string | null): Area | null {
  return areas.find((area) => area.id === preferredId) ?? areas[0] ?? null;
}

/**
 * Khu vực đang xem, suy từ đường dẫn: khu vực có tab khớp đường dẫn; nếu không có thì khu vực có tab cùng nhóm trang
 * (ví dụ trang chấm bài nằm dưới /teacher/exams); nếu vẫn không có thì khu vực ưu tiên. Khi nhiều khu vực cùng khớp,
 * ưu tiên khu vực người dùng đã chọn.
 */
export function resolveArea(areas: readonly Area[], pathname: string, preferredId?: string | null): Area | null {
  const exact = areas.filter((area) => area.tabs.some((tab) => tabMatchesPath(tab, pathname)));
  const group = groupOfPath(pathname);
  const pool = exact.length ? exact : areas.filter((area) => area.tabs.some((tab) => tab.group === group));
  return pool.find((area) => area.id === preferredId) ?? pool[0] ?? pickArea(areas, preferredId);
}

const storageKey = (userId: string) => `sql-practice:area:${userId}`;

export function readPreferredArea(userId: string | undefined): string | null {
  if (!userId) return null;
  try {
    return window.localStorage.getItem(storageKey(userId)) || null;
  } catch {
    return null;
  }
}

export function writePreferredArea(userId: string | undefined, areaId: string): void {
  if (!userId) return;
  try {
    window.localStorage.setItem(storageKey(userId), areaId);
  } catch {
    /* localStorage có thể bị chặn: bỏ qua, chỉ mất tiện ích nhớ lựa chọn */
  }
}
