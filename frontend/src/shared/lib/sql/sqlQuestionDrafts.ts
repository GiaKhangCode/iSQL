// Stable solution IDs keep answers attached when questions are renamed or reordered.
export function readQuestionDrafts(script: string, ids: string[]): Record<string, string> {
  const markers = [...script.matchAll(/^-- solution:([^\r\n]+)(?:\r?\n|$)/gm)];
  if (!markers.length) return ids[0] ? { [ids[0]]: script } : {};
  return Object.fromEntries(markers.map((match, index) => [match[1], script.slice((match.index || 0) + match[0].length, markers[index + 1]?.index ?? script.length).trimEnd()]));
}
export function writeQuestionDrafts(answers: Record<string, string>, ids: string[]) {
  return ids.map(id => `-- solution:${id}\n${answers[id] || ""}`).join("\n\n");
}

export function submittedQuestionScript(script: string, part: string, id: string) {
  const parts = [...script.matchAll(/^-- (DDL|DML|SQL|TRIGGER)\r?\n/gm)];
  if (parts.length) {
    const index = parts.findIndex(marker => marker[1] === part);
    if (index < 0) return "";
    const marker=parts[index];
    script=script.slice((marker.index || 0) + marker[0].length,parts[index+1]?.index ?? script.length);
  }
  if (!id) return script.trimEnd();
  return readQuestionDrafts(script,[id])[id] || "";
}
