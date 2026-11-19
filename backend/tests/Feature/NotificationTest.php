<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\AccountFixture;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use DatabaseTransactions;

    protected User $userA;
    protected User $userB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userA = $this->makeUser('A');
        $this->userB = $this->makeUser('B');
    }

    private function makeUser(string $tag): User
    {
        return User::create([
            'name'            => "Notification User {$tag}",
            'email'           => 'notif_' . strtolower($tag) . '_' . uniqid() . '@gm.uit.edu.vn',
            'hashed_password' => Hash::make('password123'),
            ...AccountFixture::for('student'),
            'status'          => 'Active',
            'initials'        => "N{$tag}",
        ]);
    }

    private function makeNotification(User $owner, array $overrides = []): Notification
    {
        return Notification::create(array_merge([
            'id'         => (string) Str::uuid(),
            'user_id'    => $owner->id,
            'title'      => 'Tiêu đề thử nghiệm',
            'message'    => 'Nội dung thử nghiệm',
            'type'       => 'info',
            'is_read'    => false,
            'link'       => '/assignments',
            'created_at' => now(),
        ], $overrides));
    }

    // ── Xác thực ────────────────────────────────────────────────────────────

    public function test_guest_cannot_access_notifications(): void
    {
        $this->getJson('/api/notifications')->assertStatus(401);
        $this->getJson('/api/notifications/unread-count')->assertStatus(401);
        $this->patchJson('/api/notifications/read-all')->assertStatus(401);
        $this->deleteJson('/api/notifications')->assertStatus(401);
    }

    // ── Danh sách ───────────────────────────────────────────────────────────

    public function test_index_returns_only_own_notifications_newest_first(): void
    {
        $older  = $this->makeNotification($this->userA, ['created_at' => now()->subHour()]);
        $newer  = $this->makeNotification($this->userA, ['created_at' => now()]);
        $others = $this->makeNotification($this->userB);

        Sanctum::actingAs($this->userA);

        $res = $this->getJson('/api/notifications');

        $res->assertOk()->assertJsonCount(2);
        $this->assertSame([$newer->id, $older->id], array_column($res->json(), 'id'));
        $this->assertNotContains($others->id, array_column($res->json(), 'id'));
    }

    public function test_index_uses_camel_case_fields_expected_by_frontend(): void
    {
        $this->makeNotification($this->userA, ['is_read' => true, 'link' => '/contests']);

        Sanctum::actingAs($this->userA);

        $res = $this->getJson('/api/notifications');

        $res->assertOk()
            ->assertJsonStructure([['id', 'title', 'message', 'type', 'isRead', 'link', 'createdAt']])
            ->assertJsonPath('0.isRead', true)
            ->assertJsonPath('0.link', '/contests');

        // Tên cột snake_case và user_id không được lộ ra ngoài.
        $item = $res->json('0');
        $this->assertArrayNotHasKey('is_read', $item);
        $this->assertArrayNotHasKey('created_at', $item);
        $this->assertArrayNotHasKey('user_id', $item);
        $this->assertNotNull($item['createdAt']);
    }

    // ── Đếm chưa đọc ────────────────────────────────────────────────────────

    public function test_unread_count_counts_only_own_unread_notifications(): void
    {
        $this->makeNotification($this->userA);
        $this->makeNotification($this->userA);
        $this->makeNotification($this->userA, ['is_read' => true]);
        $this->makeNotification($this->userB);

        Sanctum::actingAs($this->userA);

        $this->getJson('/api/notifications/unread-count')
            ->assertOk()
            ->assertExactJson(['count' => 2]);
    }

    // ── Đánh dấu một thông báo đã đọc ───────────────────────────────────────

    public function test_mark_read_updates_own_notification_and_is_idempotent(): void
    {
        $notification = $this->makeNotification($this->userA);

        Sanctum::actingAs($this->userA);

        $this->patchJson("/api/notifications/{$notification->id}/read")
            ->assertOk()
            ->assertJsonPath('id', $notification->id)
            ->assertJsonPath('isRead', true);

        // Gọi lần hai vẫn thành công, không lỗi.
        $this->patchJson("/api/notifications/{$notification->id}/read")
            ->assertOk()
            ->assertJsonPath('isRead', true);

        $this->assertTrue((bool) $notification->fresh()->is_read);
    }

    public function test_mark_read_cannot_touch_another_users_notification(): void
    {
        $theirs = $this->makeNotification($this->userB);

        Sanctum::actingAs($this->userA);

        $this->patchJson("/api/notifications/{$theirs->id}/read")->assertStatus(404);

        $this->assertFalse((bool) $theirs->fresh()->is_read);
    }

    public function test_mark_read_returns_404_for_unknown_id(): void
    {
        Sanctum::actingAs($this->userA);

        $this->patchJson('/api/notifications/' . Str::uuid() . '/read')->assertStatus(404);
    }

    // ── Đánh dấu tất cả đã đọc ──────────────────────────────────────────────

    public function test_mark_all_read_only_affects_own_notifications(): void
    {
        $a1     = $this->makeNotification($this->userA);
        $a2     = $this->makeNotification($this->userA);
        $aRead  = $this->makeNotification($this->userA, ['is_read' => true]);
        $theirs = $this->makeNotification($this->userB);

        Sanctum::actingAs($this->userA);

        $this->patchJson('/api/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('updated', 2);

        $this->assertTrue((bool) $a1->fresh()->is_read);
        $this->assertTrue((bool) $a2->fresh()->is_read);
        $this->assertTrue((bool) $aRead->fresh()->is_read);
        $this->assertFalse((bool) $theirs->fresh()->is_read);
    }

    // ── Xóa một thông báo ───────────────────────────────────────────────────

    public function test_destroy_deletes_own_notification(): void
    {
        $notification = $this->makeNotification($this->userA);

        Sanctum::actingAs($this->userA);

        $this->deleteJson("/api/notifications/{$notification->id}")->assertOk();

        $this->assertDatabaseMissing('notifications', ['id' => $notification->id]);
    }

    public function test_destroy_cannot_delete_another_users_notification(): void
    {
        $theirs = $this->makeNotification($this->userB);

        Sanctum::actingAs($this->userA);

        $this->deleteJson("/api/notifications/{$theirs->id}")->assertStatus(404);

        $this->assertDatabaseHas('notifications', ['id' => $theirs->id]);
    }

    // ── Xóa tất cả ──────────────────────────────────────────────────────────

    public function test_destroy_all_only_deletes_own_notifications(): void
    {
        $a1     = $this->makeNotification($this->userA);
        $a2     = $this->makeNotification($this->userA);
        $theirs = $this->makeNotification($this->userB);

        Sanctum::actingAs($this->userA);

        $this->deleteJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('deleted', 2);

        $this->assertDatabaseMissing('notifications', ['id' => $a1->id]);
        $this->assertDatabaseMissing('notifications', ['id' => $a2->id]);
        $this->assertDatabaseHas('notifications', ['id' => $theirs->id]);
    }

    // ── Tạo thông báo (NotificationService::create) ─────────────────────────

    public function test_create_persists_unread_notification_with_generated_id(): void
    {
        $notification = app(NotificationService::class)->create(
            $this->userA->id,
            'Bài tập mới: SQL cơ bản',
            'Giảng viên đã giao một bài mới.',
            'Assignment',
            '/assignments',
        );

        $this->assertTrue(Str::isUuid($notification->id));
        $this->assertDatabaseHas('notifications', [
            'id'      => $notification->id,
            'user_id' => $this->userA->id,
            'title'   => 'Bài tập mới: SQL cơ bản',
            'message' => 'Giảng viên đã giao một bài mới.',
            'type'    => 'Assignment',
            'link'    => '/assignments',
            'is_read' => false,
        ]);
        $this->assertNotNull($notification->fresh()->created_at);
    }

    public function test_create_without_link_stores_null_link(): void
    {
        $notification = app(NotificationService::class)->create(
            $this->userA->id,
            'Tiêu đề',
            'Nội dung',
            'Info',
        );

        $this->assertNull($notification->fresh()->link);
    }

    public function test_create_generates_a_distinct_id_each_time(): void
    {
        $service = app(NotificationService::class);

        $first  = $service->create($this->userA->id, 'Một', 'Nội dung', 'Info');
        $second = $service->create($this->userA->id, 'Hai', 'Nội dung', 'Info');

        $this->assertNotSame($first->id, $second->id);
    }

    // ── Tạo hàng loạt (NotificationService::createMany) ─────────────────────

    public function test_create_many_creates_one_unread_notification_per_recipient(): void
    {
        $count = app(NotificationService::class)->createMany(
            [$this->userA->id, $this->userB->id],
            'Bài tập mới: Truy vấn nâng cao',
            'Giảng viên đã giao một bài mới.',
            'Assignment',
            '/assignments',
        );

        $this->assertSame(2, $count);

        $rows = Notification::whereIn('user_id', [$this->userA->id, $this->userB->id])->get();
        $this->assertCount(2, $rows);
        $this->assertCount(2, $rows->pluck('id')->unique());

        foreach ($rows as $row) {
            $this->assertTrue(Str::isUuid($row->id));
            $this->assertSame('Bài tập mới: Truy vấn nâng cao', $row->title);
            $this->assertSame('Assignment', $row->type);
            $this->assertSame('/assignments', $row->link);
            $this->assertFalse((bool) $row->is_read);
            $this->assertNotNull($row->created_at);
        }
    }

    public function test_create_many_ignores_duplicate_recipients(): void
    {
        $count = app(NotificationService::class)->createMany(
            [$this->userA->id, $this->userA->id],
            'Tiêu đề',
            'Nội dung',
            'Info',
        );

        $this->assertSame(1, $count);
        $this->assertSame(1, Notification::where('user_id', $this->userA->id)->count());
    }

    public function test_create_many_with_no_recipients_creates_nothing(): void
    {
        $before = Notification::count();

        $count = app(NotificationService::class)->createMany([], 'Tiêu đề', 'Nội dung', 'Info');

        $this->assertSame(0, $count);
        $this->assertSame($before, Notification::count());
    }

    public function test_created_notification_is_visible_only_to_its_recipient(): void
    {
        $created = app(NotificationService::class)->create(
            $this->userA->id,
            'Dành cho A',
            'Nội dung',
            'Review',
            '/submissions',
        );

        Sanctum::actingAs($this->userA);
        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $created->id)
            ->assertJsonPath('0.isRead', false);

        Sanctum::actingAs($this->userB);
        $this->getJson('/api/notifications')->assertOk()->assertJsonCount(0);
    }
}
