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

        $weights = self::weights($questions, $selection, $maxPoints);
        $total = array_sum($weights);
        $earned = 0.0;
        foreach ($questions as $i => $q) {
            if (!empty($q['passed'])) {
                $earned += $weights[$i];
            }
        }

        return $total > 0 ? round($maxPoints * ($earned / $total), 2) : 0.0;
    }

    /**
     * Điểm tối đa của từng câu (cùng thứ tự với $questions), làm tròn 2 chữ số; câu cuối cùng có trọng số nhận phần dư
     * nên tổng luôn đúng bằng điểm tối đa của bài (10 điểm chia 3 câu = 3.33, 3.33, 3.34).
     *
     * @param array<int, array{part: string, id: string}> $questions
     * @param array<string, mixed>|null                   $selection
     * @return array<int, float>
     */
    public static function maxScores(array $questions, ?array $selection, float $maxPoints): array
    {
        $weights = self::weights($questions, $selection, $maxPoints);
        $total = array_sum($weights);
        $points = array_map(fn (float $w) => $total > 0 ? round($maxPoints * $w / $total, 2) : 0.0, $weights);

        $weighted = array_keys(array_filter($weights, fn (float $w) => $w > 0));
        if ($weighted !== []) {
            $last = end($weighted);
            $points[$last] = round($maxPoints - (array_sum($points) - $points[$last]), 2);
        }

        return $points;
    }

    /**
     * @param array<int, array{part: string, id: string}> $questions
     * @param array<string, mixed>|null                   $selection
     * @return array<int, float>
     */
    private static function weights(array $questions, ?array $selection, float $maxPoints): array
    {
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

        return array_map(fn (?float $w) => (float) ($w ?? $each), $weights);
    }

    private static function listedPoints(?array $selection, string $part, string $id): ?float
    {
        $raw = $selection[strtoupper($part)]['questionPoints'][$id] ?? null;

        return is_numeric($raw) && (float) $raw > 0 ? (float) $raw : null;
    }
}
