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
 * Phase 6 – Sharing, Quota & Access tests.
 */
class ShareQuotaAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $recipient;

    private User $stranger;

    private User $admin;

    private Folder $folder;

    private File $file;

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

        $this->folder = Folder::factory()->create(['owner_id' => $this->owner->id]);
        $this->file = File::factory()->create([
            'folder_id' => $this->folder->id,
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
    // SHARING — CREATE
    // ═══════════════════════════════════════════════════════

    /** Owner creates a user-to-user share */
    public function test_owner_can_share_with_user(): void
    {
        $response = $this->actingAs($this->owner, 'api')
            ->postJson('/api/share', [
                'file_id' => $this->file->id,
                'shared_with' => $this->recipient->id,
                'permission' => 'view',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.shared_with.id', $this->recipient->id)
            ->assertJsonPath('data.permission', 'view')
            ->assertJsonPath('data.is_guest_link', false);

        // No token for user-to-user share
        $this->assertNull($response->json('data.token'));
    }

    /** Owner creates a guest link — token returned once */
    public function test_owner_can_create_guest_link(): void
    {
        $response = $this->actingAs($this->owner, 'api')
            ->postJson('/api/share', [
                'file_id' => $this->file->id,
                'permission' => 'view',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.is_guest_link', true)
            ->assertJsonPath('data.permission', 'view');

        // Token returned on creation
        $token = $response->json('data.token');
        $this->assertNotNull($token);
        $this->assertEquals(64, strlen($token));

        // Token is hashed in DB (not plaintext)
        $share = Share::query()->latest()->first();
        $this->assertNotEquals($token, $share->token_hash);
        $this->assertEquals(hash('sha256', $token), $share->token_hash);
    }

    /** Creating guest link twice for same file returns same share, not a duplicate */
    public function test_creating_guest_link_twice_returns_same_share(): void
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

        // Same share reused
        $this->assertEquals($shareId1, $shareId2);

        // Only 1 record in DB for this file
        $count = Share::query()
            ->where('file_id', $this->file->id)
            ->whereNull('shared_with')
            ->count();
        $this->assertEquals(1, $count);

        // New token is still returned and valid
        $token2 = $response2->json('data.token');
        $this->assertNotNull($token2);
        $this->assertEquals(64, strlen($token2));
    }

    /** Creating user-to-user share twice returns same share */
    public function test_creating_user_share_twice_returns_same_share(): void
    {
        $response1 = $this->actingAs($this->owner, 'api')
            ->postJson('/api/share', [
                'file_id' => $this->file->id,
                'shared_with' => $this->recipient->id,
                'permission' => 'view',
            ]);

        $response1->assertStatus(201);

        $response2 = $this->actingAs($this->owner, 'api')
            ->postJson('/api/share', [
                'file_id' => $this->file->id,
                'shared_with' => $this->recipient->id,
                'permission' => 'edit',
            ]);

        $response2->assertStatus(201);

        // Same share reused, permission updated
        $this->assertEquals($response1->json('data.id'), $response2->json('data.id'));
        $this->assertEquals('edit', $response2->json('data.permission'));

        $count = Share::query()
            ->where('file_id', $this->file->id)
            ->where('shared_with', $this->recipient->id)
            ->count();
        $this->assertEquals(1, $count);
    }

    /** Non-owner cannot share */
    public function test_non_owner_cannot_share(): void
    {
        $response = $this->actingAs($this->stranger, 'api')
            ->postJson('/api/share', [
                'file_id' => $this->file->id,
                'shared_with' => $this->recipient->id,
                'permission' => 'view',
            ]);

        $response->assertStatus(403);
    }

    /** Share with expiry */
    public function test_share_with_expiry(): void
    {
        $expiresAt = now()->addDays(7)->toIso8601String();

        $response = $this->actingAs($this->owner, 'api')
            ->postJson('/api/share', [
                'file_id' => $this->file->id,
                'shared_with' => $this->recipient->id,
                'permission' => 'view',
                'expires_at' => $expiresAt,
            ]);

        $response->assertStatus(201);
        $this->assertNotNull($response->json('data.expires_at'));
    }

    // ═══════════════════════════════════════════════════════
    // SHARING — GUEST TOKEN ACCESS
    // ═══════════════════════════════════════════════════════

    /** Guest can access file via valid token */
    public function test_guest_can_access_via_token(): void
    {
        // Create guest link
        $createResponse = $this->actingAs($this->owner, 'api')
            ->postJson('/api/share', [
                'file_id' => $this->file->id,
                'permission' => 'view',
            ]);

        $token = $createResponse->json('data.token');

        // Access without auth
        $response = $this->getJson("/api/share/{$token}");

        $response->assertStatus(200)
            ->assertJsonPath('data.permission', 'view')
            ->assertJsonStructure(['data' => ['file' => ['id', 'name']]]);
    }

    /** Invalid token returns 404 */
    public function test_invalid_token_returns_404(): void
    {
        $response = $this->getJson('/api/share/invalid-token-xxx');

        $response->assertStatus(404);
    }

    /** Expired guest link returns 410 */
    public function test_expired_guest_link_returns_410(): void
    {
        $rawToken = Str::random(64);

        Share::query()->create([
            'file_id' => $this->file->id,
            'shared_by' => $this->owner->id,
            'shared_with' => null,
            'token_hash' => hash('sha256', $rawToken),
            'permission' => 'view',
            'expires_at' => now()->subHour(),
        ]);

        $response = $this->getJson("/api/share/{$rawToken}");

        $response->assertStatus(410)
            ->assertJsonPath('message', 'Share link has expired.');
    }

    // ═══════════════════════════════════════════════════════
    // SHARING — SHARED WITH ME
    // ═══════════════════════════════════════════════════════

    /** Recipient can list files shared with them */
    public function test_shared_with_me_list(): void
    {
        // Share 3 files
        for ($i = 0; $i < 3; $i++) {
            $file = File::factory()->create(['owner_id' => $this->owner->id]);
            Share::query()->create([
                'file_id' => $file->id,
                'shared_by' => $this->owner->id,
                'shared_with' => $this->recipient->id,
                'permission' => 'view',
            ]);
        }

        $response = $this->actingAs($this->recipient, 'api')
            ->getJson('/api/share/with-me');

        $response->assertStatus(200);
        $this->assertCount(3, $response->json('data'));
    }

    /** Stranger sees empty shared-with-me */
    public function test_stranger_shared_with_me_empty(): void
    {
        $response = $this->actingAs($this->stranger, 'api')
            ->getJson('/api/share/with-me');

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data'));
    }

    // ═══════════════════════════════════════════════════════
    // PERMISSION RESOLUTION (SKILL 4)
    // ═══════════════════════════════════════════════════════

    /** Owner can view own file */
    public function test_perm_owner_can_view(): void
    {
        $response = $this->actingAs($this->owner, 'api')
            ->getJson("/api/files/{$this->file->id}");

        $response->assertStatus(200);
    }

    /** Admin can view any file (system permission) */
    public function test_perm_admin_can_view(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->getJson("/api/files/{$this->file->id}");

        $response->assertStatus(200);
    }

    /** Shared recipient can view file */
    public function test_perm_shared_user_can_view(): void
    {
        Share::query()->create([
            'file_id' => $this->file->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'view',
        ]);

        $response = $this->actingAs($this->recipient, 'api')
            ->getJson("/api/files/{$this->file->id}");

        $response->assertStatus(200);
    }

    /** Stranger cannot view file (no permission) */
    public function test_perm_stranger_cannot_view(): void
    {
        $response = $this->actingAs($this->stranger, 'api')
            ->getJson("/api/files/{$this->file->id}");

        $response->assertStatus(403);
    }

    /** Shared with view can download (view includes download) */
    public function test_perm_view_share_can_download(): void
    {
        Share::query()->create([
            'file_id' => $this->file->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'view',
        ]);

        $response = $this->actingAs($this->recipient, 'api')
            ->getJson("/api/files/{$this->file->id}/download");

        $response->assertStatus(200);
    }

    /** Shared with edit permission can download */
    public function test_perm_edit_share_can_download(): void
    {
        Share::query()->create([
            'file_id' => $this->file->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'edit',
        ]);

        $response = $this->actingAs($this->recipient, 'api')
            ->getJson("/api/files/{$this->file->id}/download");

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['url', 'filename', 'expires_in']]);
    }

    /** Expired share does not grant access */
    public function test_perm_expired_share_denied(): void
    {
        Share::query()->create([
            'file_id' => $this->file->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'view',
            'expires_at' => now()->subHour(),
        ]);

        $response = $this->actingAs($this->recipient, 'api')
            ->getJson("/api/files/{$this->file->id}");

        $response->assertStatus(403);
    }

    // ═══════════════════════════════════════════════════════
    // QUOTA
    // ═══════════════════════════════════════════════════════

    /** Get quota — unlimited (no limit set) */
    public function test_quota_unlimited(): void
    {
        $response = $this->actingAs($this->owner, 'api')
            ->getJson('/api/me/quota');

        $response->assertStatus(200)
            ->assertJsonPath('data.limit_bytes', null)
            ->assertJsonPath('data.used_bytes', 0)
            ->assertJsonPath('data.remaining_bytes', null);
    }

    /** Get quota — with limit */
    public function test_quota_with_limit(): void
    {
        $this->owner->update([
            'quota_limit_bytes' => 104857600, // 100MB
            'quota_used_bytes' => 52428800, // 50MB
        ]);

        $response = $this->actingAs($this->owner, 'api')
            ->getJson('/api/me/quota');

        $response->assertStatus(200)
            ->assertJsonPath('data.limit_bytes', 104857600)
            ->assertJsonPath('data.used_bytes', 52428800)
            ->assertJsonPath('data.remaining_bytes', 52428800);
    }

    /** Upload complete increments quota used */
    public function test_quota_incremented_on_upload_complete(): void
    {
        $this->owner->update(['quota_used_bytes' => 0]);

        $session = \App\Models\UploadSession::query()->create([
            'user_id' => $this->owner->id,
            'folder_id' => $this->folder->id,
            'filename' => 'quota-test.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 5242880,
            'r2_object_key' => $this->owner->id.'/'.Str::uuid().'/quota-test.pdf',
            'r2_upload_id' => 'mock-upload-id',
            'status' => 'pending',
            'expires_at' => now()->addHours(24),
        ]);

        $response = $this->actingAs($this->owner, 'api')
            ->postJson('/api/upload/complete', [
                'session_id' => $session->id,
                'parts' => [['part_number' => 1, 'etag' => '"abc"']],
            ]);

        $response->assertStatus(201);

        $this->owner->refresh();
        $this->assertEquals(5242880, $this->owner->quota_used_bytes);
    }

    /** Force delete decrements quota used (soft delete does NOT) */
    public function test_quota_decremented_on_force_delete(): void
    {
        $this->owner->update(['quota_used_bytes' => 10485760]);

        $file = File::factory()->create([
            'owner_id' => $this->owner->id,
            'size_bytes' => 5242880,
        ]);

        // Force delete via trash endpoint
        $response = $this->actingAs($this->owner, 'api')
            ->deleteJson("/api/trash/files/{$file->id}");

        $response->assertStatus(204);

        $this->owner->refresh();
        $this->assertEquals(5242880, $this->owner->quota_used_bytes);
    }

    /** Upload init rejected when quota exceeded */
    public function test_quota_exceeded_rejects_upload(): void
    {
        $this->owner->update([
            'quota_limit_bytes' => 10485760,
            'quota_used_bytes' => 10000000,
        ]);

        $response = $this->actingAs($this->owner, 'api')
            ->postJson('/api/upload/init', [
                'filename' => 'too-big.zip',
                'mime_type' => 'application/zip',
                'size_bytes' => 1000000,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Storage quota exceeded.');
    }

    // ═══════════════════════════════════════════════════════
    // FILE ACCESS — DOWNLOAD / PREVIEW
    // ═══════════════════════════════════════════════════════

    /** Owner can download own file */
    public function test_owner_can_download(): void
    {
        $response = $this->actingAs($this->owner, 'api')
            ->getJson("/api/files/{$this->file->id}/download");

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['url', 'filename', 'expires_in']])
            ->assertJsonPath('data.expires_in', 3600);
    }

    /** Owner can preview own file */
    public function test_owner_can_preview(): void
    {
        $response = $this->actingAs($this->owner, 'api')
            ->getJson("/api/files/{$this->file->id}/preview");

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['url', 'mime_type', 'expires_in']]);
    }

    /** Unauthenticated cannot download */
    public function test_unauthenticated_cannot_download(): void
    {
        $response = $this->getJson("/api/files/{$this->file->id}/download");

        $response->assertUnauthorized();
    }

    /** Stranger cannot download */
    public function test_stranger_cannot_download(): void
    {
        $response = $this->actingAs($this->stranger, 'api')
            ->getJson("/api/files/{$this->file->id}/download");

        $response->assertStatus(403);
    }
}
