<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/** Một lần lưu điểm/nhận xét của giảng viên (chỉ thêm), xem migration create_submission_grade_logs_table. */
class SubmissionGradeLog extends Model
{
    use HasUuid;

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
