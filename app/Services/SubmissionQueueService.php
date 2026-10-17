<?php

namespace App\Services;

use App\Jobs\GradeSubmissionJob;
use App\Jobs\GradeContestExamJob;
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
     * Dispatch comprehensive contest grading to Redis queue
     */
    public function dispatchContestGrading(string $contestId, string $userId): void
    {
        GradeContestExamJob::dispatch($contestId, $userId)->onQueue('grading');
    }
}
