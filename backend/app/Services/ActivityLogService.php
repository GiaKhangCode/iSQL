<?php

namespace App\Services;

use App\Models\ActivityLog;

/**
 * Ghi nhật ký hoạt động (bảng activity_logs).
 *
 * Chuỗi `action` là hợp đồng với frontend: OverviewPage.tsx đọc "Created class X",
 * "Updated class X"... bằng regex, nên đừng đổi câu chữ tùy tiện.
 */
class ActivityLogService
{
    public function record(?string $userId, string $action): ActivityLog
    {
        return ActivityLog::create([
            'user_id' => $userId,
            'action'  => $action,
        ]);
    }
}
