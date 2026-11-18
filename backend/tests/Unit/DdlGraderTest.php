<?php

namespace Tests\Unit;

use App\Services\Grading\DdlGrader;
use PHPUnit\Framework\TestCase;

class DdlGraderTest extends TestCase
{
    public function test_ddl_grader_returns_expected_structure(): void
    {
        $grader = new DdlGrader();
        $result = $grader->grade([], []);

        $this->assertArrayHasKey('score', $result);
        $this->assertArrayHasKey('feedback', $result);
    }
}
