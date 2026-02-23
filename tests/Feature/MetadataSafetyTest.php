<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\Folder;
use App\Models\Share;
use App\Models\SyncEvent;
use App\Models\Tag;
use App\Models\User;
use App\Models\UserFavorite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 3 — Metadata Safety
 *
 * Regression tests proving metadata-only operations never mutate
 * file content properties (version, checksum, updated_at) or
 * emit content sync events.
 */
class MetadataSafetyTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $recipient;

    private File $file;

    private Folder $folder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->recipient = User::factory()->create();

        $this->folder = Folder::factory()->create(['owner_id' => $this->owner->id]);

        $this->file = File::factory()->create([
            'owner_id' => $this->owner->id,
            'folder_id' => $this->folder->id,
            'version' => 3,
            'checksum_sha256' => 'abc123deadbeef',
        ]);

        // Clear any sync events from setup
        SyncEvent::query()->delete();
    }

    /**
     * Snapshot file state and return it for later comparison.
     */
    private function snapshot(File $file): array
    {
        $file->refresh();

        return [
            'version' => $file->version,
            'checksum_sha256' => $file->checksum_sha256,
            'updated_at' => $file->updated_at->toDateTimeString(),
        ];
    }

    /**
     * Assert file state is unchanged and no sync events were emitted.
     */
    private function assertFileUnchanged(File $file, array $snapshot): void
    {
        $file->refresh();

        $this->assertEquals($snapshot['version'], $file->version, 'version must not change');
        $this->assertEquals($snapshot['checksum_sha256'], $file->checksum_sha256, 'checksum must not change');
        $this->assertEquals($snapshot['updated_at'], $file->updated_at->toDateTimeString(), 'updated_at must not change');
        $this->assertSame(0, SyncEvent::query()->count(), 'no sync events should be emitted');
    }

    // ── Tag ──────────────────────────────────────────────

    public function test_tag_assign_does_not_modify_file(): void
    {
        $tag = Tag::create(['user_id' => $this->owner->id, 'name' => 'test-tag']);
        $snap = $this->snapshot($this->file);

        $this->actingAs($this->owner, 'api')
            ->postJson('/api/tags/assign', [
                'tag_ids' => [$tag->uuid],
                'resource_type' => 'file',
                'resource_ids' => [$this->file->id],
            ])
            ->assertOk();

        $this->assertFileUnchanged($this->file, $snap);
    }

    public function test_tag_unassign_does_not_modify_file(): void
    {
        $tag = Tag::create(['user_id' => $this->owner->id, 'name' => 'test-tag']);

        // Assign first
        $this->actingAs($this->owner, 'api')
            ->postJson('/api/tags/assign', [
                'tag_ids' => [$tag->uuid],
                'resource_type' => 'file',
                'resource_ids' => [$this->file->id],
            ])
            ->assertOk();

        SyncEvent::query()->delete();
        $snap = $this->snapshot($this->file);

        $this->actingAs($this->owner, 'api')
            ->postJson('/api/tags/unassign', [
                'tag_ids' => [$tag->uuid],
                'resource_type' => 'file',
                'resource_ids' => [$this->file->id],
            ])
            ->assertOk();

        $this->assertFileUnchanged($this->file, $snap);
    }

    // ── Favorite ─────────────────────────────────────────

    public function test_favorite_add_does_not_modify_file(): void
    {
        $snap = $this->snapshot($this->file);

        $this->actingAs($this->owner, 'api')
            ->postJson('/api/favorites', [
                'resource_type' => 'file',
                'resource_id' => $this->file->id,
            ])
            ->assertStatus(201);

        $this->assertFileUnchanged($this->file, $snap);
    }

    public function test_favorite_remove_does_not_modify_file(): void
    {
        // Add first
        UserFavorite::create([
            'user_id' => $this->owner->id,
            'resource_type' => 'file',
            'resource_id' => $this->file->id,
        ]);

        SyncEvent::query()->delete();
        $snap = $this->snapshot($this->file);

        $this->actingAs($this->owner, 'api')
            ->deleteJson('/api/favorites', [
                'resource_type' => 'file',
                'resource_id' => $this->file->id,
            ])
            ->assertOk();

        $this->assertFileUnchanged($this->file, $snap);
    }

    // ── Share ────────────────────────────────────────────

    public function test_share_create_does_not_modify_file(): void
    {
        $snap = $this->snapshot($this->file);

        $this->actingAs($this->owner, 'api')
            ->postJson('/api/share', [
                'file_id' => $this->file->id,
                'shared_with' => $this->recipient->id,
                'permission' => 'view',
            ])
            ->assertStatus(201);

        $this->assertFileUnchanged($this->file, $snap);
    }

    public function test_share_revoke_does_not_modify_file(): void
    {
        // Create share first
        $response = $this->actingAs($this->owner, 'api')
            ->postJson('/api/share', [
                'file_id' => $this->file->id,
                'shared_with' => $this->recipient->id,
                'permission' => 'view',
            ])
            ->assertStatus(201);

        $shareId = $response->json('data.id');
        SyncEvent::query()->delete();
        $snap = $this->snapshot($this->file);

        $this->actingAs($this->owner, 'api')
            ->deleteJson("/api/share/{$shareId}")
            ->assertStatus(204);

        $this->assertFileUnchanged($this->file, $snap);
    }

    // ── Folder share ─────────────────────────────────────

    public function test_share_create_folder_does_not_modify_child_files(): void
    {
        $snap = $this->snapshot($this->file);

        $this->actingAs($this->owner, 'api')
            ->postJson('/api/share', [
                'folder_id' => $this->folder->uuid,
                'shared_with' => $this->recipient->id,
                'permission' => 'edit',
            ])
            ->assertStatus(201);

        $this->assertFileUnchanged($this->file, $snap);
    }

    public function test_share_revoke_folder_does_not_modify_child_files(): void
    {
        // Create folder share first
        $response = $this->actingAs($this->owner, 'api')
            ->postJson('/api/share', [
                'folder_id' => $this->folder->uuid,
                'shared_with' => $this->recipient->id,
                'permission' => 'edit',
            ])
            ->assertStatus(201);

        $shareId = $response->json('data.id');
        SyncEvent::query()->delete();
        $snap = $this->snapshot($this->file);

        $this->actingAs($this->owner, 'api')
            ->deleteJson("/api/share/{$shareId}")
            ->assertStatus(204);

        $this->assertFileUnchanged($this->file, $snap);
    }
}
