<?php

namespace App\Services;

use App\Models\Problem;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Số liệu cho trang tổng quan của sinh viên.
 */
class StudentDashboardService
{
    /**
     * @return array<string, mixed>
     */
    public function getDashboard(User $user): array
    {
        $solvedProblemIds = $this->solvedProblemIds($user);
        $difficultyCounts = $this->solvedByDifficulty($solvedProblemIds);

        return [
            'user'                => $user,
            'solved'              => $solvedProblemIds->count(),
            'easy'                => (int) ($difficultyCounts['Easy'] ?? 0),
            'medium'              => (int) ($difficultyCounts['Medium'] ?? 0),
            'hard'                => (int) ($difficultyCounts['Hard'] ?? 0),
            'continuing'          => $this->continuingProblems($user, $solvedProblemIds),
            'deadlines'           => [],
            'recent_submissions'  => $this->recentSubmissions($user),
        ];
    }

    /**
     * Id các bài toán đã có ít nhất một bài nộp đúng (Accepted), mỗi bài một lần.
     *
     * @return \Illuminate\Support\Collection<int, string>
     */
    private function solvedProblemIds(User $user)
    {
        return Submission::where('user_id', $user->id)
            ->where('result', 'Accepted')
            ->distinct()
            ->pluck('problem_id');
    }

    /**
     * Số bài đã giải theo độ khó. Độ khó được so khớp chính xác ('Easy', 'Medium', 'Hard').
     *
     * @return \Illuminate\Support\Collection<string, int>
     */
    private function solvedByDifficulty($solvedProblemIds)
    {
        return Problem::whereIn('id', $solvedProblemIds)
            ->select('difficulty', DB::raw('count(*) as count'))
            ->groupBy('difficulty')
            ->pluck('count', 'difficulty');
    }

    /**
     * Bài đã nộp nhưng chưa có bài nộp đúng nào.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function continuingProblems(User $user, $solvedProblemIds)
    {
        $attemptedProblemIds = Submission::where('user_id', $user->id)
            ->whereNotIn('problem_id', $solvedProblemIds)
            ->orderBy('submitted_at', 'desc')
            ->pluck('problem_id')
            ->unique();

        return Problem::whereIn('id', $attemptedProblemIds)
            ->get()
            ->map(function ($p) {
                return [
                    'id'           => (string) $p->id,
                    'number'       => (string) $p->number,
                    'title'        => (string) $p->title,
                    'topic'        => (string) ($p->topic ?: (is_array($p->topics) && count($p->topics) ? $p->topics[0] : 'SQL')),
                    'topics'       => $p->topics ?: [],
                    'difficulty'   => (string) $p->difficulty,
                    'progress'     => 'In progress',
                    'description'  => (string) $p->description,
                    'requirements' => (string) $p->requirements,
                    'databaseType' => (string) ($p->database_type ?: 'SQL Server'),
                ];
            })
            ->values();
    }

    /**
     * 10 bài nộp mới nhất kèm bài toán.
     */
    private function recentSubmissions(User $user)
    {
        return Submission::where('user_id', $user->id)
            ->with('problem')
            ->orderBy('submitted_at', 'desc')
            ->limit(10)
            ->get();
    }
}
