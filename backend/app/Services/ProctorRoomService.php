<?php

namespace App\Services;

use App\Models\ActivityAttempt;
use App\Models\Assignment;
use App\Models\ClassEnrollment;
use App\Models\ExamAccessState;
use App\Models\ExamAdmission;
use App\Models\ExamBrowserSession;
use App\Models\StudentIdentity;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkspaceSubmissionResult;
use Illuminate\Support\Facades\DB;

/**
 * Thao tác của giám thị trong phòng thi: xem trạng thái thí sinh, mở khóa, kiểm tra/thêm/chuyển sinh viên,
 * duyệt trình duyệt đăng nhập mới. Quyền vào ca do ProctorAccessService kiểm; mỗi thao tác ghi nhật ký qua
 * ProctorNoticeService.
 */
class ProctorRoomService
{
    public function __construct(
        protected readonly ExaminationService $examinationService,
        protected readonly ExamLoginService $examLogin,
        protected readonly ProctorAccessService $access,
        protected readonly ProctorNoticeService $notices,
    ) {
    }

    /**
     * GET /proctor/sessions/{id}: từng thí sinh của ca (hoặc của một phòng), kèm khóa, vi phạm và trình duyệt.
     *
     * @return array<int, array<string, mixed>>
     */
    public function roomStatus(User $user, string $sessionId, ?string $roomId): array
    {
        $session = $this->access->requireSession($sessionId, $user, $roomId);

        $session->loadMissing(['classes', 'selectedStudents']);
        $sessionClassIds = $session->classes->pluck('id')->all();

        $enrollments = !empty($sessionClassIds)
            ? ClassEnrollment::whereIn('class_id', $sessionClassIds)->get()
            : collect([]);

        $studentClassMap = [];
        foreach ($enrollments as $en) {
            if (!isset($studentClassMap[$en->student_id])) {
                $studentClassMap[$en->student_id] = $en->class_id;
            }
        }

        if ($roomId) {
            $roomStudentIds = $enrollments->where('class_id', $roomId)->pluck('student_id')->all();
            $directIds = $session->selectedStudents->pluck('id')
                ->filter(fn ($id) => !isset($studentClassMap[$id]) || $studentClassMap[$id] === $roomId)
                ->all();
            $studentIds = array_values(array_unique(array_merge($roomStudentIds, $directIds)));
        } else {
            $enrolledIds = $enrollments->pluck('student_id')->all();
            $directIds = $session->selectedStudents->pluck('id')->all();
            $studentIds = array_values(array_unique(array_merge($enrolledIds, $directIds)));
        }

        $students = !empty($studentIds)
            ? User::whereIn('id', $studentIds)
                ->where('account_type', User::ACCOUNT_STUDENT)
                ->where('status', 'Active')
                ->orderBy('name')
                ->orderBy('email')
                ->get()
            : collect([]);

        $codes = !empty($studentIds)
            ? StudentIdentity::whereIn('user_id', $studentIds)->pluck('student_code', 'user_id')->all()
            : [];

        $admissions = ExamAdmission::where('session_id', $session->id)->get()->keyBy('student_id');
        $states = ExamAccessState::where('session_id', $session->id)->get()->keyBy('student_id');
        $attempts = ActivityAttempt::where('assignment_id', $session->id)->get()->keyBy('user_id');

        $browsersGrouped = [];
        $browserRows = ExamBrowserSession::where('session_id', $session->id)
            ->orderByDesc('started_at')
            ->get();
        foreach ($browserRows as $b) {
            // Trình duyệt đã bị thu hồi không còn ý nghĩa với giám thị nên không trả về.
            if ($b->status === 'revoked') {
                continue;
            }
            $startedAt = $b->started_at ? $b->started_at->toISOString() : null;
            $browsersGrouped[$b->student_id][] = [
                'id'         => (string) $b->id,
                'code'       => (string) $b->browser_code,
                'status'     => (string) $b->status,
                'ipAddress'  => $b->ip_address,
                'userAgent'  => (string) ($b->user_agent ?? ''),
                'startedAt'  => $startedAt,
                'createdAt'  => $startedAt, // tên khóa mà frontend (kiểu Candidate) và bản SQL-UIT dùng
                'lastSeenAt' => $b->last_seen_at ? $b->last_seen_at->toISOString() : null,
            ];
        }

        $vLimit = max(1, (int) ($session->exam_violation_limit ?? 2));
        $result = [];
        foreach ($students as $st) {
            $adm = $admissions->get($st->id);
            $state = $states->get($st->id);
            $attempt = $attempts->get($st->id);

            $violations = ($attempt && is_array($attempt->violations)) ? $attempt->violations : [];
            $vCount = collect($violations)->filter(fn ($v) => is_array($v) && ($v['counted'] ?? true) === true)->count();
            $vLocked = ($attempt && $attempt->status === 'submitted' && in_array($attempt->finish_reason, ['violation_limit', 'violations'], true))
                || ($vCount >= $vLimit && (!$attempt || $attempt->status !== 'submitted'));
            $dupLocked = (bool) ($state && $state->locked);

            $result[] = [
                'studentId'         => (string) $st->id,
                'studentCode'       => $codes[$st->id] ?? $this->examinationService->studentCode($st->email, $st->id),
                'name'              => (string) $st->name,
                'email'             => (string) $st->email,
                'roomId'            => $roomId ?: ($studentClassMap[$st->id] ?? null),
                'admissionId'       => $adm ? (string) $adm->id : null,
                'locked'            => $dupLocked || $vLocked,
                'isViolationLocked' => $vLocked,
                'isDuplicateLocked' => $dupLocked,
                'violationCount'    => $vCount,
                'violationLimit'    => $vLimit,
                'finishReason'      => $attempt ? $attempt->finish_reason : null,
                'incidentAt'        => $state && $state->incident_at
                    ? $state->incident_at->toISOString()
                    : ($attempt && $attempt->submitted_at && $vLocked ? $attempt->submitted_at->toISOString() : null),
                'attemptStatus'     => $attempt ? $attempt->status : ($adm && $adm->status === 'claimed' ? 'in_progress' : 'not_started'),
                'browsers'          => $browsersGrouped[$st->id] ?? [],
            ];
        }

        return $result;
    }

    /**
     * POST .../students/{studentId}/unlock: mở khóa sinh viên. Mọi sự kiện vi phạm đã ghi thành "không tính";
     * nếu bài đã bị nộp tự động vì vi phạm thì xóa các bài nộp của ca và mở lại lượt làm.
     *
     * Sinh viên phải thuộc ca (ghi danh vào lớp của ca, được thêm riêng, hoặc đã có tài khoản ca thi / lượt làm bài
     * của ca); nếu không thì 404 và không ghi gì.
     */
    public function unlockStudent(User $user, string $sessionId, string $studentId): void
    {
        $session = $this->access->requireSession($sessionId, $user);
        abort_unless($this->belongsToSession($session, $studentId), 404, 'Sinh viên không thuộc ca thi này.');

        DB::transaction(function () use ($session, $studentId, $user) {
            $state = $this->examLogin->accessState($session->id, $studentId);
            $state->locked = false;
            $state->lock_reason = null;
            $state->incident_at = null;
            $state->unlocked_at = now();
            $state->unlocked_by = $user->id;
            $state->save();

            $attempt = ActivityAttempt::where('assignment_id', $session->id)
                ->where('user_id', $studentId)
                ->first();

            if ($attempt) {
                $updatedViolations = [];
                foreach ((array) $attempt->violations as $event) {
                    if (is_array($event)) {
                        $updatedViolations[] = array_merge($event, [
                            'counted'  => false,
                            'unlocked' => true,
                        ]);
                    }
                }
                $attempt->violations = $updatedViolations;

                if ($attempt->status === 'submitted' && in_array($attempt->finish_reason, ['violation_limit', 'violations'], true)) {
                    $subIds = Submission::where('user_id', $studentId)
                        ->where('context', $session->id)
                        ->whereIn('source', ['Assignments', 'ExamSession', 'Assignment'])
                        ->pluck('id')
                        ->all();

                    if (!empty($subIds)) {
                        WorkspaceSubmissionResult::whereIn('submission_id', $subIds)->delete();
                        Submission::whereIn('id', $subIds)->delete();
                    }

                    $attempt->status = 'in_progress';
                    $attempt->submitted_at = null;
                    $attempt->finish_reason = null;
                }

                $attempt->save();
            }

            $this->notices->record($user, 'unlock', $session->id, $studentId);
        });
    }

    /**
     * POST .../students/check: sinh viên này đã ở ca nào của kỳ thi và có thêm/chuyển vào ca này được không.
     * status: already_in_session | in_other_session | already_participated | eligible.
     *
     * @return array<string, mixed>
     */
    public function checkStudent(User $user, string $sessionId, ?string $roomId, string $studentCode): array
    {
        return $this->evaluate($user, $sessionId, $roomId, $studentCode)[1];
    }

    /**
     * POST .../students/add: thêm sinh viên vào ca (chuyển từ ca khác nếu chưa tham gia thi). Trả thông tin kiểm
     * tra và thông tin tài khoản ca thi nếu ca bắt buộc đăng nhập tài khoản ca thi.
     *
     * @return array{student: array<string, mixed>, credentials: ?array<string, mixed>}
     */
    public function addStudent(User $user, string $sessionId, ?string $roomId, string $studentCode): array
    {
        [$session, $checkData] = $this->evaluate($user, $sessionId, $roomId, $studentCode);
        if (empty($checkData['canAdd'])) {
            abort(400, $checkData['message'] ?? 'Không thể thêm sinh viên.');
        }

        $student = User::findOrFail($checkData['studentId']);

        return DB::transaction(function () use ($session, $student, $checkData, $user) {
            $transfer = ($checkData['status'] ?? '') === 'in_other_session' && !empty($checkData['otherSessionId']);
            if ($transfer) {
                $otherId = $checkData['otherSessionId'];
                DB::table('assignment_students')
                    ->where('assignment_id', $otherId)
                    ->where('student_id', $student->id)
                    ->delete();

                $otherAdms = ExamAdmission::where('session_id', $otherId)
                    ->where('student_id', $student->id)
                    ->get();

                foreach ($otherAdms as $adm) {
                    $adm->status = 'revoked';
                    $adm->revoked_at = now();
                    $adm->reason = "transfer_to:{$session->id}";
                    $adm->generation = ((int) $adm->generation) + 1;
                    $adm->save();
                }
            }

            $exists = DB::table('assignment_students')
                ->where('assignment_id', $session->id)
                ->where('student_id', $student->id)
                ->exists();

            if (!$exists) {
                DB::table('assignment_students')->insert([
                    'assignment_id' => $session->id,
                    'student_id'    => $student->id,
                    'created_at'    => now(),
                ]);
            }

            $credentials = null;
            if ($session->admission_required) {
                $admData = $this->examinationService->ensureSessionStudentAccounts($session->refresh(), $session->examination_id);
                $matched = collect($admData['students'] ?? [])->firstWhere('studentId', $student->id);
                if ($matched) {
                    $credentials = [
                        'sessionIndex' => $session->session_index ?: 1,
                        'sessionTitle' => $session->title,
                        'classId'      => '',
                        'studentCode'  => $matched['studentCode'],
                        'student'      => $matched['name'],
                        'email'        => $matched['email'],
                        'room'         => $session->room ?? '',
                        'username'     => $matched['username'],
                        'password'     => $matched['password'],
                    ];
                }
            }

            $this->notices->record(
                $user,
                $transfer ? 'transfer_student' : 'add_student',
                $session->id,
                $student->id,
                $checkData['otherSession'] ?? ''
            );

            return ['student' => $checkData, 'credentials' => $credentials];
        });
    }

    /**
     * POST /proctor/credentials/{admissionId}/approve: duyệt một trình duyệt đang chờ (đăng nhập trùng): các
     * trình duyệt khác của tài khoản bị thu hồi, sinh viên được mở khóa. Bắt buộc ghi lý do.
     */
    public function approveBrowser(User $user, string $admissionId, string $browserId, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            abort(400, 'Cần nhập lý do duyệt đăng nhập lại.');
        }

        $adm = ExamAdmission::findOrFail($admissionId);
        $this->access->requireSession($adm->session_id, $user);

        $browser = ExamBrowserSession::where('id', $browserId)
            ->where('admission_id', $adm->id)
            ->firstOrFail();

        DB::transaction(function () use ($adm, $browser, $reason, $user) {
            $now = now();
            ExamBrowserSession::where('admission_id', $adm->id)
                ->where('id', '!=', $browser->id)
                ->update(['status' => 'revoked']);

            $browser->status = 'active';
            $browser->approved_by = $user->id;
            $browser->approval_reason = $reason;
            $browser->last_seen_at = $now;
            $browser->save();

            $adm->status = 'claimed';
            if (!$adm->claimed_at) {
                $adm->claimed_at = $now;
            }
            $adm->save();

            $state = $this->examLogin->accessState($adm->session_id, $adm->student_id);
            $state->locked = false;
            $state->lock_reason = null;
            $state->unlocked_at = $now;
            $state->unlocked_by = $user->id;
            $state->save();

            $this->notices->record($user, 'approve_browser', $adm->session_id, $adm->student_id, (string) $browser->browser_code);
        });
    }

    /**
     * Kiểm tra quyền vào ca rồi phân loại sinh viên so với ca này và các ca khác cùng kỳ thi.
     *
     * @return array{0: Assignment, 1: array<string, mixed>}
     */
    protected function evaluate(User $user, string $sessionId, ?string $roomId, string $studentCode): array
    {
        $session = $this->access->requireSession($sessionId, $user, $roomId);
        $student = $this->findStudentByCode($studentCode);
        $code = $this->examinationService->studentCode($student->email, $student->id);

        $base = [
            'studentId'   => (string) $student->id,
            'studentCode' => $code,
            'name'        => (string) $student->name,
            'email'       => (string) $student->email,
        ];

        $session->loadMissing(['classes', 'selectedStudents']);
        $sessionClassIds = $session->classes->pluck('id')->all();
        $enrolledIds = !empty($sessionClassIds)
            ? ClassEnrollment::whereIn('class_id', $sessionClassIds)->pluck('student_id')->flip()->all()
            : [];
        $directIds = $session->selectedStudents->pluck('id')->flip()->all();

        if (isset($enrolledIds[$student->id]) || isset($directIds[$student->id])) {
            return [$session, ['status' => 'already_in_session', 'canAdd' => false] + $base + [
                'message' => 'Sinh viên đã có trong danh sách ca thi này.',
            ]];
        }

        $siblings = Assignment::where('examination_id', $session->examination_id)
            ->where('id', '!=', $session->id)
            ->with(['classes', 'selectedStudents'])
            ->get();

        foreach ($siblings as $other) {
            $otherClassIds = $other->classes->pluck('id')->all();
            $inOtherClass = !empty($otherClassIds) && ClassEnrollment::whereIn('class_id', $otherClassIds)->where('student_id', $student->id)->exists();
            $inOtherDirect = $other->selectedStudents->contains('id', $student->id);

            if ($inOtherClass || $inOtherDirect) {
                $adm = ExamAdmission::where('session_id', $other->id)->where('student_id', $student->id)->first();
                $att = ActivityAttempt::where('assignment_id', $other->id)->where('user_id', $student->id)->first();
                $hasSub = Submission::where('context', $other->id)->where('user_id', $student->id)->exists();
                $claimed = ($adm && $adm->status === 'claimed') || $att !== null || $hasSub;

                // Sinh viên thuộc ca kia qua lớp thì không gỡ riêng một người được (chỉ có thể chuyển cả lớp trong kế
                // hoạch ca thi), nên không cho thêm/chuyển thay vì để họ nằm ở hai ca.
                if (!$claimed && $inOtherClass) {
                    $classId = ClassEnrollment::whereIn('class_id', $otherClassIds)->where('student_id', $student->id)->value('class_id');
                    $class = $other->classes->firstWhere('id', $classId);
                    $label = $class ? ($class->practice_class ?: $class->course ?: $class->id) : $classId;

                    return [$session, ['status' => 'in_other_session_class', 'canAdd' => false] + $base + [
                        'otherSessionId' => (string) $other->id,
                        'otherSession'   => (string) $other->title,
                        'message'        => "Sinh viên thuộc lớp {$label} của {$other->title}, không thể chuyển riêng lẻ. Hãy chuyển cả lớp trong kế hoạch ca thi.",
                    ]];
                }

                return [$session, [
                    'status' => $claimed ? 'already_participated' : 'in_other_session',
                    'canAdd' => !$claimed,
                ] + $base + [
                    'otherSessionId' => (string) $other->id,
                    'otherSession'   => (string) $other->title,
                    'message'        => $claimed
                        ? "Sinh viên đã tham gia ở {$other->title}, không thể chuyển sang ca này."
                        : "Sinh viên đang thuộc {$other->title} nhưng chưa tham gia thi. Có thể chuyển sang ca này.",
                ]];
            }
        }

        return [$session, ['status' => 'eligible', 'canAdd' => true] + $base + [
            'message' => 'Sinh viên chưa thuộc ca nào trong kỳ thi này. Có thể thêm vào ca thi.',
        ]];
    }

    /** Sinh viên thuộc ca: ghi danh vào lớp của ca, được thêm riêng, hoặc đã có tài khoản ca thi / lượt làm bài. */
    protected function belongsToSession(Assignment $session, string $studentId): bool
    {
        $classIds = $session->classes()->pluck('classes.id')->all();
        if (!empty($classIds) && ClassEnrollment::whereIn('class_id', $classIds)->where('student_id', $studentId)->exists()) {
            return true;
        }

        return DB::table('assignment_students')->where('assignment_id', $session->id)->where('student_id', $studentId)->exists()
            || ExamAdmission::where('session_id', $session->id)->where('student_id', $studentId)->exists()
            || ActivityAttempt::where('assignment_id', $session->id)->where('user_id', $studentId)->exists();
    }

    /** Tìm sinh viên Active theo MSSV, email, phần trước @ của email hoặc id. */
    protected function findStudentByCode(string $rawCode): User
    {
        $code = strtolower(trim($rawCode));
        if ($code === '') {
            abort(400, 'Vui lòng nhập MSSV hoặc email sinh viên.');
        }

        $identity = StudentIdentity::whereRaw('LOWER(student_code) = ?', [$code])->first();
        if ($identity) {
            $st = User::where('id', $identity->user_id)->where('account_type', User::ACCOUNT_STUDENT)->where('status', 'Active')->first();
            if ($st) {
                return $st;
            }
        }

        $st = User::where('account_type', User::ACCOUNT_STUDENT)
            ->where('status', 'Active')
            ->where(function ($q) use ($code) {
                $q->whereRaw('LOWER(email) = ?', [$code])
                  ->orWhereRaw('LOWER(email) LIKE ?', [$code . '@%'])
                  ->orWhere('id', $code);
            })
            ->first();

        abort_unless($st !== null, 404, 'Không tìm thấy sinh viên trong hệ thống.');
        return $st;
    }
}
