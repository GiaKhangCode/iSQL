<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\ClassModel;
use App\Models\Examination;
use App\Models\Problem;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Cuộc thi độc lập (contest) đã bị loại bỏ; kỳ thi (examination) được giữ lại.
 *
 * Ca thi vẫn lưu trong bảng assignments với cờ is_contest = true (tạm giữ cho tới khi
 * đổi tên), nên các test này vừa khóa "không còn tạo/xem được cuộc thi độc lập" vừa khóa
 * "ca thi không bị ảnh hưởng".
 */
class ContestRemovalTest extends TestCase
{
    use DatabaseTransactions;

    private User $instructor;
    private User $student;
    private ClassModel $class;
    private Problem $problem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->instructor = $this->makeUser('instructor');
        $this->student = $this->makeUser('student');
        $this->class = ClassModel::create([
            'id'            => 'CR_' . strtoupper(uniqid()),
            'course'        => 'CR Course',
            'term'          => 'CR Term',
            'status'        => 'Active',
            'instructor_id' => $this->instructor->id,
        ]);
        $this->class->students()->attach($this->student->id);
        $this->problem = Problem::create([
            'number'          => 'CR-' . rand(1000, 9999),
            'title'           => 'CR problem',
            'description'     => 'x',
            'requirements'    => 'SELECT 1;',
            'difficulty'      => 'Easy',
            'creator_id'      => $this->instructor->id,
            'practice_listed' => true,
        ]);
        Sanctum::actingAs($this->instructor);
    }

    private function makeUser(string $role): User
    {
        return User::create([
            'name'            => 'CR ' . $role . ' ' . uniqid(),
            'email'           => 'cr_' . uniqid() . '@uit.edu.vn',
            'hashed_password' => Hash::make('password123'),
            'role'            => $role,
            'status'          => 'Active',
            'initials'        => 'CR',
        ]);
    }

    private function payload(array $extra = []): array
    {
        return array_merge([
            'title'        => 'CR bài tập ' . uniqid(),
            'instructions' => '<p>x</p>',
            'classIds'     => [$this->class->id],
            'audienceType' => 'classes',
            'opens'        => now()->subHour()->toIso8601String(),
            'closes'       => now()->addDays(3)->toIso8601String(),
            'status'       => 'published',
            'problems'     => [['id' => $this->problem->id, 'points' => 10, 'order' => 1]],
        ], $extra);
    }

    /** Tạo một ca thi tối thiểu như ExaminationService (is_contest + examination_id). */
    private function makeExamSession(array $attrs = []): Assignment
    {
        $exam = Examination::create([
            'id'       => (string) \Illuminate\Support\Str::uuid(),
            'title'    => 'CR kỳ thi',
            'owner_id' => $this->instructor->id,
            'exam_date' => now()->format('Y-m-d'),
        ]);
        $session = Assignment::create(array_merge([
            'title'              => 'CR ca thi',
            'instructor_id'      => $this->instructor->id,
            'examination_id'     => $exam->id,
            'session_index'      => 1,
            'is_contest'         => true,
            'exam_mode'          => true,
            'admission_required' => false,
            'audience_type'      => 'classes',
            'published'          => true,
            'opens'              => now()->subHour(),
            'closes'             => now()->addDay(),
        ], $attrs));
        $session->classes()->attach($this->class->id);

        return $session;
    }

    // ───────────────────────── không còn tạo cuộc thi độc lập ─────────────────────────

    public function test_creating_an_assignment_with_is_contest_flag_creates_a_plain_assignment(): void
    {
        foreach (['isContest' => true, 'is_contest' => true] as $key => $value) {
            $res = $this->postJson('/api/assignments', $this->payload([$key => $value]))->assertStatus(201);

            $this->assertFalse($res->json('isContest'), "{$key} không được tạo cuộc thi");
            $this->assertDatabaseHas('assignments', ['id' => $res->json('id'), 'is_contest' => false]);
        }
    }

    public function test_updating_an_assignment_cannot_turn_it_into_a_contest(): void
    {
        $id = $this->postJson('/api/assignments', $this->payload(['status' => 'draft']))->assertStatus(201)->json('id');

        $this->putJson("/api/assignments/{$id}", ['title' => 'CR đổi tên', 'isContest' => true])->assertOk();

        $this->assertDatabaseHas('assignments', ['id' => $id, 'title' => 'CR đổi tên', 'is_contest' => false]);
    }

    public function test_all_students_audience_is_no_longer_accepted(): void
    {
        $this->postJson('/api/assignments', $this->payload(['audienceType' => 'all_students']))
            ->assertStatus(422)->assertJsonValidationErrors('audience_type', 'errors');
        $this->getJson('/api/assignments/audience-count?audience_type=all_students')->assertStatus(422);
        $this->postJson('/api/assignments/audience-preview', ['audienceType' => 'all_students'])->assertStatus(422);
    }

    public function test_publishing_an_assignment_notifies_with_assignment_wording_and_link(): void
    {
        $this->postJson('/api/assignments', $this->payload(['title' => 'CR thông báo']))->assertStatus(201);

        $n = \App\Models\Notification::where('user_id', $this->student->id)->where('title', 'Bài tập mới: CR thông báo')->first();
        $this->assertNotNull($n);
        $this->assertSame('/assignments', $n->link);
    }

    // ───────────────────────── dữ liệu cũ kiểu cuộc thi toàn trường không còn hiệu lực ─────────────────────────

    public function test_a_legacy_all_students_contest_row_is_invisible_and_inaccessible_to_students(): void
    {
        $outsider = $this->makeUser('student'); // không thuộc lớp nào
        $legacy = Assignment::create([
            'title'         => 'CR cuộc thi cũ',
            'instructor_id' => $this->instructor->id,
            'is_contest'    => true,
            'audience_type' => 'all_students',
            'published'     => true,
            'opens'         => now()->subHour(),
            'closes'        => now()->addDay(),
        ]);
        $legacy->problems()->attach($this->problem->id, ['points' => 10, 'order_index' => 1]);
        Sanctum::actingAs($outsider);

        $ids = fn (array $rows) => array_column($rows, 'id');
        $this->assertNotContains($legacy->id, $ids($this->getJson('/api/assignments')->assertOk()->json()));
        $this->assertNotContains($legacy->id, $ids($this->getJson('/api/student/assignments')->assertOk()->json('assignments') ?? []));
        $this->getJson("/api/assignments/{$legacy->id}")->assertStatus(403);
    }

    // ───────────────────────── ca thi (examination) không bị ảnh hưởng ─────────────────────────

    public function test_exam_session_still_reaches_students_of_its_class_as_an_examination(): void
    {
        $session = $this->makeExamSession();
        Sanctum::actingAs($this->student);

        $res = $this->getJson('/api/student/assignments')->assertOk();

        $row = collect($res->json('assignments'))->firstWhere('id', $session->id);
        $this->assertNotNull($row, 'ca thi của lớp phải còn hiện cho sinh viên');
        $this->assertTrue($row['isContest'], 'cờ tạm giữ cho ca thi');
        $this->assertSame($session->examination_id, $row['examinationId']);
        $this->assertArrayHasKey('contestStatus', $row);

        $deadline = collect($res->json('deadlines'))->firstWhere('id', $session->id);
        $this->assertNotNull($deadline);
        $this->assertSame('Examination', $deadline['kind']);
        $this->assertSame('/examinations/' . $session->examination_id, $deadline['to']);
    }

    public function test_regular_assignment_deadline_keeps_assignment_kind_and_link(): void
    {
        $id = $this->postJson('/api/assignments', $this->payload())->assertStatus(201)->json('id');
        Sanctum::actingAs($this->student);

        $deadline = collect($this->getJson('/api/student/assignments')->assertOk()->json('deadlines'))->firstWhere('id', $id);

        $this->assertSame('Assignment', $deadline['kind']);
        $this->assertSame("/assignments?work={$id}", $deadline['to']);
    }

    public function test_exam_session_is_not_open_to_students_outside_its_classes(): void
    {
        $session = $this->makeExamSession();
        Sanctum::actingAs($this->makeUser('student'));

        $this->getJson("/api/assignments/{$session->id}")->assertStatus(403);
    }

    // ───────────────────────── quyền contest.create không còn ─────────────────────────

    public function test_contest_create_capability_is_gone_from_every_role(): void
    {
        foreach (['admin', 'instructor', 'instructor_practical', 'department_head', 'faculty_office', 'student'] as $role) {
            Sanctum::actingAs($this->makeUser($role));
            $this->assertNotContains('contest.create', $this->getJson('/api/auth/permissions')->assertOk()->json('permissions'), $role);
        }
    }
}
