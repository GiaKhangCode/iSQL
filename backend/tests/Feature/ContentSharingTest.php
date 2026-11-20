<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\ContentShare;
use App\Models\DatabaseLibraryEntry;
use App\Models\Notification;
use App\Models\Problem;
use App\Models\ProblemSqlSolution;
use App\Models\ProblemStageExercise;
use App\Models\TestCase as ProblemTestCase;
use App\Models\TestCaseScript;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\AccountFixture;
use Tests\TestCase;

/**
 * Chia sẻ bài toán / cơ sở dữ liệu mẫu giữa giảng viên (/api/sharing/*).
 * Test chạy trên DB dev với DatabaseTransactions, dùng email/tên duy nhất.
 */
class ContentSharingTest extends TestCase
{
    use DatabaseTransactions;

    protected User $owner;
    protected User $peer;
    protected User $other;
    protected User $admin;
    protected User $student;
    protected string $tag;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tag = 'Shr' . substr(uniqid(), -7);

        $this->owner = $this->makeUser('instructor', "Owner {$this->tag}");
        $this->peer = $this->makeUser('instructor', "Peer {$this->tag}");
        $this->other = $this->makeUser('instructor', "Other {$this->tag}");
        $this->admin = $this->makeUser('admin', "Admin {$this->tag}");
        $this->student = $this->makeUser('student', "Student {$this->tag}");
    }

    protected function makeUser(string $role, string $name, string $status = 'Active'): User
    {
        return User::create([
            'name'            => $name,
            'email'           => strtolower(str_replace(' ', '_', $name)) . '_' . uniqid() . '@uit.edu.vn',
            'hashed_password' => Hash::make('password123'),
            ...AccountFixture::for($role),
            'status'          => $status,
            'initials'        => 'XX',
        ]);
    }

    protected function makeProblem(User $creator, string $title = null): Problem
    {
        return Problem::create([
            'number'          => 'SHR-' . strtoupper(Str::random(8)),
            'title'           => $title ?? "Problem {$this->tag}",
            'difficulty'      => 'Easy',
            'description'     => 'Mô tả đề',
            'requirements'    => 'Yêu cầu đề',
            'practice_listed' => false,
            'creator_id'      => $creator->id,
        ]);
    }

    protected function makeLibrary(?User $owner, string $title = null): DatabaseLibraryEntry
    {
        return DatabaseLibraryEntry::create([
            'id'               => (string) Str::uuid(),
            'instructor_id'    => $owner?->id,
            'title'            => $title ?? "Library {$this->tag}",
            'create_table_sql' => 'CREATE TABLE T (id INT);',
            'insert_into_sql'  => 'INSERT INTO T VALUES (1);',
            'datasets_json'    => [['fileName' => 'a.sql', 'sql' => 'INSERT INTO T VALUES (1);'], ['fileName' => 'b.sql', 'sql' => 'INSERT INTO T VALUES (2);']],
            'bundle_json'      => ['statement' => 'Mô tả bộ dữ liệu'],
            'revision'         => 3,
        ]);
    }

    protected function grant(string $kind, string $resourceId, User $recipient, bool $canEdit = false): void
    {
        ContentShare::create([
            'kind' => $kind, 'resource_id' => $resourceId, 'recipient_id' => $recipient->id,
            'can_edit' => $canEdit, 'created_at' => now(),
        ]);
    }

    // ───────────────────────── truy cập chung ─────────────────────────

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/sharing')->assertStatus(401);
        $this->putJson('/api/sharing/problem/x', ['email' => 'a@b.c'])->assertStatus(401);
    }

    public function test_unknown_kind_is_422_and_unknown_resource_is_404(): void
    {
        Sanctum::actingAs($this->owner);
        $this->getJson('/api/sharing/banana/abc')->assertStatus(422);
        $this->getJson('/api/sharing/problem/' . Str::uuid())->assertStatus(404);
        $this->getJson('/api/sharing/library/' . Str::uuid())->assertStatus(404);
    }

    // ───────────────────────── tìm người nhận ─────────────────────────

    public function test_recipient_search_finds_active_instructors_only(): void
    {
        $inactive = $this->makeUser('instructor', "Inactive {$this->tag}", 'Inactive');
        $head = $this->makeUser('department_head', "Head {$this->tag}");

        Sanctum::actingAs($this->owner);
        $res = $this->getJson('/api/sharing/recipients/search?kind=problem&q=' . $this->tag)->assertOk();
        $ids = collect($res->json())->pluck('id');

        $this->assertTrue($ids->contains($this->peer->id));
        $this->assertTrue($ids->contains($head->id), 'trưởng bộ môn cũng nhận được');
        $this->assertFalse($ids->contains($this->owner->id), 'không tự chia sẻ cho mình');
        $this->assertFalse($ids->contains($this->student->id), 'sinh viên không phải người nhận');
        $this->assertFalse($ids->contains($inactive->id), 'tài khoản không Active bị loại');
        $this->assertFalse($ids->contains($this->admin->id), 'admin không nằm trong danh sách người nhận');

        $row = collect($res->json())->firstWhere('id', $this->peer->id);
        $this->assertSame(['id', 'name', 'email'], array_keys($row));
        $this->assertSame($this->peer->email, $row['email']);
    }

    public function test_recipient_search_needs_two_characters_and_escapes_wildcards(): void
    {
        Sanctum::actingAs($this->owner);
        $this->assertSame([], $this->getJson('/api/sharing/recipients/search?kind=problem&q=a')->assertOk()->json());
        $this->assertSame([], $this->getJson('/api/sharing/recipients/search?kind=problem')->assertOk()->json());
        // '%%' không được hiểu là ký tự đại diện: không có ai có tên chứa '%%'.
        $this->assertSame([], $this->getJson('/api/sharing/recipients/search?kind=problem&q=' . urlencode('%%'))->assertOk()->json());
    }

    public function test_recipient_search_is_limited_to_thirty_results_and_sorted_by_name(): void
    {
        for ($i = 0; $i < 32; $i++) {
            $this->makeUser('instructor', sprintf('Bulk %s %02d', $this->tag, $i));
        }
        Sanctum::actingAs($this->owner);
        $res = $this->getJson('/api/sharing/recipients/search?kind=library&q=Bulk+' . $this->tag)->assertOk();
        $this->assertCount(30, $res->json());
        $names = collect($res->json())->pluck('name')->all();
        $sorted = $names;
        sort($sorted);
        $this->assertSame($sorted, $names);
    }

    public function test_students_cannot_search_recipients(): void
    {
        Sanctum::actingAs($this->student);
        $this->getJson('/api/sharing/recipients/search?kind=problem&q=ab')->assertStatus(403);
        // Không có quyền nào liên quan thì bị chặn trước khi kiểm tra loại nội dung.
        $this->getJson('/api/sharing/recipients/search?kind=zzz&q=ab')->assertStatus(403);

        Sanctum::actingAs($this->owner);
        $this->getJson('/api/sharing/recipients/search?kind=zzz&q=ab')->assertStatus(422);
    }

    // ───────────────────────── chia sẻ / thu hồi ─────────────────────────

    public function test_owner_shares_a_problem_and_the_recipient_is_notified(): void
    {
        $problem = $this->makeProblem($this->owner);
        Sanctum::actingAs($this->owner);

        $this->putJson("/api/sharing/problem/{$problem->id}", ['email' => strtoupper($this->peer->email), 'canEdit' => true])
            ->assertOk()->assertJson(['ok' => true]);

        $grant = ContentShare::where('kind', 'problem')->where('resource_id', $problem->id)->where('recipient_id', $this->peer->id)->first();
        $this->assertNotNull($grant);
        $this->assertTrue($grant->can_edit);

        $note = Notification::where('user_id', $this->peer->id)->where('type', 'content_share')->first();
        $this->assertNotNull($note);
        $this->assertSame("/teacher/problems/{$problem->id}/preview", $note->link);
        $this->assertStringContainsString($this->owner->name, $note->message);

        $this->assertTrue(ActivityLog::where('user_id', $this->owner->id)
            ->where('action', "content:share:problem:{$problem->id}:{$this->peer->id}:edit=True")->exists());

        $list = $this->getJson("/api/sharing/problem/{$problem->id}")->assertOk()->json();
        $this->assertSame([['id' => $this->peer->id, 'name' => $this->peer->name, 'email' => $this->peer->email, 'canEdit' => true]], $list);
    }

    public function test_sharing_again_updates_can_edit_without_duplicating(): void
    {
        $lib = $this->makeLibrary($this->owner);
        Sanctum::actingAs($this->owner);

        $this->putJson("/api/sharing/library/{$lib->id}", ['email' => $this->peer->email, 'can_edit' => true])->assertOk();
        $this->putJson("/api/sharing/library/{$lib->id}", ['email' => $this->peer->email])->assertOk();

        $this->assertSame(1, ContentShare::where('kind', 'library')->where('resource_id', $lib->id)->count());
        $this->assertFalse(ContentShare::where('resource_id', $lib->id)->first()->can_edit);
        $this->assertSame("/teacher/database-library/{$lib->id}/preview",
            Notification::where('user_id', $this->peer->id)->where('type', 'content_share')->latest('created_at')->first()->link);
    }

    public function test_share_validation_and_invalid_targets(): void
    {
        $problem = $this->makeProblem($this->owner);
        $inactive = $this->makeUser('instructor', "Idle {$this->tag}", 'Inactive');
        Sanctum::actingAs($this->owner);
        $url = "/api/sharing/problem/{$problem->id}";

        $this->putJson($url, [])->assertStatus(422)->assertJsonValidationErrors(['email']);
        $this->putJson($url, ['email' => 'ab'])->assertStatus(422);
        $this->putJson($url, ['email' => $this->peer->email, 'canEdit' => 'maybe'])->assertStatus(422);

        $msg = 'Hãy chọn email giảng viên khác đang hoạt động.';
        foreach ([$this->owner->email, $this->student->email, $inactive->email, 'nobody_' . uniqid() . '@uit.edu.vn'] as $email) {
            $this->putJson($url, ['email' => $email])->assertStatus(422)->assertJsonPath('detail', $msg)->assertJsonPath('message', $msg);
        }

        $this->assertSame(0, ContentShare::where('resource_id', $problem->id)->count());
        $this->assertSame(0, Notification::where('user_id', $this->peer->id)->where('type', 'content_share')->count());
    }

    public function test_only_the_owner_or_admin_manages_shares(): void
    {
        $problem = $this->makeProblem($this->owner);
        $this->grant('problem', $problem->id, $this->peer, true);

        foreach ([$this->other, $this->peer, $this->student] as $intruder) {
            Sanctum::actingAs($intruder);
            $this->getJson("/api/sharing/problem/{$problem->id}")->assertStatus(403);
            $this->putJson("/api/sharing/problem/{$problem->id}", ['email' => $this->other->email])->assertStatus(403);
            $this->deleteJson("/api/sharing/problem/{$problem->id}/{$this->peer->id}")->assertStatus(403);
        }
        $this->assertSame(1, ContentShare::where('resource_id', $problem->id)->count());

        Sanctum::actingAs($this->admin);
        $this->getJson("/api/sharing/problem/{$problem->id}")->assertOk();
        $this->putJson("/api/sharing/problem/{$problem->id}", ['email' => $this->other->email])->assertOk();
        $this->assertSame(2, ContentShare::where('resource_id', $problem->id)->count());
    }

    public function test_revoke_removes_the_grant_and_logs_it(): void
    {
        $lib = $this->makeLibrary($this->owner);
        $this->grant('library', $lib->id, $this->peer);

        Sanctum::actingAs($this->owner);
        $this->deleteJson("/api/sharing/library/{$lib->id}/{$this->peer->id}")->assertOk()->assertJson(['ok' => true]);

        $this->assertSame(0, ContentShare::where('resource_id', $lib->id)->count());
        $this->assertTrue(ActivityLog::where('user_id', $this->owner->id)->where('action', "content:revoke:library:{$lib->id}:{$this->peer->id}")->exists());
        // Thu hồi người chưa được chia sẻ vẫn trả ok (idempotent).
        $this->deleteJson("/api/sharing/library/{$lib->id}/{$this->peer->id}")->assertOk();
    }

    public function test_revoke_only_removes_the_named_recipient(): void
    {
        $lib = $this->makeLibrary($this->owner);
        $this->grant('library', $lib->id, $this->peer);
        $this->grant('library', $lib->id, $this->other);

        Sanctum::actingAs($this->owner);
        $this->deleteJson("/api/sharing/library/{$lib->id}/{$this->peer->id}")->assertOk();

        $remaining = ContentShare::where('resource_id', $lib->id)->pluck('recipient_id')->all();
        $this->assertSame([$this->other->id], $remaining);
    }

    public function test_a_system_library_entry_has_no_owner_so_only_admin_manages_it(): void
    {
        $system = $this->makeLibrary(null);
        Sanctum::actingAs($this->owner);
        $this->getJson("/api/sharing/library/{$system->id}")->assertStatus(403);

        Sanctum::actingAs($this->admin);
        $this->putJson("/api/sharing/library/{$system->id}", ['email' => $this->peer->email])->assertOk();
    }

    // ───────────────────────── hộp thư "được chia sẻ" ─────────────────────────

    public function test_inbox_lists_shared_content_newest_first_and_skips_missing_rows(): void
    {
        $problem = $this->makeProblem($this->owner, "Inbox Problem {$this->tag}");
        $lib = $this->makeLibrary($this->owner, "Inbox Library {$this->tag}");
        $this->grant('problem', $problem->id, $this->peer, false);
        $this->grant('library', $lib->id, $this->peer, true);
        $this->grant('problem', (string) Str::uuid(), $this->peer); // nội dung đã bị xóa
        // Sắp xếp theo created_at giảm dần: ép thời gian để kết quả ổn định.
        DB::table('content_shares')->where('resource_id', $problem->id)->update(['created_at' => now()->subHour()]);

        Sanctum::actingAs($this->peer);
        $rows = $this->getJson('/api/sharing')->assertOk()->json();

        $this->assertSame([
            ['kind' => 'library', 'id' => $lib->id, 'title' => "Inbox Library {$this->tag}", 'owner' => $this->owner->name, 'canEdit' => true],
            ['kind' => 'problem', 'id' => $problem->id, 'title' => "Inbox Problem {$this->tag}", 'owner' => $this->owner->name, 'canEdit' => false],
        ], $rows);

        Sanctum::actingAs($this->other);
        $this->assertSame([], $this->getJson('/api/sharing')->assertOk()->json());
    }

    public function test_inbox_hides_content_from_accounts_that_cannot_manage_content(): void
    {
        $problem = $this->makeProblem($this->owner);
        $this->grant('problem', $problem->id, $this->student);

        Sanctum::actingAs($this->student);
        $this->assertSame([], $this->getJson('/api/sharing')->assertOk()->json());
    }

    // ───────────────────────── xem trước ─────────────────────────

    public function test_preview_of_a_library_entry(): void
    {
        $lib = $this->makeLibrary($this->owner);
        $this->grant('library', $lib->id, $this->peer, false);

        Sanctum::actingAs($this->peer);
        $this->getJson("/api/sharing/library/{$lib->id}/preview")->assertOk()->assertExactJson([
            'title'     => $lib->title,
            'canEdit'   => false,
            'statement' => 'Mô tả bộ dữ liệu',
            'schemaSql' => 'CREATE TABLE T (id INT);',
            'seedSql'   => "INSERT INTO T VALUES (1);\n\nINSERT INTO T VALUES (2);",
        ]);

        Sanctum::actingAs($this->owner);
        $this->assertTrue($this->getJson("/api/sharing/library/{$lib->id}/preview")->assertOk()->json('canEdit'));
    }

    public function test_preview_of_a_problem_and_can_edit_flag(): void
    {
        $problem = $this->makeProblem($this->owner);
        $this->grant('problem', $problem->id, $this->peer, true);

        Sanctum::actingAs($this->peer);
        $res = $this->getJson("/api/sharing/problem/{$problem->id}/preview")->assertOk();
        $this->assertSame($problem->title, $res->json('title'));
        $this->assertTrue($res->json('canEdit'));
        $this->assertSame("Mô tả đề\n\nYêu cầu đề", $res->json('statement'));
        $this->assertArrayHasKey('schemaSql', $res->json());
        $this->assertArrayHasKey('seedSql', $res->json());

        Sanctum::actingAs($this->admin);
        $this->assertTrue($this->getJson("/api/sharing/problem/{$problem->id}/preview")->assertOk()->json('canEdit'));
    }

    public function test_preview_and_copy_require_a_grant_unless_owner_or_admin(): void
    {
        $problem = $this->makeProblem($this->owner);
        $lib = $this->makeLibrary($this->owner);

        Sanctum::actingAs($this->other);
        $this->getJson("/api/sharing/problem/{$problem->id}/preview")->assertStatus(403);
        $this->getJson("/api/sharing/library/{$lib->id}/preview")->assertStatus(403);
        $this->postJson("/api/sharing/problem/{$problem->id}/copy")->assertStatus(403);
        $this->postJson("/api/sharing/library/{$lib->id}/copy")->assertStatus(403);

        Sanctum::actingAs($this->student);
        $this->getJson("/api/sharing/library/{$lib->id}/preview")->assertStatus(403);

        Sanctum::actingAs($this->admin);
        $this->getJson("/api/sharing/library/{$lib->id}/preview")->assertOk();
        $this->postJson("/api/sharing/library/{$lib->id}/copy")->assertOk();
    }

    // ───────────────────────── sao chép ─────────────────────────

    public function test_copy_a_library_entry(): void
    {
        $lib = $this->makeLibrary($this->owner);
        $this->grant('library', $lib->id, $this->peer);

        Sanctum::actingAs($this->peer);
        $res = $this->postJson("/api/sharing/library/{$lib->id}/copy")->assertOk();

        $this->assertNotSame($lib->id, $res->json('id'));
        $this->assertSame($lib->title, $res->json('title'));
        $copy = DatabaseLibraryEntry::findOrFail($res->json('id'));
        $this->assertSame($this->peer->id, $copy->instructor_id);
        $this->assertSame(1, $copy->revision, 'bản sao bắt đầu lại từ revision 1');
        $this->assertSame($lib->create_table_sql, $copy->create_table_sql);
        $this->assertSame($lib->datasets_json, $copy->datasets_json);
        $this->assertSame($lib->bundle_json, $copy->bundle_json);
        $this->assertSame($this->owner->id, $lib->fresh()->instructor_id, 'bản gốc không đổi');
        $this->assertTrue(ActivityLog::where('user_id', $this->peer->id)->where('action', "content:copy:library:{$lib->id}")->exists());
    }

    public function test_copy_a_problem_duplicates_its_related_rows(): void
    {
        $problem = $this->makeProblem($this->owner);
        $topicId = DB::table('topics')->value('id');
        if ($topicId) {
            DB::table('problem_topics')->insert(['problem_id' => $problem->id, 'topic_id' => $topicId]);
        }
        $solution = ProblemSqlSolution::create([
            'id' => (string) Str::uuid(), 'problem_id' => $problem->id, 'name' => 'Lời giải 1', 'query' => 'SELECT 1', 'enabled' => true, 'order_index' => 0,
        ]);
        ProblemStageExercise::create(['id' => (string) Str::uuid(), 'problem_id' => $problem->id, 'part' => 'DDL', 'config' => ['k' => 'v']]);
        $case = ProblemTestCase::create(['id' => (string) Str::uuid(), 'problem_id' => $problem->id, 'is_hidden' => true, 'order_index' => 0, 'expected_query' => 'SELECT 2']);
        TestCaseScript::create([
            'id' => (string) Str::uuid(), 'test_case_id' => $case->id, 'table_name' => 'T',
            'create_script' => 'CREATE TABLE T (id INT)', 'insert_script' => 'INSERT INTO T VALUES (1)', 'is_expected' => false,
        ]);
        $this->grant('problem', $problem->id, $this->peer);

        Sanctum::actingAs($this->peer);
        $res = $this->postJson("/api/sharing/problem/{$problem->id}/copy")->assertOk();
        $newId = $res->json('id');

        $this->assertNotSame($problem->id, $newId);
        $copy = Problem::findOrFail($newId);
        $this->assertSame($this->peer->id, $copy->creator_id);
        $this->assertSame($problem->title, $copy->title);
        $this->assertSame($problem->description, $copy->description);
        $this->assertSame($this->owner->id, $problem->fresh()->creator_id);

        $this->assertSame(1, ProblemSqlSolution::where('problem_id', $newId)->count());
        $this->assertSame('SELECT 1', ProblemSqlSolution::where('problem_id', $newId)->first()->query);
        $this->assertSame(1, ProblemStageExercise::where('problem_id', $newId)->count());
        $newCase = ProblemTestCase::where('problem_id', $newId)->first();
        $this->assertNotNull($newCase);
        $this->assertNotSame($case->id, $newCase->id);
        $this->assertTrue($newCase->is_hidden);
        $this->assertSame(1, TestCaseScript::where('test_case_id', $newCase->id)->count());
        if ($topicId) {
            $this->assertSame(1, DB::table('problem_topics')->where('problem_id', $newId)->count());
        }
        // Dòng gốc giữ nguyên.
        $this->assertSame(1, ProblemSqlSolution::where('problem_id', $problem->id)->count());
        $this->assertSame($solution->id, ProblemSqlSolution::where('problem_id', $problem->id)->first()->id);
        $this->assertTrue(ActivityLog::where('user_id', $this->peer->id)->where('action', "content:copy:problem:{$problem->id}")->exists());
    }

    public function test_the_owner_can_copy_their_own_content(): void
    {
        $lib = $this->makeLibrary($this->owner);
        Sanctum::actingAs($this->owner);
        $res = $this->postJson("/api/sharing/library/{$lib->id}/copy")->assertOk();
        $this->assertSame($this->owner->id, DatabaseLibraryEntry::findOrFail($res->json('id'))->instructor_id);
    }

    // ───────────────────────── quyền theo vai trò mới (capability), không theo loại tài khoản ─────────────────────────

    /** Thay toàn bộ vai trò của người dùng bằng một vai trò tùy chỉnh chỉ có các quyền cho trước. */
    private function onlyPermissions(User $user, array $permissions): User
    {
        DB::table('user_access_roles')->where('user_id', $user->id)->delete();
        $role = \App\Models\AccessRole::create(['name' => 'Test ' . uniqid(), 'is_super' => false]);
        $role->syncPermissions($permissions);
        $user->accessRoles()->attach($role->id);
        app(\App\Services\Access\AccessControlService::class)->forget();

        return $user;
    }

    public function test_sharing_works_between_accounts_whose_roles_grant_the_permissions_even_if_users_role_says_student(): void
    {
        $sender = $this->onlyPermissions($this->makeUser('student', "Sender {$this->tag}"), ['problem.create', 'problem.share']);
        $receiver = $this->onlyPermissions($this->makeUser('student', "Receiver {$this->tag}"), ['problem.create']);
        $problem = $this->makeProblem($sender);

        Sanctum::actingAs($sender);
        $found = collect($this->getJson('/api/sharing/recipients/search?kind=problem&q=Receiver+' . $this->tag)->assertOk()->json())->pluck('id');
        $this->assertTrue($found->contains($receiver->id));
        $this->putJson("/api/sharing/problem/{$problem->id}", ['email' => $receiver->email])->assertOk();

        Sanctum::actingAs($receiver);
        $this->assertCount(1, $this->getJson('/api/sharing')->assertOk()->json());
        $this->getJson("/api/sharing/problem/{$problem->id}/preview")->assertOk();
        $this->postJson("/api/sharing/problem/{$problem->id}/copy")->assertOk();
    }

    public function test_an_instructor_account_whose_roles_were_stripped_cannot_share_receive_or_be_found(): void
    {
        $stripped = $this->onlyPermissions($this->makeUser('instructor', "Stripped {$this->tag}"), ['practice.solve']);
        $problem = $this->makeProblem($this->owner);
        $this->grant('problem', $problem->id, $stripped);

        Sanctum::actingAs($this->owner);
        $this->assertSame([], $this->getJson('/api/sharing/recipients/search?kind=problem&q=Stripped+' . $this->tag)->assertOk()->json());
        $this->putJson("/api/sharing/problem/{$problem->id}", ['email' => $stripped->email])->assertStatus(422);

        Sanctum::actingAs($stripped);
        $this->assertSame([], $this->getJson('/api/sharing')->assertOk()->json());
        $this->getJson("/api/sharing/problem/{$problem->id}/preview")->assertStatus(403);
        $this->postJson("/api/sharing/problem/{$problem->id}/copy")->assertStatus(403);
        $this->getJson('/api/sharing/recipients/search?kind=problem&q=ab')->assertStatus(403);
    }

    public function test_share_permission_and_receive_permission_are_checked_per_kind(): void
    {
        $lib = $this->makeLibrary($this->owner);
        $problem = $this->makeProblem($this->owner);
        // Người gửi chỉ được chia sẻ bài toán, không được chia sẻ thư viện.
        $this->onlyPermissions($this->owner, ['problem.create', 'problem.share', 'library.manage']);
        // Người nhận chỉ quản lý bài toán, không quản lý thư viện.
        $problemOnly = $this->onlyPermissions($this->makeUser('instructor', "ProbOnly {$this->tag}"), ['problem.create']);

        Sanctum::actingAs($this->owner);
        $this->putJson("/api/sharing/library/{$lib->id}", ['email' => $problemOnly->email])->assertStatus(403);
        $this->getJson('/api/sharing/recipients/search?kind=library&q=ab')->assertStatus(403);
        $this->putJson("/api/sharing/problem/{$problem->id}", ['email' => $problemOnly->email])->assertOk();

        // Người nhận chỉ có quyền bài toán: không nhận được thư viện dù có dòng chia sẻ.
        $this->grant('library', $lib->id, $problemOnly);
        Sanctum::actingAs($problemOnly);
        $kinds = collect($this->getJson('/api/sharing')->assertOk()->json())->pluck('kind')->all();
        $this->assertSame(['problem'], $kinds);
        $this->getJson("/api/sharing/library/{$lib->id}/preview")->assertStatus(403);
    }

    public function test_super_admin_cannot_be_targeted_as_a_recipient(): void
    {
        $problem = $this->makeProblem($this->owner);
        Sanctum::actingAs($this->owner);
        $this->putJson("/api/sharing/problem/{$problem->id}", ['email' => $this->admin->email])->assertStatus(422);
    }
}
