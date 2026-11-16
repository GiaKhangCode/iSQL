import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import ts from 'typescript';
const source = readFileSync(new URL('../src/shared/lib/markdown-richtext/markdownToolbar.ts', import.meta.url), 'utf8');
const {outputText} = ts.transpileModule(source, {compilerOptions:{module:ts.ModuleKind.ES2022}});
const {markdownToolbarEdit: edit} = await import(`data:text/javascript;base64,${Buffer.from(outputText).toString('base64')}`);
function apply(text, start, end, command, argument) {
  const change = edit(text, start, end, command, argument);
  return text.slice(0, change.from) + change.replacement + text.slice(change.to);
}
assert.equal(apply('hello world', 6, 11, 'bold'), 'hello **world**');
assert.equal(apply('hello', 0, 5, 'italic'), '*hello*');
assert.equal(apply('SQL', 0, 3, 'code'), '`SQL`');
assert.equal(apply('hi', 0, 2, 'underline'), '[u]hi[/u]');
assert.equal(apply('one\ntwo\nthree', 0, 8, 'insertOrderedList'), '1. one\n2. two\nthree');
assert.equal(apply('one\ntwo', 5, 5, 'insertUnorderedList'), 'one\n- two');
assert.equal(apply('- title', 0, 7, 'formatBlock', '<h3>'), '### title');
assert.equal(apply('link', 0, 4, 'createLink', 'https://example.com'), '[link](https://example.com)');
assert.equal(apply('hi', 2, 2, 'insertHorizontalRule'), 'hi\n\n---\n\n');
const caret = edit('hi', 1, 1, 'bold');
assert.equal(caret.selectionStart, 3);
assert.equal(caret.selectionEnd, 3);
console.log('Markdown toolbar tests passed');
