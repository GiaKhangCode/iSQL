import assert from 'node:assert/strict';
import {build} from 'esbuild';
import {JSDOM} from 'jsdom';
import {writeFile,unlink} from 'node:fs/promises';
import {createRequire} from 'node:module';
import {join} from 'node:path';
import {tmpdir} from 'node:os';

const dom=new JSDOM('<div id="root"></div>',{url:'http://localhost/office/examinations',pretendToBeVisual:true});
for(const key of ['window','document','HTMLElement','Element','Node','localStorage'])globalThis[key]=dom.window[key];
globalThis.IS_REACT_ACT_ENVIRONMENT=true;
HTMLElement.prototype.scrollTo=()=>{};
globalThis.__examinations=Array.from({length:16},(_,index)=>({id:`E${index+1}`,title:`Exam ${index+1}`,examDate:'2026-11-05',
  createdAt:index===14?'invalid':index===13?null:'2026-11-04T18:00:00Z',sessions:[]}));
globalThis.__sessions=[...Array.from({length:4},(_,index)=>({id:`slot${index+1}`,title:`Exam 16 · Ca ${index+1}`,examinationId:'E16',isExamSession:true,status:'Draft',classes:'Class A'})),
  {id:'other-parent',title:'Exam 16 · Ca 1',examinationId:'other',isExamSession:true,status:'Draft',classes:'Class A'},
  {id:'standalone',title:'Exam 16 · Ca 1',isExamSession:true,status:'Draft',classes:'Class A'}];
globalThis.__examinations[15].sessions=Array.from({length:4},(_,index)=>({id:`slot${index+1}`,index:index+1,starts:'07:30',ends:'09:00',classIds:['Class A'],eligibleStudents:1,proctors:[],published:false}));
const built=await build({stdin:{contents:`
  import React from 'react'; export {act} from 'react'; export {Simulate} from 'react-dom/test-utils'; export {createRoot} from 'react-dom/client';
  import {MemoryRouter,Routes,Route,useLocation,useParams} from 'react-router-dom'; import {ExaminationsPage} from './src/features/office/ExaminationsPage';
  import {ExamSessionsListPage} from './src/features/teacher/ExamSessionsListPage';
  function EditorTarget(){return <div data-editor data-session={useParams().id}/>;}
  export const page=key=><MemoryRouter key={key} initialEntries={['/office/examinations']} future={{v7_startTransition:true,v7_relativeSplatPath:true}}><Routes>
    <Route path='/office/examinations' element={<ExaminationsPage/>}/><Route path='/office/exam-sessions' element={<ExamSessionsListPage/>}/><Route path='/office/sessions' element={<ExamSessionsListPage sessionsOnly/>}/><Route path='/office/examinations/:id/edit' element={<EditorTarget/>}/><Route path='/office/sessions/:id/edit' element={<EditorTarget/>}/><Route path='/office/exam-sessions/:id/edit' element={<EditorTarget/>}/>
  </Routes></MemoryRouter>;
 export const adminPage=()=> <MemoryRouter initialEntries={['/admin/examinations']}><Routes><Route path='/admin/examinations' element={<ExaminationsPage/>}/><Route path='/admin/examinations/:id/edit' element={<EditorTarget/>}/></Routes></MemoryRouter>;
`,resolveDir:process.cwd(),loader:'tsx'},bundle:true,write:false,platform:'node',format:'cjs',jsx:'automatic',loader:{'.css':'empty'},logLevel:'silent',plugins:[{
  name:'examinations-fixture',setup(b){b.onLoad({filter:/shared[\\/]api[\\/]teacherService\.ts$/},()=>({contents:'export const teacherService={getExaminations:async()=>globalThis.__examinations,getAssignments:async()=>globalThis.__sessions};',loader:'js'}));}
}]});
const file=join(tmpdir(),`examinations-list-${process.pid}.cjs`);
await writeFile(file,built.outputFiles[0].contents);
let root,api;
try{
  api=createRequire(import.meta.url)(file);root=api.createRoot(document.getElementById('root'));
  await api.act(async()=>root.render(api.page('list')));
  const rows=()=>[...document.querySelectorAll('.office-examination-table tbody tr')];
  const click=node=>api.act(async()=>{assert.ok(node);node.click();});
  if(process.argv.includes('--admin-only')){
    await api.act(async()=>root.render(api.adminPage()));
    for(const link of document.querySelectorAll('a[href]'))assert.ok(!link.getAttribute('href').startsWith('/office/'));
    assert.equal(document.querySelector('.office-exam-create').getAttribute('href'),'/admin/examinations/new');
    await click(document.querySelector('.office-examination-edit'));
    assert.ok(document.querySelector('[data-editor]'));
    console.log('PASS: admin exam creation, session links and editing stay under /admin.');
    await api.act(async()=>root.unmount());root=null;await unlink(file);dom.window.close();process.exit(0);
  }

  assert.equal(document.querySelectorAll('.office-examination-table th').length,6);
  assert.equal(rows().length,15);
  await click(document.querySelector('.office-exam-dashboard > div:not([hidden]) .class-room-switch'));
  assert.equal(document.querySelectorAll('.teacher-exam-session-list-table tbody tr').length,5,'Integrated mode shows exam sessions only');
  assert.equal(document.querySelector('.office-exam-dashboard > div:not([hidden]) .office-exam-create'),null,'Create examination is hidden in session mode');
  await click(document.querySelector('.office-exam-dashboard > div:not([hidden]) .class-room-switch'));
  assert.equal(rows().length,15,'Switch returns to examinations');
  assert.equal(rows()[0].cells[0].textContent,'1');
  assert.equal(rows()[0].cells[2].textContent,'05/11/2026','Creation dates use Vietnam time and locale');
  assert.equal(rows()[1].cells[2].textContent,'—','Invalid creation dates stay empty');
  assert.equal(rows()[2].cells[2].textContent,'—','Missing creation dates stay empty');
  assert.equal(document.querySelectorAll('.office-examination-session-meta').length,4,'All four sessions retain their compact counts and result link');
  assert.equal(document.querySelectorAll('.office-examination-session-row').length,4);
  assert.equal(document.querySelector('.office-examination-detail .teacher-list-detail-facts'),null,'Redundant totals are removed');
  assert.ok(document.querySelector('.office-examination-detail-header .office-examination-info'),'Session info is beside the header');
  assert.ok(document.querySelector('.office-examination-sessions').compareDocumentPosition(document.querySelector('.office-examination-edit')) & Node.DOCUMENT_POSITION_FOLLOWING,'Edit stays after the scrolling session list');
  assert.deepEqual([...document.querySelectorAll('.office-examination-session-row')].map(link=>new URL(link.href).pathname),['/office/sessions/slot1/edit','/office/sessions/slot2/edit','/office/sessions/slot3/edit','/office/sessions/slot4/edit']);
  await click(document.querySelector('.teacher-list-footer nav button:last-child'));
  assert.equal(rows().length,1);
  assert.equal(rows()[0].cells[0].textContent,'16','Row numbering continues across pages');
  await click(document.querySelector('.teacher-filter-side .u027-toggle'));
  await api.act(async()=>api.Simulate.change(document.querySelector('.u027-input'),{target:{value:'Exam 16'}}));
  assert.equal(rows().length,1);
  assert.equal(rows()[0].cells[0].textContent,'1','Filtering resets pagination');
  const admissionLink=document.querySelector('.office-examination-info');
  const destination=new URL(admissionLink.href);
  assert.equal(destination.pathname,'/office/sessions');
  assert.equal(destination.searchParams.get('search'),'Exam 16');
  assert.equal(destination.searchParams.get('examinationId'),'E16');
  await click(rows()[0]);
  assert.ok(document.querySelector('[data-editor]'),'Clicking the selected row opens the examination editor');
  await api.act(async()=>root.render(api.page('admission-link')));
  await click(document.querySelector('.office-examination-info'));
  assert.equal(document.querySelector('.u027-input').value,'Exam 16','Admission details fill the parent examination name');
  assert.equal(document.querySelector('.teacher-filter-side .u027-toggle').getAttribute('aria-expanded'),'false','The destination keeps its filters collapsed');
  await click(document.querySelector('.teacher-filter-side .u027-toggle'));
  assert.equal(document.querySelectorAll('.teacher-exam-session-list-table tbody tr').length,4,'Only the four matching parent sessions appear, even when other examinations have the same title');
  await click(document.querySelector('.u027-reset'));
  assert.equal(document.querySelectorAll('.teacher-exam-session-list-table tbody tr').length,5,'Reset shows sessions from every examination');
  assert.equal(document.querySelector('.teacher-exam-session-list-table th').textContent,'ID');
  assert.deepEqual([...document.querySelectorAll('.teacher-exam-session-list-table tbody tr')].map(row=>row.cells[0].textContent),['1','2','3','4','5']);
  const initiallySelected=document.querySelector('.teacher-exam-session-list-table tr.is-selected');
  const initialEditPath=new URL(document.querySelector('.teacher-list-detail-actions .primary').href).pathname;
  await click(initiallySelected);
  assert.equal(document.querySelector('[data-editor]').dataset.session,initialEditPath.split('/')[3],'One click opens the row selected by default');
  await api.act(async()=>root.render(api.page('select-and-open')));
  await click(document.querySelector('.teacher-list-detail-actions .primary'));
  const anotherRow=document.querySelectorAll('.teacher-exam-session-list-table tbody tr')[1];
  await click(anotherRow);
  assert.ok(!document.querySelector('[data-editor]'),'Clicking a different row first selects it');
  assert.ok(anotherRow.classList.contains('is-selected'));
  const selectedEditPath=new URL(document.querySelector('.teacher-list-detail-actions .primary').href).pathname;
  await click(anotherRow);
  assert.equal(document.querySelector('[data-editor]').dataset.session,selectedEditPath.split('/')[3],'Clicking the selected row again opens it without a double-click');
  globalThis.__sessions.push(...Array.from({length:16},(_,i)=>({id:`extra${i}`,title:`Extra ${i}`,examinationId:'other',isExamSession:true,status:'Draft',classes:'Class A'})));
  await api.act(async()=>root.render(api.page('all-sessions')));
  await click(document.querySelector('.teacher-list-detail-actions .primary'));
  await click(document.querySelector('.u027-reset'));
  await click(document.querySelector('.teacher-list-footer nav button:last-child'));
  assert.equal(document.querySelector('.teacher-exam-session-list-table tbody tr').cells[0].textContent,'16','Exam session IDs continue across pages');
  await api.act(async()=>root.render(api.page('edit-link')));
  await click(document.querySelector('.office-examination-edit'));
  assert.ok(document.querySelector('[data-editor]'),'Edit opens the multi-session examination editor');
  await api.act(async()=>root.render(api.page('session-link')));
  await click(document.querySelectorAll('.office-examination-session-row')[3]);
  assert.ok(!document.querySelector('[data-editor]'),'Opening a session preserves the current examinations tab');
  for(const link of document.querySelectorAll('.office-examination-session-row')){
    assert.equal(link.target,'_blank','Session rows open a new browser tab');
    assert.ok(link.relList.contains('noopener'));
  }
  await api.act(async()=>root.render(api.adminPage()));
  for(const link of document.querySelectorAll('a[href]'))assert.ok(!link.getAttribute('href').startsWith('/office/'),'Admin examination links stay in the admin workspace');
  assert.equal(document.querySelector('.office-exam-create').getAttribute('href'),'/admin/examinations/new');
  await click(document.querySelector('.office-examination-edit'));
  assert.ok(document.querySelector('[data-editor]'),'Admin opens the exam editor inside admin routes');
  globalThis.__examinations=[];
  await api.act(async()=>root.render(api.page('empty')));
  assert.equal(document.querySelector('.teacher-empty-row').colSpan,6);
  assert.ok(document.querySelector('.teacher-filter-side .office-exam-create'),'Empty examinations retain the create action');
  console.log('PASS: compact session summaries, columns/dates/pagination, edit/double-click editor, admission search navigation, exact parent sessions, reset and empty layout.');
}finally{if(root)await api.act(async()=>root.unmount());await unlink(file);dom.window.close();}
process.exit(0);
