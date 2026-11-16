import assert from 'node:assert/strict';
import {build} from 'esbuild';
import {JSDOM} from 'jsdom';
import {writeFile,unlink} from 'node:fs/promises';
import {createRequire} from 'node:module';
import {join} from 'node:path';
import {tmpdir} from 'node:os';
const dom=new JSDOM('<div id="root"></div>',{url:'http://localhost',pretendToBeVisual:true});
for(const key of ['window','document','HTMLElement','Element','Node'])globalThis[key]=dom.window[key];
globalThis.IS_REACT_ACT_ENVIRONMENT=true;
const built=await build({stdin:{contents:`
 import React,{useState} from 'react';export {act} from 'react';export {createRoot} from 'react-dom/client';
 import {Select} from './src/shared/ui/Select';
 export const page=()=> <Page/>; function Page(){const [value,setValue]=useState('');return <dialog open><form><label htmlFor='choice'>Choose session</label><Select id='choice' name='session' required value={value} onChange={event=>setValue(event.target.value)}><option value='' disabled>Choose…</option><><option value='1'>Session 1</option><option value='2'>Session 2</option></></Select><output>{value}</output></form></dialog>}
`,resolveDir:process.cwd(),loader:'tsx'},bundle:true,write:false,platform:'node',format:'cjs',jsx:'automatic',loader:{'.css':'empty'},logLevel:'silent'});
const file=join(tmpdir(),`select-${process.pid}.cjs`);await writeFile(file,built.outputFiles[0].contents);
let root,api;
try{
 api=createRequire(import.meta.url)(file);root=api.createRoot(document.getElementById('root'));
 await api.act(async()=>root.render(api.page()));
 const trigger=document.querySelector('[role=combobox]');
 assert.equal(trigger.getAttribute('aria-label'),'Choose session');
 assert.equal(trigger.getAttribute('aria-required'),'true');
 assert.equal(document.querySelector('form').checkValidity(),false);
 await api.act(async()=>trigger.click());
 assert.ok(document.querySelector('dialog [role=listbox]'),'The dropdown stays inside the modal top layer');
 assert.equal(document.querySelector('[role=option]').getAttribute('aria-disabled'),'true');
 await api.act(async()=>document.querySelectorAll('[role=option]')[2].click());
 assert.equal(document.querySelector('output').textContent,'2');
 assert.equal(new dom.window.FormData(document.querySelector('form')).get('session'),'2');
 assert.equal(document.querySelector('form').checkValidity(),true);
 assert.equal(trigger.getAttribute('aria-expanded'),'false');
 assert.equal(document.activeElement,trigger);
 assert.ok(trigger.textContent.includes('Session 2'));
 console.log('PASS: shared dropdown labels, fragments, disabled placeholder, modal portal, real change events, form value/validation and focus.');
}finally{if(root)await api.act(async()=>root.unmount());await unlink(file);dom.window.close();}
process.exit(0);
