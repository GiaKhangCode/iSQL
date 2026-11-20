<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Hàng đợi duyệt giảng viên (panel "Lecturer registrations" của trang Tổng quan).
 *
 * "Đang chờ duyệt" = tài khoản nhân sự (account_type = staff) có status Pending. Đây là định nghĩa DUY NHẤT:
 * danh sách, duyệt, từ chối và bộ đếm `pendingRequests` đều dùng pendingQuery().
 * Duyệt = chuyển sang Active; từ chối = xóa tài khoản chờ (giống bản gốc SQL-UIT).
 * Chuỗi nhật ký "Approved lecturer {email}" / "Rejected lecturer {email}" là hợp đồng với regex trong OverviewPage.tsx.
 */
class LecturerRequestService
{
    public function __construct(
        private readonly UserService $users,
        private readonly ActivityLogService $activityLog,
    ) {
    }

    /** @return Builder<User> */
    public function pendingQuery(): Builder
    {
        return User::query()
            ->where('status', 'Pending')
            ->where('account_type', User::ACCOUNT_STAFF);
    }

    public function pendingCount(): int
    {
        return $this->pendingQuery()->count();
    }

    /**
     * Đơn cũ nhất trước (xử lý theo thứ tự nộp).
     *
     * @return array<int, array{id: string, name: string, email: string, department: string, submitted: string}>
     */
    public function list(): array
    {
        return $this->pendingQuery()
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (User $user): array => [
                'id'         => $user->id,
                'name'       => $user->name,
                'email'      => $user->email,
                'department' => (string) ($user->department ?? ''),
                'submitted'  => $user->created_at ? $user->created_at->utc()->format('d/m/Y') : '',
            ])
            ->all();
    }

    public function approve(string $id, User $actor): void
    {
        DB::transaction(function () use ($id, $actor): void {
            $user = $this->findPending($id);
            $this->users->updateStatus($user->id, 'Active');
            $this->activityLog->record($actor->id, "Approved lecturer {$user->email}");
        });
    }

    public function reject(string $id, User $actor): void
    {
        DB::transaction(function () use ($id, $actor): void {
            $user = $this->findPending($id);
            $email = $user->email;
            $this->users->deleteUser($user->id);
            $this->activityLog->record($actor->id, "Rejected lecturer {$email}");
        });
    }

    private function findPending(string $id): User
    {
        $user = $this->pendingQuery()->where('id', $id)->first();
        abort_if($user === null, 404, 'Request not found');

        return $user;
    }
}
