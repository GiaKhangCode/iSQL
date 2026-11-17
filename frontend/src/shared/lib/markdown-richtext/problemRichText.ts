import {renderEditorLayout, spacingMarker} from "@/shared/lib/markdown-richtext/richTextLayout";
import { withoutCaretMarkers } from "@/shared/lib/markdown-richtext/richTextFormatting";

function renderEditorFontSizes(html: string) {
  const root = new DOMParser().parseFromString(`<div>${html}</div>`, "text/html").body.firstElementChild!;
  const sizes: number[] = [];
  const tokenPattern = /\[font-size=\s*(1[0-9]|20)(?:px)?\s*\]|\[\/font-size\]/gi;
  const visit = (node: Node, literal = false) => {
    if (node.nodeType === Node.TEXT_NODE) {
      const text = node.textContent || "";
      const replacement = document.createDocumentFragment();
      const append = (value: string) => {
        if (!value) return;
        const leaf = document.createTextNode(value);
        const size = sizes[sizes.length - 1];
        if (size) {
          const span = document.createElement("span");
          span.style.fontSize = `${size}px`;
          span.append(leaf);
          replacement.append(span);
        } else replacement.append(leaf);
      };
      let cursor = 0;
      if (!literal) {
        for (const match of text.matchAll(tokenPattern)) {
          append(text.slice(cursor, match.index));
          if (match[1]) sizes.push(Number(match[1]));
          else sizes.pop();
          cursor = match.index! + match[0].length;
        }
      }
      append(text.slice(cursor));
      node.parentNode!.replaceChild(replacement, node);
      return;
    }
    const element = node as HTMLElement;
    const code = literal || element.tagName === "CODE" || element.tagName === "PRE";
    Array.from(node.childNodes).forEach(child => visit(child, code));
  };
  visit(root);
  // Marker-only paragraphs carry style state, without adding visible blank paragraphs.
  root.querySelectorAll("p:empty").forEach(paragraph => paragraph.remove());
  return root.innerHTML;
}

function escapeHtml(value: string) {
  return value.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/\"/g, "&quot;");
}

export function safeHref(value: string) {
  const href = value.trim();
  return /^(https?:|mailto:|\/|#)/i.test(href) ? href : "";
}

function inlineMarkdownToHtml(value: string) {
  return escapeHtml(value)
    .replace(/\\\n/g, "<br>")
    .replace(/\n/g, " ")
    .replace(/`([^`\n]+)`/g, "<code>$1</code>")
    .replace(/\*\*([^*\n]+)\*\*/g, "<strong>$1</strong>")
    .replace(/__([^_\n]+)__/g, "<strong>$1</strong>")
    .replace(/(^|[^*])\*([^*\n]+)\*(?!\*)/g, "$1<em>$2</em>")
    .replace(/(^|[^_])_([^_\n]+)_(?!_)/g, "$1<em>$2</em>")
    .replace(/\[([^\]]+)\]\(((?:https?:|mailto:|\/|#)[^)\s]+)\)/gi, (_match, label: string, href: string) => {
      return `<a href=\"${href}\" target=\"_blank\" rel=\"noopener noreferrer\">${label}</a>`;
    });
}

export function markdownToEditorHtml(value: string) {
  const lines = value.replace(/\r\n?/g, "\n").split("\n");
  const output: string[] = [];
  const paragraph: string[] = [];
  let listType: "ul" | "ol" | null = null;
  let codeLines: string[] | null = null;

  const flushParagraph = () => {
    if (!paragraph.length) return;
    output.push(`<p>${inlineMarkdownToHtml(paragraph.join("\n"))}</p>`);
    paragraph.length = 0;
  };
  const closeList = () => {
    if (!listType) return;
    output.push(`</${listType}>`);
    listType = null;
  };

  for (const line of lines) {
    if (line.trim().startsWith("```")) {
      flushParagraph();
      closeList();
      if (codeLines) {
        output.push(`<pre><code>${escapeHtml(codeLines.join("\n"))}</code></pre>`);
        codeLines = null;
      } else codeLines = [];
      continue;
    }
    if (codeLines) {
      codeLines.push(line);
      continue;
    }

    const heading = line.match(/^(#{1,6})\s+(.+)$/);
    const bullet = line.match(/^\s*[-*+]\s+(.+)$/);
    const numbered = line.match(/^\s*\d+[.)]\s+(.+)$/);
    if (heading) {
      flushParagraph(); closeList();
      const level = heading[1].length;
      output.push(`<h${level}>${inlineMarkdownToHtml(heading[2])}</h${level}>`);
    } else if (/^\s*---+\s*$/.test(line)) {
      flushParagraph(); closeList(); output.push("<hr>");
    } else if (bullet || numbered) {
      flushParagraph();
      const nextType = bullet ? "ul" : "ol";
      if (listType !== nextType) {
        closeList();
        output.push(`<${nextType}>`);
        listType = nextType;
      }
      output.push(`<li>${inlineMarkdownToHtml((bullet || numbered)![1])}</li>`);
    } else if (!line.trim()) {
      flushParagraph(); closeList();
    } else {
      closeList(); paragraph.push(line.trim());
    }
  }
  flushParagraph();
  closeList();
  if (codeLines) output.push(`<pre><code>${escapeHtml(codeLines.join("\n"))}</code></pre>`);
  return renderEditorLayout(renderEditorFontSizes(output.join("")));
}

export function editorHtmlToMarkdown(html: string) {
  const root = new DOMParser().parseFromString(`<div>${html}</div>`, "text/html").body.firstElementChild;
  if (!root) return "";

  const render = (node: Node, parentTag = ""): string => {
    if (node.nodeType === Node.TEXT_NODE) return withoutCaretMarkers(node.textContent || "").replace(/\u00a0/g, " ");
    if (node.nodeType !== Node.ELEMENT_NODE) return "";
    const element = node as HTMLElement;
    const tag = element.tagName.toLowerCase();
    if (tag === "br") return "\\\n";
    if (tag === "hr") return "\n\n---\n\n";
    if (tag === "pre") return `\n\n\`\`\`\n${element.textContent || ""}\n\`\`\`\n\n`;
    let children = Array.from(element.childNodes).map((child) => render(child, tag)).join("");
    if (tag === "u") return `[u]${children}[/u]`;
    if (element.style.textDecoration.includes("underline") || element.style.textDecorationLine.includes("underline")) children = `[u]${children}[/u]`;
    if (["strong","b","em","i"].includes(tag)) {
      const marker = tag === "strong" || tag === "b" ? "**" : "*";
      const leading = children.match(/^\s*/)?.[0] || "";
      const trailing = children.match(/\s*$/)?.[0] || "";
      return children.trim() ? `${leading}${marker}${children.trim()}${marker}${trailing}` : children;
    }
    if (tag === "code" && parentTag !== "pre") return children ? `\`${children}\`` : "";
    if (tag === "span" && element.style.fontSize) {
      const size = Math.round(parseFloat(element.style.fontSize));
      if (size >= 10 && size <= 20) return `[font-size=${size}]${children}[/font-size]`;
    }
    if (tag === "a") {
      const href = safeHref(element.getAttribute("href") || "");
      return href ? `[${children}](${href})` : children;
    }
    if (tag === "ul") return `${Array.from(element.children).map((child) => `- ${render(child).trim()}`).join("\n")}\n\n`;
    if (tag === "ol") return `${Array.from(element.children).map((child, index) => `${index + 1}. ${render(child).trim()}`).join("\n")}\n\n`;
    if (tag === "li") return spacingMarker(element,children);
    if (/^h[1-6]$/.test(tag)) return `${"#".repeat(Number(tag[1]))} ${spacingMarker(element,children)}\n\n`;
    if (tag === "p" || tag === "div") return `${spacingMarker(element,children)}\n\n`;
    return children;
  };

  return render(root).replace(/[ \t]+\n/g, "\n").replace(/\n{3,}/g, "\n\n").trim();
}

