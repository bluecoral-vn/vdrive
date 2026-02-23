<?php

namespace Tests\Integration;

use App\Models\File;
use App\Models\Folder;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Integration — Quota Enforcement Tests.
 */
class QuotaEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Folder $folder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->mockR2();

        $userRole = Role::query()->where('slug', 'user')->firstOrFail();

        $this->user = User::factory()->create([
            'quota_used_bytes' => 0,
            'quota_limit_bytes' => 10485760, // 10 MB
        ]);
        $this->user->roles()->attach($userRole);

        $this->folder = Folder::factory()->create(['owner_id' => $this->user->id]);
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

    // ── Upload Blocked at Quota ──────────────────────────

    public function test_upload_blocked_when_quota_exceeded(): void
    {
        // Set quota usage to near limit
        $this->user->update(['quota_used_bytes' => 10485760]); // exactly at limit

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/upload/init', [
                'filename' => 'big-file.bin',
                'mime_type' => 'application/octet-stream',
                'size_bytes' => 1024, // any non-zero
                'folder_id' => $this->folder->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'Storage quota exceeded.']);
    }

    public function test_upload_allowed_within_quota(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/upload/init', [
                'filename' => 'small-file.txt',
                'mime_type' => 'text/plain',
                'size_bytes' => 1024,
                'folder_id' => $this->folder->id,
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['data' => ['session_id']]);
    }

    // ── Force Delete Frees Quota ─────────────────────────

    public function test_force_delete_frees_quota(): void
    {
        $file = File::factory()->create([
            'folder_id' => $this->folder->id,
            'owner_id' => $this->user->id,
            'size_bytes' => 2097152, // 2 MB
        ]);

        $this->user->update(['quota_used_bytes' => 2097152]);

        $this->actingAs($this->user, 'api')
            ->deleteJson("/api/trash/files/{$file->id}")
            ->assertStatus(204);

        $this->user->refresh();
        $this->assertEquals(0, $this->user->quota_used_bytes);
    }

    // ── Soft Delete Does NOT Free Quota ───────────────────

    public function test_soft_delete_does_not_free_quota(): void
    {
        $file = File::factory()->create([
            'folder_id' => $this->folder->id,
            'owner_id' => $this->user->id,
            'size_bytes' => 2097152,
        ]);

        $this->user->update(['quota_used_bytes' => 2097152]);

        $this->actingAs($this->user, 'api')
            ->deleteJson("/api/files/{$file->id}")
            ->assertStatus(204);

        $this->user->refresh();
        $this->assertEquals(2097152, $this->user->quota_used_bytes, 'Soft delete must not free quota');
    }

    // ── Quota Endpoint ───────────────────────────────────

    public function test_quota_endpoint_returns_correct_values(): void
    {
        $this->user->update(['quota_used_bytes' => 5242880]); // 5 MB

        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/me/quota');

        $response->assertStatus(200)
            ->assertJsonPath('data.used_bytes', 5242880)
            ->assertJsonPath('data.limit_bytes', 10485760);
    }
}
