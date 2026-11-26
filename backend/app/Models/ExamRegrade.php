<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Phân công chấm phúc khảo cho cả bài thi: một dòng cho mỗi (ca thi, sinh viên).
 * Mỗi bài thi chỉ có một bài toán và một lần nộp toàn bài, nên phúc khảo luôn trỏ tới đúng một bài nộp chính thức.
 */
class ExamRegrade extends Model
{
    use HasUuid;

    public $timestamps = false;

    protected $fillable = [
        'id',
        'activity_id',
        'student_id',
        'assigned_instructor_id',
        'assigned_by_id',
        'note',
        'previous_evaluated_score',
        'previous_feedback',
        'assigned_at',
        'completed_at',
    ];

    protected $casts = [
        'previous_evaluated_score' => 'float',
        'assigned_at'              => 'datetime',
        'completed_at'             => 'datetime',
    ];

    /** Phúc khảo của bài thi chứa bài nộp này (cùng ca thi, cùng sinh viên), nếu có. */
    public static function forSubmission(Submission $sub): ?self
    {
        if (empty($sub->context) || $sub->context === 'Practice') {
            return null;
        }

        return static::where('activity_id', $sub->context)->where('student_id', $sub->user_id)->first();
    }

    /**
     * Phúc khảo của từng bài nộp, khóa theo id bài nộp (bài nộp không thuộc ca thi nào thì không có mặt trong kết quả).
     *
     * @param iterable<Submission> $subs
     * @return Collection<string, self>
     */
    public static function mapForSubmissions(iterable $subs): Collection
    {
        $subs = collect($subs);
        $activityIds = $subs->pluck('context')->filter(fn ($c) => $c && $c !== 'Practice')->unique()->values()->all();
        $studentIds = $subs->pluck('user_id')->filter()->unique()->values()->all();
        if (empty($activityIds) || empty($studentIds)) {
            return collect();
        }

        $rows = static::whereIn('activity_id', $activityIds)
            ->whereIn('student_id', $studentIds)
            ->get()
            ->keyBy(fn (self $row) => $row->activity_id . '|' . $row->student_id);

        $out = collect();
        foreach ($subs as $sub) {
            $row = $rows->get($sub->context . '|' . $sub->user_id);
            if ($row) {
                $out->put((string) $sub->id, $row);
            }
        }

        return $out;
    }
}
