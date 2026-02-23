<?php

namespace Tests\Feature;

use App\Jobs\ExtractExifJob;
use App\Models\File;
use App\Models\Folder;
use App\Models\User;
use App\Services\R2ClientService;
use Aws\CommandInterface;
use Aws\S3\S3Client;
use GuzzleHttp\Psr7\Uri;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

class FileContentTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private User $otherUser;

    private Folder $folder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->user = User::factory()->create();
        $this->user->roles()->attach(
            \App\Models\Role::where('name', 'member')->first()
        );

        $this->otherUser = User::factory()->create();
        $this->otherUser->roles()->attach(
            \App\Models\Role::where('name', 'member')->first()
        );

        $this->folder = Folder::factory()->create([
            'owner_id' => $this->user->id,
            'parent_id' => null,
            'path' => '/',
        ]);

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

        // Mock getObject for text content retrieval
        $mockS3->shouldReceive('getObject')
            ->andReturn([
                'Body' => 'Hello, this is test content!',
            ])
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

    // ═══════════════════════════════════════════════════════
    // PREVIEW ENDPOINT
    // ═══════════════════════════════════════════════════════

    public function test_preview_returns_size_bytes(): void
    {
        $file = File::factory()->create([
            'folder_id' => $this->folder->id,
            'owner_id' => $this->user->id,
            'mime_type' => 'image/jpeg',
            'size_bytes' => 2048,
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/files/{$file->id}/preview");

        $response->assertStatus(200)
            ->assertJsonPath('data.size_bytes', 2048)
            ->assertJsonPath('data.expires_in', 600)
            ->assertJsonPath('data.mime_type', 'image/jpeg')
            ->assertJsonStructure(['data' => ['url', 'mime_type', 'size_bytes', 'expires_in']]);
    }

    // ═══════════════════════════════════════════════════════
    // TEXT CONTENT ENDPOINT
    // ═══════════════════════════════════════════════════════

    public function test_content_returns_text_file(): void
    {
        $file = File::factory()->create([
            'folder_id' => $this->folder->id,
            'owner_id' => $this->user->id,
            'mime_type' => 'text/plain',
            'size_bytes' => 100,
            'name' => 'readme.txt',
            'version' => 3,
            'checksum_sha256' => 'abc123',
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/files/{$file->id}/content");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $file->id)
            ->assertJsonPath('data.name', 'readme.txt')
            ->assertJsonPath('data.mime_type', 'text/plain')
            ->assertJsonPath('data.content', 'Hello, this is test content!')
            ->assertJsonPath('data.version', 3)
            ->assertJsonPath('data.checksum_sha256', 'abc123')
            ->assertJsonStructure([
                'data' => ['id', 'name', 'mime_type', 'content', 'version', 'checksum_sha256', 'updated_at'],
            ]);
    }

    public function test_content_works_for_json_mime(): void
    {
        $file = File::factory()->create([
            'folder_id' => $this->folder->id,
            'owner_id' => $this->user->id,
            'mime_type' => 'application/json',
            'size_bytes' => 50,
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/files/{$file->id}/content");

        $response->assertStatus(200)
            ->assertJsonPath('data.mime_type', 'application/json');
    }

    public function test_content_works_for_xml_mime(): void
    {
        $file = File::factory()->create([
            'folder_id' => $this->folder->id,
            'owner_id' => $this->user->id,
            'mime_type' => 'application/xml',
            'size_bytes' => 50,
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/files/{$file->id}/content");

        $response->assertStatus(200);
    }

    public function test_content_works_for_text_php_mime(): void
    {
        $file = File::factory()->create([
            'folder_id' => $this->folder->id,
            'owner_id' => $this->user->id,
            'mime_type' => 'text/x-php',
            'size_bytes' => 50,
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/files/{$file->id}/content");

        $response->assertStatus(200);
    }

    public function test_content_returns_415_for_binary_mime(): void
    {
        $file = File::factory()->create([
            'folder_id' => $this->folder->id,
            'owner_id' => $this->user->id,
            'mime_type' => 'image/jpeg',
            'size_bytes' => 100,
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/files/{$file->id}/content");

        $response->assertStatus(415);
    }

    public function test_content_returns_413_for_large_file(): void
    {
        $file = File::factory()->create([
            'folder_id' => $this->folder->id,
            'owner_id' => $this->user->id,
            'mime_type' => 'text/plain',
            'size_bytes' => 2_000_000, // 2MB > 1MB limit
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/files/{$file->id}/content");

        $response->assertStatus(413);
    }

    public function test_content_returns_410_for_deleted_file(): void
    {
        $file = File::factory()->create([
            'folder_id' => $this->folder->id,
            'owner_id' => $this->user->id,
            'mime_type' => 'text/plain',
            'size_bytes' => 100,
            'deleted_at' => now(),
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/files/{$file->id}/content");

        $response->assertStatus(410);
    }

    public function test_content_returns_403_for_other_user(): void
    {
        $file = File::factory()->create([
            'folder_id' => $this->folder->id,
            'owner_id' => $this->user->id,
            'mime_type' => 'text/plain',
            'size_bytes' => 100,
        ]);

        $response = $this->actingAs($this->otherUser, 'api')
            ->getJson("/api/files/{$file->id}/content");

        $response->assertStatus(403);
    }

    public function test_content_returns_401_for_unauthenticated(): void
    {
        $file = File::factory()->create([
            'folder_id' => $this->folder->id,
            'owner_id' => $this->user->id,
            'mime_type' => 'text/plain',
            'size_bytes' => 100,
        ]);

        $response = $this->getJson("/api/files/{$file->id}/content");

        $response->assertStatus(401);
    }

    // ═══════════════════════════════════════════════════════
    // EXIF IN FILE RESOURCE
    // ═══════════════════════════════════════════════════════

    public function test_show_includes_exif_when_populated(): void
    {
        $exifData = [
            'camera_model' => 'Canon EOS R6',
            'taken_at' => '2025-03-10T08:12:00+00:00',
            'width' => 4000,
            'height' => 3000,
            'iso' => 200,
        ];

        $file = File::factory()->create([
            'folder_id' => $this->folder->id,
            'owner_id' => $this->user->id,
            'mime_type' => 'image/jpeg',
            'exif_data' => $exifData,
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/files/{$file->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.exif.camera_model', 'Canon EOS R6')
            ->assertJsonPath('data.exif.width', 4000)
            ->assertJsonPath('data.exif.height', 3000)
            ->assertJsonPath('data.exif.iso', 200);
    }

    public function test_show_returns_null_exif_when_not_populated(): void
    {
        $file = File::factory()->create([
            'folder_id' => $this->folder->id,
            'owner_id' => $this->user->id,
            'mime_type' => 'text/plain',
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/files/{$file->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.exif', null);
    }

    // ═══════════════════════════════════════════════════════
    // EXIF JOB
    // ═══════════════════════════════════════════════════════

    public function test_extract_exif_job_is_idempotent(): void
    {
        $exifData = ['camera_model' => 'Nikon Z6'];

        $file = File::factory()->create([
            'folder_id' => $this->folder->id,
            'owner_id' => $this->user->id,
            'mime_type' => 'image/jpeg',
            'exif_data' => $exifData,
        ]);

        // Job should skip because exif_data is already set
        $job = new ExtractExifJob((string) $file->id);
        $job->handle(app(\App\Services\ExifService::class));

        $file->refresh();
        $this->assertEquals('Nikon Z6', $file->exif_data['camera_model']);
    }

    public function test_extract_exif_job_handles_missing_file(): void
    {
        $job = new ExtractExifJob('non-existent-id');

        // Should not throw
        $job->handle(app(\App\Services\ExifService::class));

        $this->assertTrue(true); // No exception = pass
    }

    // ═══════════════════════════════════════════════════════
    // CONTENT ENDPOINT SIZE LIMIT CONFIGURABILITY
    // ═══════════════════════════════════════════════════════

    public function test_content_respects_configured_max_bytes(): void
    {
        config(['vdrive.content_max_bytes' => 500]);

        $file = File::factory()->create([
            'folder_id' => $this->folder->id,
            'owner_id' => $this->user->id,
            'mime_type' => 'text/plain',
            'size_bytes' => 600, // > 500 custom limit
        ]);

        // Need to re-instantiate service with new config
        $this->app->forgetInstance(\App\Services\FileContentService::class);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/files/{$file->id}/content");

        $response->assertStatus(413);
    }
}
