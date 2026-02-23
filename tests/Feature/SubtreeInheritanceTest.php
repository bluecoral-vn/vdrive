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

/**
 * Phase 2 — Subtree Inheritance.
 *
 * Validates that:
 * - Non-owners can only move items within their authorized subtree
 * - Cross-boundary moves are blocked (403)
 * - Moving to root as non-owner is blocked
 * - Owners are unrestricted
 * - Circular moves remain blocked within shared subtrees
 */
class SubtreeInheritanceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $editor;

    private User $stranger;

    private Folder $sharedFolder;

    private Folder $subfolderA;

    private Folder $subfolderB;

    private File $fileInA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $userRole = Role::query()->where('slug', 'user')->firstOrFail();

        $this->owner = User::factory()->create();
        $this->owner->roles()->attach($userRole);

        $this->editor = User::factory()->create();
        $this->editor->roles()->attach($userRole);

        $this->stranger = User::factory()->create();
        $this->stranger->roles()->attach($userRole);

        // Structure: sharedFolder > subfolderA > fileInA
        //                         > subfolderB
        $this->sharedFolder = Folder::factory()->create(['owner_id' => $this->owner->id]);
        $this->subfolderA = Folder::factory()->create([
            'parent_id' => $this->sharedFolder->id,
            'owner_id' => $this->owner->id,
        ]);
        $this->subfolderB = Folder::factory()->create([
            'parent_id' => $this->sharedFolder->id,
            'owner_id' => $this->owner->id,
        ]);
        $this->fileInA = File::factory()->create([
            'folder_id' => $this->subfolderA->id,
            'owner_id' => $this->owner->id,
        ]);

        // Share folder with editor (edit permission)
        Share::query()->create([
            'folder_id' => $this->sharedFolder->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->editor->id,
            'permission' => 'edit',
        ]);
    }

    // ═══════════════════════════════════════════════════════
    // WITHIN-SUBTREE (ALLOWED)
    // ═══════════════════════════════════════════════════════

    public function test_editor_can_move_file_within_subtree(): void
    {
        $this->actingAs($this->editor, 'api')
            ->patchJson("/api/files/{$this->fileInA->id}", [
                'folder_id' => $this->subfolderB->uuid,
            ])
            ->assertStatus(200);

        $this->fileInA->refresh();
        $this->assertEquals($this->subfolderB->id, $this->fileInA->folder_id);
    }

    public function test_editor_can_move_file_to_shared_root(): void
    {
        // Moving to the shared folder itself (not system root)
        $this->actingAs($this->editor, 'api')
            ->patchJson("/api/files/{$this->fileInA->id}", [
                'folder_id' => $this->sharedFolder->uuid,
            ])
            ->assertStatus(200);

        $this->fileInA->refresh();
        $this->assertEquals($this->sharedFolder->id, $this->fileInA->folder_id);
    }

    public function test_editor_can_move_folder_within_subtree(): void
    {
        $this->actingAs($this->editor, 'api')
            ->patchJson("/api/folders/{$this->subfolderA->uuid}", [
                'parent_id' => $this->subfolderB->uuid,
            ])
            ->assertStatus(200);

        $this->subfolderA->refresh();
        $this->assertEquals($this->subfolderB->id, $this->subfolderA->parent_id);
    }

    public function test_editor_can_bulk_move_within_subtree(): void
    {
        $this->actingAs($this->editor, 'api')
            ->postJson('/api/move', [
                'files' => [$this->fileInA->id],
                'target_folder_id' => $this->subfolderB->uuid,
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.moved_files', 1);

        $this->fileInA->refresh();
        $this->assertEquals($this->subfolderB->id, $this->fileInA->folder_id);
    }

    // ═══════════════════════════════════════════════════════
    // CROSS-BOUNDARY (BLOCKED)
    // ═══════════════════════════════════════════════════════

    public function test_editor_cannot_move_file_to_own_folder(): void
    {
        $editorFolder = Folder::factory()->create(['owner_id' => $this->editor->id]);

        $this->actingAs($this->editor, 'api')
            ->patchJson("/api/files/{$this->fileInA->id}", [
                'folder_id' => $editorFolder->uuid,
            ])
            ->assertStatus(403);

        // File must remain in original location
        $this->fileInA->refresh();
        $this->assertEquals($this->subfolderA->id, $this->fileInA->folder_id);
    }

    public function test_editor_cannot_move_file_to_root(): void
    {
        $this->actingAs($this->editor, 'api')
            ->patchJson("/api/files/{$this->fileInA->id}", [
                'folder_id' => null,
            ])
            ->assertStatus(403);
    }

    public function test_editor_cannot_move_folder_outside_subtree(): void
    {
        $editorFolder = Folder::factory()->create(['owner_id' => $this->editor->id]);

        $this->actingAs($this->editor, 'api')
            ->patchJson("/api/folders/{$this->subfolderA->uuid}", [
                'parent_id' => $editorFolder->uuid,
            ])
            ->assertStatus(403);

        $this->subfolderA->refresh();
        $this->assertEquals($this->sharedFolder->id, $this->subfolderA->parent_id);
    }

    public function test_editor_cannot_move_folder_to_root(): void
    {
        $this->actingAs($this->editor, 'api')
            ->patchJson("/api/folders/{$this->subfolderA->uuid}", [
                'parent_id' => null,
            ])
            ->assertStatus(403);
    }

    public function test_editor_cannot_bulk_move_outside_subtree(): void
    {
        $editorFolder = Folder::factory()->create(['owner_id' => $this->editor->id]);

        $this->actingAs($this->editor, 'api')
            ->postJson('/api/move', [
                'files' => [$this->fileInA->id],
                'target_folder_id' => $editorFolder->uuid,
            ])
            ->assertStatus(403);

        $this->fileInA->refresh();
        $this->assertEquals($this->subfolderA->id, $this->fileInA->folder_id);
    }

    public function test_editor_cannot_move_to_different_shared_subtree(): void
    {
        // Create a second shared folder from a different owner
        $otherOwner = User::factory()->create();
        $otherOwner->roles()->attach(Role::query()->where('slug', 'user')->firstOrFail());

        $otherShared = Folder::factory()->create(['owner_id' => $otherOwner->id]);
        Share::query()->create([
            'folder_id' => $otherShared->id,
            'shared_by' => $otherOwner->id,
            'shared_with' => $this->editor->id,
            'permission' => 'edit',
        ]);

        $this->actingAs($this->editor, 'api')
            ->patchJson("/api/files/{$this->fileInA->id}", [
                'folder_id' => $otherShared->uuid,
            ])
            ->assertStatus(403);
    }

    // ═══════════════════════════════════════════════════════
    // OWNER UNRESTRICTED
    // ═══════════════════════════════════════════════════════

    public function test_owner_can_move_file_to_root(): void
    {
        $this->actingAs($this->owner, 'api')
            ->patchJson("/api/files/{$this->fileInA->id}", [
                'folder_id' => null,
            ])
            ->assertStatus(200);

        $this->fileInA->refresh();
        $this->assertNull($this->fileInA->folder_id);
    }

    public function test_owner_can_move_folder_to_root(): void
    {
        $this->actingAs($this->owner, 'api')
            ->patchJson("/api/folders/{$this->subfolderA->uuid}", [
                'parent_id' => null,
            ])
            ->assertStatus(200);

        $this->subfolderA->refresh();
        $this->assertNull($this->subfolderA->parent_id);
    }

    // ═══════════════════════════════════════════════════════
    // CIRCULAR STILL BLOCKED IN SHARED SUBTREE
    // ═══════════════════════════════════════════════════════

    public function test_circular_move_blocked_in_shared_subtree(): void
    {
        // subfolderA is child of sharedFolder
        // Try to move sharedFolder into subfolderA (circular)
        // Editor has edit on sharedFolder, so they can attempt this
        $this->actingAs($this->editor, 'api')
            ->patchJson("/api/folders/{$this->sharedFolder->uuid}", [
                'parent_id' => $this->subfolderA->uuid,
            ])
            ->assertStatus(422);
    }
}
