<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // Admin Demo
        User::firstOrCreate(
            ['email' => 'admin@uit.edu.vn'],
            [
                'name'            => 'System Administrator',
                'initials'        => 'AD',
                'hashed_password' => Hash::make('password123'),
                'role'            => 'admin',
                'status'          => 'Active',
                'department'      => 'IT Department',
            ]
        );

        // Instructor Demo
        User::firstOrCreate(
            ['email' => 'teacher@uit.edu.vn'],
            [
                'name'            => 'Dr. Nguyen Van A',
                'initials'        => 'NA',
                'hashed_password' => Hash::make('password123'),
                'role'            => 'instructor',
                'status'          => 'Active',
                'department'      => 'Faculty of Software Engineering',
            ]
        );

        // Student Demo
        User::firstOrCreate(
            ['email' => 'student@gm.uit.edu.vn'],
            [
                'name'            => 'Tran Thi B',
                'initials'        => 'TB',
                'hashed_password' => Hash::make('password123'),
                'role'            => 'student',
                'status'          => 'Active',
                'department'      => 'Information Systems',
            ]
        );
    }
}
