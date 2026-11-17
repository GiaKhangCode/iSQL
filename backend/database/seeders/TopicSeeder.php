<?php

namespace Database\Seeders;

use App\Models\Topic;
use Illuminate\Database\Seeder;

class TopicSeeder extends Seeder
{
    public function run(): void
    {
        $topics = [
            'Basic SELECT',
            'WHERE & Operators',
            'ORDER BY & GROUP BY',
            'Aggregate Functions',
            'JOIN Queries',
            'Subqueries',
            'DDL & Schema Design',
            'DML Operations',
            'Constraints & Indexes',
        ];

        foreach ($topics as $topicName) {
            Topic::firstOrCreate(['name' => $topicName]);
        }
    }
}
