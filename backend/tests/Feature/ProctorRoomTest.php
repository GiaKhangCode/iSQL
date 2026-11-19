<?php

namespace Tests\Feature;

use App\Models\ActivityAttempt;
use App\Models\ActivityLog;
use App\Models\Assignment;
use App\Models\ClassModel;
use App\Models\ExamAccessState;
use App\Models\ExamAdmission;
use App\Models\ExamBrowserSession;
use App\Models\ExamClassProctor;
use App\Models\Examination;
use App\Models\Problem;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkspaceSubmissionResult;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ProctorFixtures;
use Tests\Concerns\UsesDefaultRolePermissions;
use Tests\TestCase;

/**
 * Đặc tả các thao tác trong phòng thi của giám thị (ExamLoginProctorController):
 * trạng thái phòng, mở khóa, kiểm tra / thêm sinh viên, duyệt trình duyệt mới.
 * Quyền vào ca nằm ở ProctorAccessTest; ở đây người thao tác chủ yếu là quản lý kỳ thi (examination.manage).
 */
class ProctorRoomTest extends TestCase
{
    use DatabaseTransactions;
    use ProctorFixtures;
    use UsesDefaultRolePermissions;

    protected User $manager;
    protected Examination $exam;
    protected Assignment $session;
    protected ClassModel $classA;
    protected ClassModel $classB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useDefaultRolePermissions();

        $this->manager = $this->makeAccount('faculty_office', ['examination.manage']);
        $this->exam = $this->makeExamination($this->manager);
        $this->session = $this->makeExamSession(['title' => 'Ca đích', 'session_index' => 1], $this->exam);
        $this->classA = $this->makeClass(['practice_class' => 'PX.A1']);
        $this->classB = $this->makeClass(['practice_class' => 'PX.B1']);
        $this->linkClass($this->session, $this->classA);
        $this->linkClass($this->session, $this->classB);
        Sanctum::actingAs($this->manager);
    }

    private function named(User $user, string $name): User
    {
        $user->update(['name' => $name]);

        return $user;
    }

    private function roomRows(?string $roomParam = null, string $key = 'room_id'): array
    {
        $query = $roomParam ? "?{$key}={$roomParam}" : '';

        return $this->getJson("/api/proctor/sessions/{$this->session->id}{$query}")->assertOk()->json();
    }

    private function attempt(Assignment $session, User $student, array $attrs = []): ActivityAttempt
    {
        return ActivityAttempt::create(array_merge([
            'assignment_id' => $session->id, 'user_id' => $student->id, 'status' => 'active',
            'drafts' => [], 'violations' => [], 'started_at' => now(),
        ], $attrs));
    }

    private function browser(ExamAdmission $admission, string $status, array $attrs = []): ExamBrowserSession
    {
        return ExamBrowserSession::create(array_merge([
            'id' => (string) Str::uuid(), 'admission_id' => $admission->id, 'session_id' => $admission->session_id,
            'student_id' => $admission->student_id, 'browser_code' => strtoupper(Str::random(6)), 'status' => $status,
            'started_at' => now(), 'last_seen_at' => now(),
        ], $attrs));
    }

    private function counted(int $n, array $extra = []): array
    {
        return array_merge(array_fill(0, $n, ['reason' => 'focus-lost', 'counted' => true]), $extra);
    }

    // ══ GET /api/proctor/sessions/{id} (trạng thái phòng) ════════════════════

    private function rosterFixture(): array
    {
        $sA1 = $this->named($this->makeStudent('2101' . random_int(1000, 9999)), 'PX 1 An');
        $sA2 = $this->named($this->makeStudent(), 'PX 2 Binh');
        $sB1 = $this->named($this->makeStudent(), 'PX 3 Chi');
        $d1 = $this->named($this->makeStudent(), 'PX 4 Dung');
        $d2 = $this->named($this->makeStudent(), 'PX 5 Em');
        $inactive = $this->named($this->makeStudent(null, 'Inactive'), 'PX 6 Inactive');
        $staff = $this->named($this->makeAccount('instructor'), 'PX 7 Staff');
        $outsider = $this->named($this->makeStudent(), 'PX 8 Outsider');
        // Email xếp ngược với tên để test thứ tự phân biệt được "theo tên" với "theo email".
        foreach ([$sA1, $sA2, $sB1, $d1, $d2] as $i => $u) {
            $u->update(['email' => 'px_' . (9 - $i) . '_' . uniqid() . '@gm.uit.edu.vn']);
        }
        foreach ([$sA1, $sA2, $inactive, $staff] as $u) {
            $this->enroll($this->classA, $u);
        }
        $this->enroll($this->classB, $sB1);
        $this->enroll($this->classB, $d2);
        $this->addDirect($this->session, $d1);
        $this->addDirect($this->session, $d2);

        return compact('sA1', 'sA2', 'sB1', 'd1', 'd2', 'inactive', 'staff', 'outsider');
    }

    public function test_room_status_lists_enrolled_and_direct_active_students_sorted_by_name(): void
    {
        $f = $this->rosterFixture();

        $rows = $this->roomRows();

        $this->assertSame(
            [$f['sA1']->id, $f['sA2']->id, $f['sB1']->id, $f['d1']->id, $f['d2']->id],
            array_column($rows, 'studentId')
        );
        $by = collect($rows)->keyBy('studentId');
        $this->assertSame($this->classA->id, $by[$f['sA1']->id]['roomId']);
        $this->assertSame($this->classB->id, $by[$f['sB1']->id]['roomId']);
        $this->assertNull($by[$f['d1']->id]['roomId']);
        $this->assertSame($this->classB->id, $by[$f['d2']->id]['roomId']);
        $this->assertSame($f['sA1']->email, $by[$f['sA1']->id]['email']);
        $this->assertSame('PX 1 An', $by[$f['sA1']->id]['name']);
    }

    public function test_room_filter_keeps_that_rooms_students_and_unassigned_direct_students(): void
    {
        $f = $this->rosterFixture();

        foreach ([['room_id', $this->classA->id], ['roomId', $this->classA->id]] as [$key, $room]) {
            $rows = $this->roomRows($room, $key);
            $this->assertSame([$f['sA1']->id, $f['sA2']->id, $f['d1']->id], array_column($rows, 'studentId'), $key);
            $this->assertSame([$room, $room, $room], array_column($rows, 'roomId'));
        }

        $rowsB = $this->roomRows($this->classB->id);
        $this->assertSame([$f['sB1']->id, $f['d1']->id, $f['d2']->id], array_column($rowsB, 'studentId'));
    }

    public function test_student_code_comes_from_identity_or_falls_back_to_the_email_prefix(): void
    {
        $f = $this->rosterFixture();

        $by = collect($this->roomRows())->keyBy('studentId');

        $this->assertSame(\App\Models\StudentIdentity::find($f['sA1']->id)->student_code, $by[$f['sA1']->id]['studentCode']);
        $this->assertSame(strtolower(explode('@', $f['sA2']->email)[0]), $by[$f['sA2']->id]['studentCode']);
    }

    public function test_session_without_classes_or_students_returns_an_empty_list(): void
    {
        $empty = $this->makeExamSession([], $this->exam);

        $this->assertSame([], $this->getJson("/api/proctor/sessions/{$empty->id}")->assertOk()->json());
    }

    public function test_violation_and_lock_flags(): void
    {
        $mk = fn (string $name) => tap($this->named($this->makeStudent(), $name), fn ($s) => $this->enroll($this->classA, $s));
        $v1 = $mk('PX v1');
        $v2 = $mk('PX v2');
        $v3 = $mk('PX v3');
        $v4 = $mk('PX v4');
        $v5 = $mk('PX v5');
        $v6 = $mk('PX v6');
        $v7 = $mk('PX v7');
        $v8 = $mk('PX v8');
        $submittedAt = now()->subMinutes(10)->startOfSecond();
        $incident = now()->subMinutes(20)->startOfSecond();

        $this->attempt($this->session, $v1, ['status' => 'submitted', 'finish_reason' => 'violations', 'submitted_at' => $submittedAt, 'violations' => $this->counted(2)]);
        $this->attempt($this->session, $v2, ['violations' => $this->counted(2, [['reason' => 'x', 'counted' => false]])]);
        $this->attempt($this->session, $v3, ['violations' => $this->counted(1)]);
        $this->attempt($this->session, $v4, ['status' => 'submitted', 'finish_reason' => 'timeout', 'violations' => $this->counted(3), 'submitted_at' => $submittedAt]);
        $this->attempt($this->session, $v5, ['status' => 'submitted', 'finish_reason' => 'violation_limit', 'violations' => [], 'submitted_at' => $submittedAt]);
        $this->attempt($this->session, $v6, ['violations' => [['reason' => 'cu'], 'rac']]);
        $this->attempt($this->session, $v7, ['status' => 'submitted', 'finish_reason' => 'violations', 'submitted_at' => $submittedAt, 'violations' => $this->counted(2)]);
        ExamAccessState::create(['session_id' => $this->session->id, 'student_id' => $v7->id, 'locked' => true, 'lock_reason' => 'duplicate_login', 'incident_at' => $incident]);
        ExamAccessState::create(['session_id' => $this->session->id, 'student_id' => $v8->id, 'locked' => true, 'lock_reason' => 'duplicate_login']);

        $by = collect($this->roomRows())->keyBy('studentId');

        $row = fn (User $u) => $by[$u->id];
        $this->assertTrue($row($v1)['isViolationLocked']);
        $this->assertFalse($row($v1)['isDuplicateLocked']);
        $this->assertTrue($row($v1)['locked']);
        $this->assertSame(2, $row($v1)['violationCount']);
        $this->assertSame(2, $row($v1)['violationLimit']);
        $this->assertSame('violations', $row($v1)['finishReason']);
        $this->assertSame($submittedAt->toISOString(), $row($v1)['incidentAt']);
        $this->assertSame('submitted', $row($v1)['attemptStatus']);

        // Đủ số vi phạm tính điểm mà chưa nộp => khóa; sự kiện không tính (counted=false) không góp vào.
        $this->assertTrue($row($v2)['isViolationLocked']);
        $this->assertSame(2, $row($v2)['violationCount']);
        $this->assertNull($row($v2)['incidentAt']);

        $this->assertFalse($row($v3)['locked']);
        $this->assertSame(1, $row($v3)['violationCount']);
        $this->assertSame('active', $row($v3)['attemptStatus']);

        // Đã nộp vì lý do khác thì quá giới hạn cũng không còn khóa.
        $this->assertFalse($row($v4)['isViolationLocked']);
        $this->assertSame(3, $row($v4)['violationCount']);
        $this->assertNull($row($v4)['incidentAt']);

        $this->assertTrue($row($v5)['isViolationLocked']);
        $this->assertSame(0, $row($v5)['violationCount']);

        // Sự kiện cũ thiếu cờ counted tính là vi phạm; phần tử rác bị bỏ qua.
        $this->assertSame(1, $row($v6)['violationCount']);
        $this->assertFalse($row($v6)['locked']);

        // Khóa trùng đăng nhập + khóa vi phạm: giờ sự cố lấy từ trạng thái truy cập.
        $this->assertTrue($row($v7)['isDuplicateLocked']);
        $this->assertTrue($row($v7)['isViolationLocked']);
        $this->assertSame($incident->toISOString(), $row($v7)['incidentAt']);

        $this->assertTrue($row($v8)['locked']);
        $this->assertTrue($row($v8)['isDuplicateLocked']);
        $this->assertFalse($row($v8)['isViolationLocked']);
        $this->assertNull($row($v8)['incidentAt']);
    }

    public function test_violation_limit_comes_from_the_session_with_a_floor_of_one(): void
    {
        $s = $this->named($this->makeStudent(), 'PX limit');
        $this->enroll($this->classA, $s);
        $this->attempt($this->session, $s, ['violations' => $this->counted(1)]);

        $this->assertFalse($this->roomRows()[0]['locked']);

        $this->session->update(['exam_violation_limit' => 5]);
        $this->assertSame(5, $this->roomRows()[0]['violationLimit']);

        $this->session->update(['exam_violation_limit' => 0]);
        $row = $this->roomRows()[0];
        $this->assertSame(1, $row['violationLimit']);
        $this->assertTrue($row['isViolationLocked']);
    }

    public function test_attempt_status_and_admission_id(): void
    {
        $claimed = $this->named($this->makeStudent(), 'PX 1 claimed');
        $issued = $this->named($this->makeStudent(), 'PX 2 issued');
        $none = $this->named($this->makeStudent(), 'PX 3 none');
        $done = $this->named($this->makeStudent(), 'PX 4 done');
        foreach ([$claimed, $issued, $none, $done] as $s) {
            $this->enroll($this->classA, $s);
        }
        $claimedAdm = $this->makeAdmission($claimed, $this->session, ['status' => 'claimed']);
        $issuedAdm = $this->makeAdmission($issued, $this->session);
        $this->makeAdmission($done, $this->session, ['status' => 'claimed']);
        $this->attempt($this->session, $done, ['status' => 'submitted', 'finish_reason' => 'submitted', 'submitted_at' => now()]);

        $by = collect($this->roomRows())->keyBy('studentId');

        $this->assertSame('in_progress', $by[$claimed->id]['attemptStatus']);
        $this->assertSame($claimedAdm->id, $by[$claimed->id]['admissionId']);
        $this->assertSame('not_started', $by[$issued->id]['attemptStatus']);
        $this->assertSame($issuedAdm->id, $by[$issued->id]['admissionId']);
        $this->assertSame('not_started', $by[$none->id]['attemptStatus']);
        $this->assertNull($by[$none->id]['admissionId']);
        $this->assertSame('submitted', $by[$done->id]['attemptStatus']);
        $this->assertSame('submitted', $by[$done->id]['finishReason']);
        $this->assertNull($by[$none->id]['finishReason']);
    }

    public function test_browsers_are_grouped_per_student_newest_first_without_revoked_ones(): void
    {
        $s = $this->named($this->makeStudent(), 'PX browsers');
        $other = $this->named($this->makeStudent(), 'PX other');
        foreach ([$s, $other] as $x) {
            $this->enroll($this->classA, $x);
        }
        $adm = $this->makeAdmission($s, $this->session);
        $base = now()->subHours(10)->startOfSecond();
        $made = [];
        foreach ([3, 1, 5, 2, 4] as $i) {
            $made[$i] = $this->browser($adm, $i === 5 ? 'revoked' : ($i === 1 ? 'pending' : 'active'), [
                'started_at' => $base->copy()->addHours($i), 'ip_address' => "10.0.0.{$i}", 'user_agent' => "UA{$i}",
            ]);
        }
        $made[1]->update(['last_seen_at' => null, 'user_agent' => null]);
        // Trình duyệt của ca khác không được lẫn vào.
        $elsewhere = $this->makeExamSession([], $this->exam);
        $this->browser($this->makeAdmission($s, $elsewhere), 'active');

        $by = collect($this->roomRows())->keyBy('studentId');
        $browsers = $by[$s->id]['browsers'];

        // Trình duyệt số 5 đã thu hồi nên không có trong danh sách.
        $this->assertSame([4, 3, 2, 1], array_map(fn ($b) => (int) substr($b['ipAddress'], -1), $browsers));
        $this->assertSame([], $by[$other->id]['browsers']);
        $this->assertSame($made[4]->id, $browsers[0]['id']);
        $this->assertSame($made[4]->browser_code, $browsers[0]['code']);
        $this->assertSame('active', $browsers[0]['status']);
        $this->assertSame('UA4', $browsers[0]['userAgent']);
        $this->assertSame($base->copy()->addHours(4)->toISOString(), $browsers[0]['startedAt']);
        $this->assertSame($browsers[0]['startedAt'], $browsers[0]['createdAt']);
        $this->assertNotNull($browsers[0]['lastSeenAt']);
        // Thiếu user agent / lần thấy cuối: chuỗi rỗng / null.
        $this->assertSame('', $browsers[3]['userAgent']);
        $this->assertNull($browsers[3]['lastSeenAt']);
        $this->assertSame('pending', $browsers[3]['status']);
    }

    // ══ POST .../students/{id}/unlock ════════════════════════════════════════

    private function unlock(User $student, ?Assignment $session = null)
    {
        return $this->postJson('/api/proctor/sessions/' . ($session ?? $this->session)->id . "/students/{$student->id}/unlock");
    }

    private function problem(): Problem
    {
        return Problem::create([
            'number' => 'PXP-' . strtoupper(uniqid()), 'title' => 'PX bài', 'difficulty' => 'Easy',
            'description' => 'd', 'requirements' => 'r', 'workspace_parts' => ['SQL'],
        ]);
    }

    private function submissionFor(User $student, string $context, string $source, Problem $problem): Submission
    {
        $sub = Submission::create([
            'user_id' => $student->id, 'problem_id' => $problem->id, 'source' => $source, 'context' => $context,
            'result' => 'Accepted', 'score' => 1, 'query_text' => '{}', 'submitted_at' => now(),
        ]);
        WorkspaceSubmissionResult::create(['submission_id' => $sub->id, 'sections' => []]);

        return $sub;
    }

    public function test_unlock_clears_the_lock_and_records_who_did_it(): void
    {
        $s = $this->makeStudent();
        $this->addDirect($this->session, $s);
        ExamAccessState::create([
            'session_id' => $this->session->id, 'student_id' => $s->id, 'locked' => true,
            'lock_reason' => 'duplicate_login', 'incident_at' => now(),
        ]);

        $this->unlock($s)->assertOk()->assertExactJson(['ok' => true]);

        $state = ExamAccessState::where('session_id', $this->session->id)->where('student_id', $s->id)->first();
        $this->assertFalse((bool) $state->locked);
        $this->assertNull($state->lock_reason);
        $this->assertNull($state->incident_at);
        $this->assertNotNull($state->unlocked_at);
        $this->assertSame($this->manager->id, $state->unlocked_by);

        $log = ActivityLog::where('action', "proctor:unlock:{$this->session->id}:{$s->id}")->sole();
        $this->assertSame($this->manager->id, $log->user_id);
    }

    public function test_unlock_creates_the_access_state_when_missing(): void
    {
        $member = $this->makeStudent();
        $this->addDirect($this->session, $member);

        $this->unlock($member)->assertOk();

        $this->assertSame(1, ExamAccessState::where('session_id', $this->session->id)->where('student_id', $member->id)->count());
    }

    public function test_unlock_rejects_a_student_who_is_not_in_the_session_and_changes_nothing(): void
    {
        $stranger = $this->makeStudent();
        // Thuộc ca khác của cùng kỳ thi thì cũng không phải thành viên của ca này.
        $other = $this->makeExamSession([], $this->exam);
        $this->addDirect($other, $stranger);

        $this->unlock($stranger)->assertStatus(404)->assertJsonPath('detail', 'Sinh viên không thuộc ca thi này.');
        $this->unlock($this->makeStudent())->assertStatus(404);

        $this->assertSame(0, ExamAccessState::where('session_id', $this->session->id)->count());
        $this->assertSame(0, ActivityLog::where('action', 'like', "proctor:unlock:{$this->session->id}:%")->count());
    }

    public function test_unlock_accepts_every_kind_of_session_membership(): void
    {
        $viaClass = $this->makeStudent();
        $this->enroll($this->classA, $viaClass);
        $viaAdmission = $this->makeStudent();
        $this->makeAdmission($viaAdmission, $this->session);
        $viaAttempt = $this->makeStudent();
        $this->attempt($this->session, $viaAttempt);

        foreach ([$viaClass, $viaAdmission, $viaAttempt] as $s) {
            $this->unlock($s)->assertOk();
        }
    }

    public function test_unlock_with_an_unknown_session_is_404_and_without_access_is_403(): void
    {
        $s = $this->makeStudent();
        $this->addDirect($this->session, $s);
        $proctor = $this->makeAccount('instructor', ['exam.proctor']);
        Sanctum::actingAs($proctor);

        $this->unlock($s)->assertStatus(403);
        Sanctum::actingAs($this->manager);
        $this->postJson("/api/proctor/sessions/khong-ton-tai/students/{$s->id}/unlock")->assertStatus(404);
    }

    public function test_unlock_marks_every_violation_event_as_uncounted_and_unlocked(): void
    {
        $s = $this->makeStudent();
        $this->addDirect($this->session, $s);
        $this->attempt($this->session, $s, ['violations' => [
            ['reason' => 'a', 'counted' => true, 'eventId' => 'e1'], ['reason' => 'b'], 'rac',
        ]]);

        $this->unlock($s)->assertOk();

        $attempt = ActivityAttempt::where('assignment_id', $this->session->id)->where('user_id', $s->id)->first();
        $this->assertSame('active', $attempt->status);
        $this->assertSame([
            ['reason' => 'a', 'counted' => false, 'eventId' => 'e1', 'unlocked' => true],
            ['reason' => 'b', 'counted' => false, 'unlocked' => true],
        ], $attempt->violations);
    }

    public function test_unlock_reopens_an_attempt_that_was_submitted_because_of_violations(): void
    {
        $problem = $this->problem();
        $victim = $this->makeStudent();
        $this->addDirect($this->session, $victim);
        $other = $this->makeStudent();
        $otherSession = $this->makeExamSession([], $this->exam);
        foreach (['violations', 'violation_limit'] as $reason) {
            $this->attempt($this->session, $victim, [
                'status' => 'submitted', 'finish_reason' => $reason, 'submitted_at' => now(), 'violations' => $this->counted(2),
            ]);
            $gone = [
                $this->submissionFor($victim, $this->session->id, 'ExamSession', $problem),
                $this->submissionFor($victim, $this->session->id, 'Assignments', $problem),
                $this->submissionFor($victim, $this->session->id, 'Assignment', $problem),
            ];
            $kept = [
                $this->submissionFor($victim, $this->session->id, 'Practice', $problem),
                $this->submissionFor($victim, $otherSession->id, 'ExamSession', $problem),
                $this->submissionFor($other, $this->session->id, 'ExamSession', $problem),
            ];

            $this->unlock($victim)->assertOk();

            $attempt = ActivityAttempt::where('assignment_id', $this->session->id)->where('user_id', $victim->id)->first();
            $this->assertSame('in_progress', $attempt->status, $reason);
            $this->assertNull($attempt->submitted_at);
            $this->assertNull($attempt->finish_reason);
            foreach ($gone as $sub) {
                $this->assertNull(Submission::find($sub->id), "{$reason}: bài nộp phải bị xóa");
                $this->assertNull(WorkspaceSubmissionResult::find($sub->id));
            }
            foreach ($kept as $sub) {
                $this->assertNotNull(Submission::find($sub->id), "{$reason}: bài nộp khác phải còn");
                $this->assertNotNull(WorkspaceSubmissionResult::find($sub->id));
            }
            ActivityAttempt::where('assignment_id', $this->session->id)->delete();
            Submission::whereIn('id', array_map(fn ($s) => $s->id, $kept))->delete();
        }
    }

    public function test_unlock_keeps_an_attempt_that_was_submitted_for_another_reason(): void
    {
        $s = $this->makeStudent();
        $this->addDirect($this->session, $s);
        $problem = $this->problem();
        $submittedAt = now()->subMinute()->startOfSecond();
        $this->attempt($this->session, $s, ['status' => 'submitted', 'finish_reason' => 'submitted', 'submitted_at' => $submittedAt, 'violations' => $this->counted(1)]);
        $sub = $this->submissionFor($s, $this->session->id, 'ExamSession', $problem);

        $this->unlock($s)->assertOk();

        $attempt = ActivityAttempt::where('assignment_id', $this->session->id)->where('user_id', $s->id)->first();
        $this->assertSame('submitted', $attempt->status);
        $this->assertSame('submitted', $attempt->finish_reason);
        $this->assertSame($submittedAt->toISOString(), $attempt->submitted_at->toISOString());
        $this->assertNotNull(Submission::find($sub->id));
        $this->assertFalse($attempt->violations[0]['counted']);
    }

    public function test_unlocked_student_can_continue_the_exam(): void
    {
        $s = $this->makeStudent();
        $this->addDirect($this->session, $s);
        $this->attempt($this->session, $s, ['status' => 'submitted', 'finish_reason' => 'violations', 'submitted_at' => now(), 'violations' => $this->counted(2)]);
        Sanctum::actingAs($s);
        $this->getJson("/api/student/activity-attempts/{$this->session->id}?source=ExamSession")
            ->assertOk()->assertJsonPath('status', 'submitted')->assertJsonPath('finishReason', 'violations');

        Sanctum::actingAs($this->manager);
        $this->unlock($s)->assertOk();

        Sanctum::actingAs($s);
        $this->getJson("/api/student/activity-attempts/{$this->session->id}?source=ExamSession")
            ->assertOk()->assertJsonPath('status', 'active')->assertJsonPath('violationCount', 0);
    }

    // ══ POST .../students/check và .../students/add ══════════════════════════

    private function check(string $code, ?string $room = null, string $codeKey = 'student_code')
    {
        return $this->postJson("/api/proctor/sessions/{$this->session->id}/students/check", array_filter([$codeKey => $code, 'room_id' => $room]));
    }

    private function add(string $code, ?string $room = null)
    {
        return $this->postJson("/api/proctor/sessions/{$this->session->id}/students/add", array_filter(['student_code' => $code, 'room_id' => $room]));
    }

    /** Ca anh em cùng kỳ thi, có lớp B2. */
    private function sibling(array $overrides = []): Assignment
    {
        $sibling = $this->makeExamSession(array_merge(['title' => 'Ca anh em', 'session_index' => 2], $overrides), $this->exam);
        $this->linkClass($sibling, $this->makeClass(['practice_class' => 'PX.S1']));

        return $sibling;
    }

    private function siblingClass(Assignment $sibling): ClassModel
    {
        return $sibling->classes()->first();
    }

    public function test_check_reports_a_student_already_in_the_session(): void
    {
        $s = $this->makeStudent('2150' . random_int(1000, 9999));
        $this->enroll($this->classA, $s);
        $d = $this->makeStudent();
        $this->addDirect($this->session, $d);

        $this->check(\App\Models\StudentIdentity::find($s->id)->student_code)->assertOk()
            ->assertJsonPath('status', 'already_in_session')
            ->assertJsonPath('canAdd', false)
            ->assertJsonPath('studentId', $s->id)
            ->assertJsonPath('name', $s->name)
            ->assertJsonPath('email', $s->email)
            ->assertJsonPath('message', 'Sinh viên đã có trong danh sách ca thi này.');

        $this->check($d->email)->assertOk()->assertJsonPath('status', 'already_in_session');
    }

    public function test_check_reports_an_eligible_student(): void
    {
        $s = $this->makeStudent();

        $this->check($s->email)->assertOk()
            ->assertJsonPath('status', 'eligible')
            ->assertJsonPath('canAdd', true)
            ->assertJsonPath('studentId', $s->id)
            ->assertJsonPath('studentCode', strtolower(explode('@', $s->email)[0]))
            ->assertJsonPath('message', 'Sinh viên chưa thuộc ca nào trong kỳ thi này. Có thể thêm vào ca thi.');
    }

    public function test_check_distinguishes_students_of_other_sessions_who_have_not_started_from_those_who_have(): void
    {
        $sibling = $this->sibling();
        $problem = $this->problem();
        $viaDirect = $this->makeStudent();
        $this->addDirect($sibling, $viaDirect);

        $this->check($viaDirect->email)->assertOk()
            ->assertJsonPath('status', 'in_other_session')
            ->assertJsonPath('canAdd', true)
            ->assertJsonPath('otherSessionId', $sibling->id)
            ->assertJsonPath('otherSession', 'Ca anh em')
            ->assertJsonPath('message', 'Sinh viên đang thuộc Ca anh em nhưng chưa tham gia thi. Có thể chuyển sang ca này.');

        $claimed = $this->makeStudent();
        $this->addDirect($sibling, $claimed);
        $this->makeAdmission($claimed, $sibling, ['status' => 'claimed']);
        $attempted = $this->makeStudent();
        $this->addDirect($sibling, $attempted);
        $this->attempt($sibling, $attempted);
        $submitted = $this->makeStudent();
        $this->addDirect($sibling, $submitted);
        $this->submissionFor($submitted, $sibling->id, 'ExamSession', $problem);
        $issuedOnly = $this->makeStudent();
        $this->addDirect($sibling, $issuedOnly);
        $this->makeAdmission($issuedOnly, $sibling);

        foreach ([$claimed, $attempted, $submitted] as $s) {
            $this->check($s->email)->assertOk()
                ->assertJsonPath('status', 'already_participated')
                ->assertJsonPath('canAdd', false)
                ->assertJsonPath('message', 'Sinh viên đã tham gia ở Ca anh em, không thể chuyển sang ca này.');
        }
        // Mới được cấp tài khoản (chưa đăng nhập) thì vẫn chuyển được.
        $this->check($issuedOnly->email)->assertOk()->assertJsonPath('status', 'in_other_session');
    }

    public function test_check_refuses_a_student_who_belongs_to_another_session_through_its_class(): void
    {
        $sibling = $this->sibling();
        $siblingClass = $this->siblingClass($sibling);
        $s = $this->makeStudent();
        $this->enroll($siblingClass, $s);
        // Có cả trong danh sách thêm riêng của ca kia cũng không đổi được: lớp vẫn giữ họ ở ca đó.
        $both = $this->makeStudent();
        $this->enroll($siblingClass, $both);
        $this->addDirect($sibling, $both);

        foreach ([$s, $both] as $student) {
            $this->check($student->email)->assertOk()
                ->assertJsonPath('status', 'in_other_session_class')
                ->assertJsonPath('canAdd', false)
                ->assertJsonPath('otherSessionId', $sibling->id)
                ->assertJsonPath('otherSession', 'Ca anh em')
                ->assertJsonPath('message', 'Sinh viên thuộc lớp PX.S1 của Ca anh em, không thể chuyển riêng lẻ. Hãy chuyển cả lớp trong kế hoạch ca thi.');
        }

        // Đã tham gia thi ở ca kia thì vẫn báo "đã tham gia", không phải lỗi lớp.
        $this->attempt($sibling, $s);
        $this->check($s->email)->assertOk()->assertJsonPath('status', 'already_participated');
    }

    public function test_check_ignores_sessions_of_other_examinations(): void
    {
        $foreign = $this->makeExamSession(['title' => 'Kỳ khác'], $this->makeExamination());
        $s = $this->makeStudent();
        $this->addDirect($foreign, $s);

        $this->check($s->email)->assertOk()->assertJsonPath('status', 'eligible');
    }

    public function test_student_lookup_accepts_code_email_email_prefix_and_id(): void
    {
        $s = $this->makeStudent('2160' . random_int(1000, 9999));
        $code = \App\Models\StudentIdentity::find($s->id)->student_code;
        $prefix = explode('@', $s->email)[0];

        foreach ([$code, '  ' . strtoupper($code) . '  ', $s->email, strtoupper($s->email), $prefix, $s->id] as $input) {
            $this->check($input)->assertOk()->assertJsonPath('studentId', $s->id);
        }
        $this->check($code, null, 'studentCode')->assertOk()->assertJsonPath('studentId', $s->id);
    }

    public function test_student_lookup_rejects_blank_unknown_inactive_and_staff_accounts(): void
    {
        $inactive = $this->makeStudent('2161' . random_int(1000, 9999), 'Inactive');
        $staff = $this->makeAccount('instructor');

        $this->postJson("/api/proctor/sessions/{$this->session->id}/students/check", [])
            ->assertStatus(400)->assertJsonPath('detail', 'Vui lòng nhập MSSV hoặc email sinh viên.');
        $this->check('   ')->assertStatus(400);
        $this->check('khong-ton-tai-xyz')->assertStatus(404)->assertJsonPath('detail', 'Không tìm thấy sinh viên trong hệ thống.');
        $this->check(\App\Models\StudentIdentity::find($inactive->id)->student_code)->assertStatus(404);
        $this->check($inactive->email)->assertStatus(404);
        $this->check($staff->email)->assertStatus(404);
    }

    public function test_check_honours_the_room_scope_of_a_class_proctor(): void
    {
        $proctor = $this->makeAccount('instructor', ['exam.proctor']);
        ExamClassProctor::create(['session_id' => $this->session->id, 'class_id' => $this->classA->id, 'proctor_id' => $proctor->id, 'assigned_at' => now()]);
        $s = $this->makeStudent();
        Sanctum::actingAs($proctor);

        $this->check($s->email, $this->classA->id)->assertOk();
        $this->check($s->email, $this->classB->id)->assertStatus(403);
        $this->check($s->email)->assertOk();
    }

    public function test_add_puts_an_eligible_student_straight_into_the_session(): void
    {
        $s = $this->makeStudent();

        $this->add($s->email)->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('student.status', 'eligible')
            ->assertJsonPath('student.studentId', $s->id)
            ->assertJsonPath('credentials', null);

        $this->assertTrue(DB::table('assignment_students')->where('assignment_id', $this->session->id)->where('student_id', $s->id)->exists());
        $log = ActivityLog::where('action', "proctor:add_student:{$this->session->id}:{$s->id}:")->sole();
        $this->assertSame($this->manager->id, $log->user_id);

        // Thêm lần nữa: đã có trong ca => 400.
        $this->add($s->email)->assertStatus(400)->assertJsonPath('detail', 'Sinh viên đã có trong danh sách ca thi này.');
        $this->assertSame(1, DB::table('assignment_students')->where('assignment_id', $this->session->id)->where('student_id', $s->id)->count());
    }

    public function test_add_refuses_a_student_who_already_took_part_elsewhere(): void
    {
        $sibling = $this->sibling();
        $s = $this->makeStudent();
        $this->addDirect($sibling, $s);
        $this->attempt($sibling, $s);

        $this->add($s->email)->assertStatus(400)
            ->assertJsonPath('detail', 'Sinh viên đã tham gia ở Ca anh em, không thể chuyển sang ca này.');

        $this->assertFalse(DB::table('assignment_students')->where('assignment_id', $this->session->id)->where('student_id', $s->id)->exists());
        $this->assertTrue(DB::table('assignment_students')->where('assignment_id', $sibling->id)->where('student_id', $s->id)->exists());
        $this->assertSame(0, ActivityLog::where('action', 'like', "proctor:%:{$this->session->id}:{$s->id}%")->count());
    }

    public function test_add_unknown_student_is_404_and_changes_nothing(): void
    {
        $this->add('khong-ton-tai')->assertStatus(404);
        $this->add('')->assertStatus(400);
    }

    public function test_add_transfers_a_student_from_another_session_and_revokes_the_old_credentials(): void
    {
        $sibling = $this->sibling();
        $s = $this->makeStudent();
        $this->addDirect($sibling, $s);
        $old = $this->makeAdmission($s, $sibling, ['generation' => 2]);

        $this->add($s->email)->assertOk()
            ->assertJsonPath('student.status', 'in_other_session')
            ->assertJsonPath('student.otherSessionId', $sibling->id);

        $this->assertFalse(DB::table('assignment_students')->where('assignment_id', $sibling->id)->where('student_id', $s->id)->exists());
        $this->assertTrue(DB::table('assignment_students')->where('assignment_id', $this->session->id)->where('student_id', $s->id)->exists());
        $old->refresh();
        $this->assertSame('revoked', $old->status);
        $this->assertNotNull($old->revoked_at);
        $this->assertSame("transfer_to:{$this->session->id}", $old->reason);
        $this->assertSame(3, (int) $old->generation);
        ActivityLog::where('action', "proctor:transfer_student:{$this->session->id}:{$s->id}:Ca anh em")->sole();
    }

    public function test_add_refuses_to_transfer_a_student_enrolled_in_the_other_sessions_class(): void
    {
        $sibling = $this->sibling();
        $s = $this->makeStudent();
        $this->enroll($this->siblingClass($sibling), $s);

        $this->add($s->email)->assertStatus(400)->assertJsonPath('detail', 'Sinh viên thuộc lớp PX.S1 của Ca anh em, không thể chuyển riêng lẻ. Hãy chuyển cả lớp trong kế hoạch ca thi.');

        // Không để sinh viên nằm ở hai ca.
        $this->assertFalse(DB::table('assignment_students')->where('assignment_id', $this->session->id)->where('student_id', $s->id)->exists());
        $this->assertTrue(DB::table('class_enrollments')->where('class_id', $this->siblingClass($sibling)->id)->where('student_id', $s->id)->exists());
        $this->assertSame(0, ActivityLog::where('action', 'like', "proctor:%:{$this->session->id}:{$s->id}%")->count());
    }

    public function test_add_returns_credentials_when_the_session_requires_admission(): void
    {
        $this->session->update(['admission_required' => true, 'room' => 'Phòng 101']);
        $s = $this->makeStudent('2170' . random_int(1000, 9999));

        $res = $this->add($s->email)->assertOk();

        $credentials = $res->json('credentials');
        $this->assertSame('Ca đích', $credentials['sessionTitle']);
        $this->assertSame(1, $credentials['sessionIndex']);
        $this->assertSame('', $credentials['classId']);
        $this->assertSame('Phòng 101', $credentials['room']);
        $this->assertSame($s->name, $credentials['student']);
        $this->assertSame($s->email, $credentials['email']);
        $this->assertSame(\App\Models\StudentIdentity::find($s->id)->student_code, $credentials['studentCode']);
        $this->assertNotEmpty($credentials['username']);
        $this->assertNotEmpty($credentials['password']);
        $this->assertSame($credentials['username'], ExamAdmission::where('session_id', $this->session->id)->where('student_id', $s->id)->value('username'));
    }

    public function test_add_requires_access_to_the_session(): void
    {
        $proctor = $this->makeAccount('instructor', ['exam.proctor']);
        $s = $this->makeStudent();
        Sanctum::actingAs($proctor);

        $this->add($s->email)->assertStatus(403);

        $this->assertFalse(DB::table('assignment_students')->where('assignment_id', $this->session->id)->where('student_id', $s->id)->exists());
    }

    // ══ POST /api/proctor/credentials/{admissionId}/approve ══════════════════

    private function approve(string $admissionId, array $body)
    {
        return $this->postJson("/api/proctor/credentials/{$admissionId}/approve", $body);
    }

    private function duplicateLoginFixture(): array
    {
        $s = $this->makeStudent();
        $admission = $this->makeAdmission($s, $this->session, ['status' => 'claimed', 'claimed_at' => now()->subHour()]);
        $active = $this->browser($admission, 'active');
        $pending = $this->browser($admission, 'pending', ['last_seen_at' => now()->subDay()]);
        $revoked = $this->browser($admission, 'revoked');
        ExamAccessState::create(['session_id' => $this->session->id, 'student_id' => $s->id, 'locked' => true, 'lock_reason' => 'duplicate_login', 'incident_at' => now()]);

        return [$s, $admission, $active, $pending, $revoked];
    }

    public function test_approve_activates_the_chosen_browser_and_revokes_the_others(): void
    {
        [$s, $admission, $active, $pending, $revoked] = $this->duplicateLoginFixture();

        $this->approve($admission->id, ['browser_id' => $pending->id, 'reason' => '  Máy cũ bị treo  '])
            ->assertOk()->assertExactJson(['ok' => true]);

        $this->assertSame('revoked', $active->fresh()->status);
        $this->assertSame('revoked', $revoked->fresh()->status);
        $chosen = $pending->fresh();
        $this->assertSame('active', $chosen->status);
        $this->assertSame($this->manager->id, $chosen->approved_by);
        $this->assertSame('Máy cũ bị treo', $chosen->approval_reason);
        $this->assertTrue($chosen->last_seen_at->greaterThan(now()->subMinute()));

        $state = ExamAccessState::where('session_id', $this->session->id)->where('student_id', $s->id)->first();
        $this->assertFalse((bool) $state->locked);
        $this->assertNull($state->lock_reason);
        $this->assertNotNull($state->unlocked_at);
        $this->assertSame($this->manager->id, $state->unlocked_by);

        $log = ActivityLog::where('action', "proctor:approve_browser:{$this->session->id}:{$s->id}:{$pending->browser_code}")->sole();
        $this->assertSame($this->manager->id, $log->user_id);
    }

    public function test_approve_accepts_camel_case_browser_id_and_marks_the_admission_claimed(): void
    {
        $s = $this->makeStudent();
        $admission = $this->makeAdmission($s, $this->session);
        $pending = $this->browser($admission, 'pending');

        $this->approve($admission->id, ['browserId' => $pending->id, 'reason' => 'ok'])->assertOk();

        $admission->refresh();
        $this->assertSame('claimed', $admission->status);
        $this->assertNotNull($admission->claimed_at);
    }

    public function test_approve_keeps_the_original_claim_time(): void
    {
        [$s, $admission, , $pending] = $this->duplicateLoginFixture();
        $claimedAt = $admission->claimed_at->toISOString();

        $this->approve($admission->id, ['browser_id' => $pending->id, 'reason' => 'ok'])->assertOk();

        $this->assertSame($claimedAt, $admission->fresh()->claimed_at->toISOString());
    }

    public function test_approve_validates_reason_admission_and_browser(): void
    {
        [$s, $admission, $active, $pending] = $this->duplicateLoginFixture();
        $otherAdmission = $this->makeAdmission($this->makeStudent(), $this->makeExamSession([], $this->exam));
        $foreignBrowser = $this->browser($otherAdmission, 'pending');

        $this->approve($admission->id, ['browser_id' => $pending->id])->assertStatus(400)
            ->assertJsonPath('detail', 'Cần nhập lý do duyệt đăng nhập lại.');
        $this->approve($admission->id, ['browser_id' => $pending->id, 'reason' => '   '])->assertStatus(400);
        $this->approve('khong-ton-tai', ['browser_id' => $pending->id, 'reason' => 'x'])->assertStatus(404);
        $this->approve($admission->id, ['reason' => 'x'])->assertStatus(404);
        $this->approve($admission->id, ['browser_id' => 'khong-ton-tai', 'reason' => 'x'])->assertStatus(404);
        // Trình duyệt của tài khoản khác không duyệt được qua tài khoản này.
        $this->approve($admission->id, ['browser_id' => $foreignBrowser->id, 'reason' => 'x'])->assertStatus(404);

        // Không có thay đổi nào.
        $this->assertSame('active', $active->fresh()->status);
        $this->assertSame('pending', $pending->fresh()->status);
        $this->assertTrue((bool) ExamAccessState::where('session_id', $this->session->id)->where('student_id', $s->id)->value('locked'));
    }

    public function test_approve_checks_the_reason_before_access_and_requires_access_to_the_admissions_session(): void
    {
        [$s, $admission, , $pending] = $this->duplicateLoginFixture();
        $proctor = $this->makeAccount('instructor', ['exam.proctor']);
        Sanctum::actingAs($proctor);

        $this->approve($admission->id, ['browser_id' => $pending->id])->assertStatus(400);
        $this->approve($admission->id, ['browser_id' => $pending->id, 'reason' => 'x'])->assertStatus(403);
        $this->assertSame('pending', $pending->fresh()->status);

        ExamClassProctor::create(['session_id' => $this->session->id, 'class_id' => $this->classA->id, 'proctor_id' => $proctor->id, 'assigned_at' => now()]);
        $this->approve($admission->id, ['browser_id' => $pending->id, 'reason' => 'x'])->assertOk();
    }

    public function test_duplicate_login_is_resolved_end_to_end_by_the_proctor(): void
    {
        $student = $this->makeStudent();
        $this->session->update(['admission_required' => true]);
        $admission = $this->makeAdmission($student, $this->session);
        $login = fn () => $this->postJson('/api/exam-auth/login', ['username' => $admission->username, 'password' => 'Tmp-Pass-9z']);
        $status = function (string $token) {
            $this->app['auth']->forgetGuards();

            return $this->getJson('/api/exam-auth/status', ['Authorization' => "Bearer {$token}"]);
        };
        $first = $login()->assertOk()->json('access_token');
        $second = $login()->assertOk()->assertJsonPath('locked', true)->json('access_token');
        $pending = ExamBrowserSession::where('admission_id', $admission->id)->where('status', 'pending')->sole();

        Sanctum::actingAs($this->manager);
        $this->approve($admission->id, ['browser_id' => $pending->id, 'reason' => 'Máy mới'])->assertOk();

        $status($second)->assertOk()->assertJsonPath('locked', false)->assertJsonPath('browserCode', $pending->browser_code);
        $status($first)->assertOk()->assertJsonPath('locked', true);
    }
}
