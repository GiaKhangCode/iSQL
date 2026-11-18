import assert from 'node:assert/strict';
import { build } from 'esbuild';
import { JSDOM } from 'jsdom';
import { writeFile, unlink } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const dom=new JSDOM('<div id="root"></div>',{url:'http://localhost',pretendToBeVisual:true});
for (const key of ['window','document','HTMLElement','Element','Node','NodeFilter','DOMParser','localStorage','getComputedStyle']) globalThis[key]=dom.window[key];
dom.window.HTMLDialogElement.prototype.showModal=function(){this.open=true;};
dom.window.scrollTo=()=>{};
dom.window.matchMedia=()=>({matches:true,addEventListener(){},removeEventListener(){}});
globalThis.ResizeObserver=class{observe(){}disconnect(){}};
globalThis.requestAnimationFrame=dom.window.requestAnimationFrame.bind(dom.window);
globalThis.cancelAnimationFrame=dom.window.cancelAnimationFrame.bind(dom.window);
globalThis.IS_REACT_ACT_ENVIRONMENT=true;
const table=value=>({name:'Result',columns:['id'],rows:[[value]]});
const schema='CREATE TABLE T (id INT PRIMARY KEY);';
const testCases=[{seedData:'INSERT INTO T (id) VALUES (101);',isHidden:false},{seedData:'INSERT INTO T (id) VALUES (999);',isHidden:true}];
const stageQuestions=part=>[1,2].map(n=>({id:`${part}${n}`,name:`${part}${n}.sql`,description:`${part} task ${n}`,
  startFrom:n===2?'structure':part==='DDL'?'empty':'provided',answer:'-- SECRET ANSWER',setupDdl:'-- SECRET PREPARATION',setupDml:'',enabled:true}));
globalThis.__readingProblem={id:'reading',number:'006',title:'Sales database',description:'Shared context',databaseType:'SQL Server',schema,workspaceParts:['DDL','DML','SQL','TRIGGER'],
  databaseDescription:[{tableName:'T',attributes:'id',predicate:'Common table explanation'}],testCases,
  triggerExercise:{questions:stageQuestions('TRIGGER')},ddlExercise:{questions:stageQuestions('DDL')},dmlExercise:{questions:stageQuestions('DML')},
  sqlSolutions:[1,2].map(n=>({id:`SQL${n}`,name:`DQL${n}.sql`,query:'SELECT id FROM T;',description:`DQL task ${n}`,enabled:true,timeLimitMs:5000,expectedOutputVisible:n===1,expectedOutputs:[table(n*42),table(999)]}))};
const studentProblem={...globalThis.__readingProblem,topic:'SQL',difficulty:'Easy',progress:'Not started',tables:[],draft:'',seedData:testCases[0].seedData,
  triggerChecks:stageQuestions('TRIGGER').map(({id,name,description,startFrom})=>({id,name,description,startFrom,gradingMode:'exact'})),
  ddlChecks:stageQuestions('DDL').map(({id,name,description,startFrom})=>({id,name,description,startFrom})),
  dmlChecks:stageQuestions('DML').map(({id,name,description,startFrom})=>({id,name,description,startFrom})),
  sqlChecks:globalThis.__readingProblem.sqlSolutions.map(({id,name,description,timeLimitMs,expectedOutputVisible,expectedOutputs})=>({id,name,description,timeLimitMs,expectedSamples:expectedOutputVisible?[{dataset:1,table:expectedOutputs[0]}]:[]}))};
globalThis.__studentReadingProblem=studentProblem;
studentProblem.sqlChecks[1].description=''; // Older problems put the question in the common statement.
const bundle=await build({
  stdin:{contents:`
    import React,{act} from 'react'; import {createRoot} from 'react-dom/client'; import {Simulate} from 'react-dom/test-utils';
    import {MemoryRouter,Routes,Route} from 'react-router-dom';
    import {ProblemEditorPage} from './src/features/teacher/ProblemEditorPage';
    import {ProblemWorkspace} from './src/features/workspace/ProblemWorkspace';
    import {ToastProvider} from './src/shared/ui/StackingToasts';
    export {act};
    export function mount(node){const root=createRoot(node); root.render(<ToastProvider><MemoryRouter initialEntries={['/teacher/problems/reading/edit']}><Routes><Route path='/teacher/problems/:problemId/edit' element={<ProblemEditorPage/>}/></Routes></MemoryRouter></ToastProvider>);return root;}
    export function student(root,key='main'){root.render(<MemoryRouter key={key}><ProblemWorkspace problem={globalThis.__studentReadingProblem}/></MemoryRouter>);}
    export function edit(node,value){node.value=value;Simulate.change(node);}
  `,resolveDir:process.cwd(),loader:'tsx'},
  bundle:true,write:false,platform:'node',format:'cjs',jsx:'automatic',loader:{'.css':'empty'},define:{'import.meta.hot':'undefined'},
  plugins:[{name:'reading-fixture',setup(builder){
    const stub=(filter,contents)=>builder.onLoad({filter},()=>({contents,loader:'tsx',resolveDir:process.cwd()}));
    stub(/[\\/]teacherService\.ts$/,'export const teacherService={getProblem:async()=>structuredClone(globalThis.__readingProblem),getDatabaseLibrary:async()=>[],validateStage:async request=>({schemaTables:request.question.startFrom==="empty"?[]:[{name:"T",columns:[{name:"id",type:"int",constraints:"PRIMARY KEY"}],primaryKeys:["id"],foreignKeys:[],constraints:[]}],tables:request.question.startFrom==="provided"?[{name:"T",columns:["id"],rows:[[101]]}]:[]})};');
    stub(/[\\/]studentApi\.ts$/,'export const studentApi={getSubmissions:async()=>[],getDraft:()=>"",saveDraft(){},getPreferences:async()=>({favorites:[]}),getExamSessions:async()=>[]};');
    stub(/components[\\/]AppHeader\.tsx$/,'export const AppHeader=({workspaceActions,workspaceProblemNavigation})=><header>{workspaceProblemNavigation}{workspaceActions}</header>;');
    stub(/context[\\/]AuthContext\.tsx$/,'export const useAuth=()=>({session:{id:"student"}});');
    stub(/[\\/]ProblemSqlEditor\.tsx$/,'export function ProblemSqlEditor({value,label,onChange}){return <textarea aria-label={label} value={value} onChange={e=>onChange(e.target.value)}/>;}');
    stub(/@uiw[\\/]react-codemirror[\\/].*index\.js$/,'export default function CodeMirror({value,onChange}){return <textarea value={value} onInput={e=>onChange?.(e.currentTarget.value)}/>;}');
  }}]
});
const filename=join(tmpdir(),`sqluit-reading-${process.pid}.cjs`);
let root,api;
try{
  await writeFile(filename,bundle.outputFiles[0].contents);
  api=createRequire(import.meta.url)(filename);
  const click=node=>api.act(async()=>{if(node.tagName==='OPTION'){node.parentElement.value=node.value;node.parentElement.dispatchEvent(new dom.window.Event('change',{bubbles:true}));}else node.click();});
  const choose=(node,value)=>api.act(async()=>api.edit(node,value));
  await api.act(async()=>{root=api.mount(document.getElementById('root'));});
  await click(document.querySelector('.teacher-problem-preview-action'));
  const preview=()=>document.querySelector('.teacher-student-preview-panel');
  const content=()=>preview().querySelector('.teacher-student-preview-content');
  const part=()=>preview().querySelector('.teacher-preview-part-picker');
  const tabs=()=>[...preview().querySelectorAll('.teacher-preview-tabs button')];
  const question=()=>content().querySelector('.workspace-question-picker select');
  assert.deepEqual([...part().options].map(option=>option.textContent),['DDL','DML','DQL','Trigger']);
  assert.equal(part().value,'DDL','Preview starts from the enabled section when editing structure');
  assert.ok(content().querySelector('.problem-active-question').textContent.includes('DDL task 1'));
  assert.equal(content().querySelector('.problem-question-context').open,false,'Common context stays available without displacing the question');
  assert.equal(content().querySelector('.problem-expected-output'),null,'DDL never displays DQL expected output');
  await click(tabs()[1]);
  assert.equal(content().querySelector('.database-schema-panel'),null,'Empty questions do not show pre-created tables');
  assert.equal(content().querySelector('.workspace-database-table'),null);
  await click(tabs()[0]);
  await choose(question(),'DDL2');
  await click(tabs()[1]);
  assert.ok(content().querySelector('.database-schema-list'));
  assert.equal(content().querySelector('.workspace-database-table'),null,'Structure-only questions start without rows');
  await choose(part(),'DML');
  assert.ok(content().querySelector('.workspace-database-table').textContent.includes('101'));
  assert.ok(!content().textContent.includes('999'),'Hidden dataset rows stay hidden');
  await click(tabs()[0]);
  assert.ok(content().querySelector('.problem-stage-guidance').textContent.includes('Mỗi câu độc lập'));
  assert.ok(!content().textContent.includes('SECRET'),'Teacher answer and preparation SQL do not enter student preview');
  await choose(question(),'DML2');
  await choose(part(),'SQL');
  assert.ok(content().querySelector('.problem-expected-output').textContent.includes('42'));
  assert.ok(!content().textContent.includes('999'));
  await choose(question(),'SQL2');
  assert.equal(content().querySelector('.problem-expected-output'),null,'Hidden expected output stays hidden');
  await choose(part(),'TRIGGER');
  assert.ok(content().textContent.includes('mã khớp đáp án'),'Trigger preview states exact-code policy');
  assert.ok(!content().textContent.includes('SECRET'),'Trigger reference and preparation remain private');
  await choose(part(),'DDL');assert.equal(question().value,'DDL2');
  await choose(part(),'DML');assert.equal(question().value,'DML2');

  await api.act(async()=>api.student(root));
  const active=()=>document.querySelector('.problem-workspace-stage:not([hidden])');
  const nav=section=>document.querySelector(`header nav button[aria-controls="workspace-stage-${section}"]`);
  const picker=()=>active().querySelector('.workspace-question-picker select');
  const code=()=>active().querySelector('.stage-code textarea,.sql-editor textarea');
  for(const section of ['DDL','DML','SQL','TRIGGER']){
    await click(nav(section));
    assert.ok(active().querySelector('.problem-question-content'),'All three workspaces use the preview reading component');
    assert.equal(document.querySelector('.workspace-header .workspace-solution-switcher'),null,'The top bar does not repeat the question selector');
    assert.equal(active().querySelector('.workspace-pane-tabs [role="tab"][aria-selected="true"]').textContent,'Mô tả');
    const firstId=picker().value;
    const previous=()=>active().querySelector('.problem-question-stepper button:first-child');
    const next=()=>active().querySelector('.problem-question-stepper button:last-child');
    assert.equal(previous().disabled,true,'The first question cannot navigate backwards');
    await api.act(async()=>{code().value=`-- ${section} first draft`;code().dispatchEvent(new dom.window.Event('input',{bubbles:true}));});
    await click(next());
    assert.equal(picker().value,section+'2','Next question stays in sync with the selector');
    assert.equal(next().disabled,true,'The last question cannot navigate forwards');
    assert.equal(code().value,'','Each question has an independent draft');
    if(section==='SQL') assert.ok(active().querySelector('.problem-active-question').textContent.includes('Shared context'),'Legacy questions use the common statement when their description is empty');
    await click(previous());
    assert.equal(picker().value,firstId);
    assert.equal(code().value,`-- ${section} first draft`,'Question changes retain the draft');
    const tabs=[...active().querySelectorAll('.pane-tabs [role="tab"],.u039-tabs [role="tab"]')];
    await click(tabs.find(tab=>tab.textContent==='Cơ sở dữ liệu'));
    assert.equal(active().querySelector('.workspace-reference-questions'),null,'Database panes do not repeat unrelated DQL questions');
    assert.equal(active().querySelector('.problem-database-description'),null,'Database panes contain visual tables, not repeated predicates');
  }
  globalThis.__studentReadingProblem={...studentProblem,
    ddlChecks:[{id:'__base_ddl__',name:'create_tables.sql',kind:'schema',startFrom:'empty',description:'Create all tables and declare their primary and foreign keys.'}],
    dmlChecks:[{id:'__base_dml__',name:'insert_data.sql',kind:'seed',startFrom:'structure',description:'Insert the original sample data into all tables.'}]};
  localStorage.clear(); // A fresh workspace starts on the generated CREATE question.
  await api.act(async()=>api.student(root,'defaults'));
  await click([...active().querySelectorAll('.workspace-pane-tabs [role="tab"]')].find(tab=>tab.textContent==='Mô tả'));
  assert.ok(active().querySelector('.problem-active-question').textContent.includes('khóa chính'));
  await click([...active().querySelectorAll('.workspace-pane-tabs [role="tab"]')].find(tab=>tab.textContent==='Cơ sở dữ liệu'));
  assert.ok(active().querySelector('.database-schema-list'),'The generated CREATE exercise has a visible target structure');
  assert.equal(active().querySelector('.workspace-database-data'),null,'CREATE reference does not mix in seed rows');
  await click(nav('DML'));
  assert.ok(active().querySelector('.stage-target-dataset .data-table').textContent.includes('101'),'The generated import exercise presents target rows visually');
  assert.ok(!active().querySelector('.stage-target-dataset').textContent.includes('999'),'Generated import exercises never show hidden target rows');
  assert.equal(active().querySelector('.stage-target-dataset textarea'),null,'The target shows data, without giving students the INSERT answer');
  console.log('PASS: three-section preview, independent drafts, hidden data and original-database CREATE/import exercise targets.');
}finally{
  if(root)await api.act(async()=>root.unmount());
  await unlink(filename).catch(()=>{});dom.window.close();
}
process.exit(0);
