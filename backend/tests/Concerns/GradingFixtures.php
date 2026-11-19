<?php

namespace Tests\Concerns;

use App\Models\Assignment;
use App\Models\AssignmentProblem;
use App\Models\Problem;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkspaceSubmissionResult;
use Illuminate\Support\Str;

/**
 * Dữ liệu dựng sẵn cho test bài nộp / chấm bài / phúc khảo / hồ sơ sinh viên (Submission*, StudentProfile*).
 * Dùng lại makeAccount, makeStudent, makeClass... của ProctorFixtures.
 */
trait GradingFixtures
{
    use ProctorFixtures;

    protected function makeProblem(array $overrides = []): Problem
    {
        return Problem::create(array_merge([
            'number'          => 'GF-' . strtoupper(substr(uniqid(), -8)),
            'title'           => 'GF problem ' . uniqid(),
            'difficulty'      => 'Easy',
            'description'     => 'Đề bài',
            'requirements'    => 'r',
            'practice_listed' => false,
            'workspace_parts' => ['SQL'],
        ], $overrides));
    }

    /** Hoạt động đã công bố, đang mở, điểm hiển thị sau khi nộp (mặc định). */
    protected function makeActivity(?User $owner, array $overrides = []): Assignment
    {
        return Assignment::create(array_merge([
            'title'                    => 'GF activity ' . uniqid(),
            'instructor_id'            => $owner?->id,
            'published'                => true,
            'opens'                    => now()->subDay(),
            'closes'                   => now()->addDays(2),
            'show_scores_after_submit' => true,
        ], $overrides));
    }

    protected function attachProblem(Assignment $activity, Problem $problem, float $points = 10, int $order = 1, ?array $parts = null): AssignmentProblem
    {
        return AssignmentProblem::create([
            'assignment_id'   => $activity->id,
            'problem_id'      => $problem->id,
            'points'          => $points,
            'order_index'     => $order,
            'workspace_parts' => $parts,
        ]);
    }

    /** Bài nộp; $activity = null thì là bài luyện tập (source/context = Practice). */
    protected function makeSubmission(User $student, Problem $problem, ?Assignment $activity = null, array $overrides = []): Submission
    {
        return Submission::create(array_merge([
            'id'           => (string) Str::uuid(),
            'user_id'      => $student->id,
            'problem_id'   => $problem->id,
            'source'       => $activity ? 'Assignments' : 'Practice',
            'context'      => $activity ? $activity->id : 'Practice',
            'result'       => 'Accepted',
            'score'        => 5,
            'query_text'   => 'SELECT 1',
            'submitted_at' => now(),
        ], $overrides));
    }

    protected function withSections(Submission $sub, array $sections): Submission
    {
        WorkspaceSubmissionResult::create(['submission_id' => $sub->id, 'sections' => $sections]);

        return $sub;
    }

    /** Bài nộp của một câu lẻ (cập nhật từng câu), không phải lần nộp chính thức. */
    protected function questionOnly(Submission $sub, string $part = 'SQL', string $questionId = 'q1', float $score = 50): Submission
    {
        return $this->withSections($sub, [[
            'part'                => $part,
            '_questionSubmission' => ['part' => $part, 'questionId' => $questionId, 'score' => $score],
        ]]);
    }
}
