<?php

namespace App\Http\Controllers;

use App\Http\Requests\Teacher\ShareContentRequest;
use App\Services\ContentSharingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContentSharingController extends Controller
{
    public function __construct(
        protected ContentSharingService $sharing,
    ) {}

    /** GET /api/sharing: nội dung đã được chia sẻ cho người dùng hiện tại. */
    public function inbox(Request $request): JsonResponse
    {
        return response()->json($this->sharing->inbox($request->user()));
    }

    /** GET /api/sharing/recipients/search?q=...&kind=problem|library */
    public function searchRecipients(Request $request): JsonResponse
    {
        return response()->json($this->sharing->searchRecipients(
            $request->user(),
            (string) $request->query('kind', 'problem'),
            (string) $request->query('q', ''),
        ));
    }

    /** GET /api/sharing/{kind}/{resourceId} */
    public function shares(Request $request, string $kind, string $resourceId): JsonResponse
    {
        return response()->json($this->sharing->shares($request->user(), $kind, $resourceId));
    }

    /** PUT /api/sharing/{kind}/{resourceId} */
    public function share(ShareContentRequest $request, string $kind, string $resourceId): JsonResponse
    {
        $this->sharing->share($request->user(), $kind, $resourceId, $request->validated()['email'], $request->canEdit());

        return response()->json(['ok' => true]);
    }

    /** DELETE /api/sharing/{kind}/{resourceId}/{recipientId} */
    public function revoke(Request $request, string $kind, string $resourceId, string $recipientId): JsonResponse
    {
        $this->sharing->revoke($request->user(), $kind, $resourceId, $recipientId);

        return response()->json(['ok' => true]);
    }

    /** POST /api/sharing/{kind}/{resourceId}/copy */
    public function copy(Request $request, string $kind, string $resourceId): JsonResponse
    {
        return response()->json($this->sharing->copy($request->user(), $kind, $resourceId));
    }

    /** GET /api/sharing/{kind}/{resourceId}/preview */
    public function preview(Request $request, string $kind, string $resourceId): JsonResponse
    {
        return response()->json($this->sharing->preview($request->user(), $kind, $resourceId));
    }
}
