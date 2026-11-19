<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Assignment;
use App\Models\ClassModel;
use App\Models\ExamClassProctor;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ProctorFixtures;
use Tests\Concerns\UsesDefaultRolePermissions;
use Tests\TestCase;

/**
 * Đặc tả quyền coi thi (capability exam.proctor / examination.manage + phạm vi theo ca và phòng)
 * và hai endpoint đọc: GET /api/proctor/sessions, GET /api/proctor/sessions/{id}/notices.
 *
 * Quyền vào một ca (requireProctorAccess) dùng chung cho mọi endpoint /api/proctor/sessions/{id}/*, nên được kiểm
 * qua GET /api/proctor/sessions/{id} (roomStatus).
 */
class ProctorAccessTest extends TestCase
{
    use DatabaseTransactions;
    use ProctorFixtures;
    use UsesDefaultRolePermissions;

    private const NOT_ASSIGNED = 'Bạn không được phân công coi thi ca này.';

    protected User $proctor;
    protected User $manager;
    protected User $owner;
    protected Assignment $session;
    protected ClassModel $classA;
    protected ClassModel $classB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useDefaultRolePermissions();

        $this->proctor = $this->makeAccount('instructor', ['exam.proctor']);
        $this->manager = $this->makeAccount('faculty_office', ['examination.manage']);
        $this->owner = $this->makeAccount('instructor', ['exam.proctor']);

        $this->classA = $this->makeClass(['practice_class' => 'PX.A1', 'course' => 'Course A']);
        $this->classB = $this->makeClass(['practice_class' => 'PX.B1', 'course' => 'Course B']);
        $this->session = $this->makeExamSession(['instructor_id' => $this->owner->id, 'title' => 'Ca truy cập']);
        $this->linkClass($this->session, $this->classA);
        $this->linkClass($this->session, $this->classB);
    }

    private function room(User $user, ?string $roomId = null, ?Assignment $session = null)
    {
        Sanctum::actingAs($user);
        $query = $roomId ? "?room_id={$roomId}" : '';

        return $this->getJson('/api/proctor/sessions/' . ($session ?? $this->session)->id . $query);
    }

    // ══ capability ═══════════════════════════════════════════════════════════

    public function test_accounts_without_a_proctor_capability_cannot_use_any_proctor_endpoint(): void
    {
        $student = $this->makeStudent();
        $grader = $this->makeAccount('instructor', ['results.grade', 'assignment.create']);
        $id = $this->session->id;

        foreach ([$student, $grader] as $user) {
            Sanctum::actingAs($user);
            $this->getJson('/api/proctor/sessions')->assertStatus(403);
            $this->getJson("/api/proctor/sessions/{$id}")->assertStatus(403);
            $this->getJson("/api/proctor/sessions/{$id}/notices")->assertStatus(403);
            $this->postJson("/api/proctor/sessions/{$id}/students/x/unlock")->assertStatus(403);
            $this->postJson("/api/proctor/sessions/{$id}/students/check", ['student_code' => 'x'])->assertStatus(403);
            $this->postJson("/api/proctor/sessions/{$id}/students/add", ['student_code' => 'x'])->assertStatus(403);
            $this->postJson('/api/proctor/credentials/x/approve', ['reason' => 'x'])->assertStatus(403);
        }
    }

    public function test_proctor_endpoints_require_authentication(): void
    {
        $this->getJson('/api/proctor/sessions')->assertStatus(401);
        $this->getJson("/api/proctor/sessions/{$this->session->id}")->assertStatus(401);
    }

    // ══ phạm vi của một ca ═══════════════════════════════════════════════════

    public function test_unrelated_proctor_is_not_assigned_to_the_session(): void
    {
        $this->room($this->proctor)->assertStatus(403)->assertJsonPath('detail', self::NOT_ASSIGNED);
    }

    public function test_session_owner_and_examination_managers_may_enter(): void
    {
        $this->room($this->owner)->assertOk();
        $this->room($this->manager)->assertOk();
        // Quản lý kỳ thi vào được cả khi lọc theo một phòng.
        $this->room($this->manager, $this->classB->id)->assertOk();
    }

    public function test_proctor_named_on_the_session_by_name_or_email_may_enter_case_insensitively(): void
    {
        $this->session->update(['proctors' => [mb_strtoupper($this->proctor->name)]]);
        $this->room($this->proctor)->assertOk();

        $this->session->update(['proctors' => [mb_strtoupper($this->proctor->email)]]);
        $this->room($this->proctor)->assertOk();

        $this->session->update(['proctors' => ['Người khác']]);
        $this->room($this->proctor)->assertStatus(403);
    }

    public function test_class_proctor_assignment_grants_access_to_that_room_only_when_a_room_is_requested(): void
    {
        ExamClassProctor::create(['session_id' => $this->session->id, 'class_id' => $this->classA->id, 'proctor_id' => $this->proctor->id, 'assigned_at' => now()]);

        $this->room($this->proctor)->assertOk();
        $this->room($this->proctor, $this->classA->id)->assertOk();
        $this->room($this->proctor, $this->classB->id)->assertStatus(403)->assertJsonPath('detail', self::NOT_ASSIGNED);
    }

    public function test_class_proctor_of_another_session_gets_nothing(): void
    {
        $other = $this->makeExamSession();
        $this->linkClass($other, $this->classA);
        ExamClassProctor::create(['session_id' => $other->id, 'class_id' => $this->classA->id, 'proctor_id' => $this->proctor->id, 'assigned_at' => now()]);

        $this->room($this->proctor)->assertStatus(403);
    }

    public function test_teacher_of_a_session_class_may_enter_through_any_instructor_column(): void
    {
        $viaInstructor = $this->makeAccount('instructor', ['exam.proctor']);
        $viaPractice = $this->makeAccount('instructor', ['exam.proctor']);
        $viaPivot = $this->makeAccount('instructor', ['exam.proctor']);
        $this->classA->update(['instructor_id' => $viaInstructor->id, 'practice_instructor_id' => $viaPractice->id]);
        DB::table('class_instructors')->insert(['class_id' => $this->classA->id, 'instructor_id' => $viaPivot->id]);

        foreach ([$viaInstructor, $viaPractice, $viaPivot] as $teacher) {
            $this->room($teacher)->assertOk();
            $this->room($teacher, $this->classA->id)->assertOk();
            // Không dạy phòng B thì không vào được khi lọc phòng B.
            $this->room($teacher, $this->classB->id)->assertStatus(403);
        }
    }

    public function test_teacher_of_a_class_outside_the_session_is_rejected(): void
    {
        $outsideClass = $this->makeClass(['instructor_id' => $this->proctor->id]);

        $this->room($this->proctor)->assertStatus(403);
        // Phòng không thuộc ca: giao với danh sách lớp của ca rỗng => từ chối.
        $this->room($this->proctor, $outsideClass->id)->assertStatus(403);
    }

    public function test_unknown_and_non_exam_sessions_are_404(): void
    {
        $this->room($this->manager, null, new Assignment(['id' => 'khong-ton-tai']))->assertStatus(404);

        $plain = Assignment::create(['title' => 'Bài tập thường', 'published' => true]);
        $this->room($this->manager, null, $plain)->assertStatus(404);
    }

    // ══ GET /api/proctor/sessions ════════════════════════════════════════════

    private function listFor(User $user, ?array $onlyIds = null): array
    {
        Sanctum::actingAs($user);
        $items = $this->getJson('/api/proctor/sessions')->assertOk()->json();

        return $onlyIds === null ? $items : array_values(array_filter($items, fn ($i) => in_array($i['sessionId'], $onlyIds, true)));
    }

    public function test_manager_sees_one_item_per_class_of_each_published_exam_session(): void
    {
        $items = $this->listFor($this->manager, [$this->session->id]);

        $this->assertCount(2, $items);
        $byRoom = collect($items)->keyBy('roomId');
        $this->assertSame('PX.A1', $byRoom[$this->classA->id]['room']);
        $this->assertSame('PX.B1', $byRoom[$this->classB->id]['room']);
        $this->assertSame('Ca truy cập', $items[0]['title']);
        $this->assertSame($this->session->opens->toISOString(), $items[0]['opens']);
        $this->assertSame($this->session->closes->toISOString(), $items[0]['closes']);
    }

    public function test_room_label_falls_back_from_practice_class_to_course_to_id(): void
    {
        $noPractice = $this->makeClass(['practice_class' => null, 'course' => 'Chỉ có môn']);
        $bare = $this->makeClass(['practice_class' => null, 'course' => '']);
        $session = $this->makeExamSession();
        $this->linkClass($session, $noPractice);
        $this->linkClass($session, $bare);

        $byRoom = collect($this->listFor($this->manager, [$session->id]))->keyBy('roomId');

        $this->assertSame('Chỉ có môn', $byRoom[$noPractice->id]['room']);
        $this->assertSame($bare->id, $byRoom[$bare->id]['room']);
    }

    public function test_unpublished_and_non_exam_sessions_are_not_listed(): void
    {
        $unpublished = $this->makeExamSession(['published' => false]);
        $plain = Assignment::create(['title' => 'Bài tập thường', 'published' => true]);

        $this->assertSame([], $this->listFor($this->manager, [$unpublished->id, $plain->id]));
    }

    public function test_session_without_classes_shows_a_placeholder_room_only_to_owner_manager_or_named_proctor(): void
    {
        $session = $this->makeExamSession(['instructor_id' => $this->owner->id, 'room' => 'Phòng máy 3']);
        $bare = $this->makeExamSession(['instructor_id' => $this->owner->id]);

        $forManager = collect($this->listFor($this->manager, [$session->id, $bare->id]))->keyBy('sessionId');
        $this->assertNull($forManager[$session->id]['roomId']);
        $this->assertSame('Phòng máy 3', $forManager[$session->id]['room']);
        $this->assertSame('Chưa gán lớp', $forManager[$bare->id]['room']);

        $this->assertCount(2, $this->listFor($this->owner, [$session->id, $bare->id]));
        $this->assertSame([], $this->listFor($this->proctor, [$session->id, $bare->id]));

        $session->update(['proctors' => [$this->proctor->email]]);
        $this->assertCount(1, $this->listFor($this->proctor, [$session->id, $bare->id]));
    }

    public function test_proctor_sees_only_the_rooms_they_are_allowed_to_supervise(): void
    {
        $this->assertSame([], $this->listFor($this->proctor, [$this->session->id]));

        ExamClassProctor::create(['session_id' => $this->session->id, 'class_id' => $this->classA->id, 'proctor_id' => $this->proctor->id, 'assigned_at' => now()]);
        $this->assertSame([$this->classA->id], array_column($this->listFor($this->proctor, [$this->session->id]), 'roomId'));

        $this->classB->update(['practice_instructor_id' => $this->proctor->id]);
        $rooms = array_column($this->listFor($this->proctor, [$this->session->id]), 'roomId');
        $this->assertEqualsCanonicalizing([$this->classA->id, $this->classB->id], $rooms);
    }

    public function test_session_owner_sees_every_room_of_their_session(): void
    {
        $rooms = array_column($this->listFor($this->owner, [$this->session->id]), 'roomId');

        $this->assertEqualsCanonicalizing([$this->classA->id, $this->classB->id], $rooms);
    }

    public function test_sessions_are_ordered_by_opening_time_then_session_index(): void
    {
        $base = now()->addYears(5)->startOfHour();
        $byOpen = [];
        foreach ([30, 10, 50, 20, 40] as $hours) {
            $byOpen[$hours] = $this->makeExamSession(['title' => "mở {$hours}", 'opens' => $base->copy()->addHours($hours), 'closes' => $base->copy()->addHours($hours + 2)]);
        }
        $titles = array_column($this->listFor($this->manager, array_map(fn ($s) => $s->id, $byOpen)), 'title');
        $this->assertSame(['mở 10', 'mở 20', 'mở 30', 'mở 40', 'mở 50'], $titles);

        $sameOpen = [];
        foreach ([4, 2, 5, 1, 3] as $index) {
            $sameOpen[] = $this->makeExamSession(['title' => "ca {$index}", 'session_index' => $index, 'opens' => $base->copy()->addDays(2)]);
        }
        $titles = array_column($this->listFor($this->manager, array_map(fn ($s) => $s->id, $sameOpen)), 'title');
        $this->assertSame(['ca 1', 'ca 2', 'ca 3', 'ca 4', 'ca 5'], $titles);
    }

    // ══ GET /api/proctor/sessions/{id}/notices ═══════════════════════════════

    private function log(string $action, ?User $actor = null, $at = null): ActivityLog
    {
        return ActivityLog::create([
            'id'         => (string) \Illuminate\Support\Str::uuid(),
            'user_id'    => $actor?->id,
            'action'     => $action,
            'created_at' => $at ?? now(),
        ]);
    }

    private function notices(User $user, ?Assignment $session = null)
    {
        Sanctum::actingAs($user);

        return $this->getJson('/api/proctor/sessions/' . ($session ?? $this->session)->id . '/notices');
    }

    public function test_notices_require_access_to_the_session(): void
    {
        $this->notices($this->proctor)->assertStatus(403)->assertJsonPath('detail', self::NOT_ASSIGNED);
        $this->notices($this->manager)->assertOk();
    }

    public function test_notices_are_parsed_labelled_and_enriched(): void
    {
        $student = $this->makeStudent('2199' . random_int(1000, 9999));
        $noIdentity = $this->makeStudent();
        $sid = $this->session->id;
        $this->log("proctor:unlock:{$sid}:{$student->id}", $this->owner, now()->subMinutes(4));
        $this->log("proctor:add_student:{$sid}:{$student->id}:", $this->owner, now()->subMinutes(3));
        $this->log("proctor:transfer_student:{$sid}:{$noIdentity->id}:Ca 1: chuyển sang", $this->manager, now()->subMinutes(2));
        $this->log("proctor:approve_browser:{$sid}:{$student->id}:ABC123", null, now()->subMinute());
        $this->log("proctor:something_new:{$sid}:ghost-student:x", $this->owner, now());

        $rows = $this->notices($this->manager)->assertOk()->json();

        $this->assertSame(['something_new', 'approve_browser', 'transfer_student', 'add_student', 'unlock'], array_column($rows, 'kind'));
        $byKind = collect($rows)->keyBy('kind');

        $this->assertSame('Mở khóa bài thi', $byKind['unlock']['label']);
        $this->assertSame('Thêm sinh viên vào ca', $byKind['add_student']['label']);
        $this->assertSame('Chuyển ca thi', $byKind['transfer_student']['label']);
        $this->assertSame('Duyệt trình duyệt mới', $byKind['approve_browser']['label']);
        $this->assertSame('something_new', $byKind['something_new']['label']);

        $this->assertSame($student->id, $byKind['unlock']['studentId']);
        $this->assertSame($student->name, $byKind['unlock']['student']);
        $this->assertSame($student->name, $byKind['unlock']['studentName']);
        $this->assertSame($this->owner->name, $byKind['unlock']['actor']);
        $this->assertSame($this->owner->name, $byKind['unlock']['actorName']);
        $this->assertSame($student->identity?->student_code ?? \App\Models\StudentIdentity::find($student->id)->student_code, $byKind['unlock']['studentCode']);
        $this->assertSame('', $byKind['unlock']['detail']);
        $this->assertSame('', $byKind['add_student']['detail']);
        // Chi tiết giữ nguyên cả dấu hai chấm.
        $this->assertSame('Ca 1: chuyển sang', $byKind['transfer_student']['detail']);
        // Không có mã sinh viên thì suy ra từ email.
        $this->assertSame(strtolower(explode('@', $noIdentity->email)[0]), $byKind['transfer_student']['studentCode']);
        // Người thao tác không xác định => "Giám thị"; sinh viên không tồn tại => hiện mã id, mã sinh viên rỗng.
        $this->assertSame('Giám thị', $byKind['approve_browser']['actor']);
        $this->assertSame('ABC123', $byKind['approve_browser']['detail']);
        $this->assertSame('ghost-student', $byKind['something_new']['student']);
        $this->assertSame('', $byKind['something_new']['studentCode']);
        $this->assertNotEmpty($byKind['unlock']['id']);
        $this->assertNotNull($byKind['unlock']['createdAt']);
    }

    public function test_notices_only_include_this_session_newest_first_and_capped_at_forty(): void
    {
        $other = $this->makeExamSession();
        $sid = $this->session->id;
        $this->log("proctor:unlock:{$other->id}:x", $this->owner);
        $this->log("unlock:{$sid}:x:y", $this->owner);
        for ($i = 1; $i <= 45; $i++) {
            $this->log("proctor:unlock:{$sid}:s{$i}", $this->owner, now()->subHours(100)->addMinutes($i));
        }

        $rows = $this->notices($this->manager)->assertOk()->json();

        $this->assertCount(40, $rows);
        $this->assertSame('s45', $rows[0]['studentId']);
        $this->assertSame('s6', $rows[39]['studentId']);
        $this->assertNotContains('x', array_column($rows, 'studentId'));
    }

    public function test_notices_for_another_session_are_hidden_from_a_class_proctor(): void
    {
        ExamClassProctor::create(['session_id' => $this->session->id, 'class_id' => $this->classA->id, 'proctor_id' => $this->proctor->id, 'assigned_at' => now()]);
        $other = $this->makeExamSession();
        $this->log("proctor:unlock:{$other->id}:zzz", $this->owner);
        $this->log("proctor:unlock:{$this->session->id}:aaa", $this->owner);

        $rows = $this->notices($this->proctor)->assertOk()->json();

        $this->assertSame(['aaa'], array_column($rows, 'studentId'));
        $this->notices($this->proctor, $other)->assertStatus(403);
    }
}
