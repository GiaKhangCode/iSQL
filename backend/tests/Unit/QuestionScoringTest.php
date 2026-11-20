<?php

namespace Tests\Unit;

use App\Services\Grading\QuestionScoring;
use PHPUnit\Framework\TestCase;

/**
 * Điểm của một lượt làm bài: mỗi câu có trọng số (`questionPoints` trong cấu hình chọn câu), không có thì chia đều.
 * Hàm thuần túy, không đụng DB hay SQL Server.
 */
class QuestionScoringTest extends TestCase
{
    private function q(string $part, string $id, bool $passed): array
    {
        return ['part' => $part, 'id' => $id, 'passed' => $passed];
    }

    public function test_without_any_points_every_question_weighs_the_same(): void
    {
        $questions = [$this->q('DDL', 'a', true), $this->q('SQL', 'b', false), $this->q('SQL', 'c', true), $this->q('TRIGGER', 'd', false)];

        $this->assertEquals(5.0, QuestionScoring::score($questions, null, 10));
        $this->assertEquals(5.0, QuestionScoring::score($questions, ['SQL' => ['questionIds' => ['b', 'c']]], 10));
        $this->assertEquals(2.5, QuestionScoring::score($questions, null, 5));
    }

    public function test_equal_weights_give_exactly_the_old_formula_including_rounding(): void
    {
        $questions = array_map(fn ($i) => $this->q('SQL', "q{$i}", $i <= 7), range(1, 9));

        $this->assertSame(round(10 * (7 / 9), 2), QuestionScoring::score($questions, null, 10));
    }

    public function test_question_points_weigh_each_passed_question(): void
    {
        // Thang của đề thi: câu 1 = 3, hai câu CHECK = 0.5, trigger = 1, năm câu truy vấn = 1 (tổng 10).
        $selection = [
            'DDL'     => ['questionPoints' => ['c1' => 3, 'c2' => 0.5, 'c3' => 0.5]],
            'TRIGGER' => ['questionPoints' => ['t1' => 1]],
            'SQL'     => ['questionPoints' => ['s1' => 1, 's2' => 1, 's3' => 1, 's4' => 1, 's5' => 1]],
        ];
        $attempt = fn (array $failed) => array_map(
            fn ($q) => $this->q($q[0], $q[1], !in_array($q[1], $failed, true)),
            [['DDL', 'c1'], ['DDL', 'c2'], ['DDL', 'c3'], ['TRIGGER', 't1'], ['SQL', 's1'], ['SQL', 's2'], ['SQL', 's3'], ['SQL', 's4'], ['SQL', 's5']]
        );

        $this->assertEquals(10.0, QuestionScoring::score($attempt([]), $selection, 10));
        $this->assertEquals(6.0, QuestionScoring::score($attempt(['c1', 's1']), $selection, 10), 'mất câu 1 (3đ) và một câu truy vấn (1đ)');
        $this->assertEquals(9.0, QuestionScoring::score($attempt(['c2', 'c3']), $selection, 10));
        $this->assertEquals(5.0, QuestionScoring::score($attempt(['c1', 'c2', 'c3', 't1']), $selection, 10), 'chỉ làm đúng 5 câu truy vấn');
    }

    public function test_a_perfect_attempt_is_always_the_maximum_even_when_weights_do_not_add_up(): void
    {
        $questions = [$this->q('SQL', 'a', true), $this->q('SQL', 'b', true)];

        $this->assertEquals(10.0, QuestionScoring::score($questions, ['SQL' => ['questionPoints' => ['a' => 3, 'b' => 3]]], 10), 'tổng điểm câu nhỏ hơn tối đa');
        $this->assertEquals(10.0, QuestionScoring::score($questions, ['SQL' => ['questionPoints' => ['a' => 8, 'b' => 8]]], 10), 'tổng điểm câu lớn hơn tối đa');
    }

    public function test_only_the_failed_weight_is_lost(): void
    {
        $selection = ['SQL' => ['questionPoints' => ['a' => 3, 'b' => 7]]];

        $this->assertEquals(3.0, QuestionScoring::score([$this->q('SQL', 'a', true), $this->q('SQL', 'b', false)], $selection, 10));
        $this->assertEquals(7.0, QuestionScoring::score([$this->q('SQL', 'a', false), $this->q('SQL', 'b', true)], $selection, 10));
        $this->assertEquals(0.0, QuestionScoring::score([$this->q('SQL', 'a', false), $this->q('SQL', 'b', false)], $selection, 10));
    }

    public function test_questions_without_points_share_what_is_left_of_the_maximum(): void
    {
        // a = 4 điểm; b, c, d chia 6 điểm còn lại = 2 điểm một câu.
        $selection = ['SQL' => ['questionPoints' => ['a' => 4]]];
        $questions = fn (bool $a, bool $b, bool $c, bool $d) => [
            $this->q('SQL', 'a', $a), $this->q('SQL', 'b', $b), $this->q('SQL', 'c', $c), $this->q('SQL', 'd', $d),
        ];

        $this->assertEquals(4.0, QuestionScoring::score($questions(true, false, false, false), $selection, 10));
        $this->assertEquals(2.0, QuestionScoring::score($questions(false, true, false, false), $selection, 10));
        $this->assertEquals(8.0, QuestionScoring::score($questions(true, true, true, false), $selection, 10));
    }

    public function test_questions_without_points_get_nothing_when_the_listed_points_already_use_the_maximum(): void
    {
        $selection = ['SQL' => ['questionPoints' => ['a' => 10]]];

        $this->assertEquals(0.0, QuestionScoring::score([$this->q('SQL', 'a', false), $this->q('SQL', 'b', true)], $selection, 10));
        $this->assertEquals(10.0, QuestionScoring::score([$this->q('SQL', 'a', true), $this->q('SQL', 'b', false)], $selection, 10));
    }

    public function test_points_are_looked_up_per_part_so_equal_ids_in_different_parts_do_not_clash(): void
    {
        $selection = ['DDL' => ['questionPoints' => ['x' => 9]], 'SQL' => ['questionPoints' => ['x' => 1]]];
        $questions = [$this->q('DDL', 'x', false), $this->q('SQL', 'x', true)];

        $this->assertEquals(1.0, QuestionScoring::score($questions, $selection, 10));
    }

    public function test_invalid_points_are_ignored_like_missing_ones(): void
    {
        $selection = ['SQL' => ['questionPoints' => ['a' => 0, 'b' => -2, 'c' => 'abc', 'd' => null]]];
        $questions = [$this->q('SQL', 'a', true), $this->q('SQL', 'b', false), $this->q('SQL', 'c', false), $this->q('SQL', 'd', false)];

        $this->assertEquals(2.5, QuestionScoring::score($questions, $selection, 10));
    }

    public function test_no_questions_scores_zero(): void
    {
        $this->assertEquals(0.0, QuestionScoring::score([], null, 10));
        $this->assertEquals(0.0, QuestionScoring::score([], ['SQL' => ['questionPoints' => ['a' => 5]]], 10));
    }

    public function test_the_result_is_rounded_to_two_decimals(): void
    {
        $selection = ['SQL' => ['questionPoints' => ['a' => 1, 'b' => 2]]];

        $this->assertSame(3.33, QuestionScoring::score([$this->q('SQL', 'a', false), $this->q('SQL', 'b', true)], $selection, 5));
    }
}
