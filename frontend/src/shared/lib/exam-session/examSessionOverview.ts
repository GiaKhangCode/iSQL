/** Hide exact copies of banner copy in the overview without changing stored Markdown. */
export function examSessionOverview(markdown: string | null | undefined, title: string, summary?: string | null) {
  if (!markdown) return "";
  const normalize = (value: string) => value.replace(/[*_~`]/g, "").replace(/\s+/g, " ").trim().toLowerCase();
  const bannerTitle = normalize(title);
  const bannerSummary = normalize(summary || "");
  const clean = (text: string) => text.split(/\n\s*\n/).map((block) => {
    const lines = block.split("\n");
    const filtered = lines.filter((line, index) => {
      const heading = line.match(/^\s{0,3}#{1,6}\s+(.+?)\s*#*\s*$/);
      if (heading && normalize(heading[1]) === bannerTitle) return false;
      if (normalize(line) === bannerTitle && /^\s*(?:=+|-+)\s*$/.test(lines[index + 1] || "")) return false;
      if (/^\s*(?:=+|-+)\s*$/.test(line) && normalize(lines[index - 1] || "") === bannerTitle) return false;
      return true;
    }).join("\n");
    return bannerSummary && normalize(filtered) === bannerSummary ? "" : filtered;
  }).filter((block) => block.trim()).join("\n\n");

  // Fenced SQL/code is content, even when it happens to match the exam session title.
  const result: string[] = [];
  let prose: string[] = [], fence = "";
  for (const line of markdown.replace(/\r\n/g, "\n").split("\n")) {
    const marker = line.match(/^\s{0,3}(`{3,}|~{3,})/);
    if (!fence && marker) {
      result.push(clean(prose.join("\n")));
      prose = [];
      fence = marker[1];
      result.push(line);
    } else if (fence) {
      result.push(line);
      if (marker && marker[1][0] === fence[0] && marker[1].length >= fence.length) fence = "";
    } else prose.push(line);
  }
  result.push(clean(prose.join("\n")));
  return result.join("\n").trim();
}
