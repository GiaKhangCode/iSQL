<?php

namespace Tests\Unit;

use Database\Seeders\UitExaminationSeeder;
use Database\Seeders\UitProblemSeeder;
use PHPUnit\Framework\TestCase;

/**
 * 4 mã đề của kỳ thi cuối kỳ QLPT. Chỉ đọc định nghĩa, không chạm DB: UitExaminationSeeder::run() ghi kỳ thi thật.
 */
class UitExaminationSeederVariantsTest extends TestCase
{
    /** @return array<string, array> mã câu => câu hỏi, theo từng phần của bài QLPT */
    private function bank(): array
    {
        $qlpt = UitProblemSeeder::definitions()['qlpt'];

        return [
            'DDL'     => array_column($qlpt['ddl'], null, 'id'),
            'TRIGGER' => array_column($qlpt['trigger'], null, 'id'),
            'SQL'     => array_column($qlpt['sql'], null, 'id'),
        ];
    }

    public function test_four_variants_with_the_shape_of_the_exam_paper(): void
    {
        $variants = UitExaminationSeeder::variants();

        $this->assertSame(['1', '2', '3', '4'], array_column($variants, 'code'));
        foreach ($variants as $v) {
            $this->assertSame(['DDL', 'TRIGGER', 'SQL'], array_keys($v['questions']), "mã {$v['code']}");
            $this->assertCount(3, $v['questions']['DDL'], 'câu 1 + 2 CHECK');
            $this->assertCount(1, $v['questions']['TRIGGER']);
            $this->assertCount(5, $v['questions']['SQL']);
        }
    }

    public function test_each_part_is_a_plain_list_of_question_ids_like_the_exam_builder_saves_it(): void
    {
        foreach (UitExaminationSeeder::variants() as $v) {
            foreach ($v['questions'] as $part => $ids) {
                $this->assertTrue(array_is_list($ids), "mã {$v['code']} {$part}: phải là mảng mã câu, giao diện lặp trên nó");
                foreach ($ids as $id) {
                    $this->assertIsString($id);
                }
            }
        }
    }

    public function test_every_question_exists_in_the_qlpt_problem_and_has_the_right_kind(): void
    {
        $bank = $this->bank();
        $tags = [
            'DDL'     => ['DDL - Tạo bảng (CREATE TABLE)', 'DDL - Ràng buộc CHECK', 'DDL - Ràng buộc CHECK'],
            'TRIGGER' => ['Trigger - Ràng buộc toàn vẹn liên bảng'],
            'SQL'     => ['DQL - Kết nối nhiều bảng (JOIN)', 'DQL - Phép toán tập hợp (UNION, INTERSECT, EXCEPT)', 'DQL - Phép chia (DIVISION)', 'DQL - Gom nhóm & Tổng hợp (GROUP BY/HAVING)', 'DQL - Truy vấn con (SUBQUERY)'],
        ];

        foreach (UitExaminationSeeder::variants() as $v) {
            foreach ($v['questions'] as $part => $ids) {
                foreach ($ids as $i => $id) {
                    $this->assertArrayHasKey($id, $bank[$part], "mã {$v['code']}: {$id} không có trong bài QLPT");
                    $this->assertSame($tags[$part][$i], $bank[$part][$id]['category'], "mã {$v['code']} {$id}: dạng câu thứ " . ($i + 1) . " của phần {$part}");
                }
            }
        }
    }

    public function test_every_variant_scores_ten_points_on_the_exam_paper_scale(): void
    {
        $points = UitExaminationSeeder::questionPoints();

        foreach (UitExaminationSeeder::variants() as $v) {
            $total = 0.0;
            foreach ($v['questions'] as $part => $ids) {
                foreach ($ids as $id) {
                    $this->assertArrayHasKey($id, $points[$part], "mã {$v['code']} {$id} chưa có điểm");
                    $total += $points[$part][$id];
                }
            }
            $this->assertEquals(10.0, $total, "mã {$v['code']}");
            $this->assertEquals([3.0, 0.5, 0.5], array_map(fn ($id) => $points['DDL'][$id], $v['questions']['DDL']));
            $this->assertEquals([1.0], array_map(fn ($id) => $points['TRIGGER'][$id], $v['questions']['TRIGGER']));
            $this->assertEquals(array_fill(0, 5, 1.0), array_map(fn ($id) => $points['SQL'][$id], $v['questions']['SQL']));
        }
    }

    public function test_points_only_list_questions_that_are_in_some_variant(): void
    {
        $inVariants = [];
        foreach (UitExaminationSeeder::variants() as $v) {
            foreach ($v['questions'] as $part => $ids) {
                foreach ($ids as $id) {
                    $inVariants[$part][$id] = true;
                }
            }
        }

        foreach (UitExaminationSeeder::questionPoints() as $part => $map) {
            $this->assertEqualsCanonicalizing(array_keys($inVariants[$part]), array_keys($map), $part);
        }
    }

    public function test_variants_differ_from_each_other_and_mix_the_original_papers(): void
    {
        $variants = UitExaminationSeeder::variants();

        $signatures = array_map(fn ($v) => json_encode($v['questions']), $variants);
        $this->assertCount(4, array_unique($signatures), 'bốn mã đề khác nhau');

        $triggers = array_map(fn ($v) => $v['questions']['TRIGGER'][0], $variants);
        $this->assertCount(4, array_unique($triggers), 'mỗi mã một trigger khác nhau');

        foreach ($variants as $v) {
            $papers = [];
            foreach (['DDL', 'TRIGGER', 'SQL'] as $part) {
                foreach ($v['questions'][$part] as $id) {
                    if (preg_match('/^qlpt-(\d{3})-/', $id, $m)) {
                        $papers[$m[1]] = true;
                    }
                }
            }
            $this->assertGreaterThan(1, count($papers), "mã {$v['code']} trộn câu từ nhiều đề gốc");

            $queryPapers = array_unique(array_map(fn ($id) => substr($id, strlen('qlpt-'), 3), $v['questions']['SQL']));
            $this->assertGreaterThan(1, count($queryPapers), "mã {$v['code']}: năm câu truy vấn không thuộc cùng một đề gốc");
        }
    }

    public function test_each_query_slot_covers_all_four_original_papers_across_the_variants(): void
    {
        $variants = UitExaminationSeeder::variants();

        foreach (range(0, 4) as $slot) {
            $papers = array_map(
                fn ($v) => substr($v['questions']['SQL'][$slot], strlen('qlpt-'), 3),
                $variants
            );
            $this->assertEqualsCanonicalizing(['101', '102', '201', '202'], $papers, 'câu truy vấn thứ ' . ($slot + 1));
        }
    }
}
