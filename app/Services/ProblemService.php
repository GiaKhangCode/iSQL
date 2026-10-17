<?php

namespace App\Services;

use App\Models\Problem;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ProblemService
{
    /**
     * Get list of problems with topic relations
     */
    public function getAllProblems(bool $onlyPractice = false): Collection
    {
        $query = Problem::with('topicRelations');

        if ($onlyPractice) {
            $query->where('practice_listed', true);
        }

        return $query->get();
    }

    /**
     * Get detailed problem with test cases and scripts
     */
    public function getProblemById(string $id): Problem
    {
        return Problem::with(['topicRelations', 'testCases.scripts'])->findOrFail($id);
    }

    /**
     * Create a new problem with topics and optional test cases
     */
    public function createProblem(array $data, string $creatorId): Problem
    {
        return DB::transaction(function () use ($data, $creatorId) {
            $data['creator_id'] = $creatorId;
            $problem = Problem::create($data);

            if (!empty($data['topic_ids'])) {
                $problem->topicRelations()->sync($data['topic_ids']);
            }

            return $problem->load('topicRelations');
        });
    }

    /**
     * Update existing problem
     */
    public function updateProblem(string $id, array $data): Problem
    {
        return DB::transaction(function () use ($id, $data) {
            $problem = Problem::findOrFail($id);
            $problem->update($data);

            if (isset($data['topic_ids'])) {
                $problem->topicRelations()->sync($data['topic_ids']);
            }

            return $problem->load('topicRelations');
        });
    }

    /**
     * Delete a problem and associated relations
     */
    public function deleteProblem(string $id): void
    {
        DB::transaction(function () use ($id) {
            $problem = Problem::findOrFail($id);
            $problem->topicRelations()->detach();
            $problem->delete();
        });
    }
}
