import {apiFetch} from "@/shared/api/apiClient";

export type RegradeReviewer = {id: string; name: string; email: string};
export type MyRegrade = {
  submissionId: string; activityId: string; studentId: string; sessionTitle: string | null; studentName: string; studentCode: string | null;
  problemTitle: string; score: number | null; maxScore?: number | null; assignedAt: string | null; completedAt: string | null;
  assignmentNote?: string | null; note?: string | null;
};

const base = "/api/regrade-assignments";

export const regradeAssignments = {
  /** Phúc khảo gắn với cả bài thi: một sinh viên trong một ca thi. */
  reviewers: (activityId?: string, studentId?: string) => apiFetch<RegradeReviewer[]>(activityId && studentId ? `${base}/reviewers?activity_id=${encodeURIComponent(activityId)}&student_id=${encodeURIComponent(studentId)}` : `${base}/reviewers`),
  assign: (activityId: string, studentId: string, reviewerId: string, note?: string) =>
    apiFetch<{ok: boolean; reviewerName: string}>(`${base}/${encodeURIComponent(activityId)}/${encodeURIComponent(studentId)}`, {
      method: "PUT",
      body: JSON.stringify({reviewerId, note: note?.trim() || undefined}),
    }),
  unassign: (activityId: string, studentId: string) => apiFetch<{ok: boolean}>(`${base}/${encodeURIComponent(activityId)}/${encodeURIComponent(studentId)}`, {method: "DELETE"}),
  mine: () => apiFetch<MyRegrade[]>(`${base}/mine`),
};
