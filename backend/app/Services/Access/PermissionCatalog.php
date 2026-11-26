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
        'practice.solve'         => 'Giải và nộp bài trong thư viện bài luyện tập (tab Tổng quan của sinh viên).',
        'assignment.do'          => 'Xem và làm bài tập, ca thi được giao (tab Bài tập của tôi).',
        'submission.view'        => 'Xem lịch sử bài nộp của chính mình (tab Bài nộp của sinh viên).',
        'problem.create'         => 'Tạo bài toán mới; sửa và xóa bài toán của mình.',
        'tag.manage'             => 'Tạo, sửa, xóa thẻ câu hỏi (dạng bài, độ khó) dùng khi soạn bài.',
        'library.manage'         => 'Tạo, sửa, xóa cơ sở dữ liệu mẫu trong thư viện.',
        'assignment.create'      => 'Tạo, sửa, xóa bài tập và giao cho lớp hoặc sinh viên (tab Quản lý bài tập).',
        'examination.manage'     => 'Lập kỳ thi chính thức, chia ca, gán lớp và phòng thi (tab Kỳ thi).',
        'exam.credentials'       => 'Cấp và xuất tài khoản thi tạm cho sinh viên.',
        'exam.proctor'           => 'Coi thi mọi lớp trong ca thi được phân công (tab Coi thi).',
        'results.grade'          => 'Chấm bài, công bố điểm và xử lý phúc khảo bài của sinh viên được giao (tab Chấm bài).',
        'results.view_all'       => 'Xem bài làm và thống kê của mọi lớp (không chấm điểm).',
        'statistics.view'        => 'Xem thống kê các lớp mình phụ trách (tab Thống kê lớp).',
        'results.regrade'        => 'Được phân công và chấm phúc khảo (tab Phúc khảo của giảng viên).',
        'exam.results'           => 'Xem kết quả các kỳ thi (tab Kết quả thi).',
        'statistics.faculty'     => 'Xem thống kê toàn khoa (tab Thống kê khoa).',
        'system.overview'        => 'Xem trang Tổng quan quản trị (tình trạng dịch vụ, việc cần xử lý, nhật ký).',
        'sandbox.cleanup'        => 'Dọn schema bài làm của sinh viên trên SQL Server khi kết thúc học phần (trang Tổng quan quản trị).',
        'class.manage'           => 'Tạo lớp, thêm sinh viên vào lớp và phân công giảng viên.',
        'class.teach'            => 'Được phân công giảng dạy lớp (lý thuyết hoặc thực hành).',
        'student.reset_password' => 'Đặt lại mật khẩu sinh viên trong lớp mình dạy.',
        'user.manage'            => 'Tạo, sửa, duyệt và khóa tài khoản người dùng.',
        'access.manage'          => 'Tạo vai trò, sửa quyền và gán vai trò cho mọi người dùng.',
    ];

    public static function codes(): array
    {
        return array_keys(self::PERMISSIONS);
    }

    public static function isValid(string $code): bool
    {
        return array_key_exists($code, self::PERMISSIONS);
    }
}
