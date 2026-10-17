<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GradeContestExamJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $contestId;
    public string $userId;

    public function __construct(string $contestId, string $userId)
    {
        $this->contestId = $contestId;
        $this->userId = $userId;
    }

    public function handle(): void
    {
        // Comprehensive Contest Grading logic
    }
}
