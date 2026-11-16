<?php

namespace App\Services;

use App\Models\ExerciseTag;
use App\Models\User;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ExerciseTagService
{
    /**
     * Check if user is allowed to manage exercise tags.
     */
    protected function assertAdmin(User $user): void
    {
        if (strtolower($user->role) !== 'admin' && $user->role !== User::ROLE_ADMIN) {
            throw new HttpException(403, 'Only administrators can manage exercise tags.');
        }
    }

    /**
     * Get all exercise tags ordered by kind, order_index, created_at
     */
    public function getAllTags(): array
    {
        $tags = ExerciseTag::orderByRaw("CASE WHEN kind = 'difficulty' THEN 1 ELSE 2 END")
            ->orderBy('order_index', 'asc')
            ->orderBy('created_at', 'asc')
            ->get();

        return $tags->map->toArray()->all();
    }

    /**
     * Create a new exercise tag
     */
    public function createTag(array $data, User $user): array
    {
        $this->assertAdmin($user);

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new HttpException(400, 'Tag name cannot be empty.');
        }

        $kind = strtolower(trim((string) ($data['kind'] ?? 'difficulty')));
        if (!in_array($kind, ['difficulty', 'category'], true)) {
            throw new HttpException(400, "Kind must be 'difficulty' or 'category'.");
        }

        $part = strtoupper(trim((string) ($data['part'] ?? 'ALL')));
        if (!in_array($part, ['ALL', 'DDL', 'DML', 'DQL', 'TRIGGER'], true)) {
            $part = 'ALL';
        }

        $orderIndex = (int) ($data['order_index'] ?? $data['orderIndex'] ?? 0);

        $tag = ExerciseTag::create([
            'id'          => (string) Str::uuid(),
            'kind'        => $kind,
            'name'        => $name,
            'part'        => $kind === 'category' ? $part : 'ALL',
            'order_index' => $orderIndex,
        ]);

        return $tag->toArray();
    }

    /**
     * Update an existing exercise tag
     */
    public function updateTag(string $id, array $data, User $user): array
    {
        $this->assertAdmin($user);

        $tag = ExerciseTag::find($id);
        if (!$tag) {
            throw new HttpException(404, 'Exercise tag not found.');
        }

        if (array_key_exists('name', $data)) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                throw new HttpException(400, 'Tag name cannot be empty.');
            }
            $tag->name = $name;
        }

        if (array_key_exists('part', $data)) {
            $part = strtoupper(trim((string) $data['part']));
            if (in_array($part, ['ALL', 'DDL', 'DML', 'DQL', 'TRIGGER'], true)) {
                $tag->part = $tag->kind === 'category' ? $part : 'ALL';
            }
        }

        if (array_key_exists('order_index', $data) || array_key_exists('orderIndex', $data)) {
            $tag->order_index = (int) ($data['order_index'] ?? $data['orderIndex']);
        }

        $tag->save();

        return $tag->toArray();
    }

    /**
     * Delete an exercise tag
     */
    public function deleteTag(string $id, User $user): void
    {
        $this->assertAdmin($user);

        $tag = ExerciseTag::find($id);
        if (!$tag) {
            throw new HttpException(404, 'Exercise tag not found.');
        }

        $tag->delete();
    }

    /**
     * Seed standard default tags if table is empty
     */
    public function seedDefaults(): void
    {
        if (ExerciseTag::count() > 0) {
            return;
        }

        $defaults = [
            // Difficulties
            ['kind' => 'difficulty', 'name' => 'Dễ', 'part' => 'ALL', 'order_index' => 1],
            ['kind' => 'difficulty', 'name' => 'Trung bình', 'part' => 'ALL', 'order_index' => 2],
            ['kind' => 'difficulty', 'name' => 'Khó', 'part' => 'ALL', 'order_index' => 3],

            // Categories - DDL
            ['kind' => 'category', 'name' => 'DDL - Tạo bảng (CREATE TABLE)', 'part' => 'DDL', 'order_index' => 1],
            ['kind' => 'category', 'name' => 'DDL - Thay đổi cấu trúc (ALTER TABLE)', 'part' => 'DDL', 'order_index' => 2],
            ['kind' => 'category', 'name' => 'DDL - Ràng buộc CHECK', 'part' => 'DDL', 'order_index' => 3],
            ['kind' => 'category', 'name' => 'DDL - Khóa ngoại (FOREIGN KEY)', 'part' => 'DDL', 'order_index' => 4],

            // Categories - DML
            ['kind' => 'category', 'name' => 'DML - Thêm dòng dữ liệu (INSERT)', 'part' => 'DML', 'order_index' => 1],
            ['kind' => 'category', 'name' => 'DML - Cập nhật dữ liệu (UPDATE)', 'part' => 'DML', 'order_index' => 2],
            ['kind' => 'category', 'name' => 'DML - Xóa dòng dữ liệu (DELETE)', 'part' => 'DML', 'order_index' => 3],
            ['kind' => 'category', 'name' => 'DML - Sao chép bảng (SELECT INTO)', 'part' => 'DML', 'order_index' => 4],

            // Categories - DQL
            ['kind' => 'category', 'name' => 'DQL - Truy vấn cơ bản (SELECT/WHERE)', 'part' => 'DQL', 'order_index' => 1],
            ['kind' => 'category', 'name' => 'DQL - Kết nối nhiều bảng (JOIN)', 'part' => 'DQL', 'order_index' => 2],
            ['kind' => 'category', 'name' => 'DQL - Truy vấn con (SUBQUERY)', 'part' => 'DQL', 'order_index' => 3],
            ['kind' => 'category', 'name' => 'DQL - Gom nhóm & Tổng hợp (GROUP BY/HAVING)', 'part' => 'DQL', 'order_index' => 4],
            ['kind' => 'category', 'name' => 'DQL - Phép toán tập hợp (UNION, INTERSECT, EXCEPT)', 'part' => 'DQL', 'order_index' => 5],
            ['kind' => 'category', 'name' => 'DQL - Phép chia (DIVISION)', 'part' => 'DQL', 'order_index' => 6],
            ['kind' => 'category', 'name' => 'DQL - Top N và xếp hạng (TOP, DENSE_RANK)', 'part' => 'DQL', 'order_index' => 7],

            // Categories - TRIGGER
            ['kind' => 'category', 'name' => 'Trigger - Ràng buộc toàn vẹn liên bảng', 'part' => 'TRIGGER', 'order_index' => 1],
            ['kind' => 'category', 'name' => 'Trigger - Kiểm tra điều kiện dữ liệu', 'part' => 'TRIGGER', 'order_index' => 2],
        ];

        foreach ($defaults as $item) {
            ExerciseTag::create([
                'id'          => (string) Str::uuid(),
                'kind'        => $item['kind'],
                'name'        => $item['name'],
                'part'        => $item['part'],
                'order_index' => $item['order_index'],
            ]);
        }
    }
}

