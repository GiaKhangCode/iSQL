import type { RoleGroup } from "./types";

export interface PermissionMeta {
  code: string;
  label: string;
  description: string;
  /** Highlighted in the picker: granting it hands over control of the system. */
  sensitive?: boolean;
}

export interface PermissionGroup {
  id: string;
  label: string;
  hint: string;
  permissions: PermissionMeta[];
}

/** Human-readable catalog of the capability codes the backend enforces (`PERMISSIONS` in access_control.py). */
const GROUPS: PermissionGroup[] = [
  {
    id: "practice",
    label: "Luyện tập",
    hint: "Dành cho người học",
    permissions: [
      { code: "practice.solve", label: "Luyện tập bài toán", description: "Giải và nộp bài trong thư viện bài luyện tập." },
    ],
  },
  {
    id: "content",
    label: "Bài toán & cơ sở dữ liệu",
    hint: "Soạn nội dung giảng dạy",
    permissions: [
      { code: "problem.create", label: "Tạo và chỉnh sửa bài toán", description: "Tạo bài toán mới; sửa và xóa bài toán của mình." },
      { code: "problem.share", label: "Chia sẻ bài toán", description: "Chia sẻ bài toán của mình cho giảng viên khác." },
      { code: "library.manage", label: "Quản lý thư viện CSDL", description: "Tạo, sửa, xóa cơ sở dữ liệu mẫu trong thư viện." },
      { code: "library.share", label: "Chia sẻ thư viện CSDL", description: "Chia sẻ cơ sở dữ liệu mẫu cho giảng viên khác." },
    ],
  },
  {
    id: "activities",
    label: "Bài tập & kỳ thi",
    hint: "Giao bài và tổ chức thi",
    permissions: [
      { code: "assignment.create", label: "Tạo bài tập", description: "Tạo, sửa, xóa bài tập và giao cho lớp hoặc sinh viên." },
      { code: "contest.create", label: "Tạo cuộc thi", description: "Tạo và quản lý cuộc thi, ca thi riêng." },
      { code: "examination.manage", label: "Tổ chức kỳ thi", description: "Lập kỳ thi chính thức, chia ca, gán lớp và phòng thi." },
      { code: "exam.credentials", label: "Cấp tài khoản ca thi", description: "Cấp và xuất tài khoản thi tạm cho sinh viên." },
      { code: "exam.proctor", label: "Giám sát phòng thi", description: "Coi thi mọi lớp trong ca thi được phân công." },
    ],
  },
  {
    id: "grading",
    label: "Chấm điểm & thống kê",
    hint: "Kết quả học tập",
    permissions: [
      { code: "results.grade", label: "Chấm bài và xem kết quả", description: "Xem bài làm, chấm điểm, công bố điểm và phúc khảo trong phạm vi được phụ trách." },
      { code: "statistics.all", label: "Thống kê mọi lớp", description: "Xem thống kê điểm của tất cả các lớp (dành cho trưởng bộ môn)." },
    ],
  },
  {
    id: "admin",
    label: "Quản trị hệ thống",
    hint: "Cẩn trọng khi cấp",
    permissions: [
      { code: "access.manage", label: "Quản lý vai trò và phân quyền", description: "Tạo vai trò, sửa quyền và gán vai trò cho mọi người dùng.", sensitive: true },
    ],
  },
];

/** Groups restricted to the permissions the server really enforces; unknown server codes land in "Quyền khác". */
export function buildPermissionGroups(server: Record<string, string>): PermissionGroup[] {
  const known = new Set<string>();
  const groups = GROUPS.map((group) => ({
    ...group,
    permissions: group.permissions.filter((permission) => {
      const exists = permission.code in server;
      if (exists) known.add(permission.code);
      return exists;
    }),
  })).filter((group) => group.permissions.length);
  const extra = Object.keys(server).filter((code) => !known.has(code)).sort();
  if (extra.length) {
    groups.push({
      id: "other",
      label: "Quyền khác",
      hint: "Quyền mới của hệ thống",
      permissions: extra.map((code) => ({ code, label: code, description: server[code] || "" })),
    });
  }
  return groups;
}

export function permissionLabel(groups: PermissionGroup[], code: string): string {
  for (const group of groups) {
    const found = group.permissions.find((permission) => permission.code === code);
    if (found) return found.label;
  }
  return code;
}

/** For each permission: the names of the roles that provide it. */
export function permissionSources(roles: RoleGroup[]): Map<string, string[]> {
  const sources = new Map<string, string[]>();
  for (const role of roles) {
    for (const code of role.permissions) {
      sources.set(code, [...(sources.get(code) || []), role.name]);
    }
  }
  return sources;
}

export function sameSet(a: string[], b: string[]): boolean {
  return a.length === b.length && a.every((value) => b.includes(value));
}
