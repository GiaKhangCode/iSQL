<?php

namespace Tests\Unit;

use App\Services\Grading\QuestionScoring;
use PHPUnit\Framework\TestCase;

class QuestionMaxScoresTest extends TestCase
{
    private function questions(string ...$ids): array
    {
        return array_map(fn (string $id) => ['part' => 'SQL', 'id' => $id], $ids);
    }

    public function test_equal_split_gives_the_remainder_to_the_last_question(): void
    {
        $this->assertSame([3.33, 3.33, 3.34], QuestionScoring::maxScores($this->questions('a', 'b', 'c'), null, 10.0));
    }

    public function test_configured_points_are_scaled_to_the_problem_points(): void
    {
        $selection = ['SQL' => ['questionPoints' => ['a' => 2, 'b' => 6]]];

        $this->assertSame([2.5, 7.5], QuestionScoring::maxScores($this->questions('a', 'b'), $selection, 10.0));
    }

    public function test_a_question_without_weight_gets_zero(): void
    {
        $selection = ['SQL' => ['questionPoints' => ['a' => 10]]];

        $this->assertSame([10.0, 0.0], QuestionScoring::maxScores($this->questions('a', 'b'), $selection, 10.0));
    }
}
