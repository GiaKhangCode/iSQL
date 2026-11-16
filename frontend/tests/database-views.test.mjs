import assert from "node:assert/strict";
import { build } from "esbuild";
import { JSDOM } from "jsdom";
import { writeFile, unlink } from "node:fs/promises";
import { createRequire } from "node:module";
import { tmpdir } from "node:os";
import { join } from "node:path";

const dom = new JSDOM('<div id="root"></div>', { url: "http://localhost" });
for (const key of ["window", "document", "HTMLElement", "Element", "Node", "localStorage"]) globalThis[key] = dom.window[key];
globalThis.IS_REACT_ACT_ENVIRONMENT = true;
const bundle = await build({
  stdin: { contents: `
    export { createElement, act } from "react";
    export { createRoot } from "react-dom/client";
    export { DatabaseDiagram } from "./src/shared/components/DatabaseDiagram";
    export { TeacherSchemaDiagram } from "./src/features/teacher/problem-editor/TeacherSchemaDiagram";
    export { WorkspaceDatabasePanel } from "./src/shared/components/WorkspaceDatabasePanel";
  `, resolveDir: process.cwd(), loader: "tsx" },
  bundle: true, write: false, platform: "node", format: "cjs", jsx: "automatic",
  loader: { ".css": "empty" }, define: { "process.env.NODE_ENV": '"development"', "import.meta.hot": "undefined" },
});
const filename = join(tmpdir(), `sqluit-database-views-${process.pid}.cjs`);
await writeFile(filename, bundle.outputFiles[0].contents);
try {
  const { createElement: h, act, createRoot, DatabaseDiagram, TeacherSchemaDiagram, WorkspaceDatabasePanel } = createRequire(import.meta.url)(filename);
  const root = createRoot(document.getElementById("root"));
  const tables = [
    { name: "Customers", columns: [{ name: "customer_id", type: "INT", constraints: "PRIMARY KEY" }], primaryKeys: ["customer_id"], foreignKeys: [], constraints: [] },
    { name: "Orders", columns: [{ name: "order_id", type: "INT", constraints: "PRIMARY KEY" }, { name: "customer_id", type: "INT", constraints: "NOT NULL" }], primaryKeys: ["order_id"], foreignKeys: [{ columns: ["customer_id"], referencedTable: "Customers", referencedColumns: ["customer_id"] }], constraints: [] },
  ];
  const controls = () => [...document.querySelectorAll('.database-schema-view-switch button')];
  const click = async button => act(async () => button.click());
  for (const component of [DatabaseDiagram, TeacherSchemaDiagram]) {
    await act(async () => root.render(h(component, { tables, key: component === DatabaseDiagram ? "shared" : "teacher" })));
    assert.equal(controls()[0].getAttribute("aria-pressed"), "true", "Student and teacher both default to list view");
    assert.equal(controls()[1].getAttribute("aria-pressed"), "false");
    assert.deepEqual([...document.querySelectorAll('.database-schema-list article > header strong')].map(node => node.textContent), ["Customers", "Orders"], "All tables remain in instructor order");
    assert.equal(document.querySelector('.teacher-schema-diagram-lines'), null, "List view has no off-screen relationship canvas");
    assert.ok(document.querySelector('.database-schema-reference').textContent.includes('Customers.customer_id'), "Foreign key targets stay readable without diagram edges");
    assert.ok(document.querySelector('[aria-label="Tải PNG"]'), "Teacher export remains available");
    const names = [...document.querySelectorAll('.teacher-schema-diagram-column-name > strong')].map(node => node.textContent);
    await click(controls()[1]);
    assert.equal(controls()[1].getAttribute("aria-pressed"), "true");
    assert.ok(document.querySelector('.teacher-schema-diagram-lines g'), "Diagram view restores relationship edges");
    assert.ok(document.querySelectorAll('.teacher-schema-diagram-table')[1].style.left, "Diagram view keeps the original dependency layout");
    assert.deepEqual([...document.querySelectorAll('.teacher-schema-diagram-column-name > strong')].map(node => node.textContent), names);
    await click(controls()[0]);
    assert.equal(document.querySelectorAll('.database-schema-list article').length, 2);
    assert.equal(document.querySelectorAll('.teacher-schema-diagram-table')[1].style.left, "", "List view removes absolute diagram positions");
  }
  await act(async () => root.render(h(WorkspaceDatabasePanel, { database: { schemaTables: tables, tables: [] } })));
  assert.equal(controls()[0].getAttribute("aria-pressed"), "true");
  assert.equal(document.querySelector('[aria-label="Tải PNG"]'), null, "Workspace still disables downloads");
  await act(async () => root.render(h(WorkspaceDatabasePanel, { database: { schemaTables: tables, tables: [] }, structureVisible: false })));
  assert.equal(document.querySelector('.database-schema-panel'), null, "Instructor visibility settings apply to both modes");
  await act(async () => root.unmount());
  console.log("PASS: student/teacher list default, two-way switching, table order, PK/FK details, diagram edges, downloads and hidden structures.");
} finally {
  await unlink(filename);
  dom.window.close();
}
// Browser-oriented dependencies retain background handles in the isolated DOM.
process.exit(0);
