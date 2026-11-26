<?php

namespace Tests\Concerns;

use App\Models\AccessRole;
use App\Models\Assignment;
use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\ExamAdmission;
use App\Models\Examination;
use App\Models\StudentIdentity;
use App\Models\User;
use App\Services\Access\AccessControlService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Dữ liệu dựng sẵn cho test đăng nhập ca thi và coi thi (ExamLogin*, Proctor*).
 * Chạy trong DatabaseTransactions nên mọi dòng tạo ra được rollback.
 */
trait ProctorFixtures
{
    /**
     * Tài khoản nhân sự (staff) chỉ có đúng các quyền $permissions; hoặc sinh viên nếu $kind = 'student'.
     * Truyền $permissions = null để giữ vai trò mặc định của $kind.
     */
    protected function makeAccount(string $kind, ?array $permissions = null, string $status = 'Active'): User
    {
        $user = User::create([
            'name'            => 'PX ' . strtoupper(Str::random(6)),
            'email'           => 'px_' . uniqid() . '@gm.uit.edu.vn',
            'hashed_password' => Hash::make('real-pass-123'),
            ...AccountFixture::for($kind),
            'status'          => $status,
            'initials'        => 'PX',
        ]);

        if ($permissions !== null) {
            DB::table('user_access_roles')->where('user_id', $user->id)->delete();
            $role = AccessRole::create(['name' => 'PX role ' . uniqid()]);
            $role->syncPermissions($permissions);
            $user->accessRoles()->attach($role->id);
        }
        app(AccessControlService::class)->forget();

        return $user;
    }

    protected function makeStudent(?string $code = null, string $status = 'Active'): User
    {
        $student = $this->makeAccount('student', null, $status);
        if ($code !== null) {
            StudentIdentity::create(['user_id' => $student->id, 'student_code' => $code]);
        }

        return $student;
    }

    protected function makeExamination(?User $owner = null): Examination
    {
        return Examination::create([
            'id'        => (string) Str::uuid(),
            'owner_id'  => $owner?->id,
            'title'     => 'PX kỳ thi ' . uniqid(),
            'exam_date' => now()->addDay()->format('Y-m-d'),
        ]);
    }

    /** Ca thi (assignment có examination_id), đã công bố và đang mở. */
    protected function makeExamSession(array $overrides = [], ?Examination $exam = null): Assignment
    {
        $exam ??= $this->makeExamination();

        return Assignment::create(array_merge([
            'title'              => 'PX ca thi ' . uniqid(),
            'published'          => true,
            'exam_mode'          => true,
            'admission_required' => false,
            'examination_id'     => $exam->id,
            'session_index'      => 1,
            'opens'              => now()->subHour(),
            'closes'             => now()->addHours(2),
        ], $overrides));
    }

    protected function makeClass(array $overrides = []): ClassModel
    {
        return ClassModel::create(array_merge([
            'id'           => 'pxc-' . uniqid(),
            'course'       => 'PX Course',
            'theory_class' => 'PX.1',
            'term'         => 'PX',
        ], $overrides));
    }

    protected function linkClass(Assignment $session, ClassModel $class): void
    {
        DB::table('assignment_classes')->insert([
            'id' => 'pxac-' . uniqid(), 'assignment_id' => $session->id, 'class_id' => $class->id,
        ]);
    }

    protected function enroll(ClassModel $class, User $student): void
    {
        ClassEnrollment::create(['class_id' => $class->id, 'student_id' => $student->id, 'role' => 'student', 'joined_at' => now()]);
    }

    protected function addDirect(Assignment $session, User $student): void
    {
        DB::table('assignment_students')->insert(['assignment_id' => $session->id, 'student_id' => $student->id]);
    }

    protected function makeAdmission(User $student, Assignment $session, array $overrides = []): ExamAdmission
    {
        return ExamAdmission::create(array_merge([
            'session_id'       => $session->id,
            'student_id'       => $student->id,
            'username'         => 'K7_' . strtoupper(Str::random(12)),
            'hashed_password'  => Hash::make('Tmp-Pass-9z'),
            'initial_password' => 'Tmp-Pass-9z',
            'status'           => 'issued',
            'generation'       => 1,
        ], $overrides));
    }
}
