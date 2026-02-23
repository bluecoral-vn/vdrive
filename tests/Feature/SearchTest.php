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
use Mockery;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * Phase 10 — Smart Search & Filtering.
 */
class SearchTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private User $otherUser;

    private User $admin;

    private Folder $folder;

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
    // NAME SEARCH (LIKE)
    // ═══════════════════════════════════════════════════════

    public function test_search_by_name(): void
    {
        $this->createFile(['name' => 'invoice-2025.pdf']);
        $this->createFile(['name' => 'photo.jpg']);

        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/search?query=invoice');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('invoice-2025.pdf', $response->json('data.0.name'));
    }

    public function test_search_by_name_case_insensitive(): void
    {
        $this->createFile(['name' => 'Invoice-2025.PDF']);

        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/search?query=invoice');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_search_by_name_partial_match(): void
    {
        $this->createFile(['name' => 'quarterly-report-2025.pdf']);

        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/search?query=report');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    // ═══════════════════════════════════════════════════════
    // MIME TYPE FILTER
    // ═══════════════════════════════════════════════════════

    public function test_search_by_mime_type(): void
    {
        $this->createFile(['name' => 'doc.pdf', 'mime_type' => 'application/pdf']);
        $this->createFile(['name' => 'photo.jpg', 'mime_type' => 'image/jpeg']);

        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/search?mime=application/pdf');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('doc.pdf', $response->json('data.0.name'));
    }

    // ═══════════════════════════════════════════════════════
    // DATE RANGE FILTER
    // ═══════════════════════════════════════════════════════

    public function test_search_by_date_range(): void
    {
        $this->createFile(['name' => 'old.pdf', 'created_at' => '2024-01-01']);
        $this->createFile(['name' => 'new.pdf', 'created_at' => '2025-06-15']);

        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/search?from=2025-01-01&to=2025-12-31');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('new.pdf', $response->json('data.0.name'));
    }

    // ═══════════════════════════════════════════════════════
    // OWNER FILTER
    // ═══════════════════════════════════════════════════════

    public function test_admin_search_by_owner(): void
    {
        $this->createFile(['name' => 'user-file.pdf', 'owner_id' => $this->user->id]);

        $otherFolder = Folder::factory()->create(['owner_id' => $this->otherUser->id]);
        File::factory()->create([
            'name' => 'other-file.pdf',
            'folder_id' => $otherFolder->id,
            'owner_id' => $this->otherUser->id,
        ]);

        $response = $this->actingAs($this->admin, 'api')
            ->getJson("/api/search?owner={$this->otherUser->id}");

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('other-file.pdf', $response->json('data.0.name'));
    }

    // ═══════════════════════════════════════════════════════
    // COMBINED FILTERS
    // ═══════════════════════════════════════════════════════

    public function test_search_combined_filters(): void
    {
        $this->createFile(['name' => 'invoice-jan.pdf', 'mime_type' => 'application/pdf', 'created_at' => '2025-01-15']);
        $this->createFile(['name' => 'invoice-jun.pdf', 'mime_type' => 'application/pdf', 'created_at' => '2025-06-15']);
        $this->createFile(['name' => 'photo.jpg', 'mime_type' => 'image/jpeg', 'created_at' => '2025-06-15']);

        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/search?query=invoice&mime=application/pdf&from=2025-06-01');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('invoice-jun.pdf', $response->json('data.0.name'));
    }

    // ═══════════════════════════════════════════════════════
    // PERMISSION SCOPING
    // ═══════════════════════════════════════════════════════

    public function test_user_only_sees_own_files(): void
    {
        $this->createFile(['name' => 'my-file.pdf']);

        $otherFolder = Folder::factory()->create(['owner_id' => $this->otherUser->id]);
        File::factory()->create([
            'name' => 'other-file.pdf',
            'folder_id' => $otherFolder->id,
            'owner_id' => $this->otherUser->id,
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/search?query=file');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('my-file.pdf', $response->json('data.0.name'));
    }

    public function test_user_sees_directly_shared_files(): void
    {
        $otherFolder = Folder::factory()->create(['owner_id' => $this->otherUser->id]);
        $sharedFile = File::factory()->create([
            'name' => 'shared-file.pdf',
            'folder_id' => $otherFolder->id,
            'owner_id' => $this->otherUser->id,
        ]);

        Share::query()->create([
            'file_id' => $sharedFile->id,
            'shared_by' => $this->otherUser->id,
            'shared_with' => $this->user->id,
            'permission' => 'view',
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/search?query=shared');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('shared-file.pdf', $response->json('data.0.name'));
    }

    public function test_user_sees_files_in_shared_folder(): void
    {
        $otherFolder = Folder::factory()->create(['owner_id' => $this->otherUser->id]);
        File::factory()->create([
            'name' => 'inherited-file.pdf',
            'folder_id' => $otherFolder->id,
            'owner_id' => $this->otherUser->id,
        ]);

        Share::query()->create([
            'folder_id' => $otherFolder->id,
            'shared_by' => $this->otherUser->id,
            'shared_with' => $this->user->id,
            'permission' => 'view',
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/search?query=inherited');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_user_sees_files_in_nested_shared_folder(): void
    {
        $parentFolder = Folder::factory()->create(['owner_id' => $this->otherUser->id]);
        $childFolder = Folder::factory()->create([
            'parent_id' => $parentFolder->id,
            'owner_id' => $this->otherUser->id,
        ]);
        File::factory()->create([
            'name' => 'deep-nested-file.pdf',
            'folder_id' => $childFolder->id,
            'owner_id' => $this->otherUser->id,
        ]);

        Share::query()->create([
            'folder_id' => $parentFolder->id,
            'shared_by' => $this->otherUser->id,
            'shared_with' => $this->user->id,
            'permission' => 'view',
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/search?query=deep-nested');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_admin_sees_all_files(): void
    {
        $this->createFile(['name' => 'user-file.pdf']);

        $otherFolder = Folder::factory()->create(['owner_id' => $this->otherUser->id]);
        File::factory()->create([
            'name' => 'other-file.pdf',
            'folder_id' => $otherFolder->id,
            'owner_id' => $this->otherUser->id,
        ]);

        $response = $this->actingAs($this->admin, 'api')
            ->getJson('/api/search?query=file');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
    }

    // ═══════════════════════════════════════════════════════
    // TRASHED FILES EXCLUDED
    // ═══════════════════════════════════════════════════════

    public function test_trashed_files_excluded_from_search(): void
    {
        $this->createFile(['name' => 'active.pdf']);
        $this->createFile([
            'name' => 'trashed.pdf',
            'deleted_at' => now(),
            'deleted_by' => $this->user->id,
            'purge_at' => now()->addDays(15),
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/search');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('active.pdf', $response->json('data.0.name'));
    }

    // ═══════════════════════════════════════════════════════
    // PAGINATION
    // ═══════════════════════════════════════════════════════

    public function test_search_pagination(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->createFile(['name' => "file-{$i}.pdf", 'created_at' => now()->subSeconds(5 - $i)]);
        }

        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/search?limit=2');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
        $this->assertNotNull($response->json('meta.next_cursor'));
    }

    // ═══════════════════════════════════════════════════════
    // EDGE CASES
    // ═══════════════════════════════════════════════════════

    public function test_empty_search_returns_all_accessible(): void
    {
        $this->createFile(['name' => 'file1.pdf']);
        $this->createFile(['name' => 'file2.pdf']);

        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/search');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
    }

    public function test_no_results_returns_empty(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/search?query=nonexistent');

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data'));
    }

    public function test_search_after_restore_includes_file(): void
    {
        $file = $this->createFile([
            'name' => 'restored-file.pdf',
            'deleted_at' => now(),
            'deleted_by' => $this->user->id,
            'purge_at' => now()->addDays(15),
        ]);

        // Not visible while trashed
        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/search?query=restored');
        $this->assertCount(0, $response->json('data'));

        // Restore
        $file->update(['deleted_at' => null, 'deleted_by' => null, 'purge_at' => null]);

        // Now visible
        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/search?query=restored');
        $this->assertCount(1, $response->json('data'));
    }

    public function test_expired_share_not_visible_in_search(): void
    {
        $otherFolder = Folder::factory()->create(['owner_id' => $this->otherUser->id]);
        File::factory()->create([
            'name' => 'expired-shared.pdf',
            'folder_id' => $otherFolder->id,
            'owner_id' => $this->otherUser->id,
        ]);

        Share::query()->create([
            'folder_id' => $otherFolder->id,
            'shared_by' => $this->otherUser->id,
            'shared_with' => $this->user->id,
            'permission' => 'view',
            'expires_at' => now()->subHour(),
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/search?query=expired-shared');

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data'));
    }

    // ═══════════════════════════════════════════════════════
    // MULTI-MIME FILTER (Photos feature)
    // ═══════════════════════════════════════════════════════

    public function test_search_by_multiple_mime_types(): void
    {
        $this->createFile(['name' => 'photo.jpg', 'mime_type' => 'image/jpeg']);
        $this->createFile(['name' => 'screenshot.png', 'mime_type' => 'image/png']);
        $this->createFile(['name' => 'video.mp4', 'mime_type' => 'video/mp4']);
        $this->createFile(['name' => 'doc.pdf', 'mime_type' => 'application/pdf']);

        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/search?mime[]=image/jpeg&mime[]=image/png&mime[]=video/mp4');

        $response->assertStatus(200);
        $this->assertCount(3, $response->json('data'));

        $names = collect($response->json('data'))->pluck('name')->toArray();
        $this->assertContains('photo.jpg', $names);
        $this->assertContains('screenshot.png', $names);
        $this->assertContains('video.mp4', $names);
        $this->assertNotContains('doc.pdf', $names);
    }

    public function test_search_mime_array_excludes_non_matching(): void
    {
        $this->createFile(['name' => 'doc.pdf', 'mime_type' => 'application/pdf']);
        $this->createFile(['name' => 'spreadsheet.xlsx', 'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);

        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/search?mime[]=image/jpeg&mime[]=video/mp4');

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data'));
    }

    public function test_search_single_mime_still_works(): void
    {
        $this->createFile(['name' => 'photo.jpg', 'mime_type' => 'image/jpeg']);
        $this->createFile(['name' => 'doc.pdf', 'mime_type' => 'application/pdf']);

        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/search?mime=image/jpeg');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('photo.jpg', $response->json('data.0.name'));
    }
}
