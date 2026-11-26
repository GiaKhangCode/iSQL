// Stable solution IDs keep answers attached when questions are renamed or reordered.
/**
 * Tách bản nháp nhiều câu thành từng câu.
 * `keepTrailing`: dùng cho ô soạn thảo. Giữ nguyên dòng trống/khoảng trắng cuối của câu đang gõ (chỉ bỏ đúng dấu
 * ngăn cách "\n\n" do writeQuestionDrafts thêm vào). Nếu cắt khoảng trắng cuối, giá trị đưa lại cho CodeMirror sẽ khác
 * nội dung đang gõ, CodeMirror ghi đè cả văn bản: dòng vừa gõ biến mất và con trỏ nhảy về đầu.
 */
export function readQuestionDrafts(script: string, ids: string[], options: { keepTrailing?: boolean } = {}): Record<string, string> {
  const markers = [...script.matchAll(/^-- solution:([^\r\n]+)(?:\r?\n|$)/gm)];
  if (!markers.length) return ids[0] ? { [ids[0]]: script } : {};
  return Object.fromEntries(markers.map((match, index) => {
    const next = markers[index + 1];
    const body = script.slice((match.index || 0) + match[0].length, next?.index ?? script.length);
    return [match[1], options.keepTrailing ? (next ? body.replace(/\r?\n\r?\n$/, "") : body) : body.trimEnd()];
  }));
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
