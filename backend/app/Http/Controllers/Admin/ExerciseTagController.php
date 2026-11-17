<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ExerciseTagRequest;
use App\Services\ExerciseTagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExerciseTagController extends Controller
{
    public function __construct(
        protected readonly ExerciseTagService $exerciseTagService
    ) {
    }

    /**
     * Get list of all exercise tags (Public / Authenticated)
     * GET /api/exercise-tags or GET /api/admin/exercise-tags
     */
    public function index(Request $request): JsonResponse
    {
        // Auto-seed defaults if table is empty
        $this->exerciseTagService->seedDefaults();

        $tags = $this->exerciseTagService->getAllTags();

        return response()->json($tags);
    }

    /**
     * Create a new exercise tag (Admin only)
     * POST /api/admin/exercise-tags
     */
    public function store(ExerciseTagRequest $request): JsonResponse
    {
        $tag = $this->exerciseTagService->createTag($request->validatedPayload(), $request->user());

        return response()->json($tag, 201);
    }

    /**
     * Update an exercise tag (Admin only)
     * PUT /api/admin/exercise-tags/{id}
     */
    public function update(ExerciseTagRequest $request, string $id): JsonResponse
    {
        $tag = $this->exerciseTagService->updateTag($id, $request->validatedPayload(), $request->user());

        return response()->json($tag);
    }

    /**
     * Delete an exercise tag (Admin only)
     * DELETE /api/admin/exercise-tags/{id}
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->exerciseTagService->deleteTag($id, $request->user());

        return response()->json([
            'message' => 'Exercise tag deleted successfully.',
        ]);
    }
}

