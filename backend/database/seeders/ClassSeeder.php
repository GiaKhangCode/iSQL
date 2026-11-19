<?php

namespace Database\Seeders;

use App\Models\ClassModel;
use App\Models\ClassEnrollment;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ClassSeeder extends Seeder
{
    public function run(): void
    {
        $access = app(\App\Services\Access\AccessControlService::class);
        $instructors = User::whereIn('id', $access->holdersOf('class.teach'))->where('status', 'Active')->get();
        $inst1 = $instructors->get(0) ?? User::whereIn('id', $access->superAdminIds())->first();
        $inst2 = $instructors->get(1) ?? $inst1;
        $inst3 = $instructors->get(2) ?? $inst1;

        $classSpecs = [
            // Lớp lý thuyết IT004.R11
            [
                'id'                     => 'IT004.R11.1',
                'course'                 => 'Cơ sở dữ liệu - IT004.R11',
                'course_code'            => 'IT004',
                'theory_class'           => 'IT004.R11',
                'practice_class'         => 'IT004.R11.1',
                'term'                   => 'HK1 2026-2027',
                'instructor_id'          => $inst1->id,
                'practice_instructor_id' => $inst2->id,
                'status'                 => 'Active',
                'start_date'             => '2026-09-01',
                'end_date'               => '2026-12-31',
            ],
            [
                'id'                     => 'IT004.R11.2',
                'course'                 => 'Cơ sở dữ liệu - IT004.R11',
                'course_code'            => 'IT004',
                'theory_class'           => 'IT004.R11',
                'practice_class'         => 'IT004.R11.2',
                'term'                   => 'HK1 2026-2027',
                'instructor_id'          => $inst1->id,
                'practice_instructor_id' => $inst3->id,
                'status'                 => 'Active',
                'start_date'             => '2026-09-01',
                'end_date'               => '2026-12-31',
            ],
            // Lớp lý thuyết IT004.R12
            [
                'id'                     => 'IT004.R12.1',
                'course'                 => 'Cơ sở dữ liệu - IT004.R12',
                'course_code'            => 'IT004',
                'theory_class'           => 'IT004.R12',
                'practice_class'         => 'IT004.R12.1',
                'term'                   => 'HK1 2026-2027',
                'instructor_id'          => $inst2->id,
                'practice_instructor_id' => $inst1->id,
                'status'                 => 'Active',
                'start_date'             => '2026-09-01',
                'end_date'               => '2026-12-31',
            ],
            [
                'id'                     => 'IT004.R12.2',
                'course'                 => 'Cơ sở dữ liệu - IT004.R12',
                'course_code'            => 'IT004',
                'theory_class'           => 'IT004.R12',
                'practice_class'         => 'IT004.R12.2',
                'term'                   => 'HK1 2026-2027',
                'instructor_id'          => $inst2->id,
                'practice_instructor_id' => $inst3->id,
                'status'                 => 'Active',
                'start_date'             => '2026-09-01',
                'end_date'               => '2026-12-31',
            ],
            // Lớp lý thuyết IT004.R13
            [
                'id'                     => 'IT004.R13.1',
                'course'                 => 'Cơ sở dữ liệu - IT004.R13',
                'course_code'            => 'IT004',
                'theory_class'           => 'IT004.R13',
                'practice_class'         => 'IT004.R13.1',
                'term'                   => 'HK1 2026-2027',
                'instructor_id'          => $inst3->id,
                'practice_instructor_id' => $inst1->id,
                'status'                 => 'Active',
                'start_date'             => '2026-09-01',
                'end_date'               => '2026-12-31',
            ],
            [
                'id'                     => 'IT004.R13.2',
                'course'                 => 'Cơ sở dữ liệu - IT004.R13',
                'course_code'            => 'IT004',
                'theory_class'           => 'IT004.R13',
                'practice_class'         => 'IT004.R13.2',
                'term'                   => 'HK1 2026-2027',
                'instructor_id'          => $inst3->id,
                'practice_instructor_id' => $inst2->id,
                'status'                 => 'Active',
                'start_date'             => '2026-09-01',
                'end_date'               => '2026-12-31',
            ],
            // Lớp lý thuyết IT004.N11
            [
                'id'                     => 'IT004.N11.1',
                'course'                 => 'Cơ sở dữ liệu - IT004.N11',
                'course_code'            => 'IT004',
                'theory_class'           => 'IT004.N11',
                'practice_class'         => 'IT004.N11.1',
                'term'                   => 'HK1 2026-2027',
                'instructor_id'          => $inst1->id,
                'practice_instructor_id' => $inst2->id,
                'status'                 => 'Active',
                'start_date'             => '2026-09-01',
                'end_date'               => '2026-12-31',
            ],
            [
                'id'                     => 'IT004.N11.2',
                'course'                 => 'Cơ sở dữ liệu - IT004.N11',
                'course_code'            => 'IT004',
                'theory_class'           => 'IT004.N11',
                'practice_class'         => 'IT004.N11.2',
                'term'                   => 'HK1 2026-2027',
                'instructor_id'          => $inst1->id,
                'practice_instructor_id' => $inst3->id,
                'status'                 => 'Active',
                'start_date'             => '2026-09-01',
                'end_date'               => '2026-12-31',
            ],
            // Lớp lý thuyết IT004.N12
            [
                'id'                     => 'IT004.N12.1',
                'course'                 => 'Cơ sở dữ liệu - IT004.N12',
                'course_code'            => 'IT004',
                'theory_class'           => 'IT004.N12',
                'practice_class'         => 'IT004.N12.1',
                'term'                   => 'HK1 2026-2027',
                'instructor_id'          => $inst2->id,
                'practice_instructor_id' => $inst1->id,
                'status'                 => 'Active',
                'start_date'             => '2026-09-01',
                'end_date'               => '2026-12-31',
            ],
            [
                'id'                     => 'IT004.N12.2',
                'course'                 => 'Cơ sở dữ liệu - IT004.N12',
                'course_code'            => 'IT004',
                'theory_class'           => 'IT004.N12',
                'practice_class'         => 'IT004.N12.2',
                'term'                   => 'HK1 2026-2027',
                'instructor_id'          => $inst2->id,
                'practice_instructor_id' => $inst3->id,
                'status'                 => 'Active',
                'start_date'             => '2026-09-01',
                'end_date'               => '2026-12-31',
            ],
        ];

        $validIds = collect($classSpecs)->pluck('id')->all();

        // 1. Tạo/cập nhật 10 lớp học IT004
        foreach ($classSpecs as $spec) {
            ClassModel::updateOrCreate(['id' => $spec['id']], $spec);
        }

        // 2. Xóa các liên kết và lớp cũ không thuộc IT004
        DB::table('assignment_classes')->whereNotIn('class_id', $validIds)->delete();
        if (Schema::hasTable('exam_class_proctors')) {
            DB::table('exam_class_proctors')->whereNotIn('class_id', $validIds)->delete();
        }
        if (Schema::hasTable('class_instructors')) {
            DB::table('class_instructors')->whereNotIn('class_id', $validIds)->delete();
        }
        ClassEnrollment::whereNotIn('class_id', $validIds)->delete();
        ClassModel::whereNotIn('id', $validIds)->delete();

        // 3. Phân bổ sinh viên vào các lớp thực hành
        $students = User::where('account_type', User::ACCOUNT_STUDENT)->orderBy('email')->get();
        $classCount = count($validIds);

        foreach ($students as $index => $student) {
            $targetClassId = $validIds[$index % $classCount];
            DB::table('class_enrollments')->updateOrInsert(
                ['class_id' => $targetClassId, 'student_id' => $student->id],
                ['role' => 'student', 'joined_at' => now()]
            );
        }
    }
}
