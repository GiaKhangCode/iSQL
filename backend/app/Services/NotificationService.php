<?php

namespace App\Services;

use App\Models\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Nghiệp vụ thông báo (chuông) của người dùng.
 *
 * Quy tắc chung: MỌI hàm đều nhận $userId và chỉ thao tác trên thông báo của
 * chính user đó. Controller truyền id của user đang đăng nhập, nên một user
 * không thể đọc hay xóa thông báo của người khác dù đoán được id.
 */
class NotificationService
{
    /** Số dòng tối đa cho mỗi câu INSERT khi tạo hàng loạt. */
    private const INSERT_CHUNK_SIZE = 500;

    /**
     * Query gốc, luôn giới hạn theo chủ sở hữu. Mọi hàm bên dưới đều đi qua đây
     * để không thể "quên" điều kiện user_id ở một chỗ nào đó.
     */
    private function forUser(string $userId): Builder
    {
        return Notification::query()->where('user_id', $userId);
    }

    /**
     * Tìm một thông báo của user, không có (hoặc của người khác) thì trả 404.
     * Cố ý dùng chung một thông báo lỗi cho cả hai trường hợp để không lộ việc
     * id đó có tồn tại hay không.
     */
    private function findOrFail(string $userId, string $notificationId): Notification
    {
        $notification = $this->forUser($userId)->where('id', $notificationId)->first();

        if (!$notification) {
            throw new HttpException(404, 'Không tìm thấy thông báo.');
        }

        return $notification;
    }

    /**
     * Tạo một thông báo mới cho user.
     *
     * Đây là nơi duy nhất nên tạo thông báo: tự sinh id, đặt chưa đọc và thời điểm
     * tạo, để các controller/service khác chỉ cần truyền nội dung.
     *
     * @param string      $userId  người nhận
     * @param string      $title   tiêu đề ngắn
     * @param string      $message nội dung
     * @param string      $type    nhãn nghiệp vụ (vd: Assignment, Review, Info, content_share)
     * @param string|null $link    đường dẫn trên frontend khi bấm vào thông báo
     */
    public function create(
        string $userId,
        string $title,
        string $message,
        string $type,
        ?string $link = null,
    ): Notification {
        return Notification::create([
            'id'         => (string) Str::uuid(),
            'user_id'    => $userId,
            'title'      => $title,
            'message'    => $message,
            'type'       => $type,
            'is_read'    => false,
            'link'       => $link,
            'created_at' => now(),
        ]);
    }

    /**
     * Tạo cùng một thông báo cho nhiều user bằng vài câu INSERT thay vì mỗi người một câu.
     *
     * Dùng khi gửi hàng loạt (vd: công bố bài tập cho cả lớp). Lưu ý insert hàng loạt
     * bỏ qua event của Eloquent nên không dùng Notification::create() từng dòng; id
     * và created_at vì thế được tự đặt ở đây.
     *
     * @param array<int, string> $userIds danh sách người nhận (id trùng chỉ nhận một lần)
     * @return int số thông báo đã tạo
     */
    public function createMany(
        array $userIds,
        string $title,
        string $message,
        string $type,
        ?string $link = null,
    ): int {
        $userIds = array_values(array_unique($userIds));

        if ($userIds === []) {
            return 0;
        }

        $createdAt = now();

        $rows = array_map(fn (string $userId) => [
            'id'         => (string) Str::uuid(),
            'user_id'    => $userId,
            'title'      => $title,
            'message'    => $message,
            'type'       => $type,
            'is_read'    => false,
            'link'       => $link,
            'created_at' => $createdAt,
        ], $userIds);

        // Chia nhỏ để một lớp rất đông không vượt giới hạn tham số của một câu INSERT.
        foreach (array_chunk($rows, self::INSERT_CHUNK_SIZE) as $chunk) {
            Notification::insert($chunk);
        }

        return count($rows);
    }

    /**
     * Danh sách thông báo của user, mới nhất trước.
     *
     * @return Collection<int, Notification>
     */
    public function listForUser(string $userId): Collection
    {
        return $this->forUser($userId)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Số thông báo chưa đọc.
     */
    public function unreadCount(string $userId): int
    {
        return $this->forUser($userId)
            ->where('is_read', false)
            ->count();
    }

    /**
     * Đánh dấu một thông báo đã đọc. Gọi lại nhiều lần vẫn an toàn (idempotent).
     */
    public function markRead(string $userId, string $notificationId): Notification
    {
        $notification = $this->findOrFail($userId, $notificationId);

        if (!$notification->is_read) {
            $notification->update(['is_read' => true]);
        }

        return $notification;
    }

    /**
     * Đánh dấu tất cả thông báo của user đã đọc.
     *
     * @return int số thông báo vừa được chuyển sang đã đọc
     */
    public function markAllRead(string $userId): int
    {
        return $this->forUser($userId)
            ->where('is_read', false)
            ->update(['is_read' => true]);
    }

    /**
     * Xóa một thông báo (xóa thật khỏi DB).
     */
    public function delete(string $userId, string $notificationId): void
    {
        $this->findOrFail($userId, $notificationId)->delete();
    }

    /**
     * Xóa tất cả thông báo của user.
     *
     * @return int số thông báo đã xóa
     */
    public function deleteAll(string $userId): int
    {
        return $this->forUser($userId)->delete();
    }
}
