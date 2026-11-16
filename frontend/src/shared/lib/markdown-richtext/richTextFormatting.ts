export type InlineFormats = { bold: boolean; italic: boolean; underline: boolean; code: boolean };
export const emptyInlineFormats: InlineFormats = { bold: false, italic: false, underline: false, code: false };
export const withoutCaretMarkers = (value: string) => value.replace(/\u200B/g, "");

function elementAt(node: Node) { return node.nodeType === Node.ELEMENT_NODE ? node as Element : node.parentElement; }
function selectionRange(editor: HTMLElement, selection: Selection | null) {
  if (!selection?.rangeCount) return null;
  const range = selection.getRangeAt(0);
  return editor.contains(range.commonAncestorContainer) ? range : null;
}
function inlineCodeAt(editor: HTMLElement, range: Range) {
  const start = elementAt(range.startContainer)?.closest("code");
  const end = elementAt(range.endContainer)?.closest("code");
  return start && start === end && editor.contains(start) ? start : null;
}

/** Apply one size to the selection, replacing older size wrappers inside it. */
export function adjustSelectedFontSize(editor: HTMLElement, delta: number, selection = window.getSelection()): number | null {
  const range = selectionRange(editor, selection);
  if (!range || range.collapsed || !selection) return null;
  const walker = document.createTreeWalker(editor, NodeFilter.SHOW_TEXT);
  let firstText = walker.nextNode();
  while (firstText && (!firstText.textContent?.trim() || !range.intersectsNode(firstText))) firstText = walker.nextNode();
  const start = firstText?.parentElement || elementAt(range.startContainer) || editor;
  let current = 12;
  for (let element: Element | null = start; element && editor.contains(element); element = element.parentElement) {
    const computed = window.getComputedStyle(element).fontSize;
    if (computed.endsWith("px") && parseFloat(computed) > 0) {
      current = parseFloat(computed);
      break;
    }
  }
  const size = Math.max(10, Math.min(20, Math.round(current + delta)));
  const contents = range.extractContents();
  contents.querySelectorAll<HTMLElement>("span[style]").forEach(span => {
    span.style.removeProperty("font-size");
    if (!span.style.length) span.removeAttribute("style");
    if (!span.attributes.length) span.replaceWith(...span.childNodes);
  });
  const span = document.createElement("span");
  span.style.fontSize = `${size}px`;
  span.append(contents);
  range.insertNode(span);
  // Repeated toolbar clicks should replace the previous wrapper, rather than nest it.
  let parent = span.parentElement;
  while (parent && parent !== editor && parent.tagName === "SPAN" && parent.childNodes.length === 1) {
    parent.style.removeProperty("font-size");
    if (!parent.style.length) parent.removeAttribute("style");
    if (parent.attributes.length) break;
    const ancestor = parent.parentElement;
    parent.replaceWith(span);
    parent = ancestor;
  }
  range.selectNodeContents(span);
  selection.removeAllRanges();
  selection.addRange(range);
  return size;
}
function styled(node: Node, editor: HTMLElement, property: "bold" | "italic" | "underline") {
  for (let element = elementAt(node); element && element !== editor; element = element.parentElement) {
    const style = window.getComputedStyle(element);
    if (property === "bold" && (/^(B|STRONG)$/.test(element.tagName) || Number.parseInt(style.fontWeight) >= 600 || style.fontWeight === "bold")) return true;
    if (property === "underline" && (element.tagName === "U" || (style.textDecorationLine || style.textDecoration).includes("underline"))) return true;
    if (property === "italic" && (/^(I|EM)$/.test(element.tagName) || style.fontStyle === "italic")) return true;
  }
  return false;
}

/** Derive toolbar state from the current caret/selection, including keyboard commands. */
export function readInlineFormats(editor: HTMLElement, selection = window.getSelection()): InlineFormats {
  const range = selectionRange(editor, selection);
  if (!range) return emptyInlineFormats;
  const command = (name: "bold" | "italic" | "underline") => typeof document.queryCommandState === "function"
    ? document.queryCommandState(name)
    : styled(range.startContainer, editor, name) && styled(range.endContainer, editor, name);
  return { bold: command("bold"), italic: command("italic"), underline: command("underline"), code: Boolean(inlineCodeAt(editor, range)) };
}

/** Toggle the selected inline code, retaining surrounding text and other formatting. */
export function toggleInlineCode(editor: HTMLElement, selection = window.getSelection()) {
  const range = selectionRange(editor, selection);
  if (!range || !selection) return;
  const activeCode = inlineCodeAt(editor, range);
  const collapsed = range.collapsed;
  if (activeCode) {
    const before = document.createRange();
    before.selectNodeContents(activeCode);
    before.setEnd(range.startContainer, range.startOffset);
    const after = document.createRange();
    after.selectNodeContents(activeCode);
    after.setStart(range.endContainer, range.endOffset);
    const replacement = document.createDocumentFragment();
    const appendCode = (contents: DocumentFragment) => {
      if (!withoutCaretMarkers(contents.textContent ?? "")) return;
      const code = document.createElement("code");
      code.append(contents);
      replacement.append(code);
    };
    appendCode(before.cloneContents());
    let middle = collapsed ? document.createDocumentFragment() : range.cloneContents();
    if (!middle.childNodes.length) middle.append(document.createTextNode("\u200B"));
    // cloneContents omits formatting ancestors around a selection within one text node.
    for (let ancestor = elementAt(range.commonAncestorContainer); ancestor && ancestor !== activeCode; ancestor = ancestor.parentElement) {
      const wrapper = ancestor.cloneNode(false);
      wrapper.appendChild(middle);
      middle = document.createDocumentFragment();
      middle.append(wrapper);
    }
    const first = middle.firstChild!, last = middle.lastChild!;
    replacement.append(middle);
    appendCode(after.cloneContents());
    activeCode.replaceWith(replacement);
    const next = document.createRange();
    if (collapsed) { next.setStart(first, first.textContent?.length ?? 0); next.collapse(true); }
    else { next.setStartBefore(first); next.setEndAfter(last); }
    selection.removeAllRanges(); selection.addRange(next);
    return;
  }
  const code = document.createElement("code");
  if (collapsed) code.append(document.createTextNode("\u200B"));
  else {
    const contents = range.extractContents();
    contents.querySelectorAll("code").forEach(nested => nested.replaceWith(...nested.childNodes));
    code.append(contents);
  }
  range.insertNode(code);
  const next = document.createRange();
  next.selectNodeContents(code);
  if (collapsed) next.collapse(false);
  selection.removeAllRanges(); selection.addRange(next);
}
