<?php

namespace Tests\Unit;

use Database\Seeders\UitProblemSeeder;
use PHPUnit\Framework\TestCase;

/**
 * Nội dung 4 bài toán seed (QLBH, QLGV, QLPT, QLKB). Chỉ đọc file dữ liệu, không chạm DB: UitProblemSeeder::run()
 * ghi vào các bài có số QLBH/QLGV/QLPT/QLKB nên không chạy trong test. Đáp án đã được chạy thử trên sandbox
 * (rollback) lúc viết seeder.
 */
class UitProblemSeederDefinitionsTest extends TestCase
{
    /** Các thẻ `category` mặc định của exercise_tags (ExerciseTagService::seedDefaults), theo phần. */
    private const TAGS = [
        'DDL'     => ['DDL - Tạo bảng (CREATE TABLE)', 'DDL - Thay đổi cấu trúc (ALTER TABLE)', 'DDL - Ràng buộc CHECK', 'DDL - Khóa ngoại (FOREIGN KEY)'],
        'DML'     => ['DML - Thêm dòng dữ liệu (INSERT)', 'DML - Cập nhật dữ liệu (UPDATE)', 'DML - Xóa dòng dữ liệu (DELETE)', 'DML - Sao chép bảng (SELECT INTO)'],
        'SQL'     => [
            'DQL - Truy vấn cơ bản (SELECT/WHERE)', 'DQL - Kết nối nhiều bảng (JOIN)', 'DQL - Truy vấn con (SUBQUERY)',
            'DQL - Gom nhóm & Tổng hợp (GROUP BY/HAVING)', 'DQL - Phép toán tập hợp (UNION, INTERSECT, EXCEPT)',
            'DQL - Phép chia (DIVISION)', 'DQL - Top N và xếp hạng (TOP, DENSE_RANK)',
        ],
        'TRIGGER' => ['Trigger - Ràng buộc toàn vẹn liên bảng', 'Trigger - Kiểm tra điều kiện dữ liệu'],
    ];

    private const COUNTS = [
        'qlbh' => ['ddl' => 9, 'dml' => 5, 'trigger' => 2, 'sql' => 44],
        'qlgv' => ['ddl' => 15, 'dml' => 4, 'trigger' => 1, 'sql' => 35],
        'qlpt' => ['ddl' => 9, 'dml' => 0, 'trigger' => 4, 'sql' => 20],
        'qlkb' => ['ddl' => 5, 'dml' => 0, 'trigger' => 2, 'sql' => 10],
    ];

    public function test_four_problems_with_the_approved_number_of_questions_per_part(): void
    {
        $defs = UitProblemSeeder::definitions();

        $this->assertSame(array_keys(self::COUNTS), array_keys($defs));
        foreach (self::COUNTS as $key => $counts) {
            $this->assertSame(strtoupper($key), $defs[$key]['number']);
            foreach ($counts as $part => $n) {
                $this->assertCount($n, $defs[$key][$part], "{$key}: số câu {$part}");
            }
        }
    }

    public function test_every_category_is_an_existing_exercise_tag_of_the_right_part(): void
    {
        foreach (UitProblemSeeder::definitions() as $key => $def) {
            foreach (['ddl' => 'DDL', 'dml' => 'DML', 'trigger' => 'TRIGGER', 'sql' => 'SQL'] as $field => $part) {
                foreach ($def[$field] as $q) {
                    $this->assertContains($q['category'], self::TAGS[$part], "{$key} {$q['id']}");
                    $this->assertContains($q['difficulty'], ['Dễ', 'Trung bình', 'Khó'], "{$key} {$q['id']}");
                }
            }
        }
    }

    public function test_ids_are_unique_within_a_part_and_fit_the_request_limits(): void
    {
        foreach (UitProblemSeeder::definitions() as $key => $def) {
            foreach (['ddl', 'dml', 'trigger', 'sql'] as $field) {
                $ids = array_column($def[$field], 'id');
                $this->assertSame($ids, array_values(array_unique($ids)), "{$key}: id trùng trong {$field}");
                foreach ($def[$field] as $q) {
                    $this->assertLessThanOrEqual(50, mb_strlen($q['id']));
                    $this->assertLessThanOrEqual(120, mb_strlen($q['name']));
                    $this->assertNotSame('', trim($field === 'sql' ? $q['query'] : $q['answer']), "{$key} {$q['id']}: thiếu đáp án");
                }
            }
        }
    }

    public function test_trigger_questions_carry_probes_and_start_from_the_loaded_database(): void
    {
        foreach (UitProblemSeeder::definitions() as $key => $def) {
            foreach ($def['trigger'] as $q) {
                $this->assertNotEmpty($q['probes'], "{$key} {$q['id']}");
                $this->assertSame('provided', $q['startFrom'], "{$key} {$q['id']}");
            }
        }
    }
}
