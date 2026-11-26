import assert from 'node:assert/strict';
import { build } from 'esbuild';
import { JSDOM } from 'jsdom';
import { writeFile, unlink } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { createRequire } from 'node:module';

const dom = new JSDOM('<div id="root"></div>', { url: 'http://localhost', pretendToBeVisual: true });
for (const key of ['window', 'document', 'HTMLElement', 'Element', 'Node']) globalThis[key] = dom.window[key];
globalThis.IS_REACT_ACT_ENVIRONMENT = true;
const observers = [];
globalThis.ResizeObserver = class { constructor(callback) { observers.push(callback); } observe() {} disconnect() {} };
let reduced = false;
const motionListeners = new Set();
window.matchMedia = () => ({ get matches() { return reduced; }, addEventListener: (_, listener) => motionListeners.add(listener), removeEventListener: (_, listener) => motionListeners.delete(listener) });
const animations = [];
HTMLElement.prototype.animate = function (keyframes) { const animation = { node: this, keyframes, cancelled: false, cancel() { this.cancelled = true; } }; animations.push(animation); return animation; };
Object.defineProperty(HTMLElement.prototype, 'offsetHeight', { get() { return 24; } });
Object.defineProperty(HTMLElement.prototype, 'offsetWidth', { get() { return this.getAttribute('role') === 'tab' ? 110 : 330; } });
Object.defineProperty(HTMLElement.prototype, 'offsetLeft', { get() { return this.getAttribute('role') === 'tab' ? [...this.parentElement.querySelectorAll('button')].indexOf(this) * 110 + 4 : 0; } });
const output = await build({
  stdin: { contents: `
    import React, {useState} from 'react';
    import {WorkspacePaneTabs, WorkspacePanePanel} from './src/features/workspace/components/WorkspacePaneTabs';
    export {act, createElement} from 'react'; export {createRoot} from 'react-dom/client';
    export function Harness() {
      const [value, change] = useState('Description');
      const [draft, setDraft] = useState('SELECT 1');
      return <><WorkspacePaneTabs value={value} onChange={change} prefix="check"/>
        <WorkspacePanePanel value={value} prefix="check"><h1>{value}</h1><p>Details for {value}</p></WorkspacePanePanel>
        <textarea aria-label="SQL draft" value={draft} onChange={e=>setDraft(e.target.value)}/></>;
    }`, resolveDir: process.cwd(), loader: 'tsx' },
  bundle: true, write: false, format: 'cjs', platform: 'node', jsx: 'automatic', loader: { '.css': 'empty' }, logLevel: 'silent',
});
const filename = join(tmpdir(), `sqluit-pane-tabs-${process.pid}.cjs`);
let root;
try {
  await writeFile(filename, output.outputFiles[0].contents);
  const {act, createElement, createRoot, Harness} = createRequire(import.meta.url)(filename);
  root = createRoot(document.getElementById('root'));
  await act(async () => root.render(createElement(Harness)));
  const tabs = () => [...document.querySelectorAll('[role="tab"]')];
  const panel = () => document.querySelector('[role="tabpanel"]');
  const change = async index => act(async () => tabs()[index].click());
  assert.equal(animations.length, 0, 'Initial content should be visible immediately');
  await act(async () => observers.forEach(callback => callback()));
  assert.equal(document.querySelector('.u039-tabs').dataset.ready, 'false');
  await act(async () => { panel().scrollTop = 120; panel().dispatchEvent(new dom.window.Event('scroll')); });
  await change(2);
  assert.equal(panel().scrollTop, 0, 'A new pane starts at the top');
  assert.equal(document.querySelector('.u039-tabs').dataset.ready, 'true', 'Tab changes animate after the initial resize observation');
  assert.equal(panel().getAttribute('aria-labelledby'), tabs()[2].id);
  assert.equal(document.getElementById(tabs()[2].getAttribute('aria-controls')), panel());
  assert.ok(animations.find(animation => animation.keyframes[0].transform)?.keyframes[0].transform.includes('(20px)'), 'Moving right enters from the right');
  const outgoingAnimations = [...animations];
  await change(0);
  assert.equal(panel().scrollTop, 120, 'Returning to a pane restores its reading position');
  assert.ok(outgoingAnimations.every(animation => animation.cancelled), 'Rapid tab changes cancel the previous entrance');
  assert.ok(animations.findLast(animation => animation.keyframes[0].transform)?.keyframes[0].transform.includes('(-20px)'), 'Moving left enters from the left');
  reduced = true;
  await act(async () => motionListeners.forEach(listener => listener()));
  assert.ok(animations.every(animation => animation.cancelled), 'Enabling reduced motion stops every in-flight animation');
  const previousCount = animations.length;
  await change(1);
  assert.equal(animations.length, previousCount, 'Reduced motion shows the selected pane without animating it');
  const input = document.querySelector('textarea');
  assert.equal(input.value, 'SELECT 1');
  assert.equal(document.querySelectorAll('textarea').length, 1, 'Panel transitions never clone the SQL editor');
  await act(async () => tabs()[1].dispatchEvent(new dom.window.KeyboardEvent('keydown', {key:'End',bubbles:true})));
  assert.equal(document.activeElement, tabs()[2]);
  assert.equal(tabs().filter(tab => tab.tabIndex === 0).length, 1);
  await act(async () => root.unmount()); root = null;
  assert.equal(motionListeners.size, 0, 'Unmount must clean up motion listeners');
  console.log('PASS: sliding pill initialization, direction, fast switching, scroll restoration, keyboard/ARIA, reduced motion and draft preservation.');
} finally { if (root) root.unmount(); await unlink(filename); dom.window.close(); }
process.exit(0);
