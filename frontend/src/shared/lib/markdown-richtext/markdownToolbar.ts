export function markdownToolbarEdit(text: string, start: number, end: number, command: string, argument = "") {
  let from = start, to = end;
  const selected = text.slice(start, end);
  let replacement = selected;
  let selectionStart = start, selectionEnd = end;
  const wrappers: Record<string, [string, string]> = {
    bold: ["**", "**"], italic: ["*", "*"], underline: ["[u]", "[/u]"],
    code: ["`", "`"], createLink: ["[", `](${argument})`],
  };
  if (wrappers[command]) {
    const [before, after] = wrappers[command];
    replacement = before + selected + after;
    selectionStart = start + before.length;
    selectionEnd = selectionStart + selected.length;
  } else if (["insertUnorderedList", "insertOrderedList", "formatBlock"].includes(command)) {
    from = text.lastIndexOf("\n", start - 1) + 1;
    if (start === 0) from = 0;
    const lineEnd = text.indexOf("\n", end > start && text[end - 1] === "\n" ? end - 1 : end);
    to = lineEnd < 0 ? text.length : lineEnd;
    replacement = text.slice(from, to).split("\n").map((line, index) => {
      const prefix = command === "insertOrderedList" ? `${index + 1}. ` : command === "insertUnorderedList" ? "- " : argument === "<h3>" ? "### " : "## ";
      return prefix + line.replace(/^(?:#{1,6} |[-*+] |\d+\. )/, "");
    }).join("\n");
    selectionStart = from;
    selectionEnd = from + replacement.length;
  } else if (command === "insertHorizontalRule") {
    replacement = "\n\n---\n\n";
    selectionStart = selectionEnd = from + replacement.length;
  }
  return {from, to, replacement, selectionStart, selectionEnd};
}
