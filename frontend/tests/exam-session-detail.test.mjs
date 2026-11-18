import assert from "node:assert/strict";
import { build } from "esbuild";
import { JSDOM } from "jsdom";
import { writeFile, unlink } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { createRequire } from "node:module";

const dom = new JSDOM('<div id="root"></div>', { url: "http://localhost" });
for (const name of ["window", "document", "navigator", "HTMLElement", "MutationObserver", "localStorage"]) {
  Object.defineProperty(globalThis, name, { configurable: true, value: dom.window[name] });
}
globalThis.IS_REACT_ACT_ENVIRONMENT = true;
globalThis.ResizeObserver = class { observe() {} disconnect() {} };
window.matchMedia = () => ({ matches: true, addEventListener() {}, removeEventListener() {} });
Object.defineProperties(HTMLElement.prototype, {
  clientWidth: { get() { return 800; } },
  offsetWidth: { get() { return 120; } },
  offsetLeft: { get() { return this.getAttribute("role") === "tab" ? [...this.parentElement.querySelectorAll("[role=tab]")].indexOf(this) * 124 : 0; } },
});
HTMLElement.prototype.scrollTo = function (options) { this.scrollLeft = options.left ?? 0; this.dispatchEvent(new window.Event("scroll")); };
const requests = [];
globalThis.__ExamSessionApi = {
  getSubmissions: async () => {
    requests.push("submissions");
    return [{ id: "s1", context: "c1", problemId: "p2", result: "Accepted", score: 100, maxScore: 100 }];
  },
  getProblem: async (id, context) => {
    requests.push([id, context]);
    return { id, title: `Exercise ${id.slice(1)}`, description: "Return the customer records.", requirements: "Legacy problem requirements should stay hidden", difficulty: "Easy", topic: "SELECT" };
  },
};
const output = await build({
  stdin: { contents: `
    export { createElement, act } from "react";
    export { createRoot } from "react-dom/client";
    export { MemoryRouter } from "react-router-dom";
    export { ExamSessionDetailView } from "./src/shared/components/ExamSessionDetailView";
    export { examSessionOverview } from "./src/shared/lib/exam-session/examSessionOverview";
  `, resolveDir: process.cwd(), loader: "tsx" },
  bundle: true, write: false, platform: "node", format: "cjs", jsx: "automatic", loader: { ".css": "empty" },
  define: { "process.env.NODE_ENV": '"development"', "import.meta.hot": "undefined", "import.meta.env.VITE_API_URL": '""' },
  plugins: [{ name: "exam-session-api", setup(builder) {
    builder.onLoad({ filter: /services[\\/]studentApi\.ts$/ }, () => ({ contents: "export const studentApi = globalThis.__ExamSessionApi;", loader: "js" }));
  } }],
});
const filename = join(tmpdir(), `sqluit-exam-session-detail-${process.pid}.cjs`);
await writeFile(filename, output.outputFiles[0].contents);
try {
  const { createElement: h, act, createRoot, MemoryRouter, ExamSessionDetailView, examSessionOverview } = createRequire(import.meta.url)(filename);
  const summary = "Practice SQL with real data.";
  const description = `# SQL Cup\n\n${summary}\n\n## Format\n\nSolve the exercises independently.`;
  assert.equal(examSessionOverview(description, "SQL Cup", summary), "## Format\n\nSolve the exercises independently.");
  assert.equal(examSessionOverview("SQL Cup\n=======\n\nOther details.", "SQL Cup", summary), "Other details.");
  assert.equal(examSessionOverview("```sql\n# SQL Cup\nSELECT 1;\n```", "SQL Cup", summary), "```sql\n# SQL Cup\nSELECT 1;\n```");
  assert.equal(examSessionOverview(null, "SQL Cup", summary), "");
  assert.equal(examSessionOverview("SQL Cup is a timed SQL competition.", "SQL Cup", summary), "SQL Cup is a timed SQL competition.");
  const problems = Array.from({ length: 12 }, (_, index) => ({ id: `p${index + 1}`, title: `Exercise ${index + 1}`, difficulty: "Easy", topic: "SELECT", points: 10 }));
  const examSession = {
    id: "c1", title: "SQL Cup", status: "Live", scope: "All students", shortDescription: summary, description, rules: "Work independently.",
    opensAt: "2026-10-01T00:00:00Z", closesAt: "2026-10-31T00:00:00Z", date: "2026-10-01", time: "07:00", endTime: "07:00",
    problemIds: problems.map((problem) => problem.id), problemDetails: problems, problemCount: 12,
    totalPoints: 120, score: 10, rank: 1, aiAllowed: true, leaderboardEnabled: true, leaderboard: [{ rank: 1, student: "Student", score: 10 }],
  };
  let root;
  const mount = async (phase = "Live", overrides = {}, preview = false) => {
    if (root) await act(async () => root.unmount());
    root = createRoot(document.getElementById("root"));
    requests.length = 0;
    await act(async () => root.render(h(MemoryRouter, { future: { v7_startTransition: true, v7_relativeSplatPath: true } },
      h(ExamSessionDetailView, { examSession: { ...examSession, ...overrides }, phase, now: Date.parse("2026-10-29T00:00:00Z"), preview,
        getSubmissions: globalThis.__ExamSessionApi.getSubmissions, getProblem: globalThis.__ExamSessionApi.getProblem }))));
  };
  const click = async (element) => { assert.ok(element); await act(async () => element.click()); };
  const tabs = () => [...document.querySelectorAll(".exam-session-problem-slider [role=tab]")];
  const action = () => document.querySelector(".exam-session-problem-stage-header a");
  await mount();
  assert.equal(document.querySelector(".exam-session-detail-banner").nextElementSibling.firstElementChild.className, "exam-session-problem-workbench", "Problems immediately follow the banner");
  assert.equal(document.querySelectorAll("h1").length, 1, "The title is not repeated in Markdown");
  assert.equal(document.body.textContent.split(summary).length - 1, 1, "Short description appears only on the banner");
  assert.equal(document.querySelectorAll(".exam-session-context-section").length, 1, "Only the current Description field is shown below the banner");
  assert.equal(document.querySelector(".exam-session-rules"), null, "Legacy rules are no longer part of the detail page");
  assert.ok(document.querySelector(".exam-session-detail-sidebar .exam-session-context-section .exam-session-markdown"), "Description is always visible beside the problem");
  assert.equal(document.querySelector(".exam-session-context-section details, details.exam-session-context-section"), null);
  assert.equal(tabs().length, 12, "All problems use horizontal navigation, without list pagination");
  assert.equal(tabs()[0].getAttribute("aria-selected"), "true");
  assert.equal(document.querySelector(".exam-session-selected-heading").textContent.includes("Dễ"), false, "Legacy difficulty is not shown in student problem details");
  assert.ok(document.querySelector('.exam-session-problem-statement').textContent.includes('Return the customer records.'), 'The problem statement remains visible');
  assert.ok(!document.body.textContent.includes('Legacy problem requirements should stay hidden'), 'Top-level Requirements no longer appear in problem details');
  assert.equal(requests.filter((request) => Array.isArray(request)).length, 1, "Only the visible problem is fetched");
  assert.match(action().getAttribute("href"), /^\/workspace\/p1\?/);
  await click(tabs()[1]);
  assert.equal(tabs()[1].getAttribute("aria-selected"), "true");
  assert.match(action().getAttribute("href"), /^\/workspace\/p2\?/);
  assert.match(action().getAttribute("href"), /context=c1/);
  assert.ok(requests.some((request) => Array.isArray(request) && request[0] === "p2" && request[1] === "c1"));
  await click(tabs()[10]);
  assert.match(action().getAttribute("href"), /^\/workspace\/p11\?/);

  await mount("Live", { problemIds: ["p1"], problemDetails: [problems[0]], problemCount: 1 });
  assert.equal(tabs().length, 0, "The common single-problem case has no navigation strip");
  assert.equal(document.querySelectorAll(".exam-session-problem-slide").length, 1);
  assert.equal(document.querySelector(".exam-session-problem-stage-header h2").textContent, "Exercise 1");
  assert.equal(document.querySelectorAll(".exam-session-problem-stage-header a").length, 1);
  assert.match(action().getAttribute("href"), /^\/workspace\/p1\?/);

  await mount("Upcoming");
  assert.equal(tabs().length, 0);
  assert.equal(document.querySelector(".exam-session-problem-details-panel"), null);
  assert.equal(document.querySelector("a[href*='/workspace/']"), null);
  assert.equal(requests.length, 0, "Upcoming exam sessions never fetch locked problems");

  await mount("Closed");
  assert.match(action().getAttribute("href"), /closed=1/);
  assert.equal(document.querySelector(".exam-session-detail-sidebar").textContent.includes("10/120"), false, "The final score remains on the banner");

  await mount("Live", { examType: "comprehensive" });
  assert.equal(document.querySelector(".exam-session-problem-stage-header a").getAttribute("href"), "/exam-sessions/c1/exam");
  await mount("Live", {examMode:true,attemptStatus:"submitted"});
  assert.equal(action().getAttribute("href"),"/submissions","A submitted exam opens history instead of offering another start");
  assert.ok(action().textContent.includes("Xem bài thi đã nộp"));
  await mount("Live", { examType: "comprehensive" }, true);
  assert.equal(document.querySelector(".exam-session-problem-stage-header button").disabled, true);
  assert.equal(requests.length, 0, "Teacher preview does not load student attempts or problem details");
  assert.equal(document.querySelectorAll(".exam-session-problem-select, .exam-session-problem-stage-header a").length, 0);
  assert.equal(document.querySelector(".exam-session-selected-heading").textContent.includes("Dễ"), false, "Teacher preview also hides legacy difficulty");
  assert.ok(document.querySelector(".exam-session-detail-sidebar .exam-session-context-section .exam-session-markdown"));
  await act(async () => root.unmount());
  console.log("Exam session detail passed: problem-first order, duplicate copy, horizontal selection, single-problem layout, lazy loading, workspace URLs, phase gates and teacher preview.");
} finally {
  await unlink(filename);
  dom.window.close();
}
process.exit(0);
