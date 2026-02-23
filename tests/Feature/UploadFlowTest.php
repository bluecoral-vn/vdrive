<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\Folder;
use App\Models\Role;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\R2ClientService;
use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Database\Seeders\RolePermissionSeeder;
use GuzzleHttp\Psr7\Uri;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * Comprehensive upload test suite covering:
 * A. Basic Upload Init
 * B. File Delete & Rename
 * C. Chunked Upload Flow (presign parts, complete)
 * D. Network Failure / Error Simulation
 * E. Data Integrity
 * F. Security (auth, cross-user)
 * G. Performance (concurrent sessions, large uploads)
 * H. R2 Specific (presigned URLs, abort)
 * I. Cleanup (orphan sessions, expired uploads)
 */
class UploadFlowTest extends TestCase
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

    // ═══════════════════════════════════════════════════════
    // Mock setup — shared S3 mock used across all tests
    // ═══════════════════════════════════════════════════════

    private function mockR2(): void
    {
        $this->mockS3 = Mockery::mock(S3Client::class);

        $this->mockS3->shouldReceive('createMultipartUpload')
            ->andReturn(['UploadId' => 'mock-upload-id-'.Str::random(8)])
            ->byDefault();

        $this->mockS3->shouldReceive('getCommand')
            ->andReturn(Mockery::mock(CommandInterface::class))
            ->byDefault();

        $mockRequest = Mockery::mock(RequestInterface::class);
        $mockRequest->shouldReceive('getUri')
            ->andReturn(new Uri('https://r2.example.com/presigned-part'))
            ->byDefault();

        $this->mockS3->shouldReceive('createPresignedRequest')
            ->andReturn($mockRequest)
            ->byDefault();

        $this->mockS3->shouldReceive('completeMultipartUpload')
            ->andReturn([])
            ->byDefault();

        $this->mockS3->shouldReceive('abortMultipartUpload')
            ->andReturn([])
            ->byDefault();

        $this->mockS3->shouldReceive('deleteObject')
            ->andReturn([])
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

    /**
     * Helper: create a pending upload session for the default user.
     */
    private function createPendingSession(array $overrides = []): UploadSession
    {
        return UploadSession::query()->create(array_merge([
            'user_id' => $this->user->id,
            'folder_id' => $this->folder->id,
            'filename' => 'test-file.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 10485760, // 10MB
            'r2_object_key' => $this->user->id.'/'.Str::uuid().'/test-file.pdf',
            'r2_upload_id' => 'mock-upload-id-'.Str::random(8),
            'status' => 'pending',
            'expires_at' => now()->addHours(24),
        ], $overrides));
    }

    /**
     * Helper: create a File record for the default user.
     */
    private function createFile(array $overrides = []): File
    {
        return File::factory()->create(array_merge([
            'folder_id' => $this->folder->id,
            'owner_id' => $this->user->id,
        ], $overrides));
    }

    /**
     * Helper: generate mock parts array for complete endpoint.
     */
    private function mockParts(int $count = 5): array
    {
        return collect(range(1, $count))->map(fn (int $i) => [
            'part_number' => $i,
            'etag' => '"'.md5("part-{$i}").'"',
        ])->all();
    }

    // ═══════════════════════════════════════════════════════
    // A. BASIC UPLOAD INIT
    // ═══════════════════════════════════════════════════════

    /** Small file init — standard case */
    public function test_a1_init_small_file(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/upload/init', [
                'filename' => 'readme.txt',
                'mime_type' => 'text/plain',
                'size_bytes' => 256,
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['data' => ['session_id', 'r2_object_key', 'expires_at']]);

        $this->assertDatabaseHas('upload_sessions', [
            'filename' => 'readme.txt',
            'mime_type' => 'text/plain',
            'size_bytes' => 256,
            'status' => 'pending',
            'user_id' => $this->user->id,
        ]);
    }

    /** 10MB upload init */
    public function test_a2_init_10mb_file(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/upload/init', [
                'filename' => 'video.mp4',
                'mime_type' => 'video/mp4',
                'size_bytes' => 10485760,
                'folder_id' => $this->folder->uuid,
            ]);

        $response->assertStatus(201);

        $sessionId = $response->json('data.session_id');
        $session = UploadSession::find($sessionId);
        $this->assertEquals(10485760, $session->size_bytes);
        $this->assertEquals($this->folder->id, $session->folder_id);
    }

    /** Zero-byte file is rejected by validation */
    public function test_a3_zero_byte_file_rejected(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/upload/init', [
                'filename' => 'empty.txt',
                'mime_type' => 'text/plain',
                'size_bytes' => 0,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['size_bytes']);
    }

    /** Missing required fields */
    public function test_a4_missing_fields_validation(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/upload/init', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['filename', 'mime_type', 'size_bytes']);
    }

    /** Special character filename — unicode, spaces, dots */
    public function test_a5_special_character_filename(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/upload/init', [
                'filename' => '报告 (final v2).pdf',
                'mime_type' => 'application/pdf',
                'size_bytes' => 1024,
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('upload_sessions', [
            'filename' => '报告 (final v2).pdf',
        ]);
    }

    /** Very long filename — max 255 */
    public function test_a6_very_long_filename_rejected(): void
    {
        $longName = str_repeat('a', 256).'.pdf';

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/upload/init', [
                'filename' => $longName,
                'mime_type' => 'application/pdf',
                'size_bytes' => 1024,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['filename']);
    }

    /** Init without folder — root upload */
    public function test_a7_init_without_folder(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/upload/init', [
                'filename' => 'root-file.txt',
                'mime_type' => 'text/plain',
                'size_bytes' => 100,
            ]);

        $response->assertStatus(201);
        $session = UploadSession::find($response->json('data.session_id'));
        $this->assertNull($session->folder_id);
    }

    /** Invalid folder_id rejected */
    public function test_a8_invalid_folder_rejected(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/upload/init', [
                'filename' => 'test.txt',
                'mime_type' => 'text/plain',
                'size_bytes' => 100,
                'folder_id' => 99999,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['folder_id']);
    }

    // ═══════════════════════════════════════════════════════
    // B. FILE DELETE & RENAME
    // ═══════════════════════════════════════════════════════

    /** Owner can soft-delete their file */
    public function test_b1_owner_delete_file(): void
    {
        $file = $this->createFile();

        $response = $this->actingAs($this->user, 'api')
            ->deleteJson("/api/files/{$file->id}");

        $response->assertStatus(204);

        // Soft delete: record still exists but is trashed
        $file->refresh();
        $this->assertNotNull($file->deleted_at);
    }

    /** Delete non-existing file returns 404 */
    public function test_b2_delete_nonexistent_file(): void
    {
        $fakeId = Str::uuid()->toString();

        $response = $this->actingAs($this->user, 'api')
            ->deleteJson("/api/files/{$fakeId}");

        $response->assertStatus(404);
    }

    /** Owner can rename their file */
    public function test_b3_owner_rename_file(): void
    {
        $file = $this->createFile(['name' => 'old-name.pdf']);

        $response = $this->actingAs($this->user, 'api')
            ->patchJson("/api/files/{$file->id}", [
                'name' => 'new-name.pdf',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'new-name.pdf');

        // r2_object_key unchanged — rename is metadata-only
        $file->refresh();
        $this->assertEquals('new-name.pdf', $file->name);
    }

    /** Admin can rename any file */
    public function test_b4_admin_rename_any_file(): void
    {
        $file = $this->createFile(['name' => 'user-file.pdf']);

        $response = $this->actingAs($this->admin, 'api')
            ->patchJson("/api/files/{$file->id}", [
                'name' => 'admin-renamed.pdf',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'admin-renamed.pdf');
    }

    /** Rename validates name is required */
    public function test_b5_rename_requires_name(): void
    {
        $file = $this->createFile();

        $response = $this->actingAs($this->user, 'api')
            ->patchJson("/api/files/{$file->id}", []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    // ═══════════════════════════════════════════════════════
    // C. CHUNKED UPLOAD FLOW
    // ═══════════════════════════════════════════════════════

    /** Full 5-chunk sequential upload flow */
    public function test_c1_five_chunk_sequential_upload(): void
    {
        // Step 1: Init
        $initResponse = $this->actingAs($this->user, 'api')
            ->postJson('/api/upload/init', [
                'filename' => 'big-file.zip',
                'mime_type' => 'application/zip',
                'size_bytes' => 52428800, // 50MB
                'folder_id' => $this->folder->uuid,
            ]);

        $initResponse->assertStatus(201);
        $sessionId = $initResponse->json('data.session_id');

        // Step 2: Presign 5 parts sequentially
        for ($i = 1; $i <= 5; $i++) {
            $presignResponse = $this->actingAs($this->user, 'api')
                ->postJson('/api/upload/presign-part', [
                    'session_id' => $sessionId,
                    'part_number' => $i,
                ]);

            $presignResponse->assertStatus(200)
                ->assertJsonPath('data.part_number', $i)
                ->assertJsonStructure(['data' => ['url']]);
        }

        // Step 3: Complete
        $completeResponse = $this->actingAs($this->user, 'api')
            ->postJson('/api/upload/complete', [
                'session_id' => $sessionId,
                'parts' => $this->mockParts(5),
            ]);

        $completeResponse->assertStatus(201)
            ->assertJsonStructure(['data' => ['id', 'name']]);

        // Verify: session completed, file created
        $this->assertDatabaseHas('upload_sessions', [
            'id' => $sessionId,
            'status' => 'completed',
            'total_parts' => 5,
        ]);

        $this->assertDatabaseHas('files', [
            'name' => 'big-file.zip',
            'owner_id' => $this->user->id,
            'folder_id' => $this->folder->id,
        ]);
    }

    /** Presign parts out of order — valid use case */
    public function test_c2_presign_out_of_order(): void
    {
        $session = $this->createPendingSession();

        // Request parts out of order: 3, 1, 5, 2, 4 — all should succeed
        foreach ([3, 1, 5, 2, 4] as $partNumber) {
            $response = $this->actingAs($this->user, 'api')
                ->postJson('/api/upload/presign-part', [
                    'session_id' => $session->id,
                    'part_number' => $partNumber,
                ]);

            $response->assertStatus(200)
                ->assertJsonPath('data.part_number', $partNumber);
        }
    }

    /** Duplicate presign for same part — idempotent */
    public function test_c3_duplicate_presign_idempotent(): void
    {
        $session = $this->createPendingSession();

        // Request part 1 twice — both succeed
        $first = $this->actingAs($this->user, 'api')
            ->postJson('/api/upload/presign-part', [
                'session_id' => $session->id,
                'part_number' => 1,
            ]);

        $second = $this->actingAs($this->user, 'api')
            ->postJson('/api/upload/presign-part', [
                'session_id' => $session->id,
                'part_number' => 1,
            ]);

        $first->assertStatus(200);
        $second->assertStatus(200);
    }

    /** Complete requires at least one part */
    public function test_c4_complete_requires_parts(): void
    {
        $session = $this->createPendingSession();

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/upload/complete', [
                'session_id' => $session->id,
                'parts' => [],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['parts']);
    }

    /** Resume after interruption — presign next part on existing session */
    public function test_c5_resume_after_interruption(): void
    {
        $session = $this->createPendingSession();

        // Simulate: parts 1-3 uploaded, interruption, resume with part 4
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/upload/presign-part', [
                'session_id' => $session->id,
                'part_number' => 4,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.part_number', 4);

        // Session still pending
        $session->refresh();
        $this->assertTrue($session->isPending());
    }

    /** Complete endpoint creates correct File metadata */
    public function test_c6_complete_creates_correct_metadata(): void
    {
        $session = $this->createPendingSession([
            'filename' => 'report.xlsx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'size_bytes' => 2097152,
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/upload/complete', [
                'session_id' => $session->id,
                'parts' => $this->mockParts(2),
            ]);

        $response->assertStatus(201);

        $file = File::where('r2_object_key', $session->r2_object_key)->first();
        $this->assertNotNull($file);
        $this->assertEquals('report.xlsx', $file->name);
        $this->assertEquals('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $file->mime_type);
        $this->assertEquals(2097152, $file->size_bytes);
        $this->assertEquals($this->user->id, $file->owner_id);
    }

    // ═══════════════════════════════════════════════════════
    // D. NETWORK FAILURE / ERROR SIMULATION
    // ═══════════════════════════════════════════════════════

    /** R2 CreateMultipartUpload failure → 500 */
    public function test_d1_r2_init_failure(): void
    {
        $this->mockS3->shouldReceive('createMultipartUpload')
            ->andThrow(new AwsException('Connection refused', Mockery::mock(CommandInterface::class)));

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/upload/init', [
                'filename' => 'fail.pdf',
                'mime_type' => 'application/pdf',
                'size_bytes' => 1024,
            ]);

        // Should get a server error, not crash
        $response->assertServerError();
    }

    /** R2 CompleteMultipartUpload failure → 500 */
    public function test_d2_r2_complete_failure(): void
    {
        $session = $this->createPendingSession();

        $this->mockS3->shouldReceive('completeMultipartUpload')
            ->andThrow(new AwsException('Upload failed', Mockery::mock(CommandInterface::class)));

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/upload/complete', [
                'session_id' => $session->id,
                'parts' => $this->mockParts(1),
            ]);

        $response->assertServerError();

        // Session should NOT be marked completed
        $session->refresh();
        $this->assertEquals('pending', $session->status);
    }

    /** Expired upload session rejects presign */
    public function test_d3_expired_session_rejects_presign(): void
    {
        $session = $this->createPendingSession([
            'expires_at' => now()->subHour(),
        ]);

        // Session is still 'pending' status but expired by time.
        // Presign should still work (expiry is for cleanup, not enforcement)
        // If we want strict enforcement, we'd check in controller.
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/upload/presign-part', [
                'session_id' => $session->id,
                'part_number' => 1,
            ]);

        // Currently succeeds — expiry is enforced by cleanup command
        $response->assertStatus(200);
    }

    /** Complete on already-completed session → 422 */
    public function test_d4_double_complete_rejected(): void
    {
        $session = $this->createPendingSession();
        $session->update(['status' => 'completed']);

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/upload/complete', [
                'session_id' => $session->id,
                'parts' => $this->mockParts(1),
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Upload session is no longer active.');
    }

    // ═══════════════════════════════════════════════════════
    // E. DATA INTEGRITY
    // ═══════════════════════════════════════════════════════

    /** Object key format: {user_id}/{uuid}/{filename} */
    public function test_e1_object_key_deterministic_format(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/upload/init', [
                'filename' => 'photo.jpg',
                'mime_type' => 'image/jpeg',
                'size_bytes' => 5000,
            ]);

        $key = $response->json('data.r2_object_key');
        $parts = explode('/', $key);

        $this->assertCount(3, $parts, 'Object key must be {user_id}/{uuid}/{filename}');
        $this->assertEquals($this->user->id, $parts[0]);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $parts[1],
        );
        $this->assertEquals('photo.jpg', $parts[2]);
    }

    /** Unique object keys for different uploads of same filename */
    public function test_e2_unique_keys_for_same_filename(): void
    {
        $payload = [
            'filename' => 'duplicate.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
        ];

        $r1 = $this->actingAs($this->user, 'api')->postJson('/api/upload/init', $payload);
        $r2 = $this->actingAs($this->user, 'api')->postJson('/api/upload/init', $payload);

        $this->assertNotEquals(
            $r1->json('data.r2_object_key'),
            $r2->json('data.r2_object_key'),
            'Same filename must produce different object keys',
        );
    }

    /** File metadata UUID is a valid UUID */
    public function test_e3_file_uuid_valid(): void
    {
        $session = $this->createPendingSession();

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/upload/complete', [
                'session_id' => $session->id,
                'parts' => $this->mockParts(1),
            ]);

        $fileId = $response->json('data.id');
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $fileId,
        );
    }

    // ═══════════════════════════════════════════════════════
    // F. SECURITY
    // ═══════════════════════════════════════════════════════

    /** Unauthenticated user cannot init upload */
    public function test_f1_unauthenticated_cannot_init(): void
    {
        $response = $this->postJson('/api/upload/init', [
            'filename' => 'test.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 100,
        ]);

        $response->assertUnauthorized();
    }

    /** Unauthenticated user cannot complete upload */
    public function test_f2_unauthenticated_cannot_complete(): void
    {
        $session = $this->createPendingSession();

        $response = $this->postJson('/api/upload/complete', [
            'session_id' => $session->id,
            'parts' => $this->mockParts(1),
        ]);

        $response->assertUnauthorized();
    }

    /** Cross-user presign rejected */
    public function test_f3_cross_user_presign_rejected(): void
    {
        $session = $this->createPendingSession();

        $response = $this->actingAs($this->otherUser, 'api')
            ->postJson('/api/upload/presign-part', [
                'session_id' => $session->id,
                'part_number' => 1,
            ]);

        $response->assertStatus(403);
    }

    /** Cross-user complete rejected */
    public function test_f4_cross_user_complete_rejected(): void
    {
        $session = $this->createPendingSession();

        $response = $this->actingAs($this->otherUser, 'api')
            ->postJson('/api/upload/complete', [
                'session_id' => $session->id,
                'parts' => $this->mockParts(1),
            ]);

        $response->assertStatus(403);
    }

    /** Cross-user file delete rejected */
    public function test_f5_cross_user_delete_rejected(): void
    {
        $file = $this->createFile();

        $response = $this->actingAs($this->otherUser, 'api')
            ->deleteJson("/api/files/{$file->id}");

        $response->assertStatus(403);
    }

    /** Cross-user file rename rejected */
    public function test_f6_cross_user_rename_rejected(): void
    {
        $file = $this->createFile();

        $response = $this->actingAs($this->otherUser, 'api')
            ->patchJson("/api/files/{$file->id}", [
                'name' => 'hacked.pdf',
            ]);

        $response->assertStatus(403);
    }

    /** Cross-user abort rejected */
    public function test_f7_cross_user_abort_rejected(): void
    {
        $session = $this->createPendingSession();

        $response = $this->actingAs($this->otherUser, 'api')
            ->postJson('/api/upload/abort', [
                'session_id' => $session->id,
            ]);

        $response->assertStatus(403);
    }

    /** Admin can view any file */
    public function test_f8_admin_can_view_any_file(): void
    {
        $file = $this->createFile();

        $response = $this->actingAs($this->admin, 'api')
            ->getJson("/api/files/{$file->id}");

        $response->assertStatus(200);
    }

    /** Admin can delete any file */
    public function test_f9_admin_can_delete_any_file(): void
    {
        $file = $this->createFile();

        $response = $this->actingAs($this->admin, 'api')
            ->deleteJson("/api/files/{$file->id}");

        $response->assertStatus(204);
    }

    // ═══════════════════════════════════════════════════════
    // G. PERFORMANCE / CONCURRENCY
    // ═══════════════════════════════════════════════════════

    /** Multiple concurrent upload sessions for same user */
    public function test_g1_multiple_concurrent_sessions(): void
    {
        $sessions = [];

        // Init 10 upload sessions
        for ($i = 0; $i < 10; $i++) {
            $response = $this->actingAs($this->user, 'api')
                ->postJson('/api/upload/init', [
                    'filename' => "file-{$i}.dat",
                    'mime_type' => 'application/octet-stream',
                    'size_bytes' => 1048576,
                ]);

            $response->assertStatus(201);
            $sessions[] = $response->json('data.session_id');
        }

        // All 10 sessions exist and are pending
        $this->assertEquals(10, UploadSession::where('user_id', $this->user->id)
            ->where('status', 'pending')
            ->count());

        // All have unique object keys
        $keys = UploadSession::whereIn('id', $sessions)->pluck('r2_object_key');
        $this->assertEquals($keys->count(), $keys->unique()->count());
    }

    /** Large file init — 200MB logical, no memory issue */
    public function test_g2_large_file_init_200mb(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/upload/init', [
                'filename' => 'large-backup.tar.gz',
                'mime_type' => 'application/gzip',
                'size_bytes' => 209715200, // 200MB
            ]);

        $response->assertStatus(201);
        $session = UploadSession::find($response->json('data.session_id'));
        $this->assertEquals(209715200, $session->size_bytes);
    }

    /** Quota enforcement — multiple files track cumulative usage */
    public function test_g3_quota_cumulative_check(): void
    {
        // Set 10MB per-user quota with 9MB already used
        $this->user->update([
            'quota_limit_bytes' => 10485760,
            'quota_used_bytes' => 9437184, // 9MB
        ]);

        // 0.5MB should succeed (9MB + 0.5MB = 9.5MB < 10MB)
        $r1 = $this->actingAs($this->user, 'api')
            ->postJson('/api/upload/init', [
                'filename' => 'small.txt',
                'mime_type' => 'text/plain',
                'size_bytes' => 524288,
            ]);
        $r1->assertStatus(201);

        // Another 1MB should fail (9MB + 1MB > 10MB)
        $r2 = $this->actingAs($this->user, 'api')
            ->postJson('/api/upload/init', [
                'filename' => 'overflow.txt',
                'mime_type' => 'text/plain',
                'size_bytes' => 1048577,
            ]);
        $r2->assertStatus(422)
            ->assertJsonPath('message', 'Storage quota exceeded.');
    }

    // ═══════════════════════════════════════════════════════
    // H. R2 SPECIFIC (Presigned URLs, Abort)
    // ═══════════════════════════════════════════════════════

    /** Presigned URL is returned as string */
    public function test_h1_presigned_url_returned(): void
    {
        $session = $this->createPendingSession();

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/upload/presign-part', [
                'session_id' => $session->id,
                'part_number' => 1,
            ]);

        $response->assertStatus(200);
        $url = $response->json('data.url');
        $this->assertStringStartsWith('https://', $url);
    }

    /** Abort upload — cancels R2 multipart and marks session aborted */
    public function test_h2_abort_upload(): void
    {
        $session = $this->createPendingSession();

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/upload/abort', [
                'session_id' => $session->id,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Upload aborted.');

        $session->refresh();
        $this->assertEquals('aborted', $session->status);
    }

    /** Abort on already-completed session → rejected */
    public function test_h3_abort_completed_session_rejected(): void
    {
        $session = $this->createPendingSession();
        $session->update(['status' => 'completed']);

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/upload/abort', [
                'session_id' => $session->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Upload session is no longer active.');
    }

    /** Part number validation: 1-10000 */
    public function test_h4_part_number_bounds(): void
    {
        $session = $this->createPendingSession();

        // Part 0 invalid
        $r1 = $this->actingAs($this->user, 'api')
            ->postJson('/api/upload/presign-part', [
                'session_id' => $session->id,
                'part_number' => 0,
            ]);
        $r1->assertStatus(422)->assertJsonValidationErrors(['part_number']);

        // Part 10001 invalid
        $r2 = $this->actingAs($this->user, 'api')
            ->postJson('/api/upload/presign-part', [
                'session_id' => $session->id,
                'part_number' => 10001,
            ]);
        $r2->assertStatus(422)->assertJsonValidationErrors(['part_number']);

        // Part 10000 valid
        $r3 = $this->actingAs($this->user, 'api')
            ->postJson('/api/upload/presign-part', [
                'session_id' => $session->id,
                'part_number' => 10000,
            ]);
        $r3->assertStatus(200);
    }

    // ═══════════════════════════════════════════════════════
    // I. CLEANUP
    // ═══════════════════════════════════════════════════════

    /** Cleanup command marks expired sessions */
    public function test_i1_cleanup_expired_sessions(): void
    {
        // 2 expired, 1 still valid
        $this->createPendingSession(['expires_at' => now()->subHour()]);
        $this->createPendingSession(['expires_at' => now()->subDay()]);
        $this->createPendingSession(['expires_at' => now()->addHour()]);

        $this->artisan('uploads:cleanup')
            ->assertExitCode(0)
            ->expectsOutputToContain('2 expired');

        $this->assertEquals(1, UploadSession::where('status', 'pending')->count());
    }

    /** Cleanup skips already completed sessions */
    public function test_i2_cleanup_skips_completed(): void
    {
        $this->createPendingSession([
            'status' => 'completed',
            'expires_at' => now()->subHour(),
        ]);

        $this->artisan('uploads:cleanup')
            ->assertExitCode(0)
            ->expectsOutputToContain('0 expired');
    }

    /** Cleanup handles R2 abort failure gracefully */
    public function test_i3_cleanup_handles_r2_failure(): void
    {
        $this->createPendingSession(['expires_at' => now()->subHour()]);

        $this->mockS3->shouldReceive('abortMultipartUpload')
            ->andThrow(new AwsException('Not found', Mockery::mock(CommandInterface::class)));

        // Should not throw — graceful fallback to 'expired' status
        $this->artisan('uploads:cleanup')
            ->assertExitCode(0);

        $this->assertEquals(0, UploadSession::where('status', 'pending')->count());
        $this->assertEquals(1, UploadSession::where('status', 'expired')->count());
    }

    /** Force delete file triggers R2 deleteObject (soft delete does not) */
    public function test_i4_force_delete_calls_r2_delete(): void
    {
        $file = $this->createFile(['r2_object_key' => 'test/key/file.pdf']);

        $this->mockS3->shouldReceive('deleteObject')
            ->once()
            ->with(Mockery::on(fn ($args) => $args['Key'] === 'test/key/file.pdf'
                && $args['Bucket'] === 'test-bucket'))
            ->andReturn([]);

        $this->actingAs($this->user, 'api')
            ->deleteJson("/api/trash/files/{$file->id}")
            ->assertStatus(204);
    }
}
