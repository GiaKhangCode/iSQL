import assert from "node:assert/strict";
import { JSDOM } from "jsdom";
import { build } from "esbuild";
import { writeFile, unlink } from "node:fs/promises";
import { createRequire } from "node:module";
import { tmpdir } from "node:os";
import { join } from "node:path";

const dom = new JSDOM('<div id="editor" contenteditable></div><p id="outside">Other field</p>');
for (const key of ["window", "document", "HTMLElement", "Element", "Node"]) globalThis[key] = dom.window[key];
const output = await build({ entryPoints: ['src/shared/lib/markdown-richtext/richTextFormatting.ts'], bundle: true, write: false, platform: 'node', format: 'cjs' });
const filename = join(tmpdir(), `sqluit-rich-format-${process.pid}.cjs`);
try {
  await writeFile(filename, output.outputFiles[0].contents);
  const { readInlineFormats, toggleInlineCode, withoutCaretMarkers } = createRequire(import.meta.url)(filename);
  const editor = document.getElementById('editor');
  const select = (node, start = 0, end = node.textContent.length) => {
    const range = document.createRange(); range.setStart(node, start); range.setEnd(node, end);
    window.getSelection().removeAllRanges(); window.getSelection().addRange(range);
    return range;
  };
  editor.innerHTML = '<p><strong>Bold</strong> plain <em>Italic</em> <code>SQL</code></p>';
  select(editor.querySelector('strong').firstChild);
  assert.deepEqual(readInlineFormats(editor), { bold: true, italic: false, underline: false, code: false });
  select(editor.querySelector('em').firstChild, 2, 2);
  assert.deepEqual(readInlineFormats(editor), { bold: false, italic: true, underline: false, code: false }, 'The caret reflects the format of its text');
  select(editor.querySelector('code').firstChild);
  assert.equal(readInlineFormats(editor).code, true);
  toggleInlineCode(editor);
  assert.equal(readInlineFormats(editor).code, false);
  assert.equal(editor.querySelector('code'), null, 'Pressing Code again removes its formatting');
  assert.equal(window.getSelection().toString(), 'SQL', 'Formatting retains the selected text');
  assert.equal(editor.textContent, 'Bold plain Italic SQL');

  editor.innerHTML = '<p><u>Underlined</u></p>';
  select(editor.querySelector('u').firstChild);
  assert.equal(readInlineFormats(editor).underline,true);

  editor.innerHTML = '<p><code>alpha beta gamma</code></p>';
  select(editor.querySelector('code').firstChild, 6, 10);
  toggleInlineCode(editor);
  assert.deepEqual([...editor.querySelectorAll('code')].map(node => node.textContent), ['alpha ', ' gamma']);
  assert.equal(editor.textContent, 'alpha beta gamma', 'Unformatting part of a code span retains both surrounding code spans');
  assert.equal(window.getSelection().toString(), 'beta');
  toggleInlineCode(editor);
  assert.equal(readInlineFormats(editor).code, true);
  assert.equal(editor.querySelector('code code'), null, 'Formatting never nests code wrappers');

  editor.innerHTML = '<p>hello</p>';
  select(editor.firstChild.firstChild, 5, 5);
  toggleInlineCode(editor);
  assert.equal(readInlineFormats(editor).code, true, 'Code can be activated before typing');
  assert.equal(withoutCaretMarkers(editor.textContent), 'hello');
  toggleInlineCode(editor);
  assert.equal(readInlineFormats(editor).code, false, 'Code can be turned off at the caret');
  assert.equal(editor.querySelector('code'), null);
  assert.equal(withoutCaretMarkers(editor.textContent), 'hello');

  editor.innerHTML = '<p><code><strong>keep bold</strong></code></p>';
  select(editor.querySelector('strong').firstChild);
  toggleInlineCode(editor);
  assert.equal(editor.querySelector('strong').textContent, 'keep bold', 'Code toggle preserves other text formatting');
  const before = editor.innerHTML;
  select(document.getElementById('outside').firstChild);
  assert.deepEqual(readInlineFormats(editor), { bold: false, italic: false, underline: false, code: false });
  toggleInlineCode(editor);
  assert.equal(editor.innerHTML, before, 'An editor cannot change a selection in another field');
  console.log('PASS: selection/caret format state, code on/off, partial selection, preserved text and other formatting, no nested code and editor isolation.');
} finally { await unlink(filename).catch(() => {}); dom.window.close(); }
