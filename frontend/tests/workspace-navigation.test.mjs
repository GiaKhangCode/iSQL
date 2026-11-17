import assert from 'node:assert/strict';
import { build } from 'esbuild';
import { JSDOM } from 'jsdom';
import { writeFile, unlink } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { createRequire } from 'node:module';

const dom = new JSDOM('<div id="root"></div><button id="outside">Outside</button>', {url:'http://localhost/', pretendToBeVisual:true});
for (const key of ['window','document','HTMLElement','Element','Node','localStorage']) globalThis[key] = dom.window[key];
globalThis.IS_REACT_ACT_ENVIRONMENT = true;
globalThis.ResizeObserver = class { observe() {} disconnect() {} };
const output = await build({
  stdin:{contents:`
    import React, {useState} from 'react';
    import {WorkspaceNavigation} from './src/features/workspace/components/WorkspaceNavigation';
    import {ProblemQuestionNavigation} from './src/shared/components/ProblemQuestionNavigation';
    export {act, createElement} from 'react'; export {createRoot} from 'react-dom/client';
    const parts=['DDL','DML','SQL','TRIGGER'];
    const questionGroups=Object.fromEntries(parts.map(part=>[part,Array.from({length:part==='DDL'?4:6},(_,i)=>({id:part+i,name:part+' task '+(i+1)}))]));
    export function Harness({disabled=false}) {
      const [active,onChange]=useState('DDL');
      const [selected,select]=useState({DDL:'DDL1',DML:'DML0',SQL:'SQL3',TRIGGER:'TRIGGER5'});
      return <><ProblemQuestionNavigation header part={active} questions={questionGroups[active]} selectedId={selected[active]} onChange={()=>{}}/>
        <WorkspaceNavigation parts={parts} active={active} onChange={onChange} questionGroups={questionGroups}
        selectedQuestionIds={selected} disabled={disabled} isQuestionDone={(_,id)=>id==='DDL0' || id==='DML0'}
        onQuestionChange={(part,id)=>select(previous=>({...previous,[part]:id}))}/></>;
    }`,resolveDir:process.cwd(),loader:'tsx'},
  bundle:true,write:false,format:'cjs',platform:'node',jsx:'automatic',loader:{'.css':'empty'},logLevel:'silent',
});
const filename=join(tmpdir(),`sqluit-question-navigation-${process.pid}.cjs`);
let root;
try {
  await writeFile(filename,output.outputFiles[0].contents);
  const {act,createElement:h,createRoot,Harness}=createRequire(import.meta.url)(filename);
  root=createRoot(document.getElementById('root'));
  const render=disabled=>act(async()=>root.render(h(Harness,{disabled})));
  const click=button=>act(async()=>{assert.ok(button);assert.equal(button.disabled,false);button.click();});
  const active=()=>document.querySelector('.workspace-question-slide.is-open');
  const pills=()=>[...active().querySelectorAll('.workspace-question-pill')];
  const trigger=()=>active().querySelector('[role="combobox"]');
  const stage=part=>document.querySelector(`[aria-controls="workspace-stage-${part}"]`);
  const step=next=>document.querySelectorAll('.workspace-question-steppers button')[next?1:0];
  const labels=()=>pills().map(button=>button.textContent);
  const key=key=>act(async()=>trigger().dispatchEvent(new dom.window.KeyboardEvent('keydown',{key,bubbles:true})));
  const menu=()=>document.querySelector('.workspace-question-dropdown');
  await render(false);
  assert.equal(document.querySelector('.workspace-header-questions').inert,true,'The superseded hidden picker cannot take keyboard focus');
  assert.equal(document.querySelector('.workspace-header-questions').getAttribute('aria-hidden'),'true');
  assert.deepEqual(labels(),['Câu 1','Câu 2','Câu 3']);
  assert.equal(trigger().textContent,'Câu 2');
  assert.ok(pills()[0].classList.contains('done'),'Completed questions keep a green-border class');
  assert.equal(document.querySelectorAll('.workspace-question-stepper-btn').length,2,'Only one pair of previous/next buttons remains');
  assert.ok(!active().querySelector('.workspace-question-stepper-btn'),'Arrows do not interrupt the question strip');
  for (const collapsed of document.querySelectorAll('.workspace-question-slide:not(.is-open)')) {
    assert.equal(collapsed.getAttribute('aria-hidden'),'true');
    assert.equal(collapsed.firstElementChild.inert,true,'Collapsed sections cannot receive keyboard focus');
  }
  await click(stage('DML'));
  assert.deepEqual(labels(),['Câu 1','Câu 2'],'Each section starts numbering at one');
  assert.equal(step(false).disabled,true);
  await click(trigger());
  assert.equal(menu().parentElement,document.body,'The dropdown escapes the scrolling and clipped header');
  assert.equal(menu().querySelectorAll('[role="option"]').length,6,'The menu includes questions outside the visible neighbors');
  assert.deepEqual([...menu().querySelectorAll('.dropdown-item-num')].map(node=>node.textContent),Array.from({length:6},(_,i)=>`Câu ${i+1}`));
  assert.ok(menu().querySelector('[role="option"]').classList.contains('done'));
  await click(menu().querySelectorAll('[role="option"]')[4]);
  assert.equal(menu(),null);
  assert.equal(trigger().textContent,'Câu 5');
  assert.equal(document.activeElement,trigger(),'Choosing a new question restores focus to its new trigger');
  assert.deepEqual(labels(),['Câu 4','Câu 5','Câu 6']);
  await click(step(true));
  assert.equal(trigger().textContent,'Câu 6');
  assert.equal(step(true).disabled,true);
  await click(step(false));
  assert.equal(trigger().textContent,'Câu 5');
  await click(stage('SQL'));
  assert.deepEqual(labels(),['Câu 3','Câu 4','Câu 5']);
  await click(stage('DML'));
  assert.equal(trigger().textContent,'Câu 5','Switching sections remembers their selected question');
  await key('ArrowDown');
  assert.ok(menu());
  await key('Home');
  await key('Enter');
  assert.equal(trigger().textContent,'Câu 1');
  await key('End');
  await key('Enter');
  assert.equal(trigger().textContent,'Câu 6','Keyboard users can jump to every question');
  await click(trigger());
  await key('Escape');
  assert.equal(menu(),null);
  await click(trigger());
  await act(async()=>document.getElementById('outside').dispatchEvent(new dom.window.Event('pointerdown',{bubbles:true})));
  assert.equal(menu(),null);
  await click(trigger());
  await click(stage('TRIGGER'));
  assert.equal(menu(),null,'Changing sections closes the dropdown');
  assert.deepEqual(labels(),['Câu 5','Câu 6']);
  await click(stage('DML'));
  await click(trigger());
  await render(true);
  assert.equal(menu(),null,'Busy state closes the menu and disables question changes');
  assert.ok(pills().every(button=>button.disabled));
  assert.ok([...document.querySelectorAll('.workspace-question-steppers button')].every(button=>button.disabled));
  console.log('workspace question navigation tests passed');
} finally {
  if(root){const {act}=createRequire(import.meta.url)(filename);await act(async()=>root.unmount());}
  await unlink(filename).catch(()=>{});
  dom.window.close();
}
// Browser-oriented dependencies keep background handles open in this isolated DOM process.
process.exit(0);
