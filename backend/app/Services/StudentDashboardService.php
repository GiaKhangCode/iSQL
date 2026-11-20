<?php

namespace App\Services;

use App\Models\Problem;
use App\Models\Submission;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Số liệu cho trang tổng quan của sinh viên.
 *
 * Thời gian trong DB và ứng dụng đều là UTC. Biểu đồ hoạt động theo ngày được quy đổi sang
 * NGÀY ĐỊA PHƯƠNG của sinh viên bằng $tzOffset (số phút UTC trừ giờ địa phương, giống
 * Date.getTimezoneOffset() của trình duyệt).
 */
class StudentDashboardService
{
    /** Số tuần gần nhất đưa vào biểu đồ hoạt động. */
    private const ACTIVITY_WEEKS = 26;

    /**
     * @return array<string, mixed>
     */
    public function getDashboard(User $user, int $tzOffset = 0): array
    {
        $solvedProblemIds = $this->solvedProblemIds($user);
        $difficultyCounts = $this->solvedByDifficulty($solvedProblemIds);
        $submissionsPerDay = $this->submissionsPerDay($user, $tzOffset);

        return [
            'user'                => $user,
            'solved'              => $solvedProblemIds->count(),
            'easy'                => (int) ($difficultyCounts['Easy'] ?? 0),
            'medium'              => (int) ($difficultyCounts['Medium'] ?? 0),
            'hard'                => (int) ($difficultyCounts['Hard'] ?? 0),
            'continuing'          => $this->continuingProblems($user, $solvedProblemIds),
            'deadlines'           => [],
            'submissionsPerDay'   => $submissionsPerDay,
            'submissions_per_day' => $submissionsPerDay,
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
     * Số bài nộp mỗi ngày địa phương trong ACTIVITY_WEEKS tuần gần nhất, ngày tăng dần.
     *
     * @return \Illuminate\Support\Collection<int, array{date: string, count: int}>
     */
    private function submissionsPerDay(User $user, int $tzOffset)
    {
        // Mốc bắt đầu tính theo ngày địa phương rồi quy đổi lại UTC để lọc trong DB.
        $startUtc = $this->localNow($tzOffset)
            ->subWeeks(self::ACTIVITY_WEEKS)
            ->startOfDay()
            ->addMinutes($tzOffset);

        // $tzOffset là int (đã ép kiểu) nên đưa thẳng vào SQL là an toàn; không dùng tham số
        // ? vì MySQL (ONLY_FULL_GROUP_BY) coi biểu thức ở SELECT và GROUP BY là khác nhau.
        $localDate = "DATE(DATE_SUB(submitted_at, INTERVAL {$tzOffset} MINUTE))";

        return Submission::where('user_id', $user->id)
            ->where('submitted_at', '>=', $startUtc)
            ->selectRaw("{$localDate} as date, COUNT(*) as count")
            ->groupByRaw($localDate)
            ->orderBy('date', 'asc')
            ->get()
            ->map(fn ($row) => [
                'date'  => (string) $row->date,
                'count' => (int) $row->count,
            ]);
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

    /**
     * Thời điểm hiện tại theo giờ địa phương của sinh viên (biểu diễn bằng Carbon, không gắn múi giờ thật).
     */
    private function localNow(int $tzOffset): Carbon
    {
        return now()->utc()->subMinutes($tzOffset);
    }
}
