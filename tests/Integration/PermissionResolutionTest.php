<?php

namespace Tests\Integration;

use App\Models\File;
use App\Models\Folder;
use App\Models\Role;
use App\Models\Share;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Integration — Permission Resolution Tests.
 */
class PermissionResolutionTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $viewer;

    private User $stranger;

    private User $admin;

    private Folder $rootFolder;

    private Folder $childFolder;

    private Folder $grandchildFolder;

    private File $rootFile;

    private File $childFile;

    private File $grandchildFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->mockR2();

        $adminRole = Role::query()->where('slug', 'admin')->firstOrFail();
        $userRole = Role::query()->where('slug', 'user')->firstOrFail();

        $this->admin = User::factory()->create();
        $this->admin->roles()->attach($adminRole);

        $this->owner = User::factory()->create();
        $this->owner->roles()->attach($userRole);

        $this->viewer = User::factory()->create();
        $this->viewer->roles()->attach($userRole);

        $this->stranger = User::factory()->create();
        $this->stranger->roles()->attach($userRole);

        // Folder hierarchy: root > child > grandchild
        $this->rootFolder = Folder::factory()->create(['owner_id' => $this->owner->id]);
        $this->childFolder = Folder::factory()->create([
            'owner_id' => $this->owner->id,
            'parent_id' => $this->rootFolder->id,
        ]);
        $this->grandchildFolder = Folder::factory()->create([
            'owner_id' => $this->owner->id,
            'parent_id' => $this->childFolder->id,
        ]);

        // Files at each level
        $this->rootFile = File::factory()->create([
            'folder_id' => $this->rootFolder->id,
            'owner_id' => $this->owner->id,
        ]);
        $this->childFile = File::factory()->create([
            'folder_id' => $this->childFolder->id,
            'owner_id' => $this->owner->id,
        ]);
        $this->grandchildFile = File::factory()->create([
            'folder_id' => $this->grandchildFolder->id,
            'owner_id' => $this->owner->id,
        ]);
    }

    private function mockR2(): void
    {
        $mockS3 = \Mockery::mock(\Aws\S3\S3Client::class);
        $mockS3->shouldReceive('createMultipartUpload')->andReturn(['UploadId' => 'mock'])->byDefault();
        $mockS3->shouldReceive('getCommand')->andReturn(\Mockery::mock(\Aws\CommandInterface::class))->byDefault();
        $mockRequest = \Mockery::mock(\Psr\Http\Message\RequestInterface::class);
        $mockRequest->shouldReceive('getUri')->andReturn(new \GuzzleHttp\Psr7\Uri('https://r2.example.com'))->byDefault();
        $mockS3->shouldReceive('createPresignedRequest')->andReturn($mockRequest)->byDefault();
        $mockS3->shouldReceive('completeMultipartUpload')->andReturn([])->byDefault();
        $mockS3->shouldReceive('abortMultipartUpload')->andReturn([])->byDefault();
        $mockS3->shouldReceive('deleteObject')->andReturn([])->byDefault();

        $this->instance(\App\Services\R2ClientService::class, new class($mockS3) extends \App\Services\R2ClientService
        {
            public function __construct(private \Aws\S3\S3Client $mock) {}

            public function client(): \Aws\S3\S3Client
            {
                return $this->mock;
            }

            public function bucket(): string
            {
                return 'test-bucket';
            }
        });
    }

    // ── Owner Access ─────────────────────────────────────

    public function test_owner_can_view_own_file(): void
    {
        $this->actingAs($this->owner, 'api')
            ->getJson("/api/files/{$this->rootFile->id}")
            ->assertStatus(200);
    }

    public function test_owner_can_view_own_folder(): void
    {
        $this->actingAs($this->owner, 'api')
            ->getJson("/api/folders/{$this->rootFolder->id}")
            ->assertStatus(200);
    }

    // ── Stranger Cannot Access ───────────────────────────

    public function test_stranger_cannot_view_unshared_file(): void
    {
        $this->actingAs($this->stranger, 'api')
            ->getJson("/api/files/{$this->rootFile->id}")
            ->assertStatus(403);
    }

    public function test_stranger_cannot_view_unshared_folder(): void
    {
        $this->actingAs($this->stranger, 'api')
            ->getJson("/api/folders/{$this->rootFolder->id}")
            ->assertStatus(403);
    }

    // ── Direct File Share ────────────────────────────────

    public function test_direct_file_share_grants_view(): void
    {
        // Owner shares file directly with viewer
        $this->actingAs($this->owner, 'api')
            ->postJson('/api/share', [
                'file_id' => $this->rootFile->id,
                'shared_with' => $this->viewer->id,
                'permission' => 'view',
            ])
            ->assertStatus(201);

        // Viewer can now see the file
        $this->actingAs($this->viewer, 'api')
            ->getJson("/api/files/{$this->rootFile->id}")
            ->assertStatus(200);
    }

    public function test_direct_file_share_does_not_grant_sibling_access(): void
    {
        // Share rootFile with viewer
        $this->actingAs($this->owner, 'api')
            ->postJson('/api/share', [
                'file_id' => $this->rootFile->id,
                'shared_with' => $this->viewer->id,
                'permission' => 'view',
            ]);

        // Viewer cannot see childFile (not shared)
        $this->actingAs($this->viewer, 'api')
            ->getJson("/api/files/{$this->childFile->id}")
            ->assertStatus(403);
    }

    // ── Folder Share Inheritance ─────────────────────────

    public function test_folder_share_grants_access_to_child_files(): void
    {
        // Share rootFolder with viewer
        $this->actingAs($this->owner, 'api')
            ->postJson('/api/share', [
                'folder_id' => $this->rootFolder->id,
                'shared_with' => $this->viewer->id,
                'permission' => 'view',
            ])
            ->assertStatus(201);

        // Viewer can access files in child and grandchild folders
        $this->actingAs($this->viewer, 'api')
            ->getJson("/api/files/{$this->childFile->id}")
            ->assertStatus(200);

        $this->actingAs($this->viewer, 'api')
            ->getJson("/api/files/{$this->grandchildFile->id}")
            ->assertStatus(200);
    }

    public function test_folder_share_grants_access_to_descendant_folders(): void
    {
        $this->actingAs($this->owner, 'api')
            ->postJson('/api/share', [
                'folder_id' => $this->rootFolder->id,
                'shared_with' => $this->viewer->id,
                'permission' => 'view',
            ]);

        $this->actingAs($this->viewer, 'api')
            ->getJson("/api/folders/{$this->childFolder->id}")
            ->assertStatus(200);

        $this->actingAs($this->viewer, 'api')
            ->getJson("/api/folders/{$this->grandchildFolder->id}")
            ->assertStatus(200);
    }

    public function test_mid_level_share_does_not_grant_parent_access(): void
    {
        // Share childFolder (not root)
        $this->actingAs($this->owner, 'api')
            ->postJson('/api/share', [
                'folder_id' => $this->childFolder->id,
                'shared_with' => $this->viewer->id,
                'permission' => 'view',
            ]);

        // Viewer can access grandchild (below shared folder)
        $this->actingAs($this->viewer, 'api')
            ->getJson("/api/files/{$this->grandchildFile->id}")
            ->assertStatus(200);

        // But NOT root (above shared folder)
        $this->actingAs($this->viewer, 'api')
            ->getJson("/api/files/{$this->rootFile->id}")
            ->assertStatus(403);
    }

    // ── Guest Token ──────────────────────────────────────

    public function test_guest_token_grants_access(): void
    {
        $response = $this->actingAs($this->owner, 'api')
            ->postJson('/api/share', [
                'file_id' => $this->rootFile->id,
                'permission' => 'view',
            ]);

        $response->assertStatus(201);
        $token = $response->json('data.token');
        $this->assertNotNull($token);

        // Access via guest token (no auth)
        $this->getJson("/api/share/{$token}")
            ->assertStatus(200);
    }

    public function test_expired_guest_token_returns_410(): void
    {
        // Create share with valid future expiry (validation rejects past dates)
        $response = $this->actingAs($this->owner, 'api')
            ->postJson('/api/share', [
                'file_id' => $this->rootFile->id,
                'permission' => 'view',
                'expires_at' => now()->addDay()->toIso8601String(),
            ]);

        $response->assertStatus(201);
        $token = $response->json('data.token');

        // Now expire it via DB
        $share = \App\Models\Share::query()->latest()->first();
        $share->update(['expires_at' => now()->subDay()]);

        $this->getJson("/api/share/{$token}")
            ->assertStatus(410);
    }

    // ── Revocation ───────────────────────────────────────

    public function test_revocation_removes_access(): void
    {
        // Share
        $shareResponse = $this->actingAs($this->owner, 'api')
            ->postJson('/api/share', [
                'file_id' => $this->rootFile->id,
                'shared_with' => $this->viewer->id,
                'permission' => 'view',
            ]);

        $shareId = $shareResponse->json('data.id');

        // Verify access
        $this->actingAs($this->viewer, 'api')
            ->getJson("/api/files/{$this->rootFile->id}")
            ->assertStatus(200);

        // Revoke
        $this->actingAs($this->owner, 'api')
            ->deleteJson("/api/share/{$shareId}")
            ->assertStatus(204);

        // Access denied
        $this->actingAs($this->viewer, 'api')
            ->getJson("/api/files/{$this->rootFile->id}")
            ->assertStatus(403);
    }

    // ── Soft-deleted parent blocks access ────────────────

    public function test_soft_deleted_parent_blocks_child_restore(): void
    {
        // Trash the root folder (cascades)
        $this->actingAs($this->owner, 'api')
            ->deleteJson("/api/folders/{$this->rootFolder->id}")
            ->assertStatus(204);

        // Try restoring a child file — should be blocked
        $this->actingAs($this->owner, 'api')
            ->postJson("/api/trash/files/{$this->childFile->id}/restore")
            ->assertStatus(422);
    }

    // ── Admin Bypass ────────────────────────────────────

    public function test_admin_can_view_any_file(): void
    {
        $this->actingAs($this->admin, 'api')
            ->getJson("/api/files/{$this->rootFile->id}")
            ->assertStatus(200);
    }

    public function test_admin_can_view_any_folder(): void
    {
        $this->actingAs($this->admin, 'api')
            ->getJson("/api/folders/{$this->rootFolder->id}")
            ->assertStatus(200);
    }
}
