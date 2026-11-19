<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\ContentShare;
use App\Models\DatabaseLibraryEntry;
use App\Models\Problem;
use App\Models\ProblemSqlSolution;
use App\Models\ProblemStageExercise;
use App\Models\TestCase;
use App\Models\TestCaseScript;
use App\Models\User;
use App\Services\Access\AccessControlService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Chia sẻ bài toán (`problem`) và cơ sở dữ liệu mẫu (`library`) giữa giảng viên.
 *
 * Quyền (loại việc), theo từng loại nội dung:
 *   - quản lý nội dung (nhận, xem trước, sao chép): problem.create / library.manage
 *   - chia sẻ nội dung của mình cho người khác:     problem.share  / library.share
 * Phạm vi (dữ liệu của ai): chủ sở hữu quản lý chia sẻ của mình; người nhận cần một dòng content_shares;
 * siêu quản trị bỏ qua phạm vi. Không đọc users.role.
 */
class ContentSharingService
{
    private const MANAGE = ['problem' => 'problem.create', 'library' => 'library.manage'];
    private const SHARE  = ['problem' => 'problem.share', 'library' => 'library.share'];

    public function __construct(
        protected ProblemService $problemService,
        protected NotificationService $notificationService,
        protected AccessControlService $access,
    ) {}

    // ───────────────────────── hộp thư ─────────────────────────

    /** Nội dung đã được chia sẻ cho người dùng hiện tại (mới nhất trước). */
    public function inbox(User $user): array
    {
        $result = [];
        foreach (ContentShare::where('recipient_id', $user->id)->orderByDesc('created_at')->get() as $grant) {
            if (! $this->canManageKind($user, $grant->kind)) {
                continue;
            }

            $row = $grant->kind === 'problem'
                ? Problem::find($grant->resource_id)
                : DatabaseLibraryEntry::find($grant->resource_id);
            if (! $row) {
                continue;
            }

            $ownerId = $this->ownerId($grant->kind, $row);
            $owner = $ownerId ? User::find($ownerId) : null;

            $result[] = [
                'kind'    => $grant->kind,
                'id'      => (string) $row->id,
                'title'   => (string) $row->title,
                'owner'   => $owner ? (string) $owner->name : '',
                'canEdit' => (bool) $grant->can_edit,
            ];
        }

        return $result;
    }

    /** Giảng viên có thể nhận nội dung loại `$kind`: đang Active, có quyền quản lý loại đó, không phải siêu quản trị. */
    public function searchRecipients(User $user, string $kind, string $term): array
    {
        $kind = $this->normalizeKind($kind);
        $this->assertCanShare($user, $kind);

        $term = trim($term);
        if (mb_strlen($term) < 2) {
            return [];
        }

        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
        $like = "%{$escaped}%";

        return User::where('status', 'Active')
            ->whereIn('id', $this->access->holdersOf(self::MANAGE[$kind]))
            ->whereNotIn('id', $this->access->superAdminIds())
            ->where('id', '!=', $user->id)
            ->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)->orWhere('email', 'like', $like);
            })
            ->orderBy('name')
            ->orderBy('id')
            ->limit(30)
            ->get()
            ->map(fn (User $person) => [
                'id'    => (string) $person->id,
                'name'  => (string) $person->name,
                'email' => (string) $person->email,
            ])
            ->all();
    }

    // ───────────────────────── quản lý chia sẻ (chủ sở hữu) ─────────────────────────

    public function shares(User $user, string $kind, string $resourceId): array
    {
        $kind = $this->normalizeKind($kind);
        $row = $this->findResource($kind, $resourceId);
        $this->requireOwner($user, $kind, $row);

        return ContentShare::query()
            ->join('users', 'users.id', '=', 'content_shares.recipient_id')
            ->where('content_shares.kind', $kind)
            ->where('content_shares.resource_id', $row->id)
            ->select(['users.id', 'users.name', 'users.email', 'content_shares.can_edit'])
            ->get()
            ->map(fn ($r) => [
                'id'      => (string) $r->id,
                'name'    => (string) $r->name,
                'email'   => (string) $r->email,
                'canEdit' => (bool) $r->can_edit,
            ])
            ->values()
            ->all();
    }

    public function share(User $user, string $kind, string $resourceId, string $email, bool $canEdit): void
    {
        $kind = $this->normalizeKind($kind);
        $row = $this->findResource($kind, $resourceId);
        $this->requireOwner($user, $kind, $row);

        $email = mb_strtolower(trim($email));
        $target = User::whereRaw('LOWER(email) = ?', [$email])
            ->where('status', 'Active')
            ->whereNotIn('id', $this->access->superAdminIds())
            ->first();

        if (! $target || $target->id === $this->ownerId($kind, $row)) {
            throw new HttpException(422, 'Hãy chọn email giảng viên khác đang hoạt động.');
        }
        if (! $this->canManageKind($target, $kind)) {
            // Người không phải giảng viên (không có quyền quản lý loại nội dung này) bị coi như không hợp lệ.
            throw new HttpException(422, 'Hãy chọn email giảng viên khác đang hoạt động.');
        }

        DB::transaction(function () use ($kind, $row, $target, $canEdit) {
            $match = ['kind' => $kind, 'resource_id' => $row->id, 'recipient_id' => $target->id];
            if (DB::table('content_shares')->where($match)->exists()) {
                DB::table('content_shares')->where($match)->update(['can_edit' => $canEdit]);
            } else {
                DB::table('content_shares')->insert($match + ['can_edit' => $canEdit, 'created_at' => now()]);
            }
        });

        $link = $kind === 'problem'
            ? "/teacher/problems/{$row->id}/preview"
            : "/teacher/database-library/{$row->id}/preview";

        $this->notificationService->create(
            $target->id,
            'Nội dung được chia sẻ',
            "{$user->name} đã chia sẻ {$row->title} với bạn.",
            'content_share',
            $link,
        );

        $this->log($user, "content:share:{$kind}:{$row->id}:{$target->id}:edit=" . ($canEdit ? 'True' : 'False'));
    }

    public function revoke(User $user, string $kind, string $resourceId, string $recipientId): void
    {
        $kind = $this->normalizeKind($kind);
        $row = $this->findResource($kind, $resourceId);
        $this->requireOwner($user, $kind, $row);

        DB::table('content_shares')
            ->where('kind', $kind)
            ->where('resource_id', $row->id)
            ->where('recipient_id', $recipientId)
            ->delete();

        $this->log($user, "content:revoke:{$kind}:{$row->id}:{$recipientId}");
    }

    // ───────────────────────── người nhận: sao chép, xem trước ─────────────────────────

    /** @return array{id: string, title: string} */
    public function copy(User $user, string $kind, string $resourceId): array
    {
        $kind = $this->normalizeKind($kind);
        $row = $this->findResource($kind, $resourceId);
        $this->requireRecipientAccess($user, $kind, $row);

        $cloned = DB::transaction(
            fn () => $kind === 'library' ? $this->copyLibrary($row, $user) : $this->copyProblem($row, $user)
        );

        $this->log($user, "content:copy:{$kind}:{$row->id}");

        return ['id' => (string) $cloned->id, 'title' => (string) $cloned->title];
    }

    public function preview(User $user, string $kind, string $resourceId): array
    {
        $kind = $this->normalizeKind($kind);
        $row = $this->findResource($kind, $resourceId);
        $this->requireRecipientAccess($user, $kind, $row);

        $canEdit = $this->canEdit($user, $kind, $row);

        if ($kind === 'library') {
            $bundle = is_array($row->bundle_json) ? $row->bundle_json : [];
            $seedParts = [];
            foreach ($row->datasets as $dataset) {
                if (! empty($dataset['sql'])) {
                    $seedParts[] = $dataset['sql'];
                }
            }

            return [
                'title'     => (string) $row->title,
                'canEdit'   => $canEdit,
                'statement' => (string) ($bundle['statement'] ?? ''),
                'schemaSql' => (string) ($row->create_table_sql ?? ''),
                'seedSql'   => implode("\n\n", $seedParts),
            ];
        }

        $view = $this->problemService->getProblemById($row->id, $user);

        return [
            'title'     => (string) $row->title,
            'canEdit'   => $canEdit,
            'statement' => trim((string) ($row->description ?? '') . "\n\n" . (string) ($row->requirements ?? '')),
            'schemaSql' => (string) ($view['schema'] ?? $view['schema_sql'] ?? $view['schemaSql'] ?? ''),
            'seedSql'   => (string) ($view['seedData'] ?? $view['seed_data'] ?? ''),
        ];
    }

    private function copyLibrary(DatabaseLibraryEntry $row, User $user): DatabaseLibraryEntry
    {
        $copy = $row->replicate();
        $copy->id = (string) Str::uuid();
        $copy->instructor_id = $user->id;
        $copy->revision = 1;
        $copy->created_at = now();
        $copy->updated_at = now();
        $copy->save();

        return $copy;
    }

    private function copyProblem(Problem $row, User $user): Problem
    {
        $copy = $row->replicate();
        $copy->id = (string) Str::uuid();
        $copy->creator_id = $user->id;
        $copy->created_at = now();
        $copy->updated_at = now();
        $copy->save();

        foreach (DB::table('problem_topics')->where('problem_id', $row->id)->pluck('topic_id') as $topicId) {
            DB::table('problem_topics')->insert(['problem_id' => $copy->id, 'topic_id' => $topicId]);
        }

        foreach (ProblemSqlSolution::where('problem_id', $row->id)->get() as $solution) {
            $newSolution = $solution->replicate();
            $newSolution->id = (string) Str::uuid();
            $newSolution->problem_id = $copy->id;
            $newSolution->save();
        }

        foreach (ProblemStageExercise::where('problem_id', $row->id)->get() as $stage) {
            $newStage = $stage->replicate();
            $newStage->id = (string) Str::uuid();
            $newStage->problem_id = $copy->id;
            $newStage->save();
        }

        foreach (TestCase::where('problem_id', $row->id)->get() as $case) {
            $newCase = $case->replicate();
            $newCase->id = (string) Str::uuid();
            $newCase->problem_id = $copy->id;
            $newCase->save();

            foreach (TestCaseScript::where('test_case_id', $case->id)->get() as $script) {
                $newScript = $script->replicate();
                $newScript->id = (string) Str::uuid();
                $newScript->test_case_id = $newCase->id;
                $newScript->save();
            }
        }

        return $copy;
    }

    // ───────────────────────── quyền và phạm vi ─────────────────────────

    private function normalizeKind(string $kind): string
    {
        if (! in_array($kind, ['problem', 'library'], true)) {
            abort(422, 'Loại nội dung chia sẻ không hợp lệ.');
        }

        return $kind;
    }

    private function findResource(string $kind, string $resourceId): Problem|DatabaseLibraryEntry
    {
        $row = $kind === 'problem' ? Problem::find($resourceId) : DatabaseLibraryEntry::find($resourceId);

        return $row ?? abort(404, 'Không tìm thấy nội dung.');
    }

    private function ownerId(string $kind, Problem|DatabaseLibraryEntry $row): ?string
    {
        return $kind === 'problem' ? $row->creator_id : $row->instructor_id;
    }

    private function getGrant(string $kind, string $resourceId, string $userId): ?ContentShare
    {
        return ContentShare::where('kind', $kind)
            ->where('resource_id', $resourceId)
            ->where('recipient_id', $userId)
            ->first();
    }

    private function canEdit(User $user, string $kind, Problem|DatabaseLibraryEntry $row): bool
    {
        if ($this->access->isSuperAdmin($user) || $this->ownerId($kind, $row) === $user->id) {
            return true;
        }

        return (bool) $this->getGrant($kind, $row->id, $user->id)?->can_edit;
    }

    private function canManageKind(User $user, string $kind): bool
    {
        return isset(self::MANAGE[$kind]) && $this->access->can($user, self::MANAGE[$kind]);
    }

    private function assertCanShare(User $user, string $kind): void
    {
        abort_unless($this->access->can($user, self::SHARE[$kind]), 403, 'Bạn không có quyền chia sẻ nội dung này.');
    }

    private function requireOwner(User $user, string $kind, Problem|DatabaseLibraryEntry $row): void
    {
        $this->assertCanShare($user, $kind);

        if (! $this->access->isSuperAdmin($user) && $this->ownerId($kind, $row) !== $user->id) {
            abort(403, 'Chỉ chủ sở hữu được quản lý chia sẻ.');
        }
    }

    private function requireRecipientAccess(User $user, string $kind, Problem|DatabaseLibraryEntry $row): void
    {
        abort_unless($this->canManageKind($user, $kind), 403, 'Bạn không có quyền truy cập nội dung này.');

        if ($this->access->isSuperAdmin($user) || $this->ownerId($kind, $row) === $user->id) {
            return;
        }

        if (! $this->getGrant($kind, $row->id, $user->id)) {
            abort(403, 'Nội dung chưa được chia sẻ cho bạn.');
        }
    }

    private function log(User $user, string $action): void
    {
        ActivityLog::create([
            'id'         => (string) Str::uuid(),
            'user_id'    => $user->id,
            'action'     => $action,
            'created_at' => now(),
        ]);
    }
}
