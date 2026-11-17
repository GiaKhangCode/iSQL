import assert from "node:assert";

// Mock question catalog
const catalog = {
  SQL: [
    { id: "q1", difficulty: "Dễ", category: "DQL - Truy vấn cơ bản" },
    { id: "q2", difficulty: "Dễ", category: "DQL - Truy vấn cơ bản" },
    { id: "q3", difficulty: "Trung bình", category: "DQL - Truy vấn cơ bản" },
    { id: "q4", difficulty: "Khó", category: "DQL - Kết nối bảng" },
    { id: "q5", difficulty: "Dễ", category: "DQL - Kết nối bảng" },
  ]
};

function getMatchingQuestions(part, rule, excludeIds = new Set()) {
  const choices = (catalog[part] || []).filter((q) => !excludeIds.has(q.id));
  return choices.filter((q) => {
    if (rule.difficulty && rule.difficulty !== "ALL") {
      if (!q.difficulty) return false;
      const normalizedRule = rule.difficulty.trim().toLowerCase();
      const normalizedQ = q.difficulty.trim().toLowerCase();
      const diffMap = {
        "dễ": ["dễ", "easy"],
        "easy": ["dễ", "easy"],
        "trung bình": ["trung bình", "medium"],
        "medium": ["trung bình", "medium"],
        "khó": ["khó", "hard"],
        "hard": ["khó", "hard"],
      };
      const allowed = diffMap[normalizedRule] || [normalizedRule];
      if (!allowed.includes(normalizedQ)) return false;
    }
    if (rule.category && rule.category !== "ALL") {
      if (!q.category) return false;
      const normRule = rule.category.trim().toLowerCase();
      const normQ = q.category.trim().toLowerCase();
      if (normQ !== normRule) {
        const cleanRule = normRule.replace(/\s*\(.*?\)/g, "").trim();
        const cleanQ = normQ.replace(/\s*\(.*?\)/g, "").trim();
        if (!cleanRule || cleanRule !== cleanQ) {
          return false;
        }
      }
    }
    return true;
  });
}

// Test 1: Category chosen first -> difficulty options get counts
const ruleWithCat = { category: "DQL - Truy vấn cơ bản (SELECT)", difficulty: "ALL" };
const countEasy = getMatchingQuestions("SQL", { category: ruleWithCat.category, difficulty: "Dễ" }).length;
const countMedium = getMatchingQuestions("SQL", { category: ruleWithCat.category, difficulty: "Trung bình" }).length;
const countHard = getMatchingQuestions("SQL", { category: ruleWithCat.category, difficulty: "Khó" }).length;
const countAllDiff = getMatchingQuestions("SQL", { category: ruleWithCat.category, difficulty: "ALL" }).length;

assert.strictEqual(countEasy, 2, "2 easy questions in DQL basic");
assert.strictEqual(countMedium, 1, "1 medium question in DQL basic");
assert.strictEqual(countHard, 0, "0 hard questions in DQL basic");
assert.strictEqual(countAllDiff, 3, "3 total questions in DQL basic");

// Test 2: Difficulty chosen first -> category options get counts
const ruleWithDiff = { category: "ALL", difficulty: "Dễ" };
const countCatBasic = getMatchingQuestions("SQL", { difficulty: ruleWithDiff.difficulty, category: "DQL - Truy vấn cơ bản" }).length;
const countCatJoin = getMatchingQuestions("SQL", { difficulty: ruleWithDiff.difficulty, category: "DQL - Kết nối bảng" }).length;
const countAllCat = getMatchingQuestions("SQL", { difficulty: ruleWithDiff.difficulty, category: "ALL" }).length;

assert.strictEqual(countCatBasic, 2, "2 easy questions in basic");
assert.strictEqual(countCatJoin, 1, "1 easy question in join");
assert.strictEqual(countAllCat, 3, "3 total easy questions");

console.log("All category-difficulty count tests passed successfully!");
