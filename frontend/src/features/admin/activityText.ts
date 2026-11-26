// Chuyển chuỗi nhật ký từ backend sang câu tiếng Việt cho trang Tổng quan của admin.
// Không hàm nào được để lộ mã bản ghi (UUID) ra giao diện.

const RECORD_ID = /\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/gi;

// Chuỗi nhật ký cũ có thể chứa mã bản ghi (UUID); không bao giờ hiển thị chúng cho người quản trị.
export function hideRecordIds(text: string): string {
  return text.replace(RECORD_ID, "…");
}

function describeExtraActivity(action: string): string | null {
  const deleted = /^Deleted class\s+(.+)$/i.exec(action);
  if (deleted) return `Xóa lớp học ${deleted[1]}`;

  const synced = /^Synced instructors for class\s+(.+)$/i.exec(action);
  if (synced) return `Cập nhật giảng viên của lớp ${synced[1]}`;

  const kinds: Record<string, string> = { theory: "lý thuyết", practical: "thực hành" };
  const removed = /^Removed (theory|practical) instructor from class\s+(.+)$/i.exec(action);
  if (removed) return `Gỡ giảng viên ${kinds[removed[1].toLowerCase()]} khỏi lớp ${removed[2]}`;

  const assigned = /^Assigned instructor\s+\S+\s+\((theory|practical)\)\s+to class\s+(.+)$/i.exec(action);
  if (assigned) return `Phân công giảng viên ${kinds[assigned[1].toLowerCase()]} cho lớp ${assigned[2]}`;

  if (action.startsWith("results:unpublish:")) return "Ẩn kết quả thi / bài tập";
  if (action.startsWith("exam_admission_unlock:")) return "Mở khóa tài khoản thi cho sinh viên";
  return null;
}

export function formatActivityAction(action: string): string {
  return hideRecordIds(describeExtraActivity(action) ?? describeActivity(action));
}

function describeActivity(action: string): string {
  if (!action) return "Hoạt động không xác định";

  // access:save_role:<name>
  if (action.startsWith("access:save_role:")) {
    const roleName = action.slice("access:save_role:".length);
    return `Lưu vai trò: ${roleName}`;
  }

  // sandbox:clean_class:<class_id>:count=<n> | sandbox:clean_orphans:count=<n>
  const cleanedClass = /^sandbox:clean_class:(.+):count=(\d+)$/.exec(action);
  if (cleanedClass) return `Dọn ${cleanedClass[2]} schema của lớp ${cleanedClass[1]}`;
  const cleanedOrphans = /^sandbox:clean_orphans:count=(\d+)$/.exec(action);
  if (cleanedOrphans) return `Dọn ${cleanedOrphans[1]} schema mồ côi / tạm`;

  // access:assign_roles:<user_id>
  if (action.startsWith("access:assign_roles:")) {
    return "Phân quyền người dùng";
  }

  // identity:set_student_code:<user_id>
  if (action.startsWith("identity:set_student_code:")) {
    return "Cập nhật mã số sinh viên";
  }

  // labs:save:<room>
  if (action.startsWith("labs:save:")) {
    const room = action.slice("labs:save:".length);
    return `Cập nhật phòng thực hành: ${room}`;
  }

  // exam_admission:duplicate_login:<id>:generation=<gen>
  if (action.startsWith("exam_admission:duplicate_login:")) {
    return "Cảnh báo: Đăng nhập thi trùng thiết bị";
  }

  // exam_admission:reissue_export:<id>:generation=<gen>(:reason)?
  if (action.startsWith("exam_admission:reissue_export:")) {
    const parts = action.split(":");
    const reason = parts.length > 4 ? parts.slice(4).join(":") : "";
    return reason ? `Cấp lại và xuất tài khoản thi (${reason})` : "Cấp lại và xuất tài khoản thi";
  }

  // exam_admission:reissue:<id>:generation=<gen>(:reason)?
  if (action.startsWith("exam_admission:reissue:")) {
    const parts = action.split(":");
    const reason = parts.length > 4 ? parts.slice(4).join(":") : "";
    return reason ? `Cấp lại mật khẩu thi (${reason})` : "Cấp lại mật khẩu thi";
  }

  // exam_admission:export:<id>:generation=<gen>
  if (action.startsWith("exam_admission:export:")) {
    return "Xuất danh sách tài khoản thi";
  }

  // exam_rooms:save:<session_id>
  if (action.startsWith("exam_rooms:save:")) {
    return "Lưu phân bổ phòng thi";
  }

  // exam_rooms:confirm:<session_id>
  if (action.startsWith("exam_rooms:confirm:")) {
    return "Xác nhận chốt phòng thi";
  }

  // exam_rooms:reopen:<session_id>(:reason)?
  if (action.startsWith("exam_rooms:reopen:")) {
    const parts = action.split(":");
    const reason = parts.length > 3 ? parts.slice(3).join(":") : "";
    return reason ? `Mở lại phân bổ phòng thi (${reason})` : "Mở lại phân bổ phòng thi";
  }

  // exam_rooms:add_students:<session_id>:count=<count>
  if (action.startsWith("exam_rooms:add_students:")) {
    const match = /count=(\d+)/.exec(action);
    return match ? `Thêm ${match[1]} sinh viên vào phòng thi` : "Thêm sinh viên vào phòng thi";
  }

  // proctor:unlock:<session_id>:<student_id>
  if (action.startsWith("proctor:unlock:")) {
    return "Giám thị mở khóa bài thi cho sinh viên";
  }

  // proctor:add_student:<session_id>:<student_id>
  if (action.startsWith("proctor:add_student:")) {
    return "Giám thị thêm sinh viên vào ca thi";
  }

  // Saved examination <id> with <N> sessions
  const savedExamMatch = /^Saved examination\s+([\w-]+)\s+with\s+(\d+)\s+sessions$/i.exec(action);
  if (savedExamMatch) {
    return `Lưu kỳ thi (${savedExamMatch[2]} ca thi)`;
  }

  // Updated examination session <id>
  if (/^Updated examination session/i.test(action)) {
    return "Cập nhật thông tin ca thi";
  }

  // Moved classes <classes> from session <X> to session <Y> of examination <id>
  const moveClassMatch = /^Moved classes\s+(.+?)\s+from session\s+(\w+)\s+to session\s+(\w+)/i.exec(action);
  if (moveClassMatch) {
    return `Chuyển lớp ${moveClassMatch[1]} từ ca ${moveClassMatch[2]} sang ca ${moveClassMatch[3]}`;
  }

  // Removed examination sessions <ids> from <id>
  if (/^Removed examination sessions/i.test(action)) {
    return "Xóa ca thi khỏi kỳ thi";
  }

  // content:share:<kind>:<id>:<target>:edit=<can_edit>
  if (action.startsWith("content:share:")) {
    return "Chia sẻ nội dung trong thư viện";
  }

  // content:revoke:<kind>:<id>:<target>
  if (action.startsWith("content:revoke:")) {
    return "Thu hồi chia sẻ nội dung";
  }

  // content:copy:<kind>:<id>
  if (action.startsWith("content:copy:")) {
    return "Sao chép bài tập từ thư viện";
  }

  // results:publish:<id>
  if (action.startsWith("results:publish:")) {
    return "Công bố kết quả thi / bài tập";
  }

  // Created class <id>
  const createClassMatch = /^Created class\s+(.+)$/i.exec(action);
  if (createClassMatch) {
    return `Tạo lớp học ${createClassMatch[1]}`;
  }

  // Updated class <id>
  const updateClassMatch = /^Updated class\s+(.+)$/i.exec(action);
  if (updateClassMatch) {
    return `Cập nhật lớp học ${updateClassMatch[1]}`;
  }

  // Changed role of <user> from <prev> to <new>
  const roleChangeMatch = /^Changed role of\s+(.+?)\s+from\s+(.+?)\s+to\s+(.+)$/i.exec(action);
  if (roleChangeMatch) {
    return `Đổi vai trò người dùng thành ${roleChangeMatch[3]}`;
  }

  // Created user <user>
  const createUserMatch = /^Created user\s+(.+)$/i.exec(action);
  if (createUserMatch) {
    return `Tạo tài khoản: ${createUserMatch[1]}`;
  }

  // Updated user <user>
  const updateUserMatch = /^Updated user\s+(.+)$/i.exec(action);
  if (updateUserMatch) {
    return `Cập nhật tài khoản: ${updateUserMatch[1]}`;
  }

  // Deactivated user <user>
  const deactUserMatch = /^Deactivated user\s+(.+)$/i.exec(action);
  if (deactUserMatch) {
    return `Vô hiệu hóa tài khoản: ${deactUserMatch[1]}`;
  }

  // Activated user <user>
  const actUserMatch = /^Activated user\s+(.+)$/i.exec(action);
  if (actUserMatch) {
    return `Kích hoạt tài khoản: ${actUserMatch[1]}`;
  }

  // Approved lecturer <user>
  const appLectMatch = /^Approved lecturer\s+(.+)$/i.exec(action);
  if (appLectMatch) {
    return `Phê duyệt giảng viên: ${appLectMatch[1]}`;
  }

  // Rejected lecturer <user>
  const rejLectMatch = /^Rejected lecturer\s+(.+)$/i.exec(action);
  if (rejLectMatch) {
    return `Từ chối duyệt giảng viên: ${rejLectMatch[1]}`;
  }

  return action;
}
