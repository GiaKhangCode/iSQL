<?php

namespace Tests\Feature;

use App\Models\ClassModel;
use App\Models\User;
use App\Services\ExaminationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ProctorFixtures;
use Tests\Concerns\UsesDefaultRolePermissions;
use Tests\TestCase;

/**
 * Một lớp, một giảng viên (classes.instructor_id): các nơi đọc "giảng viên của lớp" ngoài API quản lý lớp.
 * Các ca này khóa hai chỗ trước đây chưa có test: phạm vi thống kê của giảng viên và tên giảng viên trong
 * danh sách tài khoản ca thi.
 */
class ClassInstructorScopeTest extends TestCase
{
    use DatabaseTransactions;
    use ProctorFixtures;
    use UsesDefaultRolePermissions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useDefaultRolePermissions();
    }

    public function test_teacher_statistics_list_only_the_classes_the_user_teaches(): void
    {
        $teacher = $this->makeAccount('instructor', ['results.grade', 'statistics.view']);
        $other = $this->makeAccount('instructor', ['results.grade', 'statistics.view']);
        $mine = $this->makeClass(['instructor_id' => $teacher->id]);
        $theirs = $this->makeClass(['instructor_id' => $other->id]);
        $nobodys = $this->makeClass();

        Sanctum::actingAs($teacher);
        $ids = array_column($this->getJson('/api/teacher/statistics')->assertOk()->json('classes'), 'id');

        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, $ids);
        $this->assertNotContains($nobodys->id, $ids);
    }

    public function test_exam_account_rows_name_the_instructor_of_the_students_class(): void
    {
        $owner = $this->makeAccount('instructor', ['exam.proctor']);
        $classTeacher = $this->makeAccount('instructor', ['exam.proctor']);
        $session = $this->makeExamSession(['instructor_id' => $owner->id]);
        $class = $this->makeClass(['instructor_id' => $classTeacher->id, 'practice_class' => 'SC.1']);
        $this->linkClass($session, $class);
        $this->enroll($class, $this->makeStudent());

        $data = app(ExaminationService::class)->ensureSessionStudentAccounts($session);

        $this->assertCount(1, $data['students']);
        $this->assertSame($classTeacher->name, $data['students'][0]['teacherName'], 'giảng viên của lớp, không phải chủ ca');
        $this->assertSame('SC.1', $data['students'][0]['className']);
        $this->assertSame($owner->name, $data['session']['teacherName'], 'phần đầu phiếu vẫn là chủ ca');
    }
}
