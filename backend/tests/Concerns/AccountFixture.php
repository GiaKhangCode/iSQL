<?php

namespace Tests\Concerns;

/**
 * Thuộc tính tài khoản cho test: loại danh tính (account_type) và vai trò khởi tạo (initial_role).
 *
 * Test vẫn nói theo tên "loại người dùng" quen thuộc (student, instructor, instructor_practical,
 * department_head, faculty_office, admin, hoặc tên lạ như visitor = không có vai trò nào), helper dịch sang
 * cột thật: `User::create([... , ...AccountFixture::for('instructor')])`.
 */
final class AccountFixture
{
    private const SLUGS = [
        'instructor'           => 'instructor',
        'instructor_practical' => 'instructor',
        'instructor_theory'    => 'instructor',
        'department_head'      => 'department_head',
        'faculty_office'       => 'faculty_office',
        'admin'                => 'admin',
    ];

    /**
     * @return array{account_type: string, initial_role?: string}
     *         `student` nhận vai trò "Sinh viên" mặc định; tên lạ (visitor) cho initial_role không tồn tại = không có vai trò.
     */
    public static function for(string $kind): array
    {
        if ($kind === 'student') {
            return ['account_type' => 'student'];
        }

        return ['account_type' => 'staff', 'initial_role' => self::SLUGS[$kind] ?? $kind];
    }
}
