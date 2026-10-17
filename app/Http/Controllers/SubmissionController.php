<?php

namespace App\Http\Controllers;

use App\Models\Submission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubmissionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Submission::with(['user', 'problem'])->orderBy('submitted_at', 'desc');

        if ($user->role === 'student') {
            $query->where('user_id', $user->id);
        }

        return response()->json($query->paginate(20));
    }

    public function show(string $id): JsonResponse
    {
        $submission = Submission::with(['user', 'problem'])->findOrFail($id);
        return response()->json($submission);
    }

    public function review(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'evaluated_score' => 'required|integer',
            'feedback'        => 'nullable|string',
        ]);

        $submission = Submission::findOrFail($id);
        $submission->evaluated_score = $request->evaluated_score;
        $submission->feedback = $request->feedback;
        $submission->save();

        return response()->json($submission);
    }
}
