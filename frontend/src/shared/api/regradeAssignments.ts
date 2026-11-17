import {apiFetch} from "@/shared/api/apiClient";

export type RegradeCandidate = {
  submissionId: string; sessionId: string; sessionTitle: string; examinationId: string | null; isContest?: boolean;
  studentId: string; studentName: string; studentCode: string | null; className: string | null;
  problemTitle: string; score: number | null; maxScore: number | null; submittedAt: string | null;
  appealRequested: boolean; appealReviewed: boolean;
  appealStatus?: string | null; appealReason?: string | null; appealCreatedAt?: string | null; appealResolvedAt?: string | null;
  reviewerId: string | null; reviewerName: string | null; assignedAt: string | null; completedAt: string | null;
  assignmentNote?: string | null; note?: string | null;
  excludedReviewerIds?: string[];
};
export type RegradeActivityOption = { id: string; title: string; isContest: boolean; examMode: boolean };
export type RegradeReviewer = {id: string; name: string; email: string};
export type MyRegrade = {
  submissionId: string; sessionTitle: string | null; studentName: string; studentCode: string | null;
  problemTitle: string; score: number | null; maxScore?: number | null; assignedAt: string | null; completedAt: string | null;
  appealRequested?: boolean; appealReviewed?: boolean; appealReason?: string | null;
  assignmentNote?: string | null; note?: string | null;
};
export type RegradeFilters = {examinationId?: string; sessionId?: string; q?: string; appealOnly?: boolean};

const base = "/api/regrade-assignments";

export const regradeAssignments = {
  candidates(filters: RegradeFilters = {}) {
    const params = new URLSearchParams();
    if (filters.examinationId) params.set("examination_id", filters.examinationId);
    if (filters.sessionId) params.set("session_id", filters.sessionId);
    if (filters.q?.trim()) params.set("q", filters.q.trim());
    if (filters.appealOnly) params.set("appeal_only", "true");
    return apiFetch<{rows: RegradeCandidate[]; truncated: boolean; activities?: RegradeActivityOption[]}>(`${base}/candidates?${params}`);
  },
  reviewers: (submissionId?: string) => apiFetch<RegradeReviewer[]>(submissionId ? `${base}/reviewers?submission_id=${encodeURIComponent(submissionId)}` : `${base}/reviewers`),
  assign: (submissionId: string, reviewerId: string, note?: string) =>
    apiFetch<{ok: boolean; reviewerName: string}>(`${base}/${encodeURIComponent(submissionId)}`, {
      method: "PUT",
      body: JSON.stringify({reviewerId, note: note?.trim() || undefined}),
    }),
  unassign: (submissionId: string) => apiFetch<{ok: boolean}>(`${base}/${encodeURIComponent(submissionId)}`, {method: "DELETE"}),
  mine: () => apiFetch<MyRegrade[]>(`${base}/mine`),
};
