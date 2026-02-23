<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\Folder;
use App\Models\Role;
use App\Models\Share;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\QueryCountHelper;

class MoveTest extends TestCase
{
    use QueryCountHelper;
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

    // ── 1. Single file move ──────────────────────────────

    public function test_move_file_to_folder(): void
    {
        $folder = Folder::factory()->create(['owner_id' => $this->user->id]);
        $targetFolder = Folder::factory()->create(['owner_id' => $this->user->id]);

        $file = File::factory()->create([
            'folder_id' => $folder->id,
            'owner_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->patchJson("/api/files/{$file->id}", [
                'folder_id' => $targetFolder->uuid,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.folder_id', $targetFolder->uuid);

        $file->refresh();
        $this->assertEquals($targetFolder->id, $file->folder_id);
    }

    // ── 2. File move to root ─────────────────────────────

    public function test_move_file_to_root(): void
    {
        $folder = Folder::factory()->create(['owner_id' => $this->user->id]);

        $file = File::factory()->create([
            'folder_id' => $folder->id,
            'owner_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->patchJson("/api/files/{$file->id}", [
                'folder_id' => null,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.folder_id', null);

        $file->refresh();
        $this->assertNull($file->folder_id);
    }

    // ── 3. File move to another folder ───────────────────

    public function test_move_file_to_another_folder(): void
    {
        $folderA = Folder::factory()->create(['owner_id' => $this->user->id, 'name' => 'FolderA']);
        $folderB = Folder::factory()->create(['owner_id' => $this->user->id, 'name' => 'FolderB']);

        $file = File::factory()->create([
            'folder_id' => $folderA->id,
            'owner_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->patchJson("/api/files/{$file->id}", [
                'folder_id' => $folderB->uuid,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.folder_id', $folderB->uuid);

        $file->refresh();
        $this->assertEquals($folderB->id, $file->folder_id);
    }

    // ── 4. Folder move ───────────────────────────────────

    public function test_move_folder(): void
    {
        $folderA = Folder::factory()->create(['owner_id' => $this->user->id, 'name' => 'FolderA']);
        $folderB = Folder::factory()->create(['owner_id' => $this->user->id, 'name' => 'FolderB']);

        $response = $this->actingAs($this->user, 'api')
            ->patchJson("/api/folders/{$folderA->uuid}", [
                'parent_id' => $folderB->uuid,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.parent_id', $folderB->uuid);

        $folderA->refresh();
        $this->assertEquals($folderB->id, $folderA->parent_id);
        $this->assertEquals("/{$folderB->id}/{$folderA->id}/", $folderA->path);
    }

    // ── 5. Folder move with deep descendants ─────────────

    public function test_move_folder_with_deep_descendants(): void
    {
        // Create: root -> A -> B -> C
        $root = Folder::factory()->create(['owner_id' => $this->user->id, 'name' => 'Root']);
        $a = Folder::factory()->create([
            'owner_id' => $this->user->id,
            'name' => 'A',
            'parent_id' => $root->id,
        ]);
        $b = Folder::factory()->create([
            'owner_id' => $this->user->id,
            'name' => 'B',
            'parent_id' => $a->id,
        ]);
        $c = Folder::factory()->create([
            'owner_id' => $this->user->id,
            'name' => 'C',
            'parent_id' => $b->id,
        ]);

        // Create a separate target folder
        $target = Folder::factory()->create(['owner_id' => $this->user->id, 'name' => 'Target']);

        // Move A (with descendants B, C) into Target
        $response = $this->actingAs($this->user, 'api')
            ->patchJson("/api/folders/{$a->uuid}", [
                'parent_id' => $target->uuid,
            ]);

        $response->assertStatus(200);

        // Verify all paths are updated correctly
        $a->refresh();
        $b->refresh();
        $c->refresh();

        $expectedAPath = "/{$target->id}/{$a->id}/";
        $expectedBPath = "/{$target->id}/{$a->id}/{$b->id}/";
        $expectedCPath = "/{$target->id}/{$a->id}/{$b->id}/{$c->id}/";

        $this->assertEquals($expectedAPath, $a->path);
        $this->assertEquals($expectedBPath, $b->path);
        $this->assertEquals($expectedCPath, $c->path);
        $this->assertEquals($target->id, $a->parent_id);
    }

    // ── 6. Circular reference rejection ──────────────────

    public function test_circular_reference_rejected(): void
    {
        // Create: A -> B
        $a = Folder::factory()->create(['owner_id' => $this->user->id, 'name' => 'A']);
        $b = Folder::factory()->create([
            'owner_id' => $this->user->id,
            'name' => 'B',
            'parent_id' => $a->id,
        ]);

        // Try to move A into B (circular reference)
        $response = $this->actingAs($this->user, 'api')
            ->patchJson("/api/folders/{$a->uuid}", [
                'parent_id' => $b->uuid,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('parent_id');
    }

    // ── 7. Move into self rejection ──────────────────────

    public function test_move_into_self_rejected(): void
    {
        $folder = Folder::factory()->create(['owner_id' => $this->user->id]);

        $response = $this->actingAs($this->user, 'api')
            ->patchJson("/api/folders/{$folder->uuid}", [
                'parent_id' => $folder->uuid,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('parent_id');
    }

    // ── 8. Move into descendant rejection ────────────────

    public function test_move_into_descendant_rejected(): void
    {
        // Create: A -> B -> C
        $a = Folder::factory()->create(['owner_id' => $this->user->id, 'name' => 'A']);
        $b = Folder::factory()->create([
            'owner_id' => $this->user->id,
            'name' => 'B',
            'parent_id' => $a->id,
        ]);
        $c = Folder::factory()->create([
            'owner_id' => $this->user->id,
            'name' => 'C',
            'parent_id' => $b->id,
        ]);

        // Try to move A into C (grandchild)
        $response = $this->actingAs($this->user, 'api')
            ->patchJson("/api/folders/{$a->uuid}", [
                'parent_id' => $c->uuid,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('parent_id');
    }

    // ── 9. Permission denied cases ───────────────────────

    public function test_non_owner_cannot_move_file(): void
    {
        $otherUser = User::factory()->create();
        $otherUser->roles()->attach($this->userRole);

        $folder = Folder::factory()->create(['owner_id' => $this->user->id]);
        $targetFolder = Folder::factory()->create(['owner_id' => $this->user->id]);

        $file = File::factory()->create([
            'folder_id' => $folder->id,
            'owner_id' => $this->user->id,
        ]);

        $response = $this->actingAs($otherUser, 'api')
            ->patchJson("/api/files/{$file->id}", [
                'folder_id' => $targetFolder->uuid,
            ]);

        $response->assertStatus(403);
    }

    public function test_non_owner_cannot_move_folder(): void
    {
        $otherUser = User::factory()->create();
        $otherUser->roles()->attach($this->userRole);

        $folder = Folder::factory()->create(['owner_id' => $this->user->id]);
        $target = Folder::factory()->create(['owner_id' => $this->user->id]);

        $response = $this->actingAs($otherUser, 'api')
            ->patchJson("/api/folders/{$folder->uuid}", [
                'parent_id' => $target->uuid,
            ]);

        $response->assertStatus(403);
    }

    // ── 10. Bulk move success ────────────────────────────

    public function test_bulk_move_success(): void
    {
        $target = Folder::factory()->create(['owner_id' => $this->user->id, 'name' => 'Target']);
        $sourceFolder = Folder::factory()->create(['owner_id' => $this->user->id, 'name' => 'Source']);

        $file1 = File::factory()->create(['owner_id' => $this->user->id, 'folder_id' => null]);
        $file2 = File::factory()->create(['owner_id' => $this->user->id, 'folder_id' => null]);
        $moveFolder = Folder::factory()->create(['owner_id' => $this->user->id, 'name' => 'MoveMe']);

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/move', [
                'files' => [$file1->id, $file2->id],
                'folders' => [$moveFolder->uuid],
                'target_folder_id' => $target->uuid,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.moved_files', 2)
            ->assertJsonPath('data.moved_folders', 1);

        $file1->refresh();
        $file2->refresh();
        $moveFolder->refresh();

        $this->assertEquals($target->id, $file1->folder_id);
        $this->assertEquals($target->id, $file2->folder_id);
        $this->assertEquals($target->id, $moveFolder->parent_id);
    }

    // ── 11. Bulk move rollback on failure ────────────────

    public function test_bulk_move_rollback_on_failure(): void
    {
        $target = Folder::factory()->create(['owner_id' => $this->user->id, 'name' => 'Target']);

        $file1 = File::factory()->create(['owner_id' => $this->user->id, 'folder_id' => null]);

        // Create a trashed file that will cause failure
        $trashedFile = File::factory()->create([
            'owner_id' => $this->user->id,
            'folder_id' => null,
            'deleted_at' => now(),
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/move', [
                'files' => [$file1->id, $trashedFile->id],
                'target_folder_id' => $target->uuid,
            ]);

        $response->assertStatus(422);

        // file1 should NOT have been moved because of rollback
        $file1->refresh();
        $this->assertNull($file1->folder_id);
    }

    // ── 12. Shared folder move validation ────────────────

    public function test_shared_folder_move_preserves_shares(): void
    {
        $folder = Folder::factory()->create(['owner_id' => $this->user->id]);
        $target = Folder::factory()->create(['owner_id' => $this->user->id, 'name' => 'Target']);

        $otherUser = User::factory()->create();
        $otherUser->roles()->attach($this->userRole);

        // Create a share on the folder
        $share = Share::create([
            'folder_id' => $folder->id,
            'shared_by' => $this->user->id,
            'shared_with' => $otherUser->id,
            'permission' => 'view',
        ]);

        // Owner moves the shared folder
        $response = $this->actingAs($this->user, 'api')
            ->patchJson("/api/folders/{$folder->uuid}", [
                'parent_id' => $target->uuid,
            ]);

        $response->assertStatus(200);

        // Share should still exist
        $share->refresh();
        $this->assertEquals($folder->id, $share->folder_id);
        $this->assertNotNull($share->id);
    }

    // ── 13. Soft-deleted parent rejection ─────────────────

    public function test_soft_deleted_parent_rejected(): void
    {
        $trashedFolder = Folder::factory()->create([
            'owner_id' => $this->user->id,
            'deleted_at' => now(),
        ]);

        $file = File::factory()->create([
            'owner_id' => $this->user->id,
            'folder_id' => null,
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->patchJson("/api/files/{$file->id}", [
                'folder_id' => $trashedFolder->uuid,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('folder_id');
    }

    public function test_move_folder_into_trashed_parent_rejected(): void
    {
        $folder = Folder::factory()->create(['owner_id' => $this->user->id]);
        $trashedTarget = Folder::factory()->create([
            'owner_id' => $this->user->id,
            'deleted_at' => now(),
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->patchJson("/api/folders/{$folder->uuid}", [
                'parent_id' => $trashedTarget->uuid,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('parent_id');
    }

    // ── 14. Query count assertion ─────────────────────────

    public function test_query_count_bounded_for_bulk_move(): void
    {
        $target = Folder::factory()->create(['owner_id' => $this->user->id, 'name' => 'Target']);

        // Create 10 files to move
        $fileIds = [];
        for ($i = 0; $i < 10; $i++) {
            $file = File::factory()->create([
                'owner_id' => $this->user->id,
                'folder_id' => null,
            ]);
            $fileIds[] = $file->id;
        }

        $this->actingAs($this->user, 'api');

        $this->assertQueryCount(function () use ($fileIds, $target) {
            $this->postJson('/api/move', [
                'files' => $fileIds,
                'target_folder_id' => $target->uuid,
            ]);
        }, 100, 'Bulk move of 10 files should stay under 100 queries');
    }

    // ── 15. Path integrity validation after move ─────────

    public function test_path_integrity_after_move(): void
    {
        // Build a deep tree: root -> A -> B -> C -> D
        $root = Folder::factory()->create(['owner_id' => $this->user->id, 'name' => 'Root']);
        $a = Folder::factory()->create([
            'owner_id' => $this->user->id,
            'name' => 'A',
            'parent_id' => $root->id,
        ]);
        $b = Folder::factory()->create([
            'owner_id' => $this->user->id,
            'name' => 'B',
            'parent_id' => $a->id,
        ]);
        $c = Folder::factory()->create([
            'owner_id' => $this->user->id,
            'name' => 'C',
            'parent_id' => $b->id,
        ]);
        $d = Folder::factory()->create([
            'owner_id' => $this->user->id,
            'name' => 'D',
            'parent_id' => $c->id,
        ]);

        // Create a separate target
        $target = Folder::factory()->create(['owner_id' => $this->user->id, 'name' => 'Target']);

        // Move B (subtree B->C->D) into target
        $response = $this->actingAs($this->user, 'api')
            ->patchJson("/api/folders/{$b->uuid}", [
                'parent_id' => $target->uuid,
            ]);

        $response->assertStatus(200);

        // Reload all folders
        $root->refresh();
        $a->refresh();
        $b->refresh();
        $c->refresh();
        $d->refresh();
        $target->refresh();

        // Verify root and A paths are unchanged
        $this->assertEquals("/{$root->id}/", $root->path);
        $this->assertEquals("/{$root->id}/{$a->id}/", $a->path);

        // Verify moved subtree paths
        $this->assertEquals("/{$target->id}/{$b->id}/", $b->path);
        $this->assertEquals("/{$target->id}/{$b->id}/{$c->id}/", $c->path);
        $this->assertEquals("/{$target->id}/{$b->id}/{$c->id}/{$d->id}/", $d->path);

        // Verify parent_id
        $this->assertEquals($target->id, $b->parent_id);
        $this->assertEquals($b->id, $c->parent_id); // unchanged
        $this->assertEquals($c->id, $d->parent_id); // unchanged

        // Verify that each path is consistent: parent's path is prefix of child's path
        $allFolders = Folder::query()->whereNull('deleted_at')->get();
        foreach ($allFolders as $folder) {
            if ($folder->parent_id !== null) {
                $parent = $allFolders->firstWhere('id', $folder->parent_id);
                if ($parent) {
                    $this->assertTrue(
                        str_starts_with($folder->path, $parent->path),
                        "Folder {$folder->name} (path={$folder->path}) should have parent path {$parent->path} as prefix"
                    );
                }
            }
        }
    }

    // ── Additional edge cases ────────────────────────────

    public function test_move_folder_to_root(): void
    {
        $parent = Folder::factory()->create(['owner_id' => $this->user->id, 'name' => 'Parent']);
        $child = Folder::factory()->create([
            'owner_id' => $this->user->id,
            'name' => 'Child',
            'parent_id' => $parent->id,
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->patchJson("/api/folders/{$child->uuid}", [
                'parent_id' => null,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.parent_id', null);

        $child->refresh();
        $this->assertNull($child->parent_id);
        $this->assertEquals("/{$child->id}/", $child->path);
    }

    public function test_move_file_same_folder_is_noop(): void
    {
        $folder = Folder::factory()->create(['owner_id' => $this->user->id]);

        $file = File::factory()->create([
            'folder_id' => $folder->id,
            'owner_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->patchJson("/api/files/{$file->id}", [
                'folder_id' => $folder->uuid,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.folder_id', $folder->uuid);
    }

    public function test_move_folder_same_parent_is_noop(): void
    {
        $parent = Folder::factory()->create(['owner_id' => $this->user->id, 'name' => 'Parent']);
        $child = Folder::factory()->create([
            'owner_id' => $this->user->id,
            'name' => 'Child',
            'parent_id' => $parent->id,
        ]);

        $originalPath = $child->path;

        $response = $this->actingAs($this->user, 'api')
            ->patchJson("/api/folders/{$child->uuid}", [
                'parent_id' => $parent->uuid,
            ]);

        $response->assertStatus(200);

        $child->refresh();
        $this->assertEquals($originalPath, $child->path);
    }

    public function test_cannot_move_file_to_other_users_folder(): void
    {
        $otherUser = User::factory()->create();
        $otherUser->roles()->attach($this->userRole);

        $otherFolder = Folder::factory()->create(['owner_id' => $otherUser->id]);

        $file = File::factory()->create([
            'owner_id' => $this->user->id,
            'folder_id' => null,
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->patchJson("/api/files/{$file->id}", [
                'folder_id' => $otherFolder->uuid,
            ]);

        $response->assertStatus(403);
    }

    public function test_bulk_move_to_root(): void
    {
        $folder = Folder::factory()->create(['owner_id' => $this->user->id]);

        $file = File::factory()->create([
            'owner_id' => $this->user->id,
            'folder_id' => $folder->id,
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/move', [
                'files' => [$file->id],
                'target_folder_id' => null,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.moved_files', 1);

        $file->refresh();
        $this->assertNull($file->folder_id);
    }

    public function test_rename_and_move_file_simultaneously(): void
    {
        $folder = Folder::factory()->create(['owner_id' => $this->user->id]);
        $targetFolder = Folder::factory()->create(['owner_id' => $this->user->id]);

        $file = File::factory()->create([
            'folder_id' => $folder->id,
            'owner_id' => $this->user->id,
            'name' => 'original.txt',
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->patchJson("/api/files/{$file->id}", [
                'name' => 'renamed.txt',
                'folder_id' => $targetFolder->uuid,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'renamed.txt')
            ->assertJsonPath('data.folder_id', $targetFolder->uuid);

        $file->refresh();
        $this->assertEquals('renamed.txt', $file->name);
        $this->assertEquals($targetFolder->id, $file->folder_id);
    }

    public function test_rename_folder(): void
    {
        $folder = Folder::factory()->create([
            'owner_id' => $this->user->id,
            'name' => 'Old Name',
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->patchJson("/api/folders/{$folder->uuid}", [
                'name' => 'New Name',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'New Name');
    }
}
