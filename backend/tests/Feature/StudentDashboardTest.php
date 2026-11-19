<?php

namespace Tests\Feature;

use App\Models\Problem;
use App\Models\Submission;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\AccountFixture;
use Tests\TestCase;

/**
 * Test đặc tả cho GET /api/student/dashboard (Student\DashboardController::index).
 *
 * Khóa hành vi trước và sau khi tách logic sang StudentDashboardService (lộ trình 2.1).
 * Thay đổi có chủ ý so với bản đầu: hỗ trợ tz_offset (biểu đồ theo ngày địa phương) và
 * bỏ hẳn tính năng chuỗi ngày (streak). Các test có ghi chú "KHÁC SQL-UIT" khóa những chỗ
 * Laravel vẫn khác bản gốc SQL-UIT.
 */
class StudentDashboardTest extends TestCase
{
    use DatabaseTransactions;

    protected User $student;
    protected User $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->student = $this->makeUser('student', 'a');
        $this->other = $this->makeUser('student', 'b');
    }

    private function makeUser(string $role, string $tag, array $extra = []): User
    {
        return User::create(array_merge([
            'name'            => "Dashboard {$tag}",
            'email'           => "dash_{$tag}_" . uniqid() . '@gm.uit.edu.vn',
            'hashed_password' => Hash::make('password123'),
            ...AccountFixture::for($role),
            'status'          => 'Active',
            'initials'        => 'DB',
        ], $extra));
    }

    private function makeProblem(string $difficulty = 'Easy', array $overrides = []): Problem
    {
        return Problem::create(array_merge([
            'number'          => 'SQL-DB-' . strtoupper(uniqid()),
            'title'           => 'Bài ' . uniqid(),
            'difficulty'      => $difficulty,
            'description'     => 'Mô tả',
            'requirements'    => 'SELECT 1',
            'practice_listed' => true,
            'creator_id'      => $this->student->id,
        ], $overrides));
    }

    private function submit(User $user, Problem $problem, string $result = 'Accepted', ?Carbon $at = null, array $overrides = []): Submission
    {
        return Submission::create(array_merge([
            'user_id'      => $user->id,
            'problem_id'   => $problem->id,
            'source'       => 'SELECT 1',
            'context'      => 'Practice',
            'result'       => $result,
            'score'        => $result === 'Accepted' ? 100 : 0,
            'submitted_at' => $at ?? now(),
        ], $overrides));
    }

    private function dashboard(string $query = '')
    {
        Sanctum::actingAs($this->student);

        return $this->getJson('/api/student/dashboard' . $query);
    }

    // ── Phân quyền ──────────────────────────────────────────────────────────

    public function test_dashboard_requires_authentication_and_practice_permission(): void
    {
        $this->getJson('/api/student/dashboard')->assertStatus(401);

        // Role lạ: không có quyền PRACTICE_MGMT:view.
        Sanctum::actingAs($this->makeUser('visitor', 'nopermission'));
        $this->getJson('/api/student/dashboard')->assertStatus(403);
    }

    // ── Sinh viên mới ───────────────────────────────────────────────────────

    public function test_new_student_gets_an_empty_dashboard(): void
    {
        $this->dashboard()
            ->assertOk()
            ->assertJsonPath('user.id', $this->student->id)
            ->assertJsonPath('solved', 0)
            ->assertJsonPath('easy', 0)
            ->assertJsonPath('medium', 0)
            ->assertJsonPath('hard', 0)
            ->assertJsonPath('continuing', [])
            ->assertJsonPath('deadlines', [])
            ->assertJsonPath('submissionsPerDay', [])
            ->assertJsonPath('recent_submissions', []);
    }

    public function test_dashboard_user_payload_hides_the_password_hash(): void
    {
        $this->dashboard()
            ->assertOk()
            ->assertJsonPath('user.email', $this->student->email)
            ->assertJsonMissingPath('user.hashed_password');
    }

    // ── Bài đã giải ─────────────────────────────────────────────────────────

    public function test_solved_counts_distinct_accepted_problems_by_difficulty(): void
    {
        $easy = $this->makeProblem('Easy');
        $medium = $this->makeProblem('Medium');
        $hard = $this->makeProblem('Hard');

        // Cùng một bài nộp đúng nhiều lần chỉ tính một lần.
        $this->submit($this->student, $easy);
        $this->submit($this->student, $easy);
        $this->submit($this->student, $medium);
        $this->submit($this->student, $hard);

        $this->dashboard()
            ->assertOk()
            ->assertJsonPath('solved', 3)
            ->assertJsonPath('easy', 1)
            ->assertJsonPath('medium', 1)
            ->assertJsonPath('hard', 1);
    }

    public function test_only_accepted_submissions_of_the_current_user_count_as_solved(): void
    {
        $problem = $this->makeProblem('Easy');
        $notMine = $this->makeProblem('Medium');

        $this->submit($this->student, $problem, 'Rejected');
        $this->submit($this->student, $problem, 'Partial');
        $this->submit($this->other, $notMine, 'Accepted');

        $this->dashboard()
            ->assertOk()
            ->assertJsonPath('solved', 0)
            ->assertJsonPath('easy', 0)
            ->assertJsonPath('medium', 0);
    }

    /**
     * Khóa hiện trạng: độ khó được so khớp chính xác với 'Easy' / 'Medium' / 'Hard'. Bài có
     * độ khó khác (vd 'Dễ' do ProblemService đặt mặc định) vẫn được tính vào `solved` nhưng
     * không rơi vào nhóm nào, nên easy + medium + hard có thể nhỏ hơn solved.
     */
    public function test_solved_problem_with_unrecognised_difficulty_is_counted_only_in_the_total(): void
    {
        $this->submit($this->student, $this->makeProblem('Dễ'));
        $this->submit($this->student, $this->makeProblem('Easy'));

        $this->dashboard()
            ->assertOk()
            ->assertJsonPath('solved', 2)
            ->assertJsonPath('easy', 1)
            ->assertJsonPath('medium', 0)
            ->assertJsonPath('hard', 0);
    }

    /**
     * KHÁC SQL-UIT: bản gốc chỉ tính bài nộp chính thức (official_submission_clause), còn
     * Laravel tính mọi bài nộp, kể cả bài nộp trong bài tập/cuộc thi (context khác Practice).
     */
    public function test_submissions_from_assignments_also_count_as_solved(): void
    {
        $this->submit($this->student, $this->makeProblem('Easy'), 'Accepted', null, [
            'source'  => 'Assignments',
            'context' => 'some-assignment-id',
        ]);

        $this->dashboard()->assertOk()->assertJsonPath('solved', 1);
    }

    // ── Bài đang làm dở ─────────────────────────────────────────────────────

    public function test_continuing_lists_attempted_but_unsolved_problems_with_mapped_fields(): void
    {
        $problem = $this->makeProblem('Medium', [
            'number'       => 'SQL-77',
            'title'        => 'Bài đang làm',
            'description'  => 'Mô tả chi tiết',
            'requirements' => 'SELECT * FROM T',
            'topic'        => 'Truy vấn lồng',
            'topics'       => ['Truy vấn lồng', 'JOIN'],
        ]);
        $this->submit($this->student, $problem, 'Rejected');

        $res = $this->dashboard();
        $res->assertOk()->assertJsonCount(1, 'continuing');

        $res->assertJsonPath('continuing.0.id', $problem->id)
            ->assertJsonPath('continuing.0.number', 'SQL-77')
            ->assertJsonPath('continuing.0.title', 'Bài đang làm')
            ->assertJsonPath('continuing.0.topic', 'Truy vấn lồng')
            ->assertJsonPath('continuing.0.topics', ['Truy vấn lồng', 'JOIN'])
            ->assertJsonPath('continuing.0.difficulty', 'Medium')
            ->assertJsonPath('continuing.0.progress', 'In progress')
            ->assertJsonPath('continuing.0.description', 'Mô tả chi tiết')
            ->assertJsonPath('continuing.0.requirements', 'SELECT * FROM T');
    }

    public function test_continuing_uses_default_topic_and_database_type_when_missing(): void
    {
        $this->submit($this->student, $this->makeProblem('Easy'), 'Rejected');

        $this->dashboard()
            ->assertOk()
            ->assertJsonPath('continuing.0.topic', 'SQL')
            ->assertJsonPath('continuing.0.topics', [])
            ->assertJsonPath('continuing.0.databaseType', 'SQL Server');
    }

    public function test_continuing_falls_back_to_the_first_topic_when_topic_is_empty(): void
    {
        $this->submit($this->student, $this->makeProblem('Easy', ['topic' => null, 'topics' => ['DDL', 'DML']]), 'Rejected');

        $this->dashboard()->assertOk()->assertJsonPath('continuing.0.topic', 'DDL');
    }

    public function test_continuing_lists_each_problem_once_and_excludes_solved_and_other_users_problems(): void
    {
        $stillTrying = $this->makeProblem('Easy');
        $solvedLater = $this->makeProblem('Medium');
        $others = $this->makeProblem('Hard');

        $this->submit($this->student, $stillTrying, 'Rejected', now()->subHours(3));
        $this->submit($this->student, $stillTrying, 'Rejected', now()->subHours(2));
        $this->submit($this->student, $solvedLater, 'Rejected', now()->subHours(2));
        $this->submit($this->student, $solvedLater, 'Accepted', now()->subHour());
        $this->submit($this->other, $others, 'Rejected');

        $res = $this->dashboard();
        $res->assertOk()->assertJsonCount(1, 'continuing');
        $this->assertSame([$stillTrying->id], array_column($res->json('continuing'), 'id'));
        $res->assertJsonPath('solved', 1);
    }

    // ── Hoạt động theo ngày ─────────────────────────────────────────────────

    public function test_submissions_per_day_groups_by_date_ascending_with_an_alias(): void
    {
        $problem = $this->makeProblem();
        $day1 = now()->subDays(5)->setTime(12, 0);
        $day2 = now()->subDays(2)->setTime(12, 0);

        $this->submit($this->student, $problem, 'Rejected', $day2->copy());
        $this->submit($this->student, $problem, 'Rejected', $day1->copy());
        $this->submit($this->student, $problem, 'Accepted', $day1->copy()->setTime(15, 30));
        $this->submit($this->other, $problem, 'Accepted', $day1->copy());   // của người khác

        $res = $this->dashboard();
        $res->assertOk();

        $expected = [
            ['date' => $day1->format('Y-m-d'), 'count' => 2],
            ['date' => $day2->format('Y-m-d'), 'count' => 1],
        ];
        $this->assertSame($expected, $res->json('submissionsPerDay'));
        $this->assertSame($expected, $res->json('submissions_per_day'));
    }

    public function test_submissions_per_day_only_covers_the_last_26_weeks(): void
    {
        $problem = $this->makeProblem();
        $inside = now()->subWeeks(25)->setTime(12, 0);
        $outside = now()->subWeeks(27)->setTime(12, 0);

        $this->submit($this->student, $problem, 'Rejected', $inside->copy());
        $this->submit($this->student, $problem, 'Rejected', $outside->copy());

        $this->dashboard()
            ->assertOk()
            ->assertJsonCount(1, 'submissionsPerDay')
            ->assertJsonPath('submissionsPerDay.0.date', $inside->format('Y-m-d'));
    }

    /**
     * tz_offset (số phút UTC trừ giờ địa phương, như Date.getTimezoneOffset() của trình
     * duyệt) dịch thời điểm nộp sang NGÀY ĐỊA PHƯƠNG của sinh viên khi gộp theo ngày.
     * Thời gian trong DB là UTC.
     */
    public function test_tz_offset_groups_submissions_by_the_students_local_day(): void
    {
        $problem = $this->makeProblem();
        $evening = now()->utc()->subDays(3)->setTime(18, 30);   // 18:30 UTC
        $earlyMorning = now()->utc()->subDays(6)->setTime(2, 0); // 02:00 UTC

        $this->submit($this->student, $problem, 'Rejected', $evening->copy());
        $this->submit($this->student, $problem, 'Rejected', $earlyMorning->copy());

        $day = fn (Carbon $c, int $shiftDays = 0) => $c->copy()->addDays($shiftDays)->format('Y-m-d');

        // UTC: giữ nguyên ngày.
        $this->assertSame(
            [['date' => $day($earlyMorning), 'count' => 1], ['date' => $day($evening), 'count' => 1]],
            $this->dashboard()->json('submissionsPerDay')
        );

        // UTC+7 (gửi -420): 18:30 UTC thành 01:30 ngày hôm sau; 02:00 UTC thành 09:00 cùng ngày.
        $this->assertSame(
            [['date' => $day($earlyMorning), 'count' => 1], ['date' => $day($evening, 1), 'count' => 1]],
            $this->dashboard('?tz_offset=-420')->json('submissionsPerDay')
        );

        // UTC-5 (gửi 300): 18:30 UTC thành 13:30 cùng ngày; 02:00 UTC thành 21:00 ngày hôm trước.
        $this->assertSame(
            [['date' => $day($earlyMorning, -1), 'count' => 1], ['date' => $day($evening), 'count' => 1]],
            $this->dashboard('?tz_offset=300')->json('submissionsPerDay')
        );
    }

    public function test_tz_offset_is_optional_and_validated(): void
    {
        $this->dashboard()->assertOk();
        $this->dashboard('?tz_offset=')->assertOk();      // rỗng được coi như không gửi
        $this->dashboard('?tz_offset=-840')->assertOk();   // UTC+14
        $this->dashboard('?tz_offset=720')->assertOk();    // UTC-12

        foreach (['abc', '1.5', '721', '-841', '99999'] as $bad) {
            $this->dashboard("?tz_offset={$bad}")
                ->assertStatus(422)
                ->assertJsonValidationErrors(['tz_offset']);
        }
    }

    // ── Không còn chuỗi ngày (streak) ───────────────────────────────────────

    public function test_dashboard_no_longer_exposes_streak_fields(): void
    {
        $res = $this->dashboard();

        $res->assertOk();
        foreach (['currentStreak', 'longestStreak', 'current_streak', 'longest_streak'] as $key) {
            $res->assertJsonMissingPath($key);
        }
    }

    // ── Bài nộp gần đây ─────────────────────────────────────────────────────

    public function test_recent_submissions_returns_at_most_ten_newest_first_with_the_problem(): void
    {
        $problem = $this->makeProblem('Easy', ['title' => 'Bài gần đây']);

        $ids = [];
        for ($i = 12; $i >= 1; $i--) {
            $ids[$i] = $this->submit($this->student, $problem, 'Rejected', now()->subHours($i))->id;
        }
        $this->submit($this->other, $problem, 'Accepted', now());   // của người khác, mới nhất

        $res = $this->dashboard();
        $res->assertOk()->assertJsonCount(10, 'recent_submissions');

        // Mới nhất của chính mình là i = 1, rồi 2, 3...
        $this->assertSame(
            [$ids[1], $ids[2], $ids[3], $ids[4], $ids[5], $ids[6], $ids[7], $ids[8], $ids[9], $ids[10]],
            array_column($res->json('recent_submissions'), 'id')
        );
        $res->assertJsonPath('recent_submissions.0.problem.title', 'Bài gần đây');
    }
}
