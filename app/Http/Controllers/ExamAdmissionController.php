<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Services\ExamAdmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ExamAdmissionController extends Controller
{
    public function __construct(
        protected readonly ExamAdmissionService $examAdmissionService
    ) {
    }

    public function listCredentials(Request $request, string $examId, string $sessionId): JsonResponse
    {
        return response()->json(
            $this->examAdmissionService->listSessionCredentials($examId, $sessionId, $request->user())
        );
    }

    public function issueAllCredentials(Request $request, string $examId): Response
    {
        $csv = $this->examAdmissionService->issueAllCredentials($examId, $request->user());
        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
        ]);
    }

    public function issueSessionCredentials(Request $request, string $examId, string $sessionId): Response
    {
        $csv = $this->examAdmissionService->issueSessionCredentials($examId, $sessionId, $request->user());
        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
        ]);
    }

    public function exportClassCredentials(Request $request, string $examId, string $sessionId): Response
    {
        $classId = $request->input('classId', $request->input('class_id'));
        $reissue = filter_var($request->input('reissue', false), FILTER_VALIDATE_BOOLEAN);

        [$content, $mediaType, $filename] = $this->examAdmissionService->exportClassCredentials(
            $examId,
            $sessionId,
            $classId,
            $reissue,
            $request->user()
        );

        return response($content, 200, [
            'Content-Type'        => $mediaType,
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    public function manageCredential(Request $request, string $examId, string $sessionId, string $credentialId): Response|JsonResponse
    {
        $action = (string) ($request->input('action') ?? '');
        $reason = (string) ($request->input('reason') ?? '');

        $result = $this->examAdmissionService->manageCredential(
            $examId,
            $sessionId,
            $credentialId,
            $action,
            $reason,
            $request->user()
        );

        if (is_string($result)) {
            return response($result, 200, [
                'Content-Type' => 'text/csv; charset=utf-8',
            ]);
        }

        return response()->json($result);
    }

    public function unlockAdmission(Request $request, string $activityId): JsonResponse
    {
        $user = $request->user();
        $username = (string) ($request->input('username') ?? '');
        $password = (string) ($request->input('password') ?? '');

        [$row, $payload] = $this->examAdmissionService->unlockAdmission($activityId, $username, $password, $user);

        $response = response()->json($payload);
        if ($row) {
            $activity = Assignment::find($activityId);
            if ($activity) {
                $cookieValue = $this->examAdmissionService->buildAdmissionCookieValue($user, $activity, $row);
                $response->withCookie(cookie(
                    ExamAdmissionService::COOKIE_NAME,
                    $cookieValue,
                    480,
                    '/',
                    null,
                    false,
                    true,
                    false,
                    'Lax'
                ));
            }
        }

        return $response;
    }
}

