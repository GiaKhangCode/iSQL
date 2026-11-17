<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\Submission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
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
