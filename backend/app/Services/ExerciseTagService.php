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
        if (! app(\App\Services\Access\AccessControlService::class)->can($user, 'tag.manage')) {
            throw new HttpException(403, 'Bạn không có quyền quản lý thẻ câu hỏi.');
        }
    }

    /**
     * Get all exercise tags: difficulties first, then by creation time (that is the display order).
     * The id only breaks ties so the order is deterministic.
     */
    public function getAllTags(): array
    {
        $tags = ExerciseTag::orderByRaw("CASE WHEN kind = 'difficulty' THEN 1 ELSE 2 END")
            ->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc')
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

        $tag = ExerciseTag::create([
            'id'   => (string) Str::uuid(),
            'kind' => $kind,
            'name' => $name,
            'part' => $kind === 'category' ? $part : 'ALL',
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
            ['kind' => 'difficulty', 'name' => 'Dễ', 'part' => 'ALL'],
            ['kind' => 'difficulty', 'name' => 'Trung bình', 'part' => 'ALL'],
            ['kind' => 'difficulty', 'name' => 'Khó', 'part' => 'ALL'],

            // Categories - DDL
            ['kind' => 'category', 'name' => 'DDL - Tạo bảng (CREATE TABLE)', 'part' => 'DDL'],
            ['kind' => 'category', 'name' => 'DDL - Thay đổi cấu trúc (ALTER TABLE)', 'part' => 'DDL'],
            ['kind' => 'category', 'name' => 'DDL - Ràng buộc CHECK', 'part' => 'DDL'],
            ['kind' => 'category', 'name' => 'DDL - Khóa ngoại (FOREIGN KEY)', 'part' => 'DDL'],

            // Categories - DML
            ['kind' => 'category', 'name' => 'DML - Thêm dòng dữ liệu (INSERT)', 'part' => 'DML'],
            ['kind' => 'category', 'name' => 'DML - Cập nhật dữ liệu (UPDATE)', 'part' => 'DML'],
            ['kind' => 'category', 'name' => 'DML - Xóa dòng dữ liệu (DELETE)', 'part' => 'DML'],
            ['kind' => 'category', 'name' => 'DML - Sao chép bảng (SELECT INTO)', 'part' => 'DML'],

            // Categories - DQL
            ['kind' => 'category', 'name' => 'DQL - Truy vấn cơ bản (SELECT/WHERE)', 'part' => 'DQL'],
            ['kind' => 'category', 'name' => 'DQL - Kết nối nhiều bảng (JOIN)', 'part' => 'DQL'],
            ['kind' => 'category', 'name' => 'DQL - Truy vấn con (SUBQUERY)', 'part' => 'DQL'],
            ['kind' => 'category', 'name' => 'DQL - Gom nhóm & Tổng hợp (GROUP BY/HAVING)', 'part' => 'DQL'],
            ['kind' => 'category', 'name' => 'DQL - Phép toán tập hợp (UNION, INTERSECT, EXCEPT)', 'part' => 'DQL'],
            ['kind' => 'category', 'name' => 'DQL - Phép chia (DIVISION)', 'part' => 'DQL'],
            ['kind' => 'category', 'name' => 'DQL - Top N và xếp hạng (TOP, DENSE_RANK)', 'part' => 'DQL'],

            // Categories - TRIGGER
            ['kind' => 'category', 'name' => 'Trigger - Ràng buộc toàn vẹn liên bảng', 'part' => 'TRIGGER'],
            ['kind' => 'category', 'name' => 'Trigger - Kiểm tra điều kiện dữ liệu', 'part' => 'TRIGGER'],
        ];

        // created_at cách nhau từng giây (kết thúc ngay trước "bây giờ") để thứ tự soạn sẵn là thứ tự hiển thị:
        // thẻ tạo sau đó luôn mới hơn mọi thẻ mặc định.
        $start = now()->subSeconds(count($defaults));
        foreach ($defaults as $i => $item) {
            $tag = new ExerciseTag([
                'id'   => (string) Str::uuid(),
                'kind' => $item['kind'],
                'name' => $item['name'],
                'part' => $item['part'],
            ]);
            $tag->created_at = $start->copy()->addSeconds($i);
            $tag->save();
        }
    }
}

