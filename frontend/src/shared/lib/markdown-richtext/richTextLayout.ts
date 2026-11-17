export type ParagraphSpacing = {line: number; before: number; after: number};
export const LINE_SPACING = [1, 1.15, 1.5, 2, 2.5, 3];
export const PARAGRAPH_SPACING = [0, 4, 6, 8, 12, 16, 24];
const blockSelector = "p,h1,h2,h3,h4,h5,h6,li,div";
export const layoutTokens = () => /\[u\]|\[\/u\]|\[paragraph line=(\d(?:\.\d{1,2})?) before=(\d{1,2}(?:\.\d{1,2})?) after=(\d{1,2}(?:\.\d{1,2})?)\]|\[\/paragraph\]/g;
export function validSpacing(line: number, before: number, after: number) {
  return [line,before,after].every(Number.isFinite) && line >= 1 && line <= 3 && before >= 0 && before <= 48 && after >= 0 && after <= 48;
}
const points = (value: string) => Math.round((parseFloat(value) || 0) * (value.endsWith("px") ? .75 : 1) * 100) / 100;
export function spacingOf(element: HTMLElement): ParagraphSpacing {
  const computed = window.getComputedStyle(element);
  const line = Number(element.style.lineHeight) || (parseFloat(computed.lineHeight) / parseFloat(computed.fontSize)) || 1.65;
  return {line: Math.round(Math.max(1,Math.min(3,line))*100)/100,
    before: Math.min(48,points(element.style.marginTop || computed.marginTop)),
    after: Math.min(48,points(element.style.marginBottom || computed.marginBottom))};
}
export function readParagraphSpacing(editor: HTMLElement): ParagraphSpacing {
  const selection = window.getSelection();
  const node = selection?.rangeCount ? selection.getRangeAt(0).startContainer : null;
  const element = node?.nodeType === Node.ELEMENT_NODE ? node as HTMLElement : node?.parentElement;
  const block = element?.closest<HTMLElement>(blockSelector);
  return spacingOf(block && editor.contains(block) ? block : editor);
}
export function setParagraphSpacing(editor: HTMLElement, patch: Partial<ParagraphSpacing>) {
  const selection = window.getSelection();
  if (!selection?.rangeCount) return false;
  let range = selection.getRangeAt(0);
  if (!editor.contains(range.commonAncestorContainer)) return false;
  const start = range.startContainer.nodeType === Node.ELEMENT_NODE ? range.startContainer as HTMLElement : range.startContainer.parentElement;
  if (!start?.closest(blockSelector) || start.closest(blockSelector) === editor) {
    if (typeof document.execCommand !== "function") return false;
    document.execCommand("formatBlock",false,"p");
    range = selection.getRangeAt(0);
  }
  const blocks = Array.from(editor.querySelectorAll<HTMLElement>(blockSelector)).filter(block => !block.querySelector(blockSelector));
  let changed = false;
  for (const block of blocks) {
    if (!range.intersectsNode(block)) continue;
    if (range.collapsed && !block.contains(range.startContainer)) continue;
    if (!range.collapsed) {
      const intersection = document.createRange(); intersection.selectNodeContents(block);
      if (range.compareBoundaryPoints(window.Range.START_TO_START,intersection)>0) intersection.setStart(range.startContainer,range.startOffset);
      if (range.compareBoundaryPoints(window.Range.END_TO_END,intersection)<0) intersection.setEnd(range.endContainer,range.endOffset);
      if (intersection.collapsed) continue;
    }
    const spacing = {...spacingOf(block),...patch};
    if (!validSpacing(spacing.line,spacing.before,spacing.after)) continue;
    block.style.lineHeight = String(spacing.line);
    block.style.marginTop = `${spacing.before}pt`;
    block.style.marginBottom = `${spacing.after}pt`;
    changed = true;
  }
  return changed;
}
export function spacingMarker(element: HTMLElement, children: string) {
  if (!element.style.lineHeight && !element.style.marginTop && !element.style.marginBottom) return children;
  const line = Number(element.style.lineHeight) || 1.65;
  const before = points(element.style.marginTop), after = points(element.style.marginBottom);
  return validSpacing(line,before,after) ? `[paragraph line=${line} before=${before} after=${after}]${children}[/paragraph]` : children;
}

/** Decode only the formatting tokens we own. Raw HTML and code remain literal. */
export function renderEditorLayout(html: string) {
  const root = new DOMParser().parseFromString(`<div>${html}</div>`,"text/html").body.firstElementChild!;
  let underline = 0;
  const visit = (node: Node, literal=false) => {
    if (node.nodeType === Node.TEXT_NODE) {
      if (literal) return;
      const text = node.textContent || "", fragment = document.createDocumentFragment();
      const append = (value: string) => {if (value) {const leaf=document.createTextNode(value); if(underline) {const u=document.createElement("u");u.append(leaf);fragment.append(u);} else fragment.append(leaf);}};
      let cursor=0;
      for (const match of text.matchAll(layoutTokens())) {
        append(text.slice(cursor,match.index));
        if(match[0]==="[u]") underline++;
        else if(match[0]==="[/u]") underline=Math.max(0,underline-1);
        else if(match[1]) {
          const line=Number(match[1]),before=Number(match[2]),after=Number(match[3]);
          const block=node.parentElement?.closest<HTMLElement>(blockSelector);
          if(validSpacing(line,before,after)&&block&&block!==root) {block.style.lineHeight=String(line);block.style.marginTop=`${before}pt`;block.style.marginBottom=`${after}pt`;}
          else append(match[0]);
        }
        cursor=match.index!+match[0].length;
      }
      append(text.slice(cursor));node.parentNode!.replaceChild(fragment,node);return;
    }
    const element=node as HTMLElement;
    Array.from(node.childNodes).forEach(child=>visit(child,literal||element.tagName==="CODE"||element.tagName==="PRE"));
  };
  visit(root);
  return root.innerHTML;
}

type MarkdownNode = {type:string; value?:string; children?:MarkdownNode[];data?:{hName?:string;hProperties?:Record<string,unknown>}};
export function remarkRichTextLayout() {
  return (tree: MarkdownNode) => {
    const state={underline:0};
    const wrap = (node: MarkdownNode) => state.underline ? {type:"underline",data:{hName:"u"},children:[node]} : node;
    const visit = (node: MarkdownNode, block?: MarkdownNode) => {
      if (!node.children || node.type==="code") return;
      if (["paragraph","heading"].includes(node.type)) block=node;
      const output: MarkdownNode[]=[];
      for(const child of node.children) {
        if(child.type!=="text" || child.value==null) {visit(child,block);output.push(child.type==="inlineCode"?wrap(child):child);continue;}
        let cursor=0;
        const append=(value:string)=>{if(value)output.push(wrap({type:"text",value}));};
        for(const match of child.value.matchAll(layoutTokens())) {
          append(child.value.slice(cursor,match.index));
          if(match[0]==="[u]")state.underline++;
          else if(match[0]==="[/u]")state.underline=Math.max(0,state.underline-1);
          else if(match[1]) {
            const line=Number(match[1]),before=Number(match[2]),after=Number(match[3]);
            if(block&&validSpacing(line,before,after)) block.data={...block.data,hProperties:{...block.data?.hProperties,style:{lineHeight:line,marginTop:`${before}pt`,marginBottom:`${after}pt`}}};
            else append(match[0]);
          }
          cursor=match.index!+match[0].length;
        }
        append(child.value.slice(cursor));
      }
      node.children=output;
    };
    visit(tree);
  };
}
