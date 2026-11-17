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
const calls = [];
globalThis.__validateStage = async payload => { calls.push(payload); return globalThis.__stageResult; };
const bundle = await build({
  stdin: { contents: `
    import React, { act, useState } from 'react';
    import { createRoot } from 'react-dom/client';
    import { Simulate } from 'react-dom/test-utils';
    import { TeacherStageExercises } from './src/features/teacher/problem-editor/TeacherStageExercises';
    const runningChange = () => {};
    function Harness({part='DDL',startFrom='provided',datasets=[{seedData:''}],answer='ALTER TABLE SANPHAM ADD GHICHU VARCHAR(20);'}) {
      const [exercise, setExercise] = useState({ questions: [{ id:'q2', name:'DDL2.sql',
        description:'Add GHICHU', answer,
        enabled:true, startFrom, setupDdl:'', setupDml:'' }] });
      return <TeacherStageExercises part={part} exercise={exercise} schema="CREATE TABLE SANPHAM (MASP CHAR(4) PRIMARY KEY);"
        datasets={datasets} onChange={setExercise} onRunningChange={runningChange} />;
    }
    export { act };
    export function mount(node) { const root=createRoot(node); root.render(<Harness/>); return root; }
    export function edit(node, value) { node.value=value; Simulate.change(node); }
    export function showDml(root,startFrom) { root.render(<Harness key={startFrom} part="DML" startFrom={startFrom}
      answer="INSERT INTO SANPHAM (MASP) VALUES ('SP01');" datasets={[{seedData:'seed A'},{seedData:'seed B'}]} />); }
  `, resolveDir: process.cwd(), loader: "tsx" },
  bundle: true, write: false, platform: "node", format: "cjs", jsx: "automatic", loader: { ".css": "empty" },
  define: { "import.meta.hot": "undefined" },
  plugins: [{ name: "stage-test-service", setup(builder) {
    builder.onLoad({ filter: /[\\/]teacherService\.ts$/ }, () => ({ contents: "export const teacherService={validateStage:payload=>globalThis.__validateStage(payload)};", loader:"js" }));
    builder.onLoad({ filter: /[\\/]ProblemSqlEditor\.tsx$/ }, () => ({ contents: "export function ProblemSqlEditor({value,label,onChange}) { return <textarea aria-label={label} value={value} onChange={event=>onChange(event.target.value)} />; }", loader:"jsx" }));
  } }],
});
const filename = join(tmpdir(), `sqluit-stage-schema-${process.pid}.cjs`);
await writeFile(filename, bundle.outputFiles[0].contents);
const product = { name:"SANPHAM", columns:[{name:"MASP",type:"char(4)",constraints:"NOT NULL"}, {name:"GHICHU",type:"varchar(20)",constraints:"NULL"}], primaryKeys:["MASP"], foreignKeys:[], constraints:[] };
const withoutNote = {...product,columns:product.columns.slice(0,1)};
const allTables = ["KHACHHANG", "NHANVIEN", "SANPHAM", "HOADON", "CTHD"].map(name => ({...product,name}));
let root;
try {
  const api = createRequire(import.meta.url)(filename);
  globalThis.__stageResult = { status:"Success", schemaTables:allTables, schemaChanges:[{name:"SANPHAM",change:"modified",before:withoutNote,table:product}] };
  await api.act(async () => { root=api.mount(document.getElementById("root")); });
  const setup = document.querySelector('.teacher-stage-setup-field');
  assert.equal(setup.open,false,'Empty optional setup starts collapsed so it does not compete with the answer');
  await api.act(async () => setup.querySelector('summary').click());
  assert.equal(setup.open,true);
  await api.act(async () => api.edit(setup.querySelector('textarea'),'-- Prepare this question'));
  assert.equal(setup.open,true,'Editing setup retains its expanded state');
  const run = () => api.act(async () => document.querySelector('.teacher-solution-run-row button').click());
  await run();
  assert.equal(calls.length,1);
  assert.equal(calls[0].question.startFrom,"provided");
  assert.equal(calls[0].question.setupDdl,'-- Prepare this question');
  assert.ok(document.querySelector('.teacher-stage-run-summary').textContent.includes('Đã thay đổi: SANPHAM'));
  assert.ok(!document.querySelector('.teacher-stage-run-summary').textContent.includes('5 bảng'));
  const details = document.querySelector('.teacher-stage-changed-tables');
  assert.equal(details.open,false,'The preview starts collapsed in the result corner');
  await api.act(async () => details.querySelector('summary').click());
  assert.equal(details.open,true);
  assert.deepEqual([...details.querySelectorAll('article > header strong')].map(node=>node.textContent), ['SANPHAM','SANPHAM']);
  const before = () => document.querySelector('.teacher-schema-comparison-before');
  const after = () => document.querySelector('.teacher-schema-comparison-after');
  assert.equal(before().querySelector('h4').textContent,'Ban đầu');
  assert.equal(after().querySelector('h4').textContent,'Sau thay đổi');
  assert.ok(!before().textContent.includes('GHICHU'));
  assert.ok(after().textContent.includes('GHICHU'));
  assert.ok(after().textContent.includes('varchar(20)'));
  assert.ok(details.querySelector('.teacher-schema-key-badge.is-pk'));
  assert.equal(details.querySelector('.database-schema-view-switch, svg.teacher-schema-diagram-lines, button'),null,'Comparison is always a list without diagram controls');
  assert.equal(details.querySelector('[aria-label="Tải PNG"]'),null);

  const toggle = document.querySelector('.teacher-question-toggle');
  const body = document.getElementById(toggle.getAttribute('aria-controls'));
  const answerInput = document.querySelector('textarea[aria-label="Đáp án tham khảo 1"]');
  await api.act(async () => toggle.click());
  assert.equal(toggle.getAttribute('aria-expanded'),'false');
  assert.equal(body.hidden,true,'Collapsing a question hides its editors and results');
  const description = document.querySelector('.database-description-question-body textarea');
  assert.equal(description.value,'Add GHICHU');
  assert.equal(body.contains(description),false,'Question description stays outside the collapsed SQL area');
  assert.equal(description.closest('.teacher-question-description-editor').getAttribute('aria-hidden'),'true','The edit box and its label fade out while collapsed');
  const questionPreview = document.querySelector('.teacher-question-description-preview');
  assert.equal(questionPreview.hidden,false);
  assert.equal(questionPreview.textContent,'Add GHICHU','Collapsed questions show only their main text without a textbox');
  assert.equal(questionPreview.querySelector('textarea,input,label'),null);
  assert.equal(document.querySelector('textarea[aria-label="Đáp án tham khảo 1"]'),answerInput,'Editors stay mounted while collapsed');
  assert.ok(document.querySelector('.teacher-question-header input'),'The filename remains editable in the collapsed header');
  await api.act(async () => toggle.click());
  assert.equal(body.hidden,false);
  assert.equal(questionPreview.hidden,true);
  assert.equal(description.closest('.teacher-question-description-editor').getAttribute('aria-hidden'),'false');
  assert.equal(answerInput.value,'ALTER TABLE SANPHAM ADD GHICHU VARCHAR(20);');
  assert.equal(setup.querySelector('textarea').value,'-- Prepare this question');
  assert.equal(setup.open,true);
  assert.equal(details.open,true,'Reopening retains the result preview and its expanded state');
  assert.equal(calls.length,1,'Reopening never reruns the SQL');

  await api.act(async () => api.edit(document.querySelector('textarea[aria-label="Đáp án tham khảo 1"]'), 'ALTER TABLE SANPHAM DROP COLUMN GHICHU;'));
  assert.equal(document.querySelector('.teacher-stage-run-result'),null,'Editing the answer hides the old structure');
  globalThis.__stageResult = {status:'Success', schemaChanges:[{name:'SANPHAM',change:'modified',before:product,table:withoutNote}]};
  await run();
  assert.ok(before().textContent.includes('GHICHU'),'The original column remains in the before pane');
  assert.ok(!after().textContent.includes('GHICHU'),'Dropping a column removes it from the after pane');

  const widerNote = {...product,columns:[product.columns[0],{...product.columns[1],type:'varchar(100)'}]};
  globalThis.__stageResult = {status:'Success',schemaChanges:[{name:'SANPHAM',change:'modified',before:product,table:widerNote}]};
  await run();
  assert.ok(before().textContent.includes('varchar(20)'));
  assert.ok(after().textContent.includes('varchar(100)'));

  globalThis.__stageResult = {status:'Success',schemaChanges:[{name:'SANPHAM',change:'dropped',before:product,table:null}]};
  await run();
  assert.ok(document.querySelector('.teacher-stage-run-summary').textContent.includes('Đã xóa: SANPHAM'));
  assert.ok(before().textContent.includes('GHICHU'));
  assert.ok(after().textContent.includes('Bảng đã bị xóa.'));
  assert.equal(after().querySelector('.teacher-schema-snapshot-column'),null);

  globalThis.__stageResult = {status:'Success',schemaChanges:['SANPHAM1','KHACHHANG1'].map(name=>({name,change:'created',before:null,table:{...product,name}}))};
  await run();
  const pairs = document.querySelectorAll('.teacher-schema-comparison-pair');
  assert.equal(pairs.length,2,'New tables each have their own aligned before/after pair');
  for (const pair of pairs) {
    assert.ok(pair.querySelector('.teacher-schema-comparison-before').textContent.includes('Bảng chưa tồn tại.'));
    assert.equal(pair.querySelector('.teacher-schema-comparison-before .teacher-schema-snapshot-column'),null);
    assert.equal(pair.querySelectorAll('.teacher-schema-comparison-after .teacher-schema-snapshot-column').length,2);
  }
  assert.ok(document.querySelector('.teacher-stage-changed-tables summary').textContent.includes('(2)'));
  globalThis.__stageResult = {status:'Success',schemaChanges:[]};
  await run();
  assert.ok(document.querySelector('.teacher-stage-run-summary').textContent.includes('Không có thay đổi cấu trúc bảng.'));
  assert.equal(document.querySelector('.teacher-stage-changed-tables'),null);

  globalThis.__validateStage = async () => { throw new Error('Invalid SQL'); };
  await run();
  assert.ok(document.querySelector('.teacher-stage-run-result.is-error').textContent.includes('Invalid SQL'));
  assert.equal(document.querySelector('.teacher-stage-changed-tables'),null);
  globalThis.__validateStage = async payload => { calls.push(payload); return {status:'Success',tables:allTables}; };
  let callCount = calls.length;
  await api.act(async () => api.showDml(root,'structure'));
  await run();
  assert.equal(calls.length,callCount+1,'Import questions run once even when multiple seed datasets exist');
  assert.equal(calls.at(-1).seedData,'','Ignored seeds are not sent to the server');
  assert.ok(document.querySelector('.teacher-stage-run-summary').textContent.includes('Chạy xong'),'DML does not report all original tables as updated');
  callCount=calls.length;
  await api.act(async () => api.showDml(root,'provided'));
  await run();
  assert.deepEqual(calls.slice(callCount).map(call=>call.seedData),['seed A','seed B'],'Update questions still run on every seed');
  const beforeData = {name:'SANPHAM1',columns:['MASP','GIA'],rows:[['SP01',100000],['SP02',20000]]};
  const afterData = {...beforeData,rows:[['SP01',105000],['SP02',20000]]};
  globalThis.__dmlChanges = [{name:'SANPHAM1',change:'modified',before:beforeData,table:afterData}];
  globalThis.__validateStage = async () => ({status:'Success',dataChanges:globalThis.__dmlChanges});
  await api.act(async () => api.showDml(root,'structure'));
  await run();
  assert.ok(document.querySelector('.teacher-stage-run-summary').textContent.includes('Đã thay đổi: SANPHAM1'));
  const dataDetails = document.querySelector('.teacher-stage-changed-tables');
  assert.ok(dataDetails.querySelector('summary').textContent.includes('Xem dữ liệu (1)'));
  await api.act(async () => dataDetails.querySelector('summary').click());
  const oldData = () => document.querySelector('.teacher-data-comparison-before');
  const newData = () => document.querySelector('.teacher-data-comparison-after');
  assert.ok(oldData().textContent.includes('100000'));
  assert.ok(newData().textContent.includes('105000'));
  assert.equal(oldData().querySelectorAll('tbody .is-changed').length,1);
  assert.equal(newData().querySelectorAll('tbody .is-changed').length,1,'Only changed rows are highlighted');
  assert.equal(dataDetails.querySelector('.teacher-schema-snapshot-column'),null,'DML shows actual rows rather than schema columns');
  globalThis.__dmlChanges = ['SANPHAM1','KHACHHANG1'].map(name=>({name,change:'created',before:null,table:{...afterData,name}}));
  await run();
  assert.equal(document.querySelectorAll('.teacher-data-comparison-before').length,2);
  assert.ok(oldData().textContent.includes('Bảng chưa tồn tại.'));
  assert.equal(document.querySelectorAll('.teacher-data-comparison-after table').length,2);
  globalThis.__dmlChanges = [{name:'SANPHAM1',change:'modified',before:{...beforeData,rows:[]},table:{...afterData,rows:[['SP01',null],['SP02','NULL']]}}];
  await run();
  assert.ok(oldData().textContent.includes('Bảng chưa có dữ liệu.'));
  assert.equal(newData().querySelectorAll('.teacher-data-null').length,1,'SQL NULL stays distinct from a literal NULL string');
  globalThis.__dmlChanges = [{name:'SANPHAM1',change:'modified',before:{...beforeData,rows:[['SP01',1],['SP01',1]]},table:{...afterData,rows:[['SP01',1]]}}];
  await run();
  assert.equal(oldData().querySelectorAll('tbody .is-changed').length,1,'Removing one duplicate highlights only the removed occurrence');
  assert.equal(newData().querySelectorAll('tbody .is-changed').length,0);
  globalThis.__dmlChanges = [];
  await run();
  assert.equal(document.querySelector('.teacher-stage-changed-tables'),null);
  assert.ok(document.querySelector('.teacher-stage-run-summary').textContent.includes('Không có thay đổi dữ liệu.'));
  console.log('PASS: list-only before/after comparison, added/removed columns, changed types, created/dropped tables, stale results, no changes and errors.');
} finally {
  if(root) await createRequire(import.meta.url)(filename).act(async()=>root.unmount());
  await unlink(filename);
  delete globalThis.__validateStage;
  delete globalThis.__stageResult;
  delete globalThis.__dmlChanges;
  dom.window.close();
}
process.exit(0);
