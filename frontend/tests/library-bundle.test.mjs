import assert from 'node:assert/strict';
import {build} from 'esbuild';
import {JSDOM} from 'jsdom';
import {writeFile,unlink} from 'node:fs/promises';
import {createRequire} from 'node:module';
import {join} from 'node:path';
import {tmpdir} from 'node:os';

const dom=new JSDOM('<div id="root"></div>',{url:'http://localhost/teacher/database-library',pretendToBeVisual:true});
for(const key of ['window','document','HTMLElement','Element','Node','localStorage','MutationObserver'])globalThis[key]=dom.window[key];
globalThis.IS_REACT_ACT_ENVIRONMENT=true;
window.confirm=()=>true;
window.matchMedia=()=>({matches:true,addEventListener(){},removeEventListener(){}});
globalThis.ResizeObserver=class{observe(){}disconnect(){}};
window.scrollTo=()=>{};
const originalBounds=HTMLElement.prototype.getBoundingClientRect;
HTMLElement.prototype.getBoundingClientRect=function(){
 if(this.matches('.teacher-library-section-nav, .teacher-library-section-nav button')){
  const top=this.tagName==='BUTTON'?40+[...this.parentElement.querySelectorAll('button')].indexOf(this)*36:0;
  return {top,left:0,width:200,height:36,right:200,bottom:top+36,x:0,y:top};
 }
 return originalBounds.call(this);
};
const question=(id,answer)=>({id,name:'Q.sql',description:'Question',answer,enabled:true,startFrom:'provided',setupDdl:'ALTER TABLE T ADD note varchar(20);',setupDml:'INSERT INTO T VALUES (1);'});
const source={id:'library',title:'Sales',createTableSql:'CREATE TABLE T(id INT);',createTableFileName:'database.sql',insertIntoSql:'',createdAt:'2026-11-01',updatedAt:'2026-11-01',revision:3,
 datasets:[{fileName:'public.sql',sql:'INSERT INTO T VALUES(1);',isHidden:false},{fileName:'private.sql',sql:'INSERT INTO T VALUES(2);',isHidden:true}],
 bundle:{version:1,statement:'**T** (id)',ddlExercise:{questions:[question('ddl','ALTER TABLE T ADD n INT;')]},dmlExercise:{questions:[question('dml','UPDATE T SET id=2;')]},triggerExercise:{questions:[question('trg','CREATE TRIGGER trg ON T AFTER INSERT AS SELECT 1;')]},sqlSolutions:[{id:'sql',name:'Q.sql',description:'Select',setupSql:'SELECT * INTO T1 FROM T;',query:'SELECT id FROM T;',enabled:true,timeLimitMs:1000,expectedOutputs:[{columns:['id'],rows:[[99]]}]}]}};
const target={id:'target',title:'Target',schema:'CREATE TABLE Old(id INT);',schemaFileName:'old.sql',statement:'Keep statement',seedData:'old seed',testCases:[{seedData:'old seed',isHidden:false}],ddlExercise:{questions:[question('existing-ddl','old')]},dmlExercise:{questions:[]},triggerExercise:{questions:[]},sqlSolutions:[{id:'existing-sql',name:'Q.sql',query:'SELECT 1;',enabled:true,timeLimitMs:1000,expectedOutputs:[{columns:['one'],rows:[[1]]}]}],referenceSolution:'SELECT 1;',expectedOutputs:[{columns:['one'],rows:[[1]]}],expectedColumns:['one'],expectedRows:[[1]],databaseDescription:[{tableName:'old'}]};
let saved;
let failSave=false;
globalThis.__libraryApi={getDatabaseLibrary:async()=>[structuredClone(source)],updateDatabaseLibraryEntry:async(id,payload)=>{saved=structuredClone(payload);if(failSave)throw new Error('Bộ đề thư viện đã được sửa ở một cửa sổ khác.');return {...structuredClone(source),...payload,revision:4};},validateStage:async payload=>({status:'Success',schemaTables:[],tables:[],triggers:[],message:payload.part}),validateSolution:async()=>({status:'Success',table:{columns:['id'],rows:[[1]]}})};
const result=await build({stdin:{contents:`import React from 'react';export {act} from 'react';export {Simulate} from 'react-dom/test-utils';export {createRoot} from 'react-dom/client';import {MemoryRouter} from 'react-router-dom';import {DatabaseLibraryPage} from './src/features/teacher/DatabaseLibraryPage';export * from './src/shared/lib/sql/libraryBundleImport';export const page=()=> <MemoryRouter><DatabaseLibraryPage/></MemoryRouter>;`,resolveDir:process.cwd(),loader:'tsx'},bundle:true,write:false,platform:'node',format:'cjs',jsx:'automatic',loader:{'.css':'empty'},logLevel:'silent',plugins:[{name:'library',setup(b){
 b.onLoad({filter:/shared[\\/]api[\\/]teacherService\.ts$/},()=>({contents:'export const teacherService=globalThis.__libraryApi;',loader:'js'}));
 b.onLoad({filter:/[\\/]ProblemSqlEditor\.tsx$/},()=>({contents:'export function ProblemSqlEditor({value,label,onChange}) {return <textarea aria-label={label} data-code value={value} onChange={e=>onChange(e.target.value)}/>;}',loader:'tsx'}));
 b.onLoad({filter:/[\\/]ProblemRichTextEditor\.tsx$/},()=>({contents:'export function ProblemRichTextEditor({value,label,onChange}) {return <textarea aria-label={label} value={value} onChange={e=>onChange(e.target.value)}/>;}',loader:'tsx'}));
}}]});
const file=join(tmpdir(),`library-bundle-${process.pid}.cjs`);await writeFile(file,result.outputFiles[0].contents);
let root,api;
try{
 api=createRequire(import.meta.url)(file);
 let counter=0;
 const imports=api.importLibraryBundle(target,source,{sections:['DML','SQL','TRIGGER'],mode:'append'},()=>`copy-${++counter}`);
 assert.equal(imports.problem.schema,target.schema,'Unselected structure is preserved');
 assert.equal(imports.problem.statement,target.statement);
 assert.equal(imports.problem.sqlSolutions.length,2);
 assert.equal(imports.problem.sqlSolutions[1].setupSql,'SELECT * INTO T1 FROM T;','DQL preparation survives selective library imports');
 assert.equal(imports.problem.sqlSolutions[1].name,'Q_2.sql','Append resolves filenames without touching current question');
 assert.notEqual(imports.problem.dmlExercise.questions[0].id,'dml');
 assert.equal(imports.problem.dmlExercise.questions[0].setupDdl,source.bundle.dmlExercise.questions[0].setupDdl);
 assert.equal(imports.problem.dmlExercise.questions[0].setupDml,source.bundle.dmlExercise.questions[0].setupDml);
 assert.deepEqual(imports.problem.sqlSolutions[1].expectedOutputs,[],'Imported outputs are refreshed by a new run');
 imports.problem.dmlExercise.questions[0].answer='edited copy';
 assert.equal(source.bundle.dmlExercise.questions[0].answer,'UPDATE T SET id=2;','No shared references');
 assert.equal(target.sqlSolutions.length,1,'Import does not mutate its inputs');
 const replacement=api.importLibraryBundle(target,source,{sections:['schema','datasets','statement','DDL','SQL'],mode:'replace'},()=>`copy-${++counter}`);
 assert.equal(replacement.problem.sqlSolutions.length,1);
 assert.equal(replacement.problem.sqlSolutions[0].query,'SELECT id FROM T;');
 assert.equal(replacement.problem.testCases[1].isHidden,true);
 assert.deepEqual(replacement.datasetNames,['public.sql','private.sql']);
 assert.equal(replacement.problem.databaseDescription.length,0,'Replacing statement retires old duplicate predicates');
 assert.deepEqual(replacement.problem.expectedOutputs,[]);
 const legacy=api.importLibraryBundle(target,{...source,bundle:null,datasets:null,insertIntoSql:'legacy seed'},api.DEFAULT_LIBRARY_IMPORT);
 assert.equal(legacy.problem.seedData,'legacy seed');assert.equal(legacy.problem.ddlExercise.questions[0].id,'existing-ddl');

 root=api.createRoot(document.getElementById('root'));await api.act(async()=>root.render(api.page()));
 const click=async(node)=>{assert.ok(node);await api.act(async()=>node.click());};
 const button=text=>[...document.querySelectorAll('button')].find(n=>n.textContent.trim()===text);
 assert.equal(document.querySelector('.teacher-database-library-main'),null,'The library initially shows the tracking table');
 assert.equal(document.querySelectorAll('.teacher-database-tracking-table tbody tr').length,1);
 assert.equal(document.querySelector('.teacher-database-tracking-table th').textContent,'ID');
 assert.equal(document.querySelector('.teacher-database-tracking-table td[data-label="ID"]').textContent,'1','The ID column shows the row number');
 assert.ok(document.querySelector('.teacher-database-tracking-table').textContent.includes('Sales'));
 await click(document.querySelector('.u027-toggle'));
 const search=document.querySelector('.u027-input');
 await api.act(async()=>api.Simulate.change(search,{target:{value:'missing'}}));
 assert.ok(document.querySelector('.teacher-empty-row'),'Searching an unknown database shows an empty result');
 assert.equal(document.querySelector('.teacher-empty-row').colSpan,4,'Empty results span all four columns');
 await api.act(async()=>api.Simulate.change(search,{target:{value:'Sales'}}));
 assert.ok(document.querySelector('.teacher-database-tracking-table tbody button'));
 await click(document.querySelector('.u027-create'));
 assert.ok(document.querySelector('.teacher-database-library-main'),'New database opens the editor');
 assert.equal(document.querySelector('[maxlength="255"]').value,'');
 assert.equal(document.querySelector('.teacher-library-saved-disclosure'),null,'Saved databases stay in the tracking table');
 const sectionButton=id=>document.querySelectorAll('.teacher-library-section-nav button')[[
  'library-details','library-schema','library-datasets',
 ].indexOf(id)];
 assert.equal(document.querySelectorAll('.teacher-library-section-nav button').length,3,'The library only edits database details, structure and initial data');
 assert.equal(document.querySelector('.teacher-exam-session-page-nav-indicator').style.width,'200px','The active indicator is measured when the new editor mounts');
 assert.equal(document.querySelector('#library-statement'),null,'The shared problem statement is no longer edited in the library');
 await click(sectionButton('library-datasets'));
 assert.ok(document.querySelector('#library-datasets'));
 assert.equal(document.querySelector('#library-setup-data').getAttribute('aria-selected'),'true');
 assert.equal(sectionButton('library-datasets').getAttribute('aria-current'),'location');
 await click(sectionButton('library-schema'));
 assert.equal(document.querySelector('#library-setup-schema').getAttribute('aria-selected'),'true');
 await click(document.querySelector('.teacher-database-library-list-back'));
 assert.ok(document.querySelector('.teacher-database-tracking-table'),'Back returns to the tracking table');
 await click(document.querySelector('.teacher-database-tracking-table tbody button'));

 assert.equal(document.querySelector('[maxlength="255"]').value,'Sales','Clicking the selected row opens the database editor');
 assert.equal(document.querySelector('.teacher-exam-session-page-nav-indicator').style.width,'200px','The indicator is restored when reopening an existing database');
 assert.equal(document.querySelectorAll('[role=tab]').length,2,'Only the structure and initial dataset tabs remain');
 assert.deepEqual([...document.querySelectorAll('[role=tab]')].map(n=>n.id),['library-setup-schema','library-setup-data']);
 assert.equal(document.querySelector('.teacher-exercise-collection'),null,'DDL, DML, DQL and trigger question editors are not part of the library');
 assert.equal(document.querySelector('.teacher-database-library-identity-fields .teacher-database-library-import-control'),null,'File import is in the tab toolbar');
 await click(button('Lưu thay đổi'));
 assert.equal(saved.expectedRevision,3);
 assert.deepEqual(saved.bundle,source.bundle,'Question content saved earlier is carried through untouched');
 assert.equal(saved.datasets[1].isHidden,true);
 await click(document.querySelector('.teacher-database-library-list-back'));
 await click(document.querySelector('.teacher-list-detail-actions .button'));
 assert.equal(document.querySelector('[maxlength="255"]').value,'Sales','Returning from the tracking table reopens the saved database');
 failSave=true;
 const title=document.querySelector('[maxlength="255"]');
 await api.act(async()=>{title.value='My draft';api.Simulate.change(title);});
 await click(button('Lưu thay đổi'));
 assert.ok(document.body.textContent.includes('cửa sổ khác'));
 assert.equal(title.value,'My draft','Conflict preserves the local draft');
 assert.equal(saved.expectedRevision,4,'Successful save advances the revision used on the next save');
 console.log('PASS: Full library authoring, versioned saves, conflict draft retention, selective append/replace import, new IDs, setup/hidden seeds, legacy import, and independent snapshots.');
}finally{if(root)await api.act(async()=>root.unmount());await unlink(file);dom.window.close();}
process.exit(0);
