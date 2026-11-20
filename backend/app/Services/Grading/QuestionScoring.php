<?php

namespace App\Services\Grading;

/**
 * Tính điểm của một lượt làm bài từ kết quả từng câu.
 *
 * Mỗi câu có trọng số lấy từ `questionPoints` của phần đó trong cấu hình chọn câu của bài
 * (`$selection[PHẦN]['questionPoints'][mã câu]`, do ExaminationService::studentSelectionForProblem trả về):
 *  - Không câu nào có điểm: mọi câu bằng nhau, điểm = số câu đúng / tổng số câu * điểm tối đa (cách tính cũ).
 *  - Có câu có điểm: câu không ghi điểm chia đều phần còn lại của điểm tối đa (không còn thì được 0).
 * Điểm = điểm tối đa * tổng trọng số câu đúng / tổng trọng số, nên làm đúng hết luôn được đúng điểm tối đa
 * dù các điểm câu cộng lại lớn hơn hay nhỏ hơn điểm tối đa của bài.
 */
final class QuestionScoring
{
    /**
     * @param array<int, array{part: string, id: string, passed: bool}> $questions mọi câu được chấm
     * @param array<string, mixed>|null                                  $selection
     */
    public static function score(array $questions, ?array $selection, float $maxPoints): float
    {
        if ($questions === []) {
            return 0.0;
        }

        $weights = [];
        $listedTotal = 0.0;
        $unlisted = 0;
        foreach ($questions as $i => $q) {
            $listed = self::listedPoints($selection, (string) $q['part'], (string) $q['id']);
            $weights[$i] = $listed;
            if ($listed === null) {
                $unlisted++;
            } else {
                $listedTotal += $listed;
            }
        }

        if ($unlisted === count($questions)) {
            $each = 1.0;
        } else {
            $left = $maxPoints - $listedTotal;
            $each = $unlisted > 0 && $left > 0 ? $left / $unlisted : 0.0;
        }

        $total = 0.0;
        $earned = 0.0;
        foreach ($questions as $i => $q) {
            $w = $weights[$i] ?? $each;
            $total += $w;
            if (!empty($q['passed'])) {
                $earned += $w;
            }
        }

        return $total > 0 ? round($maxPoints * ($earned / $total), 2) : 0.0;
    }

    private static function listedPoints(?array $selection, string $part, string $id): ?float
    {
        $raw = $selection[strtoupper($part)]['questionPoints'][$id] ?? null;

        return is_numeric($raw) && (float) $raw > 0 ? (float) $raw : null;
    }
}
