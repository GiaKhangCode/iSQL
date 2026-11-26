<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\StatisticsService;
use App\Services\Access\StaffAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StatisticsController extends Controller
{
    public function __construct(
        protected StatisticsService $statisticsService,
        protected StaffAccess $staff,
    ) {
    }

    public function teacher(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->staff->canViewStatistics($user), 403, 'Bạn không có quyền xem thống kê giảng viên.');

        $filters = [
            'kind'        => $request->query('kind', 'assignments'),
            'term'        => $request->query('term', ''),
            'classIds'    => $this->normalizeArrayParam($request, 'class_ids'),
            'activityIds' => $this->normalizeArrayParam($request, 'activity_ids'),
            'dateFrom'    => $request->query('date_from', ''),
            'dateTo'      => $request->query('date_to', ''),
        ];

        return response()->json($this->statisticsService->getTeacherStatistics($user, $filters));
    }

    public function office(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->staff->canViewFacultyStatistics($user), 403, 'Bạn không có quyền xem Dashboard tổng quát.');

        $filters = [
            'kind'          => $request->query('kind', 'all'),
            'term'          => $request->query('term', ''),
            'course'        => $request->query('course', ''),
            'examinationId' => $request->query('examination_id', ''),
            'classIds'      => $this->normalizeArrayParam($request, 'class_ids'),
            'activityIds'   => $this->normalizeArrayParam($request, 'activity_ids'),
            'dateFrom'      => $request->query('date_from', ''),
            'dateTo'        => $request->query('date_to', ''),
        ];

        return response()->json($this->statisticsService->getOfficeStatistics($user, $filters));
    }

    protected function normalizeArrayParam(Request $request, string $key): array
    {
        $raw = $request->query($key);
        if (is_array($raw)) {
            return array_values(array_filter(array_map('strval', $raw)));
        }
        if (is_string($raw) && trim($raw) !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $raw))));
        }
        return [];
    }
}

