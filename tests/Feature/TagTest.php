<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\Folder;
use App\Models\Role;
use App\Models\SyncEvent;
use App\Models\Tag;
use App\Models\Taggable;
use App\Models\User;
use App\Services\R2ClientService;
use Aws\CommandInterface;
use Aws\S3\S3Client;
use Database\Seeders\RolePermissionSeeder;
use GuzzleHttp\Psr7\Uri;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * Phase 19 — Tagging Feature.
 */
class TagTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private User $otherUser;

    private Folder $folder;

    private Folder $otherFolder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $userRole = Role::query()->where('slug', 'user')->firstOrFail();

        $this->user = User::factory()->create();
        $this->user->roles()->attach($userRole);

        $this->otherUser = User::factory()->create();
        $this->otherUser->roles()->attach($userRole);

        $this->folder = Folder::factory()->create(['owner_id' => $this->user->id]);
        $this->otherFolder = Folder::factory()->create(['owner_id' => $this->otherUser->id]);

        $this->mockR2();
    }

    private function mockR2(): void
    {
        $mockS3 = Mockery::mock(S3Client::class);

        $mockS3->shouldReceive('getCommand')
            ->andReturn(Mockery::mock(CommandInterface::class))
            ->byDefault();

        $mockRequest = Mockery::mock(RequestInterface::class);
        $mockRequest->shouldReceive('getUri')
            ->andReturn(new Uri('https://r2.example.com/presigned'))
            ->byDefault();

        $mockS3->shouldReceive('createPresignedRequest')
            ->andReturn($mockRequest)
            ->byDefault();

        $mockS3->shouldReceive('deleteObject')
            ->andReturn([])
            ->byDefault();

        $this->instance(R2ClientService::class, new class($mockS3) extends R2ClientService
        {
            public function __construct(private S3Client $mock) {}

            public function client(): S3Client
            {
                return $this->mock;
            }

            public function bucket(): string
            {
                return 'test-bucket';
            }
        });
    }

    private function createFile(array $attrs = []): File
    {
        return File::factory()->create(array_merge([
            'folder_id' => $this->folder->id,
            'owner_id' => $this->user->id,
        ], $attrs));
    }

    // ═══════════════════════════════════════════════════════
    // TAG CRUD
    // ═══════════════════════════════════════════════════════

    public function test_create_tag(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/tags', ['name' => 'Important']);

        $response->assertStatus(201);
        $response->assertJsonPath('data.name', 'Important');
        $response->assertJsonPath('data.color', null);
    }

    public function test_create_tag_with_color(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/tags', ['name' => 'Urgent', 'color' => '#FF5733']);

        $response->assertStatus(201);
        $response->assertJsonPath('data.color', '#FF5733');
    }

    public function test_create_duplicate_tag_name_fails(): void
    {
        Tag::query()->create(['user_id' => $this->user->id, 'name' => 'Work']);

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/tags', ['name' => 'Work']);

        $response->assertStatus(422);
    }

    public function test_different_users_can_have_same_tag_name(): void
    {
        Tag::query()->create(['user_id' => $this->otherUser->id, 'name' => 'Work']);

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/tags', ['name' => 'Work']);

        $response->assertStatus(201);
    }

    public function test_update_tag_name(): void
    {
        $tag = Tag::query()->create(['user_id' => $this->user->id, 'name' => 'Old']);

        $response = $this->actingAs($this->user, 'api')
            ->patchJson("/api/tags/{$tag->uuid}", ['name' => 'New']);

        $response->assertStatus(200);
        $response->assertJsonPath('data.name', 'New');
    }

    public function test_update_tag_color(): void
    {
        $tag = Tag::query()->create(['user_id' => $this->user->id, 'name' => 'Tag']);

        $response = $this->actingAs($this->user, 'api')
            ->patchJson("/api/tags/{$tag->uuid}", ['color' => '#00FF00']);

        $response->assertStatus(200);
        $response->assertJsonPath('data.color', '#00FF00');
    }

    public function test_delete_tag(): void
    {
        $tag = Tag::query()->create(['user_id' => $this->user->id, 'name' => 'ToDelete']);

        $response = $this->actingAs($this->user, 'api')
            ->deleteJson("/api/tags/{$tag->uuid}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('tags', ['id' => $tag->id]);
    }

    public function test_delete_tag_cascades_taggables(): void
    {
        $tag = Tag::query()->create(['user_id' => $this->user->id, 'name' => 'CascadeTest']);
        $file = $this->createFile(['name' => 'test.pdf']);

        Taggable::query()->create([
            'tag_id' => $tag->id,
            'resource_type' => 'file',
            'resource_id' => $file->id,
        ]);

        $this->actingAs($this->user, 'api')
            ->deleteJson("/api/tags/{$tag->uuid}");

        $this->assertDatabaseMissing('taggables', ['tag_id' => $tag->id]);
    }

    public function test_cannot_update_other_users_tag(): void
    {
        $tag = Tag::query()->create(['user_id' => $this->otherUser->id, 'name' => 'Other']);

        $response = $this->actingAs($this->user, 'api')
            ->patchJson("/api/tags/{$tag->uuid}", ['name' => 'Hacked']);

        $response->assertStatus(404);
    }

    public function test_cannot_delete_other_users_tag(): void
    {
        $tag = Tag::query()->create(['user_id' => $this->otherUser->id, 'name' => 'Other']);

        $response = $this->actingAs($this->user, 'api')
            ->deleteJson("/api/tags/{$tag->uuid}");

        $response->assertStatus(404);
    }

    public function test_list_tags(): void
    {
        Tag::query()->create(['user_id' => $this->user->id, 'name' => 'Alpha']);
        Tag::query()->create(['user_id' => $this->user->id, 'name' => 'Beta']);
        Tag::query()->create(['user_id' => $this->otherUser->id, 'name' => 'Hidden']);

        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/tags');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
    }

    public function test_list_tags_includes_items_count(): void
    {
        $tag = Tag::query()->create(['user_id' => $this->user->id, 'name' => 'Counted']);
        $file = $this->createFile(['name' => 'file.pdf']);

        Taggable::query()->create(['tag_id' => $tag->id, 'resource_type' => 'file', 'resource_id' => $file->id]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/tags');

        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('data.0.items_count'));
    }

    // ═══════════════════════════════════════════════════════
    // ASSIGN / UNASSIGN
    // ═══════════════════════════════════════════════════════

    public function test_assign_tag_to_file(): void
    {
        $tag = Tag::query()->create(['user_id' => $this->user->id, 'name' => 'Important']);
        $file = $this->createFile(['name' => 'doc.pdf']);

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/tags/assign', [
                'tag_ids' => [$tag->uuid],
                'resource_type' => 'file',
                'resource_ids' => [$file->id],
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('taggables', [
            'tag_id' => $tag->id,
            'resource_type' => 'file',
            'resource_id' => $file->id,
        ]);
    }

    public function test_assign_tag_to_folder(): void
    {
        $tag = Tag::query()->create(['user_id' => $this->user->id, 'name' => 'Project']);

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/tags/assign', [
                'tag_ids' => [$tag->uuid],
                'resource_type' => 'folder',
                'resource_ids' => [$this->folder->uuid],
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('taggables', [
            'tag_id' => $tag->id,
            'resource_type' => 'folder',
            'resource_id' => $this->folder->uuid,
        ]);
    }

    public function test_assign_multiple_tags_to_multiple_files(): void
    {
        $tag1 = Tag::query()->create(['user_id' => $this->user->id, 'name' => 'Tag1']);
        $tag2 = Tag::query()->create(['user_id' => $this->user->id, 'name' => 'Tag2']);
        $file1 = $this->createFile(['name' => 'one.pdf']);
        $file2 = $this->createFile(['name' => 'two.pdf']);

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/tags/assign', [
                'tag_ids' => [$tag1->uuid, $tag2->uuid],
                'resource_type' => 'file',
                'resource_ids' => [$file1->id, $file2->id],
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseCount('taggables', 4); // 2 tags × 2 files
    }

    public function test_cannot_assign_tag_to_file_without_access(): void
    {
        $tag = Tag::query()->create(['user_id' => $this->user->id, 'name' => 'Nope']);
        $otherFile = File::factory()->create([
            'folder_id' => $this->otherFolder->id,
            'owner_id' => $this->otherUser->id,
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/tags/assign', [
                'tag_ids' => [$tag->uuid],
                'resource_type' => 'file',
                'resource_ids' => [$otherFile->id],
            ]);

        $response->assertStatus(403);
    }

    public function test_cannot_assign_other_users_tag(): void
    {
        $otherTag = Tag::query()->create(['user_id' => $this->otherUser->id, 'name' => 'NotMine']);
        $file = $this->createFile(['name' => 'doc.pdf']);

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/tags/assign', [
                'tag_ids' => [$otherTag->uuid],
                'resource_type' => 'file',
                'resource_ids' => [$file->id],
            ]);

        $response->assertStatus(422);
    }

    public function test_unassign_tag(): void
    {
        $tag = Tag::query()->create(['user_id' => $this->user->id, 'name' => 'Remove']);
        $file = $this->createFile(['name' => 'doc.pdf']);

        Taggable::query()->create([
            'tag_id' => $tag->id,
            'resource_type' => 'file',
            'resource_id' => $file->id,
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/tags/unassign', [
                'tag_ids' => [$tag->uuid],
                'resource_type' => 'file',
                'resource_ids' => [$file->id],
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseMissing('taggables', ['tag_id' => $tag->id, 'resource_id' => $file->id]);
    }

    // ═══════════════════════════════════════════════════════
    // TAG ITEMS
    // ═══════════════════════════════════════════════════════

    public function test_list_tag_items(): void
    {
        $tag = Tag::query()->create(['user_id' => $this->user->id, 'name' => 'Items']);
        $file = $this->createFile(['name' => 'tagged.pdf']);

        Taggable::query()->create(['tag_id' => $tag->id, 'resource_type' => 'file', 'resource_id' => $file->id]);
        Taggable::query()->create(['tag_id' => $tag->id, 'resource_type' => 'folder', 'resource_id' => $this->folder->uuid]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/tags/{$tag->uuid}/items");

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('files'));
        $this->assertCount(1, $response->json('folders'));
    }

    public function test_cannot_list_items_of_other_users_tag(): void
    {
        $otherTag = Tag::query()->create(['user_id' => $this->otherUser->id, 'name' => 'Secret']);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/tags/{$otherTag->uuid}/items");

        $response->assertStatus(404);
    }

    // ═══════════════════════════════════════════════════════
    // SEARCH INTEGRATION
    // ═══════════════════════════════════════════════════════

    public function test_search_with_tag_filter(): void
    {
        $tag = Tag::query()->create(['user_id' => $this->user->id, 'name' => 'SearchTag']);
        $taggedFile = $this->createFile(['name' => 'tagged.pdf']);
        $this->createFile(['name' => 'untagged.pdf']);

        Taggable::query()->create(['tag_id' => $tag->id, 'resource_type' => 'file', 'resource_id' => $taggedFile->id]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/search?tag={$tag->uuid}");

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('tagged.pdf', $response->json('data.0.name'));
    }

    public function test_search_with_tag_and_query_combined(): void
    {
        $tag = Tag::query()->create(['user_id' => $this->user->id, 'name' => 'ComboTag']);
        $file1 = $this->createFile(['name' => 'report-jan.pdf']);
        $file2 = $this->createFile(['name' => 'report-feb.pdf']);
        $this->createFile(['name' => 'other.pdf']);

        Taggable::query()->create(['tag_id' => $tag->id, 'resource_type' => 'file', 'resource_id' => $file1->id]);
        Taggable::query()->create(['tag_id' => $tag->id, 'resource_type' => 'file', 'resource_id' => $file2->id]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/search?tag={$tag->uuid}&query=jan");

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('report-jan.pdf', $response->json('data.0.name'));
    }

    // ═══════════════════════════════════════════════════════
    // CLEANUP ON HARD DELETE
    // ═══════════════════════════════════════════════════════

    public function test_taggable_cleaned_up_on_file_force_delete(): void
    {
        $tag = Tag::query()->create(['user_id' => $this->user->id, 'name' => 'Cleanup']);
        $file = $this->createFile(['name' => 'to-delete.pdf']);

        Taggable::query()->create(['tag_id' => $tag->id, 'resource_type' => 'file', 'resource_id' => $file->id]);

        $this->assertDatabaseHas('taggables', ['resource_id' => $file->id]);

        $response = $this->actingAs($this->user, 'api')
            ->deleteJson("/api/trash/files/{$file->id}");

        $response->assertStatus(204);

        $this->assertDatabaseMissing('taggables', ['resource_id' => $file->id]);
        // Tag entity should still exist
        $this->assertDatabaseHas('tags', ['id' => $tag->id]);
    }

    // ═══════════════════════════════════════════════════════
    // DELTA SYNC — NO SYNC EVENTS
    // ═══════════════════════════════════════════════════════

    public function test_tag_operations_do_not_emit_sync_events(): void
    {
        $syncCountBefore = SyncEvent::query()->count();

        // Create tag
        $createResp = $this->actingAs($this->user, 'api')
            ->postJson('/api/tags', ['name' => 'SyncTest']);
        $createResp->assertStatus(201);
        $tagId = $createResp->json('data.id');

        // Assign tag
        $file = $this->createFile(['name' => 'doc.pdf']);
        $this->actingAs($this->user, 'api')
            ->postJson('/api/tags/assign', [
                'tag_ids' => [$tagId],
                'resource_type' => 'file',
                'resource_ids' => [$file->id],
            ])->assertStatus(200);

        // Verify no sync events were created
        $this->assertEquals($syncCountBefore, SyncEvent::query()->count());
    }

    // ═══════════════════════════════════════════════════════
    // VALIDATION
    // ═══════════════════════════════════════════════════════

    public function test_create_tag_validates_color_format(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/tags', ['name' => 'BadColor', 'color' => 'red']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('color');
    }

    public function test_create_tag_validates_name_length(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/tags', ['name' => str_repeat('A', 51)]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('name');
    }
}
