import assert from 'node:assert/strict';
import {build} from 'esbuild';
import {JSDOM} from 'jsdom';
import {writeFile,unlink} from 'node:fs/promises';
import {createRequire} from 'node:module';
import {join} from 'node:path';
import {tmpdir} from 'node:os';

const dom=new JSDOM('<div id="root"></div>',{pretendToBeVisual:true});
for(const key of ['window','document','HTMLElement','Element'])globalThis[key]=dom.window[key];
globalThis.IS_REACT_ACT_ENVIRONMENT=true;
const observers=[];
globalThis.ResizeObserver=class{constructor(callback){this.callback=callback;observers.push(this);}observe(){}disconnect(){}};
document.documentElement.style.setProperty('--header-height','64px');
let scroll=0,badgeHeight=0,reduced=false;
Object.defineProperty(window,'scrollY',{get:()=>scroll});
Object.defineProperty(window,'innerHeight',{value:800});
Object.defineProperty(document.documentElement,'scrollHeight',{get:()=>2600});
window.matchMedia=()=>({matches:reduced});
const scrollCalls=[];
window.scrollTo=options=>{scrollCalls.push(options);if(options.behavior==='auto')scroll=options.top;};
HTMLElement.prototype.getBoundingClientRect=function(){
  let top=0,left=0,width=0,height=0;
  if(this.dataset.section){top=Number(this.dataset.top)-scroll;width=600;height=700;}
  if(this.id==='nav'){top=80;left=20;width=200;height=500;}
  if(this.classList.contains('nav-button')){top=93+badgeHeight+Number(this.dataset.index)*40-document.getElementById('nav').scrollTop;left=33;width=174;height=36;}
  return {top,left,width,height,right:left+width,bottom:top+height,x:left,y:top,toJSON(){}};
};
Object.defineProperty(HTMLElement.prototype,'clientLeft',{get(){return this.id==='nav'?1:0;}});
Object.defineProperty(HTMLElement.prototype,'clientTop',{get(){return this.id==='nav'?1:0;}});
const result=await build({stdin:{contents:`
  import React from 'react';export {act,createElement} from 'react';export {createRoot} from 'react-dom/client';
  import {useSectionNavigation,useNavigationIndicator} from './src/shared/hooks/useSectionNavigation';
  export function Page(){const {activeSection,navigateToSection}=useSectionNavigation(['a','b','c']);
    const {navigationRef,activeItemRef,indicator}=useNavigationIndicator(activeSection);
    return <><nav id="nav" ref={navigationRef}><span id="indicator" data-x={indicator.x} data-y={indicator.y}/>{['a','b','c'].map((id,index)=><button className="nav-button" data-index={index} key={id} ref={activeSection===id?activeItemRef:undefined} aria-current={activeSection===id?'location':undefined} onClick={()=>navigateToSection(id)}>{id}</button>)}</nav>
      <output>{activeSection}</output>{['a','b','c'].map((id,index)=><section key={id} id={id} data-section data-top={[100,800,1600][index]}/>)}</>;
  }
`,resolveDir:process.cwd(),loader:'tsx'},bundle:true,write:false,platform:'node',format:'cjs',jsx:'automatic'});
const file=join(tmpdir(),`section-navigation-${process.pid}.cjs`);await writeFile(file,result.outputFiles[0].contents);
let root,act;
try{
  const api=createRequire(import.meta.url)(file);act=api.act;root=api.createRoot(document.getElementById('root'));
  const flush=()=>new Promise(resolve=>setTimeout(resolve,45));
  const active=()=>document.querySelector('output').textContent;
  const move=async value=>act(async()=>{scroll=value;window.dispatchEvent(new window.Event('scroll'));await flush();});
  await act(async()=>{root.render(api.createElement(api.Page));await flush();});
  assert.equal(active(),'a');
  assert.equal(document.querySelector('#indicator').dataset.x,'12','Indicator accounts for nav border and padding');
  await move(750);assert.equal(active(),'b','Heading activation uses the sticky-header offset');
  document.getElementById('nav').scrollTop=30;badgeHeight=20;
  await act(async()=>{observers.forEach(observer=>observer.callback());await flush();});
  assert.equal(document.querySelector('#indicator').dataset.y,'72','Scrolled nav and changing toolbar heights preserve alignment');
  await act(async()=>{document.querySelectorAll('.nav-button')[2].click();await flush();});
  assert.equal(scrollCalls.at(-1).top,1512,'Click navigation uses the same header offset as scroll tracking');
  await move(900);assert.equal(active(),'c','Intermediate sections do not steal the highlight during smooth scrolling');
  await move(1512);assert.equal(active(),'c');
  await act(async()=>{document.querySelectorAll('.nav-button')[0].click();await flush();scroll=900;window.dispatchEvent(new window.Event('wheel'));await flush();});
  assert.equal(active(),'b','User scrolling immediately interrupts a pending navigation');
  await move(1800);assert.equal(active(),'c','The final visible section stays active at the page bottom');
  document.getElementById('c').hidden=true;
  await act(async()=>{observers.forEach(observer=>observer.callback());await flush();});
  assert.equal(active(),'b','Hidden tab sections never become active');
  reduced=true;
  await act(async()=>{document.querySelectorAll('.nav-button')[0].click();await flush();});
  assert.equal(scrollCalls.at(-1).behavior,'auto');
  console.log('PASS: header offsets, scrolling, click locking, user interruption, bottom/hidden sections, indicator borders/scroll/resize and reduced motion.');
}finally{if(root)await act(async()=>root.unmount());await unlink(file);dom.window.close();}
process.exit(0);
