import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';
import { build } from 'esbuild';
import { createRequire } from 'node:module';
import { writeFile, unlink } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const dom = new JSDOM('<div id="root"></div>', { url: 'http://localhost' });
for (const key of ['window', 'document', 'HTMLElement', 'Node']) globalThis[key] = dom.window[key];
HTMLElement.prototype.scrollTo = () => {};
globalThis.IS_REACT_ACT_ENVIRONMENT = true;
globalThis.__problems = [
  { id: 'a', number: '010', title: 'Ten', difficulty: 'Hard', createdAt: '2026-10-18T01:00:00', practiceListed: true },
  { id: 'b', number: '002', title: 'Two', difficulty: 'Easy', createdAt: null },
  { id: 'c', number: '001', title: 'One', difficulty: 'Medium', createdAt: '2026-10-17T01:00:00Z' },
];
const require = createRequire(import.meta.url);
const result = await build({
  stdin: { contents: `import React, { act } from 'react';
    import { createRoot } from 'react-dom/client';
    import { Simulate } from 'react-dom/test-utils';
    import { MemoryRouter, useLocation } from 'react-router-dom';
    import { ProblemLibraryPage } from './src/features/teacher/TeacherLandingPages';
    function Location(){return <span data-location>{useLocation().pathname}</span>;}
    export { act };
    export function mount(node) { const root=createRoot(node); root.render(<MemoryRouter><Location/><ProblemLibraryPage/></MemoryRouter>); return root; }
    export function change(node,value) { node.value=value; Simulate.change(node); }
    export function check(node,checked) { node.checked=checked; Simulate.change(node); }`, resolveDir: process.cwd(), loader: 'tsx' },
  bundle: true, write: false, platform: 'node', format: 'cjs', jsx: 'automatic', logLevel: 'silent',
  loader: { '.css': 'empty' },
  plugins: [{ name: 'problem-library', setup(builder) {
    builder.onResolve({ filter: /^(react|react-dom|react-router-dom)(\/.*)?$/ }, args => ({ path: require.resolve(args.path), external: true }));
    builder.onLoad({ filter: /[\\/]teacherService\.ts$/ }, () => ({ contents: 'export const teacherService={getAllProblems:async()=>globalThis.__problems};', loader:'js' }));
    builder.onLoad({ filter: /[\\/]LanguageContext\.tsx$/ }, () => ({ contents: `export const useLanguage=()=>({locale:'vi-VN',t:(text,values={})=>text.replace(/\{(\w+)\}/g,(_,key)=>values[key]??'')});`, loader:'js' }));
    builder.onLoad({ filter: /[\\/]TranslatedText\.tsx$/ }, () => ({ contents: 'export function TranslatedText({children}) {return <>{children}</>;}', loader:'jsx' }));
    builder.onLoad({ filter: /[\\/]LanguageSwitch\.tsx$/ }, () => ({ contents: 'export function LanguageText({text}) {return <>{text}</>;}', loader:'jsx' }));
  }}],
});
const file = join(tmpdir(), `problem-library-${process.pid}.cjs`);
let root;
try {
  await writeFile(file,result.outputFiles[0].contents);
  const api=require(file);
  await api.act(async()=>{ root=api.mount(document.getElementById('root')); });
  const rows=()=>[...document.querySelectorAll('tbody tr')];
  const ids=()=>rows().map(row=>row.cells[0].textContent);
  const click=async(node)=>api.act(async()=>node.click());
  const button=(text)=>[...document.querySelectorAll('button')].find(node=>node.textContent===text);
  assert.equal(document.querySelectorAll('thead th').length,3);
  assert.deepEqual(ids(),['010','002','001'],'The default is descending numeric ID order');
  assert.equal(document.querySelectorAll('thead button').length,0,'Column headings no longer contain filters');
  assert.equal(document.querySelectorAll('select').length,0);
  assert.equal(rows()[1].cells[2].textContent,'—');
  assert.match(rows()[0].cells[2].textContent,/18\/10\/2026/);
  assert.doesNotMatch(document.body.textContent,/Difficulty|Easy|Medium|Hard/,'Legacy problem difficulty is never displayed');
  assert.equal(document.querySelector('.u027-drawer').getAttribute('aria-hidden'),'true');
  await click(document.querySelector('.u027-toggle'));
  await click(document.querySelector('.u027-select'));
  await click([...document.querySelectorAll('[role="option"]')].find(node=>node.textContent==='Ascending'));
  assert.deepEqual(ids(),['001','002','010']);
  const search=()=>document.querySelector('.u027-input');
  assert.equal(document.querySelector('[inputmode="numeric"]'),null,'The duplicate ID field is removed');
  await api.act(async()=>api.change(search(),'002'));
  assert.deepEqual(ids(),['002'],'The main search finds numeric IDs');
  await api.act(async()=>api.change(search(),'Ten'));
  assert.deepEqual(ids(),['010']);
  await api.act(async()=>api.change(document.querySelectorAll('.list-date-popover input')[0],'2026-10-19'));
  assert.match(document.querySelector('tbody').textContent,/No problems match/,'Date and search filters combine');
  await click(button('Reset filters'));
  assert.deepEqual(ids(),['010','002','001'],'Reset restores descending ID order and clears date/search');
  await api.act(async()=>api.change(search(),'Ten'));
  assert.deepEqual(ids(),['010']);
  assert.equal(document.querySelector('tbody tr').getAttribute('aria-selected'),'true');
  await click(document.querySelector('tbody tr'));
  assert.match(document.querySelector('[data-location]').textContent,/^\/teacher\/problems\/.+\/edit$/,'Clicking the selected problem opens its editor');
  console.log('Problem library: separate filters, no difficulty, numeric ID matching/sorting, combined search/reset, dates and selected-row semantics passed.');
  await api.act(async()=>root.unmount());
} finally { await unlink(file).catch(()=>{}); }
