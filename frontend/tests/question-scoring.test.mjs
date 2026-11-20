import assert from "node:assert/strict";
import { build } from "esbuild";
import { rmSync } from "node:fs";

// Điểm từng câu do giảng viên nhập (chế độ "Tùy chỉnh điểm"): lưu ở _questionPoints của cấu hình chọn câu,
// backend chấm theo đó (QuestionScoring). Module thuần, không React.
const outfile = "tests/.question-scoring-bundle.mjs";
await build({
  entryPoints: ["src/features/teacher/builder/questionScoring.ts"],
  bundle: true,
  format: "esm",
  platform: "node",
  outfile,
});

try {
  const m = await import(`../${outfile}?${Date.now()}`);
  const {
    SCORING_EQUAL, SCORING_CUSTOM, normalizeScoringMode, questionPointsOf, questionPoint, withQuestionPoint,
    examPapers, paperScore, customScoringSummary, formatPoints,
  } = m;

  // ── chế độ chấm ──
  assert.equal(SCORING_EQUAL, "equal_questions");
  assert.equal(SCORING_CUSTOM, "custom_questions");
  assert.equal(normalizeScoringMode("custom_questions"), "custom_questions");
  for (const other of [undefined, null, "", "weighted", 3]) assert.equal(normalizeScoringMode(other), "equal_questions");

  // ── đọc điểm ──
  const sel = { SQL: ["a", "b"], _questionPoints: { SQL: { a: 3, b: 0, c: -1, d: "x", e: "2.5" }, DDL: { z: 1 } } };
  assert.deepEqual(questionPointsOf(sel), { SQL: { a: 3, e: 2.5 }, DDL: { z: 1 } }, "chỉ giữ số dương");
  assert.deepEqual(questionPointsOf(undefined), {});
  assert.deepEqual(questionPointsOf({ _questionPoints: "nope" }), {});
  assert.equal(questionPoint(sel, "SQL", "a"), 3);
  assert.equal(questionPoint(sel, "SQL", "b"), undefined);
  assert.equal(questionPoint(sel, "DML", "a"), undefined);

  // ── ghi điểm: không đổi object cũ, giữ mọi khóa khác (mã đề, luật) ──
  const base = { SQL: ["a"], _variants: [{ code: "1", questions: { SQL: ["a"] } }], _autoRules: { SQL: [] } };
  const next = withQuestionPoint(base, "SQL", "a", 1.5);
  assert.deepEqual(next._questionPoints, { SQL: { a: 1.5 } });
  assert.equal(base._questionPoints, undefined, "không sửa object cũ");
  assert.deepEqual(next._variants, base._variants);
  assert.deepEqual(next._autoRules, base._autoRules);
  assert.deepEqual(withQuestionPoint(next, "SQL", "b", "0.25")._questionPoints, { SQL: { a: 1.5, b: 0.25 } }, "nhận chuỗi số từ ô nhập");
  assert.deepEqual(withQuestionPoint(next, "SQL", "a", "2,5")._questionPoints, { SQL: { a: 2.5 } }, "nhận dấu phẩy thập phân");
  assert.deepEqual(withQuestionPoint(next, "SQL", "a", 1 / 3)._questionPoints, { SQL: { a: 0.33 } }, "làm tròn 2 số lẻ");
  for (const empty of [null, "", 0, -2, "abc", Number.NaN]) {
    const cleared = withQuestionPoint(next, "SQL", "a", empty);
    assert.equal("_questionPoints" in cleared, false, `xóa điểm khi nhập ${String(empty)} và bỏ luôn khóa rỗng`);
  }
  const two = withQuestionPoint(withQuestionPoint(base, "SQL", "a", 1), "DDL", "c", 3);
  assert.deepEqual(withQuestionPoint(two, "SQL", "a", null)._questionPoints, { DDL: { c: 3 } }, "bỏ phần rỗng, giữ phần khác");

  // ── các "đề" của một bài: mỗi mã đề một đề; không có mã đề thì là danh sách câu đang chọn ──
  const fallback = { DDL: ["c1"], SQL: ["s1", "s2"] };
  assert.deepEqual(examPapers({}, fallback), [{ code: null, ids: fallback }]);
  assert.deepEqual(examPapers({ _variants: [] }, fallback), [{ code: null, ids: fallback }]);
  const withVariants = { _variants: [
    { code: "1", questions: { DDL: ["c1"], SQL: ["s1"] } },
    { code: "2", questions: { SQL: { questionIds: ["s2"] } } },
  ] };
  assert.deepEqual(examPapers(withVariants, fallback), [
    { code: "1", ids: { DDL: ["c1"], SQL: ["s1"] } },
    { code: "2", ids: { SQL: ["s2"] } },
  ], "đọc cả dạng {questionIds} cũ");

  // ── tổng điểm một đề ──
  const points = { DDL: { c1: 3 }, SQL: { s1: 1 } };
  assert.deepEqual(paperScore({ code: null, ids: { DDL: ["c1"], SQL: ["s1", "s2"] } }, points), {
    total: 4, missing: [{ part: "SQL", id: "s2" }],
  });
  assert.deepEqual(paperScore({ code: null, ids: { DDL: ["c1"] } }, points), { total: 3, missing: [] });
  assert.equal(paperScore({ code: null, ids: { SQL: ["s1", "s1"] } }, { SQL: { s1: 0.1 } }).total, 0.2);
  assert.equal(paperScore({ code: null, ids: { SQL: ["a", "b", "c"] } }, { SQL: { a: 0.1, b: 0.2, c: 0.7 } }).total, 1, "không lệch số thực");

  // ── kiểm tra trước khi công bố ──
  const paper = (ids) => ({ code: null, ids });
  const exam = (papers, pts, title = "QLPT") => ({ title, papers, points: pts });
  const scale = { DDL: { c1: 3, k1: 0.5, k2: 0.5 }, TRIGGER: { t1: 1 }, SQL: { s1: 1, s2: 1, s3: 1, s4: 1, s5: 1 } };
  const full = { DDL: ["c1", "k1", "k2"], TRIGGER: ["t1"], SQL: ["s1", "s2", "s3", "s4", "s5"] };

  let r = customScoringSummary([exam([paper(full)], scale)]);
  assert.deepEqual(r.problemPoints, [10]);
  assert.equal(r.total, 10);
  assert.deepEqual(r.issues, []);

  r = customScoringSummary([exam([paper({ ...full, SQL: ["s1", "s2", "s3", "s4", "s6"] })], scale)]);
  assert.deepEqual(r.issues, ["Enter points for every selected question."]);
  assert.deepEqual(r.missing, [{ problem: 0, code: null, part: "SQL", id: "s6" }]);

  r = customScoringSummary([exam([paper(full), { code: "2", ids: { ...full, SQL: ["s1"] } }], scale)]);
  assert.ok(r.issues.includes("Every exam variant must have the same total points."));
  assert.deepEqual(r.problemPoints, [10], "điểm bài = tổng của đề đầu tiên");

  r = customScoringSummary([exam([paper({ DDL: ["c1"] })], scale), exam([paper({ SQL: ["s1", "s2"] })], scale, "QLBH")]);
  assert.deepEqual(r.problemPoints, [3, 2]);
  assert.equal(r.total, 5);
  assert.deepEqual(r.issues, ["Question points must add up to 10."]);

  r = customScoringSummary([exam([paper(full)], scale)], 20);
  assert.deepEqual(r.issues, ["Question points must add up to 20."], "tổng mục tiêu đổi được");

  r = customScoringSummary([]);
  assert.deepEqual(r, { problemPoints: [], total: 0, missing: [], issues: [] }, "chưa có bài thì không báo lỗi điểm");

  // ── chọn câu thủ công: bỏ luật / mã đề nhưng giữ điểm ──
  const mixed = { SQL: ["a"], DDL: ["c"], _autoRules: { SQL: [] }, _variants: [{ code: "1", questions: {} }], _variantCount: 2, _questionPoints: { SQL: { a: 2 } } };
  assert.deepEqual(m.plainSelection(mixed), { SQL: ["a"], DDL: ["c"], _questionPoints: { SQL: { a: 2 } } });
  assert.deepEqual(m.plainSelection(undefined), {});

  // ── lưu ở chế độ chia đều: bỏ điểm khỏi dữ liệu gửi lên (backend chấm theo _questionPoints nếu có) ──
  const problem = { id: "p1", points: 10, questionSelection: { SQL: ["a"], _variants: [], _questionPoints: { SQL: { a: 2 } } } };
  const equalSaved = m.problemForSave(problem, "equal_questions");
  assert.deepEqual(equalSaved, { id: "p1", points: 10, questionSelection: { SQL: ["a"], _variants: [] } });
  assert.deepEqual(problem.questionSelection._questionPoints, { SQL: { a: 2 } }, "không sửa bản nháp");
  assert.equal(m.problemForSave(problem, "custom_questions"), problem, "tùy chỉnh: gửi nguyên");
  assert.deepEqual(m.problemForSave({ id: "p2", points: 5 }, "equal_questions"), { id: "p2", points: 5 }, "bài chưa có cấu hình chọn câu");

  // ── hiển thị ──
  assert.equal(formatPoints(10), "10");
  assert.equal(formatPoints(0.5), "0.5");
  assert.equal(formatPoints(1 / 3), "0.33");
  console.log("question-scoring: OK");
} finally {
  rmSync(outfile, { force: true });
}
