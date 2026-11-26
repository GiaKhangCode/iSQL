import assert from "node:assert/strict";
import { JSDOM } from "jsdom";
import { build } from "esbuild";
import { writeFile, unlink } from "node:fs/promises";
import { createRequire } from "node:module";
import { tmpdir } from "node:os";
import { join } from "node:path";

const dom = new JSDOM('<div id="root"></div>', { url: "http://localhost", pretendToBeVisual: true });
for (const key of ["window", "document", "HTMLElement", "Element", "Node", "NodeFilter", "DOMParser", "localStorage", "getComputedStyle"]) globalThis[key] = dom.window[key];
dom.window.HTMLDialogElement.prototype.showModal = function() { this.open = true; };
dom.window.scrollTo = () => {};
globalThis.ResizeObserver = class { observe() {} disconnect() {} };
globalThis.requestAnimationFrame = dom.window.requestAnimationFrame.bind(dom.window);
globalThis.cancelAnimationFrame = dom.window.cancelAnimationFrame.bind(dom.window);
dom.window.matchMedia = () => ({ matches: true, addEventListener() {}, removeEventListener() {} });
globalThis.IS_REACT_ACT_ENVIRONMENT = true;
const calls = [];
globalThis.__solutionRun = async (payload) => {
  calls.push(payload);
  return { status: "Success", executionTimeMs: calls.length === 1 ? 1.25 : 2.5,
    table: { name: "Result", columns: ["n"], rows: [[1]] } };
};
const require = createRequire(import.meta.url);
const output = await build({
  stdin: { contents: `
    import React, { act, useState } from 'react';
    import { createRoot } from 'react-dom/client';
    import { Simulate } from 'react-dom/test-utils';
    import { TeacherSqlSolutions, initialSqlSolutions } from './src/features/teacher/problem-editor/TeacherSqlSolutions';
    import { ProblemEditorPage } from './src/features/teacher/ProblemEditorPage';
    import { ToastProvider } from './src/shared/ui/StackingToasts';
    import { MemoryRouter, Routes, Route } from 'react-router-dom';
    let latest;
    let expected;
    let visible;
    const runningChange = () => {};
    function Harness() {
      const [solutions, setSolutions] = useState(() => initialSqlSolutions(undefined, 'SELECT 1 AS n'));
      const [expectedOutputs, setExpectedOutputs] = useState([]);
      const [expectedOutputVisible, setExpectedOutputVisible] = useState(true);
      const [datasets, setDatasets] = useState([{seedData:'seed A'}, {seedData:'seed B'}]);
      latest = solutions;
      expected = expectedOutputs;
      visible = expectedOutputVisible;
      return <TeacherSqlSolutions solutions={solutions} schema="CREATE TABLE T(id INT)"
        datasets={datasets} onChange={setSolutions}
        expectedOutputs={expectedOutputs} expectedOutputVisible={expectedOutputVisible}
        onExpectedOutputsChange={setExpectedOutputs} onExpectedOutputVisibilityChange={setExpectedOutputVisible}
        onDatasetVisibilityChange={(index, isHidden) => setDatasets(current => current.map((dataset, i) => i === index ? {...dataset,isHidden} : dataset))}
        onRunningChange={runningChange} />;
    }
    export { act };
    export function mount(node) { const root = createRoot(node); root.render(<Harness/>); return root; }
    export function showEditor(root) { root.render(<ToastProvider><MemoryRouter initialEntries={['/teacher/problems/new']}><Routes><Route path='/teacher/problems/new' element={<ProblemEditorPage />} /><Route path='/teacher/problems/:problemId/edit' element={<ProblemEditorPage />} /></Routes></MemoryRouter></ToastProvider>); }
    export function state() { return latest; }
    export function expectedState() { return expected; }
    export function outputVisible() { return visible; }
    export function change(node, value) { node.value = value; Simulate.change(node); }
    export function editExpanded(node, html) { node.innerHTML = html; Simulate.input(node); }
  `, resolveDir: process.cwd(), loader: "tsx" },
  bundle: true, write: false, platform: "node", format: "cjs", jsx: "automatic", logLevel: "silent",
  loader: { ".css": "empty" },
  plugins: [{ name: "solution-ui-test", setup(builder) {
    builder.onResolve({ filter: /^(react|react-dom)(\/.*)?$/ }, args => ({ path: require.resolve(args.path), external: true }));
    builder.onLoad({ filter: /[\\/]teacherService\.ts$/ }, () => ({ contents: "export const teacherService = { getAllProblems: async () => [], getProblem: async () => structuredClone(globalThis.__savedProblem), createProblem: async payload => { await globalThis.__holdSave; globalThis.__savedPayload = structuredClone(payload); globalThis.__savedProblem = { ...payload, id: 'saved-problem', description: payload.statement, databaseType: payload.database, practiceListed: payload.visibility === 'Public', testCases: payload.testCases.map(tc => ({ ...tc, schema: payload.schema })) }; return structuredClone(globalThis.__savedProblem); }, updateProblem: async (id, payload) => { if (globalThis.__updateError) throw new Error('Save failed'); globalThis.__savedPayload = structuredClone(payload); }, validateSolution: (data) => globalThis.__solutionRun(data) };", loader: "js" }));
    builder.onLoad({ filter: /[\\/]LanguageContext\.tsx$/ }, () => ({ contents: "export const useLanguage = () => ({ locale:'en-US', t: (text, values={}) => typeof text === 'string' ? text.replace(/\\{(\\w+)\\}/g, (_, key) => values[key] ?? '') : text });", loader: "js" }));
    builder.onLoad({ filter: /[\\/]TranslatedText\.tsx$/ }, () => ({ contents: "export function TranslatedText({children}) { return <>{children}</>; }", loader: "jsx" }));
    builder.onLoad({ filter: /[\\/]ProblemSqlEditor\.tsx$/ }, () => ({ contents: "export function ProblemSqlEditor({value,label,onChange}) { return <textarea aria-label={label} value={value} onChange={event=>onChange(event.target.value)} />; }", loader: "jsx" }));
    builder.onLoad({ filter: /[\\/]ProblemRichTextEditor\.tsx$/ }, () => ({ contents: "export function ProblemRichTextEditor({value,label,onChange,onOpenExpanded}) { return <><textarea aria-label={label} value={value} onChange={event=>onChange(event.target.value)} />{onOpenExpanded && <button type='button' aria-label={'Expand '+label} onClick={onOpenExpanded}>Expand</button>}</>; }", loader: "jsx" }));
  } }],
});
const filename = join(tmpdir(), `sqluit-solution-test-${process.pid}.cjs`);
let root, api;
try {
  await writeFile(filename, output.outputFiles[0].contents);
  api = require(filename);
  await api.act(async () => { root = api.mount(document.getElementById("root")); });
  const cards=()=>[...document.querySelectorAll('.teacher-exercise-question')];
  const runButton=()=>cards()[0].querySelector('.teacher-solution-run-row button');
  const setup=()=>cards()[0].querySelector('textarea[aria-label="SQL setup before this question (optional)"]');
  const dataTab=async()=>{await api.act(async()=>document.querySelector('[aria-controls="database-panel-structure"]').click());await api.act(async()=>document.getElementById('database-setup-tab-data').click());};
  const tableTab=async()=>{await api.act(async()=>document.querySelector('[aria-controls="database-panel-structure"]').click());await api.act(async()=>document.getElementById('database-setup-tab-tables').click());};
  assert.equal(cards().length,1);
  assert.ok(setup(),'DQL has the same independent preparation editor as DDL/DML');
  await api.act(async()=>api.change(document.querySelector('.teacher-solution-limit input'),'7000'));
  assert.equal(api.state()[0].timeLimitMs,7000);
  await api.act(async()=>api.change(setup(),'SELECT * INTO T1 FROM T;'));
  await api.act(async()=>runButton().click());
  assert.deepEqual(calls.map(call=>call.seedData),['seed A','seed B']);
  assert.ok(calls.every(call=>call.setupSql==='SELECT * INTO T1 FROM T;'));
  assert.ok(calls.every(call=>call.referenceSolution==='SELECT 1 AS n'));
  assert.equal(api.state()[0].expectedOutputs.length,2);
  assert.equal(api.expectedState().length,2);
  assert.equal(cards()[0].querySelectorAll('.teacher-solution-output').length,2);
  const saved=structuredClone(api.state()[0].expectedOutputs);
  globalThis.__solutionRun=async payload=>payload.seedData==='seed B'?{status:'Error',message:'Invalid SQL'}:{status:'Success',table:{name:'Result',columns:['n'],rows:[[2]]}};
  await api.act(async()=>runButton().click());
  assert.deepEqual(api.state()[0].expectedOutputs,saved,'A failed dataset cannot publish partial results');
  let complete;
  globalThis.__solutionRun=()=>new Promise(resolve=>{complete=resolve;});
  await api.act(async()=>runButton().click());
  await api.act(async()=>api.change(setup(),'SELECT * INTO T2 FROM T;'));
  await api.act(async()=>complete({status:'Success',table:{name:'Result',columns:['n'],rows:[[3]]}}));
  await api.act(async()=>complete({status:'Success',table:{name:'Result',columns:['n'],rows:[[3]]}}));
  assert.deepEqual(api.state()[0].expectedOutputs,[],'Editing preparation invalidates old results and prevents a stale run from publishing');
  await api.act(async()=>[...document.querySelectorAll('button')].find(button=>button.textContent==='Add question').click());
  assert.equal(api.state().length,2);
  assert.ok(!api.state()[1].setupSql,'A new question never inherits another question preparation');
  const descriptions=document.querySelectorAll('.database-description-question-body textarea');
  await api.act(async()=>api.change(descriptions[1],'List products from China.'));
  assert.equal(api.state()[1].description,'List products from China.');
  assert.equal(api.state()[0].setupSql,'SELECT * INTO T2 FROM T;');
  // Use the actual New problem page and Preview button, without saving the draft.
  let value = 7;
  globalThis.__solutionRun = async () => ({ status: "Success", executionTimeMs: 1,
    table: { name: "Result", columns: ["n"], rows: [[value]] } });
  await api.act(async () => api.showEditor(root)); 
  assert.equal([...document.querySelectorAll('.teacher-field')].some(node => /^(DIFFICULTY|ĐỘ KHÓ)/.test(node.textContent.trim())), false, 'New problem has no difficulty setting');
  assert.equal(document.querySelector('#problem-requirements, [href="#problem-requirements"], [aria-label="Problem requirements"]'), null, 'New problem has no top-level requirements field or navigation entry');
  await api.act(async () => api.change(document.querySelector('textarea[aria-label="Problem statement"]'), 'Shared statement'));
  await api.act(async () => document.querySelector('[aria-label="Expand Problem statement"]').click());
  const expandedStatement = document.querySelector('.teacher-long-text-editor[aria-label="Problem statement"]');
  assert.equal(expandedStatement.textContent, 'Shared statement');
  await api.act(async () => api.editExpanded(expandedStatement, '<p>Expanded statement content</p>'));
  await api.act(async () => document.querySelector('.teacher-long-text-done').click());
  assert.equal(document.querySelector('textarea[aria-label="Problem statement"]').value, 'Expanded statement content', 'Expanded statement editing remains available');
  await api.act(async () => document.querySelector('.teacher-problem-preview-action').click());
  const preview = () => document.querySelector('.teacher-student-preview-content');
  assert.ok(preview());
  assert.equal([...preview().querySelectorAll('h3')].some(node => ['Requirements', 'Question description'].includes(node.textContent)), false, 'Preview has no empty requirements or question-description section');
  assert.equal(preview().querySelector('.problem-expected-output'), null);
  await api.act(async () => document.querySelector('[aria-controls="database-panel-solution"]').click());
  await api.act(async () => api.change(document.querySelector('textarea[aria-label="Solution 1 SQL"]'), "SELECT 7 AS n"));
  await api.act(async () => runButton().click());
  assert.equal(preview().querySelector('.problem-expected-output h3').textContent, "Expected output");
  assert.equal(preview().querySelector('.problem-expected-output td').textContent, "7");
  assert.ok(preview().querySelector('.problem-question-content .problem-expected-output'), 'Expected output belongs to the selected question');
  assert.ok(!preview().textContent.includes("SELECT 7"), "Preview shows the output table rather than the reference query");
  assert.equal(document.querySelector('.teacher-problem-content-section .problem-expected-output'),null,'The authoring statement does not duplicate query output');
  assert.ok(document.querySelector('section#problem-statement > .teacher-section-title'), 'Content navigation targets the section heading, not the text editor');
  await api.act(async () => api.change(document.querySelector('textarea[aria-label="Solution 1 SQL"]'), "SELECT 10 AS n"));
  assert.equal(preview().querySelector('.problem-expected-output'), null, "Editing SQL must invalidate the previous expected output");

  await tableTab();
  await api.act(async()=>api.change(document.querySelector('textarea[aria-label="Database structure SQL"]'),'CREATE TABLE T (id INT PRIMARY KEY);'));
  await dataTab();
  await api.act(async () => api.change(document.querySelector('textarea[aria-label="Test dataset 1 SQL"]'), "INSERT INTO T (id) VALUES (100);"));
  await api.act(async () => [...document.querySelectorAll('button')].find(button => button.textContent === 'Add dataset').click());
  await api.act(async () => api.change(document.querySelector('textarea[aria-label="Test dataset 2 SQL"]'), "INSERT INTO T (id) VALUES (900);"));
  await api.act(async () => document.querySelector('[aria-controls="database-panel-solution"]').click());
  await api.act(async () => runButton().click());
  assert.equal(preview().querySelectorAll('.problem-expected-sample').length, 2, 'Source datasets are visible by default; activity settings control dataset visibility');
  assert.equal(document.querySelector('.teacher-result-hidden-toggle'), null, 'Dataset hide controls belong to activity Data Import settings');
  await api.act(async () => [...document.querySelectorAll('.teacher-preview-tabs button')].find(button => button.textContent === 'Database').click());
  assert.ok(preview().textContent.includes('100'), 'Source Preview uses the first visible dataset');
  await dataTab();
  assert.equal(document.querySelector('.teacher-dataset-hidden-toggle'), null, 'Source Data Import has no dataset visibility buttons');
  await api.act(async () => document.querySelector('[aria-controls="database-panel-solution"]').click());
  await api.act(async () => [...document.querySelectorAll('button')].find(button => button.textContent.includes('Add question')).click());
  await api.act(async () => api.change(document.querySelectorAll('.database-description-question-body textarea')[1], 'Count all products.'));
  await api.act(async () => api.change(document.querySelector('textarea[aria-label="Solution 2 SQL"]'), 'SELECT 42 AS n'));
  const preparation = 'SELECT * INTO T1 FROM T;';
  await api.act(async () => api.change(document.querySelectorAll('.teacher-stage-setup textarea')[1], preparation));
  globalThis.__solutionRun = async () => ({ status:'Success', executionTimeMs:1, table:{name:'Result',columns:['n'],rows:[[42]]} });
  await api.act(async () => [...document.querySelectorAll('.teacher-exercise-question')][1].querySelector('.teacher-solution-run-row .primary').click());
  await api.act(async () => [...document.querySelectorAll('.teacher-preview-tabs button')].find(button => button.textContent === 'Description').click());
  const questionSelect = preview().querySelector('.workspace-question-picker select');
  const firstId = questionSelect.options[0].value;
  const secondId = questionSelect.options[1].value;
  await api.act(async () => api.change(questionSelect, secondId));
  assert.ok(preview().textContent.includes('Count all products.'));
  assert.ok(preview().querySelector('.problem-active-question').textContent.includes('Count all products.'), 'Each SQL question keeps its own description');
  assert.equal([...preview().querySelectorAll('h3')].some(node => node.textContent === 'Requirements'), false);
  assert.equal(preview().querySelector('.problem-expected-output td').textContent,'42');
  await api.act(async () => api.change(questionSelect, firstId));
  assert.equal(preview().querySelector('.problem-expected-output td').textContent,'7','Running the second question must preserve the first output');
  assert.equal(document.querySelector('.teacher-problem-hints-section'), null, 'Hint authoring is removed');
  assert.equal(document.querySelector('.teacher-student-preview-hint, .teacher-student-preview-dock'), null, 'Preview has no hint controls');
  assert.ok([...document.querySelectorAll('.teacher-problem-database-tabs button')].every(button=>button.dataset.tooltip));
  await tableTab();
  assert.equal(document.querySelector('.teacher-problem-database-heading .teacher-database-import-button')?.textContent.trim(), 'Import library');
  assert.equal(document.querySelector('[role="tablist"] .teacher-database-import-button'), null, 'The import action is separate from the DDL/DML/DQL tabs');
  // Check Table Design and save the actual New problem form into Edit.
  await tableTab();
  assert.equal(document.querySelector('.teacher-schema-heading .teacher-database-import-button'), null);
  assert.equal(document.body.textContent.includes('Provided database structure'), false);
  assert.equal(document.body.textContent.includes('This database structure is set up for students before they begin.'), false);
  await api.act(async () => api.change(document.querySelector('textarea[aria-label="Database structure SQL"]'), 'CREATE TABLE T (id INT PRIMARY KEY);'));
  assert.equal(document.querySelector('.database-description-row input'),null,'Duplicate database predicate form remains retired');
  await api.act(async()=>document.querySelector('.teacher-schema-run-row button').click());
  assert.ok(document.querySelector('.teacher-schema-diagram-panel'), 'Table Design previews the schema');
  await api.act(async () => document.querySelector('[aria-controls="database-panel-solution"]').click());
  await api.act(async () => document.querySelectorAll('.teacher-exercise-question')[1].querySelector('.teacher-solution-run-row .primary').click());
  await dataTab();
  await api.act(async () => api.change(document.querySelector('input[aria-label="Dataset 1 file name"]'), 'students_seed'));
  await api.act(async () => api.change(document.querySelectorAll('#basic-information input')[1], 'Saved flow test'));
  let finishSave;
  globalThis.__holdSave = new Promise(resolve => { finishSave = resolve; });
  await api.act(async () => document.querySelector('.teacher-problem-save-exit-actions .primary').click());
  const unlinkedSaveBtn = document.querySelector('.teacher-db-save-prompt-dialog .teacher-dialog-actions button:nth-child(2)');
  if (unlinkedSaveBtn) await api.act(async () => unlinkedSaveBtn.click());
  assert.equal(document.querySelector('.u070-t:not(.leave)').dataset.variant, 'loading', 'New problem waits for the server');
  await api.act(async () => finishSave());
  assert.equal(document.querySelector('.u070-t:not(.leave)').dataset.variant, 'success', 'Save toast survives New to Edit navigation');
  assert.equal(document.querySelector('.teacher-problem-toolbar-title').textContent.includes('All changes saved'), false);
  assert.deepEqual(globalThis.__savedPayload.databaseDescription, [], 'Retired duplicate database predicate form stays absent');
  assert.equal(Object.hasOwn(globalThis.__savedPayload, 'difficulty'), false, 'Saving no longer sends a hidden difficulty setting');
  assert.equal(Object.hasOwn(globalThis.__savedPayload, 'requirements'), false, 'Saving no longer sends a hidden top-level requirements setting');
  assert.equal(globalThis.__savedPayload.statement, 'Expanded statement content');
  assert.equal(globalThis.__savedPayload.sqlSolutions[1].description, 'Count all products.', 'Per-question descriptions persist independently');
  assert.equal(globalThis.__savedPayload.sqlSolutions[1].setupSql, preparation, 'DQL preparation persists in the actual problem form');
  assert.equal(globalThis.__savedPayload.testCases.length, 2);
  assert.equal(globalThis.__savedPayload.sqlSolutions.length, 2);
  assert.equal(globalThis.__savedPayload.sqlSolutions[1].expectedOutputs[0].rows[0][0], 42);
  assert.equal(localStorage.getItem('teacher-dataset-names:new'), null, 'Saved dataset names must not leak to the next New problem');
  await dataTab();
  assert.equal(document.querySelector('input[aria-label="Dataset 1 file name"]').value, 'students_seed', 'Dataset names must survive New to Edit navigation');
  await api.act(async () => document.querySelector('[aria-controls="database-panel-solution"]').click());
  assert.equal(document.querySelectorAll('.database-description-question-body textarea')[1].value, 'Count all products.', 'Question descriptions restore in Edit');
  assert.equal(document.querySelector('textarea[aria-label="Solution 2 SQL"]').value, 'SELECT 42 AS n');
  assert.equal(document.querySelectorAll('.teacher-stage-setup textarea')[1].value, preparation, 'DQL preparation restores in Edit');
  globalThis.__updateError = true;
  await api.act(async () => document.querySelector('.teacher-problem-save-exit-actions .primary').click());
  assert.equal(document.querySelector('.u070-t:not(.leave)').dataset.variant, 'error');
  await api.act(async () => api.change(document.querySelectorAll('#basic-information input')[1], 'Latest edited title'));
  globalThis.__updateError = false;
  await api.act(async () => document.querySelector('.u070-act').click());
  assert.equal(globalThis.__savedPayload.title, 'Latest edited title', 'Retry must save current form values');
  assert.equal(document.querySelector('.u070-t:not(.leave)').dataset.variant, 'success');
  console.log("PASS: automatic expected output, failed/stale-run protection, live Preview, and dataset visibility controls moved to activity settings.");
} finally {
  if (root) await api.act(async () => root.unmount());
  await unlink(filename).catch(() => {});
  delete globalThis.__solutionRun;
  dom.window.close();
}
