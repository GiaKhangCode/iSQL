<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Problem;
use App\Models\Submission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        // 1. Solved problems (result = 'Accepted')
        $solvedProblemIds = Submission::where('user_id', $user->id)
            ->where('result', 'Accepted')
            ->distinct()
            ->pluck('problem_id');

        $solvedCount = $solvedProblemIds->count();

        // Solved breakdown by difficulty
        $difficultyCounts = Problem::whereIn('id', $solvedProblemIds)
            ->select('difficulty', DB::raw('count(*) as count'))
            ->groupBy('difficulty')
            ->pluck('count', 'difficulty');

        $easyCount = (int) ($difficultyCounts['Easy'] ?? 0);
        $mediumCount = (int) ($difficultyCounts['Medium'] ?? 0);
        $hardCount = (int) ($difficultyCounts['Hard'] ?? 0);

        // 2. Continuing problems (attempted but not yet Accepted)
        $attemptedProblemIds = Submission::where('user_id', $user->id)
            ->whereNotIn('problem_id', $solvedProblemIds)
            ->orderBy('submitted_at', 'desc')
            ->pluck('problem_id')
            ->unique();

        $continuingProblems = Problem::whereIn('id', $attemptedProblemIds)
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

        // 3. Submissions per day (past 26 weeks)
        $startDate = now()->subWeeks(26)->startOfDay();
        $submissionsPerDay = Submission::where('user_id', $user->id)
            ->where('submitted_at', '>=', $startDate)
            ->select(DB::raw("DATE(submitted_at) as date"), DB::raw("COUNT(*) as count"))
            ->groupBy(DB::raw("DATE(submitted_at)"))
            ->orderBy('date', 'asc')
            ->get()
            ->map(function ($row) {
                return [
                    'date'  => (string) $row->date,
                    'count' => (int) $row->count,
                ];
            });

        $recentSubmissions = Submission::where('user_id', $user->id)
            ->with('problem')
            ->orderBy('submitted_at', 'desc')
            ->limit(10)
            ->get();

        return response()->json([
            'user'                => $user,
            'solved'              => $solvedCount,
            'easy'                => $easyCount,
            'medium'              => $mediumCount,
            'hard'                => $hardCount,
            'continuing'          => $continuingProblems,
            'deadlines'           => [],
            'currentStreak'       => (int) ($user->current_streak ?? 0),
            'longestStreak'       => (int) ($user->longest_streak ?? 0),
            'current_streak'      => (int) ($user->current_streak ?? 0),
            'longest_streak'      => (int) ($user->longest_streak ?? 0),
            'submissionsPerDay'   => $submissionsPerDay,
            'submissions_per_day' => $submissionsPerDay,
            'recent_submissions'  => $recentSubmissions,
        ]);
    }
}
