<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GradeSubmissionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $submissionId;

    public function __construct(string $submissionId)
    {
        $this->submissionId = $submissionId;
    }

    public function handle(): void
    {
        // Grading logic using SqlQueryGrader
    }
}
