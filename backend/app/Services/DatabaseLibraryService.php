<?php

namespace App\Services;

use App\Models\DatabaseLibraryEntry;
use App\Models\User;
use App\Services\Access\AccessControlService;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Thư viện cơ sở dữ liệu mẫu của giảng viên (bảng database_library_entries).
 *
 * Quyền (loại việc): library.manage để tạo/sửa/xóa/chạy thử; xem được thêm với problem.create.
 * Phạm vi (dữ liệu của ai): instructor_id = null là mẫu hệ thống (ai có quyền đều xem, chỉ siêu quản trị sửa/xóa);
 * còn lại chỉ chủ sở hữu và siêu quản trị được xem/sửa/xóa. Không đọc users.role.
 * Input nhận cả camelCase lẫn snake_case vì frontend và API cũ dùng cả hai.
 */
class DatabaseLibraryService
{
    public function __construct(
        protected ProblemService $problemService,
        protected AccessControlService $access,
    ) {}

    /**
     * @param  array{scope?: ?string, search?: ?string, page?: mixed, per_page?: mixed}  $filters
     * @return array  Danh sách phẳng, hoặc mảng phân trang nếu có page/per_page.
     */
    public function list(User $user, array $filters): array
    {
        $this->assertCanView($user);

        $query = DatabaseLibraryEntry::query();

        $scope = $filters['scope'] ?? 'all';
        if ($scope === 'my') {
            $query->where('instructor_id', $user->id);
        } elseif ($scope === 'system') {
            $query->whereNull('instructor_id');
        } else {
            $query->where(function ($q) use ($user) {
                $q->where('instructor_id', $user->id)->orWhereNull('instructor_id');
            });
        }

        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('database_file_name', 'like', "%{$search}%")
                    ->orWhere('create_table_file_name', 'like', "%{$search}%");
            });
        }

        $query->orderBy('updated_at', 'desc');

        if (array_key_exists('page', $filters) || array_key_exists('per_page', $filters)) {
            $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 15)));
            $paginator = $query->paginate($perPage);

            return [
                'data'        => collect($paginator->items())->map->toResponseArray()->all(),
                'total'       => $paginator->total(),
                'currentPage' => $paginator->currentPage(),
                'lastPage'    => $paginator->lastPage(),
                'perPage'     => $paginator->perPage(),
            ];
        }

        return $query->get()->map->toResponseArray()->all();
    }

    public function show(User $user, string $id): array
    {
        $this->assertCanView($user);

        $entry = DatabaseLibraryEntry::findOrFail($id);

        if ($entry->instructor_id && $entry->instructor_id !== $user->id) {
            abort(403, 'You do not have permission to view this database library entry.');
        }

        return $entry->toResponseArray();
    }

    public function create(User $user, array $input): array
    {
        $this->assertCanManage($user);

        $datasets = $input['datasets'] ?? [];
        $firstDataset = is_array($datasets) && count($datasets) > 0 ? $datasets[0] : [];

        $entry = DatabaseLibraryEntry::create([
            'id'                     => (string) Str::uuid(),
            'instructor_id'          => $user->id,
            'title'                  => trim($input['title']),
            'database_file_name'     => $this->pick($input, 'databaseFileName', 'database_file_name'),
            'create_table_file_name' => $this->pick($input, 'createTableFileName', 'create_table_file_name'),
            'insert_into_file_name'  => $firstDataset['fileName'] ?? $this->pick($input, 'insertIntoFileName', 'insert_into_file_name'),
            'create_table_sql'       => $this->pick($input, 'createTableSql', 'create_table_sql') ?? '',
            'insert_into_sql'        => $firstDataset['sql'] ?? $this->pick($input, 'insertIntoSql', 'insert_into_sql') ?? '',
            'datasets_json'          => $datasets,
            'bundle_json'            => $this->pick($input, 'bundle', 'bundle_json'),
            'revision'               => 1,
        ]);

        return $entry->toResponseArray();
    }

    public function update(User $user, string $id, array $input): array
    {
        $this->assertCanManage($user);

        $entry = DatabaseLibraryEntry::findOrFail($id);
        $this->assertCanModify($user, $entry, 'edit');

        $expectedRevision = $this->pick($input, 'expectedRevision', 'expected_revision');
        if ($expectedRevision !== null && (int) $expectedRevision !== (int) ($entry->revision ?? 1)) {
            $message = 'Bộ đề thư viện đã được sửa ở một cửa sổ khác. Tải lại bản mới trước khi lưu.';
            throw new HttpException(409, $message);
        }

        $datasets = array_key_exists('datasets', $input) ? ($input['datasets'] ?? []) : $entry->datasets_json;
        $firstDataset = is_array($datasets) && count($datasets) > 0 ? $datasets[0] : [];

        $updateData = [
            'title'                  => array_key_exists('title', $input) ? trim($input['title']) : $entry->title,
            'database_file_name'     => $this->pick($input, 'databaseFileName', 'database_file_name') ?? $entry->database_file_name,
            'create_table_file_name' => $this->pick($input, 'createTableFileName', 'create_table_file_name') ?? $entry->create_table_file_name,
            'insert_into_file_name'  => $firstDataset['fileName'] ?? $this->pick($input, 'insertIntoFileName', 'insert_into_file_name') ?? $entry->insert_into_file_name,
            'create_table_sql'       => $this->pick($input, 'createTableSql', 'create_table_sql') ?? $entry->create_table_sql,
            'insert_into_sql'        => $firstDataset['sql'] ?? $this->pick($input, 'insertIntoSql', 'insert_into_sql') ?? $entry->insert_into_sql,
            'datasets_json'          => $datasets,
            'revision'               => ((int) ($entry->revision ?? 1)) + 1,
        ];

        if (array_key_exists('bundle', $input) || array_key_exists('bundle_json', $input)) {
            $updateData['bundle_json'] = $this->pick($input, 'bundle', 'bundle_json');
        }

        $entry->update($updateData);

        return $entry->toResponseArray();
    }

    public function delete(User $user, string $id): void
    {
        $this->assertCanManage($user);

        $entry = DatabaseLibraryEntry::findOrFail($id);
        $this->assertCanModify($user, $entry, 'delete');

        $entry->delete();
    }

    /**
     * Chạy thử DDL + DML trong sandbox SQL Server để bắt lỗi cú pháp.
     *
     * @return array{0: int, 1: array}  [HTTP status, body]
     */
    public function testRun(User $user, array $input): array
    {
        $this->assertCanManage($user);

        $createSql = $this->pick($input, 'createTableSql', 'create_table_sql') ?? '';
        $insertSql = $this->pick($input, 'insertIntoSql', 'insert_into_sql') ?? '';

        if (trim($createSql) === '' && trim($insertSql) === '') {
            return [422, [
                'status'  => 'Error',
                'message' => 'Please provide SQL statements to test.',
            ]];
        }

        $validation = $this->problemService->validateSolution([
            'schema'             => $createSql,
            'seedData'           => $insertSql,
            'reference_solution' => 'SELECT 1 AS test_connection;',
        ], $user);

        if (strtolower((string) ($validation['status'] ?? '')) === 'error') {
            $errMessage = (string) ($validation['message'] ?? 'SQL execution failed.');

            return [422, [
                'status'          => 'Error',
                'detail'          => $errMessage,
                'message'         => $errMessage,
                'executionTimeMs' => $validation['executionTimeMs'] ?? 0,
            ]];
        }

        return [200, [
            'status'          => $validation['status'] ?? 'Success',
            'message'         => 'SQL schema and dataset executed successfully without syntax errors.',
            'executionTimeMs' => $validation['executionTimeMs'] ?? 0,
        ]];
    }

    /** Giá trị đầu tiên không null trong (camelCase, snake_case). */
    private function pick(array $input, string $camel, string $snake): mixed
    {
        return $input[$camel] ?? $input[$snake] ?? null;
    }

    private function assertCanModify(User $user, DatabaseLibraryEntry $entry, string $verb): void
    {
        if (! $entry->instructor_id || $entry->instructor_id !== $user->id) {
            abort(403, "You do not have permission to {$verb} this database library entry.");
        }
    }

    private function assertCanView(User $user): void
    {
        abort_unless($this->access->canAny($user, ['library.manage', 'problem.create']), 403, 'Bạn không có quyền xem thư viện cơ sở dữ liệu.');
    }

    private function assertCanManage(User $user): void
    {
        abort_unless($this->access->can($user, 'library.manage'), 403, 'Bạn không có quyền quản lý thư viện cơ sở dữ liệu.');
    }
}
