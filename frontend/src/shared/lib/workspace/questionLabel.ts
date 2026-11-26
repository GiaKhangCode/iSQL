/**
 * Câu hỏi không còn tên riêng. Phần này dựng nhãn hiển thị từ vị trí và mô tả của câu hỏi.
 * `index` đếm từ 0 theo vị trí trong từng phần DDL/DML/DQL/Trigger.
 */

/** Dòng đầu của mô tả, bỏ tiền tố "Câu 1." và cắt ngắn. Rỗng nếu câu chưa có mô tả. */
export function questionSummary(description: string | undefined, max = 70): string {
  const first = (description || "").split(/\r?\n/).map(line => line.trim()).find(Boolean) || "";
  // Mô tả thường mở đầu bằng "Câu 1." nên bỏ đi để nhãn không lặp số câu.
  const text = first.replace(/^(câu|question)\s*\d+\s*[.:)\-–]\s*/i, "");
  return text.length > max ? `${text.slice(0, max - 1).trimEnd()}…` : text;
}

/** Toàn bộ mô tả trên một dòng (bỏ "Câu 1." đầu và xuống dòng), dùng làm tiêu đề khi không muốn cắt bớt. */
export function questionFullText(description: string | undefined): string {
  return (description || "").replace(/[\r\n]+/g, " ").trim().replace(/^(câu|question)\s*\d+\s*[.:)\-–]\s*/i, "");
}

/** "Câu 3 · dòng đầu của mô tả", hoặc chỉ "Câu 3" khi chưa có mô tả. `word` là từ đã dịch ("Câu"). */
export function questionLabel(index: number, description: string | undefined, word: string, max = 70): string {
  const summary = questionSummary(description, max);
  return summary ? `${word} ${index + 1} · ${summary}` : `${word} ${index + 1}`;
}
