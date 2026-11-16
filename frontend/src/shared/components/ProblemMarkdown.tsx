import {remarkRichTextLayout} from "@/shared/lib/markdown-richtext/richTextLayout";
import { type HTMLAttributes } from "react";
import ReactMarkdown, { type Components } from "react-markdown";
import { normalizeProblemMarkdown } from "@/shared/lib/markdown-richtext/normalizeProblemMarkdown";

type FontSizeMarkdownNode = {
  type: string;
  value?: string;
  children?: FontSizeMarkdownNode[];
  data?: {
    hName?: string;
    hProperties?: Record<string, unknown>;
  };
};

function parseFontSizeChildren(children: FontSizeMarkdownNode[], sizes: number[] = []): FontSizeMarkdownNode[] {
  const output: FontSizeMarkdownNode[] = [];
  const tokenPattern = /\[font-size=\s*(1[0-9]|20)(?:px)?\s*\]|\[\/font-size\]/gi;

  const withSize = (node: FontSizeMarkdownNode): FontSizeMarkdownNode => {
    const size = sizes[sizes.length - 1];
    return size ? {
      type: "font-size",
      data: { hName: "span", hProperties: { style: { fontSize: `${size}px` } } },
      children: [node],
    } : node;
  };

  const pushText = (text: string) => {
    if (text) output.push(withSize({ type: "text", value: text }));
  };

  children.forEach((child) => {
    if (child.type !== "text" || typeof child.value !== "string") {
      const nestedChild = child.children
        ? { ...child, children: parseFontSizeChildren(child.children, sizes) }
        : child;
      if (nestedChild.type !== "paragraph" || nestedChild.children?.length) {
        output.push(child.type === "inlineCode" ? withSize(nestedChild) : nestedChild);
      }
      return;
    }

    let cursor = 0;
    for (const match of child.value.matchAll(tokenPattern)) {
      pushText(child.value.slice(cursor, match.index));
      if (match[1]) {
        sizes.push(Number(match[1]));
      } else {
        sizes.pop();
      }
      cursor = match.index! + match[0].length;
    }
    pushText(child.value.slice(cursor));
  });

  return output;
}

function remarkFontSize() {
  return (tree: FontSizeMarkdownNode) => {
    const visit = (node: FontSizeMarkdownNode) => {
      if (!node.children) return;
      node.children = parseFontSizeChildren(node.children);
    };

    visit(tree);
  };
}

const markdownComponents: Components = {
  p({ children, style }) {
    const rawText = typeof children === "string" ? children.trim() : "";
    const expression = rawText.replace(/\s+/g, " ");
    const isStandaloneExpression =
      expression.length > 0 &&
      expression.length <= 180 &&
      /_[A-Za-z0-9]+/.test(expression) &&
      /[*/+=-]/.test(expression) &&
      /^[\w\s().,+*/%=-]+$/.test(expression);

    if (isStandaloneExpression) {
      return (
        <pre className="problem-expression">
          <code>{expression}</code>
        </pre>
      );
    }

    return <p style={style}>{children}</p>;
  },
};

type ProblemMarkdownProps = HTMLAttributes<HTMLDivElement> & {
  children?: string;
};

export function ProblemMarkdown({ className, children = "", ...props }: ProblemMarkdownProps) {
  return (
    <div className={["problem-markdown", className].filter(Boolean).join(" ")} {...props}>
      <ReactMarkdown skipHtml remarkPlugins={[remarkFontSize,remarkRichTextLayout]} components={markdownComponents}>
        {normalizeProblemMarkdown(children)}
      </ReactMarkdown>
    </div>
  );
}
