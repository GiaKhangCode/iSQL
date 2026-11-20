import type { WorkspacePart } from "@/data/models";

/**
 * Điểm từng câu do giảng viên nhập khi chọn "Tùy chỉnh điểm".
 *
 * Điểm nằm trong cấu hình chọn câu của mỗi bài: `questionSelection._questionPoints[PHẦN][mã câu]`.
 * Backend (ExaminationService + Grading/QuestionScoring) chấm theo đó; không có điểm thì chia đều như cũ.
 * Một mã câu có một mức điểm dù xuất hiện ở mã đề nào.
 */

export const SCORING_EQUAL = "equal_questions";
export const SCORING_CUSTOM = "custom_questions";
export type ScoringMode = typeof SCORING_EQUAL | typeof SCORING_CUSTOM;

export type QuestionPoints = Partial<Record<WorkspacePart, Record<string, number>>>;
export type PaperIds = Partial<Record<WorkspacePart, string[]>>;
/** Một đề sinh viên nhận: một mã đề, hoặc danh sách câu đang chọn khi bài không chia mã đề. */
export type ExamPaper = { code: string | null; ids: PaperIds };

const PARTS: WorkspacePart[] = ["DDL", "DML", "SQL", "TRIGGER"];

type SelectionLike = Record<string, any> | null | undefined;

export function normalizeScoringMode(value: unknown): ScoringMode {
  return value === SCORING_CUSTOM ? SCORING_CUSTOM : SCORING_EQUAL;
}

function toPoints(value: unknown): number | null {
  const n = typeof value === "number" ? value : typeof value === "string" ? Number(value.trim().replace(",", ".")) : Number.NaN;
  return Number.isFinite(n) && n > 0 ? Math.round(n * 100) / 100 : null;
}

export function questionPointsOf(selection: SelectionLike): QuestionPoints {
  const raw = selection?._questionPoints;
  if (!raw || typeof raw !== "object") return {};
  const out: QuestionPoints = {};
  for (const part of PARTS) {
    const map = raw[part];
    if (!map || typeof map !== "object") continue;
    const clean: Record<string, number> = {};
    for (const [id, value] of Object.entries(map)) {
      const points = toPoints(value);
      if (points !== null) clean[id] = points;
    }
    if (Object.keys(clean).length) out[part] = clean;
  }
  return out;
}

export function questionPoint(selection: SelectionLike, part: WorkspacePart, id: string): number | undefined {
  return questionPointsOf(selection)[part]?.[id];
}

/** Trả về cấu hình mới với điểm của một câu; giá trị trống / không hợp lệ / <= 0 thì xóa điểm câu đó. */
export function withQuestionPoint<T extends Record<string, any>>(selection: T | undefined, part: WorkspacePart, id: string, value: unknown): T {
  const current = questionPointsOf(selection);
  const points = toPoints(value);
  const partMap = { ...(current[part] || {}) };
  if (points === null) delete partMap[id];
  else partMap[id] = points;

  const nextPoints: QuestionPoints = { ...current, [part]: partMap };
  if (!Object.keys(partMap).length) delete nextPoints[part];

  const next: Record<string, any> = { ...(selection || {}) };
  if (Object.keys(nextPoints).length) next._questionPoints = nextPoints;
  else delete next._questionPoints;
  return next as T;
}

function idList(value: unknown): string[] {
  if (Array.isArray(value)) return value.map(String);
  if (value && typeof value === "object" && Array.isArray((value as any).questionIds)) return (value as any).questionIds.map(String);
  return [];
}

export function examPapers(selection: SelectionLike, fallback: PaperIds): ExamPaper[] {
  const variants = selection?._variants;
  if (!Array.isArray(variants) || variants.length === 0) return [{ code: null, ids: fallback }];
  return variants.map((variant: any) => {
    const ids: PaperIds = {};
    for (const part of PARTS) {
      if (variant?.questions && part in variant.questions) ids[part] = idList(variant.questions[part]);
    }
    return { code: String(variant?.code ?? ""), ids };
  });
}

export function paperScore(paper: ExamPaper, points: QuestionPoints): { total: number; missing: { part: WorkspacePart; id: string }[] } {
  let cents = 0;
  const missing: { part: WorkspacePart; id: string }[] = [];
  for (const part of PARTS) {
    for (const id of paper.ids[part] || []) {
      const value = points[part]?.[id];
      if (value === undefined) missing.push({ part, id });
      else cents += Math.round(value * 100);
    }
  }
  return { total: cents / 100, missing };
}

export type ScoredProblem = { title: string; papers: ExamPaper[]; points: QuestionPoints };

/**
 * Tổng hợp điểm của một hoạt động (bài tập / ca thi) ở chế độ tùy chỉnh.
 * Điểm của bài = tổng điểm đề đầu tiên; mọi mã đề của một bài phải cùng tổng; tổng các bài phải bằng `target`.
 */
export function customScoringSummary(problems: ScoredProblem[], target = 10) {
  const problemPoints: number[] = [];
  const missing: { problem: number; code: string | null; part: WorkspacePart; id: string }[] = [];
  let unevenVariants = false;

  problems.forEach((problem, index) => {
    const scores = problem.papers.map(paper => paperScore(paper, problem.points));
    scores.forEach((score, i) => score.missing.forEach(item => missing.push({ problem: index, code: problem.papers[i].code, ...item })));
    const totals = scores.map(score => score.total);
    if (totals.some(total => Math.abs(total - totals[0]) > 0.001)) unevenVariants = true;
    problemPoints.push(totals[0] ?? 0);
  });

  const total = Math.round(problemPoints.reduce((sum, value) => sum + Math.round(value * 100), 0)) / 100;
  const issues: string[] = [];
  if (missing.length) issues.push("Enter points for every selected question.");
  if (unevenVariants) issues.push("Every exam variant must have the same total points.");
  // Còn câu chưa có điểm thì tổng chắc chắn sai; chỉ báo lỗi tổng khi mọi câu đã nhập điểm.
  if (problems.length && !missing.length && Math.abs(total - target) > 0.001) issues.push(`Question points must add up to ${target}.`);

  return { problemPoints, total, missing, issues };
}

/**
 * Cấu hình chỉ gồm danh sách câu chọn tay: bỏ luật tự sinh và mã đề (chúng chia câu ngẫu nhiên),
 * nhưng giữ `_questionPoints` vì điểm thuộc về câu hỏi.
 */
export function plainSelection<T extends Record<string, any>>(selection: T | undefined): T {
  return Object.fromEntries(Object.entries(selection || {}).filter(([key]) => !key.startsWith("_") || key === "_questionPoints")) as T;
}

/**
 * Bài gửi lên server. Backend chấm theo `_questionPoints` bất cứ khi nào có, nên ở chế độ chia đều phải bỏ điểm đi;
 * bản nháp vẫn giữ điểm để bật lại "Tùy chỉnh điểm" không mất công nhập.
 */
export function problemForSave<T extends { questionSelection?: Record<string, any> }>(problem: T, mode: ScoringMode): T {
  if (mode === SCORING_CUSTOM || !problem.questionSelection || !("_questionPoints" in problem.questionSelection)) return problem;
  const { _questionPoints: _dropped, ...rest } = problem.questionSelection;
  return { ...problem, questionSelection: rest };
}

export function formatPoints(value: number): string {
  return String(Math.round(value * 100) / 100);
}
