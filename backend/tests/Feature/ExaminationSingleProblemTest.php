<?php

namespace Tests\Feature;

use App\Models\AccessRole;
use App\Models\Assignment;
use App\Models\Problem;
use App\Models\User;
use App\Services\Access\AccessControlService;
use App\Services\ExaminationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\AccountFixture;
use Tests\Concerns\UsesDefaultRolePermissions;
use Tests\TestCase;

/**
 * Ràng buộc của bài thi: mỗi bài thi chỉ có một bài toán (nhiều câu hỏi nhưng cùng thuộc bài toán đó) và chỉ có một
 * lần nộp toàn bài, nên số lượt nộp luôn là 1 dù trình dựng gửi giá trị khác.
 */
class ExaminationSingleProblemTest extends TestCase
{
    use DatabaseTransactions;
    use UsesDefaultRolePermissions;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useDefaultRolePermissions();

        $this->manager = User::create(array_merge([
            'name'            => 'SP manager ' . uniqid(),
            'email'           => 'sp_' . uniqid() . '@gm.uit.edu.vn',
            'hashed_password' => Hash::make('password123'),
            'initials'        => 'SP',
            'status'          => 'Active',
        ], AccountFixture::for('student')));
        DB::table('user_access_roles')->where('user_id', $this->manager->id)->delete();
        $role = AccessRole::create(['name' => 'SP role ' . uniqid()]);
        $role->syncPermissions(['examination.manage']);
        $this->manager->accessRoles()->attach($role->id);
        app(AccessControlService::class)->forget();
    }

    private function makeProblem(): Problem
    {
        return Problem::create([
            'number' => 'SP-' . strtoupper(substr(uniqid(), -8)), 'title' => 'SP problem ' . uniqid(),
            'difficulty' => 'Easy', 'description' => 'd', 'requirements' => 'r', 'practice_listed' => false,
            'creator_id' => $this->manager->id,
        ]);
    }

    /** @param array<int, array<string, mixed>> $sessionProblems */
    private function payload(array $sessionProblems, array $templateProblems = [], array $studentOptions = []): array
    {
        return [
            'title'          => 'SP exam ' . uniqid(),
            'examDate'       => date('Y-m-d', strtotime('+7 days')),
            'timezone'       => 'Asia/Ho_Chi_Minh',
            'studentOptions' => $studentOptions,
            'problems'       => $templateProblems,
            'sessions'       => [['starts' => '07:30', 'ends' => '09:00', 'classIds' => [], 'problems' => $sessionProblems]],
        ];
    }

    public function test_a_session_with_one_problem_is_accepted(): void
    {
        $problem = $this->makeProblem();

        $exam = app(ExaminationService::class)->createExamination($this->payload([['id' => $problem->id, 'points' => 10]]), $this->manager);

        $this->assertSame(1, Assignment::where('examination_id', $exam['id'])->count());
    }

    public function test_a_session_with_two_problems_is_rejected(): void
    {
        $first = $this->makeProblem();
        $second = $this->makeProblem();
        $payload = $this->payload([['id' => $first->id, 'points' => 5], ['id' => $second->id, 'points' => 5]]);

        try {
            app(ExaminationService::class)->createExamination($payload, $this->manager);
            $this->fail('Ca thi có hai bài toán phải bị từ chối.');
        } catch (ValidationException $e) {
            $this->assertSame(['Mỗi bài thi chỉ được có một bài toán (nhiều câu hỏi nhưng cùng thuộc bài toán đó).'], $e->errors()['problems']);
        }
        $this->assertSame(0, Assignment::where('title', 'like', '%' . $payload['title'] . '%')->count());
    }

    public function test_a_template_with_two_problems_is_rejected(): void
    {
        $first = $this->makeProblem();
        $second = $this->makeProblem();
        $payload = $this->payload([], [['id' => $first->id], ['id' => $second->id]]);

        $this->expectException(ValidationException::class);
        app(ExaminationService::class)->createExamination($payload, $this->manager);
    }

    public function test_the_submission_limit_of_an_exam_session_is_always_one(): void
    {
        $problem = $this->makeProblem();
        $payload = $this->payload([['id' => $problem->id, 'points' => 10]], [], ['maxSubmissions' => 5]);

        $exam = app(ExaminationService::class)->createExamination($payload, $this->manager);

        $this->assertSame([1], Assignment::where('examination_id', $exam['id'])->pluck('max_submissions')->map(fn ($n) => (int) $n)->all());
    }
}
