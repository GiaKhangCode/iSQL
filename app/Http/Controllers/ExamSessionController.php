<?php

namespace App\Http\Controllers;

use App\Services\ExamSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExamSessionController extends Controller
{
    public function __construct(
        protected readonly ExamSessionService $examSessionService
    ) {
    }

    public function proctors(Request $request): JsonResponse
    {
        return response()->json($this->examSessionService->listProctors($request->user()));
    }

    public function plan(Request $request, string $sessionId): JsonResponse
    {
        return response()->json($this->examSessionService->getSessionPlan($sessionId, $request->user()));
    }

    public function confirm(Request $request, string $sessionId): JsonResponse
    {
        return response()->json($this->examSessionService->confirmSession($sessionId, $request->user()));
    }

    public function reopen(Request $request, string $sessionId): JsonResponse
    {
        $reason = (string) ($request->input('reason') ?? '');
        return response()->json($this->examSessionService->reopenSession($sessionId, $reason, $request->user()));
    }

    public function addIndividualStudents(Request $request, string $sessionId): JsonResponse
    {
        $studentIds = (array) ($request->input('student_ids') ?? $request->input('studentIds') ?? []);
        return response()->json(
            $this->examSessionService->addIndividualStudents($sessionId, $studentIds, $request->user())
        );
    }
}

