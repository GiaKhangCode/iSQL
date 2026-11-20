<?php

namespace App\Http\Controllers;

use App\Services\ExamLoginService;
use App\Services\ProctorAccessService;
use App\Services\ProctorNoticeService;
use App\Services\ProctorRoomService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Đăng nhập tài khoản ca thi (/exam-auth/*) và coi thi (/proctor/*). Logic nằm ở ExamLoginService,
 * ProctorAccessService, ProctorRoomService và ProctorNoticeService.
 *
 * Các khóa đầu vào có hai cách viết (room_id / roomId, student_code / studentCode, browser_id / browserId)
 * vì frontend cũ và mới đều đang gọi.
 */
class ExamLoginProctorController extends Controller
{
    public function __construct(
        protected readonly ExamLoginService $examLogin,
        protected readonly ProctorAccessService $access,
        protected readonly ProctorRoomService $rooms,
        protected readonly ProctorNoticeService $notices,
    ) {
    }

    public function login(Request $request): JsonResponse
    {
        $login = $this->examLogin->login(
            $request,
            (string) $request->input('username', ''),
            (string) $request->input('password', '')
        );

        return $this->examLogin->toResponse($login);
    }

    public function status(Request $request): JsonResponse
    {
        return response()->json($this->examLogin->status($request->user()));
    }

    public function proctorSessions(Request $request): JsonResponse
    {
        return response()->json($this->access->sessionsFor($request->user()));
    }

    public function roomStatus(Request $request, string $sessionId): JsonResponse
    {
        $roomId = $request->query('room_id', $request->query('roomId'));

        return response()->json($this->rooms->roomStatus($request->user(), $sessionId, $roomId));
    }

    public function sessionNotices(Request $request, string $sessionId): JsonResponse
    {
        $session = $this->access->requireSession($sessionId, $request->user());

        return response()->json($this->notices->listFor($session));
    }

    public function unlockStudent(Request $request, string $sessionId, string $studentId): JsonResponse
    {
        $this->rooms->unlockStudent($request->user(), $sessionId, $studentId);

        return response()->json(['ok' => true]);
    }

    public function checkStudent(Request $request, string $sessionId): JsonResponse
    {
        return response()->json($this->rooms->checkStudent(
            $request->user(),
            $sessionId,
            $request->input('room_id', $request->input('roomId')),
            $this->studentCodeOf($request)
        ));
    }

    public function addStudent(Request $request, string $sessionId): JsonResponse
    {
        $result = $this->rooms->addStudent(
            $request->user(),
            $sessionId,
            $request->input('room_id', $request->input('roomId')),
            $this->studentCodeOf($request)
        );

        return response()->json([
            'ok'          => true,
            'student'     => $result['student'],
            'credentials' => $result['credentials'],
        ]);
    }

    public function approveBrowser(Request $request, string $admissionId): JsonResponse
    {
        $this->rooms->approveBrowser(
            $request->user(),
            $admissionId,
            (string) ($request->input('browser_id') ?? $request->input('browserId') ?? ''),
            (string) ($request->input('reason') ?? '')
        );

        return response()->json(['ok' => true]);
    }

    private function studentCodeOf(Request $request): string
    {
        return (string) ($request->input('student_code') ?? $request->input('studentCode') ?? '');
    }
}
