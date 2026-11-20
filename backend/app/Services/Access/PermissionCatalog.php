<?php

namespace App\Services\Access;

/**
 * Danh mục mã quyền (capability) mà hệ thống thực sự kiểm tra.
 *
 * Quyền chỉ trả lời "được làm loại việc gì"; phạm vi ("lớp nào", "bài của ai") là luật theo
 * quan hệ dữ liệu (Policy) và không nằm ở đây.
 */
final class PermissionCatalog
{
    /** mã quyền => mô tả (frontend hiển thị ở tab Vai trò). */
    public const PERMISSIONS = [
        'practice.solve'         => 'Giải và nộp bài trong thư viện bài luyện tập.',
        'problem.create'         => 'Tạo bài toán mới; sửa và xóa bài toán của mình.',
        'problem.share'          => 'Chia sẻ bài toán của mình cho giảng viên khác.',
        'library.manage'         => 'Tạo, sửa, xóa cơ sở dữ liệu mẫu trong thư viện.',
        'library.share'          => 'Chia sẻ cơ sở dữ liệu mẫu cho giảng viên khác.',
        'assignment.create'      => 'Tạo, sửa, xóa bài tập và giao cho lớp hoặc sinh viên.',
        'examination.manage'     => 'Lập kỳ thi chính thức, chia ca, gán lớp và phòng thi.',
        'exam.credentials'       => 'Cấp và xuất tài khoản thi tạm cho sinh viên.',
        'exam.proctor'           => 'Coi thi mọi lớp trong ca thi được phân công.',
        'results.grade'          => 'Chấm bài, công bố điểm và xử lý phúc khảo bài của sinh viên được giao.',
        'results.view_all'       => 'Xem bài làm và thống kê của mọi lớp (không chấm điểm).',
        'class.manage'           => 'Tạo lớp, thêm sinh viên vào lớp và phân công giảng viên.',
        'class.teach'            => 'Được phân công giảng dạy lớp (lý thuyết hoặc thực hành).',
        'student.reset_password' => 'Đặt lại mật khẩu sinh viên trong lớp mình dạy.',
        'user.manage'            => 'Tạo, sửa, duyệt và khóa tài khoản người dùng.',
        'access.manage'          => 'Tạo vai trò, sửa quyền và gán vai trò cho mọi người dùng.',
    ];

    /**
     * Siêu quản trị bỏ qua phạm vi khi xem và quản lý, nhưng không chấm bài và không tự động là giảng viên
     * (không hiện trong danh sách phân công). Hai mã này vẫn giữ nếu đến từ một vai trò khác hoặc gán riêng.
     */
    public const NEVER_FOR_SUPER = ['results.grade', 'class.teach'];

    /**
     * Khu vực giao diện KHÔNG do admin đặt mà suy ra từ quyền: có ít nhất một mã trong nhóm thì vào được khu vực đó.
     */
    public const AREA_GRANTS = [
        'admin'   => ['access.manage', 'user.manage', 'class.manage'],
        'office'  => ['examination.manage', 'exam.credentials'],
        'teacher' => ['problem.create', 'problem.share', 'library.manage', 'library.share', 'results.view_all'],
    ];

    /**
     * Khu vực người dùng được vào, suy từ tập quyền hiệu lực.
     *
     * - Không có ngoại lệ cho siêu quản trị: họ có đủ quyền nên vào được mọi khu vực mà quyền đó mở ra.
     * - Ngoài nhóm AREA_GRANTS: quyền còn lại (tạo bài tập, chấm bài, coi thi...) thuộc các trang của khu vực
     *   giảng viên; người chỉ có practice.solve ở khu vực sinh viên; không có quyền nào thì không có khu vực.
     *
     * @param  string[]  $permissions
     * @return string[]  đã sắp xếp theo tên
     */
    public static function areasFor(array $permissions): array
    {
        $areas = [];
        foreach (self::AREA_GRANTS as $area => $codes) {
            if (array_intersect($codes, $permissions)) {
                $areas[] = $area;
            }
        }

        if (! $areas) {
            $others = array_diff($permissions, ['practice.solve']);
            if ($others) {
                $areas[] = 'teacher';
            } elseif (in_array('practice.solve', $permissions, true)) {
                $areas[] = 'student';
            }
        }

        sort($areas);

        return $areas;
    }

    public static function codes(): array
    {
        return array_keys(self::PERMISSIONS);
    }

    public static function isValid(string $code): bool
    {
        return array_key_exists($code, self::PERMISSIONS);
    }

    /** Quyền của siêu quản trị: mọi mã trừ những mã không dành cho họ. */
    public static function superCodes(): array
    {
        return array_values(array_diff(self::codes(), self::NEVER_FOR_SUPER));
    }
}
