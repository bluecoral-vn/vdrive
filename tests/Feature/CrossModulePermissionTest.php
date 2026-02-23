<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\Share;
use App\Models\Tag;
use App\Models\Taggable;
use App\Models\User;
use App\Models\UserFavorite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrossModulePermissionTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $recipient;

    private User $stranger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->recipient = User::factory()->create();
        $this->stranger = User::factory()->create();
    }

    // ── Shared With Me ─────────────────────────────────

    public function test_expired_share_not_visible_in_shared_with_me(): void
    {
        $file = File::factory()->create(['owner_id' => $this->owner->id]);

        // Create an expired share
        Share::create([
            'file_id' => $file->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'view',
            'expires_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($this->recipient, 'api')
            ->getJson('/api/share/with-me');

        $response->assertOk();
        $this->assertCount(0, $response->json('data'), 'Expired shares must not appear in shared-with-me');
    }

    public function test_revoked_share_not_visible_in_shared_with_me(): void
    {
        $file = File::factory()->create(['owner_id' => $this->owner->id]);

        $share = Share::create([
            'file_id' => $file->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'view',
        ]);

        // Verify visible before revoke
        $response = $this->actingAs($this->recipient, 'api')
            ->getJson('/api/share/with-me');
        $this->assertCount(1, $response->json('data'));

        // Revoke share
        $this->actingAs($this->owner, 'api')
            ->deleteJson("/api/share/{$share->id}");

        // Verify gone after revoke
        $response = $this->actingAs($this->recipient, 'api')
            ->getJson('/api/share/with-me');

        $response->assertOk();
        $this->assertCount(0, $response->json('data'), 'Revoked share must immediately disappear');
    }

    // ── Tag Items Permission ───────────────────────────

    public function test_tag_items_only_shows_accessible_resources(): void
    {
        // Recipient owns one file, stranger owns another
        $ownFile = File::factory()->create(['owner_id' => $this->recipient->id]);
        $strangerFile = File::factory()->create(['owner_id' => $this->stranger->id]);

        $tag = Tag::create([
            'user_id' => $this->recipient->id,
            'name' => 'test-tag',
        ]);

        // Tag both files (simulating an orphan taggable for stranger's file)
        Taggable::create([
            'tag_id' => $tag->id,
            'resource_type' => 'file',
            'resource_id' => (string) $ownFile->id,
        ]);
        Taggable::create([
            'tag_id' => $tag->id,
            'resource_type' => 'file',
            'resource_id' => (string) $strangerFile->id,
        ]);

        $response = $this->actingAs($this->recipient, 'api')
            ->getJson("/api/tags/{$tag->uuid}/items");

        $response->assertOk();

        $fileIds = collect($response->json('files'))->pluck('id')->all();
        $this->assertContains((string) $ownFile->id, $fileIds, 'Own file must appear');
        $this->assertNotContains((string) $strangerFile->id, $fileIds, 'Stranger file must NOT appear');
    }

    // ── Favorites — Trashed Resources ──────────────────

    public function test_favorite_list_excludes_trashed_resources(): void
    {
        $file = File::factory()->create(['owner_id' => $this->owner->id]);

        // Create a favorite
        UserFavorite::create([
            'user_id' => $this->owner->id,
            'resource_type' => 'file',
            'resource_id' => (string) $file->id,
        ]);

        // Soft-delete the file
        $file->update(['deleted_at' => now()]);

        $response = $this->actingAs($this->owner, 'api')
            ->getJson('/api/favorites');

        $response->assertOk();

        // The favorite record still exists, but the embedded resource should be null
        $data = $response->json('data');
        $this->assertNotEmpty($data, 'Favorite record still returned');

        $resource = $data[0]['resource'] ?? null;
        $this->assertNull($resource, 'Trashed file resource must be null in favorite listing');
    }
}
