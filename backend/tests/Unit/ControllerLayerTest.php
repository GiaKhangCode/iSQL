<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Kiểm soát kiến trúc: controller KHÔNG được truy cập model/DB trực tiếp.
 *
 * Quy tắc: logic dữ liệu nằm trong app/Services, controller chỉ nhận request, gọi service
 * và trả JSON. Test này quét mã nguồn của app/Http/Controllers và báo lỗi nếu thấy:
 *   - gọi static trên model:   Notification::where(...), $x = Problem::find(...)
 *   - gọi facade DB:           DB::table(...), DB::transaction(...)
 *   - tạo model trực tiếp:     new Submission(...)
 * Vẫn được phép: type-hint model (vd `User $user`) và dùng hằng (`User::ROLE_ADMIN`),
 * vì đó không phải truy vấn.
 *
 * Giới hạn: chỉ bắt các cách gọi ở trên. Gọi `->save()/->update()/->delete()` trên một
 * model mà controller nhận được từ nơi khác thì không phát hiện được bằng cách quét văn bản.
 *
 * ── Danh sách ngoại lệ (LEGACY) ──────────────────────────────────────────────────────
 * Các controller cũ chưa được tách service. Giá trị là SỐ VI PHẠM TỐI ĐA cho phép:
 *   - file trong danh sách không được phát sinh thêm vi phạm mới (vượt số này là lỗi);
 *   - khi một file hết vi phạm, test BẮT BUỘC xóa nó khỏi danh sách (tránh danh sách lỗi thời).
 * Mục tiêu cuối cùng: danh sách này rỗng.
 */
class ControllerLayerTest extends TestCase
{
    /** @var array<string, int> đường dẫn tương đối so với app/Http/Controllers => số vi phạm tối đa */
    private const LEGACY = [
        // Giai đoạn 1: CRUD nhỏ
        // Giai đoạn 2: mức vừa
        'PermissionController.php'              => 10,
        'Teacher/DatabaseLibraryController.php' => 5,
        // Giai đoạn 3: controller lớn
        'ContentSharingController.php'          => 24,
        'RegradeAssignmentController.php'       => 38,
        'Student/ActivityAttemptController.php' => 18,
        'ExamLoginProctorController.php'        => 59,
        'SubmissionController.php'              => 72,
    ];

    private static function controllersDir(): string
    {
        return dirname(__DIR__, 2) . '/app/Http/Controllers';
    }

    /**
     * @return array<int, string> tên các class trong app/Models
     */
    private static function modelNames(): array
    {
        $names = [];
        foreach (glob(dirname(__DIR__, 2) . '/app/Models/*.php') as $file) {
            $names[] = basename($file, '.php');
        }

        return $names;
    }

    /**
     * Mã nguồn đã bỏ comment và nội dung chuỗi (giữ nguyên số dòng) để không bắt nhầm
     * các chữ như "DB::" nằm trong chú thích hay thông báo lỗi.
     */
    private static function stripCommentsAndStrings(string $source): string
    {
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (!is_array($token)) {
                $code .= $token;
                continue;
            }

            [$id, $text] = $token;

            if (in_array($id, [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                $code .= str_repeat("\n", substr_count($text, "\n"));
                continue;
            }

            $code .= $text;
        }

        return $code;
    }

    /**
     * @return array<int, array{line: int, code: string}> các chỗ vi phạm trong một file
     */
    private static function violationsIn(string $path): array
    {
        $code = self::stripCommentsAndStrings((string) file_get_contents($path));
        $models = implode('|', array_map('preg_quote', self::modelNames()));
        $bs = '\\\\'; // một dấu gạch chéo ngược trong regex

        $patterns = [
            // Model::method(  (hằng như Model::ROLE_X không có dấu "(" nên không bị bắt)
            '/(?<![\w])(?:' . $models . ')::[A-Za-z_]\w*\s*\(/',
            // DB::table / DB::transaction / ...
            '/(?<![\w])DB::[A-Za-z_]\w*/',
            // new Model(...)
            '/\bnew\s+' . $bs . '?(?:\w+' . $bs . ')*(?:' . $models . ')\s*\(/',
        ];

        $lines = explode("\n", $code);
        $found = [];

        foreach ($patterns as $pattern) {
            if (!preg_match_all($pattern, $code, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($matches[0] as [$text, $offset]) {
                $line = substr_count(substr($code, 0, $offset), "\n") + 1;
                $found[$line . ':' . $text] = ['line' => $line, 'code' => trim($lines[$line - 1] ?? $text)];
            }
        }

        usort($found, fn ($a, $b) => $a['line'] <=> $b['line']);

        return array_values($found);
    }

    /**
     * @return array<string, array<int, array{line: int, code: string}>> chỉ gồm các file có vi phạm
     */
    private static function scanControllers(): array
    {
        $base = self::controllersDir();
        $result = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($base) + 1));
            $violations = self::violationsIn($file->getPathname());

            if ($violations !== []) {
                $result[$relative] = $violations;
            }
        }

        ksort($result);

        return $result;
    }

    public function test_controllers_do_not_access_models_directly(): void
    {
        $problems = [];

        foreach (self::scanControllers() as $file => $violations) {
            $count = count($violations);
            $cap = self::LEGACY[$file] ?? 0;

            if ($count > $cap) {
                $problems[] = sprintf('%s: %d vi phạm (cho phép %d)', $file, $count, $cap);
            }
        }

        $this->assertSame(
            [],
            $problems,
            "Controller gọi trực tiếp model/DB, hãy chuyển sang app/Services:\n  - " . implode("\n  - ", $problems)
        );
    }

    public function test_legacy_allowlist_has_no_stale_entries(): void
    {
        $actual = self::scanControllers();
        $stale = [];

        foreach (self::LEGACY as $file => $cap) {
            if (!isset($actual[$file])) {
                $stale[] = $file;
            }
        }

        $this->assertSame(
            [],
            $stale,
            "Các file sau đã sạch (hoặc không còn tồn tại), hãy xóa khỏi LEGACY:\n  - " . implode("\n  - ", $stale)
        );
    }
}
