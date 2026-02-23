<?php

namespace Tests\Feature;

use App\Jobs\DeleteR2ObjectJob;
use App\Jobs\GenerateThumbnailAndBlurhashJob;
use App\Models\File;
use App\Models\Folder;
use App\Models\Role;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\R2ClientService;
use App\Services\ThumbnailService;
use Aws\CommandInterface;
use Aws\S3\S3Client;
use Database\Seeders\RolePermissionSeeder;
use GuzzleHttp\Psr7\Uri;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

class ThumbnailTest extends TestCase
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

        $mockS3->shouldReceive('getObject')
            ->andReturn(['Body' => '']);

        $mockS3->shouldReceive('putObject')
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

    // ── Job Dispatch on Upload ────────────────────────────

    public function test_thumbnail_job_dispatched_for_jpeg_upload(): void
    {
        Bus::fake([GenerateThumbnailAndBlurhashJob::class]);

        $session = $this->createPendingSession('image/jpeg', 'photo.jpg');

        $this->actingAs($this->user, 'api')
            ->postJson('/api/upload/complete', [
                'session_id' => $session->id,
                'parts' => [['part_number' => 1, 'etag' => '"abc123"']],
            ]);

        Bus::assertDispatched(GenerateThumbnailAndBlurhashJob::class);
    }

    public function test_thumbnail_job_dispatched_for_png_upload(): void
    {
        Bus::fake([GenerateThumbnailAndBlurhashJob::class]);

        $session = $this->createPendingSession('image/png', 'chart.png');

        $this->actingAs($this->user, 'api')
            ->postJson('/api/upload/complete', [
                'session_id' => $session->id,
                'parts' => [['part_number' => 1, 'etag' => '"abc123"']],
            ]);

        Bus::assertDispatched(GenerateThumbnailAndBlurhashJob::class);
    }

    public function test_thumbnail_job_dispatched_for_webp_upload(): void
    {
        Bus::fake([GenerateThumbnailAndBlurhashJob::class]);

        $session = $this->createPendingSession('image/webp', 'photo.webp');

        $this->actingAs($this->user, 'api')
            ->postJson('/api/upload/complete', [
                'session_id' => $session->id,
                'parts' => [['part_number' => 1, 'etag' => '"abc123"']],
            ]);

        Bus::assertDispatched(GenerateThumbnailAndBlurhashJob::class);
    }

    public function test_thumbnail_job_not_dispatched_for_pdf_upload(): void
    {
        Bus::fake([GenerateThumbnailAndBlurhashJob::class]);

        $session = $this->createPendingSession('application/pdf', 'doc.pdf');

        $this->actingAs($this->user, 'api')
            ->postJson('/api/upload/complete', [
                'session_id' => $session->id,
                'parts' => [['part_number' => 1, 'etag' => '"abc123"']],
            ]);

        Bus::assertNotDispatched(GenerateThumbnailAndBlurhashJob::class);
    }

    public function test_thumbnail_job_not_dispatched_for_video_upload(): void
    {
        Bus::fake([GenerateThumbnailAndBlurhashJob::class]);

        $session = $this->createPendingSession('video/mp4', 'clip.mp4');

        $this->actingAs($this->user, 'api')
            ->postJson('/api/upload/complete', [
                'session_id' => $session->id,
                'parts' => [['part_number' => 1, 'etag' => '"abc123"']],
            ]);

        Bus::assertNotDispatched(GenerateThumbnailAndBlurhashJob::class);
    }

    // ── Job Idempotency ──────────────────────────────────

    public function test_job_skips_when_file_not_found(): void
    {
        $job = new GenerateThumbnailAndBlurhashJob('non-existent-uuid');

        $mockService = Mockery::mock(ThumbnailService::class);
        $mockService->shouldNotReceive('generateForFile');

        $job->handle($mockService);

        // No exception — job silently completes
        $this->assertTrue(true);
    }

    public function test_job_skips_when_thumbnail_already_exists(): void
    {
        $file = File::factory()->create([
            'folder_id' => $this->folder->id,
            'owner_id' => $this->user->id,
            'mime_type' => 'image/jpeg',
            'thumbnail_path' => 'thumbnails/existing/750.webp',
        ]);

        $job = new GenerateThumbnailAndBlurhashJob($file->id);

        $mockService = Mockery::mock(ThumbnailService::class);
        $mockService->shouldNotReceive('generateForFile');

        $job->handle($mockService);

        $this->assertTrue(true);
    }

    // ── Thumbnail Endpoint ───────────────────────────────

    public function test_thumbnail_endpoint_returns_presigned_url_when_thumbnail_exists(): void
    {
        $file = File::factory()->create([
            'folder_id' => $this->folder->id,
            'owner_id' => $this->user->id,
            'mime_type' => 'image/jpeg',
            'thumbnail_path' => 'thumbnails/test-id/750.webp',
            'thumbnail_width' => 750,
            'thumbnail_height' => 750,
            'blurhash' => 'LEHV6nWB2yk8pyo0adR*.7kCMdnj',
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/files/{$file->id}/thumbnail");

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['url', 'width', 'height', 'blurhash', 'mime_type']])
            ->assertJsonPath('data.width', 750)
            ->assertJsonPath('data.height', 750)
            ->assertJsonPath('data.blurhash', 'LEHV6nWB2yk8pyo0adR*.7kCMdnj')
            ->assertJsonPath('data.mime_type', 'image/webp');
    }

    public function test_thumbnail_endpoint_returns_preview_fallback_when_pending(): void
    {
        Bus::fake([GenerateThumbnailAndBlurhashJob::class]);

        $file = File::factory()->create([
            'folder_id' => $this->folder->id,
            'owner_id' => $this->user->id,
            'mime_type' => 'image/jpeg',
            'thumbnail_path' => null,
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/files/{$file->id}/thumbnail");

        $response->assertStatus(200)
            ->assertJsonPath('data.pending', true)
            ->assertJsonPath('data.mime_type', 'image/jpeg');

        // Should dispatch regeneration job on-demand
        Bus::assertDispatched(GenerateThumbnailAndBlurhashJob::class, function ($job) use ($file) {
            return $job->fileId === (string) $file->id;
        });
    }

    public function test_thumbnail_endpoint_does_not_dispatch_job_for_non_image(): void
    {
        Bus::fake([GenerateThumbnailAndBlurhashJob::class]);

        $file = File::factory()->create([
            'folder_id' => $this->folder->id,
            'owner_id' => $this->user->id,
            'mime_type' => 'application/pdf',
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/files/{$file->id}/thumbnail");

        $response->assertStatus(200)
            ->assertJsonPath('data', null);

        Bus::assertNotDispatched(GenerateThumbnailAndBlurhashJob::class);
    }


    public function test_thumbnail_endpoint_requires_auth(): void
    {
        $file = File::factory()->create([
            'folder_id' => $this->folder->id,
            'owner_id' => $this->user->id,
        ]);

        $response = $this->getJson("/api/files/{$file->id}/thumbnail");

        $response->assertUnauthorized();
    }

    public function test_thumbnail_endpoint_respects_authorization(): void
    {
        $otherUser = User::factory()->create();
        $otherUser->roles()->attach(Role::query()->where('slug', 'user')->first());

        $file = File::factory()->create([
            'folder_id' => $this->folder->id,
            'owner_id' => $this->user->id,
        ]);

        $response = $this->actingAs($otherUser, 'api')
            ->getJson("/api/files/{$file->id}/thumbnail");

        $response->assertForbidden();
    }

    // ── FileResource includes thumbnail fields ───────────

    public function test_file_resource_includes_thumbnail_fields(): void
    {
        $file = File::factory()->create([
            'folder_id' => $this->folder->id,
            'owner_id' => $this->user->id,
            'thumbnail_width' => 750,
            'thumbnail_height' => 750,
            'blurhash' => 'LEHV6nWB2yk8pyo0adR*.7kCMdnj',
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/files/{$file->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.thumbnail_width', 750)
            ->assertJsonPath('data.thumbnail_height', 750)
            ->assertJsonPath('data.blurhash', 'LEHV6nWB2yk8pyo0adR*.7kCMdnj');
    }

    public function test_file_resource_includes_null_thumbnail_fields_when_not_set(): void
    {
        $file = File::factory()->create([
            'folder_id' => $this->folder->id,
            'owner_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/files/{$file->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.thumbnail_width', null)
            ->assertJsonPath('data.thumbnail_height', null)
            ->assertJsonPath('data.blurhash', null);
    }

    // ── Cleanup on Delete ────────────────────────────────

    public function test_force_delete_dispatches_thumbnail_cleanup(): void
    {
        Bus::fake([DeleteR2ObjectJob::class]);

        $file = File::factory()->create([
            'folder_id' => $this->folder->id,
            'owner_id' => $this->user->id,
            'mime_type' => 'image/jpeg',
            'thumbnail_path' => 'thumbnails/test-id/750.webp',
            'deleted_at' => now(),
            'purge_at' => now()->addDays(30),
        ]);

        $this->actingAs($this->user, 'api')
            ->deleteJson("/api/trash/files/{$file->id}");

        // Should dispatch for both original and thumbnail
        Bus::assertDispatched(DeleteR2ObjectJob::class, function ($job) use ($file) {
            return $job->objectKey === $file->r2_object_key;
        });

        Bus::assertDispatched(DeleteR2ObjectJob::class, function ($job) {
            return $job->objectKey === 'thumbnails/test-id/750.webp';
        });
    }

    public function test_force_delete_without_thumbnail_does_not_dispatch_extra_cleanup(): void
    {
        Bus::fake([DeleteR2ObjectJob::class]);

        $file = File::factory()->create([
            'folder_id' => $this->folder->id,
            'owner_id' => $this->user->id,
            'mime_type' => 'application/pdf',
            'thumbnail_path' => null,
            'deleted_at' => now(),
            'purge_at' => now()->addDays(30),
        ]);

        $this->actingAs($this->user, 'api')
            ->deleteJson("/api/trash/files/{$file->id}");

        // Should dispatch only for original
        Bus::assertDispatched(DeleteR2ObjectJob::class, 1);
    }

    // ── Service Unit Tests ───────────────────────────────

    public function test_thumbnail_service_supports_correct_mime_types(): void
    {
        $service = app(ThumbnailService::class);

        $this->assertTrue($service->supportsThumbnail('image/jpeg'));
        $this->assertTrue($service->supportsThumbnail('image/png'));
        $this->assertTrue($service->supportsThumbnail('image/webp'));
        $this->assertFalse($service->supportsThumbnail('image/svg+xml'));
        $this->assertFalse($service->supportsThumbnail('image/gif'));
        $this->assertFalse($service->supportsThumbnail('video/mp4'));
        $this->assertFalse($service->supportsThumbnail('application/pdf'));
    }

    // ── Helpers ───────────────────────────────────────────

    private function createPendingSession(string $mimeType, string $filename): UploadSession
    {
        return UploadSession::query()->create([
            'user_id' => $this->user->id,
            'folder_id' => $this->folder->id,
            'filename' => $filename,
            'mime_type' => $mimeType,
            'size_bytes' => 1024,
            'r2_object_key' => $this->user->id.'/'.fake()->uuid().'/'.$filename,
            'r2_upload_id' => 'mock-upload-id-123',
            'status' => 'pending',
            'expires_at' => now()->addHours(24),
        ]);
    }
}
