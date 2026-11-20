<?php

namespace Database\Seeders;

use App\Models\Problem;
use App\Models\User;
use App\Services\ProblemService;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * 4 bài toán (mỗi bài gồm nhiều câu hỏi DDL / DML / Trigger / DQL) cho 4 CSDL của UitDatabaseLibrarySeeder:
 *   QLBH, QLGV, QLPT (gộp đề 101, 102, 201, 202), QLKB (gộp đề 301, 302).
 * Nguồn: SQL-UIT/backend/scripts (qlbh_qlgv_questions.py, exam_papers_questions.py), đã chuyển thành
 * database/seeders/data/uit_problems.json. Dạng bài của mỗi câu là tên một thẻ `category` có sẵn trong `exercise_tags`.
 *
 * Chạy riêng (không nằm trong DatabaseSeeder), sau UitDatabaseLibrarySeeder:
 *   php artisan db:seed --class=Database\\Seeders\\UitProblemSeeder
 *
 * Idempotent: khớp theo `number` (QLBH, QLGV, QLPT, QLKB); có rồi thì cập nhật. Không xóa gì.
 * Chủ sở hữu là giảng viên teacher@uit.edu.vn (đổi bằng forOwner()).
 */
class UitProblemSeeder extends Seeder
{
    private string $ownerEmail = UitDatabaseLibrarySeeder::OWNER_EMAIL;

    public function forOwner(string $email): static
    {
        $this->ownerEmail = $email;

        return $this;
    }

    /** Nội dung các bài sẽ được seed (không ghi DB); dùng cho test. */
    public static function definitions(): array
    {
        $path = __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'uit_problems.json';
        $json = is_file($path) ? file_get_contents($path) : false;
        $data = $json === false ? null : json_decode($json, true);
        if (!is_array($data) || $data === []) {
            throw new RuntimeException('Thiếu hoặc hỏng file dữ liệu seed: uit_problems.json');
        }

        return $data;
    }

    public function run(): void
    {
        $owner = User::where('email', $this->ownerEmail)->where('status', 'Active')->first();
        if ($owner === null) {
            throw new RuntimeException("Không tìm thấy giảng viên đang hoạt động {$this->ownerEmail}; chạy UserSeeder trước.");
        }

        $libraries = UitDatabaseLibrarySeeder::definitions();
        $service = app(ProblemService::class);

        foreach (self::definitions() as $def) {
            $payload = $this->payload($def, $libraries[$def['libraryKey']]);
            $existing = Problem::where('number', $def['number'])->first();

            if ($existing) {
                $service->updateProblem($existing->id, $payload, $owner);
                $this->command?->info("  cập nhật {$def['number']} ({$existing->id})");
            } else {
                $res = $service->createProblem($payload, $owner);
                $this->command?->info("  tạo {$def['number']} ({$res['id']})");
            }
        }
    }

    private function payload(array $def, array $library): array
    {
        $first = $library['datasets'][0];
        $testCases = array_map(
            fn (array $ds) => ['schema' => $library['createTableSql'], 'seedData' => $ds['sql'], 'isHidden' => (bool) $ds['isHidden']],
            $library['datasets']
        );

        $parts = ['DDL'];
        if ($def['dml'] !== []) {
            $parts[] = 'DML';
        }
        array_push($parts, 'SQL', 'TRIGGER');

        $payload = [
            'number'                     => $def['number'],
            'title'                      => $def['title'],
            'difficulty'                 => $def['difficulty'],
            'topics'                     => $def['topics'],
            'statement'                  => $def['statement'],
            'requirements'               => 'Làm các câu theo từng phần ' . implode(' / ', $parts) . '. Câu trigger được chấm theo kết quả: hệ thống chạy các thao tác thử và so dữ liệu với đáp án.',
            'hints'                      => [],
            'database'                   => 'SQL Server',
            'schemaFileName'             => $library['createTableFileName'],
            'databaseDescription'        => $def['relations'],
            'databaseDescriptionVisible' => true,
            'workspaceParts'             => $parts,
            'schema'                     => $library['createTableSql'],
            'seedData'                   => $first['sql'],
            'testCases'                  => $testCases,
            'referenceSolution'          => $def['sql'][0]['query'],
            'ddlExercise'                => ['questions' => $def['ddl']],
            'triggerExercise'            => ['questions' => $def['trigger']],
            'sqlSolutions'               => $def['sql'],
            'practiceListed'             => true,
        ];
        if ($def['dml'] !== []) {
            $payload['dmlExercise'] = ['questions' => $def['dml']];
        }

        return $payload;
    }
}
