import assert from "node:assert/strict";
import { build } from "esbuild";
import { rmSync } from "node:fs";

// Chuỗi nhật ký / lỗi chấm của trang Tổng quan admin: câu tiếng Việt dễ đọc và KHÔNG lộ mã bản ghi (UUID).
const outfile = "tests/.admin-overview-text-bundle.mjs";
await build({
  entryPoints: ["src/features/admin/activityText.ts"],
  bundle: true,
  format: "esm",
  platform: "node",
  outfile,
});

try {
  const { formatActivityAction, formatGradingError, hideRecordIds } = await import(`../${outfile}?${Date.now()}`);
  const U1 = "3f2b8c1e-9d4a-4b6f-8a21-0c5d7e9f1a23";
  const U2 = "A1B2C3D4-0000-4111-8222-333344445555";
  const UUID = /[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i;

  // Mọi dạng chuỗi mà backend đang ghi vào activity_logs, kể cả dạng chưa có mapping riêng.
  const samples = [
    `access:assign_roles:${U1}`,
    `identity:set_student_code:${U1}`,
    `content:share:problem:${U1}:${U2}:edit=True`,
    `content:revoke:library:${U1}:${U2}`,
    `content:copy:problem:${U1}`,
    `results:publish:${U1}`,
    `results:unpublish:${U1}`,
    `exam_admission_unlock:${U1}:${U2}`,
    `proctor:unlock:${U1}:${U2}`,
    `proctor:add_student:${U1}:${U2}`,
    `proctor:browser_approve:${U1}:${U2}:extra`,
    `exam_rooms:save:${U1}`,
    `Saved examination ${U1} with 3 sessions`,
    `Updated examination session ${U1}`,
    `Removed examination sessions ${U1}, ${U2} from ${U1}`,
    `Assigned instructor ${U1} (theory) to class NT101.O21`,
    `Assigned instructor ${U1} (practical) to class NT101.O21`,
    `Totally unknown action for ${U1} and ${U2}`,
  ];
  for (const sample of samples) {
    assert.doesNotMatch(formatActivityAction(sample), UUID, `lộ UUID: ${sample}`);
  }

  // Câu cho các hành động quản trị lớp (frontend dựa vào các chuỗi này, xem ActivityLogService).
  assert.equal(formatActivityAction("Created class NT101.O21"), "Tạo lớp học NT101.O21");
  assert.equal(formatActivityAction("Updated class NT101.O21"), "Cập nhật lớp học NT101.O21");
  assert.equal(formatActivityAction("Deleted class NT101.O21"), "Xóa lớp học NT101.O21");
  assert.equal(formatActivityAction("Synced instructors for class NT101.O21"), "Cập nhật giảng viên của lớp NT101.O21");
  assert.equal(formatActivityAction("Removed theory instructor from class NT101.O21"), "Gỡ giảng viên lý thuyết khỏi lớp NT101.O21");
  assert.equal(formatActivityAction("Removed practical instructor from class NT101.O21"), "Gỡ giảng viên thực hành khỏi lớp NT101.O21");
  assert.equal(formatActivityAction(`Assigned instructor ${U1} (theory) to class NT101.O21`), "Phân công giảng viên lý thuyết cho lớp NT101.O21");
  assert.equal(formatActivityAction(`Assigned instructor ${U1} (practical) to class NT101.O21`), "Phân công giảng viên thực hành cho lớp NT101.O21");

  // Duyệt giảng viên (do LecturerRequestService ghi).
  assert.equal(formatActivityAction("Approved lecturer an@uit.edu.vn"), "Phê duyệt giảng viên: an@uit.edu.vn");
  assert.equal(formatActivityAction("Rejected lecturer an@uit.edu.vn"), "Từ chối duyệt giảng viên: an@uit.edu.vn");

  // Các hành động khác có mapping tiếng Việt riêng, không để nguyên chuỗi kỹ thuật.
  assert.equal(formatActivityAction(`results:unpublish:${U1}`), "Ẩn kết quả thi / bài tập");
  assert.equal(formatActivityAction(`exam_admission_unlock:${U1}:${U2}`), "Mở khóa tài khoản thi cho sinh viên");
  assert.equal(formatActivityAction(`access:assign_roles:${U1}`), "Phân quyền người dùng");
  assert.equal(formatActivityAction("access:save_role:Giảng viên"), "Lưu vai trò: Giảng viên");
  assert.equal(formatActivityAction(""), "Hoạt động không xác định");

  // Chuỗi lạ vẫn được giữ lại, chỉ bỏ UUID (hoa/thường đều bị bỏ).
  assert.equal(formatActivityAction(`Something new ${U1}`), "Something new …");
  assert.equal(hideRecordIds(`${U2} và ${U1}`), "… và …");
  assert.equal(hideRecordIds("Không có mã nào"), "Không có mã nào");
  assert.equal(hideRecordIds("NT101.O21"), "NT101.O21", "mã lớp không phải UUID thì giữ nguyên");

  // Loại lỗi chấm bài.
  assert.equal(formatGradingError("Runtime Error"), "Lỗi thực thi (Runtime Error)");
  assert.equal(formatGradingError("Syntax Error"), "Lỗi cú pháp (Syntax Error)");
  assert.equal(formatGradingError("Timeout"), "Quá thời gian thực thi (Time Limit)");
  assert.equal(formatGradingError("Time limit exceeded"), "Quá thời gian thực thi (Time Limit)");
  assert.equal(formatGradingError("Memory limit"), "Quá giới hạn bộ nhớ (Memory Limit)");
  assert.equal(formatGradingError("Compilation error"), "Compilation error");
  assert.equal(formatGradingError(""), "Lỗi không xác định");

  // Trang không được in mã bản ghi: `err.id`/`activity.id` chỉ dùng làm key và để chọn dòng, không để hiển thị.
  const { readFileSync } = await import("node:fs");
  const page = readFileSync("src/features/admin/OverviewPage.tsx", "utf8");
  assert.doesNotMatch(page, /substring\(0,\s*8\)/, "không cắt UUID để hiển thị");
  assert.doesNotMatch(page, /Record reference/, "không có mục 'Record reference'");
  assert.doesNotMatch(page, /(?<!key=)\{(?:err|activity)\.id\}/, "không in id lỗi/hoạt động ra giao diện (mã lớp thì được)");
  assert.doesNotMatch(page, /\{activity\.action\}/, "không in chuỗi action thô ra giao diện");

  console.log("admin-overview-text: ok");
} finally {
  rmSync(outfile, { force: true });
}
