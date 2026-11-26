import {ProfilePage} from "@/features/student/ProfilePage";
import {ExamLoginPage} from "@/features/office/ExamSessionApp";
import {apiFetch, ApiError} from "@/shared/api/apiClient";
import {ProctorPage, ProctorRoomsView} from "@/features/office/ProctorPage";
import {AdminAccessPage} from "@/features/admin/AccessPages";
import {ExaminationsPage} from "@/features/office/ExaminationsPage";
import {ExamAdmissionsPage} from "@/features/office/ExamAdmissionsPage";
import {MyRegradesPage} from "@/features/teacher/MyRegradesPage";
import {ExaminationBuilderPage} from "@/features/teacher/builder/BuilderPages";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import { useLanguage } from "@/shared/context/LanguageContext";
import { AssignmentWorkPage } from "@/features/student/AssignmentWorkPage";
import { lazy, Suspense, useEffect } from "react";
import { APP_NAME } from "@/data/models";
import { Navigate, Route, Routes, useLocation, useNavigate } from "react-router-dom";
import { useAuth } from "@/app/providers/AuthContext";
import { AdminShell, OfficeShell, Shell, TeacherShell } from "@/app/shells/Shells";
import {
  CapabilityGuard,
  AdminRoute,
  ProtectedRoute,
  PublicOnlyRoute,
  TeacherRoute,
  OfficeRoute,
  HomeRedirect,
  AreaIndex,
  ExtraHome,
} from "@/app/routes/ProtectedRoute";
import {
  ForgotPasswordPage,
  LoginPage,
  ResetPasswordPage,
  VerifyOtpPage,
} from "@/features/auth/AuthPages";
import { DashboardPage } from "@/features/student/DashboardPage";
import { StudentExaminationsPage } from "@/features/student/StudentExaminationsPage";
import { StudentExaminationDetailPage } from "@/features/student/StudentExaminationDetailPage";
import { Loading } from "@/shared/ui/ui";
const WorkspacePage = lazy(() =>
  import("@/features/workspace/WorkspacePage").then((module) => ({
    default: module.WorkspacePage,
  })),
);
const ExamSessionWorkspacePage = lazy(() =>
  import("@/features/workspace/ExamSessionWorkspacePage").then((module) => ({
    default: module.ExamSessionWorkspacePage,
  })),
);
import { AssignmentsPage } from "@/features/student/AssignmentsPage";
import { AssignmentDetailPage } from "@/features/student/AssignmentDetailPage";
import { SubmissionsPage } from "@/features/student/SubmissionsPage";
import { ProblemEditorPage } from "@/features/teacher/ProblemEditorPage";
import { DatabaseLibraryPage } from "@/features/teacher/DatabaseLibraryPage";
import { AssignmentBuilderPage, ExamSessionBuilderPage } from "@/features/teacher/builder/BuilderPages";
import { AssignmentsListPage, ProblemLibraryPage } from "@/features/teacher/TeacherLandingPages";
import { ExamSessionsListPage } from "@/features/teacher/ExamSessionsListPage";
import { ClassesPage } from "@/features/teacher/ClassesPage";
import { ManualReviewPage, ResultsDashboardPage } from "@/features/teacher/ResultsPages";
import { ActivityResultsPage } from "@/features/teacher/ActivityResultsPage";
import { StatisticsPage } from "@/features/teacher/statistics/StatisticsPage";
import { OfficeStatisticsPage } from "@/features/office/OfficeStatisticsPage";
import {
  AdminCoursesPage,
  AdminEditClassPage,
  AdminEditAccountPage,
  AdminLecturersPage,
  AdminOverviewPage,
  AdminUsersPage,
} from "@/features/admin/AdminPages";
import { ExerciseTagsPage } from "@/features/teacher/ExerciseTagsPage";
export function App() {
  const { t } = useLanguage();
  useEffect(() => {
    document.title = APP_NAME;
  }, []);
  const { session } = useAuth();
  const location = useLocation();
  const navigate = useNavigate();
  useEffect(() => {
    if (!session?.examSessionId) return;
    let active = true;
    let running = false;
    const refresh = async () => {
      if (running) return;
      running = true;
      try {
        const result = await apiFetch<{ locked: boolean; violationLocked?: boolean }>("/api/exam-auth/status");
        if (active && result.locked && !result.violationLocked && location.pathname !== "/exam-login") {
          window.dispatchEvent(new Event("exam-access-locked"));
          navigate("/exam-login", { replace: true });
        }
      } catch (e) {
        if (active && e instanceof ApiError && [401, 403].includes(e.status) && location.pathname !== "/exam-login") {
          window.dispatchEvent(new Event("exam-access-locked"));
          navigate("/exam-login", { replace: true });
        }
      } finally {
        running = false;
      }
    };
    void refresh();
    const timer = window.setInterval(() => void refresh(), 3000);
    return () => {
      active = false;
      window.clearInterval(timer);
    };
  }, [session?.examSessionId, location.pathname, navigate]);
  return (
    <>
      <a className="skip-link" href="#main-content">
        <TranslatedText>{t("Skip to content")}</TranslatedText>
      </a>
      <Routes>
        <Route
          path="/"
          element={<HomeRedirect />}
        />
        <Route path="/exam-login" element={<ExamLoginPage/>}/>
        <Route path="/extra" element={<ExtraHome/>}/>
        <Route path="/proctor" element={<ProctorPage/>}/>
        <Route path="/proctor/:sessionId" element={<ProctorPage/>}/>
        <Route element={<PublicOnlyRoute />}>
          <Route path="/login" element={<LoginPage />} />
          <Route path="/forgot-password" element={<ForgotPasswordPage />} />
          <Route path="/verify-otp" element={<VerifyOtpPage />} />
          <Route path="/reset-password" element={<ResetPasswordPage />} />
        </Route>
        <Route element={<ProtectedRoute />}>
          <Route element={<Shell />}>
            <Route path="/dashboard" element={<CapabilityGuard permission="practice.solve"><DashboardPage /></CapabilityGuard>} />
            <Route path="/profile" element={<ProfilePage />} />
            <Route path="/practice" element={<Navigate replace to="/dashboard" />} />
            <Route path="/assignments" element={<CapabilityGuard permission="assignment.do"><AssignmentsPage /></CapabilityGuard>} />
            <Route
              path="/assignments/work/:assignmentId"
              element={<CapabilityGuard permission="assignment.do"><AssignmentWorkPage /></CapabilityGuard>}
            />
            <Route
              path="/assignments/classes/:scopeId"
              element={<CapabilityGuard permission="assignment.do"><AssignmentDetailPage /></CapabilityGuard>}
            />
            <Route path="/examinations" element={<StudentExaminationsPage />} />
            <Route
              path="/examinations/:examinationId"
              element={<StudentExaminationDetailPage />}
            />
            <Route path="/submissions" element={<CapabilityGuard permission="submission.view"><SubmissionsPage /></CapabilityGuard>} />
          </Route>
          <Route
            path="/workspace/:problemId"
            element={
              <Suspense fallback={<Loading variant="workspace" />}>
                <WorkspacePage />
              </Suspense>
            }
          />
          <Route
            path="/examinations/:examSessionId/exam"
            element={
              <Suspense fallback={<Loading variant="workspace" />}>
                <ExamSessionWorkspacePage />
              </Suspense>
            }
          />
        </Route>
        <Route element={<TeacherRoute />}>
          <Route path="/teacher" element={<TeacherShell />}>
            <Route index element={<AreaIndex area="teacher" />} />
            <Route path="problems" element={<CapabilityGuard permission="problem.create"><ProblemLibraryPage /></CapabilityGuard>} />
            <Route path="problems/new" element={<CapabilityGuard permission="problem.create"><ProblemEditorPage /></CapabilityGuard>} />
            <Route path="problems/:problemId/edit" element={<CapabilityGuard permission="problem.create"><ProblemEditorPage /></CapabilityGuard>} />
            <Route path="exercise-tags" element={<CapabilityGuard permission="tag.manage"><ExerciseTagsPage /></CapabilityGuard>} />
            <Route path="database-library" element={<CapabilityGuard permission="library.manage"><DatabaseLibraryPage /></CapabilityGuard>} />
            <Route path="assignments" element={<CapabilityGuard permission="assignment.create"><AssignmentsListPage /></CapabilityGuard>} />
            <Route path="assignments/new" element={<CapabilityGuard permission="assignment.create"><AssignmentBuilderPage /></CapabilityGuard>} />
            <Route path="assignments/:id/edit" element={<CapabilityGuard permission="assignment.create"><AssignmentBuilderPage /></CapabilityGuard>} />
            <Route path="classes" element={<CapabilityGuard permission="class.teach"><ClassesPage /></CapabilityGuard>} />
            <Route path="exams" element={<CapabilityGuard permission="exam.proctor"><ProctorRoomsView /></CapabilityGuard>} />
            <Route path="exams/:sessionId" element={<CapabilityGuard permission="exam.proctor"><ProctorRoomsView /></CapabilityGuard>} />
            <Route path="exams/:activityId/grade" element={<CapabilityGuard permission="results.grade"><ActivityResultsPage /></CapabilityGuard>} />
            <Route path="exams/review/:submissionId" element={<CapabilityGuard permission="results.grade"><ManualReviewPage /></CapabilityGuard>} />
            <Route path="exams/:activityId/students/:studentId/problems/:problemId/review" element={<CapabilityGuard permission="results.grade"><ManualReviewPage /></CapabilityGuard>} />
            <Route path="regrade" element={<CapabilityGuard permission="results.regrade"><MyRegradesPage /></CapabilityGuard>} />
            <Route path="results" element={<CapabilityGuard permission="results.grade"><ResultsDashboardPage /></CapabilityGuard>} />
            <Route path="statistics" element={<CapabilityGuard permission="statistics.view"><StatisticsPage /></CapabilityGuard>} />
            <Route path="results/activity/:activityId" element={<CapabilityGuard permission="results.grade"><ActivityResultsPage /></CapabilityGuard>} />
            <Route path="results/activity/:activityId/students/:studentId/problems/:problemId/review" element={<CapabilityGuard permission="results.grade"><ManualReviewPage /></CapabilityGuard>} />
            <Route path="results/review/:submissionId" element={<CapabilityGuard permission="results.grade"><ManualReviewPage /></CapabilityGuard>} />
          </Route>
        </Route>
        <Route element={<OfficeRoute/>}>
          <Route path="/office" element={<OfficeShell/>}>
            <Route index element={<AreaIndex area="office"/>}/>
            <Route path="examinations" element={<CapabilityGuard permission="examination.manage"><ExaminationsPage/></CapabilityGuard>}/>
            <Route path="examinations/:examId/admissions" element={<CapabilityGuard permission="exam.credentials"><ExamAdmissionsPage/></CapabilityGuard>}/>
            <Route path="examinations/new" element={<CapabilityGuard permission="examination.manage"><ExaminationBuilderPage/></CapabilityGuard>}/>
            <Route path="examinations/:id/edit" element={<CapabilityGuard permission="examination.manage"><ExaminationBuilderPage/></CapabilityGuard>}/>
            <Route path="sessions" element={<CapabilityGuard permission="examination.manage"><ExamSessionsListPage/></CapabilityGuard>}/>
            <Route path="sessions/:id/edit" element={<CapabilityGuard permission="examination.manage"><ExamSessionBuilderPage/></CapabilityGuard>}/>
            <Route path="results" element={<CapabilityGuard permission="exam.results"><ResultsDashboardPage defaultKind="exams" onlyExams/></CapabilityGuard>}/>
            <Route path="statistics" element={<CapabilityGuard permission="statistics.faculty"><OfficeStatisticsPage/></CapabilityGuard>}/>
            <Route path="results/activity/:activityId" element={<CapabilityGuard permission="exam.results"><ActivityResultsPage/></CapabilityGuard>}/>
            <Route path="results/activity/:activityId/students/:studentId/problems/:problemId/review" element={<CapabilityGuard permission="exam.results"><ManualReviewPage/></CapabilityGuard>}/>
            <Route path="results/review/:submissionId" element={<CapabilityGuard permission="exam.results"><ManualReviewPage/></CapabilityGuard>}/>
          </Route>
        </Route>
        <Route element={<AdminRoute />}>
          <Route path="/admin" element={<AdminShell />}>
            <Route index element={<AreaIndex area="admin" />} />
            <Route path="users" element={<CapabilityGuard permission="user.manage"><AdminUsersPage /></CapabilityGuard>} />
            <Route path="users/:email/edit" element={<CapabilityGuard permission="user.manage"><AdminEditAccountPage /></CapabilityGuard>} />
            <Route path="roles" element={<CapabilityGuard permission="access.manage"><AdminAccessPage /></CapabilityGuard>} />
            <Route path="courses" element={<CapabilityGuard permission="class.manage"><AdminCoursesPage /></CapabilityGuard>} />
            <Route path="courses/classes/:classId/edit" element={<CapabilityGuard permission="class.manage"><AdminEditClassPage /></CapabilityGuard>} />
            <Route path="courses/lecturers" element={<CapabilityGuard permission="class.manage"><AdminLecturersPage /></CapabilityGuard>} />
            <Route path="assignments" element={<Navigate replace to="/admin/overview" />} />
            <Route path="lecturers" element={<Navigate replace to="/admin/courses/lecturers" />} />
            <Route path="practice" element={<Navigate replace to="/admin/overview" />} />
            <Route path="moderation" element={<Navigate replace to="/admin/overview" />} />
            <Route path="overview" element={<CapabilityGuard permission="system.overview"><AdminOverviewPage /></CapabilityGuard>} />
          </Route>
        </Route>
        <Route
          path="*"
          element={<HomeRedirect />}
        />
      </Routes>
    </>
  );
}
