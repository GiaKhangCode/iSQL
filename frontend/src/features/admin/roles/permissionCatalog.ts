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

/** Human-readable catalog of the capability codes the backend knows (`PermissionCatalog::PERMISSIONS`). */
const GROUPS: PermissionGroup[] = [
  {
    id: "practice",
    label: "Luyện tập",
    hint: "Dành cho người học",
    permissions: [
      { code: "practice.solve", label: "Luyện tập bài toán", description: "Giải và nộp bài trong thư viện bài luyện tập (tab Tổng quan của sinh viên)." },
      { code: "assignment.do", label: "Làm bài tập được giao", description: "Xem và làm bài tập, ca thi được giao (tab Bài tập của sinh viên)." },
      { code: "submission.view", label: "Xem bài nộp của mình", description: "Xem lịch sử bài nộp của chính mình (tab Bài nộp của sinh viên)." },
    ],
  },
  {
    id: "content",
    label: "Bài toán & cơ sở dữ liệu",
    hint: "Soạn nội dung giảng dạy",
    permissions: [
      { code: "problem.create", label: "Tạo và chỉnh sửa bài toán", description: "Tạo bài toán mới; sửa và xóa bài toán của mình." },
      { code: "tag.manage", label: "Quản lý thẻ câu hỏi", description: "Tạo, sửa, xóa thẻ câu hỏi (dạng bài, độ khó) dùng khi soạn bài." },
      { code: "library.manage", label: "Quản lý thư viện CSDL", description: "Tạo, sửa, xóa cơ sở dữ liệu mẫu trong thư viện." },
    ],
  },
  {
    id: "activities",
    label: "Bài tập & kỳ thi",
    hint: "Giao bài và tổ chức thi",
    permissions: [
      { code: "assignment.create", label: "Tạo bài tập", description: "Tạo, sửa, xóa bài tập và giao cho lớp hoặc sinh viên." },
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
      { code: "results.view_all", label: "Xem kết quả mọi lớp", description: "Xem bài làm và thống kê của tất cả các lớp, không chấm điểm (dành cho trưởng bộ môn)." },
      { code: "statistics.view", label: "Thống kê lớp phụ trách", description: "Xem thống kê các lớp mình phụ trách (tab Thống kê của giảng viên)." },
      { code: "results.regrade", label: "Chấm phúc khảo", description: "Được phân công và chấm phúc khảo (tab Phúc khảo của giảng viên)." },
      { code: "exam.results", label: "Xem kết quả kỳ thi", description: "Xem kết quả các kỳ thi (tab Kết quả thi của giáo vụ)." },
      { code: "statistics.faculty", label: "Thống kê toàn khoa", description: "Xem thống kê toàn khoa (tab Thống kê của giáo vụ)." },
    ],
  },
  {
    id: "people",
    label: "Lớp học & người dùng",
    hint: "Thường dành cho quản trị",
    permissions: [
      { code: "class.manage", label: "Quản lý lớp học", description: "Tạo lớp, thêm sinh viên vào lớp và phân công giảng viên." },
      { code: "class.teach", label: "Giảng dạy lớp", description: "Được phân công giảng dạy lớp (lý thuyết hoặc thực hành); có tên trong danh sách chọn giảng viên." },
      { code: "student.reset_password", label: "Đặt lại mật khẩu sinh viên", description: "Đặt lại mật khẩu cho sinh viên trong lớp mình dạy." },
      { code: "user.manage", label: "Quản lý người dùng", description: "Tạo, sửa, duyệt và khóa tài khoản người dùng." },
    ],
  },
  {
    id: "admin",
    label: "Quản trị hệ thống",
    hint: "Cẩn trọng khi cấp",
    permissions: [
      { code: "system.overview", label: "Tổng quan quản trị", description: "Xem trang Tổng quan quản trị (tình trạng dịch vụ, việc cần xử lý, nhật ký)." },
      { code: "sandbox.cleanup", label: "Dọn schema sinh viên", description: "Xóa schema bài làm trên SQL Server khi kết thúc học phần (trong trang Tổng quan quản trị).", sensitive: true },
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
