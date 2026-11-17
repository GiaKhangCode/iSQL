import assert from 'node:assert/strict';
import { build } from 'esbuild';
import { JSDOM } from 'jsdom';
import { readFile, writeFile, unlink } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const dom = new JSDOM('<div id="root"></div>', { url: 'http://localhost' });
for (const key of ['window', 'document', 'HTMLElement', 'Element', 'Node', 'localStorage']) globalThis[key] = dom.window[key];
globalThis.IS_REACT_ACT_ENVIRONMENT = true;
const queries = await Promise.all([1, 2, 3, 4, 5].map(n => readFile(`../sql/bai-6-ban-hang/queries/6.${n}.sql`, 'utf8')));
globalThis.__dqlQueries = queries;
const calls = [];
const table = (query, seed) => ({ name: 'Result', columns: ['MASP', 'TENSP'], rows: [[String(queries.indexOf(query) + 1), seed]] });
globalThis.__dqlRun = async payload => {
  calls.push(payload);
  return { status: 'Success', executionTimeMs: 2, table: table(payload.referenceSolution, payload.seedData) };
};
const bundle = await build({
  stdin: { contents: `
    import React, { act, useState } from 'react';
    import { createRoot } from 'react-dom/client';
    import { Simulate } from 'react-dom/test-utils';
    import { TeacherSqlSolutions } from './src/features/teacher/problem-editor/TeacherSqlSolutions';
    let current;
    const running = () => {};
    function Harness() {
      const [solutions, setSolutions] = useState(globalThis.__dqlQueries.map((query,index) => ({
        id:'q'+(index+1), name:'DQL'+(index+1)+'.sql', description:'Question '+(index+1),
        query, enabled:true, timeLimitMs:5000, expectedOutputs:[], expectedOutputVisible:true
      })));
      const [expected, setExpected] = useState([]);
      current = {solutions,expected};
      return <TeacherSqlSolutions solutions={solutions} onChange={setSolutions} schema="CREATE TABLE SANPHAM (MASP CHAR(4));"
        datasets={[{seedData:'original'},{seedData:'boundary',isHidden:true}]}
        onExpectedOutputsChange={setExpected} onRunningChange={running} />;
    }
    export {act};
    export const state = () => current;
    export function mount(node) { const root=createRoot(node); root.render(<Harness/>); return root; }
    export function edit(node,value) { node.value=value; Simulate.change(node); }
  `, resolveDir: process.cwd(), loader: 'tsx' },
  bundle: true, write: false, platform: 'node', format: 'cjs', jsx: 'automatic', loader: { '.css': 'empty' },
  plugins: [{ name: 'dql-service-fixture', setup(builder) {
    builder.onLoad({ filter: /[\\/]teacherService\.ts$/ }, () => ({ contents: 'export const teacherService={validateSolution:payload=>globalThis.__dqlRun(payload)};', loader:'js' }));
    builder.onLoad({ filter: /[\\/]ProblemSqlEditor\.tsx$/ }, () => ({ contents: 'export function ProblemSqlEditor({value,label,onChange}) { return <textarea aria-label={label} value={value} onChange={event=>onChange(event.target.value)} />; }', loader:'jsx' }));
    builder.onLoad({ filter: /[\\/]LanguageContext\.tsx$/ }, () => ({ contents: "export const useLanguage=()=>({locale:'en-US',t:(text,values={})=>text.replace(/\\{(\\w+)\\}/g,(_,key)=>values[key]??'')});", loader:'js' }));
    builder.onLoad({ filter: /[\\/]TranslatedText\.tsx$/ }, () => ({ contents: 'export function TranslatedText({children}) { return <>{children}</>; }', loader:'jsx' }));
  } }],
});
const filename = join(tmpdir(), `sqluit-dql-sales-${process.pid}.cjs`);
let root, api;
try {
  await writeFile(filename, bundle.outputFiles[0].contents);
  api = createRequire(import.meta.url)(filename);
  await api.act(async () => { root=api.mount(document.getElementById('root')); });
  const cards = () => [...document.querySelectorAll('.teacher-exercise-question')];
  const run = index => cards()[index].querySelector('.teacher-solution-run-row button');
  assert.equal(cards().length,5);
  for (let index=0; index<5; index++) await api.act(async () => run(index).click());
  assert.equal(calls.length,10);
  assert.deepEqual(calls.map(call=>call.seedData),Array.from({length:5},()=>['original','boundary']).flat());
  assert.deepEqual(api.state().solutions.map(item=>item.expectedOutputs.length),[2,2,2,2,2]);
  assert.deepEqual(api.state().expected,api.state().solutions[0].expectedOutputs);
  assert.equal(cards()[4].querySelectorAll('.teacher-solution-output table').length,2,'DQL results render as tables');
  const output=cards()[4].querySelector('.teacher-solution-output');
  assert.equal(output.open,false,'Results start collapsed like DDL and DML');
  assert.ok(output.querySelector('.teacher-stage-result-header .teacher-stage-result-toggle'),'DQL shares the result header with DDL and DML');
  await api.act(async () => output.querySelector('summary').click());
  assert.equal(output.open,true);
  assert.ok(output.querySelector('.teacher-data-snapshot-scroll .app-table'),'DQL shares the data table presentation with DML');

  const previous = structuredClone(api.state().solutions);
  globalThis.__dqlRun = async payload => payload.seedData === 'boundary'
    ? {status:'Error',message:'Invalid SQL'} : {status:'Success',table:{name:'Result',columns:['x'],rows:[[99]]}};
  await api.act(async () => run(1).click());
  assert.deepEqual(api.state().solutions[1].expectedOutputs,previous[1].expectedOutputs,'A failed dataset must not publish partial outputs');
  assert.ok(cards()[1].querySelector('[role="alert"]').textContent.includes('Invalid SQL'));

  let complete;
  globalThis.__dqlRun = () => new Promise(resolve => { complete=resolve; });
  await api.act(async () => run(2).click());
  assert.ok(cards().every((_,index)=>run(index).disabled),'Avoid overlapping runs');
  await api.act(async () => api.edit(cards()[2].querySelector('textarea[aria-label="Solution 3 SQL"]'),'SELECT MASP,TENSP FROM SANPHAM;'));
  await api.act(async () => complete({status:'Success',table:{name:'Result',columns:['x'],rows:[[99]]}}));
  await api.act(async () => complete({status:'Success',table:{name:'Result',columns:['x'],rows:[[99]]}}));
  assert.deepEqual(api.state().solutions[2].expectedOutputs,[],'An edited query must not receive stale results');
  for (const index of [0,1,3,4]) assert.deepEqual(api.state().solutions[index].expectedOutputs,previous[index].expectedOutputs,'Editing one question preserves other answers');
  assert.equal(cards()[2].querySelectorAll('.teacher-solution-output').length,0,'Hide outdated results');
  assert.ok(cards()[2].textContent.includes('Changed since last run'));

  await api.act(async () => cards()[4].querySelector('.teacher-question-toggle').click());
  assert.equal(cards()[4].querySelector('.teacher-question-body').hidden,true);
  assert.equal(cards()[4].querySelector('.teacher-question-description-preview').textContent,'Question 5');
  await api.act(async () => cards()[4].querySelector('.teacher-question-toggle').click());
  assert.equal(cards()[4].querySelectorAll('.teacher-solution-output').length,2,'Collapsing retains run results');
  console.log('DQL sales flow passed: 5 independent questions, 2 datasets, expected outputs, failures, stale results and collapse.');
} finally {
  if (root) await api.act(async () => root.unmount());
  await unlink(filename).catch(()=>{});
  dom.window.close();
}
// The bundled Markdown highlighter keeps background workers alive in Node.
process.exit(0);
