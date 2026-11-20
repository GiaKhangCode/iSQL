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
import React,{act} from 'react';import{createRoot}from'react-dom/client';import{Simulate}from'react-dom/test-utils';import{MemoryRouter,Routes,Route,useLocation}from'react-router-dom';
import{ManualReviewPage}from'./src/features/teacher/submissions/SubmissionReviewPage';
function Path(){return <output data-path>{useLocation().pathname}</output>}
export{act,Simulate};export function mount(node,path){const root=createRoot(node);root.render(<MemoryRouter initialEntries={[path]}><Path/><Routes><Route path='/teacher/exams/:activityId/students/:studentId/problems/:problemId/review' element={<ManualReviewPage/>}/><Route path='/teacher/exams/review/:submissionId' element={<ManualReviewPage/>}/><Route path='/teacher/results/review/:submissionId' element={<ManualReviewPage/>}/></Routes></MemoryRouter>);return root;}
`,resolveDir:process.cwd(),loader:'tsx'},bundle:true,write:false,platform:'node',format:'cjs',jsx:'automatic',loader:{'.css':'empty'},logLevel:'silent',plugins:[{name:'review-adapters',setup(builder){
 const stub=(filter,contents)=>builder.onLoad({filter},()=>({contents,loader:'tsx',resolveDir:process.cwd()}));
 stub(/[\\/]teacherService\.ts$/,'export const teacherService=globalThis.__reviewApi;');
 stub(/[\\/]LanguageContext\.tsx$/,'export const useLanguage=()=>({locale:"en-US",t:(text,values={})=>text.replace(/\\{(\\w+)\\}/g,(_,key)=>values[key]??"")});');
 stub(/[\\/]TranslatedText\.tsx$/,'export function TranslatedText({children}){return <>{children}</>;}');
 stub(/@uiw[\\/]react-codemirror[\\/].*index\.js$/,'export default function CodeMirror(props){return <pre aria-label={props["aria-label"]}>{props.value}</pre>;}');
}}]});
const file=join(tmpdir(),`exam-review-routing-${process.pid}.cjs`);let root;
try{
 await writeFile(file,bundle.outputFiles[0].contents);const api=createRequire(import.meta.url)(file);
 for(const section of ['exams','results']){
  await api.act(async()=>{root=api.mount(document.getElementById('root'),`/teacher/${section}/review/new`);});
  assert.ok(document.querySelector('.teacher-review-workspace'));
  assert.equal(document.querySelector('.review-back-link').getAttribute('href'),section==='exams'?'/teacher/exams/A/grade':'/teacher/results/activity/A');
  const picker=document.querySelector('select[aria-label="Attempt"]');
  await api.act(async()=>{picker.value='old';api.Simulate.change(picker);});
  assert.equal(document.querySelector('[data-path]').textContent,`/teacher/${section}/review/old`,'Changing attempts stays in its original navigation tab');
  await api.act(async()=>root.unmount());root=null;
 }
 globalThis.__reviewApi.getStudentWork=async()=>({...detail,id:'unsubmitted',isSubmitted:false,status:'Not submitted',submittedAt:null,attemptHistory:[],reviewSections:detail.reviewSections.map(section=>({...section,questions:section.questions.map(q=>({...q,studentSql:'',result:null}))}))});
 await api.act(async()=>{root=api.mount(document.getElementById('root'),'/teacher/exams/A/students/s/problems/p/review');});
 assert.ok(document.querySelector('.teacher-review-workspace'));
 assert.ok(document.body.textContent.includes('Sinh viên chưa nộp bài'));
 assert.equal(document.querySelector('.review-back-link').getAttribute('href'),'/teacher/exams/A/grade');
 assert.equal(document.querySelector('.review-save-button').disabled,true);
 assert.equal(document.querySelector('input[aria-label="Final score"]').disabled,true);
 await api.act(async()=>root.unmount());root=null;
 console.log('PASS: exam review deep links, return to exam grading, attempt switching and existing results route');
}finally{if(root)await createRequire(import.meta.url)(file).act(async()=>root.unmount());await unlink(file);dom.window.close();}
process.exit(0);
