<?php

namespace Tests\Feature;

use App\Models\ExerciseTag;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ProctorFixtures;
use Tests\Concerns\UsesDefaultRolePermissions;
use Tests\TestCase;

/**
 * Thẻ câu hỏi (độ khó + dạng bài). Không còn cột thứ tự: thứ tự hiển thị là thứ tự tạo (created_at),
 * độ khó trước dạng bài. Thẻ mặc định được tạo với created_at cách nhau từng giây để giữ đúng thứ tự soạn sẵn.
 */
class ExerciseTagTest extends TestCase
{
    use DatabaseTransactions;
    use ProctorFixtures;
    use UsesDefaultRolePermissions;

    private const DEFAULT_ORDER = [
        'Dễ', 'Trung bình', 'Khó',
        'DDL - Tạo bảng (CREATE TABLE)', 'DDL - Thay đổi cấu trúc (ALTER TABLE)', 'DDL - Ràng buộc CHECK', 'DDL - Khóa ngoại (FOREIGN KEY)',
        'DML - Thêm dòng dữ liệu (INSERT)', 'DML - Cập nhật dữ liệu (UPDATE)', 'DML - Xóa dòng dữ liệu (DELETE)', 'DML - Sao chép bảng (SELECT INTO)',
        'DQL - Truy vấn cơ bản (SELECT/WHERE)', 'DQL - Kết nối nhiều bảng (JOIN)', 'DQL - Truy vấn con (SUBQUERY)',
        'DQL - Gom nhóm & Tổng hợp (GROUP BY/HAVING)', 'DQL - Phép toán tập hợp (UNION, INTERSECT, EXCEPT)', 'DQL - Phép chia (DIVISION)',
        'DQL - Top N và xếp hạng (TOP, DENSE_RANK)',
        'Trigger - Ràng buộc toàn vẹn liên bảng', 'Trigger - Kiểm tra điều kiện dữ liệu',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->useDefaultRolePermissions();
        // Bảng thẻ trên DB dev có dữ liệu; xóa trong transaction (được rollback) để seed mặc định chạy lại.
        ExerciseTag::query()->delete();
    }

    private function names(): array
    {
        return array_column($this->getJson('/api/exercise-tags')->assertOk()->json(), 'name');
    }

    public function test_the_column_for_a_display_order_no_longer_exists(): void
    {
        $this->assertFalse(Schema::hasColumn('exercise_tags', 'order_index'));
    }

    public function test_default_tags_keep_their_prepared_order_without_an_order_column(): void
    {
        $this->assertSame(self::DEFAULT_ORDER, $this->names());
        // Gọi lại không tạo trùng và không đổi thứ tự.
        $this->assertSame(self::DEFAULT_ORDER, $this->names());
    }

    public function test_tag_payload_has_no_order_fields_but_keeps_the_id_for_the_api(): void
    {
        $row = $this->getJson('/api/exercise-tags')->assertOk()->json('0');

        $this->assertSame(['id', 'kind', 'name', 'part', 'createdAt', 'created_at'], array_keys($row));
        $this->assertNotEmpty($row['id']);
    }

    public function test_a_new_tag_goes_after_the_existing_tags_of_its_kind_and_difficulties_stay_first(): void
    {
        $manager = $this->makeAccount('instructor', ['tag.manage']);
        Sanctum::actingAs($manager);
        $this->getJson('/api/exercise-tags')->assertOk(); // seed mặc định

        $category = $this->postJson('/api/admin/exercise-tags', ['name' => 'Thẻ dạng mới', 'kind' => 'category', 'part' => 'DQL'])
            ->assertStatus(201)->json();
        $difficulty = $this->postJson('/api/admin/exercise-tags', ['name' => 'Rất khó', 'kind' => 'difficulty'])
            ->assertStatus(201)->json();

        $names = $this->names();
        $this->assertSame('Thẻ dạng mới', end($names), 'thẻ dạng bài mới đứng cuối');
        $this->assertSame(['Dễ', 'Trung bình', 'Khó', 'Rất khó'], array_slice($names, 0, 4), 'thẻ độ khó luôn đứng trước dạng bài');
        $this->assertArrayNotHasKey('orderIndex', $category);
        $this->assertArrayNotHasKey('order_index', $difficulty);
    }

    public function test_legacy_order_fields_in_requests_are_ignored_and_editing_keeps_the_position(): void
    {
        $manager = $this->makeAccount('instructor', ['tag.manage']);
        Sanctum::actingAs($manager);
        $this->getJson('/api/exercise-tags')->assertOk();

        $created = $this->postJson('/api/admin/exercise-tags', [
            'name' => 'Thẻ cũ gửi thứ tự', 'kind' => 'category', 'part' => 'DDL', 'orderIndex' => 0, 'order_index' => 0,
        ])->assertStatus(201)->json();

        $this->putJson("/api/admin/exercise-tags/{$created['id']}", ['name' => 'Thẻ đã đổi tên', 'orderIndex' => -5])
            ->assertOk()->assertJsonPath('name', 'Thẻ đã đổi tên');

        $this->assertSame('Thẻ đã đổi tên', collect($this->names())->last(), 'sửa tên không đổi vị trí');
        $this->assertCount(count(self::DEFAULT_ORDER) + 1, $this->names());
    }
}
