import assert from "node:assert/strict";
import { build } from "esbuild";
import { rmSync } from "node:fs";

const outfile = "tests/.access-areas-bundle.mjs";
await build({ entryPoints: ["src/shared/lib/access/areas.ts"], bundle: true, format: "esm", platform: "node", outfile });

try {
  const a = await import(`./.access-areas-bundle.mjs?${Date.now()}`);

  const labels = (tabs) => tabs.map((tab) => tab.label);
  const student = { id: "r-student", name: "Sinh viên", permissions: ["practice.solve", "assignment.do", "submission.view"] };
  const admin = { id: "r-admin", name: "Admin", permissions: ["system.overview", "user.manage", "class.manage", "access.manage"] };
  const office = { id: "r-office", name: "Giáo vụ khoa", permissions: ["examination.manage", "exam.credentials", "exam.results", "statistics.faculty"] };
  const assistant = { id: "r-ta", name: "Trợ giảng", permissions: ["results.grade", "exam.proctor"] };

  // Tab của một vai trò = các tab mà quyền của vai trò đó mở ra, đúng thứ tự menu
  assert.deepEqual(labels(a.tabsFor(student.permissions)), ["Dashboard", "My assignments", "Submissions"]);
  assert.deepEqual(labels(a.tabsFor(admin.permissions)), ["Overview", "Users", "Courses", "Roles"]);
  assert.deepEqual(labels(a.tabsFor(assistant.permissions)), ["Proctoring", "Grading"]);
  assert.deepEqual(a.tabsFor(["exam.credentials", "student.reset_password"]), [], "quyền không có tab thì không mở tab nào");
  // Tên tab và đường dẫn không trùng nhau (vai trò trộn nhiều nhóm vẫn rõ nghĩa)
  assert.equal(new Set(a.NAV_TABS.map((tab) => tab.label)).size, a.NAV_TABS.length);
  assert.equal(new Set(a.NAV_TABS.map((tab) => tab.path)).size, a.NAV_TABS.length);

  // Mỗi vai trò có tab là một khu vực, tên khu vực là tên vai trò; vai trò mới có khu vực mới
  const areas = a.buildAreas([admin, student, assistant, { id: "r-empty", name: "Chỉ cấp tài khoản thi", permissions: ["exam.credentials"] }], []);
  assert.deepEqual(areas.map((area) => [area.id, area.label]), [["r-admin", "Admin"], ["r-student", "Sinh viên"], ["r-ta", "Trợ giảng"]]);

  // Quyền gán riêng: chỉ tab mà không vai trò nào đã mở mới vào khu vực Quyền bổ sung
  const withExtra = a.buildAreas([student], ["practice.solve", "statistics.view", "tag.manage"]);
  assert.deepEqual(withExtra.map((area) => area.id), ["r-student", "extra"]);
  assert.deepEqual(labels(withExtra[1].tabs), ["Question tags", "Class statistics"]);
  assert.equal(a.buildAreas([student], ["practice.solve"]).length, 1);

  // Trang chủ và khu vực ưu tiên
  assert.equal(a.homeOfArea(areas[0]), "/admin/overview");
  assert.equal(a.homeOfArea(areas[2]), "/teacher/exams");
  assert.equal(a.homeOfArea(null), "/login");
  assert.equal(a.pickArea(areas, "r-student").id, "r-student");
  assert.equal(a.pickArea(areas, "da-xoa").id, "r-admin", "vai trò đã bị gỡ thì quay về khu vực đầu tiên");
  assert.equal(a.pickArea([], "r-admin"), null);

  // Khu vực đang xem theo đường dẫn
  const both = a.buildAreas([student, office], []);
  assert.equal(a.resolveArea(both, "/office/results", "r-student").id, "r-office");
  assert.equal(a.resolveArea(both, "/assignments", "r-office").id, "r-student");
  assert.equal(a.resolveArea(both, "/office/sessions/5/edit", null).id, "r-office", "trang con theo prefix của tab");
  const grading = a.buildAreas([assistant], []);
  assert.equal(a.resolveArea(grading, "/teacher/exams/review/9", null).id, "r-ta", "trang chấm bài nằm dưới /teacher/exams");
  assert.equal(a.resolveArea(both, "/profile", "r-office").id, "r-student", "trang thuộc nhóm sinh viên ưu tiên khu vực có tab sinh viên");
  assert.equal(a.resolveArea(a.buildAreas([office], []), "/profile", null).id, "r-office", "không khu vực nào cùng nhóm thì dùng khu vực ưu tiên");
  assert.equal(a.resolveArea([], "/dashboard", null), null);

  // Vào cụm /admin, /office, /teacher dựa trên quyền
  assert.equal(a.canOpenGroup(admin.permissions, "admin"), true);
  assert.equal(a.canOpenGroup(admin.permissions, "office"), false);
  assert.equal(a.canOpenGroup(["examination.manage"], "office"), true);
  assert.equal(a.firstTabOfGroup(["user.manage", "access.manage"], "admin").path, "/admin/users");
  assert.equal(a.firstTabOfGroup([], "teacher"), null);
  assert.equal(a.groupOfPath("/admin/users"), "admin");
  assert.equal(a.groupOfPath("/teachers-lounge"), "student");

  // Ghi nhớ khu vực: dùng localStorage, hỏng thì bỏ qua
  const store = new Map();
  globalThis.window = { localStorage: { getItem: (k) => store.get(k) ?? null, setItem: (k, v) => store.set(k, v) } };
  assert.equal(a.readPreferredArea("u1"), null);
  a.writePreferredArea("u1", "r-office");
  assert.equal(a.readPreferredArea("u1"), "r-office");
  assert.equal(a.readPreferredArea("u2"), null);
  globalThis.window = { localStorage: { getItem() { throw new Error("blocked"); }, setItem() { throw new Error("blocked"); } } };
  assert.equal(a.readPreferredArea("u1"), null);
  assert.doesNotThrow(() => a.writePreferredArea("u1", "r-admin"));

  console.log("access-areas: ok");
} finally {
  rmSync(outfile, { force: true });
}
