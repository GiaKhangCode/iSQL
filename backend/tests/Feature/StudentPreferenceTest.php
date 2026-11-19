<?php

namespace Tests\Feature;

use App\Models\Favorite;
use App\Models\Problem;
use App\Models\ProblemList;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\AccountFixture;
use Tests\TestCase;

/**
 * Test đặc tả cho /api/student/preferences (Student\PreferenceController):
 *   GET  /api/student/preferences               danh sách yêu thích + danh sách tự tạo
 *   POST /api/student/preferences/favorites/{id}  bật/tắt yêu thích một bài toán
 *
 * Hợp đồng JSON (khớp với cách SqlWorkspace.tsx đang dùng):
 *   - GET  : { favorites: string[] (id bài toán), custom_lists: [...] }
 *   - POST : { favorited: bool, status: "added" | "removed" }; bài toán không tồn tại => 404
 */
class StudentPreferenceTest extends TestCase
{
    use DatabaseTransactions;

    protected User $student;
    protected User $otherStudent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->student = $this->makeUser('student', 'a');
        $this->otherStudent = $this->makeUser('student', 'b');
    }

    private function makeUser(string $role, string $tag): User
    {
        return User::create([
            'name'            => "Preference {$tag}",
            'email'           => "pref_{$tag}_" . uniqid() . '@gm.uit.edu.vn',
            'hashed_password' => Hash::make('password123'),
            ...AccountFixture::for($role),
            'status'          => 'Active',
            'initials'        => 'PF',
        ]);
    }

    private function makeProblem(string $title = 'Bài toán yêu thích'): Problem
    {
        return Problem::create([
            'number'          => 'SQL-PF-' . strtoupper(uniqid()),
            'title'           => $title,
            'difficulty'      => 'Easy',
            'description'     => 'Mô tả',
            'requirements'    => 'SELECT 1',
            'practice_listed' => true,
            'creator_id'      => $this->student->id,
        ]);
    }

    // ── Phân quyền ──────────────────────────────────────────────────────────

    public function test_preference_routes_require_authentication(): void
    {
        $this->getJson('/api/student/preferences')->assertStatus(401);
        $this->postJson('/api/student/preferences/favorites/any-id')->assertStatus(401);
    }

    public function test_preference_routes_require_practice_permission(): void
    {
        // Role lạ: không có quyền PRACTICE_MGMT:view mặc định lẫn qua nhóm quyền.
        Sanctum::actingAs($this->makeUser('visitor', 'nopermission'));

        $this->getJson('/api/student/preferences')->assertStatus(403);
        $this->postJson('/api/student/preferences/favorites/' . $this->makeProblem()->id)->assertStatus(403);
    }

    // ── Danh sách ───────────────────────────────────────────────────────────

    public function test_index_is_empty_for_a_user_without_preferences(): void
    {
        Sanctum::actingAs($this->student);

        $this->getJson('/api/student/preferences')
            ->assertOk()
            ->assertExactJson(['favorites' => [], 'custom_lists' => []]);
    }

    public function test_index_returns_only_own_favorites_as_a_list_of_problem_ids(): void
    {
        $mine1 = $this->makeProblem('Bài của tôi 1');
        $mine2 = $this->makeProblem('Bài của tôi 2');
        $theirs = $this->makeProblem('Bài của người khác');

        Favorite::create(['user_id' => $this->student->id, 'problem_id' => $mine1->id]);
        Favorite::create(['user_id' => $this->student->id, 'problem_id' => $mine2->id]);
        Favorite::create(['user_id' => $this->otherStudent->id, 'problem_id' => $theirs->id]);

        Sanctum::actingAs($this->student);

        $res = $this->getJson('/api/student/preferences');
        $res->assertOk()->assertJsonCount(2, 'favorites');

        // Mảng id thuần (frontend gọi favorites.includes(problem.id)).
        $favorites = $res->json('favorites');
        $this->assertContainsOnly('string', $favorites);
        $this->assertEqualsCanonicalizing([$mine1->id, $mine2->id], $favorites);
        $this->assertNotContains($theirs->id, $favorites);
    }

    public function test_index_returns_only_own_custom_lists_with_their_problems(): void
    {
        $p1 = $this->makeProblem('Bài 1');
        $p2 = $this->makeProblem('Bài 2');
        $other = $this->makeProblem('Bài khác');

        $mine = ProblemList::create(['user_id' => $this->student->id, 'name' => 'Ôn thi giữa kỳ', 'created_at' => now()]);
        $mine->problems()->attach([$p1->id, $p2->id]);

        $theirs = ProblemList::create(['user_id' => $this->otherStudent->id, 'name' => 'Danh sách của B', 'created_at' => now()]);
        $theirs->problems()->attach([$other->id]);

        Sanctum::actingAs($this->student);

        $res = $this->getJson('/api/student/preferences');
        $res->assertOk()
            ->assertJsonCount(1, 'custom_lists')
            ->assertJsonPath('custom_lists.0.id', $mine->id)
            ->assertJsonPath('custom_lists.0.name', 'Ôn thi giữa kỳ')
            ->assertJsonCount(2, 'custom_lists.0.problems');

        $titles = collect($res->json('custom_lists.0.problems'))->pluck('title')->sort()->values()->all();
        $this->assertSame(['Bài 1', 'Bài 2'], $titles);
    }

    // ── Bật/tắt yêu thích ───────────────────────────────────────────────────

    public function test_toggle_favorite_adds_then_removes_then_adds_again(): void
    {
        $problem = $this->makeProblem();
        $where = ['user_id' => $this->student->id, 'problem_id' => $problem->id];

        Sanctum::actingAs($this->student);

        $this->postJson("/api/student/preferences/favorites/{$problem->id}")
            ->assertOk()->assertExactJson(['favorited' => true, 'status' => 'added']);
        $this->assertDatabaseHas('favorites', $where);

        // Trước đây bước này trả 500 (khóa chính kép không có cột id), nay phải bỏ được.
        $this->postJson("/api/student/preferences/favorites/{$problem->id}")
            ->assertOk()->assertExactJson(['favorited' => false, 'status' => 'removed']);
        $this->assertDatabaseMissing('favorites', $where);

        $this->postJson("/api/student/preferences/favorites/{$problem->id}")
            ->assertOk()->assertExactJson(['favorited' => true, 'status' => 'added']);
        $this->assertDatabaseHas('favorites', $where);
    }

    public function test_toggled_favorite_shows_up_in_the_preferences_list_and_disappears_when_removed(): void
    {
        $problem = $this->makeProblem();

        Sanctum::actingAs($this->student);

        $this->postJson("/api/student/preferences/favorites/{$problem->id}")->assertOk();
        $this->getJson('/api/student/preferences')->assertJsonPath('favorites', [$problem->id]);

        $this->postJson("/api/student/preferences/favorites/{$problem->id}")->assertOk();
        $this->getJson('/api/student/preferences')->assertJsonPath('favorites', []);
    }

    public function test_toggle_favorite_is_scoped_to_the_current_user(): void
    {
        $problem = $this->makeProblem();
        $theirs = ['user_id' => $this->otherStudent->id, 'problem_id' => $problem->id];
        $mine = ['user_id' => $this->student->id, 'problem_id' => $problem->id];

        // Người khác đã yêu thích cùng bài toán.
        Favorite::create($theirs);

        Sanctum::actingAs($this->student);

        // Với mình đây là lần đầu: phải thêm, không được đụng vào của người khác.
        $this->postJson("/api/student/preferences/favorites/{$problem->id}")
            ->assertOk()->assertExactJson(['favorited' => true, 'status' => 'added']);
        $this->assertDatabaseHas('favorites', $theirs);
        $this->assertDatabaseHas('favorites', $mine);

        // Bỏ yêu thích của mình cũng không ảnh hưởng người kia.
        $this->postJson("/api/student/preferences/favorites/{$problem->id}")
            ->assertOk()->assertExactJson(['favorited' => false, 'status' => 'removed']);
        $this->assertDatabaseHas('favorites', $theirs);
        $this->assertDatabaseMissing('favorites', $mine);
    }

    public function test_toggle_favorite_with_unknown_problem_returns_404_and_creates_nothing(): void
    {
        Sanctum::actingAs($this->student);

        $this->postJson('/api/student/preferences/favorites/khong-ton-tai')->assertStatus(404);

        $this->assertDatabaseMissing('favorites', ['user_id' => $this->student->id, 'problem_id' => 'khong-ton-tai']);
    }
}
