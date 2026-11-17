<?php

namespace App\Services;

use App\Models\Assignment;
use App\Models\ClassEnrollment;
use App\Models\ExamClassProctor;
use App\Models\Examination;
use App\Models\ExamSessionPlan;
use App\Models\StudentIdentity;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ExamSessionService
{
    public function __construct(
        protected readonly ExaminationService $examinationService
    ) {
    }

    public function listProctors(User $user): array
    {
        $this->examinationService->assertCanManageExaminations($user);
        $rows = User::whereIn('role', array_merge(User::INSTRUCTOR_ROLES, [User::ROLE_DEPARTMENT_HEAD]))
            ->where('status', 'Active')
            ->orderBy('name')
            ->orderBy('email')
            ->get();

        return $rows->map(fn (User $u) => [
            'id'    => (string) $u->id,
            'name'  => (string) $u->name,
            'email' => (string) $u->email,
        ])->values()->all();
    }

    public function getSession(string $sessionId, User $user): Assignment
    {
        $this->examinationService->assertCanManageExaminations($user);
        $session = Assignment::where('id', $sessionId)
            ->whereNotNull('examination_id')
            ->firstOrFail();

        $exam = Examination::find($session->examination_id);
        if (!$exam || !$this->examinationService->canAccessExamination($exam, $user)) {
            abort(404, 'Không tìm thấy ca thi.');
        }

        return $session;
    }

    public function getSessionPlan(string $sessionId, User $user): array
    {
        $session = $this->getSession($sessionId, $user);
        return $this->serializeSessionPlan($session);
    }

    public function confirmSession(string $sessionId, User $user): array
    {
        $session = $this->getSession($sessionId, $user);

        return DB::transaction(function () use ($session, $user) {
            $plan = ExamSessionPlan::find($session->id);
            if (!$plan) {
                $plan = new ExamSessionPlan(['session_id' => $session->id]);
            }
            $plan->confirmed_at = now();
            $plan->confirmed_by = $user->id;
            $plan->save();

            $session->published = true;
            $session->show_scores_after_submit = false;
            $session->save();

            $this->examinationService->ensureSessionStudentAccounts($session, $session->examination_id);

            return $this->serializeSessionPlan($session->refresh());
        });
    }

    public function reopenSession(string $sessionId, string $reason, User $user): array
    {
        $session = $this->getSession($sessionId, $user);
        $reason = trim($reason);
        if ($reason === '') {
            abort(400, 'Vui lòng nhập lý do mở lại ca thi.');
        }

        if ($session->opens && now()->greaterThanOrEqualTo($session->opens)) {
            abort(400, 'Ca thi đã bắt đầu, không thể mở lại danh sách.');
        }

        $plan = ExamSessionPlan::find($session->id);
        if (!$plan) {
            $plan = new ExamSessionPlan(['session_id' => $session->id]);
        }
        $plan->confirmed_at = null;
        $plan->reopened_at = now();
        $plan->reopen_reason = $reason;
        $plan->save();

        return $this->serializeSessionPlan($session->refresh());
    }

    public function addIndividualStudents(string $sessionId, array $studentIds, User $user): array
    {
        $session = $this->getSession($sessionId, $user);
        $plan = ExamSessionPlan::find($session->id);
        if ($plan && $plan->confirmed_at) {
            abort(400, 'Ca thi đã chốt. Mở lại ca trước khi thêm sinh viên.');
        }

        $wantedIds = array_values(array_unique(array_filter(array_map('trim', array_map('strval', $studentIds)))));
        if (empty($wantedIds)) {
            return $this->serializeSessionPlan($session);
        }

        $students = User::whereIn('id', $wantedIds)
            ->where('role', User::ROLE_STUDENT)
            ->where('status', 'Active')
            ->get();

        if ($students->count() !== count($wantedIds)) {
            abort(400, 'Một số sinh viên không tồn tại hoặc không hoạt động.');
        }

        $existing = DB::table('assignment_students')
            ->where('assignment_id', $session->id)
            ->pluck('student_id')
            ->flip()
            ->all();

        $rows = [];
        foreach ($students as $st) {
            if (!isset($existing[$st->id])) {
                $rows[] = [
                    'assignment_id' => $session->id,
                    'student_id'    => $st->id,
                    'created_at'    => now(),
                ];
            }
        }

        if (!empty($rows)) {
            DB::table('assignment_students')->insert($rows);
        }

        return $this->serializeSessionPlan($session->refresh());
    }

    public function serializeSessionPlan(Assignment $session): array
    {
        $session->loadMissing(['classes', 'selectedStudents']);
        $plan = ExamSessionPlan::find($session->id);

        $classIds = $session->classes->pluck('id')->all();
        $enrollments = !empty($classIds)
            ? ClassEnrollment::whereIn('class_id', $classIds)->get()
            : collect([]);

        $classBuckets = [];
        foreach ($classIds as $cid) {
            $classBuckets[$cid] = [];
        }

        $studentClasses = [];
        foreach ($enrollments as $row) {
            $classBuckets[$row->class_id][] = $row->student_id;
            $studentClasses[$row->student_id][] = $row->class_id;
        }

        foreach ($session->selectedStudents as $st) {
            if (!isset($studentClasses[$st->id])) {
                $studentClasses[$st->id] = [];
            }
        }

        $allStudentIds = array_keys($studentClasses);
        $students = !empty($allStudentIds)
            ? User::whereIn('id', $allStudentIds)
                ->where('role', User::ROLE_STUDENT)
                ->where('status', 'Active')
                ->orderBy('name')
                ->orderBy('email')
                ->get()
            : collect([]);

        $codes = !empty($allStudentIds)
            ? StudentIdentity::whereIn('user_id', $allStudentIds)->pluck('student_code', 'user_id')->all()
            : [];

        $proctorMap = [];
        $proctorRows = ExamClassProctor::where('session_id', $session->id)->get();
        foreach ($proctorRows as $row) {
            $proctorMap[$row->class_id][] = $row->proctor_id;
        }

        return [
            'sessionId'     => (string) $session->id,
            'examinationId' => (string) $session->examination_id,
            'title'         => (string) $session->title,
            'confirmedAt'   => $plan && $plan->confirmed_at ? $plan->confirmed_at->toISOString() : null,
            'students'      => $students->map(fn (User $st) => [
                'id'          => (string) $st->id,
                'name'        => (string) $st->name,
                'email'       => (string) $st->email,
                'studentCode' => $codes[$st->id] ?? $this->examinationService->studentCode($st->email, $st->id),
                'classIds'    => $studentClasses[$st->id] ?? [],
            ])->values()->all(),
            'classes'       => collect($classIds)->map(fn (string $cid) => [
                'classId'    => $cid,
                'studentIds' => $classBuckets[$cid] ?? [],
                'proctorIds' => $proctorMap[$cid] ?? [],
            ])->values()->all(),
        ];
    }
}

