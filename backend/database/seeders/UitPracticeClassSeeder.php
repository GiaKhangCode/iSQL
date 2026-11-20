<?php

namespace Database\Seeders;

use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * 2 lớp thực hành IT004 (Cơ sở dữ liệu) với sinh viên mẫu, cùng một giảng viên đứng lớp.
 * Port từ SQL-UIT/backend/scripts/seed_uit_classes.py. Sinh viên là HỒ SƠ GIẢ theo định dạng UIT
 * (MSSV 8 chữ số, email MSSV@gm.uit.edu.vn), không phải người thật.
 *
 * Chạy riêng (không nằm trong DatabaseSeeder vì ClassSeeder xóa lớp không thuộc danh sách của nó):
 *   php artisan db:seed --class=Database\\Seeders\\UitPracticeClassSeeder
 *
 * Idempotent: lớp khớp theo id, sinh viên khớp theo email, ghi danh khớp theo (lớp, sinh viên). Không xóa gì,
 * không sửa vai trò/quyền. Mỗi lớp một giảng viên (classes.instructor_id).
 */
class UitPracticeClassSeeder extends Seeder
{
    public const INSTRUCTOR_EMAIL = 'teacher@uit.edu.vn';
    public const DEFAULT_PASSWORD = 'password123';

    private const COURSE = 'Cơ sở dữ liệu';
    private const COURSE_CODE = 'IT004';
    private const THEORY_CLASS = 'IT004.O21';
    private const TERM = 'HK1 2026-2027';
    private const START_DATE = '2026-09-07';
    private const END_DATE = '2027-01-10';

    /** @var array<string, array<int, array{0: string, 1: string}>> lớp thực hành => [[MSSV, họ tên]] */
    private const CLASSES = [
        'IT004.O21.1' => [
            ['24520031', 'Nguyễn Hoàng An'], ['24520118', 'Trần Minh Khôi'], ['24520254', 'Lê Thị Ngọc Anh'],
            ['24520367', 'Phạm Quốc Bảo'], ['24520482', 'Võ Thanh Tâm'], ['24520596', 'Đặng Gia Huy'],
            ['24520613', 'Bùi Thị Thu Hà'], ['24520745', 'Hoàng Đức Thịnh'], ['24520829', 'Phan Khánh Linh'],
            ['24520937', 'Đỗ Nhật Nam'], ['24521046', 'Huỳnh Bảo Châu'], ['24521152', 'Ngô Tuấn Kiệt'],
        ],
        'IT004.O21.2' => [
            ['24521204', 'Dương Thị Mai Phương'], ['24521288', 'Lý Gia Bảo'], ['24521331', 'Trịnh Anh Thư'],
            ['24521417', 'Vũ Hải Đăng'], ['24521523', 'Đinh Ngọc Trâm'], ['24521609', 'Lâm Quang Vinh'],
            ['24521674', 'Mai Thị Hồng Nhung'], ['24521758', 'Cao Minh Quân'], ['24521846', 'Tô Yến Nhi'],
            ['24521921', 'Châu Thế Vĩ'], ['24521987', 'Hồ Thảo Vy'], ['24521995', 'Nguyễn Trọng Nghĩa'],
        ],
    ];

    public function run(): void
    {
        $instructor = User::where('email', self::INSTRUCTOR_EMAIL)->where('status', 'Active')->first();
        if ($instructor === null) {
            throw new RuntimeException('Không tìm thấy giảng viên đang hoạt động ' . self::INSTRUCTOR_EMAIL . '; chạy UserSeeder trước.');
        }

        $stats = ['lớp' => [0, 0], 'sinh viên' => [0, 0], 'ghi danh' => [0, 0]];

        DB::transaction(function () use ($instructor, &$stats): void {
            foreach (self::CLASSES as $classId => $members) {
                $values = [
                    'course'         => self::COURSE . ' - ' . self::THEORY_CLASS,
                    'course_code'    => self::COURSE_CODE,
                    'theory_class'   => self::THEORY_CLASS,
                    'practice_class' => $classId,
                    'term'           => self::TERM,
                    'instructor_id'  => $instructor->id,
                    'start_date'     => self::START_DATE,
                    'end_date'       => self::END_DATE,
                    'status'         => 'Active',
                ];

                $class = ClassModel::find($classId);
                if ($class === null) {
                    ClassModel::create(['id' => $classId] + $values);
                    $stats['lớp'][0]++;
                } else {
                    $class->update($values);
                    $stats['lớp'][1]++;
                }

                foreach ($members as [$mssv, $name]) {
                    $email = "{$mssv}@gm.uit.edu.vn";
                    $student = User::where('email', $email)->first();
                    if ($student === null) {
                        $student = User::create([
                            'name'            => $name,
                            'email'           => $email,
                            'initials'        => $this->initials($name),
                            'hashed_password' => Hash::make(self::DEFAULT_PASSWORD),
                            'account_type'    => User::ACCOUNT_STUDENT,
                            'status'          => 'Active',
                            'department'      => '',
                        ]);
                        $stats['sinh viên'][0]++;
                    } elseif ($student->account_type !== User::ACCOUNT_STUDENT) {
                        throw new RuntimeException("{$email} đã tồn tại nhưng không phải tài khoản sinh viên; dừng để không ghi đè.");
                    } else {
                        $stats['sinh viên'][1]++;
                    }

                    $enrolled = ClassEnrollment::where('class_id', $classId)->where('student_id', $student->id)->exists();
                    if (! $enrolled) {
                        ClassEnrollment::create([
                            'class_id'   => $classId,
                            'student_id' => $student->id,
                            'role'       => 'Member',
                            'joined_at'  => now(),
                        ]);
                        $stats['ghi danh'][0]++;
                    } else {
                        $stats['ghi danh'][1]++;
                    }
                }
            }
        });

        $this->command?->info("Giảng viên: {$instructor->name} <{$instructor->email}>");
        foreach ($stats as $label => [$created, $existed]) {
            $this->command?->info("  {$label}: tạo {$created}, đã có {$existed}");
        }
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name)) ?: [];
        $first = mb_substr($parts[0] ?? '', 0, 1);
        $last = count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1) : '';

        return mb_strtoupper($first . $last);
    }
}
