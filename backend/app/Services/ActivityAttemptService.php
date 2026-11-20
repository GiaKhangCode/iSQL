<?php

namespace App\Services;

use App\Models\ActivityAttempt;
use App\Models\Assignment;
use App\Models\AssignmentProblem;
use App\Models\ClassEnrollment;
use App\Models\ExamAccessState;
use App\Models\ExamAdmission;
use App\Models\Problem;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Lượt làm bài của sinh viên cho một hoạt động (bài tập hoặc ca thi): kiểm tra quyền vào, nháp, vi phạm,
 * nộp / hết giờ và dựng phản hồi cho frontend (WorkspaceExamContext).
 *
 * Tách từ Student/ActivityAttemptController. Việc chấm từng bài nằm ở ActivityAttemptGradingService.
 * Lỗi nghiệp vụ ném HttpException (abort) nên sẽ thành JSON {message, detail} theo chuẩn của API.
 */
class ActivityAttemptService
{
    /** Các hành động ghi hợp lệ của POST /activity-attempts/{id}/{action}. */
    public const ACTIONS = ['save', 'violation', 'event', 'submit', 'timeout'];

    /** Lý do sự kiện được tính là "rời màn hình thi" dù không bị đếm là vi phạm. */
    public const DEPARTURE_REASONS = ['tab-hidden', 'window-blur', 'fullscreen-exit', 'page-leave'];

    public function __construct(
        protected readonly ExamAdmissionService $examAdmissionService,
        protected readonly ActivityAttemptGradingService $grader
    ) {
    }

    /** GET: trạng thái lượt làm. Hết giờ mà chưa nộp thì tự nộp (timeout). */
    public function show(?Request $request, User $user, string $activityId, string $source): array
    {
        $activity = $this->resolveActivity($request, $activityId, $user, true);

        $attempt = $this->getOrCreateAttempt($activity, $user->id, false);
        if ($attempt && $attempt->status !== 'submitted' && $this->pastDeadline($activity)) {
            $this->finishAttempt($activity, $attempt, $user, $source, 'timeout');
            $attempt = $this->getAttempt($activity->id, $user->id);
        }

        return $this->attemptResponse($activity, $attempt, $user);
    }

    /** POST /start: bắt đầu ca thi (chỉ hoạt động ở chế độ thi). Gọi lại thì trả lượt hiện có. */
    public function start(?Request $request, User $user, string $activityId, string $source): array
    {
        $activity = $this->resolveActivity($request, $activityId, $user, false);

        if (!$activity->exam_mode) {
            abort(400, 'Activity is not in exam mode.');
        }

        $attempt = $this->getOrCreateAttempt($activity, $user->id, true);
        if ($attempt->status === 'submitted') {
            return $this->attemptResponse($activity, $attempt, $user);
        }

        if ($this->pastDeadline($activity)) {
            $this->finishAttempt($activity, $attempt, $user, $source, 'timeout');
            $attempt = $this->getAttempt($activity->id, $user->id);
        }

        return $this->attemptResponse($activity, $attempt, $user);
    }

    /**
     * POST /{action}: save | violation | event | submit | timeout.
     *
     * @param array<string, mixed> $input toàn bộ body của request
     */
    public function applyAction(?Request $request, User $user, string $activityId, string $action, string $source, array $input): array
    {
        if (!in_array($action, self::ACTIONS, true)) {
            abort(404);
        }

        $finishing = in_array($action, ['submit', 'timeout'], true);
        $activity = $this->resolveActivity($request, $activityId, $user, $finishing);

        $createExam = $activity->exam_mode && $finishing;
        $attempt = $this->getOrCreateAttempt($activity, $user->id, $createExam);

        if (!$attempt) {
            abort(409, 'Start the exam before continuing.');
        }

        if ($attempt->status === 'submitted') {
            return $this->attemptResponse($activity, $attempt, $user);
        }

        $draftsInput = $input['drafts'] ?? null;
        if (is_array($draftsInput) && !empty($draftsInput)) {
            $attempt->drafts = $this->mergeDrafts((array) ($attempt->drafts ?? []), $draftsInput);
        }

        // Ảnh chụp một bài do frontend gửi kèm (problemId, ddl, dml, query, trigger, revision).
        $this->saveSnapshot($activity, $attempt, $input);

        $submissions = [];
        if (in_array($action, ['violation', 'event'], true)) {
            $submissions = $this->recordViolation($activity, $attempt, $user, $source, $action, $input);
        } elseif ($finishing) {
            $reason = ($action === 'timeout' || $this->pastDeadline($activity)) ? 'timeout' : 'submitted';
            $submissions = $this->finishAttempt($activity, $attempt, $user, $source, $reason);
        } else {
            // save
            if ($this->pastDeadline($activity)) {
                $submissions = $this->finishAttempt($activity, $attempt, $user, $source, 'timeout');
            } else {
                $attempt->save();
            }
        }

        $attempt = $this->getAttempt($activity->id, $user->id);
        $payload = $this->attemptResponse($activity, $attempt, $user);
        if ($finishing) {
            $payload['submissions'] = $submissions;
        }

        return $payload;
    }

    /**
     * Ghi một vi phạm (hoặc sự kiện không đếm). Trùng eventId thì bỏ qua. Chạm giới hạn vi phạm ở chế độ thi
     * thì nộp bài ngay.
     *
     * @return array<int, array<string, mixed>> kết quả chấm nếu lượt làm bị kết thúc, ngược lại rỗng
     */
    protected function recordViolation(Assignment $activity, ActivityAttempt $attempt, User $user, string $source, string $action, array $input): array
    {
        $eventId = trim((string) ($input['eventId'] ?? $input['event_id'] ?? ''));
        $kind = trim((string) ($input['kind'] ?? ''));
        $durationMs = $input['durationMs'] ?? $input['duration_ms'] ?? null;
        $counted = ($action === 'violation') && !in_array($kind, ['focus_returned', 'clipboard_panel'], true);
        $reason = $kind !== '' ? $kind : (trim((string) ($input['reason'] ?? '')) ?: ($counted ? 'focus-lost' : 'activity-event'));
        $detail = trim((string) ($input['detail'] ?? ''));

        $violations = is_array($attempt->violations) ? $attempt->violations : [];
        $alreadyRecorded = false;
        if ($eventId !== '') {
            foreach ($violations as $existingEvt) {
                if (is_array($existingEvt) && ($existingEvt['eventId'] ?? '') === $eventId) {
                    $alreadyRecorded = true;
                    break;
                }
            }
        }

        if (!$alreadyRecorded) {
            $event = [
                'reason'  => substr($reason, 0, 80),
                'at'      => now()->toISOString(),
                'counted' => $counted,
            ];
            if ($eventId !== '') {
                $event['eventId'] = substr($eventId, 0, 64);
            }
            if ($kind !== '') {
                $event['kind'] = substr($kind, 0, 40);
            }
            if ($durationMs !== null && is_numeric($durationMs)) {
                $event['durationMs'] = (int) $durationMs;
            }
            if ($detail !== '') {
                $event['detail'] = substr($detail, 0, 240);
            }
            $violations[] = $event;
            $attempt->violations = $violations;
        }

        $limit = max(1, (int) ($activity->exam_violation_limit ?? 2));
        if ($counted && $activity->exam_mode && $this->violationCount($attempt) >= $limit) {
            return $this->finishAttempt($activity, $attempt, $user, $source, 'violations');
        }

        $attempt->save();

        return [];
    }

    /**
     * Kiểm tra sinh viên được phép làm hoạt động này: đã công bố, đã mở, còn hạn (trừ khi $allowClosed),
     * đã đăng nhập tài khoản ca thi nếu bắt buộc, không bị khóa, và thuộc đối tượng được giao.
     */
    protected function resolveActivity(?Request $request, string $activityId, User $user, bool $allowClosed): Assignment
    {
        $activity = Assignment::findOrFail($activityId);
        if (!$activity->published) {
            abort(403, 'This activity is not available.');
        }

        if ($activity->opens && now()->lessThan($activity->opens)) {
            abort(403, 'This activity has not opened yet.');
        }

        if (!$allowClosed && $this->pastDeadline($activity)) {
            abort(403, 'The submission window for this activity has closed.');
        }

        if ($activity->admission_required && !$this->examAdmissionService->hasUnlockedAdmission($request, $user, $activity)) {
            $hasSubmittedAttempt = ActivityAttempt::where('assignment_id', $activity->id)
                ->where('user_id', $user->id)
                ->where('status', 'submitted')
                ->exists();
            if (!$hasSubmittedAttempt || !$allowClosed) {
                abort(403, 'Cần đăng nhập tài khoản ca thi trước khi truy cập.');
            }
        }

        $accessState = ExamAccessState::where('session_id', $activity->id)
            ->where('student_id', $user->id)
            ->first();
        if ($accessState && $accessState->locked && !$allowClosed) {
            abort(403, 'Tài khoản ca thi đang tạm khóa. Vui lòng báo giám thị phòng thi.');
        }

        if (!$this->isAssignedTo($activity, $user)) {
            abort(403, 'You are not assigned to this activity.');
        }

        return $activity;
    }

    /** Được giao qua lớp, giao riêng, hoặc có tài khoản ca thi chưa bị thu hồi. */
    protected function isAssignedTo(Assignment $activity, User $user): bool
    {
        $classIds = ClassEnrollment::where('student_id', $user->id)->pluck('class_id')->all();
        $inClass = !empty($classIds) && DB::table('assignment_classes')
            ->where('assignment_id', $activity->id)
            ->whereIn('class_id', $classIds)
            ->exists();
        if ($inClass) {
            return true;
        }

        $isSelected = DB::table('assignment_students')
            ->where('assignment_id', $activity->id)
            ->where('student_id', $user->id)
            ->exists();
        if ($isSelected) {
            return true;
        }

        return ExamAdmission::where('session_id', $activity->id)
            ->where('student_id', $user->id)
            ->where('status', '!=', 'revoked')
            ->exists();
    }

    protected function getAttempt(string $activityId, string $userId): ?ActivityAttempt
    {
        return ActivityAttempt::where('assignment_id', $activityId)
            ->where('user_id', $userId)
            ->first();
    }

    /** Ở chế độ thi, lượt làm chỉ được tạo khi $createForExam (start / submit / timeout); bài thường thì tạo ngay. */
    protected function getOrCreateAttempt(Assignment $activity, string $userId, bool $createForExam): ?ActivityAttempt
    {
        $attempt = $this->getAttempt($activity->id, $userId);
        if ($attempt) {
            return $attempt;
        }

        if ($activity->exam_mode && !$createForExam) {
            return null;
        }

        return ActivityAttempt::create([
            'assignment_id' => $activity->id,
            'user_id'       => $userId,
            'status'        => 'active',
            'drafts'        => [],
            'violations'    => [],
            'started_at'    => now(),
        ]);
    }

    protected function pastDeadline(Assignment $activity): bool
    {
        return $activity->closes !== null && now()->greaterThanOrEqualTo($activity->closes);
    }

    protected function violationCount(?ActivityAttempt $attempt): int
    {
        if (!$attempt || !is_array($attempt->violations)) {
            return 0;
        }
        return collect($attempt->violations)->filter(fn ($e) => is_array($e) && ($e['counted'] ?? true) === true)->count();
    }

    protected function departureCount(?ActivityAttempt $attempt): int
    {
        if (!$attempt || !is_array($attempt->violations)) {
            return 0;
        }
        return collect($attempt->violations)->filter(function ($e) {
            if (!is_array($e)) {
                return false;
            }
            return ($e['counted'] ?? true) === true || in_array($e['reason'] ?? '', self::DEPARTURE_REASONS, true);
        })->count();
    }

    /** Gộp bản nháp nhiều bài; từng câu (questions) được gộp theo phần/mã câu để không mất câu khác. */
    protected function mergeDrafts(array $current, array $incoming): array
    {
        $merged = $current;
        foreach ($incoming as $problemId => $draft) {
            if (!is_array($draft)) {
                continue;
            }
            $prev = is_array($merged[$problemId] ?? null) ? $merged[$problemId] : [];
            $nextQuestions = is_array($prev['questions'] ?? null) ? $prev['questions'] : [];
            if (is_array($draft['questions'] ?? null)) {
                foreach ($draft['questions'] as $part => $values) {
                    if (is_array($values)) {
                        $prevPart = is_array($nextQuestions[$part] ?? null) ? $nextQuestions[$part] : [];
                        foreach ($values as $qid => $code) {
                            $prevPart[(string) $qid] = (string) ($code ?? '');
                        }
                        $nextQuestions[(string) $part] = $prevPart;
                    }
                }
            }

            $entry = [
                'ddl'       => (string) ($draft['ddl'] ?? $prev['ddl'] ?? ''),
                'dml'       => (string) ($draft['dml'] ?? $prev['dml'] ?? ''),
                'query'     => (string) ($draft['query'] ?? $prev['query'] ?? ''),
                'trigger'   => (string) ($draft['trigger'] ?? $prev['trigger'] ?? ''),
                'database'  => (string) ($draft['database'] ?? $prev['database'] ?? 'SQL Server'),
                'updatedAt' => now()->toISOString(),
            ];
            if (!empty($nextQuestions)) {
                $entry['questions'] = $nextQuestions;
            }
            $merged[(string) $problemId] = $entry;
        }

        return $merged;
    }

    /** Lưu ảnh chụp một bài (chỉ bài thuộc hoạt động); bản có revision thấp hơn bản đang lưu bị bỏ qua. */
    protected function saveSnapshot(Assignment $activity, ActivityAttempt $attempt, array $input): void
    {
        $problemId = (string) ($input['problemId'] ?? $input['problem_id'] ?? '');
        if ($problemId === '') {
            return;
        }

        $isMember = AssignmentProblem::where('assignment_id', $activity->id)
            ->where('problem_id', $problemId)
            ->exists();
        if (!$isMember) {
            return;
        }

        $hasAnyField = array_key_exists('ddl', $input)
            || array_key_exists('dml', $input)
            || array_key_exists('query', $input)
            || array_key_exists('trigger', $input);
        if (!$hasAnyField) {
            return;
        }

        $drafts = is_array($attempt->drafts) ? $attempt->drafts : [];
        $prev = is_array($drafts[$problemId] ?? null) ? $drafts[$problemId] : [];
        $incomingRev = (int) ($input['revision'] ?? 0);
        $prevRev = (int) ($prev['revision'] ?? 0);

        if ($incomingRev >= $prevRev) {
            $drafts[$problemId] = [
                'ddl'       => (string) ($input['ddl'] ?? $prev['ddl'] ?? ''),
                'dml'       => (string) ($input['dml'] ?? $prev['dml'] ?? ''),
                'query'     => (string) ($input['query'] ?? $prev['query'] ?? ''),
                'trigger'   => (string) ($input['trigger'] ?? $prev['trigger'] ?? ''),
                'database'  => (string) ($input['database'] ?? $prev['database'] ?? 'SQL Server'),
                'revision'  => $incomingRev,
                'updatedAt' => now()->toISOString(),
            ];
            if (isset($prev['questions']) && is_array($prev['questions'])) {
                $drafts[$problemId]['questions'] = $prev['questions'];
            }
            $attempt->drafts = $drafts;
        }
    }

    /**
     * Chấm mọi bài của hoạt động từ bản nháp rồi đóng lượt làm. Đã nộp rồi thì không làm gì.
     *
     * @return array<int, array<string, mixed>> kết quả từng bài (điểm bị ẩn nếu hoạt động ẩn điểm)
     */
    protected function finishAttempt(Assignment $activity, ActivityAttempt $attempt, User $user, string $source, string $reason): array
    {
        if ($attempt->status === 'submitted') {
            return [];
        }

        $members = AssignmentProblem::where('assignment_id', $activity->id)
            ->orderBy('order_index')
            ->get();

        $drafts = is_array($attempt->drafts) ? $attempt->drafts : [];
        $results = [];

        foreach ($members as $member) {
            $problem = Problem::with(['testCases.scripts', 'sqlSolutions', 'stageExercises'])->find($member->problem_id);
            if (!$problem) {
                continue;
            }

            $draft = is_array($drafts[$problem->id] ?? null) ? $drafts[$problem->id] : [];
            $parts = !empty($member->workspace_parts) ? $member->workspace_parts : ($problem->workspace_parts ?: ['SQL']);

            $sub = $this->grader->gradeAndStore($user, $activity, $member, $problem, $source, $draft, $parts);
            $results[] = [
                'problemId'    => (string) $problem->id,
                'submissionId' => (string) $sub->id,
                'status'       => $activity->show_scores_after_submit ? $sub->result : 'Submitted',
                'score'        => $activity->show_scores_after_submit ? (float) $sub->score : null,
                'maxScore'     => (float) $member->points,
                'scoreVisible' => (bool) $activity->show_scores_after_submit,
            ];
        }

        $attempt->status = 'submitted';
        $attempt->submitted_at = now();
        $attempt->finish_reason = $reason;
        $attempt->save();

        return $results;
    }

    protected function attemptResponse(Assignment $activity, ?ActivityAttempt $attempt, User $user): array
    {
        $problemIds = AssignmentProblem::where('assignment_id', $activity->id)
            ->orderBy('order_index')
            ->pluck('problem_id')
            ->all();

        $countsRows = Submission::where('user_id', $user->id)
            ->where('context', $activity->id)
            ->whereIn('source', ['Assignments', 'ExamSession', 'Assignment'])
            ->whereDoesntHave('workspaceResult', function ($q) {
                $q->whereRaw("JSON_EXTRACT(sections, '$[0]._questionSubmission.part') IS NOT NULL");
            })
            ->select('problem_id', DB::raw('COUNT(id) as cnt'))
            ->groupBy('problem_id')
            ->pluck('cnt', 'problem_id')
            ->all();

        $submissionCounts = [];
        foreach ($problemIds as $pid) {
            $submissionCounts[(string) $pid] = (int) ($countsRows[$pid] ?? 0);
        }

        $resolvedStatus = $attempt
            ? ($attempt->status === 'in_progress' ? 'active' : $attempt->status)
            : ($this->pastDeadline($activity) ? 'closed' : 'not_started');

        return [
            'activityId'            => (string) $activity->id,
            'examMode'              => (bool) $activity->exam_mode,
            'examViolationLimit'    => max(1, (int) ($activity->exam_violation_limit ?? 2)),
            'problemIds'            => array_values($problemIds),
            'closesAt'              => $activity->closes ? $activity->closes->toISOString() : null,
            'serverNow'             => now()->toISOString(),
            'showScoresAfterSubmit' => (bool) $activity->show_scores_after_submit,
            'maxSubmissions'        => $activity->max_submissions,
            'submissionCounts'      => (object) $submissionCounts,
            'status'                => $resolvedStatus,
            'violationCount'        => $this->violationCount($attempt),
            'departureCount'        => $this->departureCount($attempt),
            'drafts'                => $attempt && is_array($attempt->drafts) ? (object) $attempt->drafts : (object) [],
            'startedAt'             => $attempt && $attempt->started_at ? $attempt->started_at->toISOString() : null,
            'submittedAt'           => $attempt && $attempt->submitted_at ? $attempt->submitted_at->toISOString() : null,
            'finishReason'          => $attempt ? $attempt->finish_reason : null,
        ];
    }
}
