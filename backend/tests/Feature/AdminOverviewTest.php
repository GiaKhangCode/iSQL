<?php

namespace Tests\Feature;

use App\Models\ClassModel;
use App\Models\Problem;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\AccountFixture;
use Tests\Concerns\UsesDefaultRolePermissions;
use Tests\TestCase;

/**
 * Test đặc tả cho GET /api/admin/overview (Admin\OverviewController).
 *
 * DB dev đã có dữ liệu sẵn nên test không giả định giá trị tuyệt đối: nó đo ĐỘ CHÊNH
 * của từng bộ đếm trước và sau khi tạo dữ liệu mẫu.
 */
class AdminOverviewTest extends TestCase
{
    use DatabaseTransactions;
    use UsesDefaultRolePermissions;

    private const KEYS = [
        'total_users',
        'total_students',
        'total_instructors',
        'total_problems',
        'total_submissions',
        'total_classes',
    ];

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useDefaultRolePermissions();

        $this->admin = $this->makeUser('admin');
    }

    private function makeUser(string $role, string $status = 'Active'): User
    {
        return User::create([
            'name'            => "Overview {$role}",
            'email'           => "overview_{$role}_" . uniqid() . '@uit.edu.vn',
            'hashed_password' => Hash::make('password123'),
            ...AccountFixture::for($role),
            'status'          => $status,
            'initials'        => 'OV',
        ]);
    }

    /** @return array<string, int> */
    private function overview(): array
    {
        Sanctum::actingAs($this->admin);

        $res = $this->getJson('/api/admin/overview');
        $res->assertOk();

        return $res->json();
    }

    public function test_overview_requires_authentication_and_admin_role(): void
    {
        $this->getJson('/api/admin/overview')->assertStatus(401);

        Sanctum::actingAs($this->makeUser('instructor'));
        $this->getJson('/api/admin/overview')->assertStatus(403);

        Sanctum::actingAs($this->makeUser('student'));
        $this->getJson('/api/admin/overview')->assertStatus(403);
    }

    public function test_overview_returns_exactly_the_expected_integer_counters(): void
    {
        $data = $this->overview();

        $this->assertEqualsCanonicalizing(self::KEYS, array_keys($data));

        foreach (self::KEYS as $key) {
            $this->assertIsInt($data[$key], "{$key} phải là số nguyên");
            $this->assertGreaterThanOrEqual(0, $data[$key]);
        }

        // Admin tạo trong setUp nên tổng người dùng luôn >= 1.
        $this->assertGreaterThanOrEqual(1, $data['total_users']);
    }

    public function test_overview_counters_reflect_created_data(): void
    {
        $before = $this->overview();

        // Cố ý có người dùng không Active: bộ đếm không được lọc theo trạng thái.
        $student1 = $this->makeUser('student');
        $this->makeUser('student', 'Pending');
        $this->makeUser('instructor', 'Inactive');
        $this->makeUser('instructor_practical');
        $this->makeUser('faculty_office');

        $problem = Problem::create([
            'number'          => 'SQL-OV-' . strtoupper(uniqid()),
            'title'           => 'Bài thử thống kê',
            'difficulty'      => 'Easy',
            'description'     => 'Mô tả',
            'requirements'    => 'SELECT 1',
            'practice_listed' => true,
            'creator_id'      => $student1->id,
        ]);

        Submission::create([
            'id'           => (string) Str::uuid(),
            'user_id'      => $student1->id,
            'problem_id'   => $problem->id,
            'source'       => 'SELECT 1',
            'context'      => 'Practice',
            'result'       => 'Accepted',
            'score'        => 100,
            'submitted_at' => now(),
        ]);

        // Một lớp Active và một lớp đã lưu trữ: cả hai đều được đếm.
        foreach (['A' => 'Active', 'B' => 'Archived'] as $tag => $status) {
            ClassModel::create([
                'id'     => 'OV_' . $tag . '_' . strtoupper(uniqid()),
                'course' => 'Lớp thống kê',
                'term'   => 'HK thử',
                'status' => $status,
            ]);
        }

        $after = $this->overview();

        $this->assertSame(5, $after['total_users'] - $before['total_users'], 'mọi role, mọi trạng thái đều được tính vào tổng');
        $this->assertSame(2, $after['total_students'] - $before['total_students'], 'sinh viên Pending vẫn được đếm');
        $this->assertSame(2, $after['total_instructors'] - $before['total_instructors'], 'giảng viên Inactive vẫn được đếm; giáo vụ thì không');
        $this->assertSame(1, $after['total_problems'] - $before['total_problems']);
        $this->assertSame(1, $after['total_submissions'] - $before['total_submissions']);
        $this->assertSame(2, $after['total_classes'] - $before['total_classes']);
    }

    /**
     * Đo độ chênh sẽ triệt tiêu mọi hằng số cộng thêm, nên so thêm với số đếm tuyệt đối
     * lấy thẳng từ bảng (DB::table, không qua model) để bắt sai lệch kiểu đó.
     */
    public function test_overview_counters_match_direct_table_counts(): void
    {
        $this->makeUser('student', 'Pending');
        $this->makeUser('instructor');

        $data = $this->overview();

        $this->assertSame(DB::table('users')->count(), $data['total_users']);
        $this->assertSame(DB::table('users')->where('account_type', 'student')->count(), $data['total_students']);
        $this->assertSame($this->teachingHoldersFromTables(), $data['total_instructors']);
        $this->assertSame(DB::table('problems')->count(), $data['total_problems']);
        $this->assertSame(DB::table('submissions')->count(), $data['total_submissions']);
        $this->assertSame(DB::table('classes')->count(), $data['total_classes']);
    }

    /** Số người giữ class.teach tính thẳng từ bảng: qua vai trò thường hoặc quyền gán riêng, không trùng. */
    private function teachingHoldersFromTables(): int
    {
        $viaRole = DB::table('user_access_roles as uar')
            ->join('access_role_permissions as arp', 'arp.access_role_id', '=', 'uar.access_role_id')
            ->join('access_roles as ar', 'ar.id', '=', 'uar.access_role_id')
            ->where('ar.is_super', false)->where('arp.permission', 'class.teach')
            ->pluck('uar.user_id');
        $direct = DB::table('user_permissions')->where('permission', 'class.teach')->pluck('user_id');

        return $viaRole->merge($direct)->unique()->count();
    }

    /**
     * total_instructors đếm người giữ quyền class.teach (được phân công dạy lớp), không đếm theo loại tài khoản:
     * mọi biến thể giảng viên mặc định tính, trưởng bộ môn và giáo vụ thì không (vai trò mặc định không có quyền này).
     */
    public function test_total_instructors_counts_class_teach_holders(): void
    {
        $before = $this->overview();

        $this->makeUser('instructor');
        $this->makeUser('instructor_practical');
        $this->makeUser('instructor_theory');
        $this->makeUser('department_head');

        $after = $this->overview();

        $this->assertSame(3, $after['total_instructors'] - $before['total_instructors']);
        $this->assertSame(4, $after['total_users'] - $before['total_users']);
    }
}
