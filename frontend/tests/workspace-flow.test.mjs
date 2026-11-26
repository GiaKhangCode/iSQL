import assert from "node:assert/strict";
import { build } from "esbuild";
import { JSDOM } from "jsdom";
import { writeFile, unlink } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { createRequire } from "node:module";

const dom = new JSDOM('<!doctype html><html><body><div id="root"></div></body></html>', { url: "http://localhost/" });
for (const name of ["window", "document", "navigator", "HTMLElement", "Element", "MutationObserver", "localStorage"]) Object.defineProperty(globalThis, name, { configurable: true, value: dom.window[name] });
globalThis.IS_REACT_ACT_ENVIRONMENT = true;
globalThis.ResizeObserver = class { observe() {} disconnect() {} };
globalThis.__workspaceApi = { getDraft: () => "", saveDraft() {}, getPreferences: async () => ({ favorites: [] }), getExamSessionOrExam: async () => undefined, getCurrentDatabase: async () => ({ tables: [] }) };

const output = await build({
  stdin: { contents: `
    export { createElement, act } from "react";
    export { createRoot } from "react-dom/client";
    export { MemoryRouter } from "react-router-dom";
    export { ProblemWorkspace } from "./src/features/workspace/ProblemWorkspace";
    export { SqlWorkspace, ExamSessionSqlWorkspace } from "./src/features/workspace/SqlWorkspace";
    export { WorkspaceSubmissionPanel } from "./src/features/workspace/components/WorkspaceSubmissionPanel";
  `, resolveDir: process.cwd(), loader: "tsx" },
  bundle: true, write: false, platform: "node", format: "cjs", jsx: "automatic",
  loader: { ".css": "empty" }, define: { "process.env.NODE_ENV": '"development"', "import.meta.hot": "undefined" },
  plugins: [{ name: "workspace-test-adapters", setup(builder) {
    const stub = (filter, contents) => builder.onLoad({ filter }, () => ({ contents, loader: "tsx", resolveDir: process.cwd() }));
    stub(/components[\\/]AppHeader\.tsx$/, 'export const AppHeader = ({workspaceActions,workspaceProblemNavigation}) => <header>{workspaceProblemNavigation}{workspaceActions}</header>;');
    stub(/(?:context|providers)[\\/]AuthContext\.tsx$/, 'export const useAuth = () => ({session:{id:"student", name:"Student"}});');
    stub(/(?:services|api)[\\/]studentApi\.ts$/, 'export const studentApi = globalThis.__workspaceApi;');
    stub(/@uiw[\\/]react-codemirror[\\/].*index\.js$/, 'export default function CodeMirror({value,onChange,onUpdate,...props}) { const update = node => { const text=node.value,head=node.selectionEnd,from=text.lastIndexOf("\\n",head-1)+1; onUpdate?.({state:{selection:{main:{from:node.selectionStart,to:node.selectionEnd,head}},doc:{lineAt:()=>({number:text.slice(0,head).split("\\n").length,from})},sliceDoc:(a,b)=>text.slice(a,b)}}); }; return <textarea aria-label={props["aria-label"]} value={value} onInput={e=>{onChange(e.currentTarget.value);update(e.currentTarget);}} onMouseUp={e=>update(e.currentTarget)} />; }');
  }}],
});
const filename = join(tmpdir(), `sqluit-workspace-flow-${process.pid}.cjs`);
await writeFile(filename, output.outputFiles[0].contents);
try {
  const { createElement: h, act, createRoot, MemoryRouter, ProblemWorkspace, SqlWorkspace, ExamSessionSqlWorkspace, WorkspaceSubmissionPanel } = createRequire(import.meta.url)(filename);
  const root = createRoot(document.getElementById("root"));
  const problem = { id:"p1", number:"1", title:"Customer directory", workspaceParts:["DDL","DML","SQL"], description:"Find customers", requirements:"Legacy top-level requirements must stay hidden", schema:"CREATE TABLE Customers(id INT PRIMARY KEY)", seedData:"INSERT INTO Customers VALUES (91)", tables:[], draft:"", topic:"SELECT", difficulty:"Easy", progress:"Not started", hint:"Legacy hint should stay hidden", expected:{name:"Expected",columns:["id"],rows:[[314]]} };
  const schemaTables = [{name:"Customers",columns:[{name:"id",type:"int",constraints:"NOT NULL"}],primaryKeys:["id"],foreignKeys:[],constraints:[]}];
  const success = {status:"Success",message:"Executed successfully",schemaTables,executionTimeMs:4.25};
  let complete, lastRun;
  globalThis.__workspaceApi.runWorkspace = (_id,input) => new Promise(resolve => { lastRun=input; complete = resolve; });
  const stage = () => document.querySelector('.problem-workspace-stage:not([hidden])');
  const nav = part => document.querySelector(`header nav button[aria-controls="workspace-stage-${part}"]`);
  const click = async button => { assert.ok(button, "Button must exist"); assert.equal(button.disabled,false); await act(async()=>{ if(button.tagName==='OPTION'){button.parentElement.value=button.value;button.parentElement.dispatchEvent(new dom.window.Event('change',{bubbles:true}));}else button.click(); }); };
  const edit = async value => { await act(async()=>{ const input=stage().querySelector('textarea'); input.value=value; input.dispatchEvent(new dom.window.Event('input',{bubbles:true})); }); };
  const select = async (from,to) => { await act(async()=>{const input=stage().querySelector('textarea');input.setSelectionRange(from,to);input.dispatchEvent(new dom.window.MouseEvent('mouseup',{bubbles:true}));}); };
  const run = () => click(document.querySelector('.stage-run-actions .primary'));
  await act(async()=>root.render(h(MemoryRouter, null, h(ProblemWorkspace,{problem}))));
  assert.deepEqual([...document.querySelectorAll('header nav button')].map(button=>button.textContent), ['DDL','DML','DQL']);
  assert.equal(document.querySelector('header .workspace-solution-switcher, .layout-trigger, .workspace-timer'),null,'The removed layout chooser and timer stay absent');
  assert.equal(stage().querySelector('[role=tab][aria-selected=true]').textContent, 'Mô tả');
  const paneTabs = () => [...stage().querySelectorAll('.workspace-pane-tabs [role="tab"]')];
  await click(paneTabs()[0]);
  assert.ok(stage().textContent.includes('Find customers'), 'The problem statement remains visible in DDL');
  assert.ok(!stage().textContent.includes(problem.requirements), 'DDL no longer renders top-level Requirements');
  await click(paneTabs()[1]);
  async function paneKey(key) { await act(async()=>stage().querySelector('[role="tab"][aria-selected="true"]').dispatchEvent(new dom.window.KeyboardEvent('keydown',{key,bubbles:true}))); }
  await paneKey('ArrowLeft');
  assert.equal(paneTabs()[0].getAttribute('aria-selected'),'true');
  assert.equal(document.activeElement,paneTabs()[0],'Arrow keys must move keyboard focus with the selected pill');
  assert.equal(stage().querySelector('[role="tabpanel"]').getAttribute('aria-labelledby'),paneTabs()[0].id);
  await paneKey('End');
  assert.equal(paneTabs()[2].getAttribute('aria-selected'),'true');
  await paneKey('ArrowRight');
  assert.equal(paneTabs()[0].getAttribute('aria-selected'),'true','Arrow keys wrap at the last tab');
  await paneKey('ArrowRight');
  assert.equal(paneTabs().filter(tab=>tab.tabIndex===0).length,1,'Only the selected tab belongs in the Tab sequence');

  assert.ok(stage().querySelector('.workspace-database-table').textContent.includes('91'), 'DDL starts with the original instructor data');
  assert.equal(stage().querySelector('.problem-expected-output'), null, 'DDL must not show the DQL answer');
  await click(nav('DML'));
  await click(paneTabs()[0]);
  assert.ok(!stage().textContent.includes(problem.requirements), 'DML no longer renders top-level Requirements');
  await click(paneTabs()[1]);
  assert.ok(stage().querySelector('.workspace-database-table').textContent.includes('91'), 'DML is available with instructor data before any DDL run');
  assert.equal(stage().id,'workspace-stage-DML','DML must be available before running DDL');
  await click(nav('SQL'));
  assert.equal(stage().id,'workspace-stage-SQL','DQL must be available before running DDL');
  await click(nav('DDL'));
  assert.equal(document.querySelectorAll('header .workspace-tools').length,0,'The top bar no longer contains layout tools or the self-tracking timer');
  assert.equal(document.querySelector('.editor-toolbar .workspace-tools'),null);
  assert.equal(document.querySelectorAll('.cursor-position').length,0,'The editor no longer shows the cursor position');
  assert.equal(stage().querySelector('.problem-scroll > .tiny'),null,'Remove the redundant PROBLEM number');
  const chosenDdl='CREATE TABLE Customers(id INT PRIMARY KEY)';
  await edit(chosenDdl+';\nCREATE TABLE Ignored(id INT)');
  await select(0,chosenDdl.length);
  await click(document.querySelector('.stage-run-actions button:first-child'));
  assert.equal(lastRun.ddl,chosenDdl,'Run selected must send only the highlighted DDL');
  assert.ok(stage().querySelector('textarea').value.includes('Ignored'),'Running a selection must preserve the complete draft');
  await act(async()=>complete({...success,tables:[]}));
  await edit(chosenDdl);
  await run();
  await act(async()=>complete({...success,tables:[]}));
  assert.ok(stage().querySelector('.workspace-output.is-open'), 'Running opens the output panel under the editor');
  assert.deepEqual([...stage().querySelectorAll('.workspace-output [role="tab"]')].map(node=>node.textContent),['Thông báo'],'DDL output has only the Messages tab, like SSMS');
  assert.ok(stage().querySelector('.workspace-output-messages').textContent.includes('Lệnh đã chạy thành công.'),'A DDL run without row counts reports success like SSMS');
  assert.ok(!stage().querySelector('.workspace-output').textContent.includes('314'), 'DDL output never shows the DQL answer');
  assert.equal(document.querySelector('.stage-output'),null,"There must be no bottom result pane");
  await edit('CREATE TABLE Customers(id INT PRIMARY KEY, name NVARCHAR(50))');
  assert.equal(nav('DML').disabled,false,"Editing DDL must keep other sections available");
  await run();
  await act(async()=>complete({...success,tables:[]}));
  await click(nav('DML'));
  assert.equal(stage().id,'workspace-stage-DML');
  assert.equal(nav('DML').disabled,false);
  const chosenDml='INSERT INTO Customers(id) VALUES(1)';
  await edit(chosenDml+';\nDELETE FROM Customers');
  await select(0,chosenDml.length);
  await click(document.querySelector('.stage-run-actions button:first-child'));
  assert.equal(lastRun.dml,chosenDml,'Run selected must send only the highlighted DML');
  await act(async()=>complete({...success,tables:[]}));
  await edit(chosenDml);
  await run();
  await act(async()=>complete({...success,tables:[{name:'Customers',columns:['id'],rows:[[1]]}]}));
  assert.ok(!stage().querySelector('.workspace-output').textContent.includes('91'), 'The output never displays the target dataset');
  assert.equal(stage().querySelector('.workspace-output-grid'), null, 'DML output shows messages only, like SSMS');
  assert.ok(stage().querySelector('.workspace-output-messages'), 'DML run messages remain visible');
  assert.equal(lastRun.ddl, '', 'DML does not send the DDL draft');
  await click(stage().querySelectorAll('.pane-tabs button')[1]);
  const database=stage().querySelector('.workspace-database-content');
  assert.ok(database.textContent.includes('91'), 'Running DML must not mutate the reference database');
  assert.ok(database.querySelector('.teacher-schema-diagram-panel'));
  assert.ok(database.querySelector('.workspace-database-table'));
  assert.equal(database.querySelector('.workspace-execution-feedback'), null, "Successful execution must not add a banner above the diagram");
  assert.equal(database.querySelectorAll('.teacher-schema-diagram-action').length, 1, "The workspace diagram must offer fullscreen only");
  assert.ok(stage().querySelector('.workspace-output-time').textContent.includes('4'),'The run time is shown on the output bar, not under the editor');
  assert.equal(stage().querySelector('.stage-editor-actions'),null,'Run buttons live in the top bar, not under the editor');
  assert.ok(document.querySelector('.workspace-run-slot .stage-run-actions'),'Run buttons sit left of the timer in the top bar');
  await click(nav('DDL'));
  await edit('CREATE TABLE Customers(id INT)');
  assert.equal(nav('DML').disabled,false,"Editing DDL must not lock DML");
  await run();
  await act(async()=>complete({status:'Error',message:"('42S02', \"[42S02] [Microsoft][ODBC Driver 17 for SQL Server][SQL Server]Invalid object name 'w_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.Authors'. (208) (SQLExecDirectW)\")",executionTimeMs:1.5}));
  assert.ok(stage().querySelector('[role="alert"]').textContent.includes('Không tìm thấy bảng Authors'));
  assert.ok(!stage().querySelector('[role="alert"]').textContent.includes('ODBC'));
  assert.ok(!stage().querySelector('[role="alert"]').textContent.includes('w_aaaaaaaa'));

  await click(nav('DML'));
  assert.ok(stage().querySelector('.workspace-output-messages'), 'DML shows its latest run after failed DDL');
  assert.ok(!stage().querySelector('.workspace-output').textContent.includes('Authors'), 'DDL errors never appear in the DML output');
  await click(nav('SQL'));
  await act(async()=>root.render(h(MemoryRouter,null,h(SqlWorkspace,{problem:{...problem,workspaceParts:['SQL']}}))));
  assert.ok(document.querySelector('.problem-question-content'), 'DQL starts with the problem description');
  await click(document.querySelector('.pane-tabs button'));
  assert.ok(document.querySelector('.problem-expected-output').textContent.includes('314'), 'DQL keeps its own expected output in Description');
  assert.ok(!document.querySelector('.problem-scroll').textContent.includes(problem.requirements), 'DQL never falls back to top-level Requirements');
  assert.equal(document.querySelector('#workspace-hint-panel'), null);
  assert.equal(document.querySelector('#workspace-ai-panel'), null);
  assert.equal(document.querySelector('[aria-controls="workspace-hint-panel"], [aria-controls="workspace-ai-panel"]'), null);
  assert.ok(!document.body.textContent.includes('Legacy hint should stay hidden'));
  assert.ok(document.querySelector('.workspace-problem-actions .save-problem'), 'Saving a favorite remains available after removing help controls');
  globalThis.__workspaceApi.runQuery = async()=>({...success,table:{name:'Result',columns:['id'],rows:[[1]]}});
  globalThis.__workspaceApi.submitSolution = async()=>({...success,status:'Accepted',message:'All tests passed',score:25,maxScore:25,scoreVisible:true,submissionsUsed:1,maxSubmissions:1});
  await act(async()=>{const input=document.querySelector('.sql-editor textarea');input.value='SELECT 1';input.dispatchEvent(new dom.window.Event('input',{bubbles:true}));});
  await click(document.querySelectorAll('.stage-run-actions button')[1]);
  assert.equal(document.querySelector('#workspace-result-pane'),null,"SQL must not mount a bottom result panel");
  assert.ok(document.querySelector('.workspace-output-grid'),'A DQL run shows its rows in the Results tab');
  assert.equal(document.querySelector('.workspace-output a'),null,"The output panel must not include a history link");
  assert.ok(document.querySelector('.workspace-output-time').textContent.includes('4'),'The run time is shown on the output bar');
  await click(document.querySelector('.workspace-submit-current'));
  assert.equal(document.querySelectorAll('.workspace-reading-pane .workspace-attempt').length,1,"Submitted work is separate from the latest run (which stays in the output panel)");
  assert.ok(document.querySelector('.workspace-reading-pane .workspace-attempt').textContent.includes('25/25'), 'A graded submission must show its released score');
  assert.equal(document.querySelector('.workspace-submit-current').disabled, false, 'A submitted workspace should offer an explicit return to editing');
  assert.equal(document.querySelector('.workspace-submit-current').textContent.trim(), 'Sửa lại');
  const combined = {status:'Partial',message:'All combined feedback must not leak across tabs',scoreVisible:true,score:67,maxScore:100,sections:[
    {part:'DDL',status:'Wrong Answer',message:'DDL columns differ',actualSchema:schemaTables,expectedSchema:schemaTables},
    {part:'DML',status:'Accepted',message:'DML rows match',actualTables:[{name:'Customers',columns:['id'],rows:[[1]]}],expectedTables:[{name:'Customers',columns:['id'],rows:[[1]]}]},
    {part:'SQL',status:'Accepted',message:'DQL query matches',comparisons:[{dataset:1,actualTable:{name:'Result',columns:['id'],rows:[[1]]},expectedTable:{name:'Expected',columns:['id'],rows:[[1]]},matched:true}]},
  ]};
  for (const part of ['DDL','DML','SQL']) {
    await act(async()=>root.render(h(WorkspaceSubmissionPanel,{part,busy:false,notice:'',submissionsUsed:1,maxSubmissions:3,attempts:[{id:1,kind:'submit',result:combined},{id:2,kind:'run',part:'DDL',result:{status:'Error',message:'Only DDL run history'}}]})));
    assert.equal(document.querySelectorAll('.workspace-attempt').length,1,'Only submitted work is shown in the submission tab');
    assert.ok(document.body.textContent.includes('67/100'),'The combined score is shared across sections');
    assert.ok(!document.body.textContent.includes('All combined feedback'));
    assert.ok(document.body.textContent.includes(part==='DDL'?'DDL columns differ':part==='DML'?'DML rows match':'DQL query matches'));
    assert.equal(Boolean(document.querySelector('.workspace-schema-result')),part==='DDL');
    assert.equal(Boolean(document.querySelector('.workspace-query-comparison')),part==='SQL');
    assert.ok(document.querySelector('.workspace-result-comparison'),'Each section must show its own actual result');
    assert.equal(document.querySelector('.workspace-submission-content').textContent.includes('Kết quả mong đợi'),part==='SQL','Only DQL submissions show the expected result queried from the reference answer');
    assert.equal(document.querySelector('.workspace-result-comparison').children.length,part==='SQL'?2:1,'DQL shows the student result and the expected result; DDL/DML show the student result only');
  }
  const dqlQuestion={id:'q-message',name:'q.sql',ratio:0,status:'Runtime Error',message:"SQL execution error: Invalid column name 'unknown'.",datasets:[]};
  const dqlSaved={status:'Runtime Error',message:'Summary',questionId:'q-message',sections:[{part:'SQL',status:'Wrong Answer',message:'Aggregate summary',questions:[dqlQuestion]}]};
  const dqlPanel={part:'SQL',questionId:'q-message',questionGroups:{SQL:[{id:'q-message',name:'q.sql'}]},busy:false,notice:''};
  await act(async()=>root.render(h(WorkspaceSubmissionPanel,{...dqlPanel,attempts:[{id:10,kind:'submit',result:dqlSaved}]})));
  assert.ok(document.querySelector('.workspace-question-grade').textContent.includes('unknown'),'DQL runtime error message must survive an empty stage-datasets array');
  const matchedQuestion={...dqlQuestion,ratio:1,status:'Accepted',message:'Query matched.',comparisons:[{dataset:1,matched:true,actualTable:{name:'Result',columns:['id'],rows:[[75]]},expectedTable:{name:'PRIVATE EXPECTED',columns:['id'],rows:[[999]]}}]};
  await act(async()=>root.render(h(WorkspaceSubmissionPanel,{...dqlPanel,attempts:[{id:11,kind:'submit',result:{...dqlSaved,status:'Accepted',sections:[{part:'SQL',status:'Accepted',message:'Summary',questions:[matchedQuestion]}]}}]})));
  assert.ok(document.body.textContent.includes('Query matched.'));
  assert.ok(document.querySelector('.workspace-query-comparison').textContent.includes('75'),'Saved DQL renders its own comparison table');
  assert.ok(document.querySelector('.workspace-expected-result').textContent.includes('999'),'Saved DQL also shows the expected result returned by the server');
  await act(async()=>root.render(h(WorkspaceSubmissionPanel,{...dqlPanel,attempts:[{id:12,kind:'submit',result:{...dqlSaved,scoreVisible:false}}]})));
  assert.ok(!document.body.textContent.includes('unknown'),'Hidden grading does not leak question messages');
  await act(async()=>root.render(h(WorkspaceSubmissionPanel,{part:'SQL',busy:false,notice:'',attempts:[{id:1,kind:'submit',result:{...combined,scoreVisible:false}}]})));
  assert.ok(!document.body.textContent.includes('DQL query matches'));
  assert.equal(document.querySelector('.workspace-result-comparison'),null,'Hidden grading snapshots must not reach the UI');
  await act(async()=>root.render(h(WorkspaceSubmissionPanel,{busy:false,notice:'',submissionsUsed:1,maxSubmissions:3,attempts:[{id:1,kind:'submit',result:{status:'Submitted',message:'Saved',scoreVisible:false,score:null,maxScore:25}}]})));
  assert.ok(document.body.textContent.includes('Giáo viên đang ẩn điểm'));
  assert.ok(document.body.textContent.includes('1 / 3'), 'The submission counter must display used and allowed attempts');
  assert.ok(!document.body.textContent.includes('0/25'), 'A hidden score must not appear as zero');
  await act(async()=>root.render(h(WorkspaceSubmissionPanel,{busy:false,notice:'',attempts:[{id:1,kind:'submit',result:{status:'Pending review',message:'Saved',scoreVisible:true,score:null,maxScore:25}}]})));
  assert.ok(document.body.textContent.includes('Đang chờ chấm điểm'), 'Pending review must not look like a zero grade');
  const questions = [{id:'q1',name:'first.sql',description:'Return the first customer.',timeLimitMs:5000,expectedSamples:[{dataset:1,table:{name:'First',columns:['id'],rows:[[11]]}}]},
    {id:'q2',name:'second.sql',description:'Count the customers.',timeLimitMs:900,expectedSamples:[{dataset:1,table:{name:'Second',columns:['total'],rows:[[22]]}}]}];
  const multi = {...problem,id:'multi',workspaceParts:['SQL'],sqlChecks:questions};
  const mountMulti = () => root.render(h(MemoryRouter,{key:'multi-test'},h(SqlWorkspace,{problem:multi,key:'multi-workspace'})));
  await act(async()=>mountMulti());
  const questionTabs = () => [...document.querySelector('header .workspace-header-questions select').options];
  assert.equal(questionTabs().length,2);
  const headerSolution = () => document.querySelector('header .workspace-header-questions select');
  assert.ok(headerSolution(), 'The question selector belongs in the header');
  assert.equal(headerSolution().options.length, 2);
  assert.equal(headerSolution().value, 'q1');
  const chooseHeaderSolution = async id => act(async()=>{ const select=headerSolution(); select.value=id; select.dispatchEvent(new dom.window.Event('change',{bubbles:true})); });

  assert.ok(document.querySelector('.problem-scroll').textContent.includes('Return the first customer.'));
  await click(document.querySelector('.pane-tabs button'));
  assert.ok(document.querySelector('.problem-expected-output').textContent.includes('11'));
  assert.ok(document.querySelector('header .workspace-header-questions').textContent.includes('Câu hỏi'), 'SQL questions show their position beside the section badge');
  assert.ok(!document.querySelector('.problem-scroll').textContent.includes(problem.requirements), 'Question text is independent of obsolete Requirements');
  const editSql = async value => act(async()=>{const input=document.querySelector('.sql-editor textarea'); input.value=value;input.dispatchEvent(new dom.window.Event('input',{bubbles:true}));});
  await editSql('SELECT 11 AS id');
  await chooseHeaderSolution('q2');
  assert.equal(questionTabs()[1].selected, true);
  assert.ok(document.querySelector('.problem-scroll').textContent.includes('Count the customers.'));
  assert.ok(document.querySelector('.problem-expected-output').textContent.includes('22'));
  assert.equal(document.querySelector('.sql-editor textarea').value,'');
  await editSql('SELECT 22 AS total');
  await click(questionTabs()[0]);
  assert.equal(headerSolution().value,'q1', 'The header selector keeps the selected question');
  assert.equal(document.querySelector('.sql-editor textarea').value,'SELECT 11 AS id');
  let runArgs, submitted;
  globalThis.__workspaceApi.runQuery = async (...args) => {runArgs=args;return {...success,table:{name:'First',columns:['id'],rows:[[11]]}};};
  globalThis.__workspaceApi.submitSolution = async (...args) => {submitted=args;return {...success,status:'Accepted',passed:2,total:2};};
  await click(document.querySelectorAll('.stage-run-actions button')[1]);
  assert.equal(runArgs[5],'q1');
  assert.equal(runArgs[1],'SELECT 11 AS id');
  await click(document.querySelector('.workspace-submit-current'));
  assert.ok(submitted[1].includes('-- solution:q1\nSELECT 11 AS id'));
  assert.ok(submitted[1].includes('-- solution:q2\nSELECT 22 AS total'));
  await act(async()=>root.render(h('div')));
  globalThis.__workspaceApi.getDraft = () => submitted[1];
  await act(async()=>mountMulti());
  await click(document.querySelector('.workspace-pane-tabs [role="tab"]'));
  await click(questionTabs()[1]);
  assert.equal(document.querySelector('.sql-editor textarea').value,'SELECT 22 AS total','Reload preserves each question draft');

  await act(async()=>root.render(h('div')));
  await act(async()=>root.render(h(MemoryRouter,{key:'embedded-solutions'},h(ProblemWorkspace,{problem:{...multi,id:'multi-embedded',workspaceParts:['DDL','DML','SQL']}}))));
  assert.equal(stage().id,'workspace-stage-DDL');
  await click(nav('SQL'));
  await chooseHeaderSolution('q2');
  assert.equal(stage().id,'workspace-stage-SQL','Choosing a solution opens its SQL stage');
  assert.ok(stage().querySelector('.problem-scroll').textContent.includes('Count the customers.'));
  assert.equal(document.querySelector('header .workspace-header-questions select').value,'q2');
  await chooseHeaderSolution('q1');
  assert.equal(headerSolution().value,'q1');

  for (const visible of [true, false]) {
    await act(async()=>root.render(h(MemoryRouter,{key:'reference-' + visible},h(ProblemWorkspace,{problem:{...problem,id:'reference-' + visible,databaseStructureVisible:visible,schema: visible ? 'CREATE TABLE ReferenceCustomers (Id INT)' : ''}}))));
    await click(stage().querySelectorAll('.pane-tabs button')[1]);
    assert.equal(Boolean(stage().querySelector('.teacher-schema-diagram-panel')), visible, 'Teacher structure visibility must apply before any DDL run');
    if (visible) assert.ok(stage().querySelector('.problem-scroll').textContent.includes('ReferenceCustomers'));
  }

  await act(async()=>root.render(h(MemoryRouter,{key:'no-question-prompt'},h(SqlWorkspace,{problem:{...problem,id:'no-question-prompt',sqlChecks:[{id:'empty',name:'empty.sql',description:''}]}}))));
  await click(document.querySelector('.pane-tabs button'));
  assert.ok(!document.querySelector('.problem-scroll').textContent.includes(problem.requirements), 'An empty SQL question must not reveal legacy top-level Requirements');
  assert.ok(![...document.querySelectorAll('.problem-scroll h3')].some(node=>node.textContent==='Câu hỏi'), 'An empty prompt does not add a blank Question section');
  await act(async()=>root.render(h(ExamSessionSqlWorkspace,{state:{examSession:{problemDetails:[{id:'p1',title:'Customer directory',description:'Preserved exam session problem statement',requirements:problem.requirements}]},activeQuestionIndex:0,queryAnswers:{},queryRunning:false,queryResult:null,error:'',setShowFullscreenDiagram(){},setActiveQuestionIndex(){},setQueryAnswers(){},setQueryResult(){},handleRunQuery(){}}})));
  assert.ok(document.querySelector('.exam-session-prompt-pane').textContent.includes('Preserved exam session problem statement'));
  assert.ok(!document.querySelector('.exam-session-prompt-pane').textContent.includes(problem.requirements), 'Exam session problemDetails.requirements is obsolete problem metadata, not a SQL question description');

  const layoutKey='sql-practice:workspace:student:preferences:layouts';
  localStorage.setItem(layoutKey,JSON.stringify({layout:'Debug',sizes:{Default:{problemWidth:36,secondBoundary:72,editorHeight:58}}}));
  await act(async()=>root.render(h(MemoryRouter,{key:'fixed-layout'},h(ProblemWorkspace,{problem:{...problem,id:'fixed-layout'}}))));
  assert.equal(document.querySelector('.layout-debug'),null,'Previously saved layouts must not restore the removed layout choice');
  assert.equal(JSON.parse(localStorage.getItem(layoutKey)).layout,'Default');
  assert.equal(JSON.parse(localStorage.getItem(layoutKey)).sizes.Default.problemWidth,36,'Keep the saved reading pane width');
  assert.equal(document.querySelector('.workspace-timer, .layout-trigger'),null);
  await act(async()=>root.unmount());
  console.log('PASS: restored stage navigation and pill tabs, independent questions, shared data, submission history and simplified layout.');
} finally { await unlink(filename); dom.window.close(); }
// Browser-oriented dependencies keep background handles open in this isolated DOM process.
process.exit(0);
