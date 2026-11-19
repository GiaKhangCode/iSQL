import assert from "node:assert/strict";
import { build } from "esbuild";
import { rmSync } from "node:fs";

// Ô chọn giảng viên: danh sách lấy từ GET /api/admin/instructors (Active + class.teach),
// cộng thêm giảng viên đang dạy lớp dù đã mất quyền (backend vẫn giữ phân công đang có).
const outfile = "tests/.lecturer-options-bundle.mjs";
await build({
  entryPoints: ["src/features/admin/adminService.ts"],
  bundle: true,
  format: "esm",
  platform: "node",
  outfile,
  define: { "import.meta.env": "{}" },
  alias: { "@": "./src" },
});

try {
  globalThis.window = { location: { origin: "http://localhost" } };
  const calls = [];
  globalThis.fetch = async (url) => {
    calls.push(url);
    return { ok: true, status: 200, headers: { get: () => "application/json" }, json: async () => [{ id: "u1", name: "An" }], text: async () => "[]" };
  };

  const { adminService, lecturerOptionsFor, userRoleNames } = await import(`./.lecturer-options-bundle.mjs?${Date.now()}`);

  const list = await adminService.getInstructors();
  assert.ok(String(calls[0]).endsWith("/api/admin/instructors"), "gọi đúng endpoint danh sách giảng viên");
  assert.deepEqual(list, [{ id: "u1", name: "An" }]);

  const eligible = [{ id: "u1", name: "An" }, { id: "u2", name: "Bình" }];
  assert.equal(lecturerOptionsFor(eligible, null), eligible, "không có lớp: giữ nguyên");
  assert.equal(lecturerOptionsFor(eligible, { lecturerId: "Unassigned", lecturer: "Unassigned" }), eligible, "lớp chưa có giảng viên");
  assert.equal(lecturerOptionsFor(eligible, { lecturerId: "u2", lecturer: "Bình" }), eligible, "giảng viên hiện tại đã có trong danh sách: không nhân đôi");
  assert.deepEqual(
    lecturerOptionsFor(eligible, { lecturerId: "u9", lecturer: "Cường" }),
    [{ id: "u9", name: "Cường" }, ...eligible],
    "giảng viên đang dạy nhưng mất quyền vẫn hiện để ô chọn không bị trống",
  );
  assert.deepEqual(lecturerOptionsFor([], { lecturerId: "u9" }), [{ id: "u9", name: "u9" }], "thiếu tên thì hiện id");

  // Hộp thoại tạo tài khoản lấy vai trò động; cột Role hiển thị tên vai trò, chưa có thì nhãn loại tài khoản.
  await adminService.getRoleOptions();
  assert.ok(String(calls[calls.length - 1]).endsWith("/api/admin/users/role-options"), "gọi đúng endpoint vai trò chọn được");
  assert.equal(userRoleNames({ account_type: "staff", roles: [{ id: "1", name: "Giảng viên" }, { id: "2", name: "Trưởng bộ môn" }] }), "Giảng viên, Trưởng bộ môn");
  assert.equal(userRoleNames({ account_type: "staff", roles: [] }), "Staff", "chưa có vai trò: nhãn loại tài khoản");
  assert.equal(userRoleNames({ account_type: "student" }), "Student", "thiếu roles không gây lỗi");

  console.log("lecturer-options tests passed");
} finally {
  rmSync(outfile, { force: true });
}
