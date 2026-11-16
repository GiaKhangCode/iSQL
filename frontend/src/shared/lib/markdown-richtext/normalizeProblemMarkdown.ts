/** Compatibility for bold spans emitted by older rich-text editors. Code stays literal. */
export function normalizeProblemMarkdown(source: string) {
  let fence = "";
  return source.split("\n").map(line => {
    const marker = line.match(/^\s*(`{3,}|~{3,})/);
    if (marker) {
      if (!fence) fence = marker[1];
      else if (marker[1][0] === fence[0] && marker[1].length >= fence.length) fence = "";
      return line;
    }
    if (fence || /^(?: {4}|\t)/.test(line)) return line;
    return line.split(/(`+[^`]*`+)/g).map(part => part.startsWith("`") ? part :
      part.replace(/(?<!\\)\*\*([^*\n]+)\*\*/g, (whole,content: string) => {
        if (content === content.trim() || !content.trim()) return whole;
        const leading = content.match(/^\s*/)?.[0] || "";
        const trailing = content.match(/\s*$/)?.[0] || "";
        return `${leading}**${content.trim()}**${trailing}`;
      })).join("");
  }).join("\n");
}
