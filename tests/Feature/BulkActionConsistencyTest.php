<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\Folder;
use App\Models\Tag;
use App\Models\User;
use App\Models\UserFavorite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 5 — Bulk Action Consistency
 *
 * Tests that all bulk operations are all-or-nothing:
 * either fully succeed or fully reject with no partial mutations.
 */
class BulkActionConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $stranger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->stranger = User::factory()->create();
    }

    // ── Bulk Favorite Add ─────────────────────────────────

    public function test_bulk_favorite_add_rejects_entirely_if_one_unauthorized(): void
    {
        $ownFile = File::factory()->create(['owner_id' => $this->owner->id]);
        $otherFile = File::factory()->create(['owner_id' => $this->stranger->id]);

        $response = $this->actingAs($this->owner, 'api')
            ->postJson('/api/favorites/bulk', [
                'resource_type' => 'file',
                'resource_ids' => [(string) $ownFile->id, (string) $otherFile->id],
            ]);

        $response->assertStatus(403);

        // No favorites should have been created
        $this->assertSame(
            0,
            UserFavorite::where('user_id', $this->owner->id)->count(),
            'Zero favorites must exist after rejected bulk add'
        );
    }

    public function test_bulk_favorite_add_rejects_entirely_if_one_not_found(): void
    {
        $ownFile = File::factory()->create(['owner_id' => $this->owner->id]);
        $fakeId = '00000000-0000-0000-0000-000000000099';

        $response = $this->actingAs($this->owner, 'api')
            ->postJson('/api/favorites/bulk', [
                'resource_type' => 'file',
                'resource_ids' => [(string) $ownFile->id, $fakeId],
            ]);

        $response->assertStatus(404);

        $this->assertSame(
            0,
            UserFavorite::where('user_id', $this->owner->id)->count(),
            'Zero favorites must exist after rejected bulk add'
        );
    }

    public function test_bulk_favorite_add_succeeds_when_all_authorized(): void
    {
        $file1 = File::factory()->create(['owner_id' => $this->owner->id]);
        $file2 = File::factory()->create(['owner_id' => $this->owner->id]);

        $response = $this->actingAs($this->owner, 'api')
            ->postJson('/api/favorites/bulk', [
                'resource_type' => 'file',
                'resource_ids' => [(string) $file1->id, (string) $file2->id],
            ]);

        $response->assertStatus(200)
            ->assertJson(['added' => 2]);

        $this->assertSame(2, UserFavorite::where('user_id', $this->owner->id)->count());
    }

    // ── Bulk Move ──────────────────────────────────────────

    public function test_bulk_move_rejects_entirely_if_one_unauthorized(): void
    {
        $targetFolder = Folder::factory()->create(['owner_id' => $this->owner->id]);
        $ownFile = File::factory()->create(['owner_id' => $this->owner->id]);
        $otherFile = File::factory()->create(['owner_id' => $this->stranger->id]);

        $originalFolderId = $ownFile->folder_id;

        $response = $this->actingAs($this->owner, 'api')
            ->postJson('/api/move', [
                'files' => [(string) $ownFile->id, (string) $otherFile->id],
                'folders' => [],
                'target_folder_id' => $targetFolder->uuid,
            ]);

        $response->assertStatus(403);

        // Own file should NOT have moved
        $ownFile->refresh();
        $this->assertSame($originalFolderId, $ownFile->folder_id, 'Own file must remain unmoved after rejected bulk move');
    }

    // ── Bulk Tag Assign ────────────────────────────────────

    public function test_bulk_tag_assign_rejects_entirely_if_one_unauthorized(): void
    {
        $tag = Tag::create(['user_id' => $this->owner->id, 'name' => 'test']);
        $ownFile = File::factory()->create(['owner_id' => $this->owner->id]);
        $otherFile = File::factory()->create(['owner_id' => $this->stranger->id]);

        $response = $this->actingAs($this->owner, 'api')
            ->postJson('/api/tags/assign', [
                'tag_ids' => [$tag->uuid],
                'resource_type' => 'file',
                'resource_ids' => [(string) $ownFile->id, (string) $otherFile->id],
            ]);

        $response->assertStatus(403);

        // No taggables should exist
        $this->assertSame(0, $tag->taggables()->count(), 'Zero taggables must exist after rejected bulk assign');
    }
}
