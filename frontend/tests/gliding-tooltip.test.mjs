import assert from "node:assert/strict";
import { JSDOM } from "jsdom";
import { build } from "esbuild";
import { writeFile, unlink } from "node:fs/promises";
import { createRequire } from "node:module";
import { tmpdir } from "node:os";
import { join } from "node:path";

const dom = new JSDOM('<div id="root"></div>', { url: "http://localhost", pretendToBeVisual: true });
for (const key of ["window", "document", "HTMLElement", "Element", "Node"]) globalThis[key] = dom.window[key];
globalThis.IS_REACT_ACT_ENVIRONMENT = true;
globalThis.ResizeObserver = class { observe() {} disconnect() {} };
const rects = { bold: { left: 0, top: 50, width: 38, height: 38 }, italic: { left: 50, top: 50, width: 38, height: 38 } };
HTMLElement.prototype.getBoundingClientRect = function () {
  const { left = 0, top = 50, width = 350, height = 38 } = rects[this.id] ?? {};
  return { left, top, width, height, right: left + width, bottom: top + height, x: left, y: top };
};
Object.defineProperty(HTMLElement.prototype, 'offsetWidth', { get() { return this.classList.contains('u076-tip') ? 200 : 38; } });
Object.defineProperty(HTMLElement.prototype, 'offsetHeight', { get() { return this.classList.contains('u076-tip') ? 34 : 38; } });
const require = createRequire(import.meta.url);
const output = await build({
  stdin: { contents: `
    export { createElement, act } from 'react';
    export { createRoot } from 'react-dom/client';
    export { Simulate } from 'react-dom/test-utils';
    export { GlidingTooltipGroup } from './src/shared/ui/GlidingTooltip';
  `, resolveDir: process.cwd(), loader: "tsx" },
  bundle: true, write: false, platform: "node", format: "cjs", jsx: "automatic", loader: { ".css": "empty" }, logLevel: "silent",
  plugins: [{ name: "native-react", setup(builder) {
    builder.onResolve({ filter: /^(react|react-dom)(\/.*)?$/ }, args => ({ path: require.resolve(args.path), external: true }));
  } }],
});
const filename = join(tmpdir(), `sqluit-tooltip-${process.pid}.cjs`);
let root, api;
try {
  await writeFile(filename, output.outputFiles[0].contents);
  api = require(filename);
  const { createElement: h, act, createRoot, Simulate, GlidingTooltipGroup } = api;
  root = createRoot(document.getElementById("root"));
  let clicks = 0, escaped = 0;
  const render = side => h('div', { onKeyDown: () => escaped++ }, h(GlidingTooltipGroup, { label: 'Formatting', side },
    h('button', { id: 'bold', 'data-tooltip': 'Bold', 'data-shortcut': 'Ctrl+B', 'aria-label': 'Bold', 'aria-describedby': 'existing-help', onClick: () => clicks++ }, 'B'),
    h('button', { id: 'italic', 'data-tooltip': 'Italic', 'aria-label': 'Italic' }, 'I'),
    h('button', { id: 'disabled', 'data-tooltip': 'Disabled', disabled: true }, 'X'),
  ));
  await act(async () => root.render(render('below')));
  const tip = () => document.querySelector('[role="tooltip"]');
  const bold = document.getElementById('bold'), italic = document.getElementById('italic');
  const hover = (node, relatedTarget = null) => Simulate.pointerOver(node, { pointerType: 'mouse', relatedTarget });
  const wait = milliseconds => act(async () => { await new Promise(resolve => setTimeout(resolve, milliseconds)); });
  await act(async () => hover(bold));
  assert.equal(tip().getAttribute('aria-hidden'), 'true', 'First mouse hover waits before appearing');
  await wait(470);
  assert.equal(tip().getAttribute('aria-hidden'), 'false');
  assert.equal(tip().textContent, 'BoldCtrl+B');
  assert.ok(bold.getAttribute('aria-describedby').includes('existing-help'), 'Retain existing accessibility descriptions');
  assert.ok(bold.getAttribute('aria-describedby').includes(tip().id));
  assert.equal(tip().style.getPropertyValue('--x'), '8px');
  assert.equal(tip().style.getPropertyValue('--ax'), '11px', 'The arrow stays aligned when the box shifts at the left edge');
  await act(async () => { Simulate.pointerOut(bold, { pointerType: 'mouse', relatedTarget: italic }); hover(italic, bold); });
  assert.equal(tip().textContent, 'Italic', 'A warm tooltip moves immediately');
  assert.ok(tip().classList.contains('glide'));
  assert.equal(bold.getAttribute('aria-describedby'), 'existing-help');
  await act(async () => Simulate.pointerOut(italic, { pointerType: 'mouse', relatedTarget: null }));
  await act(async () => hover(italic));
  await wait(100);
  assert.equal(tip().getAttribute('aria-hidden'), 'false', 'Returning before the hide delay keeps the tooltip open');
  await act(async () => Simulate.keyDown(italic, { key: 'Escape' }));
  assert.equal(tip().getAttribute('aria-hidden'), 'true');
  assert.equal(escaped, 0, 'Escape hides the tooltip before reaching a parent dialog');
  assert.equal(italic.hasAttribute('aria-describedby'), false);
  await act(async () => bold.focus());
  assert.equal(tip().getAttribute('aria-hidden'), 'false', 'Keyboard focus shows the tooltip immediately');
  await act(async () => bold.click());
  assert.equal(clicks, 1, 'Formatting actions are preserved');
  assert.equal(tip().getAttribute('aria-hidden'), 'true');
  assert.equal(bold.getAttribute('aria-describedby'), 'existing-help');
  await act(async () => hover(document.getElementById('disabled')));
  assert.equal(tip().getAttribute('aria-hidden'), 'true', 'Disabled controls do not show actionable hints');
  await act(async () => Simulate.pointerOver(italic, { pointerType: 'touch' }));
  assert.equal(tip().getAttribute('aria-hidden'), 'true', 'Touch does not create sticky hover tooltips');
  rects.italic = { left: 312, top: 180, width: 38, height: 38 };
  window.innerHeight = 240;
  await act(async () => italic.focus());
  assert.ok(tip().classList.contains('above'), 'Flip above the button at the bottom of the viewport');
  assert.equal(tip().style.getPropertyValue('--x'), '142px');
  assert.equal(tip().style.getPropertyValue('--ax'), '189px');
  await act(async () => root.unmount()); root = null;
  assert.equal(bold.getAttribute('aria-describedby'), 'existing-help', 'Unmount cleans up only its own description');
  console.log('PASS: cold delay, warm glide, edge arrow alignment, hover return, focus/Escape, preserved actions/descriptions, touch, disabled controls and viewport flipping.');
} finally {
  if (root) await api.act(async () => root.unmount());
  await unlink(filename).catch(() => {});
  dom.window.close();
}
