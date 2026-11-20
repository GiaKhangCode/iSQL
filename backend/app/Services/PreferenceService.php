<?php

namespace App\Services;

use App\Models\Favorite;
use App\Models\Problem;
use App\Models\ProblemList;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Tùy chọn cá nhân của sinh viên: bài toán yêu thích và danh sách bài toán tự tạo.
 *
 * Mọi hàm đều nhận $userId và chỉ thao tác trên dữ liệu của chính user đó.
 */
class PreferenceService
{
    /**
     * @return array{favorites: array<int, string>, custom_lists: \Illuminate\Database\Eloquent\Collection<int, ProblemList>}
     *         favorites là mảng id bài toán (đúng với cách frontend dùng: favorites.includes(problemId));
     *         custom_lists là các danh sách tự tạo kèm bài toán bên trong.
     */
    public function getPreferences(string $userId): array
    {
        $favoriteProblemIds = Favorite::where('user_id', $userId)
            ->orderBy('created_at')
            ->pluck('problem_id')
            ->all();

        $customLists = ProblemList::where('user_id', $userId)
            ->with('problems')
            ->get();

        return [
            'favorites'    => $favoriteProblemIds,
            'custom_lists' => $customLists,
        ];
    }

    /**
     * Bật/tắt yêu thích một bài toán: chưa yêu thích thì thêm, đã yêu thích thì bỏ.
     *
     * @return array{favorited: bool, status: string} status là "added" hoặc "removed"
     *         (frontend đọc status, favorited giữ lại để tương thích với client cũ).
     */
    public function toggleFavorite(string $userId, string $problemId): array
    {
        if (!Problem::whereKey($problemId)->exists()) {
            throw new HttpException(404, 'Không tìm thấy bài toán.');
        }

        // Bảng favorites có khóa chính kép (user_id, problem_id) nên phải xóa bằng câu truy
        // vấn theo hai cột này; $favorite->delete() của Eloquent sẽ tìm theo cột id không có.
        $deleted = Favorite::where('user_id', $userId)
            ->where('problem_id', $problemId)
            ->delete();

        if ($deleted > 0) {
            return ['favorited' => false, 'status' => 'removed'];
        }

        // insertOrIgnore: nếu hai request cùng lúc cùng thêm thì không lỗi trùng khóa chính.
        Favorite::insertOrIgnore([[
            'user_id'    => $userId,
            'problem_id' => $problemId,
            'created_at' => now(),
        ]]);

        return ['favorited' => true, 'status' => 'added'];
    }
}
