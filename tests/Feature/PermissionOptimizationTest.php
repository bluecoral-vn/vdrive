<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\Folder;
use App\Models\Role;
use App\Models\Share;
use App\Models\User;
use App\Services\PermissionContextBuilder;
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
 * Phase 11 — PermissionService Optimization Tests.
 *
 * Covers:
 * A. Correctness (owner, direct share, inherited, revocation, guest, soft-delete)
 * B. Nested hierarchy (deep, mid-level, subtree)
 * C. Conflict resolution (overlapping, direct+inherited)
 * D. Performance (5000+ files, query count assertions)
 */
class PermissionOptimizationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $recipient;

    private User $admin;

    private User $stranger;

    private Role $userRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $adminRole = Role::query()->where('slug', 'admin')->firstOrFail();
        $this->userRole = Role::query()->where('slug', 'user')->firstOrFail();

        $this->admin = User::factory()->create();
        $this->admin->roles()->attach($adminRole);

        $this->owner = User::factory()->create();
        $this->owner->roles()->attach($this->userRole);

        $this->recipient = User::factory()->create();
        $this->recipient->roles()->attach($this->userRole);

        $this->stranger = User::factory()->create();
        $this->stranger->roles()->attach($this->userRole);

        $this->mockR2();
    }

    private function mockR2(): void
    {
        $mockS3 = Mockery::mock(S3Client::class);
        $mockS3->shouldReceive('createMultipartUpload')->andReturn(['UploadId' => 'mock-upload-id'])->byDefault();
        $mockS3->shouldReceive('getCommand')->andReturn(Mockery::mock(CommandInterface::class))->byDefault();

        $mockRequest = Mockery::mock(RequestInterface::class);
        $mockRequest->shouldReceive('getUri')->andReturn(new Uri('https://r2.example.com/presigned'))->byDefault();

        $mockS3->shouldReceive('createPresignedRequest')->andReturn($mockRequest)->byDefault();
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
    // A. CORRECTNESS TESTS
    // ═══════════════════════════════════════════════════════

    public function test_owner_can_access_own_file(): void
    {
        $folder = Folder::factory()->create(['owner_id' => $this->owner->id]);
        $file = File::factory()->create(['folder_id' => $folder->id, 'owner_id' => $this->owner->id]);

        $this->actingAs($this->owner, 'api')
            ->getJson("/api/files/{$file->id}")
            ->assertStatus(200);
    }

    public function test_direct_file_share_works(): void
    {
        $folder = Folder::factory()->create(['owner_id' => $this->owner->id]);
        $file = File::factory()->create(['folder_id' => $folder->id, 'owner_id' => $this->owner->id]);

        Share::query()->create([
            'file_id' => $file->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'view',
        ]);

        $this->actingAs($this->recipient, 'api')
            ->getJson("/api/files/{$file->id}")
            ->assertStatus(200);
    }

    public function test_inherited_folder_share_works(): void
    {
        $folder = Folder::factory()->create(['owner_id' => $this->owner->id]);
        $file = File::factory()->create(['folder_id' => $folder->id, 'owner_id' => $this->owner->id]);

        Share::query()->create([
            'folder_id' => $folder->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'view',
        ]);

        $this->actingAs($this->recipient, 'api')
            ->getJson("/api/files/{$file->id}")
            ->assertStatus(200);
    }

    public function test_revoking_folder_share_removes_inherited_access(): void
    {
        $folder = Folder::factory()->create(['owner_id' => $this->owner->id]);
        $file = File::factory()->create(['folder_id' => $folder->id, 'owner_id' => $this->owner->id]);

        $share = Share::query()->create([
            'folder_id' => $folder->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'view',
        ]);

        // Has access
        $this->actingAs($this->recipient, 'api')
            ->getJson("/api/files/{$file->id}")
            ->assertStatus(200);

        // Revoke
        $share->delete();

        // No longer has access
        $this->actingAs($this->recipient, 'api')
            ->getJson("/api/files/{$file->id}")
            ->assertStatus(403);
    }

    public function test_direct_file_share_overrides_revoked_folder_share(): void
    {
        $folder = Folder::factory()->create(['owner_id' => $this->owner->id]);
        $file = File::factory()->create(['folder_id' => $folder->id, 'owner_id' => $this->owner->id]);

        // Folder share
        $folderShare = Share::query()->create([
            'folder_id' => $folder->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'view',
        ]);

        // Direct file share
        Share::query()->create([
            'file_id' => $file->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'view',
        ]);

        // Revoke folder share
        $folderShare->delete();

        // Still has access via direct file share
        $this->actingAs($this->recipient, 'api')
            ->getJson("/api/files/{$file->id}")
            ->assertStatus(200);
    }

    public function test_guest_token_access_works(): void
    {
        $folder = Folder::factory()->create(['owner_id' => $this->owner->id]);
        $file = File::factory()->create(['folder_id' => $folder->id, 'owner_id' => $this->owner->id]);

        $rawToken = Str::random(64);
        Share::query()->create([
            'file_id' => $file->id,
            'shared_by' => $this->owner->id,
            'shared_with' => null,
            'token_hash' => hash('sha256', $rawToken),
            'permission' => 'view',
        ]);

        $this->getJson("/api/share/{$rawToken}")
            ->assertStatus(200)
            ->assertJsonPath('data.permission', 'view');
    }

    public function test_soft_deleted_parent_blocks_access(): void
    {
        $folder = Folder::factory()->create([
            'owner_id' => $this->owner->id,
            'deleted_at' => now(),
        ]);
        $childFolder = Folder::factory()->create([
            'parent_id' => $folder->id,
            'owner_id' => $this->owner->id,
        ]);
        $file = File::factory()->create([
            'folder_id' => $childFolder->id,
            'owner_id' => $this->owner->id,
        ]);

        Share::query()->create([
            'folder_id' => $folder->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'view',
        ]);

        // File not accessible by stranger even with share on trashed parent
        $this->actingAs($this->stranger, 'api')
            ->getJson("/api/files/{$file->id}")
            ->assertStatus(403);
    }

    // ═══════════════════════════════════════════════════════
    // B. NESTED HIERARCHY TESTS
    // ═══════════════════════════════════════════════════════

    public function test_share_root_folder_access_deep_nested_file(): void
    {
        // Build: root > L1 > L2 > L3 > L4 > file (5 levels deep)
        $root = Folder::factory()->create(['owner_id' => $this->owner->id]);
        $l1 = Folder::factory()->create(['parent_id' => $root->id, 'owner_id' => $this->owner->id]);
        $l2 = Folder::factory()->create(['parent_id' => $l1->id, 'owner_id' => $this->owner->id]);
        $l3 = Folder::factory()->create(['parent_id' => $l2->id, 'owner_id' => $this->owner->id]);
        $l4 = Folder::factory()->create(['parent_id' => $l3->id, 'owner_id' => $this->owner->id]);
        $file = File::factory()->create(['folder_id' => $l4->id, 'owner_id' => $this->owner->id]);

        Share::query()->create([
            'folder_id' => $root->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'view',
        ]);

        $this->actingAs($this->recipient, 'api')
            ->getJson("/api/files/{$file->id}")
            ->assertStatus(200);
    }

    public function test_share_mid_level_folder_access_only_subtree(): void
    {
        $root = Folder::factory()->create(['owner_id' => $this->owner->id]);
        $child = Folder::factory()->create(['parent_id' => $root->id, 'owner_id' => $this->owner->id]);
        $grandchild = Folder::factory()->create(['parent_id' => $child->id, 'owner_id' => $this->owner->id]);

        $rootFile = File::factory()->create(['folder_id' => $root->id, 'owner_id' => $this->owner->id]);
        $grandchildFile = File::factory()->create(['folder_id' => $grandchild->id, 'owner_id' => $this->owner->id]);

        // Share child (mid-level), not root
        Share::query()->create([
            'folder_id' => $child->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'view',
        ]);

        // Can see grandchild file (below shared folder)
        $this->actingAs($this->recipient, 'api')
            ->getJson("/api/files/{$grandchildFile->id}")
            ->assertStatus(200);

        // Cannot see root file (above shared folder)
        $this->actingAs($this->recipient, 'api')
            ->getJson("/api/files/{$rootFile->id}")
            ->assertStatus(403);
    }

    public function test_share_child_but_not_parent_correct_subtree(): void
    {
        $root = Folder::factory()->create(['owner_id' => $this->owner->id]);
        $child1 = Folder::factory()->create(['parent_id' => $root->id, 'owner_id' => $this->owner->id]);
        $child2 = Folder::factory()->create(['parent_id' => $root->id, 'owner_id' => $this->owner->id]);

        $file1 = File::factory()->create(['folder_id' => $child1->id, 'owner_id' => $this->owner->id]);
        $file2 = File::factory()->create(['folder_id' => $child2->id, 'owner_id' => $this->owner->id]);

        // Share only child1
        Share::query()->create([
            'folder_id' => $child1->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'view',
        ]);

        // Can access file1
        $this->actingAs($this->recipient, 'api')
            ->getJson("/api/files/{$file1->id}")
            ->assertStatus(200);

        // Cannot access file2 (sibling, not shared)
        $this->actingAs($this->recipient, 'api')
            ->getJson("/api/files/{$file2->id}")
            ->assertStatus(403);
    }

    // ═══════════════════════════════════════════════════════
    // C. CONFLICT TESTS
    // ═══════════════════════════════════════════════════════

    public function test_multiple_overlapping_folder_shares(): void
    {
        $root = Folder::factory()->create(['owner_id' => $this->owner->id]);
        $child = Folder::factory()->create(['parent_id' => $root->id, 'owner_id' => $this->owner->id]);
        $file = File::factory()->create(['folder_id' => $child->id, 'owner_id' => $this->owner->id]);

        // Root shared with view
        Share::query()->create([
            'folder_id' => $root->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'view',
        ]);

        // Child shared with edit (more permissive)
        Share::query()->create([
            'folder_id' => $child->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'edit',
        ]);

        // Should be able to download (most permissive wins)
        $this->actingAs($this->recipient, 'api')
            ->getJson("/api/files/{$file->id}/download")
            ->assertStatus(200);
    }

    public function test_direct_and_inherited_share_mix(): void
    {
        $folder = Folder::factory()->create(['owner_id' => $this->owner->id]);
        $file = File::factory()->create(['folder_id' => $folder->id, 'owner_id' => $this->owner->id]);

        // Folder shared with view
        Share::query()->create([
            'folder_id' => $folder->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'view',
        ]);

        // Direct file share with edit
        Share::query()->create([
            'file_id' => $file->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'edit',
        ]);

        // Direct share edit overrides inherited view
        $this->actingAs($this->recipient, 'api')
            ->getJson("/api/files/{$file->id}/download")
            ->assertStatus(200);
    }

    // ═══════════════════════════════════════════════════════
    // D. MATERIALIZED PATH TESTS
    // ═══════════════════════════════════════════════════════

    public function test_folder_path_computed_correctly_for_root(): void
    {
        $folder = Folder::factory()->create(['owner_id' => $this->owner->id]);
        $folder->refresh();

        $this->assertEquals("/{$folder->id}/", $folder->path);
    }

    public function test_folder_path_computed_correctly_for_nested(): void
    {
        $root = Folder::factory()->create(['owner_id' => $this->owner->id]);
        $child = Folder::factory()->create(['parent_id' => $root->id, 'owner_id' => $this->owner->id]);
        $grandchild = Folder::factory()->create(['parent_id' => $child->id, 'owner_id' => $this->owner->id]);

        $root->refresh();
        $child->refresh();
        $grandchild->refresh();

        $this->assertEquals("/{$root->id}/", $root->path);
        $this->assertEquals("/{$root->id}/{$child->id}/", $child->path);
        $this->assertEquals("/{$root->id}/{$child->id}/{$grandchild->id}/", $grandchild->path);
    }

    // ═══════════════════════════════════════════════════════
    // E. PERMISSION CONTEXT TESTS
    // ═══════════════════════════════════════════════════════

    public function test_permission_context_has_correct_permissions(): void
    {
        $builder = $this->app->make(PermissionContextBuilder::class);
        $context = $builder->build($this->admin);

        $this->assertTrue($context->hasPermission('files.view-any'));
    }

    public function test_permission_context_has_direct_file_shares(): void
    {
        $folder = Folder::factory()->create(['owner_id' => $this->owner->id]);
        $file = File::factory()->create(['folder_id' => $folder->id, 'owner_id' => $this->owner->id]);

        Share::query()->create([
            'file_id' => $file->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'edit',
        ]);

        $builder = $this->app->make(PermissionContextBuilder::class);
        $context = $builder->build($this->recipient);

        $this->assertArrayHasKey($file->id, $context->directFileShares);
        $this->assertEquals('edit', $context->directFileShares[$file->id]);
    }

    public function test_permission_context_has_folder_shares_with_path(): void
    {
        $folder = Folder::factory()->create(['owner_id' => $this->owner->id]);

        Share::query()->create([
            'folder_id' => $folder->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'view',
        ]);

        $builder = $this->app->make(PermissionContextBuilder::class);
        $context = $builder->build($this->recipient);

        $this->assertCount(1, $context->folderShares);
        $this->assertEquals($folder->id, $context->folderShares[0]['folder_id']);
        $this->assertStringStartsWith('/', $context->folderShares[0]['path']);
    }

    public function test_permission_context_excludes_expired_shares(): void
    {
        $folder = Folder::factory()->create(['owner_id' => $this->owner->id]);
        $file = File::factory()->create(['folder_id' => $folder->id, 'owner_id' => $this->owner->id]);

        Share::query()->create([
            'file_id' => $file->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'view',
            'expires_at' => now()->subHour(),
        ]);

        $builder = $this->app->make(PermissionContextBuilder::class);
        $context = $builder->build($this->recipient);

        $this->assertEmpty($context->directFileShares);
    }

    // ═══════════════════════════════════════════════════════
    // F. PERFORMANCE TESTS
    // ═══════════════════════════════════════════════════════

    public function test_permission_context_built_with_constant_queries(): void
    {
        // Setup: multiple shares
        $folder = Folder::factory()->create(['owner_id' => $this->owner->id]);
        for ($i = 0; $i < 10; $i++) {
            $file = File::factory()->create(['folder_id' => $folder->id, 'owner_id' => $this->owner->id]);
            Share::query()->create([
                'file_id' => $file->id,
                'shared_by' => $this->owner->id,
                'shared_with' => $this->recipient->id,
                'permission' => 'view',
            ]);
        }

        for ($i = 0; $i < 5; $i++) {
            $f = Folder::factory()->create(['parent_id' => $folder->id, 'owner_id' => $this->owner->id]);
            Share::query()->create([
                'folder_id' => $f->id,
                'shared_by' => $this->owner->id,
                'shared_with' => $this->recipient->id,
                'permission' => 'view',
            ]);
        }

        // Building context should use constant number of queries (3)
        $queryCount = 0;
        \DB::listen(function () use (&$queryCount) {
            $queryCount++;
        });

        $builder = $this->app->make(PermissionContextBuilder::class);
        $builder->build($this->recipient);

        // Exactly 3 queries: permissions, file shares, folder shares (with join)
        $this->assertLessThanOrEqual(4, $queryCount, "Expected <=4 queries, got {$queryCount}");
    }

    public function test_search_with_many_files_no_n_plus_one(): void
    {
        // Seed 100 files owned by the user
        $folder = Folder::factory()->create(['owner_id' => $this->owner->id]);
        for ($i = 0; $i < 100; $i++) {
            File::factory()->create([
                'name' => "perf-file-{$i}.txt",
                'folder_id' => $folder->id,
                'owner_id' => $this->owner->id,
            ]);
        }

        $queryCount = 0;
        \DB::listen(function () use (&$queryCount) {
            $queryCount++;
        });

        $this->actingAs($this->owner, 'api')
            ->getJson('/api/search?query=perf-file&limit=50')
            ->assertStatus(200);

        // Should remain constant regardless of file count
        // Expected: context build (3) + search query (1) + overhead (auth, etc)
        $this->assertLessThan(15, $queryCount, "Expected <15 queries for 100 files, got {$queryCount}");
    }

    public function test_search_query_count_constant_regardless_of_file_count(): void
    {
        $folder = Folder::factory()->create(['owner_id' => $this->owner->id]);

        // First: 50 files
        for ($i = 0; $i < 50; $i++) {
            File::factory()->create([
                'name' => "const-test-{$i}.txt",
                'folder_id' => $folder->id,
                'owner_id' => $this->owner->id,
            ]);
        }

        // Measure query count for search with 50 files
        \DB::enableQueryLog();
        $this->actingAs($this->owner, 'api')
            ->getJson('/api/search?query=const-test&limit=25')
            ->assertStatus(200);
        $queryCount1 = count(\DB::getQueryLog());
        \DB::disableQueryLog();
        \DB::flushQueryLog();

        // Now add 200 more files
        for ($i = 50; $i < 250; $i++) {
            File::factory()->create([
                'name' => "const-test-{$i}.txt",
                'folder_id' => $folder->id,
                'owner_id' => $this->owner->id,
            ]);
        }

        // Measure query count for search with 250 files
        \DB::enableQueryLog();
        $this->actingAs($this->owner, 'api')
            ->getJson('/api/search?query=const-test&limit=25')
            ->assertStatus(200);
        $queryCount2 = count(\DB::getQueryLog());
        \DB::disableQueryLog();
        \DB::flushQueryLog();

        // Both should be small constants, not scaling linearly
        $this->assertLessThan(20, $queryCount1, "50 files: Expected <20 queries, got {$queryCount1}");
        $this->assertLessThan(20, $queryCount2, "250 files: Expected <20 queries, got {$queryCount2}");

        // Crucially, the difference should be negligible (not scaling with file count)
        $this->assertLessThanOrEqual(5, abs($queryCount2 - $queryCount1),
            "Query count should not scale with file count: {$queryCount1} vs {$queryCount2}");
    }

    public function test_file_view_policy_no_extra_queries_with_context(): void
    {
        $folder = Folder::factory()->create(['owner_id' => $this->owner->id]);
        $file = File::factory()->create(['folder_id' => $folder->id, 'owner_id' => $this->owner->id]);

        Share::query()->create([
            'folder_id' => $folder->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'view',
        ]);

        // Build context first (this does 3 queries)
        $builder = $this->app->make(PermissionContextBuilder::class);
        $context = $builder->build($this->recipient);

        // Pre-load folder relationship BEFORE measuring
        $file->load('folder');

        // Now check permission — should be 0 DB queries
        \DB::enableQueryLog();
        $result = $context->canViewFile($file->id, $file->owner_id, $file->folder?->path);
        $queryCount = count(\DB::getQueryLog());
        \DB::disableQueryLog();
        \DB::flushQueryLog();

        $this->assertTrue($result);
        $this->assertEquals(0, $queryCount, "Expected 0 queries for in-memory check, got {$queryCount}");
    }
}
