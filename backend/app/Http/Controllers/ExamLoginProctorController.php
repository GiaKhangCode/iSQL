<?php

namespace App\Http\Controllers;

use App\Models\ActivityAttempt;
use App\Models\ActivityLog;
use App\Models\Assignment;
use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\ExamAccessState;
use App\Models\ExamAdmission;
use App\Models\ExamBrowserSession;
use App\Models\ExamClassProctor;
use App\Models\StudentIdentity;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkspaceSubmissionResult;
use App\Services\ExamAdmissionService;
use App\Services\ExaminationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ExamLoginProctorController extends Controller
{
    public const BROWSER_COOKIE = 'sql_exam_browser';
    private const BROWSER_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(
        protected readonly ExaminationService $examinationService,
        protected readonly ExamAdmissionService $examAdmissionService
    ) {
    }

    public static function normalizeAmbiguous(string $str): string
    {
        $s = strtolower(trim($str));
        $s = str_replace(['1', 'i'], 'l', $s);
        $s = str_replace('o', '0', $s);
        return $s;
    }

    public function findAdmission(string $rawInput): ?ExamAdmission
    {
        $input = trim($rawInput);
        if ($input === '') {
            return null;
        }

        // 1. Exact match
        $row = ExamAdmission::where('username', $input)->first();
        if ($row) {
            return $row;
        }

        // 2. Case-insensitive match
        $row = ExamAdmission::whereRaw('LOWER(username) = ?', [strtolower($input)])->first();
        if ($row) {
            return $row;
        }

        // 3. Ambiguous character normalizer (I/l/1 and O/o/0)
        $normalized = self::normalizeAmbiguous($input);
        $matched = null;
        ExamAdmission::where('status', '!=', 'revoked')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->chunk(500, function ($candidates) use ($normalized, &$matched) {
                foreach ($candidates as $cand) {
                    if (self::normalizeAmbiguous($cand->username) === $normalized) {
                        $matched = $cand;
                        return false;
                    }
                }
                return true;
            });

        return $matched;
    }

    public function loginWithAdmission(Request $request, ExamAdmission $row, string $password): JsonResponse
    {
        if ($row->status === 'revoked') {
            abort(401, 'Sai tài khoản hoặc mật khẩu ca thi.');
        }

        $passwordOk = Hash::check($password, $row->hashed_password)
            || ($row->initial_password !== null && hash_equals((string) $row->initial_password, $password));

        if (!$passwordOk) {
            abort(401, 'Sai tài khoản hoặc mật khẩu ca thi.');
        }

        $student = User::where('id', $row->student_id)
            ->where('role', User::ROLE_STUDENT)
            ->first();

        if (!$student || $student->status !== 'Active') {
            abort(403, 'Tài khoản sinh viên không khả dụng.');
        }

        $activity = Assignment::find($row->session_id);
        if (!$activity || !$activity->published) {
            abort(403, 'Ca thi chưa được công bố.');
        }

        $now = now();
        if ($activity->closes && $now->greaterThanOrEqualTo($activity->closes)) {
            abort(403, 'Ca thi đã kết thúc.');
        }

        $state = $this->getOrCreateAccessState($activity->id, $student->id);
        $browserCookieVal = $request->cookie(self::BROWSER_COOKIE);
        $currentBrowser = $this->readBrowserCookie($browserCookieVal, $row->id);

        $activeBrowsers = ExamBrowserSession::where('admission_id', $row->id)
            ->where('status', 'active')
            ->get();

        if ($currentBrowser && $currentBrowser->status === 'active') {
            $currentBrowser->last_seen_at = $now;
            $currentBrowser->save();
            $browser = $currentBrowser;
            $locked = (bool) $state->locked;
        } elseif ($activeBrowsers->isEmpty() && !$state->locked) {
            $browser = $this->createBrowserSession($request, $row, 'active', $now);
            $row->status = 'claimed';
            if (!$row->claimed_at) {
                $row->claimed_at = $now;
            }
            $row->save();
            $locked = false;
        } else {
            if ($currentBrowser && $currentBrowser->status === 'pending') {
                $currentBrowser->last_seen_at = $now;
                $currentBrowser->save();
                $browser = $currentBrowser;
            } else {
                $browser = $this->createBrowserSession($request, $row, 'pending', $now);
            }
            $state->locked = true;
            $state->lock_reason = 'duplicate_login';
            $state->incident_at = $now;
            $state->save();
            $locked = true;
        }

        $tokenName = "exam:{$activity->id}:{$browser->id}:{$row->id}:{$row->generation}";
        $accessToken = $student->createToken($tokenName)->plainTextToken;

        $userPayload = array_merge($student->toAuthArray(), [
            'examSessionId'   => (string) $activity->id,
            'exam_session_id' => (string) $activity->id,
        ]);

        $response = response()->json([
            'access_token' => $accessToken,
            'token_type'   => 'bearer',
            'user'         => $userPayload,
            'locked'       => $locked,
        ]);

        $admissionCookie = $this->examAdmissionService->buildAdmissionCookieValue($student, $activity, $row);
        $browserSigned = $this->buildBrowserCookieValue($browser->id, $row->id);

        $response->withCookie(cookie(ExamAdmissionService::COOKIE_NAME, $admissionCookie, 480, '/', null, false, true, false, 'Lax'));
        $response->withCookie(cookie(self::BROWSER_COOKIE, $browserSigned, 480, '/', null, false, true, false, 'Lax'));

        return $response;
    }

    public function login(Request $request): JsonResponse
    {
        $username = trim((string) $request->input('username', ''));
        $password = (string) $request->input('password', '');

        $row = $this->findAdmission($username);
        if (!$row) {
            abort(401, 'Sai tài khoản hoặc mật khẩu ca thi.');
        }

        return $this->loginWithAdmission($request, $row, $password);
    }

    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        $sessionId = null;
        $browserId = null;

        $token = $user?->currentAccessToken();
        if ($token && is_string($token->name) && str_starts_with($token->name, 'exam:')) {
            $parts = explode(':', $token->name);
            $sessionId = $parts[1] ?? null;
            $browserId = $parts[2] ?? null;
        }

        if (!$sessionId) {
            $latestAdm = ExamAdmission::where('student_id', $user->id)
                ->where('status', '!=', 'revoked')
                ->orderByDesc('updated_at')
                ->first();
            $sessionId = $latestAdm?->session_id;
        }

        abort_unless($sessionId !== null, 403, 'Chỉ dùng cho tài khoản ca thi.');

        $activity = Assignment::findOrFail($sessionId);
        $state = $this->getOrCreateAccessState($activity->id, $user->id);

        $browser = $browserId
            ? ExamBrowserSession::find($browserId)
            : ExamBrowserSession::where('session_id', $activity->id)
                ->where('student_id', $user->id)
                ->orderByDesc('started_at')
                ->first();

        if ($browser) {
            $browser->last_seen_at = now();
            $browser->save();
        }

        $attempt = ActivityAttempt::where('assignment_id', $activity->id)
            ->where('user_id', $user->id)
            ->first();

        $violationLocked = $attempt && $attempt->status === 'submitted' && in_array($attempt->finish_reason, ['violation_limit', 'violations'], true);
        $browserLocked = !$browser || $browser->status !== 'active' || (bool) $state->locked || $violationLocked;

        return response()->json([
            'sessionId'       => (string) $activity->id,
            'title'           => (string) $activity->title,
            'locked'          => $browserLocked,
            'violationLocked' => $violationLocked,
            'browserCode'     => $browser ? (string) $browser->browser_code : '',
            'opens'           => $activity->opens ? $activity->opens->toISOString() : null,
            'closes'          => $activity->closes ? $activity->closes->toISOString() : null,
            'serverTime'      => now()->toISOString(),
        ]);
    }

    public function proctorSessions(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->assertStaff($user);

        $sessions = Assignment::whereNotNull('examination_id')
            ->where('published', true)
            ->with('classes')
            ->orderBy('opens')
            ->orderBy('session_index')
            ->get();

        $taughtClassIds = ClassModel::where('instructor_id', $user->id)
            ->orWhere('practice_instructor_id', $user->id)
            ->orWhereHas('instructors', fn ($q) => $q->where('users.id', $user->id))
            ->pluck('id')
            ->flip()
            ->all();

        $proctorRows = ExamClassProctor::where('proctor_id', $user->id)->get();
        $proctorPairs = [];
        foreach ($proctorRows as $r) {
            $proctorPairs["{$r->session_id}:{$r->class_id}"] = true;
        }

        $items = [];
        foreach ($sessions as $s) {
            $sClasses = $s->classes;
            $isAdminOrOwner = $user->isAdmin() || $user->isFacultyOffice() || $user->role === User::ROLE_DEPARTMENT_HEAD || $s->instructor_id === $user->id;
            $proctorNames = is_array($s->proctors) ? array_map('mb_strtolower', $s->proctors) : [];
            $namedProctor = in_array(mb_strtolower((string) $user->name), $proctorNames, true)
                || in_array(mb_strtolower((string) $user->email), $proctorNames, true);

            if ($sClasses->isEmpty()) {
                if ($isAdminOrOwner || $namedProctor) {
                    $items[] = [
                        'sessionId' => (string) $s->id,
                        'title'     => (string) $s->title,
                        'roomId'    => null,
                        'room'      => $s->room ?: 'Chưa gán lớp',
                        'opens'     => $s->opens ? $s->opens->toISOString() : null,
                        'closes'    => $s->closes ? $s->closes->toISOString() : null,
                    ];
                }
                continue;
            }

            foreach ($sClasses as $c) {
                $allowed = $isAdminOrOwner
                    || $namedProctor
                    || isset($proctorPairs["{$s->id}:{$c->id}"])
                    || isset($taughtClassIds[$c->id]);

                if ($allowed) {
                    $label = $c->practice_class ?: $c->course ?: $c->id;
                    $items[] = [
                        'sessionId' => (string) $s->id,
                        'title'     => (string) $s->title,
                        'roomId'    => (string) $c->id,
                        'room'      => (string) $label,
                        'opens'     => $s->opens ? $s->opens->toISOString() : null,
                        'closes'    => $s->closes ? $s->closes->toISOString() : null,
                    ];
                }
            }
        }

        return response()->json($items);
    }

    public function roomStatus(Request $request, string $sessionId): JsonResponse
    {
        $user = $request->user();
        $roomId = $request->query('room_id', $request->query('roomId'));
        $session = $this->requireProctorAccess($sessionId, $user, $roomId);

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
                ->where('role', User::ROLE_STUDENT)
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
            $browsersGrouped[$b->student_id][] = [
                'id'         => (string) $b->id,
                'code'       => (string) $b->browser_code,
                'status'     => (string) $b->status,
                'ipAddress'  => $b->ip_address,
                'userAgent'  => (string) ($b->user_agent ?? ''),
                'startedAt'  => $b->started_at ? $b->started_at->toISOString() : null,
                'lastSeenAt' => $b->last_seen_at ? $b->last_seen_at->toISOString() : null,
            ];
        }

        $result = [];
        foreach ($students as $st) {
            $adm = $admissions->get($st->id);
            $state = $states->get($st->id);
            $attempt = $attempts->get($st->id);

            $violations = ($attempt && is_array($attempt->violations)) ? $attempt->violations : [];
            $vCount = collect($violations)->filter(fn ($v) => is_array($v) && ($v['counted'] ?? true) === true)->count();
            $vLimit = max(1, (int) ($session->exam_violation_limit ?? 2));
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

        return response()->json($result);
    }

    public function sessionNotices(Request $request, string $sessionId): JsonResponse
    {
        $user = $request->user();
        $session = $this->requireProctorAccess($sessionId, $user);

        $logs = ActivityLog::where('action', 'like', "proctor:%:{$session->id}:%")
            ->orderByDesc('created_at')
            ->limit(40)
            ->get();

        $userIds = $logs->pluck('user_id')->filter()->unique()->values()->all();
        $parsedStudentIds = [];
        $parsedItems = [];

        foreach ($logs as $row) {
            $parts = explode(':', (string) $row->action, 5);
            if (count($parts) >= 4) {
                $parsedStudentIds[] = $parts[3];
                $parsedItems[] = [
                    'row'       => $row,
                    'kind'      => $parts[1],
                    'studentId' => $parts[3],
                    'detail'    => $parts[4] ?? '',
                ];
            }
        }

        $allUserIds = array_values(array_unique(array_merge($userIds, $parsedStudentIds)));
        $usersMap = !empty($allUserIds) ? User::whereIn('id', $allUserIds)->get()->keyBy('id') : collect([]);
        $codesMap = !empty($parsedStudentIds)
            ? StudentIdentity::whereIn('user_id', $parsedStudentIds)->pluck('student_code', 'user_id')->all()
            : [];

        $labels = [
            'unlock'           => 'Mở khóa bài thi',
            'add_student'      => 'Thêm sinh viên vào ca',
            'transfer_student' => 'Chuyển ca thi',
            'approve_browser'  => 'Duyệt trình duyệt mới',
        ];

        $result = [];
        foreach ($parsedItems as $item) {
            $row = $item['row'];
            $kind = $item['kind'];
            $studentId = $item['studentId'];
            $detail = $item['detail'];

            $actor = $usersMap->get($row->user_id);
            $student = $usersMap->get($studentId);
            $code = $codesMap[$studentId] ?? ($student ? $this->examinationService->studentCode($student->email, $student->id) : '');

            $actorName = $actor ? $actor->name : 'Giám thị';
            $studentName = $student ? $student->name : $studentId;

            $result[] = [
                'id'          => (string) $row->id,
                'kind'        => $kind,
                'label'       => $labels[$kind] ?? $kind,
                'actor'       => $actorName,
                'actorName'   => $actorName,
                'studentId'   => $studentId,
                'studentCode' => $code,
                'student'     => $studentName,
                'studentName' => $studentName,
                'detail'      => $detail,
                'createdAt'   => $row->created_at ? $row->created_at->toISOString() : null,
            ];
        }

        return response()->json($result);
    }

    public function unlockStudent(Request $request, string $sessionId, string $studentId): JsonResponse
    {
        $user = $request->user();
        $session = $this->requireProctorAccess($sessionId, $user);

        return DB::transaction(function () use ($session, $studentId, $user) {
            $state = $this->getOrCreateAccessState($session->id, $studentId);
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
                        ->whereIn('source', ['Assignments', 'Contests', 'Assignment', 'Contest'])
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

            ActivityLog::create([
                'id'         => (string) Str::uuid(),
                'user_id'    => $user->id,
                'action'     => "proctor:unlock:{$session->id}:{$studentId}",
                'created_at' => now(),
            ]);

            return response()->json(['ok' => true]);
        });
    }

    public function checkStudent(Request $request, string $sessionId): JsonResponse
    {
        $user = $request->user();
        $roomId = $request->input('room_id', $request->input('roomId'));
        $studentCode = (string) ($request->input('student_code') ?? $request->input('studentCode') ?? '');

        $session = $this->requireProctorAccess($sessionId, $user, $roomId);
        $student = $this->findStudentByCode($studentCode);
        $code = $this->examinationService->studentCode($student->email, $student->id);

        $session->loadMissing(['classes', 'selectedStudents']);
        $sessionClassIds = $session->classes->pluck('id')->all();
        $enrolledIds = !empty($sessionClassIds)
            ? ClassEnrollment::whereIn('class_id', $sessionClassIds)->pluck('student_id')->flip()->all()
            : [];
        $directIds = $session->selectedStudents->pluck('id')->flip()->all();

        if (isset($enrolledIds[$student->id]) || isset($directIds[$student->id])) {
            return response()->json([
                'status'      => 'already_in_session',
                'canAdd'      => false,
                'studentId'   => (string) $student->id,
                'studentCode' => $code,
                'name'        => (string) $student->name,
                'email'       => (string) $student->email,
                'message'     => 'Sinh viên đã có trong danh sách ca thi này.',
            ]);
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

                return response()->json([
                    'status'         => $claimed ? 'already_participated' : 'in_other_session',
                    'canAdd'         => !$claimed,
                    'studentId'      => (string) $student->id,
                    'studentCode'    => $code,
                    'name'           => (string) $student->name,
                    'email'          => (string) $student->email,
                    'otherSessionId' => (string) $other->id,
                    'otherSession'   => (string) $other->title,
                    'message'        => $claimed
                        ? "Sinh viên đã tham gia ở {$other->title}, không thể chuyển sang ca này."
                        : "Sinh viên đang thuộc {$other->title} nhưng chưa tham gia thi. Có thể chuyển sang ca này.",
                ]);
            }
        }

        return response()->json([
            'status'      => 'eligible',
            'canAdd'      => true,
            'studentId'   => (string) $student->id,
            'studentCode' => $code,
            'name'        => (string) $student->name,
            'email'       => (string) $student->email,
            'message'     => 'Sinh viên chưa thuộc ca nào trong kỳ thi này. Có thể thêm vào ca thi.',
        ]);
    }

    public function addStudent(Request $request, string $sessionId): JsonResponse
    {
        $user = $request->user();
        $roomId = $request->input('room_id', $request->input('roomId'));
        $studentCode = (string) ($request->input('student_code') ?? $request->input('studentCode') ?? '');

        $checkData = $this->checkStudent($request, $sessionId)->getData(true);
        if (empty($checkData['canAdd'])) {
            abort(400, $checkData['message'] ?? 'Không thể thêm sinh viên.');
        }

        $session = $this->requireProctorAccess($sessionId, $user, $roomId);
        $student = User::findOrFail($checkData['studentId']);

        return DB::transaction(function () use ($session, $student, $checkData, $user) {
            if (($checkData['status'] ?? '') === 'in_other_session' && !empty($checkData['otherSessionId'])) {
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

            $kind = ($checkData['status'] ?? '') === 'in_other_session' ? 'transfer_student' : 'add_student';
            $detail = $checkData['otherSession'] ?? '';
            ActivityLog::create([
                'id'         => (string) Str::uuid(),
                'user_id'    => $user->id,
                'action'     => "proctor:{$kind}:{$session->id}:{$student->id}:{$detail}",
                'created_at' => now(),
            ]);

            return response()->json([
                'ok'          => true,
                'student'     => $checkData,
                'credentials' => $credentials,
            ]);
        });
    }

    public function approveBrowser(Request $request, string $admissionId): JsonResponse
    {
        $user = $request->user();
        $browserId = (string) ($request->input('browser_id') ?? $request->input('browserId') ?? '');
        $reason = trim((string) ($request->input('reason') ?? ''));

        if ($reason === '') {
            abort(400, 'Cần nhập lý do duyệt đăng nhập lại.');
        }

        $adm = ExamAdmission::findOrFail($admissionId);
        $this->requireProctorAccess($adm->session_id, $user);

        $browser = ExamBrowserSession::where('id', $browserId)
            ->where('admission_id', $adm->id)
            ->firstOrFail();

        return DB::transaction(function () use ($adm, $browser, $reason, $user) {
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

            $state = $this->getOrCreateAccessState($adm->session_id, $adm->student_id);
            $state->locked = false;
            $state->lock_reason = null;
            $state->unlocked_at = $now;
            $state->unlocked_by = $user->id;
            $state->save();

            ActivityLog::create([
                'id'         => (string) Str::uuid(),
                'user_id'    => $user->id,
                'action'     => "proctor:approve_browser:{$adm->session_id}:{$adm->student_id}:{$browser->browser_code}",
                'created_at' => now(),
            ]);

            return response()->json(['ok' => true]);
        });
    }

    protected function assertStaff(User $user): void
    {
        $ok = $user->isAdmin() || $user->isFacultyOffice() || $user->isInstructor() || $user->role === User::ROLE_DEPARTMENT_HEAD;
        abort_unless($ok, 403, 'Chỉ giảng viên, giáo vụ khoa hoặc quản trị viên mới có quyền coi thi.');
    }

    protected function requireProctorAccess(string $sessionId, User $user, ?string $roomId = null): Assignment
    {
        $this->assertStaff($user);
        $session = Assignment::where('id', $sessionId)
            ->whereNotNull('examination_id')
            ->firstOrFail();

        if ($user->isAdmin() || $user->isFacultyOffice() || $user->role === User::ROLE_DEPARTMENT_HEAD || $session->instructor_id === $user->id) {
            return $session;
        }

        $proctorNames = is_array($session->proctors) ? array_map('mb_strtolower', $session->proctors) : [];
        if (in_array(mb_strtolower((string) $user->name), $proctorNames, true) || in_array(mb_strtolower((string) $user->email), $proctorNames, true)) {
            return $session;
        }

        $assignedProctor = ExamClassProctor::where('session_id', $session->id)
            ->where('proctor_id', $user->id)
            ->when($roomId, fn ($q) => $q->where('class_id', $roomId))
            ->exists();
        if ($assignedProctor) {
            return $session;
        }

        $sessionClassIds = $session->classes()->pluck('classes.id')->all();
        if ($roomId) {
            $sessionClassIds = array_values(array_intersect($sessionClassIds, [$roomId]));
        }
        if (!empty($sessionClassIds)) {
            $teaches = ClassModel::whereIn('id', $sessionClassIds)
                ->where(function ($q) use ($user) {
                    $q->where('instructor_id', $user->id)
                      ->orWhere('practice_instructor_id', $user->id)
                      ->orWhereHas('instructors', fn ($iq) => $iq->where('users.id', $user->id));
                })
                ->exists();
            if ($teaches) {
                return $session;
            }
        }

        abort(403, 'Bạn không được phân công coi thi ca này.');
    }

    protected function findStudentByCode(string $rawCode): User
    {
        $code = strtolower(trim($rawCode));
        if ($code === '') {
            abort(400, 'Vui lòng nhập MSSV hoặc email sinh viên.');
        }

        $identity = StudentIdentity::whereRaw('LOWER(student_code) = ?', [$code])->first();
        if ($identity) {
            $st = User::where('id', $identity->user_id)->where('role', User::ROLE_STUDENT)->where('status', 'Active')->first();
            if ($st) {
                return $st;
            }
        }

        $st = User::where('role', User::ROLE_STUDENT)
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

    protected function getOrCreateAccessState(string $sessionId, string $studentId): ExamAccessState
    {
        $state = ExamAccessState::where('session_id', $sessionId)
            ->where('student_id', $studentId)
            ->first();

        if (!$state) {
            $state = ExamAccessState::create([
                'session_id' => $sessionId,
                'student_id' => $studentId,
                'locked'     => false,
            ]);
        }

        return $state;
    }

    protected function createBrowserSession(Request $request, ExamAdmission $row, string $status, $now): ExamBrowserSession
    {
        $browserCode = $this->uniqueBrowserCode();
        return ExamBrowserSession::create([
            'id'           => (string) Str::uuid(),
            'admission_id' => $row->id,
            'session_id'   => $row->session_id,
            'student_id'   => $row->student_id,
            'browser_code' => $browserCode,
            'status'       => $status,
            'user_agent'   => substr((string) $request->userAgent(), 0, 500),
            'ip_address'   => $request->ip(),
            'started_at'   => $now,
            'last_seen_at' => $now,
        ]);
    }

    protected function uniqueBrowserCode(): string
    {
        $max = strlen(self::BROWSER_ALPHABET) - 1;
        for ($try = 0; $try < 30; $try++) {
            $code = '';
            for ($i = 0; $i < 6; $i++) {
                $code .= self::BROWSER_ALPHABET[random_int(0, $max)];
            }
            if (!ExamBrowserSession::where('browser_code', $code)->exists()) {
                return $code;
            }
        }
        return strtoupper(substr(Str::uuid()->toString(), 0, 6));
    }

    protected function buildBrowserCookieValue(string $browserId, string $admissionId): string
    {
        $payload = json_encode(['b' => $browserId, 'a' => $admissionId]);
        $encoded = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
        $sig = hash_hmac('sha256', $encoded, (string) config('app.key', 'isql-secret'));
        return "{$encoded}.{$sig}";
    }

    protected function readBrowserCookie(?string $token, string $admissionId): ?ExamBrowserSession
    {
        if (!is_string($token) || !str_contains($token, '.')) {
            return null;
        }
        [$encoded, $sig] = explode('.', $token, 2);
        $expected = hash_hmac('sha256', $encoded, (string) config('app.key', 'isql-secret'));
        if (!hash_equals($expected, $sig)) {
            return null;
        }
        $pad = strlen($encoded) % 4;
        $b64 = $encoded . ($pad ? str_repeat('=', 4 - $pad) : '');
        $data = json_decode(base64_decode(strtr($b64, '-_', '+/')), true);
        if (!is_array($data) || ($data['a'] ?? null) !== $admissionId || empty($data['b'])) {
            return null;
        }
        return ExamBrowserSession::find($data['b']);
    }
}

