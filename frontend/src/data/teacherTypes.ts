import type { DataTable, DatabaseRelationDescription, WorkspacePart } from "@/data/models";

export type TeacherSqlSolution = {
  setupSql?: string;
  description?: string;
  expectedOutputs?: DataTable[];
  expectedOutputVisible?: boolean;
  id: string;
  query: string;
  enabled: boolean;
  timeLimitMs: number;
  difficulty?: string;
  category?: string;
  /** Chấm có tính thứ tự dòng không. null/undefined = tự động (tính khi đáp án có ORDER BY ngoài cùng). */
  checkRowOrder?: boolean | null;
  /** Chỉ so số cột và giá trị theo vị trí, bỏ qua tên cột. */
  ignoreColumnNames?: boolean;
};

export type TeacherStageQuestion = {
  id: string;
  description: string;
  answer: string;
  enabled: boolean;
  startFrom: "empty" | "structure" | "provided";
  setupDdl: string;
  setupDml: string;
  difficulty?: string;
  category?: string;
  /** TRIGGER only: hidden test scenarios (bắt buộc ít nhất một; chấm theo dữ liệu sau mỗi kịch bản). */
  probes?: string[];
  /** DDL only: chấm có tính thứ tự cột không (mặc định không). */
  checkColumnOrder?: boolean;
};

export type TeacherStageExercise = { questions: TeacherStageQuestion[] };

export type TeacherProblem = {
  databaseDescription?: DatabaseRelationDescription[];
  databaseDescriptionVisible?: boolean;
  workspaceParts?: WorkspacePart[];
  id: string;
  number: string;
  title: string;
  difficulty: "Easy" | "Medium" | "Hard";
  topics: string;
  statement: string;
  requirements: string;
  hints: string[];
  database: string;
  schemaFileName: string;
  schema: string;
  seedData: string;
  seedSummary: string;
  referenceSolution: string;
  sqlSolutions?: TeacherSqlSolution[];
  triggerExercise?: TeacherStageExercise;
  ddlExercise?: TeacherStageExercise;
  dmlExercise?: TeacherStageExercise;
  expectedOutputs: DataTable[];
  expectedOutputVisible: boolean;
  expectedColumns: string[];
  expectedRows: (string | number)[][];
  testCases?: { seedData: string; isHidden: boolean }[];
  canEdit?: boolean;
  canDelete?: boolean;
};

export type TeacherClass = {
  id: string;
  course: string;
  term: string;
  students: number;
  status: "Active" | "Archived" | string;
  startDate?: string;
  endDate?: string;
};

export type TeacherClassMember = {
  id?: string;
  name: string;
  email?: string;
  role: string;
  joinedAt?: string;
};
