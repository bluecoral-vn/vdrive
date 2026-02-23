<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\Folder;
use App\Models\Role;
use App\Models\UploadSession;
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

class UploadTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Folder $folder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $userRole = Role::query()->where('slug', 'user')->firstOrFail();
        $this->user = User::factory()->create();
        $this->user->roles()->attach($userRole);

        $this->folder = Folder::factory()->create(['owner_id' => $this->user->id]);

        // Mock R2ClientService to avoid real S3 calls
        $this->mockR2Client();
    }

    private function mockR2Client(): void
    {
        $mockS3 = Mockery::mock(S3Client::class);

        $mockS3->shouldReceive('createMultipartUpload')
            ->andReturn(['UploadId' => 'mock-upload-id-123']);

        $mockS3->shouldReceive('getCommand')
            ->andReturn(Mockery::mock(CommandInterface::class));

        $mockRequest = Mockery::mock(RequestInterface::class);
        $mockRequest->shouldReceive('getUri')
            ->andReturn(new Uri('https://mock-presigned-url.example.com/part'));

        $mockS3->shouldReceive('createPresignedRequest')
            ->andReturn($mockRequest);

        $mockS3->shouldReceive('completeMultipartUpload')
            ->andReturn([]);

        $this->instance(R2ClientService::class, new class($mockS3) extends R2ClientService
        {
            public function __construct(private S3Client $mock)
            {
                // Skip parent constructor
            }

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

    // ── Init ──────────────────────────────────────────────

    public function test_user_can_init_upload(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/upload/init', [
                'filename' => 'photo.jpg',
                'mime_type' => 'image/jpeg',
                'size_bytes' => 1048576,
                'folder_id' => $this->folder->uuid,
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['data' => ['session_id', 'r2_object_key', 'expires_at']]);

        $this->assertDatabaseHas('upload_sessions', [
            'filename' => 'photo.jpg',
            'status' => 'pending',
            'user_id' => $this->user->id,
        ]);
    }

    public function test_init_upload_validates_required_fields(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/upload/init', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['filename', 'mime_type', 'size_bytes']);
    }

    public function test_init_upload_validates_folder_exists(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/upload/init', [
                'filename' => 'doc.pdf',
                'mime_type' => 'application/pdf',
                'size_bytes' => 500,
                'folder_id' => 9999,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['folder_id']);
    }

    public function test_init_upload_without_folder(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/upload/init', [
                'filename' => 'readme.txt',
                'mime_type' => 'text/plain',
                'size_bytes' => 100,
            ]);

        $response->assertStatus(201);

        $sessionId = $response->json('data.session_id');
        $session = UploadSession::find($sessionId);
        $this->assertNull($session->folder_id);
    }

    // ── Quota ─────────────────────────────────────────────

    public function test_init_upload_fails_when_quota_exceeded(): void
    {
        // Set a 1MB per-user quota
        $this->user->update([
            'quota_limit_bytes' => 1048576,
            'quota_used_bytes' => 921600, // 900KB used
        ]);

        // Try to upload 200KB more (total would exceed 1MB)
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/upload/init', [
                'filename' => 'too-large.zip',
                'mime_type' => 'application/zip',
                'size_bytes' => 204800,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Storage quota exceeded.');
    }

    public function test_init_upload_succeeds_without_quota_config(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/upload/init', [
                'filename' => 'big.zip',
                'mime_type' => 'application/zip',
                'size_bytes' => 999999999,
            ]);

        $response->assertStatus(201);
    }

    // ── Presign Part ──────────────────────────────────────

    public function test_user_can_presign_part(): void
    {
        $session = $this->createPendingSession();

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/upload/presign-part', [
                'session_id' => $session->id,
                'part_number' => 1,
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['url', 'part_number']])
            ->assertJsonPath('data.part_number', 1);
    }

    public function test_presign_part_rejects_other_user(): void
    {
        $otherUser = User::factory()->create();
        $otherUser->roles()->attach(Role::query()->where('slug', 'user')->first());

        $session = $this->createPendingSession();

        $response = $this->actingAs($otherUser, 'api')
            ->postJson('/api/upload/presign-part', [
                'session_id' => $session->id,
                'part_number' => 1,
            ]);

        $response->assertStatus(403);
    }

    public function test_presign_part_rejects_completed_session(): void
    {
        $session = $this->createPendingSession();
        $session->update(['status' => 'completed']);

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/upload/presign-part', [
                'session_id' => $session->id,
                'part_number' => 1,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Upload session is no longer active.');
    }

    // ── Complete ──────────────────────────────────────────

    public function test_user_can_complete_upload(): void
    {
        $session = $this->createPendingSession();

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/upload/complete', [
                'session_id' => $session->id,
                'parts' => [
                    ['part_number' => 1, 'etag' => '"abc123"'],
                ],
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['data' => ['id', 'name']]);

        // Session updated
        $session->refresh();
        $this->assertEquals('completed', $session->status);
        $this->assertEquals(1, $session->total_parts);

        // File created
        $this->assertDatabaseHas('files', [
            'name' => $session->filename,
            'r2_object_key' => $session->r2_object_key,
            'owner_id' => $this->user->id,
        ]);
    }

    public function test_complete_rejects_other_user(): void
    {
        $otherUser = User::factory()->create();
        $otherUser->roles()->attach(Role::query()->where('slug', 'user')->first());

        $session = $this->createPendingSession();

        $response = $this->actingAs($otherUser, 'api')
            ->postJson('/api/upload/complete', [
                'session_id' => $session->id,
                'parts' => [['part_number' => 1, 'etag' => '"abc"']],
            ]);

        $response->assertStatus(403);
    }

    // ── Object Key ────────────────────────────────────────

    public function test_object_key_is_deterministic_format(): void
    {
        $session = $this->createPendingSession();

        // Format: {user_id}/{uuid}/{filename}
        $parts = explode('/', $session->r2_object_key);
        $this->assertCount(3, $parts);
        $this->assertEquals($this->user->id, $parts[0]);
        $this->assertEquals('test.pdf', $parts[2]);
    }

    // ── Auth ──────────────────────────────────────────────

    public function test_unauthenticated_cannot_init_upload(): void
    {
        $response = $this->postJson('/api/upload/init', [
            'filename' => 'test.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 100,
        ]);

        $response->assertUnauthorized();
    }

    // ── Helpers ───────────────────────────────────────────

    private function createPendingSession(): UploadSession
    {
        return UploadSession::query()->create([
            'user_id' => $this->user->id,
            'folder_id' => $this->folder->id,
            'filename' => 'test.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'r2_object_key' => $this->user->id.'/'.fake()->uuid().'/test.pdf',
            'r2_upload_id' => 'mock-upload-id-123',
            'status' => 'pending',
            'expires_at' => now()->addHours(24),
        ]);
    }
}
