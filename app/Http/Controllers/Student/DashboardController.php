<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Submission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $recentSubmissions = Submission::where('user_id', $user->id)
            ->with('problem')
            ->orderBy('submitted_at', 'desc')
            ->limit(10)
            ->get();

        return response()->json([
            'user'               => $user,
            'current_streak'     => $user->current_streak,
            'longest_streak'     => $user->longest_streak,
            'recent_submissions' => $recentSubmissions,
        ]);
    }
}
