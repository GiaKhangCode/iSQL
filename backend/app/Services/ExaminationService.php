<?php

namespace App\Services;

use App\Models\Assignment;
use App\Models\AssignmentProblem;
use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\ExamAccessState;
use App\Models\ExamAdmission;
use App\Models\ExamClassProctor;
use App\Models\Examination;
use App\Models\Problem;
use App\Models\ProblemSqlSolution;
use App\Models\ProblemStageExercise;
use App\Models\StudentIdentity;
use App\Models\Submission;
use App\Models\User;
use App\Services\Access\AccessControlService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ExaminationService
{
    public const DEFAULT_TIMEZONE = 'Asia/Ho_Chi_Minh';
    private const PASSWORD_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';

    public function __construct(
        protected readonly AssignmentService $assignmentService,
        protected readonly AccessControlService $access,
    ) {
    }

    public function assertCanManageExaminations(User $user): void
    {
        abort_unless(
            $this->access->canAny($user, ['examination.manage', 'exam.credentials']),
            403,
            'Bạn không có quyền quản lý kỳ thi.'
        );
    }

    public function canAccessExamination(Examination $exam, User $user): bool
    {
        return $this->access->can($user, 'examination.manage') || $exam->owner_id === $user->id;
    }

    public function listExaminations(User $user): array
    {
        $this->assertCanManageExaminations($user);

        $query = Examination::query()->orderByDesc('created_at')->orderByDesc('id');
        if (!$this->access->can($user, 'examination.manage')) {
            $query->where('owner_id', $user->id);
        }

        $exams = $query->get();
        return $exams->map(fn (Examination $exam) => $this->serializeExamination($exam))->values()->all();
    }

    public function getExamination(string $examinationId, User $user): array
    {
        $this->assertCanManageExaminations($user);
        $exam = Examination::findOrFail($examinationId);
        abort_unless($this->canAccessExamination($exam, $user), 404, 'Không tìm thấy kỳ thi.');

        return $this->serializeExamination($exam);
    }

    public function previewExamination(array $payload, User $user): array
    {
        $this->assertCanManageExaminations($user);
        $sessions = $this->normalizeSessions($payload, $user);

        return [
            'title'         => trim((string) ($payload['title'] ?? '')),
            'examDate'      => (string) ($payload['examDate'] ?? $payload['exam_date'] ?? ''),
            'timezone'      => (string) ($payload['timezone'] ?? self::DEFAULT_TIMEZONE),
            'totalSessions' => count($sessions),
            'sessions'      => $sessions,
        ];
    }

    public function createExamination(array $payload, User $user): array
    {
        $this->assertCanManageExaminations($user);
        $title = trim((string) ($payload['title'] ?? ''));
        if ($title === '') {
            abort(400, 'Vui lòng nhập tên kỳ thi.');
        }

        $sessions = $this->normalizeSessions($payload, $user);

        return DB::transaction(function () use ($payload, $title, $sessions, $user) {
            $exam = Examination::create([
                'id'        => (string) Str::uuid(),
                'owner_id'  => $user->id,
                'title'     => $title,
                'exam_date' => (string) ($payload['examDate'] ?? $payload['exam_date'] ?? date('Y-m-d')),
                'timezone'  => (string) ($payload['timezone'] ?? self::DEFAULT_TIMEZONE),
                'template'  => $this->templateJson($payload),
                'revision'  => 1,
            ]);

            foreach ($sessions as $item) {
                $session = $this->createSessionAssignment($exam, $item, $payload, $user);
                if (!empty($item['published'])) {
                    $this->ensureSessionStudentAccounts($session, $exam->id);
                }
            }

            return $this->serializeExamination($exam->refresh());
        });
    }

    public function updateExamination(string $examinationId, array $payload, User $user): array
    {
        $this->assertCanManageExaminations($user);

        return DB::transaction(function () use ($examinationId, $payload, $user) {
            $exam = Examination::where('id', $examinationId)->lockForUpdate()->firstOrFail();
            abort_unless($this->canAccessExamination($exam, $user), 404, 'Không tìm thấy kỳ thi.');

            $expectedRevision = $payload['expectedRevision'] ?? $payload['expected_revision'] ?? null;
            if ($expectedRevision !== null && (int) $expectedRevision !== (int) ($exam->revision ?? 1)) {
                abort(409, 'Kỳ thi đã được cập nhật ở phiên làm việc khác. Vui lòng tải lại trước khi lưu.');
            }

            $title = trim((string) ($payload['title'] ?? $exam->title));
            if ($title === '') {
                abort(400, 'Vui lòng nhập tên kỳ thi.');
            }

            $sessions = $this->normalizeSessions($payload, $user, $exam->id);
            $existing = Assignment::where('examination_id', $exam->id)
                ->orderBy('session_index')
                ->get()
                ->keyBy('id');

            $keptIds = [];
            foreach ($sessions as $item) {
                $sessionId = $item['id'] ?? null;
                if ($sessionId && $existing->has($sessionId)) {
                    $session = $existing->get($sessionId);
                    $this->applySessionToAssignment($session, $exam, $item, $payload, $user);
                    $keptIds[] = $session->id;
                    if ($session->published) {
                        $this->ensureSessionStudentAccounts($session, $exam->id);
                    }
                } else {
                    $created = $this->createSessionAssignment($exam, $item, $payload, $user);
                    $keptIds[] = $created->id;
                    if ($created->published) {
                        $this->ensureSessionStudentAccounts($created, $exam->id);
                    }
                }
            }

            foreach ($existing as $id => $oldSession) {
                if (!in_array($id, $keptIds, true)) {
                    $this->assertSessionRemovable($oldSession);
                    $this->deleteSessionAssignment($oldSession);
                }
            }

            $exam->title = $title;
            $exam->exam_date = (string) ($payload['examDate'] ?? $payload['exam_date'] ?? $exam->exam_date);
            $exam->timezone = (string) ($payload['timezone'] ?? self::DEFAULT_TIMEZONE);
            $exam->template = $this->templateJson($payload);
            $exam->revision = ((int) ($exam->revision ?? 1)) + 1;
            $exam->save();

            return $this->serializeExamination($exam->refresh());
        });
    }

    /**
     * Handle updating an individual session via PUT /api/assignments/{id} when it belongs to an Examination
     */
    public function saveSessionFromAssignmentUpdate(Assignment $session, array $data, User $user): array
    {
        return DB::transaction(function () use ($session, $data, $user) {
            $exam = Examination::findOrFail($session->examination_id);
            abort_unless($this->canAccessExamination($exam, $user), 403, 'Bạn không có quyền chỉnh sửa ca thi này.');

            $classIds = isset($data['class_ids'])
                ? array_values(array_unique(array_filter((array) $data['class_ids'])))
                : $session->classes()->pluck('classes.id')->all();

            $classTransfers = $data['classTransfers'] ?? $data['class_transfers'] ?? [];
            if (is_array($classTransfers) && !empty($classTransfers)) {
                $this->applyClassTransfers($exam, $session, $classIds, $classTransfers);
            }

            if (isset($data['title'])) {
                $session->title = trim((string) $data['title']);
            }
            if (array_key_exists('scoring_mode', $data)) {
                $session->scoring_mode = $data['scoring_mode'] ?: 'equal_questions';
            }
            if (array_key_exists('short_description', $data)) {
                $session->short_description = $data['short_description'];
            }
            if (array_key_exists('description', $data)) {
                $session->description = $data['description'];
            }
            if (array_key_exists('rules', $data)) {
                $session->rules = $data['rules'];
            }
            if (array_key_exists('instructions', $data)) {
                $session->instructions = $data['instructions'];
            }
            if (array_key_exists('opens', $data)) {
                $session->opens = !empty($data['opens']) ? Carbon::parse($data['opens'])->setTimezone(config('app.timezone', self::DEFAULT_TIMEZONE)) : null;
            }
            if (array_key_exists('closes', $data)) {
                $session->closes = !empty($data['closes']) ? Carbon::parse($data['closes'])->setTimezone(config('app.timezone', self::DEFAULT_TIMEZONE)) : null;
            }
            if (array_key_exists('published', $data)) {
                $session->published = (bool) $data['published'];
            }
            if (array_key_exists('hints_enabled', $data)) {
                $session->hints_enabled = (bool) $data['hints_enabled'];
            }
            if (array_key_exists('comments_enabled', $data)) {
                $session->comments_enabled = (bool) $data['comments_enabled'];
            }
            if (array_key_exists('leaderboard_enabled', $data)) {
                $session->leaderboard_enabled = (bool) $data['leaderboard_enabled'];
            }
            if (array_key_exists('ai_allowed', $data)) {
                $session->ai_allowed = (bool) $data['ai_allowed'];
            }
            if (array_key_exists('exam_mode', $data)) {
                $session->exam_mode = (bool) $data['exam_mode'];
            }
            if (array_key_exists('exam_violation_limit', $data)) {
                $session->exam_violation_limit = (int) $data['exam_violation_limit'];
            }
            if (array_key_exists('show_scores_after_submit', $data)) {
                $session->show_scores_after_submit = (bool) $data['show_scores_after_submit'];
            }
            if (array_key_exists('max_submissions', $data)) {
                $session->max_submissions = $data['max_submissions'] !== null ? (int) $data['max_submissions'] : 1;
            }
            if (array_key_exists('proctors', $data)) {
                $session->proctors = $this->cleanTextList((array) $data['proctors']);
            }
            if (array_key_exists('room', $data)) {
                $session->room = trim((string) $data['room']);
            }
            if (array_key_exists('capacity', $data)) {
                $session->capacity = $data['capacity'] !== null && $data['capacity'] !== '' ? (int) $data['capacity'] : null;
            }
            if (array_key_exists('admission_required', $data) || array_key_exists('admissionRequired', $data)) {
                $session->admission_required = (bool) ($data['admission_required'] ?? $data['admissionRequired']);
            }

            $session->save();

            // Sync classes
            $syncData = [];
            foreach ($classIds as $cid) {
                $syncData[$cid] = ['id' => (string) Str::uuid()];
            }
            $session->classes()->sync($syncData);

            // Sync students
            if (isset($data['student_emails'])) {
                $this->syncSessionStudents($session, (array) $data['student_emails'], $classIds);
            }

            // Sync problems
            if (isset($data['problems'])) {
                $this->syncSessionProblems($session, (array) $data['problems']);
            }

            if ($session->published) {
                $this->ensureSessionStudentAccounts($session, $exam->id);
            }

            $exam->revision = ((int) ($exam->revision ?? 1)) + 1;
            $exam->save();

            return $this->assignmentService->formatAssignmentResponse(
                $session->refresh()->load(['classes', 'selectedStudents', 'assignmentProblems.problem']),
                $user
            );
        });
    }

    protected function applyClassTransfers(Examination $exam, Assignment $target, array $classIds, array $transfers): void
    {
        $siblings = Assignment::where('examination_id', $exam->id)
            ->where('id', '!=', $target->id)
            ->get()
            ->keyBy('id');

        foreach ($transfers as $classId => $sourceId) {
            if (!in_array($classId, $classIds, true)) {
                continue;
            }
            $source = $siblings->get($sourceId);
            if (!$source) {
                abort(400, "Không tìm thấy ca nguồn cho lớp {$classId}.");
            }
            DB::table('assignment_classes')
                ->where('assignment_id', $source->id)
                ->where('class_id', $classId)
                ->delete();
        }
    }

    public function getSessionStudentAccounts(string $examinationId, string $sessionIdOrIndex, User $user): array
    {
        $this->assertCanManageExaminations($user);
        $exam = Examination::findOrFail($examinationId);
        abort_unless($this->canAccessExamination($exam, $user), 404, 'Không tìm thấy kỳ thi.');

        $session = Assignment::where('examination_id', $exam->id)
            ->where('id', $sessionIdOrIndex)
            ->first();

        if (!$session && ctype_digit((string) $sessionIdOrIndex)) {
            $session = Assignment::where('examination_id', $exam->id)
                ->where('session_index', (int) $sessionIdOrIndex)
                ->first();
        }

        abort_unless($session !== null, 404, 'Không tìm thấy ca thi.');

        return $this->ensureSessionStudentAccounts($session, $exam->id);
    }

    public function ensureSessionStudentAccounts(Assignment $session, ?string $examinationId = null): array
    {
        $session->loadMissing(['classes.instructor', 'classes.practiceInstructor', 'selectedStudents', 'assignmentProblems']);

        $classIds = $session->classes->pluck('id')->all();
        $classesMap = $session->classes->keyBy('id');

        $instructorIds = $session->classes->pluck('instructor_id')
            ->merge($session->classes->pluck('practice_instructor_id'))
            ->push($session->instructor_id)
            ->filter()
            ->unique()
            ->values()
            ->all();
        $instructorsMap = User::whereIn('id', $instructorIds)->get()->keyBy('id');

        $studentClassMap = [];
        $enrolledIds = [];
        if (!empty($classIds)) {
            $enrollments = ClassEnrollment::whereIn('class_id', $classIds)->get();
            foreach ($enrollments as $en) {
                $enrolledIds[] = $en->student_id;
                if (!isset($studentClassMap[$en->student_id])) {
                    $studentClassMap[$en->student_id] = $en->class_id;
                }
            }
        }

        $directIds = $session->selectedStudents->pluck('id')->all();
        $allStudentIds = array_values(array_unique(array_merge($enrolledIds, $directIds)));

        $students = !empty($allStudentIds)
            ? User::whereIn('id', $allStudentIds)
                ->where('account_type', User::ACCOUNT_STUDENT)
                ->where('status', 'Active')
                ->orderBy('name')
                ->orderBy('email')
                ->get()
            : collect([]);

        $existingAdmissions = ExamAdmission::where('session_id', $session->id)
            ->get()
            ->keyBy('student_id');

        $removedQuery = ExamAdmission::where('session_id', $session->id)
            ->where('status', '!=', 'revoked');
        if (!empty($allStudentIds)) {
            $removedQuery->whereNotIn('student_id', $allStudentIds);
        }
        $removedQuery->update([
            'status'     => 'revoked',
            'revoked_at' => now(),
            'reason'     => 'removed_from_session',
        ]);

        $existingAccessStates = ExamAccessState::where('session_id', $session->id)
            ->get()
            ->keyBy('student_id');

        $problemsPayload = $this->sessionProblemsPayload($session);
        $variantCodes = $this->extractVariantCodes($problemsPayload);
        $numVariants = max(1, count($variantCodes));

        // Chuẩn bị danh sách sinh viên cùng thông tin lớp học và MSSV
        $studentList = $students->map(function ($student) use ($studentClassMap, $classesMap, $instructorsMap, $session) {
            $cid = $studentClassMap[$student->id] ?? null;
            $cObj = $cid ? $classesMap->get($cid) : null;
            $cName = $cObj ? ($cObj->practice_class ?: $cObj->course ?: $cObj->id) : 'Thí sinh tự do';
            $sortClassKey = $cObj ? ($cObj->practice_class ?: $cObj->id) : 'zz_free';

            $mssv = $this->studentCode($student->email, $student->id);
            $sortMssvKey = $mssv ?: $student->name;

            $tId = $cObj ? ($cObj->practice_instructor_id ?: $cObj->instructor_id) : $session->instructor_id;
            $tObj = $tId ? $instructorsMap->get($tId) : null;
            $tName = $tObj ? $tObj->name : (is_array($session->proctors) && !empty($session->proctors) ? implode(', ', $session->proctors) : '');

            return [
                'user'         => $student,
                'classId'      => $cid,
                'className'    => $cName,
                'sortClassKey' => $sortClassKey,
                'mssv'         => $mssv,
                'sortMssvKey'  => $sortMssvKey,
                'teacherName'  => $tName,
            ];
        });

        // 1) Sort theo thứ tự lớp trước (sortClassKey), sau đó theo thứ tự MSSV (sortMssvKey)
        $sortedStudents = $studentList->sort(function ($a, $b) {
            $classCmp = strcmp((string) $a['sortClassKey'], (string) $b['sortClassKey']);
            if ($classCmp !== 0) {
                return $classCmp;
            }
            return strcmp((string) $a['sortMssvKey'], (string) $b['sortMssvKey']);
        })->values();

        $rows = [];
        $stt = 1;
        $prevClassKey = null;
        $classIdx = 0;
        foreach ($sortedStudents as $item) {
            $student = $item['user'];
            $mssv = $item['mssv'];
            $cName = $item['className'];
            $tName = $item['teacherName'];
            $currentClassKey = (string) $item['sortClassKey'];

            if ($prevClassKey !== $currentClassKey) {
                $classIdx = 0;
                $prevClassKey = $currentClassKey;
            }

            // 2) Trong cùng 1 lớp, chia đề lần lượt 1, 2, 3...; qua lớp khác thì reset chia lại từ mã đề 1
            $variantCode = $variantCodes[$classIdx % $numVariants];
            $classIdx++;

            if (!$existingAccessStates->has($student->id)) {
                ExamAccessState::create([
                    'session_id' => $session->id,
                    'student_id' => $student->id,
                    'locked'     => false,
                ]);
            }

            $adm = $existingAdmissions->get($student->id);

            // Tạo tài khoản thi tạm thời theo chuẩn SQL-UIT: "K7_" + 20 ký tự ngẫu nhiên
            $isOldFormat = $adm && (str_starts_with((string) $adm->username, 'sv.') || str_starts_with((string) $adm->username, 'ca'));

            if (!$adm || $isOldFormat) {
                $rawPwd = $this->generatePassword(10);
                $uname = $this->generateExamUsername((string) $student->id, (string) $mssv);

                if (!$adm) {
                    $adm = ExamAdmission::create([
                        'id'               => (string) Str::uuid(),
                        'examination_id'   => $examinationId ?? $session->examination_id,
                        'session_id'       => $session->id,
                        'student_id'       => $student->id,
                        'username'         => $uname,
                        'hashed_password'  => Hash::make($rawPwd),
                        'initial_password' => $rawPwd,
                        'status'           => 'issued',
                        'variant_code'     => $variantCode,
                        'generation'       => 1,
                    ]);
                } else {
                    $adm->username = $uname;
                    $adm->initial_password = $rawPwd;
                    $adm->hashed_password = Hash::make($rawPwd);
                    $adm->variant_code = $variantCode;
                    $adm->save();
                }
            } else {
                if (empty($adm->initial_password)) {
                    $rawPwd = $this->generatePassword(10);
                    $adm->initial_password = $rawPwd;
                    $adm->hashed_password = Hash::make($rawPwd);
                }
                if ($adm->status === 'revoked' && $adm->reason === 'removed_from_session') {
                    $adm->status = 'issued';
                    $adm->revoked_at = null;
                    $adm->reason = null;
                }
                $adm->variant_code = $variantCode;
                $adm->save();
            }

            $rows[] = [
                'stt'         => $stt++,
                'studentId'   => (string) $student->id,
                'studentCode' => $mssv,
                'name'        => (string) $student->name,
                'email'       => (string) $student->email,
                'className'   => (string) $cName,
                'teacherName' => (string) $tName,
                'variantCode' => $variantCode,
                'username'    => (string) $adm->username,
                'password'    => (string) ($adm->initial_password ?? ''),
                'status'      => (string) $adm->status,
            ];
        }

        $sessionTeacher = $session->instructor_id ? $instructorsMap->get($session->instructor_id) : null;
        $examDate = $this->sessionDateLocal($session->opens, $session->examination?->exam_date ?? date('Y-m-d'));

        return [
            'session' => [
                'id'           => (string) $session->id,
                'title'        => (string) $session->title,
                'sessionIndex' => (int) ($session->session_index ?: 1),
                'teacherName'  => $sessionTeacher?->name ?: (is_array($session->proctors) && !empty($session->proctors) ? implode(', ', $session->proctors) : ''),
                'teacherEmail' => $sessionTeacher?->email ?? '',
                'examDate'     => $examDate,
                'starts'       => $this->localClock($session->opens),
                'ends'         => $this->localClock($session->closes),
            ],
            'students' => $rows,
        ];
    }

    public function buildAccountsZip(string $examinationId, User $user): array
    {
        $this->assertCanManageExaminations($user);
        $exam = Examination::findOrFail($examinationId);
        abort_unless($this->canAccessExamination($exam, $user), 404, 'Không tìm thấy kỳ thi.');

        $sessions = Assignment::where('examination_id', $exam->id)
            ->orderBy('session_index')
            ->get();

        $tmpFile = tempnam(sys_get_temp_dir(), 'exam_accounts_') . '.zip';
        $zip = new \ZipArchive();
        $zip->open($tmpFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        foreach ($sessions as $s) {
            $data = $this->ensureSessionStudentAccounts($s, $exam->id);
            $sInfo = $data['session'];
            $students = $data['students'];

            $stream = fopen('php://temp', 'r+');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, ['Ca thi', $sInfo['title']]);
            fputcsv($stream, ['Ngày thi', $sInfo['examDate'], 'Giờ thi', "{$sInfo['starts']} - {$sInfo['ends']}"]);
            fputcsv($stream, ['Giảng viên phụ trách', $sInfo['teacherName']]);
            fputcsv($stream, []);
            fputcsv($stream, ['STT', 'MSSV', 'Họ và tên', 'Email', 'Lớp', 'Giảng viên', 'Mã đề', 'Tài khoản thi', 'Mật khẩu']);

            foreach ($students as $st) {
                fputcsv($stream, [
                    $st['stt'],
                    $st['studentCode'],
                    $st['name'],
                    $st['email'],
                    $st['className'],
                    $st['teacherName'],
                    $st['variantCode'],
                    $st['username'],
                    $st['password'],
                ]);
            }

            rewind($stream);
            $csvContent = stream_get_contents($stream);
            fclose($stream);

            $safeTitle = preg_replace('/[^A-Za-z0-9_-]+/', '_', "Ca_{$sInfo['sessionIndex']}_{$sInfo['examDate']}");
            $zip->addFromString("TaiKhoan_{$safeTitle}.csv", $csvContent);
        }

        $zip->close();
        $filename = "DanhSachTaiKhoanThi_{$exam->exam_date}.zip";

        return [$tmpFile, $filename];
    }

    public function studentCode(?string $email, ?string $userId = null): string
    {
        if ($userId) {
            $identity = StudentIdentity::find($userId);
            if ($identity && !empty($identity->student_code)) {
                return (string) $identity->student_code;
            }
        }
        $local = strtolower(trim(explode('@', (string) $email)[0] ?? ''));
        return $local;
    }

    public function resolveStudentVariantCode(string $studentId, ?string $sessionId, array $problems): string
    {
        if ($sessionId) {
            $adm = ExamAdmission::where('session_id', $sessionId)->where('student_id', $studentId)->first();
            if ($adm && !empty($adm->variant_code)) {
                return (string) $adm->variant_code;
            }
        }

        foreach ($problems as $prob) {
            $qs = is_array($prob) ? ($prob['questionSelection'] ?? $prob['question_selection'] ?? null) : null;
            if (!is_array($qs)) {
                continue;
            }
            if (!empty($qs['_assignedVariantCode']) && is_string($qs['_assignedVariantCode'])) {
                return $qs['_assignedVariantCode'];
            }
            $variants = $qs['_variants'] ?? null;
            if (is_array($variants) && count($variants) > 0) {
                $pid = $prob['id'] ?? $prob['problem_id'] ?? '';
                $digest = hash('sha256', "{$sessionId}:{$pid}:{$studentId}");
                $seed = hexdec(substr($digest, 0, 8));
                $idx = $seed % count($variants);
                $v = $variants[$idx] ?? null;
                if (is_array($v) && !empty($v['code'])) {
                    return (string) $v['code'];
                }
                return 'Mã ' . ($idx + 1);
            }
        }

        return '1';
    }

    /**
     * Resolve question selection for a student in an assignment/examination session
     * Matches student_selection() in SQL-UIT/backend/app/services/activity_questions.py
     */
    public function studentSelectionForProblem(User $student, ?Assignment $activity, ?AssignmentProblem $ap): ?array
    {
        if (!$activity || !$ap || !is_array($ap->question_selection)) {
            return null;
        }

        $raw = $ap->question_selection;
        $variants = $raw['_variants'] ?? null;
        if (is_array($variants) && count($variants) > 0) {
            $chosen = null;
            // First check if student was assigned a specific variant in ExamAdmission
            $adm = ExamAdmission::where('session_id', $activity->id)->where('student_id', $student->id)->first();
            if ($adm && !empty($adm->variant_code)) {
                foreach ($variants as $v) {
                    if (is_array($v) && (string) ($v['code'] ?? '') === (string) $adm->variant_code) {
                        $chosen = $v;
                        break;
                    }
                }
            }

            if (!$chosen) {
                $digest = hash('sha256', "{$activity->id}:{$ap->problem_id}:{$student->id}");
                $seed = hexdec(substr($digest, 0, 8));
                $chosen = $variants[$seed % count($variants)] ?? null;
            }

            if (is_array($chosen)) {
                $chosenQuestions = $chosen['questions'] ?? $chosen['selection'] ?? [];
                if (is_array($chosenQuestions)) {
                    $raw = array_merge($raw, $chosenQuestions, [
                        '_VariantId'   => $chosen['id'] ?? null,
                        '_VariantCode' => $chosen['code'] ?? null,
                    ]);
                }
            }
        }

        $problem = $ap->relationLoaded('problem') && $ap->problem
            ? $ap->problem
            : Problem::with(['sqlSolutions', 'stageExercises', 'testCases.scripts'])->find($ap->problem_id);

        if (!$problem) {
            return null;
        }

        $resolved = [];
        foreach (['DDL', 'DML', 'SQL', 'TRIGGER'] as $part) {
            if (!isset($raw[$part]) || !is_array($raw[$part])) {
                continue;
            }
            $rule = $raw[$part];
            $available = $this->availablePartQuestionIds($problem, $part);
            $mode = $rule['mode'] ?? 'manual';

            if ($mode === 'random') {
                $easyCount = (int) ($rule['easyCount'] ?? $rule['easy_count'] ?? 0);
                $mediumCount = (int) ($rule['mediumCount'] ?? $rule['medium_count'] ?? 0);
                $hardCount = (int) ($rule['hardCount'] ?? $rule['hard_count'] ?? 0);
                $hasDiffCounts = ($easyCount + $mediumCount + $hardCount) > 0;

                if ($hasDiffCounts) {
                    $meta = $this->availablePartQuestionsMeta($problem, $part);
                    $picked = [];
                    foreach ([['easy', $easyCount], ['medium', $mediumCount], ['hard', $hardCount]] as [$diffKey, $cnt]) {
                        if ($cnt <= 0) {
                            continue;
                        }
                        $pool = array_values(array_map(
                            fn ($q) => $q['id'],
                            array_filter($meta, fn ($q) => $this->normalizeDifficultyBucket((string) ($q['difficulty'] ?? 'medium')) === $diffKey)
                        ));
                        $picked = array_merge(
                            $picked,
                            $this->deterministicSample($pool, $cnt, "{$activity->id}:{$problem->id}:{$student->id}:{$part}:{$diffKey}")
                        );
                    }
                    $pickedSet = array_flip($picked);
                    $ids = array_values(array_filter($available, fn ($id) => isset($pickedSet[$id])));
                } else {
                    $count = max(0, min((int) ($rule['count'] ?? 0), count($available)));
                    $picked = $this->deterministicSample($available, $count, "{$activity->id}:{$problem->id}:{$student->id}:{$part}");
                    $pickedSet = array_flip($picked);
                    $ids = array_values(array_filter($available, fn ($id) => isset($pickedSet[$id])));
                }
            } else {
                if (isset($rule['questionIds']) || isset($rule['question_ids'])) {
                    $requested = array_map('strval', (array) ($rule['questionIds'] ?? $rule['question_ids'] ?? []));
                } elseif (array_is_list($rule) || (is_array($rule) && !empty($rule) && is_string(reset($rule)))) {
                    $requested = array_map('strval', $rule);
                } else {
                    $requested = [];
                }
                $reqSet = array_flip($requested);
                $ids = array_values(array_filter($available, fn ($id) => isset($reqSet[$id])));
            }

            $qPoints = [];
            $rawPoints = $rule['questionPoints'] ?? $rule['question_points'] ?? [];
            if (is_array($rawPoints)) {
                foreach ($ids as $qid) {
                    if (isset($rawPoints[$qid]) && is_numeric($rawPoints[$qid]) && (float) $rawPoints[$qid] > 0) {
                        $qPoints[$qid] = round((float) $rawPoints[$qid], 2);
                    }
                }
            }

            $resolved[$part] = [
                'mode'           => $mode,
                'questionIds'    => $ids,
                'count'          => $mode === 'random' ? ($rule['count'] ?? count($ids)) : null,
                'points'         => isset($rule['points']) && is_numeric($rule['points']) ? round((float) $rule['points'], 2) : null,
                'questionPoints' => $qPoints,
            ];
        }

        return $resolved;
    }

    protected function deterministicSample(array $pool, int $count, string $seedStr): array
    {
        if ($count <= 0 || empty($pool)) {
            return [];
        }
        $count = min($count, count($pool));
        $scored = [];
        foreach ($pool as $item) {
            $scored[] = [
                'hash' => hash('sha256', $seedStr . ':' . $item),
                'item' => $item,
            ];
        }
        usort($scored, fn ($a, $b) => strcmp($a['hash'], $b['hash']));
        return array_slice(array_column($scored, 'item'), 0, $count);
    }

    protected function normalizeDifficultyBucket(string $difficulty): string
    {
        $val = mb_strtolower(trim($difficulty), 'UTF-8');
        if (in_array($val, ['easy', 'dễ', 'de'], true)) {
            return 'easy';
        }
        if (in_array($val, ['hard', 'khó', 'kho'], true)) {
            return 'hard';
        }
        return 'medium';
    }

    protected function availablePartQuestionsMeta(Problem $problem, string $part): array
    {
        $part = strtoupper($part);
        if ($part === 'SQL') {
            $sols = $problem->relationLoaded('sqlSolutions')
                ? $problem->sqlSolutions->sortBy('order_index')
                : ProblemSqlSolution::where('problem_id', $problem->id)->orderBy('order_index')->get();
            $out = [];
            foreach ($sols as $s) {
                if ($s->enabled && trim((string) $s->query) !== '') {
                    $exp = is_array($s->expected) ? $s->expected : [];
                    $out[] = [
                        'id'         => (string) $s->id,
                        'difficulty' => $exp['_difficulty'] ?? ($problem->difficulty ?: 'Medium'),
                    ];
                }
            }
            if (empty($out)) {
                $testCases = $problem->relationLoaded('testCases')
                    ? $problem->testCases
                    : $problem->testCases()->get();
                $legacyQuery = $testCases->sortBy('order_index')->first()?->expected_query ?? '';
                if (trim((string) $legacyQuery) !== '') {
                    $out[] = [
                        'id'         => '__base_sql__',
                        'difficulty' => $problem->difficulty ?: 'Medium',
                    ];
                }
            }
            return $out;
        }

        $out = [];
        if ($part === 'DDL' || $part === 'DML') {
            $testCases = $problem->relationLoaded('testCases')
                ? $problem->testCases
                : $problem->testCases()->with('scripts')->get();
            $firstTc = $testCases->sortBy('order_index')->first();
            $firstScript = $firstTc?->scripts?->first();
            $schemaSql = trim((string) ($firstScript?->create_script ?? ''));
            $seedSql = trim((string) ($firstScript?->insert_script ?? ''));
            if ($part === 'DDL' && $schemaSql !== '') {
                $out[] = ['id' => '__base_ddl__', 'difficulty' => 'Dễ'];
            }
            if ($part === 'DML' && $schemaSql !== '' && $seedSql !== '') {
                $out[] = ['id' => '__base_dml__', 'difficulty' => 'Dễ'];
            }
        }

        $row = $problem->relationLoaded('stageExercises')
            ? $problem->stageExercises->firstWhere('part', $part)
            : ProblemStageExercise::where('problem_id', $problem->id)->where('part', $part)->first();
        $questions = $row && is_array($row->config) ? ($row->config['questions'] ?? []) : [];
        foreach ($questions as $q) {
            if (!empty($q['id']) && trim((string) ($q['answer'] ?? '')) !== '') {
                $out[] = [
                    'id'         => (string) $q['id'],
                    'difficulty' => $q['difficulty'] ?? 'Medium',
                ];
            }
        }
        return $out;
    }

    protected function availablePartQuestionIds(Problem $problem, string $part): array
    {
        return array_values(array_map(fn ($q) => (string) $q['id'], $this->availablePartQuestionsMeta($problem, $part)));
    }

    public function serializeExamination(Examination $exam): array
    {
        $sessions = Assignment::where('examination_id', $exam->id)
            ->with(['classes', 'selectedStudents', 'assignmentProblems'])
            ->orderBy('session_index')
            ->get();

        $serializedSessions = $sessions->values()->map(function (Assignment $s, int $position) use ($exam) {
            return $this->serializeSession($s, $exam->exam_date, $position + 1);
        })->all();

        $template = is_array($exam->template) ? $exam->template : [];
        $fallbackSession = $serializedSessions[0] ?? [];

        return [
            'id'        => (string) $exam->id,
            'title'     => (string) $exam->title,
            'examDate'  => (string) $exam->exam_date,
            'timezone'  => (string) ($exam->timezone ?: self::DEFAULT_TIMEZONE),
            'revision'  => (int) ($exam->revision ?: 1),
            'createdAt' => $exam->created_at ? $exam->created_at->toISOString() : null,
            'template'  => [
                'scoringMode'           => $template['scoringMode'] ?? ($fallbackSession['scoringMode'] ?? 'equal_questions'),
                'shortDescription'      => $template['shortDescription'] ?? '',
                'description'           => $template['description'] ?? '',
                'rules'                 => $template['rules'] ?? '',
                'instructions'          => $template['instructions'] ?? '',
                'Problems'              => $template['problems'] ?? ($fallbackSession['problems'] ?? []),
                'problems'              => $template['problems'] ?? ($fallbackSession['problems'] ?? []),
                'studentOptions'        => $template['studentOptions'] ?? [
                    'hints'                 => false,
                    'comments'              => false,
                    'leaderboard'           => false,
                    'aiAllowed'             => false,
                    'examMode'              => true,
                    'examViolationLimit'    => 2,
                    'showScoresAfterSubmit' => false,
                    'maxSubmissions'        => 1,
                ],
                'bannerUrl'             => $template['bannerUrl'] ?? null,
                'bannerSourceUrl'       => $template['bannerSourceUrl'] ?? null,
                'bannerCrop'            => $template['bannerCrop'] ?? null,
                'sessionDurationMinutes'=> $template['sessionDurationMinutes'] ?? 90,
                'sessionGapMinutes'     => $template['sessionGapMinutes'] ?? 15,
                'dayStart'              => $template['dayStart'] ?? '07:30',
                'dayEnd'                => $template['dayEnd'] ?? '17:30',
            ],
            'sessions'  => $serializedSessions,
        ];
    }

    public function serializeSession(Assignment $session, string $examDate, int $fallbackIndex): array
    {
        $session->loadMissing(['classes', 'selectedStudents', 'assignmentProblems']);
        $classIds = $session->classes->pluck('id')->all();
        $studentEmails = $session->selectedStudents->pluck('email')->all();

        $counts = $this->assignmentService->getAudienceCount('classes', $classIds, $studentEmails);
        $index = (int) ($session->session_index ?: $fallbackIndex);

        $classProctors = [];
        $proctorRows = ExamClassProctor::where('session_id', $session->id)->get();
        foreach ($proctorRows as $row) {
            $classProctors[$row->class_id][] = $row->proctor_id;
        }

        return [
            'id'               => (string) $session->id,
            'index'            => $index,
            'sessionNumber'    => $index,
            'examDate'         => $this->sessionDateLocal($session->opens, $examDate),
            'title'            => (string) $session->title,
            'starts'           => $this->localClock($session->opens),
            'ends'             => $this->localClock($session->closes),
            'classIds'         => $classIds,
            'studentEmails'    => $studentEmails,
            'problems'         => $this->sessionProblemsPayload($session),
            'proctors'         => is_array($session->proctors) ? $session->proctors : [],
            'classProctors'    => (object) $classProctors,
            'room'             => (string) ($session->room ?? ''),
            'capacity'         => $session->capacity,
            'eligibleStudents' => (int) ($counts['eligibleStudents'] ?? 0),
            'published'        => (bool) $session->published,
        ];
    }

    public function sessionProblemsPayload(Assignment $session): array
    {
        $items = $session->relationLoaded('assignmentProblems')
            ? $session->assignmentProblems
            : $session->assignmentProblems()->orderBy('order_index')->get();

        if ($items->isEmpty() && $session->examination_id) {
            $exam = Examination::find($session->examination_id);
            if ($exam && is_array($exam->template) && !empty($exam->template['problems'])) {
                return $exam->template['problems'];
            }
        }

        return $items->map(function (AssignmentProblem $ap) {
            return [
                'id'                         => (string) $ap->problem_id,
                'points'                     => (float) $ap->points,
                'datasetVisibility'          => $ap->dataset_visibility ?? (object) [],
                'questionSelection'          => $ap->question_selection,
                'databaseStructureVisible'   => (bool) ($ap->database_structure_visible ?? true),
                'databaseDescriptionVisible' => (bool) ($ap->database_description_visible ?? true),
                'workspaceParts'             => $ap->workspace_parts ?? ['DDL', 'DML', 'SQL'],
            ];
        })->values()->all();
    }

    protected function normalizeSessions(array $payload, User $user, ?string $examinationId = null): array
    {
        $timezone = (string) ($payload['timezone'] ?? self::DEFAULT_TIMEZONE);
        $rawSessions = $payload['sessions'] ?? [];
        if (!is_array($rawSessions) || empty($rawSessions)) {
            abort(400, 'Cần ít nhất một ca thi.');
        }

        $fallbackExamDate = (string) ($payload['examDate'] ?? $payload['exam_date'] ?? date('Y-m-d'));
        $defaultProblems = $payload['problems'] ?? $payload['template']['problems'] ?? [];

        $existingById = collect([]);
        if ($examinationId) {
            $existingById = Assignment::where('examination_id', $examinationId)->get()->keyBy('id');
        }

        $normalized = [];
        foreach (array_values($rawSessions) as $idx => $item) {
            $position = $idx + 1;
            $sessionId = $item['id'] ?? null;
            $existingSession = ($sessionId && $existingById->has($sessionId)) ? $existingById->get($sessionId) : null;

            $sessionExamDate = (string) ($item['examDate'] ?? $item['exam_date'] ?? $fallbackExamDate);
            $sessionNumber = (int) ($item['sessionNumber'] ?? $item['session_number'] ?? $position);
            $starts = (string) ($item['starts'] ?? '07:30');
            $ends = (string) ($item['ends'] ?? '09:00');

            [$opensUtc, $closesUtc] = $this->combineExamWindow($sessionExamDate, $starts, $ends, $timezone);

            $classIds = array_values(array_unique(array_filter(array_map('strval', (array) ($item['classIds'] ?? $item['class_ids'] ?? [])))));
            $studentEmails = array_values(array_unique(array_filter(array_map('strval', (array) ($item['studentEmails'] ?? $item['student_emails'] ?? [])))));

            $sessionProblems = !empty($item['problems']) ? $item['problems'] : $defaultProblems;
            if ($existingSession && empty($sessionProblems)) {
                $sessionProblems = $this->sessionProblemsPayload($existingSession);
            }

            $counts = $this->assignmentService->getAudienceCount('classes', $classIds, $studentEmails);
            $eligible = (int) ($counts['eligibleStudents'] ?? 0);

            $capacity = isset($item['capacity']) && $item['capacity'] !== '' && $item['capacity'] !== null
                ? (int) $item['capacity']
                : null;

            if ($capacity !== null && $eligible > $capacity) {
                abort(400, "Ca {$sessionNumber} vượt sức chứa ({$eligible}/{$capacity} sinh viên).");
            }

            $title = trim((string) ($item['title'] ?? ''));
            if ($title === '') {
                $baseTitle = trim((string) ($payload['title'] ?? 'Kỳ thi'));
                $dateLabel = Carbon::parse($sessionExamDate)->format('d/m/Y');
                $title = "{$baseTitle} - Ca {$sessionNumber} - {$dateLabel}";
            }

            $proctors = $this->cleanTextList((array) ($item['proctors'] ?? []));
            $classProctors = is_array($item['classProctors'] ?? $item['class_proctors'] ?? null)
                ? ($item['classProctors'] ?? $item['class_proctors'])
                : [];

            $published = array_key_exists('published', $item) && $item['published'] !== null
                ? (bool) $item['published']
                : (bool) ($payload['published'] ?? false);

            $normalized[] = [
                'id'               => $existingSession ? $existingSession->id : null,
                'index'            => $position,
                'sessionNumber'    => $sessionNumber,
                'examDate'         => $sessionExamDate,
                'title'            => $title,
                'starts'           => $starts,
                'ends'             => $ends,
                'opens'            => $opensUtc->toIso8601String(),
                'closes'           => $closesUtc->toIso8601String(),
                'opensCarbon'      => $opensUtc,
                'closesCarbon'     => $closesUtc,
                'classIds'         => $classIds,
                'studentEmails'    => $studentEmails,
                'problems'         => $sessionProblems,
                'proctors'         => $proctors,
                'classProctors'    => $classProctors,
                'room'             => trim((string) ($item['room'] ?? '')),
                'capacity'         => $capacity,
                'eligibleStudents' => $eligible,
                'published'        => $published,
            ];
        }

        $this->validateConflicts($normalized);

        return $normalized;
    }

    protected function validateConflicts(array $sessions): void
    {
        $n = count($sessions);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $first = $sessions[$i];
                $second = $sessions[$j];

                if ($first['opensCarbon'] < $second['closesCarbon'] && $second['opensCarbon'] < $first['closesCarbon']) {
                    if (!empty($first['room']) && !empty($second['room']) && mb_strtolower($first['room']) === mb_strtolower($second['room'])) {
                        abort(400, "Ca {$first['index']} và Ca {$second['index']} trùng phòng {$first['room']} trong cùng khung giờ.");
                    }
                    $sharedClasses = array_intersect(
                        $first['classIds'] ?? [],
                        $second['classIds'] ?? []
                    );
                    if (!empty($sharedClasses)) {
                        abort(400, "Ca {$first['index']} và Ca {$second['index']} trùng lớp trong cùng khung giờ.");
                    }
                    $sharedProctors = array_intersect(
                        array_map('mb_strtolower', $first['proctors']),
                        array_map('mb_strtolower', $second['proctors'])
                    );
                    $firstClassProctors = [];
                    foreach (($first['classProctors'] ?? []) as $pids) {
                        foreach ((array) $pids as $pid) {
                            $firstClassProctors[] = (string) $pid;
                        }
                    }
                    $secondClassProctors = [];
                    foreach (($second['classProctors'] ?? []) as $pids) {
                        foreach ((array) $pids as $pid) {
                            $secondClassProctors[] = (string) $pid;
                        }
                    }
                    $sharedClassProctors = array_intersect(
                        array_unique($firstClassProctors),
                        array_unique($secondClassProctors)
                    );
                    if (!empty($sharedProctors) || !empty($sharedClassProctors)) {
                        abort(400, "Ca {$first['index']} và Ca {$second['index']} trùng giám thị trong cùng khung giờ.");
                    }
                }
            }
        }
    }

    protected function createSessionAssignment(Examination $exam, array $item, array $payload, User $user): Assignment
    {
        $session = new Assignment();
        $session->id = (string) Str::uuid();
        $session->instructor_id = $exam->owner_id ?: $user->id;
        $session->examination_id = $exam->id;
        $this->applySessionToAssignment($session, $exam, $item, $payload, $user);
        return $session;
    }

    protected function applySessionToAssignment(Assignment $session, Examination $exam, array $item, array $payload, User $user): void
    {
        $opts = $payload['studentOptions'] ?? $payload['student_options'] ?? [];

        $session->examination_id = $exam->id;
        $session->session_index = (int) $item['index'];
        $session->title = $item['title'];
        $session->scoring_mode = (string) ($payload['scoringMode'] ?? $payload['scoring_mode'] ?? 'equal_questions');
        $session->audience_type = 'classes';
        $session->short_description = (string) ($payload['shortDescription'] ?? $payload['short_description'] ?? '');
        $session->description = (string) ($payload['description'] ?? '');
        $session->rules = (string) ($payload['rules'] ?? '');
        $session->instructions = (string) ($payload['instructions'] ?? '');
        $session->banner_url = $payload['bannerUrl'] ?? $payload['banner_url'] ?? null;
        $session->banner_source_url = $payload['bannerSourceUrl'] ?? $payload['banner_source_url'] ?? null;
        $session->banner_crop = is_array($payload['bannerCrop'] ?? null) ? json_encode($payload['bannerCrop']) : ($payload['bannerCrop'] ?? null);
        $session->opens = $item['opensCarbon'];
        $session->closes = $item['closesCarbon'];
        $session->published = (bool) $item['published'];
        $session->proctors = $item['proctors'];
        $session->room = $item['room'];
        $session->capacity = $item['capacity'];
        $session->admission_required = true;

        $session->hints_enabled = (bool) ($opts['hints'] ?? false);
        $session->comments_enabled = (bool) ($opts['comments'] ?? false);
        $session->leaderboard_enabled = (bool) ($opts['leaderboard'] ?? false);
        $session->ai_allowed = (bool) ($opts['aiAllowed'] ?? false);
        $session->exam_mode = true;
        $session->exam_violation_limit = isset($opts['examViolationLimit']) ? (int) $opts['examViolationLimit'] : 2;
        $session->show_scores_after_submit = (bool) ($opts['showScoresAfterSubmit'] ?? false);
        $session->max_submissions = isset($opts['maxSubmissions']) && $opts['maxSubmissions'] !== null ? (int) $opts['maxSubmissions'] : 1;

        $session->save();

        // Sync classes
        $syncClasses = [];
        foreach ($item['classIds'] as $cid) {
            $syncClasses[$cid] = ['id' => (string) Str::uuid()];
        }
        $session->classes()->sync($syncClasses);

        // Sync class proctors
        $this->syncSessionClassProctors($session, $item['classIds'], $item['classProctors'] ?? []);

        // Sync individual students
        $this->syncSessionStudents($session, $item['studentEmails'], $item['classIds']);

        // Sync problems
        $this->syncSessionProblems($session, $item['problems']);
    }

    protected function syncSessionClassProctors(Assignment $session, array $classIds, array $classProctors): void
    {
        ExamClassProctor::where('session_id', $session->id)->delete();

        $allowedClasses = array_flip($classIds);
        $seen = [];
        $rows = [];
        foreach ($classProctors as $cid => $proctorIds) {
            if (!isset($allowedClasses[$cid]) || !is_array($proctorIds)) {
                continue;
            }
            foreach ($proctorIds as $pid) {
                $pid = trim((string) $pid);
                if ($pid === '') {
                    continue;
                }
                $key = "{$cid}:{$pid}";
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $rows[] = [
                    'session_id'  => $session->id,
                    'class_id'    => $cid,
                    'proctor_id'  => $pid,
                    'assigned_at' => now(),
                ];
            }
        }

        if (!empty($rows)) {
            ExamClassProctor::insert($rows);
        }
    }

    protected function syncSessionStudents(Assignment $session, array $emails, array $classIds): void
    {
        $studentIds = $this->assignmentService->resolveStudentEmails($emails);
        $coveredIds = !empty($classIds)
            ? DB::table('class_enrollments')->whereIn('class_id', $classIds)->pluck('student_id')->all()
            : [];
        $coveredSet = array_flip($coveredIds);

        $finalIds = array_values(array_filter($studentIds, fn ($id) => !isset($coveredSet[$id])));
        DB::table('assignment_students')->where('assignment_id', $session->id)->delete();

        $rows = [];
        foreach ($finalIds as $sid) {
            $rows[] = [
                'assignment_id' => $session->id,
                'student_id'    => $sid,
                'created_at'    => now(),
            ];
        }
        if (!empty($rows)) {
            DB::table('assignment_students')->insert($rows);
        }
    }

    protected function syncSessionProblems(Assignment $session, array $problems): void
    {
        $session->assignmentProblems()->delete();

        foreach (array_values($problems) as $idx => $item) {
            $problemId = is_array($item) ? ($item['id'] ?? $item['problem_id'] ?? null) : $item;
            if (!$problemId) {
                continue;
            }
            AssignmentProblem::create([
                'id'                           => (string) Str::uuid(),
                'assignment_id'                => $session->id,
                'problem_id'                   => (string) $problemId,
                'points'                       => is_array($item) ? (float) ($item['points'] ?? 10.0) : 10.0,
                'order_index'                  => $idx,
                'workspace_parts'              => is_array($item) ? ($item['workspaceParts'] ?? $item['workspace_parts'] ?? ['DDL', 'DML', 'SQL']) : ['DDL', 'DML', 'SQL'],
                'dataset_visibility'           => is_array($item) ? ($item['datasetVisibility'] ?? $item['dataset_visibility'] ?? []) : [],
                'question_selection'           => is_array($item) ? ($item['questionSelection'] ?? $item['question_selection'] ?? null) : null,
                'database_structure_visible'   => is_array($item) ? (bool) ($item['databaseStructureVisible'] ?? $item['database_structure_visible'] ?? true) : true,
                'database_description_visible' => is_array($item) ? (bool) ($item['databaseDescriptionVisible'] ?? $item['database_description_visible'] ?? true) : true,
            ]);
        }
    }

    protected function assertSessionRemovable(Assignment $session): void
    {
        $hasSub = Submission::where('context', $session->id)->exists();
        if ($hasSub) {
            abort(400, "Không thể xóa {$session->title} vì đã có bài nộp.");
        }
    }

    protected function deleteSessionAssignment(Assignment $session): void
    {
        ExamClassProctor::where('session_id', $session->id)->delete();
        ExamAdmission::where('session_id', $session->id)->delete();
        ExamAccessState::where('session_id', $session->id)->delete();
        DB::table('exam_session_plans')->where('session_id', $session->id)->delete();
        $session->classes()->detach();
        $session->selectedStudents()->detach();
        $session->assignmentProblems()->delete();
        $session->delete();
    }

    protected function combineExamWindow(string $examDate, string $starts, string $ends, string $timezone = self::DEFAULT_TIMEZONE): array
    {
        try {
            $tz = new \DateTimeZone($timezone ?: self::DEFAULT_TIMEZONE);
        } catch (\Throwable) {
            $tz = new \DateTimeZone(self::DEFAULT_TIMEZONE);
        }

        $openLocal = Carbon::createFromFormat('Y-m-d H:i', "{$examDate} {$starts}", $tz);
        $closeLocal = Carbon::createFromFormat('Y-m-d H:i', "{$examDate} {$ends}", $tz);

        if ($closeLocal->lessThanOrEqualTo($openLocal)) {
            abort(400, "Giờ kết thúc ({$ends}) phải sau giờ bắt đầu ({$starts}).");
        }

        $appTz = config('app.timezone', self::DEFAULT_TIMEZONE);
        return [$openLocal->copy()->setTimezone($appTz), $closeLocal->copy()->setTimezone($appTz)];
    }

    public function localClock(?Carbon $dt, string $timezone = self::DEFAULT_TIMEZONE): string
    {
        if (!$dt) {
            return '07:30';
        }
        return $dt->copy()->setTimezone($timezone ?: self::DEFAULT_TIMEZONE)->format('H:i');
    }

    public function sessionDateLocal(?Carbon $dt, string $fallbackDate, string $timezone = self::DEFAULT_TIMEZONE): string
    {
        if (!$dt) {
            return $fallbackDate;
        }
        return $dt->copy()->setTimezone($timezone ?: self::DEFAULT_TIMEZONE)->format('Y-m-d');
    }

    protected function templateJson(array $payload): array
    {
        $opts = $payload['studentOptions'] ?? $payload['student_options'] ?? [
            'hints'                 => false,
            'comments'              => false,
            'leaderboard'           => false,
            'aiAllowed'             => false,
            'examMode'              => true,
            'examViolationLimit'    => 2,
            'showScoresAfterSubmit' => false,
            'maxSubmissions'        => 1,
        ];

        return [
            'scoringMode'            => $payload['scoringMode'] ?? $payload['scoring_mode'] ?? 'equal_questions',
            'shortDescription'       => $payload['shortDescription'] ?? $payload['short_description'] ?? '',
            'description'            => $payload['description'] ?? '',
            'rules'                  => $payload['rules'] ?? '',
            'instructions'           => $payload['instructions'] ?? '',
            'problems'               => $payload['problems'] ?? $payload['template']['problems'] ?? [],
            'studentOptions'         => $opts,
            'bannerUrl'              => $payload['bannerUrl'] ?? $payload['banner_url'] ?? null,
            'bannerSourceUrl'        => $payload['bannerSourceUrl'] ?? $payload['banner_source_url'] ?? null,
            'bannerCrop'             => $payload['bannerCrop'] ?? $payload['banner_crop'] ?? null,
            'sessionDurationMinutes' => (int) ($payload['sessionDurationMinutes'] ?? 90),
            'sessionGapMinutes'      => (int) ($payload['sessionGapMinutes'] ?? 15),
            'dayStart'               => (string) ($payload['dayStart'] ?? '07:30'),
            'dayEnd'                 => (string) ($payload['dayEnd'] ?? '17:30'),
        ];
    }

    protected function cleanTextList(array $items): array
    {
        $seen = [];
        $result = [];
        foreach ($items as $raw) {
            foreach (preg_split('/[,;\n]+/', (string) $raw) as $part) {
                $val = trim($part);
                if ($val === '') {
                    continue;
                }
                $key = mb_strtolower($val);
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $result[] = $val;
                }
            }
        }
        return $result;
    }

    public function generatePassword(int $length = 10): string
    {
        $max = strlen(self::PASSWORD_ALPHABET) - 1;
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= self::PASSWORD_ALPHABET[random_int(0, $max)];
        }
        return $out;
    }

    /**
     * Generate temporary exam username matching SQL-UIT pattern: "K7_" + 20 random alphanumeric characters
     * Excludes ambiguous characters (i, I, l, 1, o, O, 0) to avoid font confusion.
     */
    public function generateExamUsername(string $studentId, string $studentCode = ''): string
    {
        $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $max = strlen($chars) - 1;
        for ($attempts = 0; $attempts < 100; $attempts++) {
            $rand = '';
            for ($i = 0; $i < 20; $i++) {
                $rand .= $chars[random_int(0, $max)];
            }
            $username = 'K7_' . $rand;
            if ($studentCode !== '' && str_contains($username, $studentCode)) {
                continue;
            }
            if (!ExamAdmission::where('username', $username)->exists()) {
                return $username;
            }
        }
        return 'K7_' . Str::random(20);
    }

    /**
     * Extract available variant codes from session problems payload
     */
    public function extractVariantCodes(array $problemsPayload): array
    {
        foreach ($problemsPayload as $prob) {
            $qs = is_array($prob) ? ($prob['questionSelection'] ?? $prob['question_selection'] ?? null) : null;
            if (!is_array($qs)) {
                continue;
            }
            $variants = $qs['_variants'] ?? null;
            if (is_array($variants) && count($variants) > 0) {
                $codes = [];
                foreach ($variants as $idx => $v) {
                    $c = is_array($v) && !empty($v['code']) ? (string) $v['code'] : (string) ($idx + 1);
                    $codes[] = $c;
                }
                return $codes;
            }
        }
        return ['1', '2'];
    }
}
