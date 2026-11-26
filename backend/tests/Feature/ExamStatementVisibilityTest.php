<?php

namespace Tests\Feature;

use App\Models\ActivityAttempt;
use App\Models\Assignment;
use App\Models\Examination;
use App\Models\Problem;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\AccountFixture;
use Tests\Concerns\UsesDefaultRolePermissions;
use Tests\TestCase;

/** Đề của ca thi (tên bài, chủ đề, mô tả) chỉ lộ cho sinh viên sau khi đã bắt đầu làm bài. */
class ExamStatementVisibilityTest extends TestCase
{
    use DatabaseTransactions;
    use UsesDefaultRolePermissions;

    private User $student;
    private Assignment $session;
    private Problem $problem;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useDefaultRolePermissions();
        $owner = $this->makeUser('instructor');
        $this->student = $this->makeUser('student');
        $this->problem = Problem::create([
            'number' => 'EV-' . strtoupper(substr(uniqid(), -8)), 'title' => 'EV secret title', 'difficulty' => 'Easy',
            'topic' => 'EV topic', 'description' => 'EV secret statement', 'requirements' => 'r',
            'practice_listed' => false, 'creator_id' => $owner->id,
        ]);
        $examination = Examination::create([
            'title' => 'EV exam ' . uniqid(), 'exam_date' => now()->toDateString(), 'instructor_id' => $owner->id,
        ]);
        $this->session = Assignment::create([
            'title' => 'EV session ' . uniqid(), 'instructor_id' => $owner->id, 'published' => true, 'exam_mode' => true,
            'examination_id' => $examination->id, 'opens' => now()->subHour(), 'closes' => now()->addHour(),
        ]);
        $this->session->problems()->attach($this->problem->id, ['points' => 10, 'order_index' => 1]);
        DB::table('assignment_students')->insert([
            'assignment_id' => $this->session->id, 'student_id' => $this->student->id,
        ]);
        Sanctum::actingAs($this->student);
    }

    private function makeUser(string $kind): User
    {
        return User::create([
            'name' => 'EV ' . uniqid(), 'email' => 'ev_' . uniqid() . '@uit.edu.vn', 'hashed_password' => Hash::make('password123'),
            ...AccountFixture::for($kind), 'status' => 'Active', 'initials' => 'EV',
        ]);
    }

    private function listedSession(): array
    {
        $body = $this->getJson('/api/student/assignments')->assertOk()->json();

        return [
            'session' => collect($body['assignments'])->firstWhere('id', $this->session->id),
            'catalog' => collect($body['problems'])->firstWhere('id', $this->problem->id),
        ];
    }

    public function test_before_starting_neither_the_statement_nor_the_title_is_sent(): void
    {
        $this->getJson("/api/problems/{$this->problem->id}?context={$this->session->id}")->assertForbidden();
        $this->getJson("/api/problems/{$this->problem->id}")->assertForbidden();

        ['session' => $session, 'catalog' => $catalog] = $this->listedSession();
        $this->assertSame([$this->problem->id], $session['problemIds']);
        $this->assertSame('', $session['problemDetails'][0]['title']);
        $this->assertSame('', $session['problemDetails'][0]['topic']);
        $this->assertSame(10.0, (float) $session['problemDetails'][0]['points']);
        $this->assertNull($catalog);
    }

    public function test_after_starting_the_statement_is_available(): void
    {
        ActivityAttempt::create([
            'assignment_id' => $this->session->id, 'user_id' => $this->student->id, 'status' => 'active',
            'drafts' => [], 'violations' => [], 'started_at' => now(),
        ]);

        $this->getJson("/api/problems/{$this->problem->id}?context={$this->session->id}")->assertOk()
            ->assertJsonPath('description', 'EV secret statement');
        $this->getJson("/api/problems/{$this->problem->id}")->assertOk();

        ['session' => $session, 'catalog' => $catalog] = $this->listedSession();
        $this->assertSame('EV secret title', $session['problemDetails'][0]['title']);
        $this->assertSame('EV secret title', $catalog['title']);
    }
}
