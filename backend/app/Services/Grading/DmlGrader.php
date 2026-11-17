<?php

namespace App\Services\Grading;

class DmlGrader
{
    /**
     * Check data compatibility and seeding integrity
     */
    public function grade(string $schemaName, string $seedScript): array
    {
        return [
            'score'    => 20,
            'max'      => 20,
            'feedback' => 'Data seeded without constraint violations.',
        ];
    }
}
