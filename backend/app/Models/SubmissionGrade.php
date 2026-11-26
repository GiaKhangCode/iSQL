<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * Bản chấm của một người chấm cho một bài nộp: `initial` (chấm lần đầu) hoặc `regrade` (phúc khảo).
 * Hai bản độc lập nhau, xem migration create_submission_grades_table.
 */
class SubmissionGrade extends Model
{
    use HasUuid;

    public const INITIAL = 'initial';
    public const REGRADE = 'regrade';

    public $timestamps = false;

    protected $fillable = [
        'id',
        'submission_id',
        'kind',
        'grader_id',
        'score',
        'feedback',
        'question_scores',
        'question_feedback',
        'graded_at',
    ];

    protected $casts = [
        'score'           => 'float',
        'question_scores' => 'array',
        'question_feedback' => 'array',
        'graded_at'       => 'datetime',
    ];

    public function grader()
    {
        return $this->belongsTo(User::class, 'grader_id');
    }
}
