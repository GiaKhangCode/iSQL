<?php

namespace App\Services;

use App\Models\ActivityAttempt;
use App\Models\ActivityLog;
use App\Models\Assignment;
use App\Models\ClassEnrollment;
use App\Models\ExamAccessState;
use App\Models\ExamAdmission;
use App\Models\ExamBrowserSession;
use App\Models\Examination;
use App\Models\ExamSessionPlan;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ExamAdmissionService
{
    public const COOKIE_NAME = 'sql_exam_admission';
    private const PASSWORD_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(
        protected readonly ExaminationService $examinationService
    ) {
    }

    public function listSessionCredentials(string $examId, string $sessionId, User $user): array
    {
        $this->examinationService->assertCanManageExaminations($user);
        $session = $this->resolveSession($examId, $sessionId, $user);
        $students = $this->eligibleStudentsForSession($session);

        $rows = ExamAdmission::where('session_id', $session->id)
            ->get()
            ->keyBy('student_id');

        return $students->map(function (User $student) use ($rows) {
            /** @var ExamAdmission|null $row */
            $row = $rows->get($student->id);
            return [
                'id'         => $row ? (string) $row->id : null,
                'studentId'  => (string) $student->id,
                'student'    => (string) $student->name,
                'email'      => (string) $student->email,
                'username'   => $row ? (string) $row->username : null,
                'status'     => $row ? (string) $row->status : 'not_issued',
                'generation' => $row ? (int) $row->generation : 0,
                'claimedAt'  => $row && $row->claimed_at ? $row->claimed_at->toISOString() : null,
            ];
        })->values()->all();
    }

    public function issueAllCredentials(string $examId, User $user): string
    {
        $this->examinationService->assertCanManageExaminations($user);
        $exam = $this->resolveExamination($examId, $user);
        $sessions = Assignment::where('examination_id', $exam->id)
            ->orderBy('session_index')
            ->get();

        $allRows = [];
        foreach ($sessions as $s) {
            $allRows = array_merge($allRows, $this->issueForSession($s, false));
        }

        return $this->credentialsCsv($allRows);
    }

    public function issueSessionCredentials(string $examId, string $sessionId, User $user): string
    {
        $this->examinationService->assertCanManageExaminations($user);
        $session = $this->resolveSession($examId, $sessionId, $user);
        $rows = $this->issueForSession($session, false);
        return $this->credentialsCsv($rows);
    }

    /**
     * Export credentials as CSV (for a single class) or ZIP of CSVs (when classId is null).
     * Returns [content, mediaType, filename].
     */
    public function exportClassCredentials(string $examId, string $sessionId, ?string $classId, bool $reissue, User $user): array
    {
        $this->examinationService->assertCanManageExaminations($user);
        $session = $this->resolveSession($examId, $sessionId, $user);
        $plan = ExamSessionPlan::find($session->id);
        if (!$plan || !$plan->confirmed_at) {
            // Auto-confirm if session is already published so export works smoothly
            if ($session->published) {
                ExamSessionPlan::updateOrCreate(
                    ['session_id' => $session->id],
                    ['confirmed_at' => now(), 'confirmed_by' => $user->id]
                );
            } else {
                abort(400, 'Cần chốt danh sách ca thi trước khi xuất tài khoản.');
            }
        }

        $session->loadMissing(['classes', 'selectedStudents']);
        $sessionClassIds = $session->classes->pluck('id')->all();

        if ($classId !== null && $classId !== '') {
            if ($classId !== '__individual__' && !in_array($classId, $sessionClassIds, true)) {
                abort(404, 'Lớp không thuộc ca thi này.');
            }
            $students = $this->studentsForClass($session, $classId);
            $issued = $this->issueStudents($session, $students, $classId, $reissue);
            $safeClassId = preg_replace('/[^A-Za-z0-9._-]+/', '_', $classId);
            return [
                $this->credentialsCsv($issued),
                'text/csv; charset=utf-8',
                "credentials-{$safeClassId}.csv",
            ];
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'cred_zip_') . '.zip';
        $zip = new \ZipArchive();
        $zip->open($tmpFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        foreach ($sessionClassIds as $cid) {
            $students = $this->studentsForClass($session, $cid);
            $issued = $this->issueStudents($session, $students, $cid, $reissue);
            $safeCid = preg_replace('/[^A-Za-z0-9._-]+/', '_', $cid);
            $zip->addFromString("{$safeCid}.csv", $this->credentialsCsv($issued));
        }

        $individuals = $this->individualStudents($session, $sessionClassIds);
        if ($individuals->isNotEmpty()) {
            $issued = $this->issueStudents($session, $individuals, 'individual', $reissue);
            $zip->addFromString('individual.csv', $this->credentialsCsv($issued));
        }

        $zip->close();
        $bytes = file_get_contents($tmpFile);
        @unlink($tmpFile);

        return [
            $bytes,
            'application/zip',
            "credentials-ca-{$session->session_index}.zip",
        ];
    }

    public function manageCredential(string $examId, string $sessionId, string $credentialId, string $action, string $reason, User $user): string|array
    {
        $this->examinationService->assertCanManageExaminations($user);
        $session = $this->resolveSession($examId, $sessionId, $user);
        $row = ExamAdmission::where('id', $credentialId)
            ->where('session_id', $session->id)
            ->firstOrFail();

        $reason = trim($reason);
        if ($reason === '') {
            abort(400, 'Reason is required.');
        }

        if (in_array($action, ['revoke', 'transfer'], true)) {
            $row->status = 'revoked';
            $row->revoked_at = now();
            $row->reason = "{$action}: {$reason}";
            $row->generation = ((int) $row->generation) + 1;
            $row->save();
            return ['ok' => true];
        }

        if ($action === 'reissue') {
            $student = User::findOrFail($row->student_id);
            $password = $this->randomPassword(10);
            $row->hashed_password = Hash::make($password);
            $row->initial_password = $password;
            $row->status = 'issued';
            $row->claimed_at = null;
            $row->revoked_at = null;
            $row->reason = "reissue: {$reason}";
            $row->generation = ((int) $row->generation) + 1;
            $row->save();

            return $this->credentialsCsv([[
                'sessionIndex' => $session->session_index ?: 1,
                'sessionTitle' => $session->title,
                'classId'      => '',
                'studentCode'  => $this->examinationService->studentCode($student->email, $student->id),
                'student'      => $student->name,
                'email'        => $student->email,
                'room'         => $session->room ?? '',
                'username'     => $row->username,
                'password'     => $password,
            ]]);
        }

        abort(400, 'Unsupported credential action.');
    }

    public function unlockAdmission(string $activityId, string $username, string $password, User $student): array
    {
        $activity = Assignment::findOrFail($activityId);
        if (!$activity->admission_required) {
            return [
                null,
                [
                    'admissionUnlocked' => true,
                    'serverTime'        => now()->toISOString(),
                ],
            ];
        }

        if ($activity->opens && now()->lessThan($activity->opens)) {
            abort(403, 'Ca thi chưa đến giờ mở.');
        }
        if ($activity->closes && now()->greaterThanOrEqualTo($activity->closes)) {
            abort(403, 'Ca thi đã kết thúc.');
        }

        $row = ExamAdmission::where('session_id', $activity->id)
            ->where('student_id', $student->id)
            ->first();

        if (!$row || $row->status === 'revoked') {
            abort(403, 'Tài khoản ca thi không hợp lệ hoặc đã bị thu hồi.');
        }

        $inputUsername = strtolower(trim($username));
        $passwordOk = Hash::check($password, $row->hashed_password)
            || ($row->initial_password !== null && hash_equals((string) $row->initial_password, $password));

        if (strtolower($row->username) !== $inputUsername || !$passwordOk) {
            abort(403, 'Sai tài khoản hoặc mật khẩu ca thi.');
        }

        $row->status = 'claimed';
        if (!$row->claimed_at) {
            $row->claimed_at = now();
        }
        $row->save();

        ActivityLog::create([
            'id'         => (string) Str::uuid(),
            'user_id'    => $student->id,
            'action'     => "exam_admission_unlock:{$activity->id}:{$row->id}",
            'created_at' => now(),
        ]);

        return [
            $row,
            [
                'admissionUnlocked' => true,
                'serverTime'        => now()->toISOString(),
            ],
        ];
    }

    public function buildAdmissionCookieValue(User $student, Assignment $activity, ExamAdmission $row): string
    {
        $payload = json_encode([
            'u' => (string) $student->id,
            's' => (string) $activity->id,
            'a' => (string) $row->id,
            'g' => (int) $row->generation,
        ]);
        $encoded = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
        $sig = hash_hmac('sha256', $encoded, (string) config('app.key', 'isql-secret'));
        return "{$encoded}.{$sig}";
    }

    public function hasUnlockedAdmission(?Request $request, User $student, Assignment $activity): bool
    {
        if (!$activity->admission_required) {
            return true;
        }

        $row = ExamAdmission::where('session_id', $activity->id)
            ->where('student_id', $student->id)
            ->first();

        if (!$row || $row->status !== 'claimed') {
            return false;
        }

        // 1. Check if authenticated via Exam direct login token for this session
        $token = $student->currentAccessToken();
        if ($token && is_string($token->name) && str_starts_with($token->name, "exam:{$activity->id}:")) {
            return true;
        }

        // 2. Check cookie if present
        $cookieToken = $request?->cookie(self::COOKIE_NAME);
        if (is_string($cookieToken) && str_contains($cookieToken, '.')) {
            [$encoded, $sig] = explode('.', $cookieToken, 2);
            $expected = hash_hmac('sha256', $encoded, (string) config('app.key', 'isql-secret'));
            if (hash_equals($expected, $sig)) {
                $pad = strlen($encoded) % 4;
                $b64 = $encoded . ($pad ? str_repeat('=', 4 - $pad) : '');
                $data = json_decode(base64_decode(strtr($b64, '-_', '+/')), true);
                if (
                    is_array($data)
                    && ($data['u'] ?? null) === $student->id
                    && ($data['s'] ?? null) === $activity->id
                    && ($data['a'] ?? null) === $row->id
                    && (int) ($data['g'] ?? -1) === (int) $row->generation
                ) {
                    return true;
                }
            }
        }

        // 3. Check if student unlocked admission via API or logged in via ExamBrowserSession
        $hasBrowserSession = ExamBrowserSession::where('session_id', $activity->id)
            ->where('student_id', $student->id)
            ->where('status', 'active')
            ->exists();
        if ($hasBrowserSession) {
            return true;
        }

        $hasUnlockLog = ActivityLog::where('user_id', $student->id)
            ->where('action', "exam_admission_unlock:{$activity->id}:{$row->id}")
            ->exists();

        return $hasUnlockLog;
    }

    protected function resolveExamination(string $examId, User $user): Examination
    {
        $this->examinationService->assertCanManageExaminations($user);
        $exam = Examination::findOrFail($examId);
        abort_unless($this->examinationService->canAccessExamination($exam, $user), 404, 'Examination not found.');
        return $exam;
    }

    protected function resolveSession(string $examId, string $sessionId, User $user): Assignment
    {
        $exam = $this->resolveExamination($examId, $user);
        $session = Assignment::where('id', $sessionId)
            ->where('examination_id', $exam->id)
            ->first();

        if (!$session && ctype_digit($sessionId)) {
            $session = Assignment::where('examination_id', $exam->id)
                ->where('session_index', (int) $sessionId)
                ->first();
        }

        abort_unless($session !== null, 404, 'Exam session not found.');
        return $session;
    }

    protected function eligibleStudentsForSession(Assignment $session)
    {
        $session->loadMissing(['classes', 'selectedStudents']);
        $classIds = $session->classes->pluck('id')->all();

        $enrolledIds = !empty($classIds)
            ? ClassEnrollment::whereIn('class_id', $classIds)->pluck('student_id')->all()
            : [];
        $directIds = $session->selectedStudents->pluck('id')->all();
        $ids = array_values(array_unique(array_merge($enrolledIds, $directIds)));

        if (empty($ids)) {
            return collect([]);
        }

        return User::whereIn('id', $ids)
            ->where('role', User::ROLE_STUDENT)
            ->where('status', 'Active')
            ->orderBy('name')
            ->orderBy('email')
            ->get();
    }

    protected function studentsForClass(Assignment $session, string $classId)
    {
        if ($classId === '__individual__') {
            $classIds = $session->classes->pluck('id')->all();
            return $this->individualStudents($session, $classIds);
        }

        $ids = ClassEnrollment::where('class_id', $classId)->pluck('student_id')->all();
        if (empty($ids)) {
            return collect([]);
        }

        return User::whereIn('id', $ids)
            ->where('role', User::ROLE_STUDENT)
            ->where('status', 'Active')
            ->orderBy('name')
            ->orderBy('email')
            ->get();
    }

    protected function individualStudents(Assignment $session, array $classIds)
    {
        $covered = !empty($classIds)
            ? ClassEnrollment::whereIn('class_id', $classIds)->pluck('student_id')->flip()->all()
            : [];

        $directIds = $session->selectedStudents
            ->pluck('id')
            ->filter(fn ($id) => !isset($covered[$id]))
            ->values()
            ->all();

        if (empty($directIds)) {
            return collect([]);
        }

        return User::whereIn('id', $directIds)
            ->where('role', User::ROLE_STUDENT)
            ->where('status', 'Active')
            ->orderBy('name')
            ->orderBy('email')
            ->get();
    }

    protected function issueForSession(Assignment $session, bool $reissue = true): array
    {
        $students = $this->eligibleStudentsForSession($session);
        return $this->issueStudents($session, $students, '', $reissue);
    }

    protected function issueStudents(Assignment $session, $students, string $classId, bool $reissue): array
    {
        $existing = ExamAdmission::where('session_id', $session->id)
            ->get()
            ->keyBy('student_id');

        $attemptUsers = ActivityAttempt::where('assignment_id', $session->id)
            ->pluck('user_id')
            ->flip()
            ->all();

        $issued = [];
        foreach ($students as $student) {
            /** @var ExamAdmission|null $row */
            $row = $existing->get($student->id);
            $hasAttempt = isset($attemptUsers[$student->id]);

            if ($row && ($row->status === 'claimed' || $hasAttempt) && !$reissue) {
                $password = $row->initial_password ?: '(da-su-dung-dung-cap-lai-ca-nhan)';
            } else {
                $password = (!$reissue && $row && !empty($row->initial_password))
                    ? $row->initial_password
                    : $this->randomPassword(10);

                if ($row) {
                    if ($reissue || empty($row->initial_password)) {
                        $row->hashed_password = Hash::make($password);
                        $row->initial_password = $password;
                        $row->status = 'issued';
                        $row->claimed_at = null;
                        $row->revoked_at = null;
                        $row->reason = $reissue ? 'class-reissue' : null;
                        $row->generation = ((int) $row->generation) + 1;
                        $row->save();
                    }
                } else {
                    $username = $this->uniqueUsername($session, $student);
                    $row = ExamAdmission::create([
                        'id'               => (string) Str::uuid(),
                        'examination_id'   => $session->examination_id,
                        'session_id'       => $session->id,
                        'student_id'       => $student->id,
                        'username'         => $username,
                        'hashed_password'  => Hash::make($password),
                        'initial_password' => $password,
                        'status'           => 'issued',
                        'generation'       => 1,
                    ]);
                }
            }

            ExamAccessState::firstOrCreate(
                ['session_id' => $session->id, 'student_id' => $student->id],
                ['locked' => false]
            );

            $issued[] = [
                'sessionIndex' => $session->session_index ?: 1,
                'sessionTitle' => $session->title,
                'classId'      => $classId,
                'studentCode'  => $this->examinationService->studentCode($student->email, $student->id),
                'student'      => $student->name,
                'email'        => $student->email,
                'room'         => $session->room ?? '',
                'username'     => $row->username,
                'password'     => $password,
            ];
        }

        return $issued;
    }

    protected function uniqueUsername(Assignment $session, User $student): string
    {
        $code = preg_replace('/[^a-z0-9._-]+/', '', strtolower($this->examinationService->studentCode($student->email, $student->id))) ?: '';
        return $this->examinationService->generateExamUsername((string) $student->id, (string) $code);
    }

    protected function randomPassword(int $length = 10): string
    {
        $max = strlen(self::PASSWORD_ALPHABET) - 1;
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= self::PASSWORD_ALPHABET[random_int(0, $max)];
        }
        return $out;
    }

    protected function credentialsCsv(array $rows): string
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, ['session_index', 'session_title', 'class_id', 'student_code', 'student_name', 'email', 'room', 'exam_username', 'exam_password']);
        foreach ($rows as $row) {
            fputcsv($stream, [
                $row['sessionIndex'] ?? '',
                $row['sessionTitle'] ?? '',
                $row['classId'] ?? '',
                $row['studentCode'] ?? '',
                $row['student'] ?? '',
                $row['email'] ?? '',
                $row['room'] ?? '',
                $row['username'] ?? '',
                $row['password'] ?? '',
            ]);
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        return (string) $csv;
    }
}

