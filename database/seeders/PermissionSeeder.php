<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\User;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Tạo các Chức năng hệ thống (functions)
        $functions = [
            ['name' => 'Quản lý người dùng', 'code' => 'USER_MGMT', 'description' => 'Xem danh sách, thêm, sửa, khóa tài khoản'],
            ['name' => 'Quản lý phân quyền', 'code' => 'PERMISSION_MGMT', 'description' => 'Cấu hình chức năng, nhóm quyền và gán quyền'],
            ['name' => 'Quản lý bài tập SQL', 'code' => 'PROBLEM_MGMT', 'description' => 'Tạo mới, chỉnh sửa, xóa bài tập thực hành SQL'],
            ['name' => 'Quản lý lớp học', 'code' => 'CLASS_MGMT', 'description' => 'Tạo lớp, phân công giảng viên và sinh viên'],
            ['name' => 'Quản lý bài thi & Đánh giá', 'code' => 'ASSIGNMENT_MGMT', 'description' => 'Tạo bài kiểm tra, chấm bài và phúc khảo'],
            ['name' => 'Luyện tập SQL', 'code' => 'PRACTICE_MGMT', 'description' => 'Thực hành giải bài tập và nộp bài'],
        ];

        $functionIds = [];
        foreach ($functions as $f) {
            $func = DB::table('functions')->where('code', $f['code'])->first();
            if (!$func) {
                $id = DB::table('functions')->insertGetId(array_merge($f, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
                $functionIds[$f['code']] = $id;
            } else {
                $functionIds[$f['code']] = $func->id;
            }
        }

        // 2. Tạo các Quyền chi tiết (roles) theo từng chức năng
        $rolesData = [
            // USER_MGMT
            ['function_id' => $functionIds['USER_MGMT'], 'name' => 'Toàn quyền người dùng', 'add' => 1, 'edit' => 1, 'delete' => 1, 'download' => 1, 'view' => 1],
            ['function_id' => $functionIds['USER_MGMT'], 'name' => 'Chỉ xem danh sách người dùng', 'add' => 0, 'edit' => 0, 'delete' => 0, 'download' => 0, 'view' => 1],

            // PERMISSION_MGMT
            ['function_id' => $functionIds['PERMISSION_MGMT'], 'name' => 'Toàn quyền cấu hình phân quyền', 'add' => 1, 'edit' => 1, 'delete' => 1, 'download' => 1, 'view' => 1],

            // PROBLEM_MGMT
            ['function_id' => $functionIds['PROBLEM_MGMT'], 'name' => 'Toàn quyền bài tập SQL', 'add' => 1, 'edit' => 1, 'delete' => 1, 'download' => 1, 'view' => 1],
            ['function_id' => $functionIds['PROBLEM_MGMT'], 'name' => 'Chỉ xem kho bài tập', 'add' => 0, 'edit' => 0, 'delete' => 0, 'download' => 1, 'view' => 1],

            // CLASS_MGMT
            ['function_id' => $functionIds['CLASS_MGMT'], 'name' => 'Toàn quyền lớp học', 'add' => 1, 'edit' => 1, 'delete' => 1, 'download' => 1, 'view' => 1],
            ['function_id' => $functionIds['CLASS_MGMT'], 'name' => 'Chỉ xem lớp học', 'add' => 0, 'edit' => 0, 'delete' => 0, 'download' => 0, 'view' => 1],

            // ASSIGNMENT_MGMT
            ['function_id' => $functionIds['ASSIGNMENT_MGMT'], 'name' => 'Toàn quyền bài thi & chấm điểm', 'add' => 1, 'edit' => 1, 'delete' => 1, 'download' => 1, 'view' => 1],

            // PRACTICE_MGMT
            ['function_id' => $functionIds['PRACTICE_MGMT'], 'name' => 'Quyền luyện tập & nộp bài', 'add' => 1, 'edit' => 0, 'delete' => 0, 'download' => 0, 'view' => 1],
        ];

        $roleIds = [];
        foreach ($rolesData as $r) {
            $existing = DB::table('roles')->where('function_id', $r['function_id'])->where('name', $r['name'])->first();
            if (!$existing) {
                $id = DB::table('roles')->insertGetId(array_merge($r, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
                $roleIds[$r['name']] = $id;
            } else {
                $roleIds[$r['name']] = $existing->id;
            }
        }

        // 3. Tạo Nhóm quyền (role_groups)
        $groups = [
            ['name' => 'Admin (Quản trị viên)', 'description' => 'Toàn quyền trên tất cả các tính năng hệ thống'],
            ['name' => 'Giảng viên', 'description' => 'Quản lý lớp học, biên soạn bài tập và chấm thi'],
            ['name' => 'Sinh viên', 'description' => 'Luyện tập, nộp bài tập và xem kết quả'],
        ];

        $groupIds = [];
        foreach ($groups as $g) {
            $existing = DB::table('role_groups')->where('name', $g['name'])->first();
            if (!$existing) {
                $id = DB::table('role_groups')->insertGetId(array_merge($g, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
                $groupIds[$g['name']] = $id;
            } else {
                $groupIds[$g['name']] = $existing->id;
            }
        }

        // 4. Gán quyền vào nhóm quyền (role_group_assign_role)
        // Admin: có tất cả các quyền Toàn quyền
        $adminRoles = [
            $roleIds['Toàn quyền người dùng'],
            $roleIds['Toàn quyền cấu hình phân quyền'],
            $roleIds['Toàn quyền bài tập SQL'],
            $roleIds['Toàn quyền lớp học'],
            $roleIds['Toàn quyền bài thi & chấm điểm'],
            $roleIds['Quyền luyện tập & nộp bài'],
        ];

        foreach ($adminRoles as $rid) {
            DB::table('role_group_assign_role')->updateOrInsert(
                ['role_group_id' => $groupIds['Admin (Quản trị viên)'], 'role_id' => $rid],
                ['created_at' => now(), 'updated_at' => now()]
            );
        }

        // Giảng viên: Bài tập, lớp học, bài thi
        $lecturerRoles = [
            $roleIds['Chỉ xem danh sách người dùng'],
            $roleIds['Toàn quyền bài tập SQL'],
            $roleIds['Toàn quyền lớp học'],
            $roleIds['Toàn quyền bài thi & chấm điểm'],
        ];

        foreach ($lecturerRoles as $rid) {
            DB::table('role_group_assign_role')->updateOrInsert(
                ['role_group_id' => $groupIds['Giảng viên'], 'role_id' => $rid],
                ['created_at' => now(), 'updated_at' => now()]
            );
        }

        // Sinh viên: Luyện tập, nộp bài, xem lớp học
        $studentRoles = [
            $roleIds['Quyền luyện tập & nộp bài'],
            $roleIds['Chỉ xem lớp học'],
        ];

        foreach ($studentRoles as $rid) {
            DB::table('role_group_assign_role')->updateOrInsert(
                ['role_group_id' => $groupIds['Sinh viên'], 'role_id' => $rid],
                ['created_at' => now(), 'updated_at' => now()]
            );
        }

        // 5. Gán nhóm cho các tài khoản demo hiện có
        // Admin
        $adminUsers = User::where('role', 'admin')->get();
        foreach ($adminUsers as $u) {
            DB::table('account_assign_role_group')->updateOrInsert(
                ['account_id' => $u->id, 'role_group_id' => $groupIds['Admin (Quản trị viên)']],
                ['created_at' => now(), 'updated_at' => now()]
            );
        }

        // Giảng viên
        $lecturerUsers = User::whereIn('role', User::INSTRUCTOR_ROLES)->orWhere('role', 'instructor')->get();
        foreach ($lecturerUsers as $u) {
            DB::table('account_assign_role_group')->updateOrInsert(
                ['account_id' => $u->id, 'role_group_id' => $groupIds['Giảng viên']],
                ['created_at' => now(), 'updated_at' => now()]
            );
        }

        // Sinh viên
        $studentUsers = User::where('role', 'student')->get();
        foreach ($studentUsers as $u) {
            DB::table('account_assign_role_group')->updateOrInsert(
                ['account_id' => $u->id, 'role_group_id' => $groupIds['Sinh viên']],
                ['created_at' => now(), 'updated_at' => now()]
            );
        }
    }
}
