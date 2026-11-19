import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';
import { build } from 'esbuild';
import { createRequire } from 'node:module';
import { writeFile, unlink } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const dom = new JSDOM('<button id="navigation">Navigation</button><div id="root"></div>', { url:'http://localhost', pretendToBeVisual:true });
for (const key of ['window','document','HTMLElement','Element','Node']) globalThis[key] = dom.window[key];
HTMLElement.prototype.scrollTo = () => {};
globalThis.IS_REACT_ACT_ENVIRONMENT = true;
const require = createRequire(import.meta.url);
const result = await build({
  stdin:{ contents:`
    import React, { act } from 'react';
    import { createRoot } from 'react-dom/client';
    import { MemoryRouter } from 'react-router-dom';
    import { Loading } from './src/shared/ui/ui';
    import { SubmissionsPage } from './src/features/student/SubmissionsPage';
    import { AssignmentsListPage } from './src/features/teacher/TeacherLandingPages';
    import { AdminUsersPage } from './src/features/admin/AdminPages';
    export { act };
    export function mount(node,kind,props={}) { const root=createRoot(node); root.render(<MemoryRouter future={{v7_startTransition:true,v7_relativeSplatPath:true}}>{kind==='student'?<SubmissionsPage/>:kind==='teacher'?<AssignmentsListPage/>:kind==='admin'?<AdminUsersPage/>:<Loading {...props}/>}</MemoryRouter>); return root; }
  `,resolveDir:process.cwd(),loader:'tsx' },
  bundle:true,write:false,platform:'node',format:'cjs',jsx:'automatic',logLevel:'silent',loader:{'.css':'empty'},
  plugins:[{name:'loading-fixtures',setup(builder){
    builder.onResolve({filter:/^(react|react-dom|react-router-dom)(\/.*)?$/},args=>({path:require.resolve(args.path),external:true}));
    builder.onLoad({filter:/[\\/]studentApi\.ts$/},()=>({contents:'export const studentApi={getSubmissions:()=>globalThis.__pending,getProblems:async()=>[]};',loader:'js'}));
    builder.onLoad({filter:/[\\/]teacherService\.ts$/},()=>({contents:'export const teacherService={getAssignments:()=>globalThis.__pending};',loader:'js'}));
    builder.onLoad({filter:/[\\/]adminService\.ts$/},()=>({contents:'export const adminService={getUsers:()=>globalThis.__pending,getInstructors:async()=>[],getRoleOptions:async()=>[],getLecturerRequests:async()=>[]};export const lecturerOptionsFor=(eligible)=>eligible;export const userRoleNames=(user)=>user.account_type||'';',loader:'js'}));
    builder.onLoad({filter:/[\\/]LanguageContext\.tsx$/},()=>({contents:`export const useLanguage=()=>({locale:'vi-VN',language:'en',t:(text,values={})=>typeof text==='string'?text.replace(/\{(\w+)\}/g,(_,key)=>values[key]??''):text});`,loader:'js'}));
    builder.onLoad({filter:/[\\/]TranslatedText\.tsx$/},()=>({contents:'export function TranslatedText({children}){return <>{children}</>;}',loader:'jsx'}));
    builder.onLoad({filter:/[\\/]LanguageSwitch\.tsx$/},()=>({contents:'export function LanguageText({text}){return <>{text}</>;}',loader:'jsx'}));
  }}],
});
const file=join(tmpdir(),`sqluit-loading-${process.pid}.cjs`);
let root,api;
try{
  await writeFile(file,result.outputFiles[0].contents);
  api=require(file);
  const target=document.getElementById('root');
  for(const variant of ['list','table','cards','dashboard','detail','form','workspace','inline']){
    await api.act(async()=>{document.getElementById('navigation').focus();root=api.mount(target,'skeleton',{variant,label:'Loading data…'});});
    assert.equal(document.querySelector('[role="status"]').textContent,'Loading data…',`${variant} keeps an accessible loading announcement`);
    assert.ok(document.querySelector('.loading-skeleton__visual[aria-hidden="true"]'),`${variant} hides decorative placeholders from assistive technology`);
    assert.equal(target.querySelectorAll('input,select,button,a,[tabindex]').length,0,`${variant} has no pretend interactive controls`);
    assert.equal(document.activeElement.id,'navigation',`${variant} does not steal focus`);
    await api.act(async()=>root.unmount());root=null;
  }
  for(const kind of ['student','teacher','admin']){
    let finish,fail;
    globalThis.__pending=new Promise((resolve,reject)=>{finish=resolve;fail=reject;});
    await api.act(async()=>{root=api.mount(target,kind);});
    assert.equal(target.querySelector('.spinner'),null,`${kind} uses a skeleton while the request is pending`);
    assert.ok(target.querySelector('.skeleton-line'),`${kind} renders placeholders`);
    const search=kind==='student'?target.querySelector('.u027-input'):null;
    if(search)await api.act(async()=>search.focus());
    if(kind==='teacher')assert.equal(target.querySelector('.u027-toggle').disabled,true);
    await api.act(async()=>finish(kind==='teacher'?Array.from({length:16},(_,index)=>({id:`A${index+1}`,title:'SQL exercise',classes:'Class A',createdAt:index===0?'2026-11-04T18:00:00Z':index===1?'invalid':undefined,problems:3,due:'Oct 30',closes:index===0?'2026-10-29T18:00:00Z':index===1?'invalid':null,submitted:'0/20',eligibleStudents:20,status:'Open'})):kind==='admin'?[{id:'u1',name:'A Student',email:'student@example.test',role:'Student',status:'Active',lastActive:'Today'}]:[]));
    assert.equal(target.querySelector('.skeleton-line'),null,`${kind} replaces placeholders when data arrives`);
    if(kind==='teacher'){
      assert.match(target.textContent,/SQL exercise/);
      assert.equal(target.querySelector('.u027-toggle').disabled,false);
      const table=target.querySelector('.teacher-assignment-list-table');
      assert.deepEqual([...table.querySelectorAll('th')].map(node=>node.textContent),['ID','ASSIGNMENT','CLASSES','Created date','DUE DATE','STATUS']);
      assert.equal(table.querySelector('td[data-label="ID"]').textContent,'1');
      const dates=table.querySelectorAll('td[data-label="Created date"]');
      assert.equal(dates[0].textContent,'05/11/2026','Creation dates use Vietnam time');
      assert.equal(dates[1].textContent,'—','Invalid dates stay empty');
      assert.equal(dates[2].textContent,'—','Missing dates are never invented');
      const deadlines=table.querySelectorAll('td[data-label="DUE"]');
      assert.equal(deadlines[0].textContent,'30/10/2026','Deadlines use the interface locale and Vietnam time');
      assert.equal(deadlines[1].textContent,'—','Invalid deadlines stay empty');
      assert.equal(deadlines[2].textContent,'—','Missing deadlines stay empty');
      assert.ok(target.querySelector('.teacher-list-detail-facts').textContent.includes('Due date30/10/2026'),'The detail panel uses the same localized deadline');
      assert.ok(!table.textContent.includes('Oct'),'The table ignores English date labels from the API');
      assert.ok(target.querySelector('.teacher-list-detail-facts').textContent.includes('Problems3'));
      assert.ok(target.querySelector('.teacher-list-detail-facts').textContent.includes('Submitted0/20'));
      await api.act(async()=>[...target.querySelectorAll('.teacher-library-pagination button')].find(node=>node.textContent.includes('Next')).click());
      assert.equal(table.querySelector('td[data-label="ID"]').textContent,'16','Row numbers continue across pages');
    }else if(kind==='admin')assert.match(target.textContent,/A Student/);
    else{
      assert.match(target.textContent,/No submissions found/);
      assert.equal(document.activeElement,search,'Loaded data does not replace or blur the existing filters');
    }
    await api.act(async()=>root.unmount());root=null;
    globalThis.__pending=new Promise((resolve,reject)=>{fail=reject;});
    await api.act(async()=>{root=api.mount(target,kind);});
    await api.act(async()=>fail(new Error('Test connection failed')));
    assert.equal(target.querySelector('.skeleton-line'),null,`${kind} does not leave a skeleton on request failure`);
    assert.ok(target.querySelector('[role="alert"]'),`${kind} preserves its error state`);
    await api.act(async()=>root.unmount());root=null;
  }
  console.log('PASS: all loading layouts preserve accessibility and focus; student, teacher and admin pending/success/empty/error transitions work.');
}finally{
  if(root)await api.act(async()=>root.unmount());
  await unlink(file).catch(()=>{});dom.window.close();
}
