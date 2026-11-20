<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            AccessRoleSeeder::class,
            UserSeeder::class,
            ClassSeeder::class,
            TopicSeeder::class,
            TestProblemAndAssignmentSeeder::class,
            DatabaseLibrarySeeder::class,
        ]);
    }
}
