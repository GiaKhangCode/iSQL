import assert from 'node:assert/strict';
import {build} from 'esbuild';
import {JSDOM} from 'jsdom';
import {writeFile,unlink} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {createRequire} from 'node:module';
const dom=new JSDOM('<div id="root"></div>',{url:'http://localhost/teacher/exams/a/grade'});
for(const key of ['window','document','HTMLElement','Element','Node','localStorage','MutationObserver'])globalThis[key]=dom.window[key];
globalThis.IS_REACT_ACT_ENVIRONMENT=true;
window.confirm=()=>true;
globalThis.ResizeObserver=class{observe(){}disconnect(){}};
const rows=Array.from({length:17},(_,i)=>({id:i===0?'submitted':null,studentId:'s'+i,problemId:'p',student:'Student '+i,studentCode:String(100+i),className:'Class A',problem:'SQL',parts:['SQL'],score:i===0?8:null,maxScore:10,status:i===0?'Graded':'Not submitted',submittedAt:i===0?'2026-11-08T01:00:00Z':null}));
globalThis.__gradeApi={getAssignment:async()=>({title:'Exam',examinationId:'exam'}),getAssignmentSubmissions:async()=>rows};
const output=await build({stdin:{contents:`export {act,createElement} from 'react';export {createRoot} from 'react-dom/client';export {MemoryRouter,Routes,Route} from 'react-router-dom';export {ActivityResultsPage} from './src/features/teacher/ActivityResultsPage';`,resolveDir:process.cwd(),loader:'tsx'},bundle:true,write:false,platform:'node',format:'cjs',jsx:'automatic',loader:{'.css':'empty'},logLevel:'silent',plugins:[{name:'grade-adapters',setup(b){
 b.onLoad({filter:/shared[\\/]api[\\/]teacherService\.ts$/},()=>({contents:'export const teacherService=globalThis.__gradeApi;',loader:'ts'}));
 b.onLoad({filter:/shared[\\/]api[\\/]apiClient\.ts$/},()=>({contents:'export const apiFetch=async()=>({});',loader:'ts'}));
}}]});
const file=join(tmpdir(),`grade-roster-${process.pid}.cjs`);await writeFile(file,output.outputFiles[0].contents);
const {act,createElement:h,createRoot,MemoryRouter,Routes,Route,ActivityResultsPage}=createRequire(import.meta.url)(file);const root=createRoot(document.getElementById('root'));
try{
 await act(async()=>{root.render(h(MemoryRouter,{initialEntries:['/teacher/exams/a/grade']},h(Routes,null,h(Route,{path:'/teacher/exams/:activityId/grade',element:h(ActivityResultsPage)}))));await new Promise(r=>setTimeout(r,0));});
 assert.ok(document.querySelector('.teacher-list-detail-layout .teacher-filter-side'));
 assert.equal(document.querySelectorAll('tbody tr').length,15);
 assert.equal(document.querySelectorAll('thead th').length,5);
 assert.equal(document.querySelector('tbody a').getAttribute('href'),'/teacher/exams/review/submitted');
 assert.equal(document.querySelector('.grading-detail-footer a').getAttribute('href'),'/teacher/exams/review/submitted');
 assert.ok(document.querySelector('.grading-actions').textContent.includes('Công bố điểm'));
 assert.equal(document.querySelector('.grading-actions a').textContent,'Thoát');
 assert.ok(!document.body.textContent.includes('Xuất bảng điểm'));
 assert.ok(!document.querySelector('.grading-detail-body button'));

 assert.ok(document.querySelector('.teacher-list-detail-panel a').getAttribute('href').endsWith('/submitted'));
 await act(async()=>document.querySelectorAll('.submission-select-button')[1].click());
 assert.ok(document.querySelector('.teacher-list-detail-panel').textContent.includes('Sinh viên chưa nộp bài thi.'));
 assert.equal(document.querySelector('.grading-detail-footer a').getAttribute('href'),'/teacher/exams/a/students/s1/problems/p/review','Unsubmitted students have their own review route');
 assert.equal(document.querySelectorAll('tbody a').length,15);
 await act(async()=>[...document.querySelectorAll('button')].find(b=>b.textContent==='Tiếp →').click());
 assert.equal(document.querySelectorAll('tbody tr').length,2,'Pagination advances without resetting itself');
 assert.ok(document.querySelector('.teacher-list-detail-panel').textContent.includes('Student 15'));
 console.log('PASS: complete roster split layout, selection, missing submission and pagination');
}finally{await act(async()=>root.unmount());await unlink(file);dom.window.close();}
process.exit(0);
