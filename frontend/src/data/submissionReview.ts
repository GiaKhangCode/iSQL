import type { DataTable, WorkspacePart } from "@/data/models";
import type { SchemaTable } from "@/shared/lib/sql/parseDatabaseSchema";

export type ReviewDataset = {
  gradingMode?: "exact" | "behavior" | "mixed"; scenarios?: {scenario:number;matched:boolean;message?:string}[]; actualTriggers?: {name:string;table:string;isDisabled:boolean}[]; expectedTriggers?: {name:string;table:string;isDisabled:boolean}[];
  dataset: number; correct?: boolean; matched?: boolean; runtimeError?: boolean; message?: string;
  actualTable?: DataTable; expectedTable?: DataTable;
  actualSchema?: SchemaTable[]; expectedSchema?: SchemaTable[];
  actualTables?: DataTable[]; expectedTables?: DataTable[];
};
export type ReviewResult = {
  status?: string; message?: string; ratio?: number; datasets?: ReviewDataset[]; comparisons?: ReviewDataset[];
  actualSchema?: SchemaTable[]; expectedSchema?: SchemaTable[]; actualTables?: DataTable[]; expectedTables?: DataTable[];
};
export type ReviewQuestion = {
  sourceSubmissionId?: string | null; sourceSubmittedAt?: string | null;
  id: string; name: string; description: string; startFrom?: string; kind?: string;
  setupSql?: string; studentSql: string; referenceSql: string; setupDdl: string; setupDml: string; result?: ReviewResult | null;
};
export type ReviewSection = { part: WorkspacePart; status?: string; questions: ReviewQuestion[] };
export type TeacherSubmissionDetail = {
  isSubmitted?: boolean;
  departureCount?: number;
  examEvents?: {id:string;kind:string;at:string;counted?:boolean;durationMs?:number}[];
  id: string; student: string; problem: string; autoScore: number | null; maxScore: number; finalScore: number | null;
  status: string; attempts: string; query: string; referenceSolution?: string; submittedAt: string | null; feedback?: string;
  activityId?: string; activityTitle?: string; isExam?: boolean; examinationId?: string | null;
  canEditReview?: boolean;
  previousEvaluatedScore?: number | null;
  appealStatus?: string | null; appealReason?: string | null; appealCreatedAt?: string | null; appealResolvedAt?: string | null;
  regradeAssignment?: {
    reviewerId: string; reviewerName: string;
    note?: string | null; previousEvaluatedScore?: number | null; previousFeedback?: string | null;
    assignedAt: string | null; completedAt: string | null;
  } | null;
  reviewSections: ReviewSection[];
  attemptHistory: { id: string; number: number; submittedAt: string; autoScore: number; finalScore: number | null; status: string }[];
};
export type TeacherSubmissionSummary = {
  id: string | null;
  studentId?: string;
  problemId?: string;
  student: string;
  studentCode?: string;
  className?: string;
  variantCode?: string;
  problem: string;
  score: number | null;
  maxScore: number;
  status: string;
  submittedAt: string | null;
  parts: WorkspacePart[];
  appealRequested?: boolean;
  appealStatus?: string | null;
  appealReason?: string | null;
  appealResolvedAt?: string | null;
  regradeReviewerId?: string | null;
  regradeReviewerName?: string | null;
  regradeNote?: string | null;
  regradeCompletedAt?: string | null;
};
