import assert from "node:assert/strict";
import { build } from "esbuild";
import { rmSync } from "node:fs";

const outfile = "tests/.access-areas-bundle.mjs";
await build({ entryPoints: ["src/shared/lib/access/areas.ts"], bundle: true, format: "esm", platform: "node", outfile });

try {
  const a = await import(`./.access-areas-bundle.mjs?${Date.now()}`);

  // normalizeAreas: bỏ giá trị lạ, không trùng, theo thứ tự ưu tiên
  assert.deepEqual(a.normalizeAreas(["student", "admin", "x", "student"]), ["admin", "student"]);
  assert.deepEqual(a.normalizeAreas(undefined), []);
  assert.deepEqual(a.normalizeAreas("admin"), []);

  // pickArea: lựa chọn đã lưu thắng nếu còn được phép; ngược lại theo ưu tiên admin>office>teacher>student
  assert.equal(a.pickArea(["student", "teacher"], "student"), "student");
  assert.equal(a.pickArea(["student", "teacher"], "admin"), "teacher");
  assert.equal(a.pickArea(["student", "teacher"], null), "teacher");
  assert.equal(a.pickArea(["student", "office", "admin"]), "admin");
  assert.equal(a.pickArea([], "student"), null);
  assert.equal(a.pickArea(["student"], "not-an-area"), "student");

  // homeForArea / areaOfPath
  assert.equal(a.homeForArea("teacher"), "/teacher/problems");
  assert.equal(a.homeForArea("office"), "/office/examinations");
  assert.equal(a.homeForArea("admin"), "/admin/overview");
  assert.equal(a.homeForArea("student"), "/dashboard");
  assert.equal(a.homeForArea(null), "/login");
  assert.equal(a.areaOfPath("/admin/users"), "admin");
  assert.equal(a.areaOfPath("/office"), "office");
  assert.equal(a.areaOfPath("/teacher/problems"), "teacher");
  assert.equal(a.areaOfPath("/teachers-lounge"), "student");
  assert.equal(a.areaOfPath("/workspace/1"), "student");

  // Ghi nhớ khu vực: dùng localStorage, hỏng thì bỏ qua
  const store = new Map();
  globalThis.window = { localStorage: { getItem: (k) => store.get(k) ?? null, setItem: (k, v) => store.set(k, v) } };
  assert.equal(a.readPreferredArea("u1"), null);
  a.writePreferredArea("u1", "office");
  assert.equal(a.readPreferredArea("u1"), "office");
  assert.equal(a.readPreferredArea("u2"), null);
  store.set("sql-practice:area:u3", "bogus");
  assert.equal(a.readPreferredArea("u3"), null);
  globalThis.window = { localStorage: { getItem() { throw new Error("blocked"); }, setItem() { throw new Error("blocked"); } } };
  assert.equal(a.readPreferredArea("u1"), null);
  assert.doesNotThrow(() => a.writePreferredArea("u1", "admin"));

  console.log("access-areas: ok");
} finally {
  rmSync(outfile, { force: true });
}
