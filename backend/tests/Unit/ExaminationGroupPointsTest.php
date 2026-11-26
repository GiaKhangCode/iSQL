<?php

namespace Tests\Unit;

use App\Services\ExaminationService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Điểm của kỳ thi nhập theo nhóm câu hỏi (điểm mỗi câu) và theo câu chung, tra bằng `groups` của mã đề.
 * Không đụng DB: hàm chỉ đọc mảng cấu hình.
 */
class ExaminationGroupPointsTest extends TestCase
{
    private function points(array $raw, array $ids, array $groupOf, string $part = 'SQL', mixed $rule = null): array
    {
        $service = (new \ReflectionClass(ExaminationService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($service, 'pointsForQuestions');
        $method->setAccessible(true);

        return $method->invoke($service, $raw, $rule ?? $ids, $part, $ids, $groupOf);
    }

    public function test_each_question_gets_the_points_of_its_group(): void
    {
        $raw = ['_autoRules' => ['SQL' => [['id' => 'g1', 'points' => '3'], ['id' => 'g2', 'points' => 4]]]];

        $this->assertEquals(['a' => 3.0, 'b' => 4.0], $this->points($raw, ['a', 'b'], ['a' => 'g1', 'b' => 'g2']));
    }

    public function test_shared_question_uses_its_own_points_before_any_group(): void
    {
        $raw = [
            '_autoRules'   => ['SQL' => [['id' => 'g1', 'points' => 3]]],
            '_sharedRules' => ['SQL' => [['id' => 's1', 'questionId' => 'c', 'points' => '1,5']]],
        ];

        $this->assertEquals(['a' => 3.0], $this->points($raw, ['a', 'c'], ['a' => 'g1']), 'điểm viết dấu phẩy không hợp lệ thì bỏ qua');

        $raw['_sharedRules']['SQL'][0]['points'] = 1.5;
        $this->assertEquals(['a' => 3.0, 'c' => 1.5], $this->points($raw, ['a', 'c'], ['a' => 'g1', 'c' => 'g1']));
    }

    public function test_questions_without_a_group_or_points_are_left_out_and_per_question_points_still_work(): void
    {
        $raw = [
            '_autoRules'      => ['SQL' => [['id' => 'g1', 'points' => 0], ['id' => 'g2']]],
            '_questionPoints' => ['SQL' => ['x' => 2, 'y' => -1]],
        ];

        $this->assertEquals(['x' => 2.0], $this->points($raw, ['a', 'b', 'x', 'y'], ['a' => 'g1', 'b' => 'g2']));
        $this->assertEquals([], $this->points([], ['a'], []));
    }
}
