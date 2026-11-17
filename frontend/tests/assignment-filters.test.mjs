import assert from "node:assert/strict";
import { JSDOM } from "jsdom";
import { build } from "esbuild";
import { writeFile, unlink } from "node:fs/promises";
import { createRequire } from "node:module";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { auditUi } from "../scripts/i18n-audit.mjs";

const dom = new JSDOM('<div id="root"></div><div id="actions-root"></div>', { url: "http://localhost", pretendToBeVisual: true });
for (const key of ["window", "document", "HTMLElement", "Element", "Node"]) globalThis[key] = dom.window[key];
globalThis.IS_REACT_ACT_ENVIRONMENT = true;
const require = createRequire(import.meta.url);
const output = await build({
  stdin: { contents: `
    export { createElement, act, useState } from 'react';
    export { createRoot } from 'react-dom/client';
    export { Simulate } from 'react-dom/test-utils';
    export * from './src/features/teacher/filters/AssignmentFilters';
    export { catalogs } from './src/i18n';
  `, resolveDir: process.cwd(), loader: "tsx" },
  bundle: true, write: false, platform: "node", format: "cjs", jsx: "automatic", loader: { ".css": "empty" }, logLevel: "silent",
  plugins: [{ name: "native-react", setup(builder) {
    builder.onResolve({ filter: /^(react|react-dom)(\/.*)?$/ }, args => ({ path: require.resolve(args.path), external: true }));
  } }],
});
const filename = join(tmpdir(), `sqluit-assignment-filters-${process.pid}.cjs`);
let root, api;
try {
  await writeFile(filename, output.outputFiles[0].contents);
  api = require(filename);
  const catalog = Object.assign({}, ...Object.values(api.catalogs.vi));
  assert.deepEqual(auditUi(catalog).errors.filter(error => /(?:AssignmentFilter(?:s|Select)|TeacherListFilters)\.tsx/.test(error)), [], 'New filters follow translation and language-motion conventions');
  const { createElement: h, act, createRoot, useState, Simulate, AssignmentFilters, assignmentClassNames, matchesAssignmentSearch } = api;
  const assignments = [
    { id: "1", title: "SQL joins", classes: "Class 1, Class 2", status: "Open" },
    { id: "2", title: "SQL grouping", classes: "Class 10", status: "Draft" },
    { id: "3", title: "<script>SQL</script>", classes: "Class 1", status: "Closed" },
  ];
  assert.deepEqual(assignmentClassNames("Class 1,Class 2, "), ["Class 1", "Class 2"]);
  assert.equal(assignmentClassNames("Class 10").includes("Class 1"), false);
  assert.equal(matchesAssignmentSearch(assignments[0], "  sQl JOINS  "), true);
  assert.equal(matchesAssignmentSearch(assignments[0], "class 2"), true);
  let created = 0;
  function Harness() {
    const [search, onSearchChange] = useState("");
    const [classFilter, onClassChange] = useState("All classes");
    const [statusFilter, onStatusChange] = useState("All statuses");
    return h(AssignmentFilters, { assignments, search, onSearchChange, classFilter, onClassChange, statusFilter, onStatusChange, onCreate: () => created++, actionsTarget: document.getElementById('actions-root') });
  }
  root = createRoot(document.getElementById("root"));
  await act(async () => root.render(h(Harness)));
  assert.equal(document.querySelector('#root .u027-actions'), null, 'Actions do not take up vertical space above the left table');
  assert.deepEqual([...document.querySelector('#actions-root .u027-actions').children].map(node => node.classList.contains('u027-toggle') ? 'filters' : 'create'), ['filters', 'create'], 'Filters stay to the left of New assignment in the side column');
  const input = () => document.querySelector('input[role="combobox"]');
  const trigger = () => document.querySelector('.u027-toggle');
  const options = () => [...document.querySelectorAll('[role="option"]')];
  const key = async (value, extra = {}) => act(async () => Simulate.keyDown(input(), { key: value, nativeEvent: {}, ...extra }));
  const change = async value => act(async () => Simulate.change(input(), { target: { value } }));
  assert.equal(trigger().getAttribute('aria-expanded'), 'false', 'Filters start hidden');
  assert.equal(document.querySelector('.u027-drawer').hasAttribute('inert'), true, 'Hidden filters cannot receive keyboard focus');
  assert.ok(document.querySelector('.u027-create'), 'Create action is always present');
  assert.equal(document.querySelector('.u027-count'), null, 'No result count above the table');
  await act(async () => trigger().click());
  assert.equal(document.querySelector('.u027-drawer').hasAttribute('inert'), false);
  await act(async () => input().focus());
  assert.equal(input().getAttribute('aria-expanded'), 'true');
  await change('SQL');
  assert.equal(options().length, 3);
  assert.equal(document.querySelectorAll('mark').length, 3);
  assert.equal(document.querySelectorAll('script').length, 0, "Titles render as safe text");
  await key('ArrowUp');
  assert.equal(input().getAttribute('aria-activedescendant'), options()[2].id);
  await key('ArrowDown');
  assert.equal(input().getAttribute('aria-activedescendant'), options()[0].id);
  await key('Enter', { nativeEvent: { isComposing: true } });
  assert.equal(input().value, 'SQL', "IME confirmation must not choose a suggestion");
  await key('Enter');
  assert.equal(input().value, 'SQL joins');
  assert.equal(input().getAttribute('aria-expanded'), 'false');
  await key('Escape');
  assert.equal(document.activeElement, trigger());
  assert.equal(input().value, 'SQL joins', "Closing keeps the applied search");
  await act(async () => input().focus());
  assert.equal(document.querySelector('.u027-recent button').textContent, 'SQL joins');
  await act(async () => document.querySelector('.u027-close').click());
  const selects = document.querySelectorAll('.u027-select');
  const selectKey = (index, key) => act(async () => Simulate.keyDown(selects[index], { key, nativeEvent: {} }));
  async function pick(index, optionIndex) {
    await act(async () => { selects[index].focus(); selects[index].click(); });
    assert.equal(document.querySelector('.u027-select-popover').parentElement, document.body, 'Menus escape the scrolling table');
    await selectKey(index, 'Home');
    for (let step = 0; step < optionIndex; step++) await selectKey(index, 'ArrowDown');
    await selectKey(index, 'Enter');
    assert.equal(selects[index].getAttribute('aria-expanded'), 'false');
    assert.equal(document.activeElement, selects[index], 'Selecting keeps keyboard focus on the control');
  }
  await pick(0, 1);
  await act(async () => input().focus());
  assert.deepEqual(options().map(item => item.querySelector('b').textContent), ['SQL joins', '<script>SQL</script>']);
  await pick(1, 3);
  assert.equal(options().length, 1, "Suggestions honor class and status together");
  await selectKey(0, 'ArrowDown');
  await selectKey(0, 'End');
  await selectKey(0, 'Escape');
  assert.equal(selects[0].querySelector('.u027-select-value').textContent, 'Class 1', 'Escape cancels an unconfirmed option');
  await selectKey(0, 'ArrowDown');
  await selectKey(0, 'Tab');
  assert.equal(document.querySelector('.u027-select-popover'), null, 'Tab closes the menu without trapping focus');
  await selectKey(0, 'ArrowDown');
  await act(async () => document.body.dispatchEvent(new dom.window.MouseEvent('pointerdown', { bubbles: true })));
  assert.equal(document.querySelector('.u027-select-popover'), null, 'Clicking outside dismisses the menu');
  await change('not found');
  assert.equal(options().length, 0);
  assert.equal(input().hasAttribute('aria-activedescendant'), false);
  assert.ok(document.querySelector('.u027-empty'));
  await act(async () => document.querySelector('.u027-reset').click());
  assert.equal(input().value, '');
  assert.equal(selects[0].querySelector('.u027-select-value').textContent, 'Lớp học');
  assert.equal(selects[1].querySelector('.u027-select-value').textContent, 'Trạng thái');
  await change('SQL');
  await selectKey(0, 'ArrowDown');
  await act(async () => trigger().click());
  assert.equal(trigger().getAttribute('aria-expanded'), 'false');
  assert.equal(document.querySelector('.u027-drawer').hasAttribute('inert'), true);
  assert.equal(document.querySelector('.u027-select-popover'), null, 'Hiding filters also dismisses their menus');
  assert.equal(input().value, 'SQL', 'Hiding filters preserves their values');
  assert.equal(document.querySelector('.u027-filter-count').textContent, '1', 'The toggle indicates applied filters while collapsed');
  await act(async () => trigger().click());
  assert.equal(input().value, 'SQL');
  await act(async () => document.querySelector('.u027-create').click());
  assert.equal(created, 1);
  console.log('PASS: hidden drawer and focus exclusion, preserved filters, always-visible create action, keyboard, IME, highlighting, safe titles, recents, exact classes, combined filters, empty results, reset and translation audit.');
} finally {
  if (root) await api.act(async () => root.unmount());
  await unlink(filename).catch(() => {});
  dom.window.close();
}
