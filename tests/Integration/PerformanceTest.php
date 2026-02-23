<?php

namespace Tests\Integration;

use App\Models\File;
use App\Models\Folder;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\QueryCountHelper;

/**
 * Integration — Performance / N+1 Tests.
 */
class PerformanceTest extends TestCase
{
    use QueryCountHelper;
    use RefreshDatabase;

    private User $owner;

    private Folder $folder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->mockR2();

        $userRole = Role::query()->where('slug', 'user')->firstOrFail();

        $this->owner = User::factory()->create();
        $this->owner->roles()->attach($userRole);

        $this->folder = Folder::factory()->create(['owner_id' => $this->owner->id]);
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

    // ── Folder File Listing ──────────────────────────────

    public function test_folder_file_listing_no_n_plus_one(): void
    {
        // Seed 200 files
        File::factory()->count(200)->create([
            'folder_id' => $this->folder->id,
            'owner_id' => $this->owner->id,
        ]);

        // Auth resolve + permission check + paginated listing = constant queries
        // Generous threshold: 15 queries max (auth, permission, pagination, count)
        $this->actingAs($this->owner, 'api');

        $this->assertQueryCount(function () {
            $response = $this->getJson("/api/folders/{$this->folder->id}/files");
            $response->assertStatus(200);
        }, 15, 'Folder file listing should not trigger N+1 queries');
    }

    public function test_folder_file_listing_constant_with_1000_files(): void
    {
        // Seed 1000 files
        File::factory()->count(1000)->create([
            'folder_id' => $this->folder->id,
            'owner_id' => $this->owner->id,
        ]);

        $this->actingAs($this->owner, 'api');

        $this->assertQueryCount(function () {
            $response = $this->getJson("/api/folders/{$this->folder->id}/files");
            $response->assertStatus(200);
        }, 15, 'Query count must remain constant regardless of file count');
    }

    // ── Folder Children Listing ──────────────────────────

    public function test_folder_children_listing_no_n_plus_one(): void
    {
        // Seed 100 child folders
        Folder::factory()->count(100)->create([
            'parent_id' => $this->folder->id,
            'owner_id' => $this->owner->id,
        ]);

        $this->actingAs($this->owner, 'api');

        $this->assertQueryCount(function () {
            $response = $this->getJson("/api/folders/{$this->folder->id}/children");
            $response->assertStatus(200);
        }, 15, 'Folder children listing should not trigger N+1');
    }

    // ── Share Listing ────────────────────────────────────

    public function test_share_listing_no_n_plus_one(): void
    {
        $viewer = User::factory()->create();
        $userRole = Role::query()->where('slug', 'user')->firstOrFail();
        $viewer->roles()->attach($userRole);

        // Create 50 files and share each with viewer
        $files = File::factory()->count(50)->create([
            'folder_id' => $this->folder->id,
            'owner_id' => $this->owner->id,
        ]);

        foreach ($files as $file) {
            $this->actingAs($this->owner, 'api')
                ->postJson('/api/share', [
                    'file_id' => $file->id,
                    'shared_with' => $viewer->id,
                    'permission' => 'view',
                ]);
        }

        $this->actingAs($viewer, 'api');

        $this->assertQueryCount(function () {
            $response = $this->getJson('/api/share/with-me');
            $response->assertStatus(200);
        }, 20, 'Share listing should not trigger N+1');
    }

    // ── Search ───────────────────────────────────────────

    public function test_search_query_count_reasonable(): void
    {
        File::factory()->count(100)->create([
            'folder_id' => $this->folder->id,
            'owner_id' => $this->owner->id,
        ]);

        $this->actingAs($this->owner, 'api');

        $this->assertQueryCount(function () {
            $response = $this->getJson('/api/search?q=test');
            $response->assertStatus(200);
        }, 20, 'Search query count should be reasonable');
    }
}
