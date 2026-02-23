<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\Folder;
use App\Models\Share;
use App\Models\Tag;
use App\Models\Taggable;
use App\Models\User;
use App\Models\UserFavorite;
use App\Services\ShareService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 4 — Share Revoke Cleanup
 *
 * Tests that revoking a share cleans up the recipient's
 * user-scoped metadata (favorites, tags) without affecting
 * the resource or the owner's data.
 */
class ShareRevokeCleanupTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $recipient;

    private ShareService $shareService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->recipient = User::factory()->create();
        $this->shareService = app(ShareService::class);
    }

    // ── File share cleanup ───────────────────────────────

    public function test_revoke_file_share_removes_recipient_favorites(): void
    {
        $file = File::factory()->create(['owner_id' => $this->owner->id]);

        $share = Share::create([
            'file_id' => $file->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'view',
        ]);

        // Recipient favorites the file
        UserFavorite::create([
            'user_id' => $this->recipient->id,
            'resource_type' => 'file',
            'resource_id' => $file->id,
        ]);

        $this->assertSame(1, UserFavorite::where('user_id', $this->recipient->id)->count());

        $this->shareService->revoke($share);

        $this->assertSame(0, UserFavorite::where('user_id', $this->recipient->id)->count());
        $this->assertTrue(File::where('id', $file->id)->exists(), 'file must still exist');
    }

    public function test_revoke_file_share_removes_recipient_tags(): void
    {
        $file = File::factory()->create(['owner_id' => $this->owner->id]);

        $share = Share::create([
            'file_id' => $file->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'view',
        ]);

        // Recipient tags the file
        $tag = Tag::create(['user_id' => $this->recipient->id, 'name' => 'work']);
        Taggable::create([
            'tag_id' => $tag->id,
            'resource_type' => 'file',
            'resource_id' => $file->id,
        ]);

        $this->assertSame(1, Taggable::where('tag_id', $tag->id)->count());

        $this->shareService->revoke($share);

        $this->assertSame(0, Taggable::where('tag_id', $tag->id)->count());
    }

    public function test_revoke_file_share_preserves_owner_favorites(): void
    {
        $file = File::factory()->create(['owner_id' => $this->owner->id]);

        $share = Share::create([
            'file_id' => $file->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'view',
        ]);

        // Both owner and recipient favorite the file
        UserFavorite::create([
            'user_id' => $this->owner->id,
            'resource_type' => 'file',
            'resource_id' => $file->id,
        ]);
        UserFavorite::create([
            'user_id' => $this->recipient->id,
            'resource_type' => 'file',
            'resource_id' => $file->id,
        ]);

        $this->shareService->revoke($share);

        $this->assertSame(1, UserFavorite::where('user_id', $this->owner->id)->count(), 'owner favorite must remain');
        $this->assertSame(0, UserFavorite::where('user_id', $this->recipient->id)->count(), 'recipient favorite must be removed');
    }

    public function test_revoke_file_share_preserves_owner_tags(): void
    {
        $file = File::factory()->create(['owner_id' => $this->owner->id]);

        $share = Share::create([
            'file_id' => $file->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'view',
        ]);

        // Owner and recipient each tag the file
        $ownerTag = Tag::create(['user_id' => $this->owner->id, 'name' => 'owner-tag']);
        $recipientTag = Tag::create(['user_id' => $this->recipient->id, 'name' => 'recipient-tag']);

        Taggable::create(['tag_id' => $ownerTag->id, 'resource_type' => 'file', 'resource_id' => $file->id]);
        Taggable::create(['tag_id' => $recipientTag->id, 'resource_type' => 'file', 'resource_id' => $file->id]);

        $this->shareService->revoke($share);

        $this->assertSame(1, Taggable::where('tag_id', $ownerTag->id)->count(), 'owner tag must remain');
        $this->assertSame(0, Taggable::where('tag_id', $recipientTag->id)->count(), 'recipient tag must be removed');
    }

    // ── Folder share cleanup ─────────────────────────────

    public function test_revoke_folder_share_removes_recipient_favorites_on_descendants(): void
    {
        $folder = Folder::factory()->create(['owner_id' => $this->owner->id]);
        $subfolder = Folder::factory()->create([
            'owner_id' => $this->owner->id,
            'parent_id' => $folder->id,
        ]);
        $file = File::factory()->create([
            'owner_id' => $this->owner->id,
            'folder_id' => $subfolder->id,
        ]);

        $share = Share::create([
            'folder_id' => $folder->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'edit',
        ]);

        // Recipient favorites the folder, subfolder, and file
        UserFavorite::create(['user_id' => $this->recipient->id, 'resource_type' => 'folder', 'resource_id' => $folder->uuid]);
        UserFavorite::create(['user_id' => $this->recipient->id, 'resource_type' => 'folder', 'resource_id' => $subfolder->uuid]);
        UserFavorite::create(['user_id' => $this->recipient->id, 'resource_type' => 'file', 'resource_id' => $file->id]);

        // Owner also favorites the folder
        UserFavorite::create(['user_id' => $this->owner->id, 'resource_type' => 'folder', 'resource_id' => $folder->uuid]);

        $this->assertSame(3, UserFavorite::where('user_id', $this->recipient->id)->count());

        $this->shareService->revoke($share);

        $this->assertSame(0, UserFavorite::where('user_id', $this->recipient->id)->count(), 'all recipient favorites must be removed');
        $this->assertSame(1, UserFavorite::where('user_id', $this->owner->id)->count(), 'owner favorite must remain');
    }

    public function test_revoke_folder_share_removes_recipient_tags_on_descendants(): void
    {
        $folder = Folder::factory()->create(['owner_id' => $this->owner->id]);
        $file = File::factory()->create([
            'owner_id' => $this->owner->id,
            'folder_id' => $folder->id,
        ]);

        $share = Share::create([
            'folder_id' => $folder->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'edit',
        ]);

        // Recipient tags both folder and file
        $tag = Tag::create(['user_id' => $this->recipient->id, 'name' => 'work']);
        Taggable::create(['tag_id' => $tag->id, 'resource_type' => 'folder', 'resource_id' => $folder->uuid]);
        Taggable::create(['tag_id' => $tag->id, 'resource_type' => 'file', 'resource_id' => $file->id]);

        // Owner also tags the folder
        $ownerTag = Tag::create(['user_id' => $this->owner->id, 'name' => 'owner-tag']);
        Taggable::create(['tag_id' => $ownerTag->id, 'resource_type' => 'folder', 'resource_id' => $folder->uuid]);

        $this->shareService->revoke($share);

        $this->assertSame(0, Taggable::where('tag_id', $tag->id)->count(), 'recipient tags must be removed');
        $this->assertSame(1, Taggable::where('tag_id', $ownerTag->id)->count(), 'owner tags must remain');
    }

    // ── Guest link ───────────────────────────────────────

    public function test_revoke_guest_link_does_not_affect_metadata(): void
    {
        $file = File::factory()->create(['owner_id' => $this->owner->id]);

        $share = Share::create([
            'file_id' => $file->id,
            'shared_by' => $this->owner->id,
            'shared_with' => null,
            'permission' => 'view',
            'token_hash' => hash('sha256', 'test-token'),
        ]);

        // Owner favorites the file
        UserFavorite::create([
            'user_id' => $this->owner->id,
            'resource_type' => 'file',
            'resource_id' => $file->id,
        ]);

        $this->shareService->revoke($share);

        $this->assertSame(1, UserFavorite::where('user_id', $this->owner->id)->count(), 'owner favorite must remain');
        $this->assertTrue(File::where('id', $file->id)->exists(), 'file must still exist');
    }
}
