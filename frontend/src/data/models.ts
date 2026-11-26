import type { QueryResult } from "@/shared/types/queryResult";
import type { DataTable, WorkspacePart } from "@/shared/types/domain";
export type { DataTable, WorkspacePart } from "@/shared/types/domain";

export const APP_NAME = "iSQL";

export type Progress = "Solved" | "In progress" | "Not started";
export type Verdict =
  | "Accepted"
  | "Wrong Answer"
  | "Runtime Error"
  | "Time Limit Exceeded"
  | "Partial"
  | "Rejected"
  | "Submitted"
  | "Pending review"
  | "Not answered";

export type ExpectedOutputSample = { dataset: number; table: DataTable };

export type DatabaseRelationDescription = { tableName: string; attributes: string; predicate: string };

export type Problem = {
    assignedVariantCode?: string;
  databaseStructureVisible?: boolean;
  databaseDescription?: DatabaseRelationDescription[];
  databaseDescriptionVisible?: boolean;
  sqlChecks?: { hasSetup?: boolean; id: string; timeLimitMs: number; description?: string; expectedSamples?: ExpectedOutputSample[]; expectedVisible?: boolean }[];
  triggerChecks?: { kind?: "schema" | "seed"; id: string; name: string; description?: string; startFrom?: "empty" | "structure" | "provided"; hasSetup?: boolean; gradingMode?: "exact" | "behavior" }[];
  ddlChecks?: { id: string; name: string; description?: string; kind?: "schema" | "seed"; startFrom?: "empty" | "structure" | "provided"; hasSetup?: boolean }[];
  dmlChecks?: { id: string; name: string; description?: string; kind?: "schema" | "seed"; startFrom?: "empty" | "structure" | "provided"; hasSetup?: boolean }[];
  workspaceParts?: WorkspacePart[];
  testCases?: { schema?: string; seedData?: string; isHidden?: boolean }[];
  schema?: string;
  seedData?: string;
  id: string;
  number: string;
  title: string;
  topic: string;
  difficulty: "Easy" | "Medium" | "Hard";
  progress: Progress;
  description: string;
  requirements: string;
  draft: string;
  tables: DataTable[];
  expected?: DataTable;
  expectedSamples?: ExpectedOutputSample[];
  hint: string;
};

export type Assignment = {
  createdAt?: string | null;
  /** ISO time the assignment opens; before it, problemIds is empty and only problemCount is known. */
  opens?: string | null;
  /** ISO deadline (date/time below are the same instant split for display). */
  closes?: string | null;
  problemCount?: number;
  examMode?: boolean;
  showScoresAfterSubmit?: boolean;
  maxSubmissions?: number | null;
  submissionCounts?: Record<string, number>;
  id: string;
  title: string;
  instructions: string;
  classIds: string[];
  date: string;
  time: string;
  status: Progress;
  problemIds: string[];
  problemProgress: Record<string, Progress>;
};

export type ExamSession = {
  admissionRequired?: boolean;
  admissionUnlocked?: boolean;
  attemptStatus?: "active" | "submitted" | null;
  finishReason?: string | null;
  serverTime?: string;
  examinationId?: string | null;
  sessionIndex?: number | null;
  room?: string;
  examViolationLimit?: number;
  proctors?: string[];
  createdAt?: string | null;
  examMode?: boolean;
  showScoresAfterSubmit?: boolean;
  maxSubmissions?: number | null;
  submissionCounts?: Record<string, number>;
  id: string;
  title: string;
  status: "Live" | "Upcoming" | "Closed";
  date: string;
  time: string;
  endTime: string;
  endDate?: string;
  scope: string;
  audienceType: "classes";
  shortDescription: string;
  description: string;
  rules: string;
  bannerUrl?: string | null;
  opensAt: string;
  closesAt: string;
  problemCount: number;
  problemDetails: { id: string; title: string; difficulty: string; topic: string; points?: number; description?: string; requirements?: string }[];
  totalPoints: number;
  score: number | null;
  rank: number | null;
  leaderboardEnabled: boolean;
  aiAllowed: boolean;
  leaderboard: { rank: number; student: string; score: number }[];
  participants?: number;
  submitters?: number;
  problemIds: string[];
  examType?: "classic" | "comprehensive";
  ddlReferenceScript?: string;
  dmlSeedScript?: string;
  ddlPoints?: number;
  dmlPoints?: number;
  sqlPoints?: number;
};

export type Submission = {
  submittedScript?: string;
  questionOnly?: boolean;
  questionPart?: WorkspacePart;
  questionId?: string;
  questionScore?: number;
  sections?: QueryResult[];
  id: string;
  problemId: string;
  source: "Practice" | "Assignments" | "ExamSession";
  context: string;
  contextTitle?: string;
  result: Verdict;
  score: number | null;
  scoreVisible?: boolean;
  submittedAt: string;
  query?: string;
  database?: string;
  appealStatus?: string;
  appealCreatedAt?: string | null;
  appealResolvedAt?: string | null;
  canAppeal?: boolean;
  evaluatedScore?: number;
  feedback?: string;
  maxScore?: number;
};

export type Deadline = {
  id: string;
  title: string;
  date: string;
  time: string;
  kind: "Assignment" | "ExamSession";
  context: string;
  to: string;
};
