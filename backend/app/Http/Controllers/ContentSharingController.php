<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\ContentShare;
use App\Models\DatabaseLibraryEntry;
use App\Models\Notification;
use App\Models\Problem;
use App\Models\ProblemSqlSolution;
use App\Models\ProblemStageExercise;
use App\Models\TestCase;
use App\Models\TestCaseScript;
use App\Models\User;
use App\Services\ProblemService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ContentSharingController extends Controller
{
    public function __construct(
        protected ProblemService $problemService
    ) {}

    /**
     * GET /api/sharing — Danh sách nội dung đã được chia sẻ cho user hiện tại.
     */
    public function inbox(Request $request): JsonResponse
    {
        $user = $request->user();
        $grants = ContentShare::where('recipient_id', $user->id)
            ->orderByDesc('created_at')
            ->get();

        $result = [];
        foreach ($grants as $grant) {
            $canAccess = $grant->kind === 'problem'
                ? $this->canManageKind($user, 'problem')
                : $this->canManageKind($user, 'library');

            if (!$canAccess) {
                continue;
            }

            $row = $grant->kind === 'problem'
                ? Problem::find($grant->resource_id)
                : DatabaseLibraryEntry::find($grant->resource_id);

            if (!$row) {
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

        return response()->json($result);
    }

    /**
     * GET /api/sharing/recipients/search?q=...&kind=problem|library
     */
    public function searchRecipients(Request $request): JsonResponse
    {
        $user = $request->user();
        $kind = $this->normalizeKind((string) $request->query('kind', 'problem'));
        $this->assertCanShare($user, $kind);

        $query = trim((string) $request->query('q', ''));
        if (mb_strlen($query) < 2) {
            return response()->json([]);
        }

        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query);
        $likePattern = "%{$escaped}%";

        $matches = User::where('status', 'Active')
            ->whereIn('role', $this->instructorRoles())
            ->where('id', '!=', $user->id)
            ->where(function ($q) use ($likePattern) {
                $q->where('name', 'like', $likePattern)
                  ->orWhere('email', 'like', $likePattern);
            })
            ->orderBy('name')
            ->orderBy('id')
            ->limit(30)
            ->get();

        $result = [];
        foreach ($matches as $person) {
            if ($this->canManageKind($person, $kind)) {
                $result[] = [
                    'id'    => (string) $person->id,
                    'name'  => (string) $person->name,
                    'email' => (string) $person->email,
                ];
            }
        }

        return response()->json($result);
    }

    /**
     * GET /api/sharing/{kind}/{resourceId}
     */
    public function shares(Request $request, string $kind, string $resourceId): JsonResponse
    {
        $user = $request->user();
        $kind = $this->normalizeKind($kind);
        $row = $this->findResource($kind, $resourceId);
        $this->requireOwner($user, $kind, $row);

        $rows = ContentShare::query()
            ->join('users', 'users.id', '=', 'content_shares.recipient_id')
            ->where('content_shares.kind', $kind)
            ->where('content_shares.resource_id', $row->id)
            ->select([
                'users.id',
                'users.name',
                'users.email',
                'content_shares.can_edit',
            ])
            ->get();

        return response()->json($rows->map(fn ($r) => [
            'id'      => (string) $r->id,
            'name'    => (string) $r->name,
            'email'   => (string) $r->email,
            'canEdit' => (bool) $r->can_edit,
        ])->values());
    }

    /**
     * PUT /api/sharing/{kind}/{resourceId}
     */
    public function share(Request $request, string $kind, string $resourceId): JsonResponse
    {
        $user = $request->user();
        $kind = $this->normalizeKind($kind);
        $row = $this->findResource($kind, $resourceId);
        $this->requireOwner($user, $kind, $row);

        $validated = $request->validate([
            'email'    => ['required', 'string', 'min:3', 'max:255'],
            'can_edit' => ['nullable', 'boolean'],
            'canEdit'  => ['nullable', 'boolean'],
        ]);

        $email = mb_strtolower(trim($validated['email']));
        $canEdit = (bool) ($validated['can_edit'] ?? $validated['canEdit'] ?? false);

        $target = User::whereRaw('LOWER(email) = ?', [$email])
            ->where('status', 'Active')
            ->whereIn('role', $this->instructorRoles())
            ->first();

        $ownerId = $this->ownerId($kind, $row);
        if (!$target || $target->id === $ownerId) {
            return response()->json([
                'detail'  => 'Hãy chọn email giảng viên khác đang hoạt động.',
                'message' => 'Hãy chọn email giảng viên khác đang hoạt động.',
            ], 422);
        }

        if (!$this->canManageKind($target, $kind)) {
            abort(403, 'Người nhận không có quyền quản lý nội dung này.');
        }

        $exists = DB::table('content_shares')
            ->where('kind', $kind)
            ->where('resource_id', $row->id)
            ->where('recipient_id', $target->id)
            ->exists();

        if ($exists) {
            DB::table('content_shares')
                ->where('kind', $kind)
                ->where('resource_id', $row->id)
                ->where('recipient_id', $target->id)
                ->update(['can_edit' => $canEdit]);
        } else {
            DB::table('content_shares')->insert([
                'kind'         => $kind,
                'resource_id'  => $row->id,
                'recipient_id' => $target->id,
                'can_edit'     => $canEdit,
                'created_at'   => now(),
            ]);
        }

        $link = $kind === 'problem'
            ? "/teacher/problems/{$row->id}/preview"
            : "/teacher/database-library/{$row->id}/preview";

        Notification::create([
            'id'         => (string) Str::uuid(),
            'user_id'    => $target->id,
            'title'      => 'Nội dung được chia sẻ',
            'message'    => "{$user->name} đã chia sẻ {$row->title} với bạn.",
            'type'       => 'content_share',
            'is_read'    => false,
            'link'       => $link,
            'created_at' => now(),
        ]);

        ActivityLog::create([
            'id'         => (string) Str::uuid(),
            'user_id'    => $user->id,
            'action'     => "content:share:{$kind}:{$row->id}:{$target->id}:edit=" . ($canEdit ? 'True' : 'False'),
            'created_at' => now(),
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * DELETE /api/sharing/{kind}/{resourceId}/{recipientId}
     */
    public function revoke(Request $request, string $kind, string $resourceId, string $recipientId): JsonResponse
    {
        $user = $request->user();
        $kind = $this->normalizeKind($kind);
        $row = $this->findResource($kind, $resourceId);
        $this->requireOwner($user, $kind, $row);

        DB::table('content_shares')
            ->where('kind', $kind)
            ->where('resource_id', $row->id)
            ->where('recipient_id', $recipientId)
            ->delete();

        ActivityLog::create([
            'id'         => (string) Str::uuid(),
            'user_id'    => $user->id,
            'action'     => "content:revoke:{$kind}:{$row->id}:{$recipientId}",
            'created_at' => now(),
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * POST /api/sharing/{kind}/{resourceId}/copy
     */
    public function copy(Request $request, string $kind, string $resourceId): JsonResponse
    {
        $user = $request->user();
        $kind = $this->normalizeKind($kind);
        $row = $this->findResource($kind, $resourceId);
        $this->requireRecipientAccess($user, $kind, $row);

        $cloned = DB::transaction(function () use ($kind, $row, $user) {
            if ($kind === 'library') {
                /** @var DatabaseLibraryEntry $copy */
                $copy = $row->replicate();
                $copy->id = (string) Str::uuid();
                $copy->instructor_id = $user->id;
                $copy->revision = 1;
                $copy->created_at = now();
                $copy->updated_at = now();
                $copy->save();

                return $copy;
            }

            /** @var Problem $row */
            /** @var Problem $copy */
            $copy = $row->replicate();
            $copy->id = (string) Str::uuid();
            $copy->creator_id = $user->id;
            $copy->created_at = now();
            $copy->updated_at = now();
            $copy->save();

            // Copy topic relations
            $topicIds = DB::table('problem_topics')
                ->where('problem_id', $row->id)
                ->pluck('topic_id')
                ->all();
            foreach ($topicIds as $topicId) {
                DB::table('problem_topics')->insert([
                    'problem_id' => $copy->id,
                    'topic_id'   => $topicId,
                ]);
            }

            // Copy SQL solutions
            foreach (ProblemSqlSolution::where('problem_id', $row->id)->get() as $sol) {
                $newSol = $sol->replicate();
                $newSol->id = (string) Str::uuid();
                $newSol->problem_id = $copy->id;
                $newSol->save();
            }

            // Copy stage exercises
            foreach (ProblemStageExercise::where('problem_id', $row->id)->get() as $stage) {
                $newStage = $stage->replicate();
                $newStage->id = (string) Str::uuid();
                $newStage->problem_id = $copy->id;
                $newStage->save();
            }

            // Copy test cases and their scripts
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
        });

        ActivityLog::create([
            'id'         => (string) Str::uuid(),
            'user_id'    => $user->id,
            'action'     => "content:copy:{$kind}:{$row->id}",
            'created_at' => now(),
        ]);

        return response()->json([
            'id'    => (string) $cloned->id,
            'title' => (string) $cloned->title,
        ]);
    }

    /**
     * GET /api/sharing/{kind}/{resourceId}/preview
     */
    public function preview(Request $request, string $kind, string $resourceId): JsonResponse
    {
        $user = $request->user();
        $kind = $this->normalizeKind($kind);
        $row = $this->findResource($kind, $resourceId);
        $this->requireRecipientAccess($user, $kind, $row);

        $canEdit = $this->canEdit($user, $kind, $row);

        if ($kind === 'library') {
            /** @var DatabaseLibraryEntry $row */
            $bundle = is_array($row->bundle_json) ? $row->bundle_json : [];
            $seedParts = [];
            foreach ($row->datasets as $dataset) {
                if (!empty($dataset['sql'])) {
                    $seedParts[] = $dataset['sql'];
                }
            }

            return response()->json([
                'title'     => (string) $row->title,
                'canEdit'   => $canEdit,
                'statement' => (string) ($bundle['statement'] ?? ''),
                'schemaSql' => (string) ($row->create_table_sql ?? ''),
                'seedSql'   => implode("\n\n", $seedParts),
            ]);
        }

        /** @var Problem $row */
        $view = $this->problemService->getProblemById($row->id, $user);

        return response()->json([
            'title'     => (string) $row->title,
            'canEdit'   => $canEdit,
            'statement' => trim((string) ($row->description ?? '') . "\n\n" . (string) ($row->requirements ?? '')),
            'schemaSql' => (string) ($view['schema'] ?? $view['schema_sql'] ?? $view['schemaSql'] ?? ''),
            'seedSql'   => (string) ($view['seedData'] ?? $view['seed_data'] ?? ''),
        ]);
    }

    private function normalizeKind(string $kind): string
    {
        if (!in_array($kind, ['problem', 'library'], true)) {
            abort(422, 'Loại nội dung chia sẻ không hợp lệ.');
        }

        return $kind;
    }

    private function findResource(string $kind, string $resourceId): Problem|DatabaseLibraryEntry
    {
        $row = $kind === 'problem'
            ? Problem::find($resourceId)
            : DatabaseLibraryEntry::find($resourceId);

        if (!$row) {
            abort(404, 'Không tìm thấy nội dung.');
        }

        return $row;
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
        if ($user->isAdmin()) {
            return true;
        }
        if ($this->ownerId($kind, $row) === $user->id) {
            return true;
        }

        $grant = $this->getGrant($kind, $row->id, $user->id);

        return (bool) ($grant && $grant->can_edit);
    }

    private function assertCanShare(User $user, string $kind): void
    {
        $allowed = $user->isAdmin()
            || $user->isInstructor()
            || $user->canDo('PROBLEM_MGMT', 'edit');

        abort_unless($allowed, 403, 'Bạn không có quyền chia sẻ nội dung này.');
    }

    private function canManageKind(User $user, string $kind): bool
    {
        return $user->isAdmin()
            || $user->isInstructor()
            || $user->canDo('PROBLEM_MGMT', 'add');
    }

    private function requireOwner(User $user, string $kind, Problem|DatabaseLibraryEntry $row): void
    {
        $this->assertCanShare($user, $kind);

        if (!$user->isAdmin() && $this->ownerId($kind, $row) !== $user->id) {
            abort(403, 'Chỉ chủ sở hữu được quản lý chia sẻ.');
        }
    }

    private function requireRecipientAccess(User $user, string $kind, Problem|DatabaseLibraryEntry $row): void
    {
        abort_unless($this->canManageKind($user, $kind), 403, 'Bạn không có quyền truy cập nội dung này.');

        if ($user->isAdmin() || $this->ownerId($kind, $row) === $user->id) {
            return;
        }

        if (!$this->getGrant($kind, $row->id, $user->id)) {
            abort(403, 'Nội dung chưa được chia sẻ cho bạn.');
        }
    }

    private function instructorRoles(): array
    {
        return array_values(array_unique(array_merge(
            User::INSTRUCTOR_ROLES,
            [User::ROLE_DEPARTMENT_HEAD]
        )));
    }
}
