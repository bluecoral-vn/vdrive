<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\Folder;
use App\Models\Role;
use App\Models\SyncEvent;
use App\Models\User;
use App\Models\UserFavorite;
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
 * Phase 19 — Favorites Feature.
 */
class FavoriteTest extends TestCase
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
    // ADD FAVORITE
    // ═══════════════════════════════════════════════════════

    public function test_add_file_favorite(): void
    {
        $file = $this->createFile(['name' => 'doc.pdf']);

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/favorites', [
                'resource_type' => 'file',
                'resource_id' => $file->id,
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.resource_type', 'file');
        $response->assertJsonPath('data.resource_id', $file->id);

        $this->assertDatabaseHas('user_favorites', [
            'user_id' => $this->user->id,
            'resource_type' => 'file',
            'resource_id' => $file->id,
        ]);
    }

    public function test_add_folder_favorite(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/favorites', [
                'resource_type' => 'folder',
                'resource_id' => $this->folder->uuid,
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.resource_type', 'folder');
    }

    public function test_add_duplicate_favorite_is_idempotent(): void
    {
        $file = $this->createFile(['name' => 'doc.pdf']);

        $this->actingAs($this->user, 'api')
            ->postJson('/api/favorites', [
                'resource_type' => 'file',
                'resource_id' => $file->id,
            ])->assertStatus(201);

        // Second add should also succeed (idempotent via firstOrCreate)
        $this->actingAs($this->user, 'api')
            ->postJson('/api/favorites', [
                'resource_type' => 'file',
                'resource_id' => $file->id,
            ])->assertStatus(201);

        // Only one record should exist
        $this->assertDatabaseCount('user_favorites', 1);
    }

    public function test_cannot_favorite_file_without_access(): void
    {
        $otherFile = File::factory()->create([
            'folder_id' => $this->otherFolder->id,
            'owner_id' => $this->otherUser->id,
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/favorites', [
                'resource_type' => 'file',
                'resource_id' => $otherFile->id,
            ]);

        $response->assertStatus(403);
    }

    public function test_cannot_favorite_nonexistent_resource(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/favorites', [
                'resource_type' => 'file',
                'resource_id' => 'nonexistent-uuid',
            ]);

        $response->assertStatus(404);
    }

    // ═══════════════════════════════════════════════════════
    // REMOVE FAVORITE
    // ═══════════════════════════════════════════════════════

    public function test_remove_favorite(): void
    {
        $file = $this->createFile(['name' => 'doc.pdf']);

        UserFavorite::query()->create([
            'user_id' => $this->user->id,
            'resource_type' => 'file',
            'resource_id' => $file->id,
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->deleteJson('/api/favorites', [
                'resource_type' => 'file',
                'resource_id' => $file->id,
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseMissing('user_favorites', [
            'user_id' => $this->user->id,
            'resource_id' => $file->id,
        ]);
    }

    public function test_remove_nonexistent_favorite_returns_404(): void
    {
        $file = $this->createFile(['name' => 'doc.pdf']);

        $response = $this->actingAs($this->user, 'api')
            ->deleteJson('/api/favorites', [
                'resource_type' => 'file',
                'resource_id' => $file->id,
            ]);

        $response->assertStatus(404);
    }

    // ═══════════════════════════════════════════════════════
    // LIST FAVORITES
    // ═══════════════════════════════════════════════════════

    public function test_list_favorites(): void
    {
        $file1 = $this->createFile(['name' => 'a.pdf']);
        $file2 = $this->createFile(['name' => 'b.pdf']);

        UserFavorite::query()->create([
            'user_id' => $this->user->id,
            'resource_type' => 'file',
            'resource_id' => $file1->id,
        ]);
        UserFavorite::query()->create([
            'user_id' => $this->user->id,
            'resource_type' => 'file',
            'resource_id' => $file2->id,
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/favorites');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
    }

    public function test_list_favorites_filtered_by_type(): void
    {
        $file = $this->createFile(['name' => 'a.pdf']);

        UserFavorite::query()->create([
            'user_id' => $this->user->id,
            'resource_type' => 'file',
            'resource_id' => $file->id,
        ]);
        UserFavorite::query()->create([
            'user_id' => $this->user->id,
            'resource_type' => 'folder',
            'resource_id' => $this->folder->uuid,
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/favorites?type=file');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('file', $response->json('data.0.resource_type'));
    }

    public function test_list_favorites_pagination(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $file = $this->createFile(['name' => "file-{$i}.pdf"]);
            UserFavorite::query()->create([
                'user_id' => $this->user->id,
                'resource_type' => 'file',
                'resource_id' => $file->id,
            ]);
        }

        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/favorites?limit=2');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
        $this->assertNotNull($response->json('meta.next_cursor'));
    }

    public function test_user_only_sees_own_favorites(): void
    {
        $file = $this->createFile(['name' => 'my-file.pdf']);
        $otherFile = File::factory()->create([
            'folder_id' => $this->otherFolder->id,
            'owner_id' => $this->otherUser->id,
        ]);

        UserFavorite::query()->create([
            'user_id' => $this->user->id,
            'resource_type' => 'file',
            'resource_id' => $file->id,
        ]);
        UserFavorite::query()->create([
            'user_id' => $this->otherUser->id,
            'resource_type' => 'file',
            'resource_id' => $otherFile->id,
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/favorites');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    // ═══════════════════════════════════════════════════════
    // BULK OPERATIONS
    // ═══════════════════════════════════════════════════════

    public function test_bulk_add_favorites(): void
    {
        $file1 = $this->createFile(['name' => 'a.pdf']);
        $file2 = $this->createFile(['name' => 'b.pdf']);

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/favorites/bulk', [
                'resource_type' => 'file',
                'resource_ids' => [$file1->id, $file2->id],
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('added', 2);
        $this->assertDatabaseCount('user_favorites', 2);
    }

    public function test_bulk_remove_favorites(): void
    {
        $file1 = $this->createFile(['name' => 'a.pdf']);
        $file2 = $this->createFile(['name' => 'b.pdf']);

        UserFavorite::query()->create(['user_id' => $this->user->id, 'resource_type' => 'file', 'resource_id' => $file1->id]);
        UserFavorite::query()->create(['user_id' => $this->user->id, 'resource_type' => 'file', 'resource_id' => $file2->id]);

        $response = $this->actingAs($this->user, 'api')
            ->deleteJson('/api/favorites/bulk', [
                'resource_type' => 'file',
                'resource_ids' => [$file1->id, $file2->id],
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('removed', 2);
        $this->assertDatabaseCount('user_favorites', 0);
    }

    // ═══════════════════════════════════════════════════════
    // SEARCH INTEGRATION
    // ═══════════════════════════════════════════════════════

    public function test_search_with_favorite_filter(): void
    {
        $favFile = $this->createFile(['name' => 'favorite-doc.pdf']);
        $this->createFile(['name' => 'regular-doc.pdf']);

        UserFavorite::query()->create([
            'user_id' => $this->user->id,
            'resource_type' => 'file',
            'resource_id' => $favFile->id,
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/search?favorite=1');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('favorite-doc.pdf', $response->json('data.0.name'));
    }

    // ═══════════════════════════════════════════════════════
    // CLEANUP ON HARD DELETE
    // ═══════════════════════════════════════════════════════

    public function test_favorite_cleaned_up_on_file_force_delete(): void
    {
        $file = $this->createFile(['name' => 'to-delete.pdf']);

        UserFavorite::query()->create([
            'user_id' => $this->user->id,
            'resource_type' => 'file',
            'resource_id' => $file->id,
        ]);

        $this->assertDatabaseHas('user_favorites', ['resource_id' => $file->id]);

        $response = $this->actingAs($this->user, 'api')
            ->deleteJson("/api/trash/files/{$file->id}");

        $response->assertStatus(204);

        $this->assertDatabaseMissing('user_favorites', [
            'resource_id' => $file->id,
        ]);
    }

    // ═══════════════════════════════════════════════════════
    // DELTA SYNC — NO SYNC EVENTS
    // ═══════════════════════════════════════════════════════

    public function test_favorite_does_not_emit_sync_event(): void
    {
        $file = $this->createFile(['name' => 'doc.pdf']);
        $syncCountBefore = SyncEvent::query()->count();

        $this->actingAs($this->user, 'api')
            ->postJson('/api/favorites', [
                'resource_type' => 'file',
                'resource_id' => $file->id,
            ])->assertStatus(201);

        $this->assertEquals($syncCountBefore, SyncEvent::query()->count());
    }
}
