<?php

namespace Tests\Feature;

use App\Models\DatabaseLibraryEntry;
use Database\Seeders\UitDatabaseLibrarySeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use RuntimeException;
use Tests\Concerns\ProctorFixtures;
use Tests\Concerns\UsesDefaultRolePermissions;
use Tests\TestCase;

/**
 * Seeder 4 CSDL mẫu (QLBH, QLGV, QLPT, QLKB). Test này không chạy SQL trên sandbox (đã chạy tay một lần khi
 * viết seeder); nó khóa nội dung SQL, tính idempotent và cách cập nhật. Dùng giảng viên riêng của test nên
 * không đụng vào mục thư viện thật của teacher@uit.edu.vn trên DB dev.
 */
class UitDatabaseLibrarySeederTest extends TestCase
{
    use DatabaseTransactions;
    use ProctorFixtures;
    use UsesDefaultRolePermissions;

    private const EXPECTED = [
        'qlbh' => ['title' => 'Quản lý bán hàng (QLBH)', 'tables' => ['KHACHHANG', 'NHANVIEN', 'SANPHAM', 'HOADON', 'CTHD']],
        'qlgv' => ['title' => 'Quản lý giáo vụ (QLGV)', 'tables' => ['KHOA', 'MONHOC', 'DIEUKIEN', 'GIAOVIEN', 'LOP', 'HOCVIEN', 'GIANGDAY', 'KETQUATHI']],
        'qlpt' => ['title' => 'Quản lý cho thuê phòng trọ (QLPT)', 'tables' => ['PHONGTRO', 'CUDAN', 'HOPDONG', 'DICHVU', 'PHIEUTINHTIEN', 'CHITIETTTDV']],
        'qlkb' => ['title' => 'Quản lý khám bệnh (QLKB)', 'tables' => ['BENHNHAN', 'BACSI', 'KHAMBENH', 'THUOC', 'DONTHUOC', 'CHITIETDT']],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->useDefaultRolePermissions();
    }

    private function owner()
    {
        return $this->makeAccount('instructor', ['library.manage']);
    }

    private function seedFor($owner): void
    {
        (new UitDatabaseLibrarySeeder())->forOwner($owner->email)->run();
    }

    private function entries($owner)
    {
        return DatabaseLibraryEntry::where('instructor_id', $owner->id)->orderBy('title')->get();
    }

    public function test_the_sql_files_define_the_four_databases_with_every_table_created_and_loaded(): void
    {
        $defs = UitDatabaseLibrarySeeder::definitions();

        $this->assertSame(array_keys(self::EXPECTED), array_keys($defs));
        foreach (self::EXPECTED as $key => $expected) {
            $def = $defs[$key];
            $this->assertSame($expected['title'], $def['title']);

            preg_match_all('/CREATE\s+TABLE\s+(\w+)/i', $def['createTableSql'], $created);
            $this->assertEqualsCanonicalizing($expected['tables'], $created[1], "{$key}: bảng được tạo");

            // Bộ dữ liệu đầu tiên nạp đủ mọi bảng (không có bảng rỗng).
            preg_match_all('/INSERT\s+INTO\s+(\w+)/i', $def['datasets'][0]['sql'], $loaded);
            $this->assertEqualsCanonicalizing($expected['tables'], array_unique($loaded[1]), "{$key}: bảng có dữ liệu");
            $this->assertFalse($def['datasets'][0]['isHidden'], "{$key}: bộ dữ liệu đầu hiển thị");
        }
    }

    public function test_only_qlgv_has_a_second_hidden_test_dataset_that_extends_the_first(): void
    {
        $defs = UitDatabaseLibrarySeeder::definitions();

        foreach (['qlbh', 'qlpt', 'qlkb'] as $key) {
            $this->assertCount(1, $defs[$key]['datasets'], $key);
        }
        $qlgv = $defs['qlgv']['datasets'];
        $this->assertCount(2, $qlgv);
        $this->assertTrue($qlgv[1]['isHidden']);
        $this->assertStringStartsWith(trim($qlgv[0]['sql']), trim($qlgv[1]['sql']), 'bộ ẩn = dữ liệu gốc + dữ liệu kiểm thử');
        $this->assertGreaterThan(strlen($qlgv[0]['sql']), strlen($qlgv[1]['sql']));
    }

    public function test_the_room_database_is_the_union_of_the_exam_variants_and_the_clinic_uses_nvarchar(): void
    {
        $defs = UitDatabaseLibrarySeeder::definitions();

        $this->assertStringContainsString('LoaiPT', $defs['qlpt']['createTableSql']);
        $this->assertStringContainsString('NgayThue', $defs['qlpt']['createTableSql']);
        $this->assertStringNotContainsString('nvachar', strtolower($defs['qlkb']['createTableSql']));
    }

    public function test_seeding_creates_the_four_entries_for_the_owner_in_the_library_response_shape(): void
    {
        $owner = $this->owner();
        $this->assertCount(0, $this->entries($owner));

        $this->seedFor($owner);

        $entries = $this->entries($owner);
        $this->assertSame(
            collect(self::EXPECTED)->pluck('title')->sort()->values()->all(),
            $entries->pluck('title')->all()
        );
        foreach ($entries as $entry) {
            $this->assertSame(1, $entry->revision);
            $this->assertNotEmpty($entry->create_table_sql);
            $row = $entry->toResponseArray();
            $this->assertNotEmpty($row['datasets']);
            foreach ($row['datasets'] as $dataset) {
                $this->assertSame(['fileName', 'sql', 'isHidden'], array_keys($dataset));
            }
            $this->assertSame($row['datasets'][0]['sql'], $row['insertIntoSql']);
        }
    }

    public function test_running_the_seeder_again_changes_nothing(): void
    {
        $owner = $this->owner();
        $this->seedFor($owner);
        $before = $this->entries($owner)->map(fn ($e) => [$e->id, $e->revision, $e->updated_at?->toISOString()])->all();

        $this->seedFor($owner);

        $this->assertSame($before, $this->entries($owner)->map(fn ($e) => [$e->id, $e->revision, $e->updated_at?->toISOString()])->all());
    }

    public function test_a_drifted_entry_is_restored_and_its_revision_is_bumped_without_touching_others(): void
    {
        $owner = $this->owner();
        $other = $this->owner();
        $this->seedFor($owner);
        $this->seedFor($other);
        $mine = $this->entries($owner)->firstWhere('title', self::EXPECTED['qlpt']['title']);
        $untouched = $this->entries($other)->firstWhere('title', self::EXPECTED['qlpt']['title']);
        $mine->update(['create_table_sql' => 'CREATE TABLE X (A INT);']);

        $this->seedFor($owner);

        $mine->refresh();
        $this->assertStringContainsString('PHONGTRO', $mine->create_table_sql);
        $this->assertSame(2, $mine->revision);
        $this->assertSame(1, $untouched->fresh()->revision, 'mục của giảng viên khác không bị đụng');
        $this->assertCount(4, $this->entries($owner), 'không tạo bản trùng');
    }

    public function test_a_missing_or_inactive_owner_stops_the_seeder_before_writing(): void
    {
        $inactive = $this->makeAccount('instructor', ['library.manage'], 'Inactive');
        $before = DatabaseLibraryEntry::count();

        foreach (['khong.co.ai@uit.edu.vn', $inactive->email] as $email) {
            try {
                (new UitDatabaseLibrarySeeder())->forOwner($email)->run();
                $this->fail('Seeder phải dừng khi không có giảng viên đang hoạt động.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString($email, $e->getMessage());
            }
        }

        $this->assertSame($before, DatabaseLibraryEntry::count());
    }
}
