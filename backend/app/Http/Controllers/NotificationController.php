<?php

namespace App\Http\Controllers;

use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controller mỏng: lấy user đang đăng nhập, gọi NotificationService, trả JSON.
 * Mọi nghiệp vụ và kiểm tra quyền sở hữu nằm trong service.
 */
class NotificationController extends Controller
{
    public function __construct(private readonly NotificationService $notificationService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(
            $this->notificationService->listForUser($request->user()->id)
        );
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'count' => $this->notificationService->unreadCount($request->user()->id),
        ]);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        return response()->json(
            $this->notificationService->markRead($request->user()->id, $id)
        );
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $updated = $this->notificationService->markAllRead($request->user()->id);

        return response()->json([
            'message' => 'All notifications marked as read',
            'updated' => $updated,
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->notificationService->delete($request->user()->id, $id);

        return response()->json(['message' => 'Notification deleted']);
    }

    public function destroyAll(Request $request): JsonResponse
    {
        $deleted = $this->notificationService->deleteAll($request->user()->id);

        return response()->json([
            'message' => 'All notifications deleted',
            'deleted' => $deleted,
        ]);
    }
}
