<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\Folder;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BulkDeleteTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $user;

    private Role $userRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $adminRole = Role::query()->where('slug', 'admin')->firstOrFail();
        $this->userRole = Role::query()->where('slug', 'user')->firstOrFail();

        $this->admin = User::factory()->create();
        $this->admin->roles()->attach($adminRole);

        $this->user = User::factory()->create();
        $this->user->roles()->attach($this->userRole);
    }

    // ── 1. Bulk delete files + folders → 200 ─────────────

    public function test_bulk_delete_files_and_folders(): void
    {
        $file1 = File::factory()->create(['owner_id' => $this->user->id]);
        $file2 = File::factory()->create(['owner_id' => $this->user->id]);
        $folder = Folder::factory()->create(['owner_id' => $this->user->id]);

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/delete', [
                'files' => [$file1->id, $file2->id],
                'folders' => [$folder->uuid],
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', '3 items moved to trash');

        $file1->refresh();
        $file2->refresh();
        $folder->refresh();

        $this->assertNotNull($file1->deleted_at);
        $this->assertNotNull($file2->deleted_at);
        $this->assertNotNull($folder->deleted_at);
    }

    // ── 2. Only files ───────────────────────────────────

    public function test_bulk_delete_only_files(): void
    {
        $file = File::factory()->create(['owner_id' => $this->user->id]);

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/delete', [
                'files' => [$file->id],
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', '1 items moved to trash');

        $file->refresh();
        $this->assertNotNull($file->deleted_at);
    }

    // ── 3. Only folders ─────────────────────────────────

    public function test_bulk_delete_only_folders(): void
    {
        $folder = Folder::factory()->create(['owner_id' => $this->user->id]);

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/delete', [
                'folders' => [$folder->uuid],
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', '1 items moved to trash');

        $folder->refresh();
        $this->assertNotNull($folder->deleted_at);
    }

    // ── 4. Empty payload → 422 ──────────────────────────

    public function test_empty_payload_returns_422(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/delete', []);

        $response->assertStatus(422);
    }

    public function test_empty_arrays_returns_422(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/delete', [
                'files' => [],
                'folders' => [],
            ]);

        $response->assertStatus(422);
    }

    // ── 5. Permission denied → 403 ──────────────────────

    public function test_non_owner_cannot_delete_file(): void
    {
        $otherUser = User::factory()->create();
        $otherUser->roles()->attach($this->userRole);

        $file = File::factory()->create(['owner_id' => $this->user->id]);

        $response = $this->actingAs($otherUser, 'api')
            ->postJson('/api/delete', [
                'files' => [$file->id],
            ]);

        $response->assertStatus(403);
    }

    public function test_non_owner_cannot_delete_folder(): void
    {
        $otherUser = User::factory()->create();
        $otherUser->roles()->attach($this->userRole);

        $folder = Folder::factory()->create(['owner_id' => $this->user->id]);

        $response = $this->actingAs($otherUser, 'api')
            ->postJson('/api/delete', [
                'folders' => [$folder->uuid],
            ]);

        $response->assertStatus(403);
    }

    // ── 6. Atomic rollback on permission failure ────────

    public function test_atomic_rollback_on_permission_failure(): void
    {
        $otherUser = User::factory()->create();
        $otherUser->roles()->attach($this->userRole);

        $ownedFile = File::factory()->create(['owner_id' => $otherUser->id]);
        $otherFile = File::factory()->create(['owner_id' => $this->user->id]);

        // otherUser tries to delete their own file + someone else's file
        $response = $this->actingAs($otherUser, 'api')
            ->postJson('/api/delete', [
                'files' => [$ownedFile->id, $otherFile->id],
            ]);

        $response->assertStatus(403);

        // ownedFile should NOT be deleted because entire operation was aborted
        $ownedFile->refresh();
        $this->assertNull($ownedFile->deleted_at);
    }

    // ── 7. Folder recursive deletion ────────────────────

    public function test_folder_deletion_cascades_to_descendants(): void
    {
        $parent = Folder::factory()->create(['owner_id' => $this->user->id]);
        $child = Folder::factory()->create([
            'owner_id' => $this->user->id,
            'parent_id' => $parent->id,
        ]);
        $childFile = File::factory()->create([
            'owner_id' => $this->user->id,
            'folder_id' => $parent->id,
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/delete', [
                'folders' => [$parent->uuid],
            ]);

        $response->assertStatus(200);

        $parent->refresh();
        $child->refresh();
        $childFile->refresh();

        $this->assertNotNull($parent->deleted_at);
        $this->assertNotNull($child->deleted_at);
        $this->assertNotNull($childFile->deleted_at);
    }

    // ── 8. Admin can delete any item ────────────────────

    public function test_admin_can_delete_any_users_items(): void
    {
        $file = File::factory()->create(['owner_id' => $this->user->id]);
        $folder = Folder::factory()->create(['owner_id' => $this->user->id]);

        $response = $this->actingAs($this->admin, 'api')
            ->postJson('/api/delete', [
                'files' => [$file->id],
                'folders' => [$folder->uuid],
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', '2 items moved to trash');

        $file->refresh();
        $folder->refresh();

        $this->assertNotNull($file->deleted_at);
        $this->assertNotNull($folder->deleted_at);
    }

    // ── 9. Already trashed items are skipped ────────────

    public function test_already_trashed_items_are_skipped(): void
    {
        $file = File::factory()->create([
            'owner_id' => $this->user->id,
            'deleted_at' => now(),
        ]);
        $activeFile = File::factory()->create(['owner_id' => $this->user->id]);

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/delete', [
                'files' => [$file->id, $activeFile->id],
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', '1 items moved to trash');

        $activeFile->refresh();
        $this->assertNotNull($activeFile->deleted_at);
    }

    // ── 10. Unauthenticated → 401 ───────────────────────

    public function test_unauthenticated_returns_401(): void
    {
        $response = $this->postJson('/api/delete', [
            'files' => ['some-id'],
        ]);

        $response->assertStatus(401);
    }
}
