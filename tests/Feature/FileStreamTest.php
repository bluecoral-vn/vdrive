<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\Folder;
use App\Models\Role;
use App\Models\User;
use App\Services\R2ClientService;
use Aws\Result;
use Aws\S3\S3Client;
use Database\Seeders\RolePermissionSeeder;
use GuzzleHttp\Psr7\Stream;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Tests for the file stream proxy endpoint: GET /api/files/{file}/stream
 */
class FileStreamTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private User $otherUser;

    private User $admin;

    private Folder $folder;

    private S3Client|Mockery\MockInterface $mockS3;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $adminRole = Role::query()->where('slug', 'admin')->firstOrFail();
        $userRole = Role::query()->where('slug', 'user')->firstOrFail();

        $this->admin = User::factory()->create();
        $this->admin->roles()->attach($adminRole);

        $this->user = User::factory()->create();
        $this->user->roles()->attach($userRole);

        $this->otherUser = User::factory()->create();
        $this->otherUser->roles()->attach($userRole);

        $this->folder = Folder::factory()->create(['owner_id' => $this->user->id]);

        $this->mockR2();
    }

    private function mockR2(): void
    {
        $this->mockS3 = Mockery::mock(S3Client::class);

        // Default: getObject returns binary content
        $body = fopen('php://memory', 'r+');
        fwrite($body, 'fake-binary-content-for-testing');
        rewind($body);

        $this->mockS3->shouldReceive('getObject')
            ->andReturn(new Result([
                'Body' => new Stream($body),
            ]))
            ->byDefault();

        $this->instance(R2ClientService::class, new class($this->mockS3) extends R2ClientService
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

    private function createFile(array $overrides = []): File
    {
        return File::factory()->create(array_merge([
            'folder_id' => $this->folder->id,
            'owner_id' => $this->user->id,
            'r2_object_key' => $this->user->id . '/test-uuid/test-file.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 10485760,
        ], $overrides));
    }

    // ═══════════════════════════════════════════════════════
    // HAPPY PATH
    // ═══════════════════════════════════════════════════════

    /** Owner can stream their file */
    public function test_owner_can_stream_file(): void
    {
        $file = $this->createFile(['name' => 'report.pdf']);

        $response = $this->actingAs($this->user, 'api')
            ->get("/api/files/{$file->id}/stream");

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/pdf');
        $response->assertHeader('Content-Disposition', 'attachment; filename=report.pdf');
        $response->assertHeader('Content-Length', (string) $file->size_bytes);
    }

    /** Admin can stream any file */
    public function test_admin_can_stream_any_file(): void
    {
        $file = $this->createFile(['name' => 'user-file.png', 'mime_type' => 'image/png']);

        $response = $this->actingAs($this->admin, 'api')
            ->get("/api/files/{$file->id}/stream");

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'image/png');
    }

    /** Response has correct headers for binary streaming */
    public function test_stream_has_correct_response_headers(): void
    {
        $file = $this->createFile(['name' => 'image.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 512000]);

        $response = $this->actingAs($this->user, 'api')
            ->get("/api/files/{$file->id}/stream");

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'image/jpeg');
        $response->assertHeader('Content-Disposition', 'attachment; filename=image.jpg');
        $response->assertHeader('Content-Length', '512000');
    }

    // ═══════════════════════════════════════════════════════
    // AUTHORIZATION
    // ═══════════════════════════════════════════════════════

    /** Unauthenticated user gets 401 */
    public function test_unauthenticated_cannot_stream(): void
    {
        $file = $this->createFile();

        $response = $this->get("/api/files/{$file->id}/stream");

        $response->assertUnauthorized();
    }

    /** Stranger gets 403 */
    public function test_stranger_cannot_stream(): void
    {
        $file = $this->createFile();

        $response = $this->actingAs($this->otherUser, 'api')
            ->get("/api/files/{$file->id}/stream");

        $response->assertStatus(403);
    }

    // ═══════════════════════════════════════════════════════
    // EDGE CASES
    // ═══════════════════════════════════════════════════════

    /** Non-existent file returns 404 */
    public function test_nonexistent_file_returns_404(): void
    {
        $fakeId = '019c0000-0000-7000-8000-000000000000';

        $response = $this->actingAs($this->user, 'api')
            ->get("/api/files/{$fakeId}/stream");

        $response->assertStatus(404);
    }

    /** Trashed file returns 404 (SoftDeletes scope) */
    public function test_trashed_file_returns_404(): void
    {
        $file = $this->createFile();
        $file->delete(); // Soft delete

        $response = $this->actingAs($this->user, 'api')
            ->get("/api/files/{$file->id}/stream");

        $response->assertStatus(404);
    }

    /** Stream works for files with unicode names */
    public function test_stream_unicode_filename(): void
    {
        $file = $this->createFile(['name' => '报告 final.pdf']);

        $response = $this->actingAs($this->user, 'api')
            ->get("/api/files/{$file->id}/stream");

        $response->assertStatus(200);
        $response->assertHeader('Content-Disposition', 'attachment; filename="______ final.pdf"; filename*=utf-8\'\'%E6%8A%A5%E5%91%8A%20final.pdf');
    }
}
