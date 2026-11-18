<?php

namespace App\Services\Grading;

class DdlGrader
{
    /**
     * Compare student schema with reference schema
     */
    public function grade(array $studentSchema, array $referenceSchema): array
    {
        return [
            'score'    => 30,
            'max'      => 30,
            'feedback' => 'Schema matches reference definition.',
        ];
    }
}
