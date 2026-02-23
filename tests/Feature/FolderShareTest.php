<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\Folder;
use App\Models\Role;
use App\Models\Share;
use App\Models\User;
use App\Services\R2ClientService;
use Aws\CommandInterface;
use Aws\S3\S3Client;
use Database\Seeders\RolePermissionSeeder;
use GuzzleHttp\Psr7\Uri;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * Phase 8 — Folder Sharing & Inherited Permissions.
 */
class FolderShareTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $recipient;

    private User $stranger;

    private User $admin;

    private Folder $folder;

    private Folder $childFolder;

    private File $file;

    private File $childFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $adminRole = Role::query()->where('slug', 'admin')->firstOrFail();
        $userRole = Role::query()->where('slug', 'user')->firstOrFail();

        $this->admin = User::factory()->create();
        $this->admin->roles()->attach($adminRole);

        $this->owner = User::factory()->create();
        $this->owner->roles()->attach($userRole);

        $this->recipient = User::factory()->create();
        $this->recipient->roles()->attach($userRole);

        $this->stranger = User::factory()->create();
        $this->stranger->roles()->attach($userRole);

        // Structure: folder > childFolder > childFile
        //                  > file
        $this->folder = Folder::factory()->create(['owner_id' => $this->owner->id]);
        $this->file = File::factory()->create([
            'folder_id' => $this->folder->id,
            'owner_id' => $this->owner->id,
        ]);

        $this->childFolder = Folder::factory()->create([
            'parent_id' => $this->folder->id,
            'owner_id' => $this->owner->id,
        ]);
        $this->childFile = File::factory()->create([
            'folder_id' => $this->childFolder->id,
            'owner_id' => $this->owner->id,
        ]);

        $this->mockR2();
    }

    private function mockR2(): void
    {
        $mockS3 = Mockery::mock(S3Client::class);

        $mockS3->shouldReceive('createMultipartUpload')
            ->andReturn(['UploadId' => 'mock-upload-id'])
            ->byDefault();

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

        $mockS3->shouldReceive('completeMultipartUpload')->andReturn([])->byDefault();
        $mockS3->shouldReceive('abortMultipartUpload')->andReturn([])->byDefault();
        $mockS3->shouldReceive('deleteObject')->andReturn([])->byDefault();

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

    // ═══════════════════════════════════════════════════════
    // FOLDER SHARING — CREATE
    // ═══════════════════════════════════════════════════════

    public function test_owner_can_share_folder_with_user_view(): void
    {
        $response = $this->actingAs($this->owner, 'api')
            ->postJson('/api/share', [
                'folder_id' => $this->folder->uuid,
                'shared_with' => $this->recipient->id,
                'permission' => 'view',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.folder_id', $this->folder->uuid)
            ->assertJsonPath('data.shared_with.id', $this->recipient->id)
            ->assertJsonPath('data.permission', 'view')
            ->assertJsonPath('data.is_folder_share', true)
            ->assertJsonPath('data.is_file_share', false);
    }

    public function test_owner_can_share_folder_with_edit(): void
    {
        $response = $this->actingAs($this->owner, 'api')
            ->postJson('/api/share', [
                'folder_id' => $this->folder->uuid,
                'shared_with' => $this->recipient->id,
                'permission' => 'edit',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.permission', 'edit');
    }

    public function test_owner_can_create_guest_link_for_folder(): void
    {
        $response = $this->actingAs($this->owner, 'api')
            ->postJson('/api/share', [
                'folder_id' => $this->folder->uuid,
                'permission' => 'view',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.is_guest_link', true)
            ->assertJsonPath('data.is_folder_share', true);

        $token = $response->json('data.token');
        $this->assertNotNull($token);
        $this->assertEquals(64, strlen($token));
    }

    public function test_creating_guest_link_for_folder_twice_returns_same_share(): void
    {
        $response1 = $this->actingAs($this->owner, 'api')
            ->postJson('/api/share', [
                'folder_id' => $this->folder->uuid,
                'permission' => 'view',
            ]);

        $response1->assertStatus(201);
        $shareId1 = $response1->json('data.id');

        $response2 = $this->actingAs($this->owner, 'api')
            ->postJson('/api/share', [
                'folder_id' => $this->folder->uuid,
                'permission' => 'view',
            ]);

        $response2->assertStatus(201);
        $shareId2 = $response2->json('data.id');

        $this->assertEquals($shareId1, $shareId2);

        $count = Share::query()
            ->where('folder_id', $this->folder->id)
            ->whereNull('shared_with')
            ->count();
        $this->assertEquals(1, $count);
    }

    public function test_non_owner_cannot_share_folder(): void
    {
        $response = $this->actingAs($this->stranger, 'api')
            ->postJson('/api/share', [
                'folder_id' => $this->folder->uuid,
                'shared_with' => $this->recipient->id,
                'permission' => 'view',
            ]);

        $response->assertStatus(403);
    }

    public function test_cannot_share_both_file_and_folder(): void
    {
        $response = $this->actingAs($this->owner, 'api')
            ->postJson('/api/share', [
                'file_id' => $this->file->id,
                'folder_id' => $this->folder->uuid,
                'shared_with' => $this->recipient->id,
                'permission' => 'view',
            ]);

        $response->assertStatus(422);
    }

    public function test_must_provide_file_or_folder(): void
    {
        $response = $this->actingAs($this->owner, 'api')
            ->postJson('/api/share', [
                'shared_with' => $this->recipient->id,
                'permission' => 'view',
            ]);

        $response->assertStatus(422);
    }

    // ═══════════════════════════════════════════════════════
    // INHERITED PERMISSION — FILES
    // ═══════════════════════════════════════════════════════

    public function test_folder_share_grants_view_to_file_inside(): void
    {
        // Share folder with recipient
        Share::query()->create([
            'folder_id' => $this->folder->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'view',
        ]);

        // Recipient can view file inside shared folder
        $response = $this->actingAs($this->recipient, 'api')
            ->getJson("/api/files/{$this->file->id}");

        $response->assertStatus(200);
    }

    public function test_folder_view_share_grants_download_to_file(): void
    {
        Share::query()->create([
            'folder_id' => $this->folder->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'view',
        ]);

        $response = $this->actingAs($this->recipient, 'api')
            ->getJson("/api/files/{$this->file->id}/download");

        $response->assertStatus(200);
    }

    public function test_folder_edit_share_grants_download_to_file(): void
    {
        Share::query()->create([
            'folder_id' => $this->folder->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'edit',
        ]);

        $response = $this->actingAs($this->recipient, 'api')
            ->getJson("/api/files/{$this->file->id}/download");

        $response->assertStatus(200);
    }

    public function test_inherited_access_works_through_nested_folders(): void
    {
        // Share parent folder → should grant access to grandchild file
        Share::query()->create([
            'folder_id' => $this->folder->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'view',
        ]);

        // childFile is in childFolder which is in folder
        $response = $this->actingAs($this->recipient, 'api')
            ->getJson("/api/files/{$this->childFile->id}");

        $response->assertStatus(200);
    }

    public function test_direct_file_share_overrides_folder_revocation(): void
    {
        // Create folder share
        $folderShare = Share::query()->create([
            'folder_id' => $this->folder->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'view',
        ]);

        // Also create direct file share
        Share::query()->create([
            'file_id' => $this->file->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'view',
        ]);

        // Revoke folder share
        $folderShare->delete();

        // Recipient still has access via direct file share
        $response = $this->actingAs($this->recipient, 'api')
            ->getJson("/api/files/{$this->file->id}");

        $response->assertStatus(200);
    }

    // ═══════════════════════════════════════════════════════
    // INHERITED PERMISSION — FOLDERS
    // ═══════════════════════════════════════════════════════

    public function test_folder_share_grants_view_to_child_folder(): void
    {
        Share::query()->create([
            'folder_id' => $this->folder->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'view',
        ]);

        $response = $this->actingAs($this->recipient, 'api')
            ->getJson("/api/folders/{$this->childFolder->uuid}");

        $response->assertStatus(200);
    }

    public function test_inherited_share_grants_access_to_deeply_nested_folder(): void
    {
        $grandchild = Folder::factory()->create([
            'parent_id' => $this->childFolder->id,
            'owner_id' => $this->owner->id,
        ]);

        Share::query()->create([
            'folder_id' => $this->folder->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'view',
        ]);

        $response = $this->actingAs($this->recipient, 'api')
            ->getJson("/api/folders/{$grandchild->uuid}");

        $response->assertStatus(200);
    }

    // ═══════════════════════════════════════════════════════
    // REVOCATION
    // ═══════════════════════════════════════════════════════

    public function test_revoking_folder_share_removes_inherited_file_access(): void
    {
        $share = Share::query()->create([
            'folder_id' => $this->folder->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'view',
        ]);

        // Before revocation — access granted
        $this->actingAs($this->recipient, 'api')
            ->getJson("/api/files/{$this->file->id}")
            ->assertStatus(200);

        // Revoke
        $this->actingAs($this->owner, 'api')
            ->deleteJson("/api/share/{$share->id}")
            ->assertStatus(204);

        // After revocation — access denied
        $this->actingAs($this->recipient, 'api')
            ->getJson("/api/files/{$this->file->id}")
            ->assertStatus(403);
    }

    public function test_revoking_folder_share_removes_inherited_subfolder_access(): void
    {
        $share = Share::query()->create([
            'folder_id' => $this->folder->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'view',
        ]);

        // Revoke
        $this->actingAs($this->owner, 'api')
            ->deleteJson("/api/share/{$share->id}")
            ->assertStatus(204);

        // After revocation — subfolder access denied
        $this->actingAs($this->recipient, 'api')
            ->getJson("/api/folders/{$this->childFolder->uuid}")
            ->assertStatus(403);
    }

    public function test_admin_can_revoke_any_share(): void
    {
        $share = Share::query()->create([
            'folder_id' => $this->folder->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'view',
        ]);

        $this->actingAs($this->admin, 'api')
            ->deleteJson("/api/share/{$share->id}")
            ->assertStatus(204);

        $this->assertDatabaseMissing('shares', ['id' => $share->id]);
    }

    public function test_stranger_cannot_revoke_share(): void
    {
        $share = Share::query()->create([
            'folder_id' => $this->folder->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'view',
        ]);

        $this->actingAs($this->stranger, 'api')
            ->deleteJson("/api/share/{$share->id}")
            ->assertStatus(404);
    }

    // ═══════════════════════════════════════════════════════
    // EDGE CASES
    // ═══════════════════════════════════════════════════════

    public function test_direct_share_takes_precedence_over_inherited(): void
    {
        // Folder shared with 'view'
        Share::query()->create([
            'folder_id' => $this->folder->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'view',
        ]);

        // Direct file share with 'edit'
        Share::query()->create([
            'file_id' => $this->file->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'edit',
        ]);

        // Should be able to download via direct share even though folder is view-only
        $response = $this->actingAs($this->recipient, 'api')
            ->getJson("/api/files/{$this->file->id}/download");

        $response->assertStatus(200);
    }

    public function test_nested_folder_shares_most_permissive_wins(): void
    {
        // Parent folder shared with 'view'
        Share::query()->create([
            'folder_id' => $this->folder->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'view',
        ]);

        // Child folder shared with 'edit'
        Share::query()->create([
            'folder_id' => $this->childFolder->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'edit',
        ]);

        // childFile is in childFolder — should get 'edit' permission (download included)
        $response = $this->actingAs($this->recipient, 'api')
            ->getJson("/api/files/{$this->childFile->id}/download");

        $response->assertStatus(200);
    }

    public function test_guest_token_on_folder_returns_folder_data(): void
    {
        $rawToken = Str::random(64);

        Share::query()->create([
            'folder_id' => $this->folder->id,
            'shared_by' => $this->owner->id,
            'shared_with' => null,
            'token_hash' => hash('sha256', $rawToken),
            'permission' => 'view',
        ]);

        $response = $this->getJson("/api/share/{$rawToken}");

        $response->assertStatus(200)
            ->assertJsonPath('data.permission', 'view')
            ->assertJsonStructure(['data' => [
                'folder' => ['id', 'name'],
                'children',
                'files',
            ]]);

        // Folder contains 1 sub-folder (childFolder) and 1 file (file)
        $children = $response->json('data.children');
        $files = $response->json('data.files');

        $this->assertCount(1, $children);
        $this->assertEquals($this->childFolder->uuid, $children[0]['id']);
        $this->assertEquals($this->childFolder->name, $children[0]['name']);

        $this->assertCount(1, $files);
        $this->assertEquals($this->file->id, $files[0]['id']);
        $this->assertEquals($this->file->name, $files[0]['name']);
    }

    public function test_expired_folder_share_does_not_grant_access(): void
    {
        Share::query()->create([
            'folder_id' => $this->folder->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'view',
            'expires_at' => now()->subHour(),
        ]);

        $response = $this->actingAs($this->recipient, 'api')
            ->getJson("/api/files/{$this->file->id}");

        $response->assertStatus(403);
    }

    public function test_stranger_still_cannot_access_after_folder_share(): void
    {
        // Share folder with recipient only
        Share::query()->create([
            'folder_id' => $this->folder->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'view',
        ]);

        // Stranger should still not have access
        $response = $this->actingAs($this->stranger, 'api')
            ->getJson("/api/files/{$this->file->id}");

        $response->assertStatus(403);
    }

    public function test_shared_with_me_includes_folder_shares(): void
    {
        Share::query()->create([
            'folder_id' => $this->folder->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'view',
        ]);

        $response = $this->actingAs($this->recipient, 'api')
            ->getJson('/api/share/with-me');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertTrue($response->json('data.0.is_folder_share'));
    }

    public function test_file_share_still_works_after_folder_share_feature(): void
    {
        // Existing file share behavior should not regress
        $response = $this->actingAs($this->owner, 'api')
            ->postJson('/api/share', [
                'file_id' => $this->file->id,
                'shared_with' => $this->recipient->id,
                'permission' => 'view',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.is_file_share', true)
            ->assertJsonPath('data.is_folder_share', false);

        // Recipient can view file
        $this->actingAs($this->recipient, 'api')
            ->getJson("/api/files/{$this->file->id}")
            ->assertStatus(200);
    }

    // ═══════════════════════════════════════════════════════
    // FOLDER SHARE — SUB-FOLDER BROWSING
    // ═══════════════════════════════════════════════════════

    public function test_guest_can_browse_subfolder_via_token(): void
    {
        $rawToken = Str::random(64);

        Share::query()->create([
            'folder_id' => $this->folder->id,
            'shared_by' => $this->owner->id,
            'shared_with' => null,
            'token_hash' => hash('sha256', $rawToken),
            'permission' => 'view',
        ]);

        $response = $this->getJson("/api/share/{$rawToken}/folders/{$this->childFolder->uuid}");

        $response->assertStatus(200)
            ->assertJsonPath('data.permission', 'view')
            ->assertJsonPath('data.folder.id', $this->childFolder->uuid)
            ->assertJsonStructure(['data' => ['folder', 'children', 'files']]);

        // childFolder contains childFile
        $files = $response->json('data.files');
        $this->assertCount(1, $files);
        $this->assertEquals($this->childFile->id, $files[0]['id']);
    }

    public function test_guest_can_browse_shared_root_folder_via_browse_endpoint(): void
    {
        $rawToken = Str::random(64);

        Share::query()->create([
            'folder_id' => $this->folder->id,
            'shared_by' => $this->owner->id,
            'shared_with' => null,
            'token_hash' => hash('sha256', $rawToken),
            'permission' => 'view',
        ]);

        // Can also browse the root shared folder itself
        $response = $this->getJson("/api/share/{$rawToken}/folders/{$this->folder->uuid}");

        $response->assertStatus(200)
            ->assertJsonPath('data.folder.id', $this->folder->uuid);
    }

    public function test_guest_cannot_browse_unrelated_folder_via_token(): void
    {
        $rawToken = Str::random(64);
        $unrelatedFolder = Folder::factory()->create(['owner_id' => $this->owner->id]);

        Share::query()->create([
            'folder_id' => $this->folder->id,
            'shared_by' => $this->owner->id,
            'shared_with' => null,
            'token_hash' => hash('sha256', $rawToken),
            'permission' => 'view',
        ]);

        $response = $this->getJson("/api/share/{$rawToken}/folders/{$unrelatedFolder->uuid}");

        $response->assertStatus(404);
    }

    // ═══════════════════════════════════════════════════════
    // FOLDER SHARE — FILE ACCESS
    // ═══════════════════════════════════════════════════════

    public function test_guest_can_access_file_inside_shared_folder(): void
    {
        $rawToken = Str::random(64);

        Share::query()->create([
            'folder_id' => $this->folder->id,
            'shared_by' => $this->owner->id,
            'shared_with' => null,
            'token_hash' => hash('sha256', $rawToken),
            'permission' => 'view',
        ]);

        $response = $this->getJson("/api/share/{$rawToken}/files/{$this->file->id}");

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['file' => ['id', 'name'], 'url', 'permission']])
            ->assertJsonPath('data.permission', 'view');
    }

    public function test_guest_can_access_nested_file_inside_shared_folder(): void
    {
        $rawToken = Str::random(64);

        Share::query()->create([
            'folder_id' => $this->folder->id,
            'shared_by' => $this->owner->id,
            'shared_with' => null,
            'token_hash' => hash('sha256', $rawToken),
            'permission' => 'view',
        ]);

        // childFile is in childFolder which is in folder
        $response = $this->getJson("/api/share/{$rawToken}/files/{$this->childFile->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.file.id', $this->childFile->id)
            ->assertJsonPath('data.permission', 'view');
    }

    public function test_guest_cannot_access_file_outside_shared_folder(): void
    {
        $rawToken = Str::random(64);
        $unrelatedFile = File::factory()->create(['owner_id' => $this->owner->id]);

        Share::query()->create([
            'folder_id' => $this->folder->id,
            'shared_by' => $this->owner->id,
            'shared_with' => null,
            'token_hash' => hash('sha256', $rawToken),
            'permission' => 'view',
        ]);

        $response = $this->getJson("/api/share/{$rawToken}/files/{$unrelatedFile->id}");

        $response->assertStatus(404);
    }

    public function test_file_endpoint_returns_correct_permission(): void
    {
        $rawToken = Str::random(64);

        Share::query()->create([
            'folder_id' => $this->folder->id,
            'shared_by' => $this->owner->id,
            'shared_with' => null,
            'token_hash' => hash('sha256', $rawToken),
            'permission' => 'view',
        ]);

        $response = $this->getJson("/api/share/{$rawToken}/files/{$this->file->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.permission', 'view');
    }

    // ═══════════════════════════════════════════════════════
    // DEDUPLICATION — GUEST LINKS
    // ═══════════════════════════════════════════════════════

    public function test_creating_file_guest_link_twice_returns_same_share(): void
    {
        $response1 = $this->actingAs($this->owner, 'api')
            ->postJson('/api/share', [
                'file_id' => $this->file->id,
                'permission' => 'view',
            ]);

        $response1->assertStatus(201);
        $shareId1 = $response1->json('data.id');

        $response2 = $this->actingAs($this->owner, 'api')
            ->postJson('/api/share', [
                'file_id' => $this->file->id,
                'permission' => 'view',
            ]);

        $response2->assertStatus(201);
        $shareId2 = $response2->json('data.id');

        $this->assertEquals($shareId1, $shareId2);

        $count = Share::query()
            ->where('file_id', $this->file->id)
            ->whereNull('shared_with')
            ->count();
        $this->assertEquals(1, $count);
    }

    public function test_creating_guest_link_after_expiry_reuses_same_row(): void
    {
        // Insert expired share directly (API rejects past expires_at)
        $expiredShare = Share::query()->create([
            'file_id' => $this->file->id,
            'shared_by' => $this->owner->id,
            'shared_with' => null,
            'token_hash' => hash('sha256', Str::random(64)),
            'permission' => 'view',
            'expires_at' => now()->subHour(),
        ]);

        // Create again via API — should reuse the expired row, not create a new one
        $response = $this->actingAs($this->owner, 'api')
            ->postJson('/api/share', [
                'file_id' => $this->file->id,
                'permission' => 'view',
            ]);

        $response->assertStatus(201);

        $this->assertEquals($expiredShare->id, $response->json('data.id'));

        // Permission should be updated
        $this->assertEquals('view', $response->json('data.permission'));

        // Only one row
        $count = Share::query()
            ->where('file_id', $this->file->id)
            ->whereNull('shared_with')
            ->count();
        $this->assertEquals(1, $count);
    }

    public function test_creating_folder_guest_link_after_expiry_reuses_same_row(): void
    {
        // Insert expired folder guest link directly
        $expiredShare = Share::query()->create([
            'folder_id' => $this->folder->id,
            'shared_by' => $this->owner->id,
            'shared_with' => null,
            'token_hash' => hash('sha256', Str::random(64)),
            'permission' => 'view',
            'expires_at' => now()->subHour(),
        ]);

        // Create again via API — should reuse
        $response = $this->actingAs($this->owner, 'api')
            ->postJson('/api/share', [
                'folder_id' => $this->folder->uuid,
                'permission' => 'view',
            ]);

        $response->assertStatus(201);

        $this->assertEquals($expiredShare->id, $response->json('data.id'));

        $count = Share::query()
            ->where('folder_id', $this->folder->id)
            ->whereNull('shared_with')
            ->count();
        $this->assertEquals(1, $count);
    }

    public function test_cleanup_removes_duplicate_guest_links(): void
    {
        // Manually insert duplicate guest links for the same file
        Share::query()->create([
            'file_id' => $this->file->id,
            'shared_by' => $this->owner->id,
            'shared_with' => null,
            'token_hash' => hash('sha256', Str::random(64)),
            'permission' => 'view',
        ]);
        Share::query()->create([
            'file_id' => $this->file->id,
            'shared_by' => $this->owner->id,
            'shared_with' => null,
            'token_hash' => hash('sha256', Str::random(64)),
            'permission' => 'view',
        ]);
        Share::query()->create([
            'file_id' => $this->file->id,
            'shared_by' => $this->owner->id,
            'shared_with' => null,
            'token_hash' => hash('sha256', Str::random(64)),
            'permission' => 'view',
        ]);

        // 3 rows exist
        $this->assertEquals(3, Share::query()
            ->where('file_id', $this->file->id)
            ->whereNull('shared_with')
            ->count());

        $shareService = app(\App\Services\ShareService::class);
        $deleted = $shareService->cleanupDuplicateGuestLinks();

        $this->assertEquals(2, $deleted);

        // Only 1 row remains
        $this->assertEquals(1, Share::query()
            ->where('file_id', $this->file->id)
            ->whereNull('shared_with')
            ->count());
    }

    public function test_shared_by_me_returns_no_duplicates(): void
    {
        // Create one file share and one folder share
        $this->actingAs($this->owner, 'api')
            ->postJson('/api/share', [
                'file_id' => $this->file->id,
                'permission' => 'view',
            ])->assertStatus(201);

        $this->actingAs($this->owner, 'api')
            ->postJson('/api/share', [
                'folder_id' => $this->folder->uuid,
                'permission' => 'view',
            ])->assertStatus(201);

        // Call "shared by me" and ensure no duplicates
        $response = $this->actingAs($this->owner, 'api')
            ->getJson('/api/share/by-me');

        $response->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertEquals($ids->count(), $ids->unique()->count(), 'sharedByMe returned duplicate share IDs');
    }

    // ═══════════════════════════════════════════════════════
    // EDIT PERMISSION — VALIDATION
    // ═══════════════════════════════════════════════════════

    public function test_edit_permission_requires_shared_with(): void
    {
        $response = $this->actingAs($this->owner, 'api')
            ->postJson('/api/share', [
                'file_id' => $this->file->id,
                'permission' => 'edit',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('permission');
    }

    public function test_edit_permission_rejected_for_folder_guest_link(): void
    {
        $response = $this->actingAs($this->owner, 'api')
            ->postJson('/api/share', [
                'folder_id' => $this->folder->uuid,
                'permission' => 'edit',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('permission');
    }

    public function test_download_permission_no_longer_accepted(): void
    {
        $response = $this->actingAs($this->owner, 'api')
            ->postJson('/api/share', [
                'file_id' => $this->file->id,
                'shared_with' => $this->recipient->id,
                'permission' => 'download',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('permission');
    }

    public function test_guest_download_via_token_with_view_permission(): void
    {
        $rawToken = Str::random(64);

        Share::query()->create([
            'file_id' => $this->file->id,
            'shared_by' => $this->owner->id,
            'shared_with' => null,
            'token_hash' => hash('sha256', $rawToken),
            'permission' => 'view',
        ]);

        $response = $this->getJson("/api/share/{$rawToken}/download");

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['url', 'filename', 'expires_in']]);
    }

    // ═══════════════════════════════════════════════════════
    // PATCH SHARE — UPDATE EXPIRY
    // ═══════════════════════════════════════════════════════

    public function test_owner_can_update_share_expiry(): void
    {
        $share = Share::query()->create([
            'file_id' => $this->file->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'view',
        ]);

        $newExpiry = now()->addDays(7)->toIso8601String();

        $response = $this->actingAs($this->owner, 'api')
            ->patchJson("/api/share/{$share->id}", [
                'expires_at' => $newExpiry,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $share->id);

        $this->assertNotNull($response->json('data.expires_at'));
    }

    public function test_owner_can_clear_share_expiry(): void
    {
        $share = Share::query()->create([
            'file_id' => $this->file->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'view',
            'expires_at' => now()->addDay(),
        ]);

        $response = $this->actingAs($this->owner, 'api')
            ->patchJson("/api/share/{$share->id}", [
                'expires_at' => null,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.expires_at', null);
    }

    public function test_update_share_with_past_date_returns_422(): void
    {
        $share = Share::query()->create([
            'file_id' => $this->file->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'view',
        ]);

        $response = $this->actingAs($this->owner, 'api')
            ->patchJson("/api/share/{$share->id}", [
                'expires_at' => now()->subDay()->toIso8601String(),
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('expires_at');
    }

    public function test_non_owner_cannot_update_share(): void
    {
        $share = Share::query()->create([
            'file_id' => $this->file->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'view',
        ]);

        $response = $this->actingAs($this->recipient, 'api')
            ->patchJson("/api/share/{$share->id}", [
                'expires_at' => now()->addDays(7)->toIso8601String(),
            ]);

        $response->assertStatus(404);
    }

    // ═══════════════════════════════════════════════════════
    // BY-ME TOKEN EXPOSURE
    // ═══════════════════════════════════════════════════════

    public function test_by_me_includes_token_for_guest_links(): void
    {
        // Create a guest link share (token will be stored)
        $response = $this->actingAs($this->owner, 'api')
            ->postJson('/api/share', [
                'file_id' => $this->file->id,
                'permission' => 'view',
            ]);

        $response->assertStatus(201);
        $createdToken = $response->json('data.token');
        $this->assertNotNull($createdToken);

        // Fetch by-me — token should be present
        $byMeResponse = $this->actingAs($this->owner, 'api')
            ->getJson('/api/share/by-me');

        $byMeResponse->assertStatus(200);

        $guestShare = collect($byMeResponse->json('data'))
            ->firstWhere('is_guest_link', true);

        $this->assertNotNull($guestShare);
        $this->assertNotNull($guestShare['token']);
    }

    public function test_by_me_excludes_token_for_member_shares(): void
    {
        Share::query()->create([
            'file_id' => $this->file->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'view',
        ]);

        $response = $this->actingAs($this->owner, 'api')
            ->getJson('/api/share/by-me');

        $response->assertStatus(200);

        $memberShare = collect($response->json('data'))
            ->firstWhere('is_guest_link', false);

        $this->assertNotNull($memberShare);
        $this->assertNull($memberShare['token']);
    }
}
