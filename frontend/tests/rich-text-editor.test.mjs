import assert from "node:assert/strict";
import { JSDOM } from "jsdom";
import { build } from "esbuild";
import { writeFile, unlink } from "node:fs/promises";
import { createRequire } from "node:module";
import { tmpdir } from "node:os";
import { join } from "node:path";

const dom = new JSDOM('<div id="root"></div><div id="scratch" contenteditable></div>', { url:"http://localhost" });
for (const key of ["window", "document", "HTMLElement", "Element", "Node", "NodeFilter", "DOMParser", "localStorage"]) globalThis[key] = dom.window[key];
globalThis.IS_REACT_ACT_ENVIRONMENT = true;
const nested = '[font-size=11][font-size=10]Đoạn một.\n\nĐoạn hai **in đậm**.\n\nĐoạn ba.[/font-size][/font-size]';
globalThis.__initialRichText = nested;
const bundle = await build({
  stdin: { contents: `
    import React, { act, useState } from 'react';
    import { createRoot } from 'react-dom/client';
    import { renderToStaticMarkup } from 'react-dom/server';
    import { ProblemRichTextEditor } from './src/features/teacher/problem-editor/ProblemRichTextEditor';
    import { ProblemMarkdown } from './src/shared/components/ProblemMarkdown';
    export * from './src/shared/lib/markdown-richtext/problemRichText';
    export { adjustSelectedFontSize } from './src/shared/lib/markdown-richtext/richTextFormatting';
    export {setParagraphSpacing} from './src/shared/lib/markdown-richtext/richTextLayout';
    let current;
    function Harness() {
      const [value,setValue] = useState(globalThis.__initialRichText);
      current=value;
      return <ProblemRichTextEditor value={value} label="Statement" onChange={setValue} onOpenExpanded={()=>{}} />;
    }
    export { act };
    export function mount(node) { const root=createRoot(node); root.render(<Harness/>); return root; }
    export const value=()=>current;
    export const preview=value=>renderToStaticMarkup(<ProblemMarkdown>{value}</ProblemMarkdown>);
  `, resolveDir:process.cwd(),loader:"tsx" },
  bundle:true,write:false,platform:"node",format:"cjs",jsx:"automatic",loader:{".css":"empty"},
  define:{"import.meta.hot":"undefined"},
});
const filename = join(tmpdir(),`sqluit-rich-editor-${process.pid}.cjs`);
await writeFile(filename,bundle.outputFiles[0].contents);
let root;
const scratch=document.getElementById('scratch');
const selectAll = editor => {
  const range=document.createRange();range.selectNodeContents(editor);
  const selection=window.getSelection();selection.removeAllRanges();selection.addRange(range);
};
const sizedText = node => {
  const walker=document.createTreeWalker(node,NodeFilter.SHOW_TEXT);
  const sizes=[];
  for(let text=walker.nextNode();text;text=walker.nextNode()) {
    if(!text.textContent.trim()) continue;
    sizes.push(text.parentElement.closest('[style*="font-size"]')?.style.fontSize);
  }
  return sizes;
};
try {
  const api=createRequire(import.meta.url)(filename);
  assert.equal(api.editorHtmlToMarkdown('<p><strong>KHACHHANG </strong>(MAKH)</p>'),'**KHACHHANG** (MAKH)');
  scratch.innerHTML=api.preview('**KHACHHANG **(MAKH)');
  assert.equal(scratch.querySelector('strong').textContent,'KHACHHANG');
  scratch.innerHTML=api.preview('`**KHACHHANG **`\n\n```sql\nSELECT \'**literal **\';\n```');
  assert.equal(scratch.querySelector('code').textContent,'**KHACHHANG **');
  assert.ok(scratch.querySelector('pre code').textContent.includes('**literal **'));
  for(let iteration=0, value=nested;iteration<3;iteration++) {
    scratch.innerHTML=api.markdownToEditorHtml(value);
    assert.equal(scratch.querySelectorAll('p').length,3,'Multi-paragraph content retains its paragraph structure');
    assert.equal(scratch.textContent,'Đoạn một.Đoạn hai in đậm.Đoạn ba.');
    assert.ok(scratch.querySelector('strong'));
    assert.ok(sizedText(scratch).every(size=>size==='10px'),'Nested font sizes apply to every paragraph');
    value=api.editorHtmlToMarkdown(scratch.innerHTML);
  }
  scratch.innerHTML=api.preview(nested);
  assert.ok(!scratch.textContent.includes('[font-size'));
  assert.ok(sizedText(scratch).every(size=>size==='10px'),'Student preview uses the same size across paragraphs');
  assert.equal(scratch.querySelectorAll('p').length,3);

  const markdown='## Heading\n\n- First\n- Second\n\n---\n\n`[font-size=10]literal[/font-size]`\n\n```sql\nSELECT \'[font-size=10]\';\n```';
  scratch.innerHTML=api.markdownToEditorHtml(markdown);
  assert.equal(scratch.querySelectorAll('h2').length,1);
  assert.equal(scratch.querySelectorAll('li').length,2);
  assert.equal(scratch.querySelectorAll('hr').length,1);
  assert.equal(scratch.querySelector('p code').textContent,'[font-size=10]literal[/font-size]','Code examples keep literal formatting markers');
  assert.ok(scratch.querySelector('pre code').textContent.includes('[font-size=10]'));
  scratch.innerHTML=api.markdownToEditorHtml('<script>alert(1)</script>');
  assert.equal(scratch.querySelector('script'),null,'Editor conversion keeps HTML as text');

  const hardBreak=api.editorHtmlToMarkdown('<p><u>First<br>Second</u></p>');
  scratch.innerHTML=api.markdownToEditorHtml(hardBreak);
  assert.ok(scratch.querySelector('br'),'Authored line breaks survive reopening');
  scratch.innerHTML=api.preview(hardBreak);
  assert.ok(scratch.querySelector('br'),'Authored line breaks survive preview');
  const layoutHtml='<p style="line-height:1.5;margin-top:6pt;margin-bottom:12pt">KHACHHANG (<u><strong>MAKH</strong></u>, HOTEN)</p><h2 style="line-height:2;margin-top:0pt;margin-bottom:8pt">Heading</h2>';
  let layout=api.editorHtmlToMarkdown(layoutHtml);
  assert.ok(layout.includes('[u]**MAKH**[/u]'));
  for(let iteration=0;iteration<3;iteration++) {
    scratch.innerHTML=api.markdownToEditorHtml(layout);
    assert.equal(scratch.querySelector('p').style.lineHeight,'1.5');
    assert.equal(scratch.querySelector('p').style.marginBottom,'12pt');
    assert.equal(scratch.querySelector('h2').style.lineHeight,'2');
    assert.equal(scratch.querySelector('u').textContent,'MAKH');
    layout=api.editorHtmlToMarkdown(scratch.innerHTML);
  }
  scratch.innerHTML=api.preview(layout);
  assert.equal(scratch.querySelector('p').style.lineHeight,'1.5');
  assert.equal(scratch.querySelector('p').style.marginTop,'6pt');
  assert.equal(scratch.querySelector('h2').style.marginBottom,'8pt');
  assert.equal(scratch.querySelector('u').textContent,'MAKH');
  assert.ok(!scratch.textContent.includes('[paragraph'));
  assert.ok(!scratch.textContent.includes('[u]'));
  scratch.innerHTML=api.preview('`[u]SQL[/u]`\n\n```sql\n[paragraph line=2 before=0 after=8]SELECT 1;[/paragraph]\n```');
  assert.equal(scratch.querySelector('code').textContent,'[u]SQL[/u]');
  assert.ok(scratch.querySelector('pre code').textContent.includes('[paragraph'));
  assert.equal(scratch.querySelector('u'),null,'Formatting markers inside code stay literal');
  scratch.innerHTML='<p>First</p><p><b>Second</b></p><p>Third</p>';
  const blockRange=document.createRange();blockRange.setStart(scratch.firstChild.firstChild,1);blockRange.setEnd(scratch.children[1].firstChild.firstChild,3);
  window.getSelection().removeAllRanges();window.getSelection().addRange(blockRange);
  assert.equal(api.setParagraphSpacing(scratch,{line:2,after:8}),true);
  assert.equal(scratch.children[0].style.lineHeight,'2');
  assert.equal(scratch.children[1].style.marginBottom,'8pt');
  assert.equal(scratch.children[2].style.lineHeight,'','Unselected paragraphs are unchanged');
  assert.equal(window.getSelection().toString(),'irstSec','Spacing retains the text selection');
  await api.act(async()=>{root=api.mount(document.getElementById('root'));});
  const editor=document.querySelector('.teacher-problem-inline-editor');
  editor.style.fontSize='12px';
  editor.focus();selectAll(editor);
  const sizeButton=delta=>[...document.querySelectorAll('.teacher-markdown-toolbar button')].find(button=>button.textContent===(delta>0?'A+':'A−'));
  for (const size of [11,12,13]) {
    await api.act(async()=>sizeButton(1).click());
    assert.ok(!editor.textContent.includes('[font-size'));
    assert.equal(editor.querySelector('span[style]').style.fontSize,`${size}px`,'Repeated increases use the current text size');
    assert.equal(editor.querySelector('span[style] span[style]'),null,'Repeated clicks do not accumulate size wrappers');
  }
  await api.act(async()=>sizeButton(-1).click());
  assert.equal(editor.querySelector('span[style]').style.fontSize,'12px');
  assert.ok(editor.querySelector('strong'),'Changing size keeps bold formatting');
  const commands=[];document.execCommand=(command)=>{commands.push(command);return true;};
  const shortcut=new window.KeyboardEvent('keydown',{key:'u',ctrlKey:true,bubbles:true,cancelable:true});
  await api.act(async()=>editor.dispatchEvent(shortcut));
  assert.ok(shortcut.defaultPrevented,'Ctrl+U stays in the editor rather than opening browser source');
  assert.equal(commands.at(-1),'underline');
  const spacingButton=document.querySelector('[aria-label="Giãn dòng và khoảng cách đoạn"]');
  await api.act(async()=>spacingButton.click());
  assert.ok(document.querySelector('.teacher-paragraph-menu'));
  await api.act(async()=>[...document.querySelectorAll('.teacher-line-spacing-options button')].find(button=>button.textContent==='2').click());
  assert.equal(editor.querySelector('p').style.lineHeight,'2');
  assert.ok(api.value().includes('[paragraph line=2'),'Spacing is saved as part of the authored statement');
  const saved=api.value();
  await api.act(async()=>editor.blur());
  scratch.innerHTML=api.markdownToEditorHtml(saved);
  assert.ok(!scratch.textContent.includes('[font-size'),'Reopening saved content never exposes markers');
  assert.ok(sizedText(scratch).every(size=>size==='12px'));
  scratch.innerHTML=api.preview(saved);
  assert.ok(sizedText(scratch).every(size=>size==='12px'));

  scratch.innerHTML='<p><span style="font-size:10px">small</span> <span style="font-size:18px"><em>large</em></span></p>';
  selectAll(scratch);
  assert.equal(api.adjustSelectedFontSize(scratch,1),11);
  assert.equal(scratch.querySelectorAll('span[style]').length,1,'A selection replaces old mixed sizes with the new size');
  assert.ok(scratch.querySelector('em'));
  assert.equal(window.getSelection().toString(),'small large');
  console.log('PASS: nested/multi-paragraph font sizes, repeated toolbar clicks, saved/reopened content, student preview, preserved formatting and literal code.');
} finally {
  if(root)await createRequire(import.meta.url)(filename).act(async()=>root.unmount());
  await unlink(filename);delete globalThis.__initialRichText;dom.window.close();
}
process.exit(0);
