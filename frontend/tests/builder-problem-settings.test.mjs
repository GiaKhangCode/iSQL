import assert from "node:assert/strict";
import { build } from "esbuild";
import { JSDOM } from "jsdom";
import { writeFile, unlink } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { createRequire } from "node:module";
const dom = new JSDOM('<div id="root"></div>');
const output = await build({
  stdin: { contents: `
    import React, { useState } from "react";
    export { act } from "react";
    export { createRoot } from "react-dom/client";
    import { BuilderProblemSettings } from "./src/features/teacher/builder/BuilderProblemSettings";
    export function Harness() {
      const [datasetVisibility, setDatasetVisibility] = useState({});
      const [parts, setParts] = useState(["DDL", "DML", "SQL"]);
      const [selection, setSelection] = useState({});
      const [visibility, setVisibility] = useState({ databaseStructureVisible: true, databaseDescriptionVisible: true });
      globalThis.currentSelection=selection;
      return <BuilderProblemSettings problemId="p1" title="Customer exercise" questionSelection={selection} onQuestionSelectionChange={setSelection} visibility={visibility} onVisibilityChange={setVisibility} datasetVisibility={datasetVisibility} onDatasetVisibilityChange={setDatasetVisibility} parts={parts} closing={false} onClose={() => globalThis.closed = true} onClosed={() => {}} onPartsChange={setParts} onAdd={() => globalThis.added = {parts,selection,datasetVisibility}} />;
    }
  `, resolveDir: process.cwd(), loader: "tsx" },
  bundle: true, write: false, platform: "node", format: "cjs", jsx: "automatic",
  loader: { ".css": "empty" }, define: { "import.meta.hot": "undefined" },
  plugins: [{ name: "mock-service", setup(build) {
    build.onResolve({ filter: /shared\/api\/teacherService$/ }, () => ({ path: "service", namespace: "mock" }));
    build.onLoad({ filter: /.*/, namespace: "mock" }, () => ({ contents: `export const teacherService = { getProblem: async id => globalThis.problemDetail };` }));
  } }],
});
const filename = join(tmpdir(), `sqluit-builder-settings-${process.pid}.cjs`);
try {
  await writeFile(filename, output.outputFiles[0].contents);
  const { act, createRoot, Harness } = createRequire(import.meta.url)(filename);
  const React = createRequire(import.meta.url)("react");
  globalThis.window = dom.window; globalThis.document = dom.window.document;
  globalThis.IS_REACT_ACT_ENVIRONMENT = true;
  globalThis.problemDetail = {
    description: "Customer exercise statement", requirements: "Find customers",
    schema: "CREATE TABLE Customers (Id INT PRIMARY KEY);",
    databaseDescription: [{ tableName: "Customers", attributes: "Id", predicate: "Customer records" }],
    testCases: [{ seedData: "INSERT INTO Customers (Id) VALUES (1);", isHidden: false },{seedData:"INSERT INTO Customers (Id) VALUES (99);",isHidden:true}],
    ddlExercise: {questions:[{id:"alter",name:"alter.sql",description:"Add a phone column",answer:"SECRET ANSWER"}]},
    dmlExercise: {questions:[{id:"update",name:"update.sql",description:"Update customers",answer:"SECRET ANSWER"}]},
    sqlSolutions: [{ id: "q", name: "Answer", query: "SELECT Id FROM Customers", enabled: true, timeLimitMs: 1500 }],
    expectedOutputs: [{ columns: ["Id"], rows: [[1]] }],
  };
  const root = createRoot(document.getElementById("root"));
  await act(async () => root.render(React.createElement(Harness)));
  const click = node => act(async () => node.click());
  const section = part => document.getElementById('builder-settings-'+part);
  const master = part => section(part).closest('.builder-settings-section').querySelector('.builder-settings-part-toggle');
  const question = name => document.querySelector(`.builder-settings-question input[aria-label="Chọn ${name}"]`);
  assert.deepEqual(globalThis.currentSelection.DDL,['__base_ddl__','alter']);
  assert.deepEqual(globalThis.currentSelection.DML,['__base_dml__','update']);
  assert.deepEqual(globalThis.currentSelection.SQL,['q']);
  assert.ok(section('DDL').textContent.includes('Add a phone column'));
  assert.ok(section('DDL').textContent.includes('Tạo bảng và khai báo khóa'));
  for(const part of ['DDL','DML','SQL']) {
    assert.ok(!section(part).textContent.includes('Customer exercise statement'),'Sections contain questions, without the general statement');
    assert.equal(section(part).querySelector('.database-schema-panel,.builder-settings-data'),null,'Reference metadata and seed rows are outside question sections');
  }
  assert.ok(!document.querySelector('aside').textContent.includes('SECRET ANSWER'));
  assert.equal(document.getElementById('builder-database-body').hidden,true);
  await click(document.querySelector('.builder-database-heading'));
  assert.ok(document.querySelector('.builder-database-reference .database-schema-list'));
  const visibilityChecks=[...document.querySelectorAll('.builder-database-student-visibility input[type="checkbox"]')];
  assert.deepEqual(visibilityChecks.map(box=>box.checked),[true,true],'Students see the structure and the description by default');
  await click(visibilityChecks[0]); await click(visibilityChecks[1]);
  assert.deepEqual([...document.querySelectorAll('.builder-database-student-visibility input[type="checkbox"]')].map(box=>box.checked),[false,false],'Unticking hides them from students');
  await click(question('Câu hỏi 2 · Add a phone column'));
  assert.deepEqual(globalThis.currentSelection.DDL,['__base_ddl__']);
  assert.ok(master('DDL').indeterminate,'Partial selection is visible in the section checkbox');
  await click(master('DML'));
  assert.equal(master('DML').checked,false);
  await click(question('Câu hỏi 2 · Update customers'));
  assert.deepEqual(globalThis.currentSelection.DML,['update'],'Picking one question enables just that question');
  assert.equal(master('DML').checked,true);
  await click(section('DML').querySelector('.builder-settings-select-all'));
  assert.deepEqual(globalThis.currentSelection.DML,['__base_dml__','update']);
  await click(document.getElementById('builder-db-data'));
  assert.ok(document.querySelector('.builder-database-reference .data-table').textContent.includes('1'));
  const datasets=()=>[...document.querySelectorAll('.builder-settings-dataset-heading button')];
  await click(datasets()[0]);
  assert.ok(document.querySelector('.builder-problem-settings-footer button').disabled,'An import question needs a visible target dataset');
  await click(datasets()[1]);
  assert.equal(document.querySelector('.builder-problem-settings-footer button').disabled,false);
  await click(document.querySelector('.builder-problem-settings-footer button'));
  assert.deepEqual(globalThis.added.selection.DDL,['__base_ddl__']);
  assert.deepEqual(globalThis.added.datasetVisibility,{'1':false,'2':true});
  for(const part of ['DDL','DML','SQL']) await click(master(part));
  assert.ok(document.querySelector('.builder-problem-settings-footer button').disabled);
  await act(async()=>document.querySelector('aside').dispatchEvent(new dom.window.KeyboardEvent('keydown',{key:'Escape',bubbles:true})));
  assert.equal(globalThis.closed,true);
  await act(async () => root.unmount());
  console.log("PASS: settings load saved content, expand independently, choose sections, validate add and close.");
} finally { await unlink(filename); dom.window.close(); }
process.exit(0);
