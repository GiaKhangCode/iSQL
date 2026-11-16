import assert from "node:assert/strict";
import { build } from "esbuild";
import { rmSync } from "node:fs";

// Bundle the role service and permission catalog into a temporary JS bundle for testing.
const outfile = "tests/.role-adapter-bundle.mjs";
await build({
  entryPoints: ["src/features/admin/roles/roleService.ts"],
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
  const serverPermissions = {
    "practice.solve": "Solve practice problems",
    "problem.create": "Create problems",
    "results.grade": "Grade assigned activities",
    "brand.new": "A permission added later",
  };
  globalThis.fetch = async (url, options = {}) => {
    calls.push({ url, options });
    const json = async () => {
      if (url === "/api/admin/access") {
        return {
          roles: [
            { id: "actor:student", name: "Mặc định · Sinh viên", permissions: ["practice.solve"] },
            { id: "custom-1", name: "Trợ giảng", permissions: ["results.grade", "problem.create"] },
          ],
          permissions: serverPermissions,
        };
      }
      if (url === "/api/admin/access/roles" && options.method === "POST") {
        const body = JSON.parse(options.body);
        return { id: "new-role-id", name: body.name, permissions: body.permissions };
      }
      if (url === "/api/admin/access/roles/custom-1" && options.method === "PUT") {
        const body = JSON.parse(options.body);
        return { id: "custom-1", name: body.name, permissions: body.permissions };
      }
      if (url === "/api/admin/access/users/user-1" && options.method === "PUT") {
        const body = JSON.parse(options.body);
        return { actor: "student", studentCode: null, role_ids: body.role_ids, direct_permissions: body.direct_permissions ?? ["results.grade"], permissions: ["practice.solve"] };
      }
      if (url === "/api/admin/access/users/user-1") {
        return { actor: "student", studentCode: "21520000", role_ids: ["custom-1"], direct_permissions: ["results.grade"], permissions: ["practice.solve", "results.grade"] };
      }
      return {};
    };
    return { ok: true, status: 200, headers: { get: () => "application/json" }, json };
  };

  const { roleService, buildPermissionGroups, permissionSources, permissionLabel, sameSet } = await import("./.role-adapter-bundle.mjs");

  // Catalog: roles are plain permission-code lists; system roles are normalised and the admin role is always present.
  const catalog = await roleService.getCatalog();
  assert.deepStrictEqual(catalog.customRoles.map((role) => role.name), ["Trợ giảng"]);
  assert.deepStrictEqual(catalog.customRoles[0].permissions, ["problem.create", "results.grade"]);
  assert.equal(catalog.systemRoles.find((role) => role.id === "actor:student").name, "Sinh viên");
  const admin = catalog.systemRoles.find((role) => role.id === "actor:admin");
  assert.equal(admin.readOnly, true);
  assert.deepStrictEqual(admin.permissions, Object.keys(serverPermissions).sort(), "admin holds every permission the server reports");

  // The picker groups only what the server enforces, and any new server permission still shows up.
  const groups = buildPermissionGroups(serverPermissions);
  const codes = groups.flatMap((group) => group.permissions.map((permission) => permission.code)).sort();
  assert.deepStrictEqual(codes, Object.keys(serverPermissions).sort());
  assert.equal(groups.at(-1).id, "other");
  assert.equal(groups.at(-1).permissions[0].code, "brand.new");
  assert.ok(!codes.includes("exam.proctor"), "permissions the server does not report are not offered");
  assert.equal(permissionLabel(groups, "problem.create"), "Tạo và chỉnh sửa bài toán");
  assert.equal(permissionLabel(groups, "unknown.code"), "unknown.code");

  // Saving sends exactly the chosen codes, sorted, with no lossy translation.
  await roleService.createRole({ name: "  Giám thị  ", permissions: ["results.grade", "practice.solve"] });
  const created = calls.find((call) => call.url === "/api/admin/access/roles" && call.options.method === "POST");
  assert.deepStrictEqual(JSON.parse(created.options.body), { name: "Giám thị", permissions: ["practice.solve", "results.grade"] });
  const updated = await roleService.updateRole("custom-1", { id: "custom-1", name: "Trợ giảng", permissions: ["practice.solve"] });
  assert.deepStrictEqual(updated.permissions, ["practice.solve"]);

  // A user keeps several roles; effective permissions come straight from the server.
  const assignment = await roleService.getUserAssignment("user-1");
  assert.deepStrictEqual(assignment.roleGroupIds, ["custom-1"]);
  assert.deepStrictEqual(assignment.effectivePermissions, ["practice.solve", "results.grade"]);
  const saved = await roleService.saveUserRoles("user-1", ["custom-1", "new-role-id"]);
  assert.deepStrictEqual(saved.roleGroupIds, ["custom-1", "new-role-id"]);
  assert.deepStrictEqual(assignment.directPermissions, ["results.grade"]);
  const unchanged = calls.at(-1);
  assert.deepStrictEqual(JSON.parse(unchanged.options.body), { role_ids: ["custom-1", "new-role-id"] }, "direct permissions stay untouched unless sent");
  const withDirect = await roleService.saveUserRoles("user-1", ["custom-1"], ["problem.create", "exam.proctor"]);
  assert.deepStrictEqual(JSON.parse(calls.at(-1).options.body), { role_ids: ["custom-1"], direct_permissions: ["exam.proctor", "problem.create"] });
  assert.deepStrictEqual(withDirect.directPermissions, ["exam.proctor", "problem.create"]);

  // Provenance: which role grants which permission, so overlapping roles stay readable.
  const sources = permissionSources([
    { id: "a", name: "Giảng viên", permissions: ["problem.create", "results.grade"] },
    { id: "b", name: "Trợ giảng", permissions: ["results.grade"] },
  ]);
  assert.deepStrictEqual(sources.get("results.grade"), ["Giảng viên", "Trợ giảng"]);
  assert.deepStrictEqual(sources.get("problem.create"), ["Giảng viên"]);
  assert.ok(sameSet(["a", "b"], ["b", "a"]) && !sameSet(["a"], ["a", "b"]));

  console.log("role-adapter tests passed");
} finally {
  rmSync(outfile, { force: true });
}
