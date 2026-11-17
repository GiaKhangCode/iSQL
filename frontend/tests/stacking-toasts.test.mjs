import assert from "node:assert/strict";
import { JSDOM } from "jsdom";
import { build } from "esbuild";
import { writeFile, unlink } from "node:fs/promises";
import { createRequire } from "node:module";
import { tmpdir } from "node:os";
import { join } from "node:path";

const dom = new JSDOM('<div id="root"></div>', { url: "http://localhost", pretendToBeVisual: true });
for (const key of ["window", "document", "HTMLElement", "Element", "Node", "localStorage"]) globalThis[key] = dom.window[key];
globalThis.IS_REACT_ACT_ENVIRONMENT = true;
globalThis.ResizeObserver = class { observe() {} disconnect() {} };
HTMLElement.prototype.setPointerCapture = () => {};
let now = 0, sequence = 0;
const frames = new Map();
globalThis.requestAnimationFrame = callback => { frames.set(++sequence, callback); return sequence; };
globalThis.cancelAnimationFrame = id => frames.delete(id);
window.matchMedia = () => ({ matches: true, addEventListener() {}, removeEventListener() {} });
const require = createRequire(import.meta.url);
const output = await build({
  stdin: { contents: `
    export { createElement, act } from 'react';
    export { createRoot } from 'react-dom/client';
    export { Simulate } from 'react-dom/test-utils';
    export { ToastProvider, toast } from './src/shared/ui/StackingToasts';
    export { ToastHistoryBell } from './src/shared/ui/ToastHistoryBell';
    export { toastHistory } from './src/shared/ui/toastHistory';
  `, resolveDir: process.cwd(), loader: "tsx" },
  bundle: true, write: false, platform: "node", format: "cjs", jsx: "automatic", loader: { ".css": "empty" },
  define: { "import.meta.hot": "undefined" }, logLevel: "silent",
  plugins: [{ name: "native-react", setup(builder) {
    builder.onResolve({ filter: /^(react|react-dom)(\/.*)?$/ }, args => ({ path: require.resolve(args.path), external: true }));
  } }],
});
const filename = join(tmpdir(), `sqluit-toasts-${process.pid}.cjs`);
let root, api;
try {
  await writeFile(filename, output.outputFiles[0].contents);
  api = require(filename);
  const { createElement: h, act, createRoot, Simulate, ToastProvider, toast,ToastHistoryBell,toastHistory } = api;
  const advance = async milliseconds => {
    await act(async () => { now += milliseconds; const pending = [...frames.values()]; frames.clear(); pending.forEach(callback => callback(now)); });
  };
  const card = id => document.querySelector(`[data-toast-id="${id}"]`);
  const active = () => [...document.querySelectorAll('.u070-t:not(.leave)')];
  root = createRoot(document.getElementById("root"));
  await act(async () => root.render(h(ToastProvider, null, h(ToastHistoryBell,{userId:'teacher-test'}),h("input", { id: "draft", defaultValue: "Untouched SQL" }))));
  assert.equal(document.querySelector('.u070-zone'), null, 'Do not show a saved notification before a save');
  let saving;
  await act(async () => { saving = toast.show({ variant: 'loading', title: 'Saving…', description: 'Problem A' }); });
  await advance(0); await advance(10000);
  assert.ok(card(saving) && !card(saving).classList.contains('leave'), 'Loading waits for the actual operation');
  assert.equal(card(saving).getAttribute('aria-busy'), 'true');
  await act(async () => toast.update(saving, { variant: 'success', title: 'All changes saved' }));
  assert.equal(active().length, 1, 'A save updates its existing toast');
  assert.equal(card(saving).dataset.variant, 'success');
  assert.ok(document.querySelector('[role="status"]').textContent.includes('Đã lưu mọi thay đổi'));
  let second, third;
  await act(async () => {
    second = toast.show({ title: 'Assignment saved', variant: 'success' });
    third = toast.show({ title: 'Contest saved', variant: 'success' });
  });
  assert.equal(card(second).inert, true, 'Collapsed back cards do not receive focus');
  assert.equal(card(third).inert, false);
  assert.equal(card(second).style.getPropertyValue('--y'), '-14px');
  const zone = document.querySelector('.u070-zone');
  await act(async () => Simulate.pointerEnter(zone, { pointerType: 'mouse' }));
  assert.equal(card(second).inert, false, 'Hover fans out the stack');
  await advance(0); await advance(20000);
  assert.equal(active().length, 3, 'Hover pauses dismissal');
  await act(async () => Simulate.pointerLeave(zone, { pointerType: 'mouse' }));
  await advance(0); await advance(4400);
  assert.equal(active().length, 3, 'Only the newest toast consumes time');
  await advance(200);
  assert.ok(card(third).classList.contains('leave'));
  assert.ok(!card(second).classList.contains('leave'), 'Older toasts retain their lifetime');
  await act(async () => card(second).querySelector('button').focus());
  assert.equal(card(saving).inert, false, 'Keyboard focus expands and pauses the stack');
  await advance(10000);
  assert.ok(!card(second).classList.contains('leave'));
  await act(async () => Simulate.keyDown(card(second).querySelector('button'), { key: 'Escape' }));
  assert.ok(card(second).classList.contains('leave'), 'Escape dismisses the focused toast only');
  assert.equal(document.activeElement, zone, 'Dismissing a focused control retains a keyboard focus target');
  await act(async () => document.getElementById('draft').focus());
  assert.equal(document.getElementById('draft').value, 'Untouched SQL');

  let swipe;
  await act(async () => { swipe = toast.show({ title: 'Swipe me', duration: 0 }); });
  await act(async () => Simulate.pointerDown(card(swipe), { button: 0, clientX: 200, pointerId: 1 }));
  await act(async () => Simulate.pointerMove(card(swipe), { clientX: 320 }));
  await act(async () => Simulate.pointerCancel(card(swipe)));
  assert.ok(!card(swipe).classList.contains('leave'), 'A cancelled touch gesture does not dismiss');
  await act(async () => Simulate.pointerDown(card(swipe), { button: 0, clientX: 200, pointerId: 1 }));
  await act(async () => Simulate.pointerMove(card(swipe), { clientX: 80 }));
  await act(async () => Simulate.pointerUp(card(swipe)));
  assert.ok(card(swipe).classList.contains('side'), 'A horizontal swipe dismisses just that card');

  let failure, retries = 0;
  const dialog = document.createElement('dialog');
  await act(async () => { dialog.open = true; document.body.append(dialog); });
  await act(async () => { failure = toast.show({ variant: 'error', title: 'Save failed',description:'CSV row 1: missing student 23520001. Full details remain available.', duration: 0, action: { label: 'Retry', onClick: () => retries++ } }); });
  assert.ok(dialog.contains(card(failure)), 'Save errors stay above an open Save and exit dialog');
  assert.ok(document.querySelector('[role="alert"]').textContent.includes('Lưu thất bại'));
  await act(async () => dialog.remove());
  assert.ok(document.body.contains(card(failure)), 'Notifications survive closing the dialog');
  await advance(0); await advance(20000);
  assert.ok(!card(failure).classList.contains('leave'), 'A persistent error remains until acted on');
  await act(async () => card(failure).querySelector('.u070-act').click());
  assert.equal(retries, 1);
  assert.ok(card(failure).classList.contains('leave'));
  await act(async () => { toast.dismiss(saving); await new Promise(resolve => setTimeout(resolve, 470)); });
  assert.equal(active().length, 0);
  assert.equal(document.querySelector('.u070-zone'), null);
  assert.ok(toastHistory.snapshot().some(item=>item.id===failure),'Completed errors are archived');
  const size=toastHistory.snapshot().length;
  await act(async()=>toast.dismiss(failure));
  assert.equal(toastHistory.snapshot().length,size,'A toast is archived once');
  assert.equal(document.querySelector('.toast-history-count').textContent,String(size));
  await act(async()=>document.querySelector('.toast-history-trigger').click());
  assert.ok(document.querySelector('.toast-history-panel').textContent.includes('Full details remain available.'));
  assert.ok(document.querySelector('.toast-history-ring'),'A new log triggers the bell animation');
  const errorActions=()=>document.querySelectorAll('.toast-history-item.is-error .icon-button');
  await act(async()=>errorActions()[0].click());
  assert.equal(toastHistory.snapshot().find(item=>item.id===failure).read,true,'Mark read is stored');
  assert.equal(document.querySelector('.toast-history-count').textContent,String(size-1),'The bell counts unread logs');
  await act(async()=>errorActions()[1].click());
  assert.equal(toastHistory.snapshot().find(item=>item.id===failure).starred,true);
  await act(async()=>{toastHistory.setOwner('other-test');toastHistory.setOwner('teacher-test');});
  assert.equal(toastHistory.snapshot().find(item=>item.id===failure).starred,true,'Important state survives a reload of account history');
  assert.equal(toastHistory.snapshot().find(item=>item.id===failure).read,true,'Read state is also persisted');
  await act(async()=>document.querySelectorAll('.toast-history-tabs button')[1].click());
  assert.equal(document.querySelectorAll('.toast-history-item').length,1,'Important tab shows only starred logs');
  await act(async()=>errorActions()[2].click());
  await act(async()=>document.querySelectorAll('.toast-history-tabs button')[0].click());
  assert.ok(!toastHistory.snapshot().some(item=>item.id===failure));
  let crossAccount;
  await act(async()=>{crossAccount=toast.show({title:'Private old-account message'});toastHistory.setOwner('student-test');toast.dismiss(crossAccount);});
  assert.equal(toastHistory.snapshot().length,0,'Late old-account toasts do not enter the new account history');
  await act(async()=>toastHistory.setOwner('teacher-test'));
  assert.equal(toastHistory.snapshot().length,size-1,'Switching accounts restores its own persisted history');
  await act(async()=>document.querySelector('.toast-history-panel > header .text-button').click());
  assert.equal(toastHistory.snapshot().length,0);
  assert.equal(document.querySelector('.toast-history-count'),null);
  await act(async()=>{toastHistory.setOwner('student-test');toastHistory.setOwner('teacher-test');});
  assert.equal(toastHistory.snapshot().length,0,'Clearing history persists');
  console.log('PASS: loading-to-success, stacking, front-only lifetime, hover/focus pause, keyboard dismissal, swipe/cancel, persistent errors and retry.');
} finally {
  if (root) await api.act(async () => root.unmount());
  await unlink(filename).catch(() => {});
  dom.window.close();
}
