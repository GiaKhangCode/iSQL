<?php

namespace Database\Seeders;

use App\Models\DatabaseLibraryEntry;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * 4 cơ sở dữ liệu mẫu (T-SQL, chạy trên sandbox SQL Server) cho thư viện CSDL của giảng viên:
 *   QLBH (quản lý bán hàng), QLGV (quản lý giáo vụ),
 *   QLPT (quản lý cho thuê phòng trọ), QLKB (quản lý khám bệnh).
 * Nguồn: SQL-UIT/backend/scripts (seed_qlbh_qlgv.py, seed_exam_papers.py). SQL nằm trong database/seeders/sql/:
 * {khóa}_createtable.sql (cấu trúc), {khóa}_insertdata.sql (dữ liệu), thêm bộ kiểm thử ẩn cho QLGV.
 * QLPT là bản hợp của các biến thể đề thi (có cả PHONGTRO.LoaiPT và CUDAN.NgayThue); QLKB sửa nvachar thành nvarchar.
 * Dữ liệu thỏa mọi ràng buộc CHECK mà đề yêu cầu thêm, nên ALTER TABLE ... ADD CONSTRAINT không lỗi trên dữ liệu gốc.
 *
 * Chạy riêng (không nằm trong DatabaseSeeder):
 *   php artisan db:seed --class=Database\\Seeders\\UitDatabaseLibrarySeeder
 *
 * Idempotent: mục thư viện khớp theo (chủ sở hữu, tiêu đề); có rồi thì cập nhật nội dung (tăng revision nếu nội dung đổi).
 * Không xóa gì. Chủ sở hữu là giảng viên teacher@uit.edu.vn (đổi bằng forOwner()).
 */
class UitDatabaseLibrarySeeder extends Seeder
{
    public const OWNER_EMAIL = 'teacher@uit.edu.vn';

    /** @var array<string, array{title: string, datasets: array<int, array{0: string, 1: bool}>}> khóa => cấu hình */
    private const LIBRARIES = [
        'qlbh' => [
            'title'    => 'Quản lý bán hàng (QLBH)',
            'datasets' => [['qlbh_insertdata.sql', false]],
        ],
        'qlgv' => [
            'title'    => 'Quản lý giáo vụ (QLGV)',
            'datasets' => [['qlgv_insertdata.sql', false], ['qlgv_insertdata_kiemthu.sql', true]],
        ],
        'qlpt' => [
            'title'    => 'Quản lý cho thuê phòng trọ (QLPT)',
            'datasets' => [['qlpt_insertdata.sql', false]],
        ],
        'qlkb' => [
            'title'    => 'Quản lý khám bệnh (QLKB)',
            'datasets' => [['qlkb_insertdata.sql', false]],
        ],
    ];

    private string $ownerEmail = self::OWNER_EMAIL;

    public function forOwner(string $email): static
    {
        $this->ownerEmail = $email;

        return $this;
    }

    /** Khóa, tiêu đề và nội dung các mục sẽ được seed (không ghi DB); dùng cho test. */
    public static function definitions(): array
    {
        $out = [];
        foreach (self::LIBRARIES as $key => $config) {
            $datasets = [];
            foreach ($config['datasets'] as [$file, $hidden]) {
                $datasets[] = ['fileName' => $file, 'sql' => self::readSql($file), 'isHidden' => $hidden];
            }
            $out[$key] = [
                'title'               => $config['title'],
                'createTableFileName' => "{$key}_createtable.sql",
                'createTableSql'      => self::readSql("{$key}_createtable.sql"),
                'datasets'            => $datasets,
            ];
        }

        return $out;
    }

    public function run(): void
    {
        $owner = User::where('email', $this->ownerEmail)->where('status', 'Active')->first();
        if ($owner === null) {
            throw new RuntimeException("Không tìm thấy giảng viên đang hoạt động {$this->ownerEmail}; chạy UserSeeder trước.");
        }

        $created = 0;
        $updated = 0;
        foreach (self::definitions() as $key => $def) {
            $first = $def['datasets'][0];
            $values = [
                'database_file_name'     => "{$key}.sql",
                'create_table_file_name' => $def['createTableFileName'],
                'insert_into_file_name'  => $first['fileName'],
                'create_table_sql'       => $def['createTableSql'],
                'insert_into_sql'        => $first['sql'],
                'datasets_json'          => $def['datasets'],
            ];

            $entry = DatabaseLibraryEntry::where('instructor_id', $owner->id)->where('title', $def['title'])->first();
            if ($entry === null) {
                DatabaseLibraryEntry::create([
                    'id'            => (string) Str::uuid(),
                    'instructor_id' => $owner->id,
                    'title'         => $def['title'],
                    'revision'      => 1,
                ] + $values);
                $created++;
                continue;
            }

            $entry->fill($values);
            if ($entry->isDirty()) {
                $entry->revision = ((int) ($entry->revision ?? 1)) + 1;
                $entry->save();
            }
            $updated++;
        }

        $this->command?->info("Chủ sở hữu: {$owner->name} <{$owner->email}>");
        $this->command?->info("  thư viện CSDL: tạo {$created}, đã có {$updated}");
    }

    private static function readSql(string $file): string
    {
        $path = __DIR__ . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR . $file;
        $sql = is_file($path) ? file_get_contents($path) : false;
        if ($sql === false || trim($sql) === '') {
            throw new RuntimeException("Thiếu hoặc rỗng file SQL seed: {$file}");
        }

        return str_replace("\r\n", "\n", $sql);
    }
}
