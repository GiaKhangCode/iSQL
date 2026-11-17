import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';
import { build } from 'esbuild';
import { createRequire } from 'node:module';
import { writeFile, unlink } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const dom = new JSDOM('<div id="root"></div>', { url: 'http://localhost', pretendToBeVisual: true });
for (const key of ['window', 'document', 'HTMLElement', 'Element', 'Node']) globalThis[key] = dom.window[key];
HTMLElement.prototype.scrollTo = () => {};
window.scrollTo=()=>{};
dom.window.HTMLDialogElement.prototype.showModal=function(){this.open=true;};
dom.window.HTMLDialogElement.prototype.close=function(){this.open=false;};
globalThis.IS_REACT_ACT_ENVIRONMENT = true;
globalThis.__activities = [
  { id: 'live', title: 'Live joins', classes: 'Class A', shortDescription: 'Joins practice', status: 'Open', isContest: true },
  { id: 'next', title: 'Next challenge', classes: 'Class B', status: 'Scheduled', isContest: true },
  { id: 'closed', title: 'Previous challenge', classes: 'Class A', status: 'Closed', isContest: true },
  { id: 'assignment', title: 'Regular assignment', classes: 'Class A', status: 'Open', isContest: false },
  { id: 'exam-slot', title: 'Examination session', classes: 'Class A', status: 'Open', isContest: true, examinationId: 'exam-parent' },
];
globalThis.__classes = [
  { id: 'A', course: 'Database systems', term: 'Semester 2, 2026', students: 20, status: 'Active' },
  { id: 'B', course: 'SQL basics', term: 'Semester 1, 2026', students: 25, status: 'Archived' },
];
const require = createRequire(import.meta.url);
const result = await build({
  stdin: { contents: `
    import React, { act } from 'react';
    import { createRoot } from 'react-dom/client';
    import { Simulate } from 'react-dom/test-utils';
    import { MemoryRouter, useLocation } from 'react-router-dom';
    import { ContestsListPage } from './src/features/teacher/ContestsListPage';
    import { AssignmentsListPage } from './src/features/teacher/AssignmentsListPage';
    import { ClassesPage } from './src/features/teacher/ClassesPage';
    import { ProctorRoomsView } from './src/features/office/ProctorPage';
    import { ResultsDashboardPage } from './src/features/teacher/ResultsPages';
    import { SubmissionsPage } from './src/features/student/SubmissionsPage';
    export { act, Simulate };
    function Location(){return <output data-location>{useLocation().pathname}</output>;}
    export function mount(node, kind) { const root=createRoot(node); root.render(<MemoryRouter initialEntries={[kind === 'contests' ? '/office/contests' : kind === 'exams' ? '/teacher/exams' : '/']} future={{v7_startTransition:true,v7_relativeSplatPath:true}}><Location/>{kind === 'contests' ? <ContestsListPage/> : kind === 'assignments' ? <AssignmentsListPage/> : kind === 'results' ? <ResultsDashboardPage/> : kind === 'submissions' ? <SubmissionsPage/> : kind === 'exams' ? <ProctorRoomsView/> : <ClassesPage/>}</MemoryRouter>); return root; }
  `, resolveDir: process.cwd(), loader: 'tsx' },
  bundle: true, write: false, platform: 'node', format: 'cjs', jsx: 'automatic', logLevel: 'silent', loader: { '.css': 'empty' },
  plugins: [{ name: 'teacher-filter-fixtures', setup(builder) {
    builder.onResolve({ filter: /^(react|react-dom|react-router-dom)(\/.*)?$/ }, args => ({ path: require.resolve(args.path), external: true }));
    builder.onLoad({ filter: /[\\/]teacherService\.ts$/ }, () => ({ contents: 'export const teacherService={getAssignments:()=>globalThis.__activitiesRequest??Promise.resolve(globalThis.__activities),getClasses:async()=>globalThis.__classes,getClassMembers:async()=>[]};', loader: 'js' }));
    builder.onLoad({filter:/[\\/]AuthContext\.tsx$/},()=>({contents:'export const useAuth=()=>({session:{id:"teacher",role:"instructor"},permissions:["exam.proctor"]});',loader:'js'}));
    builder.onLoad({filter:/[\\/]apiClient\.ts$/},()=>({contents:'export const apiFetch=async(path)=>{globalThis.__roomCalls??=[];globalThis.__roomCalls.push(path);if(path==="/api/proctor/sessions")return globalThis.__examRooms??[];return globalThis.__roomPeople??[];};',loader:'js'}));
    builder.onLoad({ filter: /[\\/]studentApi\.ts$/ }, () => ({ contents: 'export const studentApi={getSubmissions:async(filters)=>{globalThis.__submissionFilters=filters;globalThis.__submissionCalls=(globalThis.__submissionCalls??0)+1;return [];},getProblems:async()=>[]};', loader: 'js' }));
    builder.onLoad({ filter: /[\\/]LanguageContext\.tsx$/ }, () => ({ contents: `export const useLanguage=()=>({locale:'en-US',t:(text="",values={})=>text.replace(/\{(\w+)\}/g,(_,key)=>values[key]??'')});`, loader: 'js' }));
    builder.onLoad({ filter: /[\\/]TranslatedText\.tsx$/ }, () => ({ contents: 'export function TranslatedText({children}) {return <>{children}</>;}', loader: 'jsx' }));
  } }],
});
const file = join(tmpdir(), `teacher-list-filters-${process.pid}.cjs`);
let root, api;
try {
  await writeFile(file, result.outputFiles[0].contents);
  api = require(file);
  const click = node => api.act(async () => node.click());
  const scope = () => document.querySelector('[id^="results-panel-"]:not([hidden])') || document;
  const input = () => scope().querySelector('.u027-input');
  const toggle = () => document.querySelector('.u027-toggle');
  const change = value => api.act(async () => api.Simulate.change(input(), { target: { value } }));
  const rows = () => [...scope().querySelectorAll('tbody tr')];
  const text = () => scope().querySelector('tbody').textContent;
  async function pick(index, field = 0) {
    const select = scope().querySelectorAll('.u027-select')[field];
    await api.act(async () => { select.focus(); select.click(); api.Simulate.keyDown(select, { key: 'Home', nativeEvent: {} }); });
    for (let step = 0; step < index; step++) await api.act(async () => api.Simulate.keyDown(select, { key: 'ArrowDown', nativeEvent: {} }));
    await api.act(async () => api.Simulate.keyDown(select, { key: 'Enter', nativeEvent: {} }));
  }
  let resolveActivities;
  globalThis.__activitiesRequest = new Promise(resolve => { resolveActivities = resolve; });
  await api.act(async () => { root = api.mount(document.getElementById('root'), 'contests'); });
  assert.ok(document.querySelector('.teacher-list-loading'), 'Loading reserves the same table and detail columns');
  assert.equal(document.querySelector('[role="status"]').textContent, 'Loading contests…');
  assert.equal(document.querySelector('.spinner'), null, 'Loading no longer replaces the page with an isolated spinner');
  assert.equal(toggle().disabled, true, 'Filters stay disabled until data is available');
  assert.equal(document.querySelector('.teacher-filter-side .u027-create').getAttribute('href'), '/office/contests/new', 'The create action keeps its final position and styling during loading');
  await api.act(async () => resolveActivities(globalThis.__activities));
  delete globalThis.__activitiesRequest;
  assert.equal(document.querySelector('.teacher-list-loading'), null);
  assert.equal(toggle().disabled, false);
  assert.equal(rows().length, 3, 'The contest page excludes assignments and examination sessions');
  assert.deepEqual([...document.querySelectorAll('.teacher-contest-list-table th')].map(node=>node.textContent),['ID','CONTEST','AUDIENCE','STARTS','ENDS','STATUS']);
  assert.equal(document.querySelector('.teacher-contest-list-table td[data-label="STATUS"]').textContent,'Upcoming');
  assert.equal(toggle().getAttribute('aria-expanded'), 'false');
  assert.equal(document.querySelector('.teacher-filter-side .u027-create').getAttribute('href'), '/office/contests/new');
  assert.ok(document.querySelector('.teacher-list-detail-actions a[href^="/office/contests/"][href$="/edit"]'),'Edit stays in the faculty office');
  assert.ok(document.querySelector('.teacher-list-detail-actions a[href^="/office/results/activity/"]'),'Submissions open directly in the faculty office');
  await click(toggle());
  await pick(1);
  assert.equal(rows().length, 1);
  assert.match(text(), /Live joins/);
  await change('Class B');
  assert.match(text(), /No contests match/);
  await change('Joins practice');
  assert.match(text(), /Live joins/, 'Search still includes the summary');
  await click(toggle());
  assert.equal(input().value, 'Joins practice');
  assert.equal(document.querySelector('.u027-select-value').textContent, 'Live');
  await click(toggle());
  await click(document.querySelector('.u027-reset'));
  assert.equal(rows().length, 3);
  await click(rows()[0]);
  assert.match(document.querySelector('[data-location]').textContent,/^\/office\/contests\/.+\/edit$/,'Clicking the selected row opens the exam row');
  await api.act(async () => root.unmount()); root = null;

  await api.act(async () => { root = api.mount(document.getElementById('root'), 'assignments'); });
  await click(rows()[0]);
  assert.equal(document.querySelector('[data-location]').textContent,'/teacher/assignments/assignment/edit','Clicking the selected assignment opens its editor');
  await api.act(async () => root.unmount()); root = null;

  globalThis.__classes.reverse();
  await api.act(async () => { root = api.mount(document.getElementById('root'), 'classes'); });
  assert.equal(rows().length, 2);
  assert.equal(toggle().getAttribute('aria-expanded'),'false','Class filters use the same collapsed default as other pages');
  await click(toggle());
  await pick(1,2);
  assert.match(rows()[0].textContent,/Database systems/,'Ascending sort orders the whole filtered class list by code');
  await click(document.querySelector('.u027-reset'));
  assert.match(rows()[0].textContent,/SQL basics/,'Default order restores the original list without mutating it');

  assert.equal(toggle().getAttribute('aria-expanded'), 'true', 'Class filters can be opened and collapsed');
  await click(toggle());
  assert.equal(document.querySelector('.u027-drawer').getAttribute('aria-hidden'), 'true');
  await click(toggle());
  assert.equal(document.querySelector('.u027-drawer').getAttribute('aria-hidden'), 'false');
  await pick(1);
  assert.equal(rows().length, 1);
  assert.match(text(), /Database systems/);
  await change('SQL');
  assert.match(text(), /No classes match/);
  await click(document.querySelector('.u027-reset'));
  assert.equal(rows().length, 2);
  await change('basics');
  assert.equal(rows().length, 1);
  assert.match(document.querySelector('.teacher-list-detail-panel').textContent, /SQL basics/, 'The detail panel follows the filtered class');
  assert.equal(input().value, 'basics');
  assert.equal(document.querySelector('.u027-drawer').hasAttribute('inert'), false);
  await click(rows()[0]);
  assert.ok(document.querySelector('.teacher-class-detail-dialog-modal'),'Clicking the selected class opens its details');
  await api.act(async () => root.unmount()); root = null;
  globalThis.__examRooms=[
    {sessionId:'S1',roomId:'A',room:'LAB-A',title:'Morning SQL',opens:'2026-11-06T00:00:00Z',closes:'2099-11-06T02:00:00Z'},
    {sessionId:'S2',roomId:'B',room:'LAB-B',title:'Afternoon SQL',opens:'2099-11-06T06:00:00Z',closes:'2099-11-06T08:00:00Z'},
  ];
  globalThis.__roomPeople=[{studentId:'SV1',name:'Candidate A',email:'a@example.test',roomId:'A',locked:true,attemptStatus:'active',browsers:[]},{studentId:'SV2',name:'Candidate B',email:'b@example.test',roomId:'B',locked:false,attemptStatus:'not_started',browsers:[]}];
  await api.act(async () => {root=api.mount(document.getElementById('root'),'exams');});
  assert.equal(rows().length,2,'Exams list displays assigned sessions');
  assert.match(document.querySelector('.proctor-room-details').textContent,/LAB-A/,'First exam classes are selected automatically');
  assert.ok(document.querySelector('.proctor-open-dashboard-btn'),'Detail panel includes Chi tiết kỳ thi button');
  await click(rows()[1]);
  assert.match(document.querySelector('.proctor-room-details').textContent,/LAB-B/);
  await change('Morning');
  assert.equal(rows().length,1);
  assert.match(document.querySelector('.proctor-room-details').textContent,/LAB-A/,'Filtering selects the first remaining exam');


  await api.act(async () => root.unmount()); root = null;


  globalThis.__activities = [
    { id: 'one', title: 'SQL joins', classes: 'Class 1, Class 2', isContest: false, awaiting: 2, average: '80', submitted: '8/10' },
    { id: 'ten', title: 'Reviewed contest', classes: 'Class 10', isContest: true, examinationId:'exam-parent', awaiting: 0, average: '90', submitted: '10/10' },
    { id: 'contest', title: 'Pending contest', classes: 'Class 1', isContest: true, examinationId:'exam-parent', awaiting: 1, average: '75', submitted: '9/10' },
  ];
  globalThis.__activitiesRequest = new Promise(resolve => { resolveActivities = resolve; });
  await api.act(async () => { root = api.mount(document.getElementById('root'), 'results'); });
  assert.equal(toggle(), null, 'Results loading has no filter toggle');
  assert.equal(scope().querySelectorAll('.teacher-loading-filters .skeleton-line').length, 3);
  await api.act(async () => resolveActivities(globalThis.__activities));
  delete globalThis.__activitiesRequest;
  assert.equal(document.getElementById('results-panel-assignments').hidden,false,'Assignments are the default');
  assert.equal(scope().querySelector('.teacher-filter-actions .u027-toggle').getAttribute('aria-expanded'),'false');
  await click(scope().querySelector('.u027-toggle'));
  assert.equal(rows().length,1);
  assert.match(text(),/SQL joins/);
  assert.equal(scope().querySelector('thead th').textContent,'ID');
  assert.equal(scope().querySelectorAll('thead th').length,6);
  assert.ok(![...scope().querySelectorAll('thead th')].some(node=>node.textContent==='ACTIONS'),'The redundant actions column is removed');
  assert.equal(scope().querySelector('.teacher-result-id-link').getAttribute('href'),'/teacher/results/activity/one');
  assert.equal(scope().querySelector('.teacher-result-id-link').textContent,'1');
  await change('joins');
  await click(scope().querySelector('[data-results-kind=exams]'));
  assert.equal(rows().length,2);
  assert.equal(input().value,'','Exam search is independent');
  await click(scope().querySelector('.u027-toggle'));
  await pick(1);
  assert.equal(rows().length,1,'Class 1 does not include Class 10');
  assert.match(text(),/Pending contest/);
  await pick(2,1);
  assert.match(text(),/No results match/);
  await pick(1,1);
  assert.match(text(),/Pending contest/);
  await change('  pending  ');
  await click(scope().querySelector('[data-results-kind=assignments]'));
  assert.equal(input().value,'joins','Assignment filter survives switching tabs');
  assert.match(text(),/SQL joins/);
  await click(scope().querySelector('[data-results-kind=exams]'));
  assert.equal(input().value,'  pending  ','Exam filters are restored');
  assert.match(scope().querySelector('.teacher-list-detail-panel').textContent,/Pending contest/);
  await click(scope().querySelector('.u027-reset'));
  assert.equal(rows().length,2,'Reset never mixes assignment and exam results');
  assert.deepEqual([...scope().querySelectorAll('.u027-select-value')].map(node=>node.textContent),['Class','Review status','Descending']);
  const resultPath=scope().querySelector('.teacher-result-id-link').getAttribute('href');
  await click(rows()[0]);
  assert.equal(document.querySelector('[data-location]').textContent,resultPath,'Clicking the selected row opens result details');
  await api.act(async () => root.unmount()); root = null;

  await api.act(async () => { root = api.mount(document.getElementById('root'), 'submissions'); });
  assert.equal(toggle(), null, 'Submission filters have no toggle');
  assert.equal(document.querySelector('.u027-drawer').getAttribute('aria-hidden'), 'false');
  await pick(2);
  await pick(1, 1);
  await change('joins');
  assert.deepEqual(globalThis.__submissionFilters, {search:'joins',source:'Assignments',result:'Accepted'}, 'Display controls preserve server filter values');
  assert.equal(input().value, 'joins');
  assert.equal(document.querySelector('.u027-drawer').hasAttribute('inert'), false);
  await click([...document.querySelectorAll('.u027-chip')].find(node => node.textContent === 'Assignments'));
  assert.deepEqual(globalThis.__submissionFilters, {search:'joins',source:'',result:'Accepted'}, 'Clearing one chip preserves the other conditions');
  await click(document.querySelector('.u027-reset'));
  assert.deepEqual(globalThis.__submissionFilters, {search:'',source:'',result:''});
  assert.equal(document.querySelectorAll('.u027-chip').length, 0);
  await api.act(async () => root.unmount()); root = null;

  globalThis.__activities = [];
  await api.act(async () => { root = api.mount(document.getElementById('root'), 'contests'); });
  assert.ok(document.querySelector('a[href="/office/contests/new"]'), 'Empty contests still offer the create action');
  assert.equal(document.querySelectorAll('.teacher-contest-list-table th').length,6,'An empty library keeps the full table structure');
  assert.equal(document.querySelector('.teacher-empty-row').colSpan,6);
  assert.ok(document.querySelector('.teacher-filter-side .u027-toggle'),'The empty library keeps filters beside the create action');
  assert.ok(document.querySelector('.teacher-filter-side .teacher-list-detail-panel'),'The empty library keeps the detail panel');
  console.log('PASS: loading transitions, contest status mapping, academic terms, results class/type/review filters, exact classes, submissions server filters, individual chips/reset, retained hidden values and action placement.');
} finally {
  if (root) await api.act(async () => root.unmount());
  await unlink(file).catch(() => {});
  dom.window.close();
}
