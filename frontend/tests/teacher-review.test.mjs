import assert from 'node:assert/strict';
import {build} from 'esbuild';
import {JSDOM} from 'jsdom';
import {writeFile,unlink} from 'node:fs/promises';
import {createRequire} from 'node:module';
import {join} from 'node:path';
import {tmpdir} from 'node:os';
const dom=new JSDOM('<div id="root"></div>',{url:'http://localhost',pretendToBeVisual:true});
for(const key of ['window','document','HTMLElement','Element','Node','localStorage','MutationObserver'])globalThis[key]=dom.window[key];
HTMLElement.prototype.scrollTo=()=>{};
globalThis.ResizeObserver=class{observe(){}disconnect(){}};
globalThis.IS_REACT_ACT_ENVIRONMENT=true;
const question=(id,name,sql,reference)=>({id,name,description:`Prompt ${id}`,studentSql:sql,referenceSql:reference,setupDdl:'',setupDml:'',result:{status:'Wrong Answer',datasets:[{dataset:1,correct:false,message:'Mismatch'}]}});
const detail={id:'new',student:'Student A',problem:'Sales',autoScore:5,maxScore:10,finalScore:null,status:'Needs review',query:'-- combined',submittedAt:'2026-10-30T01:00:00Z',activityId:'A',activityTitle:'Sales exam',feedback:'',reviewSections:[
  {part:'DDL',status:'Wrong Answer',questions:[question('create','create.sql','CREATE TABLE T(id INT);','CREATE TABLE T(id INT PRIMARY KEY);'),question('alter','alter.sql','ALTER TABLE T ADD n INT;','ALTER TABLE T ADD n MONEY;')]},
  {part:'DML',status:'Wrong Answer',questions:[question('update','update.sql','UPDATE T SET n=12;','UPDATE T SET n=11;')]},
  {part:'SQL',status:'Accepted',questions:[question('count','count.sql','SELECT COUNT(*) FROM T;','SELECT COUNT(*) FROM T;')]}
],attemptHistory:[{id:'old',number:1,autoScore:2,finalScore:null},{id:'new',number:2,autoScore:5,finalScore:null}]};
let saves=0,inspection;
globalThis.__reviewApi={
  getAssignments:async()=>[{id:'A',title:'Sales exam',classes:'Class A',isExamSession:true,submitted:'1/1',average:'50%',awaiting:1}],
  getAssignment:async()=>({id:'A',title:'Sales exam',classes:'Class A',isExamSession:true}),
  getAssignmentSubmissions:async()=>[{id:'new',student:'Student A',problem:'Sales',score:5,maxScore:10,status:'Needs review',parts:['DDL','DML','SQL'],submittedAt:detail.submittedAt},{id:'other',student:'Student B',problem:'Simple select',score:10,maxScore:10,status:'Accepted',parts:['SQL'],submittedAt:detail.submittedAt}],
  getSubmission:async id=>id==='old'?{...detail,id:'old',feedback:'Older feedback',reviewSections:[detail.reviewSections[2]]}:structuredClone(detail),
  updateSubmissionReview:async(id,score,feedback)=>{saves++;if(globalThis.__saveFail)throw Error('Save failed');return {...detail,id,finalScore:score,feedback};},
  inspectSubmission:async(...args)=>{inspection=args;return {status:'Wrong Answer',message:'Compared',datasets:[{dataset:1,matched:false,actualTables:[{name:'T',columns:['n'],rows:[[12]]}],expectedTables:[{name:'T',columns:['n'],rows:[[11]]}]}]};}
};
const bundle=await build({stdin:{contents:`
import React,{act} from 'react';import{createRoot}from'react-dom/client';import{Simulate}from'react-dom/test-utils';import{MemoryRouter,Routes,Route}from'react-router-dom';
import{ResultsDashboardPage}from'./src/features/teacher/ResultsPages';import{ActivityResultsPage}from'./src/features/teacher/ActivityResultsPage';import{ManualReviewPage}from'./src/features/teacher/submissions/SubmissionReviewPage';
export{act,Simulate};export function mount(node){const root=createRoot(node);root.render(<MemoryRouter future={{v7_startTransition:true,v7_relativeSplatPath:true}} initialEntries={['/teacher/results']}><Routes><Route path='/teacher/results' element={<ResultsDashboardPage/>}/><Route path='/teacher/results/activity/:activityId' element={<ActivityResultsPage/>}/><Route path='/teacher/results/review/:submissionId' element={<ManualReviewPage/>}/></Routes></MemoryRouter>);return root;}
`,resolveDir:process.cwd(),loader:'tsx'},bundle:true,write:false,platform:'node',format:'cjs',jsx:'automatic',loader:{'.css':'empty'},logLevel:'silent',plugins:[{name:'review-adapters',setup(builder){
  const stub=(filter,contents)=>builder.onLoad({filter},()=>({contents,loader:'tsx',resolveDir:process.cwd()}));
  stub(/[\\/]teacherService\.ts$/,'export const teacherService=globalThis.__reviewApi;');
  stub(/[\\/]LanguageContext\.tsx$/,'export const useLanguage=()=>({locale:"en-US",t:(text,values={})=>text.replace(/\\{(\\w+)\\}/g,(_,key)=>values[key]??"")});');
  stub(/[\\/]TranslatedText\.tsx$/,'export function TranslatedText({children}){return <>{children}</>;}');
  stub(/@uiw[\\/]react-codemirror[\\/].*index\.js$/,'export default function CodeMirror(props){return <pre aria-label={props["aria-label"]}>{props.value}</pre>;}');
}}]});
const file=join(tmpdir(),`teacher-review-${process.pid}.cjs`);let root,api;
try{
  await writeFile(file,bundle.outputFiles[0].contents);api=createRequire(import.meta.url)(file);
  const click=node=>api.act(async()=>{assert.ok(node);node.click();});
  const change=(node,value)=>api.act(async()=>{node.value=value;api.Simulate.change(node);});
  const button=text=>[...document.querySelectorAll('button')].find(node=>node.textContent===text);
  await api.act(async()=>{root=api.mount(document.getElementById('root'));});
  await click(button('View submissions'));
  assert.ok(document.querySelector('.teacher-activity-submissions'));
  assert.equal(document.querySelector('[role="dialog"],dialog'),null,'The submission list opens as a full page');
  assert.equal(document.querySelectorAll('.review-submissions-table tbody tr').length,2);
  const filters=document.querySelectorAll('.review-list-filters select');
  await change(filters[1],'DDL');
  assert.equal(document.querySelectorAll('.review-submissions-table tbody tr').length,1);
  await click(document.querySelector('.review-submissions-table a'));
  assert.ok(document.querySelector('.teacher-review-workspace'));
  assert.match(document.querySelector('[aria-label="Student SQL"]').textContent,/CREATE TABLE/);
  assert.match(document.querySelector('[aria-label="Current reference SQL"]').textContent,/PRIMARY KEY/);
  await click(document.querySelectorAll('.review-question-list button')[1]);
  assert.match(document.querySelector('[aria-label="Student SQL"]').textContent,/ADD n INT/);
  await click(document.querySelectorAll('.review-section-tabs button')[1]);
  assert.match(document.querySelector('[aria-label="Student SQL"]').textContent,/UPDATE/);
  await click(button('Run comparison'));
  assert.deepEqual(inspection,['new','DML','update']);
  assert.equal(document.querySelectorAll('.review-dataset .is-changed').length,2);
  assert.match(document.querySelector('.review-inspection-note').textContent,/saved score is unchanged/);
  await click(document.querySelectorAll('.review-section-tabs button')[2]);
  assert.match(document.querySelector('[aria-label="Student SQL"]').textContent,/COUNT/);
  const score=()=>document.querySelector('[aria-label="Final score"]');
  const feedback=()=>document.querySelector('[aria-label="Feedback"]');
  await change(score(),'11');await click(button('Save review'));
  assert.equal(saves,0);assert.match(document.querySelector('[role="alert"]').textContent,/0 to 10/);
  await change(score(),'8');await change(feedback(),'Good approach');
  globalThis.__saveFail=true;await click(button('Save review'));
  assert.equal(score().value,'8');assert.equal(feedback().value,'Good approach');
  assert.match(document.querySelector('[role="alert"]').textContent,/Save failed/);
  globalThis.__saveFail=false;await click(button('Save review'));
  assert.equal(saves,2);assert.match(document.querySelector('.review-save-state').textContent,/Review saved/);
  assert.equal(document.querySelector('[role="dialog"],dialog'),null,'Saving stays in the review page with inline confirmation');
  await change(document.querySelector('[aria-label="Attempt"]'),'old');
  assert.equal(feedback().value,'Older feedback');
  assert.equal(document.querySelectorAll('.review-section-tabs button').length,1,'Attempt navigation reloads the correct questions');
  assert.equal(document.querySelector('.review-inspection-note'),null,'Another attempt does not inherit a newer comparison');
  await click(document.querySelector('.review-back-link'));
  assert.equal(document.querySelectorAll('.review-submissions-table tbody tr').length,1,'Returning keeps the query type filter');
  console.log('PASS: full-page grading, query-type filters, exact question SQL, DML comparison, attempt navigation, score validation, failed-save preservation and inline save confirmation.');
}finally{if(root)await api.act(async()=>root.unmount());await unlink(file).catch(()=>{});dom.window.close();}
process.exit(0);
