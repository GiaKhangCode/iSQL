import { apiFetch } from "@/shared/api/apiClient";
import type { TeacherClass, TeacherClassMember, TeacherStageExercise, TeacherSqlSolution } from "@/data/teacherTypes";
import type { TeacherSubmissionDetail, TeacherSubmissionSummary, ReviewResult } from "@/data/submissionReview";
import type { WorkspacePart } from "@/data/models";
import type { Examination, ExaminationPlan } from "@/data/examinations";

export interface ClassCreate {
  id: string;
  course: string;
  term: string;
  startDate?: string;
  endDate?: string;
}

export interface ClassMemberAdd {
  student_id: string;
}
export type AudienceStudent = { id:string; name:string; email:string; studentNumber:string | null; coveredClasses?:string[] };

export interface DatabaseLibraryBundle {
  version:1; statement:string; ddlExercise:TeacherStageExercise; dmlExercise:TeacherStageExercise; triggerExercise:TeacherStageExercise; sqlSolutions:TeacherSqlSolution[];
}

export interface TeacherDatabaseLibraryEntry {
  bundle?:DatabaseLibraryBundle | null; revision?:number;
  id: string;
  title: string;
  createTableFileName?: string | null;
  insertIntoFileName?: string | null;
  createTableSql: string;
  insertIntoSql: string;
  datasets?: TeacherDatabaseLibraryDataset[] | null;
  createdAt: string;
  updatedAt: string;
}

export interface TeacherDatabaseLibraryDataset {
  isHidden?:boolean;
  fileName: string;
  sql: string;
}

export interface TeacherDatabaseLibraryEntryInput {
  bundle?:DatabaseLibraryBundle; expectedRevision?:number;
  title: string;
  createTableFileName: string;
  createTableSql: string;
  datasets: TeacherDatabaseLibraryDataset[];
}

export const teacherService = {
  async getExaminations(): Promise<Examination[]> { return apiFetch("/api/examinations"); },
  async getExamination(id:string): Promise<Examination> { return apiFetch(`/api/examinations/${encodeURIComponent(id)}`); },
  async saveExamination(plan:ExaminationPlan & {title:string;template:any}, id?:string): Promise<Examination> {
    return apiFetch(id?`/api/examinations/${encodeURIComponent(id)}`:"/api/examinations", {method:id?"PUT":"POST",body:JSON.stringify(plan)});
  },
  async getDatabaseLibrary(): Promise<TeacherDatabaseLibraryEntry[]> {
    return apiFetch("/api/teacher/database-library");
  },

  async createDatabaseLibraryEntry(data: TeacherDatabaseLibraryEntryInput): Promise<TeacherDatabaseLibraryEntry> {
    return apiFetch("/api/teacher/database-library", {
      method: "POST",
      body: JSON.stringify(data),
    });
  },

  async updateDatabaseLibraryEntry(id: string, data: TeacherDatabaseLibraryEntryInput): Promise<TeacherDatabaseLibraryEntry> {
    return apiFetch(`/api/teacher/database-library/${encodeURIComponent(id)}`, {
      method: "PUT",
      body: JSON.stringify(data),
    });
  },

  async deleteDatabaseLibraryEntry(id: string): Promise<void> {
    return apiFetch(`/api/teacher/database-library/${encodeURIComponent(id)}`, {
      method: "DELETE",
    });
  },

  async getClasses(): Promise<TeacherClass[]> {
    return apiFetch("/api/teacher/classes");
  },

  async createProblem(data: any): Promise<any> {
    return apiFetch("/api/problems", {
      method: "POST",
      body: JSON.stringify(data),
    });
  },

  async getAllProblems(): Promise<any[]> {
    return apiFetch("/api/problems");
  },

  async getProblem(id: string): Promise<any> {
    return apiFetch(`/api/problems/${encodeURIComponent(id)}`);
  },

  async updateProblem(id: string, data: any): Promise<any> {
    return apiFetch(`/api/problems/${encodeURIComponent(id)}`, {
      method: "PUT",
      body: JSON.stringify(data),
    });
  },

  async deleteProblem(id: string): Promise<any> {
    return apiFetch(`/api/problems/${encodeURIComponent(id)}`, {
      method: "DELETE",
    });
  },

  async validateSolution(data: any): Promise<any> {
    return apiFetch("/api/problems/validate", {
      method: "POST",
      body: JSON.stringify(data),
    });
  },

  async validateStage(data: { part: "DDL" | "DML" | "TRIGGER"; schema: string; seedData: string; question: unknown; prepareOnly?: boolean }): Promise<any> {
    return apiFetch("/api/problems/validate-stage", {
      method: "POST",
      body: JSON.stringify(data),
    });
  },

  async getAllStudents(): Promise<TeacherClassMember[]> {
    return apiFetch("/api/teacher/classes/students");
  },

  async createClass(data: ClassCreate): Promise<TeacherClass> {
    return apiFetch("/api/teacher/classes", {
      method: "POST",
      body: JSON.stringify(data),
    });
  },

  async getClassMembers(classId: string): Promise<TeacherClassMember[]> {
    return apiFetch(`/api/teacher/classes/${encodeURIComponent(classId)}/members`);
  },

  async addClassMember(classId: string, data: ClassMemberAdd): Promise<TeacherClassMember> {
    return apiFetch(`/api/teacher/classes/${encodeURIComponent(classId)}/members`, {
      method: "POST",
      body: JSON.stringify(data),
    });
  },

  async removeClassMember(classId: string, studentId: string): Promise<void> {
    return apiFetch(`/api/teacher/classes/${encodeURIComponent(classId)}/members/${encodeURIComponent(studentId)}`, {
      method: "DELETE",
    });
  },

  async importClassMembers(classId: string, file: File): Promise<any> {
    const formData = new FormData();
    formData.append("file", file);

    return apiFetch(`/api/teacher/classes/${encodeURIComponent(classId)}/members/import`, {
      method: "POST",
      body: formData,
    });
  },

  async getAssignments(): Promise<any[]> {
    return apiFetch("/api/assignments");
  },

  async getStatistics(filters: {
    kind: "assignments" | "exams" | "all";
    term: string;
    classIds: string[];
    activityIds: string[];
    dateFrom?: string;
    dateTo?: string;
  }): Promise<TeacherStatistics> {
    const params = new URLSearchParams({ kind: filters.kind });
    if (filters.term) params.set("term", filters.term);
    if (filters.dateFrom) params.set("date_from", filters.dateFrom);
    if (filters.dateTo) params.set("date_to", filters.dateTo);
    filters.classIds.forEach(id => params.append("class_ids[]", id));
    filters.activityIds.forEach(id => params.append("activity_ids[]", id));
    return apiFetch(`/api/teacher/statistics?${params}`);
  },

  async getOfficeStatistics(filters: {
    kind: "assignments" | "exams" | "all";
    term: string;
    course: string;
    examinationId: string;
    classIds: string[];
    activityIds: string[];
    dateFrom?: string;
    dateTo?: string;
  }): Promise<TeacherStatistics> {
    const params = new URLSearchParams({ kind: filters.kind });
    if (filters.term) params.set("term", filters.term);
    if (filters.course) params.set("course", filters.course);
    if (filters.examinationId) params.set("examination_id", filters.examinationId);
    if (filters.dateFrom) params.set("date_from", filters.dateFrom);
    if (filters.dateTo) params.set("date_to", filters.dateTo);
    filters.classIds.forEach(id => params.append("class_ids[]", id));
    filters.activityIds.forEach(id => params.append("activity_ids[]", id));
    return apiFetch(`/api/office/statistics?${params}`);
  },

  /** Activities the user owns plus those they grade as a class's practice instructor or regrader (results tab). */
  async getResultActivities(): Promise<any[]> {
    return apiFetch("/api/assignments?scope=results");
  },

  async getAudienceCount(audienceType: "classes", classIds: string[], studentEmails:string[]=[]): Promise<{ eligibleStudents: number; individualStudents?: number; overlappingStudents?: number; resolvedStudentEmails?:string[]; coveredIndividuals?:{id:string;name:string;email:string;studentNumber:string;classes:string[]}[] }> {
    if(studentEmails.length) return apiFetch("/api/assignments/audience-preview",{method:"POST",body:JSON.stringify({audienceType,classIds,studentEmails})});
    const params = new URLSearchParams({ audience_type: audienceType });
    classIds.forEach((id) => {
      params.append("class_ids[]", id);
    });
    return apiFetch(`/api/assignments/audience-count?${params.toString()}`);
  },
  async searchAudienceStudents(query: string, classIds:string[]=[]): Promise<{items:AudienceStudent[];total:number}> {
    const params=new URLSearchParams({q:query});
    classIds.forEach(id => {
      params.append("class_ids[]", id);
    });
    return apiFetch(`/api/assignments/students/search?${params}`);
  },

  async getAssignment(id: string): Promise<any> {
    return apiFetch(`/api/assignments/${encodeURIComponent(id)}`);
  },

  async uploadExamSessionBanner(banner: Blob, source: Blob): Promise<{ bannerUrl: string; bannerSourceUrl: string }> {
    const body = new FormData();
    body.append("banner", banner, "exam-session-banner.webp");
    body.append("source", source, "exam-session-banner-source.webp");
    return apiFetch("/api/assignments/banners", { method: "POST", body });
  },

  async createAssignment(data: any): Promise<any> {
    return apiFetch("/api/assignments", {
      method: "POST",
      body: JSON.stringify(data),
    });
  },

  async updateAssignment(id: string, data: any): Promise<any> {
    return apiFetch(`/api/assignments/${encodeURIComponent(id)}`, {
      method: "PUT",
      body: JSON.stringify(data),
    });
  },

  async deleteAssignment(id: string): Promise<any> {
    return apiFetch(`/api/assignments/${encodeURIComponent(id)}`, {
      method: "DELETE",
    });
  },

  async getStudentWork(activityId: string, studentId: string, problemId: string): Promise<TeacherSubmissionDetail> {
    return apiFetch(`/api/submissions/activity/${encodeURIComponent(activityId)}/students/${encodeURIComponent(studentId)}/problems/${encodeURIComponent(problemId)}`);
  },

  async getSubmission(id: string): Promise<TeacherSubmissionDetail> {
    return apiFetch(`/api/submissions/${encodeURIComponent(id)}`);
  },

  async updateSubmissionReview(id: string, finalScore: number, feedback: string): Promise<TeacherSubmissionDetail> {
    return apiFetch(`/api/submissions/${encodeURIComponent(id)}/review`, {
      method: "PUT",
      body: JSON.stringify({ finalScore, feedback }),
    });
  },

  /** Bài thi: chấm từng câu, điểm cuối do máy chủ cộng lại. */
  async updateSubmissionQuestionReview(id: string, questionScores: Record<string, number>, questionFeedback: Record<string, string>): Promise<TeacherSubmissionDetail> {
    return apiFetch(`/api/submissions/${encodeURIComponent(id)}/review`, {
      method: "PUT",
      body: JSON.stringify({ questionScores, questionFeedback }),
    });
  },

  async inspectSubmission(id: string, part: WorkspacePart, questionId: string): Promise<ReviewResult> {
    return apiFetch(`/api/submissions/${encodeURIComponent(id)}/inspect`, {
      method: "POST", body: JSON.stringify({ part, question_id: questionId }),
    });
  },

  async getAssignmentSubmissions(assignmentId: string): Promise<TeacherSubmissionSummary[]> {
    return apiFetch(`/api/assignments/${encodeURIComponent(assignmentId)}/submissions`);
  },
};

export type TeacherStatistics = {
  roleScope?: "teacher" | "office";
  terms: string[];
  courses?: string[];
  examinations?: { id: string; title: string; examDate: string | null }[];
  classes: { id: string; course: string; term: string; students: number }[];
  activities: { id: string; title: string; examinationId?: string | null; isExam?: boolean }[];
  kpis: {
    students: number;
    participants: number;
    below: number;
    averageScore: number | null;
    passRate?: number;
    pendingGrading?: number;
    submissions?: number;
  };
  progress: { id: string; title: string; done: number; inProgress: number; notStarted: number; total: number }[];
  distribution: { label: string; count: number }[];
  verdictBreakdown?: { label: string; count: number }[];
  trend?: { date: string; label: string; submissions: number; avgScore: number }[];
  weakCategories: { name: string; total: number; failed: number; failureRate: number }[];
  classAverages?: { id: string; course: string; students: number; participants: number; averageScore: number | null; passRate: number }[];
  officeOverview?: {
    totalExaminations: number;
    totalActivities: number;
    violationEvents: number;
    flaggedStudents: number;
    courseAverages: { course: string; students: number; participants: number; averageScore: number | null; passRate: number }[];
    regradeSummary: {
      totalRegrades: number;
      awaitingRegrade: number;
      completedRegrade: number;
      scoreChanged: number;
    };
    gradingByActivity: {
      id: string;
      title: string;
      graded: number;
      needsReview: number;
      awaitingRegrade: number;
      regraded: number;
      total: number;
    }[];
  } | null;
};
