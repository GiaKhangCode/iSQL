import assert from "node:assert/strict";
import { JSDOM } from "jsdom";
import { build } from "esbuild";
import { writeFile, unlink } from "node:fs/promises";
import { createRequire } from "node:module";
import { tmpdir } from "node:os";
import { join } from "node:path";

const dom = new JSDOM('<div id="root"></div>', {url:"http://localhost",pretendToBeVisual:true});
for(const key of ["window","document","HTMLElement","Element","Node","NodeFilter","DOMParser","localStorage","getComputedStyle"])globalThis[key]=dom.window[key];
dom.window.HTMLDialogElement.prototype.showModal=function(){this.open=true;};
dom.window.scrollTo=()=>{};
dom.window.confirm=()=>true;
dom.window.matchMedia=()=>({matches:true,addEventListener(){},removeEventListener(){}});
globalThis.ResizeObserver=class{observe(){}disconnect(){}};
globalThis.requestAnimationFrame=dom.window.requestAnimationFrame.bind(dom.window);
globalThis.cancelAnimationFrame=dom.window.cancelAnimationFrame.bind(dom.window);
globalThis.IS_REACT_ACT_ENVIRONMENT=true;
globalThis.__problem={id:"source",number:"001",title:"Database setup",description:"Statement",databaseType:"SQL Server",schema:"CREATE TABLE Customers (Id INT PRIMARY KEY);",databaseDescription:[{tableName:"Customers",attributes:"Id",predicate:"Customer relation explanation"}],testCases:[{seedData:"INSERT INTO Customers (Id) VALUES (10);",isHidden:false},{seedData:"INSERT INTO Customers (Id) VALUES (20);",isHidden:false}],sqlSolutions:[]};
globalThis.__libraryEntries=[{id:"library",title:"Customers bundle",createTableSql:"CREATE TABLE Customers (Id INT PRIMARY KEY);",createTableFileName:"customers.sql",datasets:[{fileName:"hidden.sql",sql:"INSERT INTO Customers VALUES(42);",isHidden:true}],bundle:{version:1,statement:"Library statement",ddlExercise:{questions:[]},dmlExercise:{questions:[{id:"lib-dml",name:"change_customer.sql",description:"Change ID",answer:"UPDATE Customers SET Id=43;",enabled:true,startFrom:"provided",setupDdl:"ALTER TABLE Customers ADD note VARCHAR(20);",setupDml:""}]},triggerExercise:{questions:[{id:"lib-trigger",name:"log.sql",description:"Create trigger",answer:"CREATE TRIGGER trg ON Customers AFTER INSERT AS SELECT 1;",enabled:true,startFrom:"provided",setupDdl:"",setupDml:""}]},sqlSolutions:[]}}];
const bundle=await build({
  stdin:{contents:`
    import React,{act} from 'react';
    import {createRoot} from 'react-dom/client';
    import {Simulate} from 'react-dom/test-utils';
    import {MemoryRouter,Routes,Route} from 'react-router-dom';
    import {ProblemEditorPage} from './src/features/teacher/ProblemEditorPage';
    import {ToastProvider} from './src/shared/ui/StackingToasts';
    export {act};
    export function mount(node){const root=createRoot(node);root.render(<ToastProvider><MemoryRouter initialEntries={['/teacher/problems/source/edit']}><Routes><Route path='/teacher/problems/:problemId/edit' element={<ProblemEditorPage/>}/></Routes></MemoryRouter></ToastProvider>);return root;}
    export function edit(node,value){node.value=value;Simulate.change(node);}
  `,resolveDir:process.cwd(),loader:"tsx"},
  bundle:true,write:false,platform:"node",format:"cjs",jsx:"automatic",loader:{".css":"empty"},
  define:{"import.meta.hot":"undefined"},
  plugins:[{name:"database-setup-test-adapters",setup(builder){
    builder.onLoad({filter:/[\\/]teacherService\.ts$/},()=>({contents:"export const teacherService={getProblem:async()=>structuredClone(globalThis.__problem),getDatabaseLibrary:async()=>structuredClone(globalThis.__libraryEntries),updateProblem:async(id,payload)=>{globalThis.__savedDatabaseProblem=structuredClone(payload);}};",loader:"js"}));
    builder.onLoad({filter:/[\\/]ProblemSqlEditor\.tsx$/},()=>({contents:"export function ProblemSqlEditor({value,label,onChange}){return <textarea aria-label={label} value={value} onChange={event=>onChange(event.target.value)}/>;}",loader:"jsx"}));
    builder.onLoad({filter:/[\\/]ProblemRichTextEditor\.tsx$/},()=>({contents:"export function ProblemRichTextEditor({value,label,onChange}){return <textarea aria-label={label} value={value} onChange={event=>onChange(event.target.value)}/>;}",loader:"jsx"}));
  }}],
});
const filename=join(tmpdir(),`sqluit-database-setup-${process.pid}.cjs`);
await writeFile(filename,bundle.outputFiles[0].contents);
let root;
try{
  const api=createRequire(import.meta.url)(filename);
  const click=node=>api.act(async()=>node.click());
  const mainTab=name=>document.querySelector(`.teacher-problem-database-tabs [aria-controls="database-panel-${name}"]`);
  const subTab=name=>document.getElementById(`database-setup-tab-${name}`);
  const seeds=()=>[...document.querySelectorAll('.teacher-dataset-card textarea')];
  await api.act(async()=>{root=api.mount(document.getElementById('root'));});
  assert.equal(subTab('tables').textContent,'Cấu trúc bảng');
  assert.equal(subTab('data').textContent,'Dữ liệu ban đầu');
  assert.equal(subTab('tables').getAttribute('aria-selected'),'true');
  assert.equal(seeds().length,0);
  assert.equal(document.querySelector('.teacher-problem-database-toolbar .teacher-database-import-button'),null);
  assert.ok(document.querySelector('.teacher-problem-database-heading .teacher-database-import-button'),'Import is in the database heading');
  await click(subTab('data'));
  assert.equal(seeds().length,2,'Existing seed datasets now live in the initial-data tab');
  assert.equal(document.querySelectorAll('fieldset.teacher-dataset-card > legend').length,2,'Each seed dataset has a fieldset with its title on the border');
  assert.equal(document.querySelectorAll('fieldset.teacher-dataset-card > .teacher-dataset-delete').length,2,'Delete buttons sit in the corner of each card when multiple datasets exist');
  assert.equal(seeds()[1].value,globalThis.__problem.testCases[1].seedData);
  assert.ok(document.querySelector('.teacher-database-setup-indicator').style.transform.includes('100%'));
  await api.act(async()=>api.edit(seeds()[0],'INSERT INTO Customers (Id) VALUES (101);'));
  await click(document.querySelector('.teacher-dataset-add'));
  assert.equal(seeds().length,3);
  await api.act(async()=>api.edit(seeds()[2],'INSERT INTO Customers (Id) VALUES (303);'));
  await click(document.querySelector('.teacher-dataset-preview-toggle'));
  assert.ok(document.querySelector('.teacher-dataset-preview').textContent.includes('101'));

  await click(mainTab('datasets'));
  assert.equal(document.querySelector('.teacher-dataset-list'),null,'DML only contains its exercises');
  assert.ok(document.querySelector('#database-dml-questions .teacher-exercise-question'));
  await click(mainTab('structure'));
  assert.equal(subTab('data').getAttribute('aria-selected'),'true','Returning to setup remembers the selected subtab');
  assert.ok(seeds()[0].value.includes('101'));
  assert.ok(seeds()[2].value.includes('303'));
  await api.act(async()=>subTab('data').dispatchEvent(new dom.window.KeyboardEvent('keydown',{key:'ArrowLeft',bubbles:true})));
  assert.equal(subTab('tables').getAttribute('aria-selected'),'true');
  assert.equal(document.activeElement,subTab('tables'));
  const schema=document.querySelector('textarea[aria-label="SQL cấu trúc cơ sở dữ liệu"]');
  assert.equal(schema.value,globalThis.__problem.schema);
  await api.act(async()=>api.edit(schema,'CREATE TABLE Customers (Id INT PRIMARY KEY, Name VARCHAR(40));'));
  await click(subTab('data'));
  assert.equal(seeds().length,3);
  await click(document.querySelector('.teacher-problem-preview-action'));
  await click(document.querySelectorAll('.teacher-preview-tabs button')[1]);
  const preview=document.querySelector('.teacher-student-preview-database');
  assert.ok(preview.querySelector('.database-schema-list'));
  assert.ok(preview.querySelector('.workspace-database-table').textContent.includes('101'));
  assert.equal(preview.querySelector('.problem-database-description'),null,'Database preview contains no duplicate relation explanation');
  await click(document.querySelector('.teacher-problem-save-exit-actions .primary'));
  assert.equal(globalThis.__savedDatabaseProblem.testCases.length,3);
  assert.ok(globalThis.__savedDatabaseProblem.testCases[0].seedData.includes('101'));
  assert.ok(globalThis.__savedDatabaseProblem.testCases[2].seedData.includes('303'));
  assert.ok(globalThis.__savedDatabaseProblem.schema.includes('Name VARCHAR(40)'));
  await api.act(async()=>api.edit(document.querySelector('.teacher-dataset-card legend input'),'customers_original'));
  assert.equal(document.querySelector('.teacher-dataset-card .teacher-sql-filename-measure').textContent,'customers_original');
  await click(document.querySelectorAll('.teacher-dataset-card > .teacher-dataset-delete')[1]);
  assert.equal(seeds().length,2);
  assert.ok(seeds()[1].value.includes('303'),'Deleting a dataset retains the other SQL inputs');
  assert.equal(document.querySelector('.teacher-dataset-card legend input').value,'customers_original');
  await click(document.querySelector('.teacher-problem-save-exit-actions .primary'));
  assert.equal(globalThis.__savedDatabaseProblem.testCases.length,2);
  assert.equal(JSON.parse(localStorage.getItem('teacher-dataset-names:source'))[0],'customers_original.sql');
  await click(document.querySelector('.teacher-dataset-card > .teacher-dataset-delete'));
  assert.equal(seeds().length,1);
  assert.equal(document.querySelector('.teacher-dataset-delete'),null,'The last remaining dataset is retained');
  for (const [tab,name] of [['schema','create_products'],['datasets','insert_products'],['solution','query_products']]) {
    await click(mainTab(tab));
    const filename=document.querySelector('.teacher-exercise-question .teacher-question-header input');
    assert.ok(filename,'DDL, DML and DQL questions all expose editable file names');
    await api.act(async()=>api.edit(filename,`${name}_long_version.sql`));
    assert.equal(filename.value,`${name}_long_version`,'Pasting a full filename never duplicates the extension');
    assert.equal(filename.closest('.teacher-sql-filename').querySelector('.teacher-sql-filename-measure').textContent,`${name}_long_version`);
    await api.act(async()=>api.edit(filename,name));
    assert.equal(filename.closest('.teacher-sql-filename').querySelector('.teacher-sql-filename-measure').textContent,name,'The filename also shrinks when a shorter name is entered');
    assert.equal(filename.closest('.teacher-sql-filename').querySelector('.teacher-sql-filename-extension').textContent,'.sql');
    const toggle=document.querySelector('.teacher-question-toggle');
    const body=document.getElementById(toggle.getAttribute('aria-controls'));
    const editor=body.querySelector('textarea');
    await click(toggle);
    assert.equal(body.hidden,true,`${tab} questions can be collapsed individually`);
    const description=document.querySelector('.teacher-question-description-editor textarea');
    assert.ok(description,`${tab} keeps the description outside the collapsible SQL area`);
    assert.equal(description.closest('.teacher-question-description-editor').getAttribute('aria-hidden'),'true');
    assert.equal(filename.value,name,'Collapsing does not change the filename');
    await click(toggle);
    assert.equal(body.hidden,false);
    assert.equal(body.querySelector('textarea'),editor,`${tab} keeps its editor when reopened`);
  }
  await click(document.querySelector('.teacher-problem-save-exit-actions .primary'));
  assert.equal(globalThis.__savedDatabaseProblem.ddlExercise.questions[0].name,'create_products.sql');
  assert.equal(globalThis.__savedDatabaseProblem.dmlExercise.questions[0].name,'insert_products.sql');
  assert.equal(globalThis.__savedDatabaseProblem.sqlSolutions[0].name,'query_products.sql');
  await click(mainTab('structure'));
  await click(document.querySelector('.teacher-problem-database-heading .teacher-database-import-button'));
  await click(document.querySelector('.teacher-database-import-preview-button'));
  assert.equal(document.querySelectorAll('.library-import-sections input:checked').length,2,'Old default imports structure and data only');
  for(const name of ['DML','Trigger']) {
    const choice=[...document.querySelectorAll('.library-import-sections label')].find(label=>label.querySelector('span').textContent===name);
    await click(choice.querySelector('input'));
  }
  await api.act(async()=>api.edit(document.querySelector('.library-import-mode select'),'replace'));
  await click(document.querySelector('.teacher-database-import-dialog-actions .primary'));
  assert.equal(document.querySelector('.teacher-database-import-content'),null);
  await click(document.querySelector('.teacher-problem-save-exit-actions .primary'));
  const imported=globalThis.__savedDatabaseProblem;
  assert.equal(imported.ddlExercise.questions[0].name,'create_products.sql','Unselected DDL group survives import');
  assert.equal(imported.sqlSolutions[0].name,'query_products.sql','Unselected DQL group survives import');
  assert.equal(imported.dmlExercise.questions.length,1);
  assert.equal(imported.dmlExercise.questions[0].answer,'UPDATE Customers SET Id=43;');
  assert.notEqual(imported.dmlExercise.questions[0].id,'lib-dml');
  assert.equal(imported.dmlExercise.questions[0].setupDdl,'ALTER TABLE Customers ADD note VARCHAR(20);');
  assert.notEqual(imported.triggerExercise.questions[0].id,'lib-trigger');
  assert.ok(imported.workspaceParts.includes('TRIGGER'));
  assert.equal(imported.testCases[0].isHidden,true);
  console.log('PASS: setup subtabs and indicator, seed fieldsets, moved import, add/rename/delete, preserved SQL, keyboard navigation, preview and saved edits.');
}finally{
  if(root)await createRequire(import.meta.url)(filename).act(async()=>root.unmount());
  await unlink(filename);delete globalThis.__problem;delete globalThis.__savedDatabaseProblem;dom.window.close();
}
process.exit(0);
