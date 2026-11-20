<?php

namespace App\Services;

use App\Jobs\GradeSubmissionJob;
use App\Jobs\GradeExamSessionJob;
use App\Models\Submission;

class SubmissionQueueService
{
    /**
     * Dispatch submission grading to Redis queue
     */
    public function dispatchPracticeGrading(Submission $submission): void
    {
        GradeSubmissionJob::dispatch($submission->id)->onQueue('grading');
    }

    /**
     * Dispatch comprehensive exam-session grading to Redis queue
     */
    public function dispatchExamSessionGrading(string $examSessionId, string $userId): void
    {
        GradeExamSessionJob::dispatch($examSessionId, $userId)->onQueue('grading');
    }
}
