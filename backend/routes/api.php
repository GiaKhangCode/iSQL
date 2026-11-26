<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ExaminationController;
use App\Http\Controllers\ExamSessionController;
use App\Http\Controllers\ExamAdmissionController;
use App\Http\Controllers\ExamLoginProctorController;
use App\Http\Controllers\RegradeAssignmentController;
use App\Http\Controllers\StatisticsController;

/*
|--------------------------------------------------------------------------
| API Routes — iSQL Backend
|--------------------------------------------------------------------------
| Tất cả các route được tổ chức theo module theo đúng Blueprint.
| Đường dẫn URL và format JSON phải tương thích 100% với Frontend React.
*/

Route::prefix('auth')->group(base_path('routes/auth.php'));
Route::prefix('admin')->group(base_path('routes/admin.php'));
Route::prefix('teacher')->group(base_path('routes/teacher.php'));
Route::prefix('student')->group(base_path('routes/student.php'));
Route::prefix('problems')->group(base_path('routes/problems.php'));
Route::prefix('assignments')->group(base_path('routes/assignments.php'));
Route::prefix('exam-sessions')->group(base_path('routes/exam_sessions.php'));
Route::prefix('submissions')->group(base_path('routes/submissions.php'));
Route::prefix('notifications')->group(base_path('routes/notifications.php'));

// Exercise Tags (Public / All roles)
Route::get('/exercise-tags', [\App\Http\Controllers\Admin\ExerciseTagController::class, 'index']);

// Direct Exam Login (Public login endpoint + Authenticated status endpoint)
Route::post('/exam-auth/login', [ExamLoginProctorController::class, 'login'])->middleware('throttle:login');
Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::get('/exam-auth/status', [ExamLoginProctorController::class, 'status']);

    // Role-based Analytics Dashboards (Lecturer & Faculty Office only)
    Route::get('/teacher/statistics', [StatisticsController::class, 'teacher'])
        ->middleware('capability:statistics.view');
    Route::get('/office/statistics', [StatisticsController::class, 'office'])
        ->middleware('capability:statistics.faculty');

    // Examinations Management & Credentials
    Route::prefix('examinations')->middleware('capability:examination.manage,exam.credentials')->group(function () {
        Route::get('/', [ExaminationController::class, 'index']);
        Route::post('/preview', [ExaminationController::class, 'preview']);
        Route::post('/', [ExaminationController::class, 'store']);
        Route::get('/{examinationId}', [ExaminationController::class, 'show']);
        Route::put('/{examinationId}', [ExaminationController::class, 'update']);
        Route::get('/{examinationId}/accounts-zip', [ExaminationController::class, 'accountsZip']);
        Route::get('/{examinationId}/sessions/{sessionId}/student-accounts', [ExaminationController::class, 'studentAccounts']);

        // Credentials
        Route::get('/{examId}/sessions/{sessionId}/credentials', [ExamAdmissionController::class, 'listCredentials']);
        Route::post('/{examId}/credentials', [ExamAdmissionController::class, 'issueAllCredentials']);
        Route::post('/{examId}/sessions/{sessionId}/credentials', [ExamAdmissionController::class, 'issueSessionCredentials']);
        Route::post('/{examId}/sessions/{sessionId}/credentials-export', [ExamAdmissionController::class, 'exportClassCredentials']);
        Route::post('/{examId}/sessions/{sessionId}/credentials/{credentialId}', [ExamAdmissionController::class, 'manageCredential']);
    });

    // Exam Admission Unlock (Student)
    Route::post('/exam-admission/{activityId}/unlock', [ExamAdmissionController::class, 'unlockAdmission']);

    // Exam Sessions Planning
    Route::prefix('exam-sessions')->middleware('capability:examination.manage,exam.credentials')->group(function () {
        Route::get('/proctors', [ExamSessionController::class, 'proctors']);
        Route::get('/{sessionId}/plan', [ExamSessionController::class, 'plan']);
        Route::post('/{sessionId}/confirm', [ExamSessionController::class, 'confirm']);
        Route::post('/{sessionId}/reopen', [ExamSessionController::class, 'reopen']);
        Route::post('/{sessionId}/individual-students', [ExamSessionController::class, 'addIndividualStudents']);
    });

    // Proctoring
    Route::prefix('proctor')->middleware('capability:exam.proctor,examination.manage')->group(function () {
        Route::get('/sessions', [ExamLoginProctorController::class, 'proctorSessions']);
        Route::get('/sessions/{sessionId}', [ExamLoginProctorController::class, 'roomStatus']);
        Route::get('/sessions/{sessionId}/notices', [ExamLoginProctorController::class, 'sessionNotices']);
        Route::post('/sessions/{sessionId}/students/{studentId}/unlock', [ExamLoginProctorController::class, 'unlockStudent']);
        Route::post('/sessions/{sessionId}/students/check', [ExamLoginProctorController::class, 'checkStudent']);
        Route::post('/sessions/{sessionId}/students/add', [ExamLoginProctorController::class, 'addStudent']);
        Route::post('/credentials/{admissionId}/approve', [ExamLoginProctorController::class, 'approveBrowser']);
    });

    // Regrade Assignments
    Route::prefix('regrade-assignments')->group(function () {
        Route::get('/mine', [RegradeAssignmentController::class, 'mine'])->middleware('capability:results.regrade');
        Route::middleware('capability:examination.manage')->group(function () {
            Route::get('/reviewers', [RegradeAssignmentController::class, 'reviewers']);
            Route::put('/{activityId}/{studentId}', [RegradeAssignmentController::class, 'assign']);
            Route::delete('/{activityId}/{studentId}', [RegradeAssignmentController::class, 'unassign']);
        });
    });
});
