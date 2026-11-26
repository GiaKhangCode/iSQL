import assert from "node:assert/strict";
import { build } from "esbuild";
import { writeFile, unlink } from "node:fs/promises";
import { createRequire } from "node:module";
import { tmpdir } from "node:os";
import { join } from "node:path";

const bundle = await build({
  stdin: { contents: `export * from "./src/shared/lib/sql/sqlQuestionDrafts";`, resolveDir: process.cwd(), loader: "ts" },
  bundle: true, write: false, platform: "node", format: "cjs",
});
const filename = join(tmpdir(), `question-drafts-${process.pid}.cjs`);
await writeFile(filename, bundle.outputFiles[0].contents);
try {
  const { readQuestionDrafts, writeQuestionDrafts } = createRequire(import.meta.url)(filename);
  const ids = ["a", "b", "c"];
  // Ô soạn thảo: giá trị ghi vào rồi đọc ra phải y hệt, kể cả dòng trống và khoảng trắng cuối.
  for (const typed of ["SELECT 1", "SELECT 1\n", "SELECT 1\n\n", "SELECT 1  ", "SELECT *\nFROM t\n  ", "", "\n"]) {
    for (const id of ids) {
      const stored = writeQuestionDrafts({ ...readQuestionDrafts(writeQuestionDrafts({ a: "x", b: "y", c: "z" }, ids), ids, { keepTrailing: true }), [id]: typed }, ids);
      assert.equal(readQuestionDrafts(stored, ids, { keepTrailing: true })[id], typed, `câu ${id} phải giữ nguyên ${JSON.stringify(typed)}`);
    }
  }
  // Các câu khác không bị ảnh hưởng khi gõ vào một câu.
  const stored = writeQuestionDrafts({ a: "A\n", b: "B  ", c: "C" }, ids);
  assert.deepEqual(readQuestionDrafts(stored, ids, { keepTrailing: true }), { a: "A\n", b: "B  ", c: "C" });
  // Cách đọc cũ (không tùy chọn) vẫn cắt khoảng trắng cuối để chấm/hiển thị.
  assert.deepEqual(readQuestionDrafts(stored, ids), { a: "A", b: "B", c: "C" });
  // Bản nháp một câu, không có dấu "-- solution:".
  assert.deepEqual(readQuestionDrafts("SELECT 1\n", ["a"], { keepTrailing: true }), { a: "SELECT 1\n" });
  console.log("question drafts OK");
} finally {
  await unlink(filename).catch(() => {});
}
