<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Http\Requests\AssignmentRequest;
use App\Services\AssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AssignmentController extends Controller
{
    public function __construct(protected readonly AssignmentService $assignmentService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $scope = $request->query('scope');
        $assignments = $this->assignmentService->getAllAssignments($request->user(), $scope);
        return response()->json($assignments);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $assignment = $this->assignmentService->getAssignmentById($id, $request->user());
        return response()->json($assignment);
    }

    public function audienceCount(Request $request): JsonResponse
    {
        $audienceType = $request->query('audience_type', 'classes');
        $classIds = $this->extractClassIdsFromQuery($request);
        $studentEmails = preg_split('/[,;\s]+/', (string) $request->query('student_emails', ''));

        $counts = $this->assignmentService->getAudienceCount($audienceType, $classIds, array_filter($studentEmails), $request->user());
        return response()->json($counts);
    }

    public function audiencePreview(Request $request): JsonResponse
    {
        $audienceType = $request->input('audienceType', $request->input('audience_type', 'classes'));
        $classIds = (array) $request->input('classIds', $request->input('class_ids', []));
        $studentEmails = (array) $request->input('studentEmails', $request->input('student_emails', []));

        $counts = $this->assignmentService->getAudienceCount($audienceType, $classIds, array_filter($studentEmails), $request->user());
        return response()->json($counts);
    }

    public function searchStudents(Request $request): JsonResponse
    {
        $query = (string) $request->query('q', '');
        $classIds = $this->extractClassIdsFromQuery($request);

        $result = $this->assignmentService->searchAudienceStudents($query, $classIds, $request->user());
        return response()->json($result);
    }

    protected function extractClassIdsFromQuery(Request $request): array
    {
        $classIds = [];
        $queryString = (string) $request->server->get('QUERY_STRING', '');
        if ($queryString !== '' && str_contains($queryString, 'class_ids')) {
            preg_match_all('/(?:^|&|;)class_ids(?:%5B%5D|\[\])?=([^&;]*)/i', $queryString, $matches);
            if (!empty($matches[1])) {
                $classIds = array_map('urldecode', $matches[1]);
            }
        }

        if (empty($classIds)) {
            $raw = $request->query('class_ids');
            if (is_array($raw)) {
                $classIds = $raw;
            } elseif (is_string($raw) && trim($raw) !== '') {
                $classIds = [trim($raw)];
            }
        }

        return array_values(array_unique(array_filter($classIds, fn ($v) => is_string($v) && trim($v) !== '')));
    }

    public function uploadBanner(Request $request): JsonResponse
    {
        $request->validate([
            'banner' => 'required|file|max:6144',
            'source' => 'required|file|max:8192',
        ]);

        $result = $this->assignmentService->uploadBanner(
            $request->file('banner'),
            $request->file('source'),
            $request->user()
        );

        return response()->json($result);
    }

    public function getBanner(string $filename): BinaryFileResponse
    {
        $path = $this->assignmentService->getBannerPath($filename);
        if (!$path) {
            abort(404, 'Banner not found.');
        }

        return response()->file($path, [
            'Content-Type'  => 'image/webp',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    public function store(AssignmentRequest $request): JsonResponse
    {
        $assignment = $this->assignmentService->createAssignment(
            $request->validated(),
            $request->user()->id
        );

        return response()->json($assignment, 201);
    }

    public function update(AssignmentRequest $request, string $id): JsonResponse
    {
        $assignment = $this->assignmentService->updateAssignment(
            $id,
            $request->validated(),
            $request->user()
        );

        return response()->json($assignment);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->assignmentService->deleteAssignment($id, $request->user());
        return response()->json(['message' => 'Assignment deleted successfully']);
    }

    public function submissions(Request $request, string $id): JsonResponse
    {
        $submissions = $this->assignmentService->getAssignmentSubmissions($id, $request->user());
        return response()->json($submissions);
    }
}
