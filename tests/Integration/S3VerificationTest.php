<?php

namespace Tests\Integration;

use App\Models\File;
use App\Models\Folder;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\S3CleanupTrait;

/**
 * Integration — S3 Verification Tests.
 *
 * Requires real R2/S3 credentials in .env.
 */
class S3VerificationTest extends TestCase
{
    use RefreshDatabase;
    use S3CleanupTrait;

    private User $user;

    private Folder $folder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootRealR2();

        $this->seed(RolePermissionSeeder::class);

        $userRole = Role::query()->where('slug', 'user')->firstOrFail();

        $this->user = User::factory()->create([
            'quota_used_bytes' => 0,
            'quota_limit_bytes' => 104857600, // 100 MB
        ]);
        $this->user->roles()->attach($userRole);

        $this->folder = Folder::factory()->create(['owner_id' => $this->user->id]);
    }

    // ── Direct Put Object ────────────────────────────────

    public function test_direct_put_object_succeeds(): void
    {
        $key = "test/{$this->user->id}/".uniqid('s3v_').'/hello.txt';
        $body = 'Hello from vDrive integration test';

        $this->putTestObject($key, $body);

        $this->assertS3ObjectExists($key);
    }

    // ── Object Size Matches ──────────────────────────────

    public function test_object_size_matches_content(): void
    {
        $key = "test/{$this->user->id}/".uniqid('s3v_').'/sized.txt';
        $body = str_repeat('A', 5000); // 5 KB

        $this->putTestObject($key, $body);

        $size = $this->getS3ObjectSize($key);
        $this->assertEquals(5000, $size, 'S3 object size must match uploaded content size');
    }

    // ── Object Remains After Soft Delete ─────────────────

    public function test_object_remains_after_soft_delete(): void
    {
        $objectKey = "test/{$this->user->id}/".uniqid('s3v_').'/soft.txt';
        $this->putTestObject($objectKey, 'soft-delete-test');

        $file = File::factory()->create([
            'folder_id' => $this->folder->id,
            'owner_id' => $this->user->id,
            'size_bytes' => 16,
            'r2_object_key' => $objectKey,
        ]);

        // Soft delete
        $this->actingAs($this->user, 'api')
            ->deleteJson("/api/files/{$file->id}")
            ->assertStatus(204);

        $this->assertS3ObjectExists($objectKey, 'S3 object must remain after soft delete');
    }

    // ── Object Removed After Force Delete ────────────────

    public function test_object_removed_after_force_delete(): void
    {
        $objectKey = "test/{$this->user->id}/".uniqid('s3v_').'/force.txt';
        $this->putTestObject($objectKey, 'force-delete-test');

        $file = File::factory()->create([
            'folder_id' => $this->folder->id,
            'owner_id' => $this->user->id,
            'size_bytes' => 17,
            'r2_object_key' => $objectKey,
        ]);

        // Force delete
        $this->actingAs($this->user, 'api')
            ->deleteJson("/api/trash/files/{$file->id}")
            ->assertStatus(204);

        $this->assertS3ObjectNotExists($objectKey, 'S3 object must be removed after force delete');

        $this->trackedObjectKeys = array_filter(
            $this->trackedObjectKeys,
            fn ($k) => $k !== $objectKey
        );
    }

    // ── Cleanup Verification ─────────────────────────────

    public function test_no_orphan_objects_after_cleanup(): void
    {
        $keys = [];
        for ($i = 0; $i < 3; $i++) {
            $key = "test/{$this->user->id}/".uniqid('orphan_')."/file{$i}.txt";
            $this->putTestObject($key, "orphan-test-{$i}");
            $keys[] = $key;
        }

        // Manually clean
        $this->cleanupS3Objects();

        // All should be gone
        foreach ($keys as $key) {
            $this->assertS3ObjectNotExists($key, "Orphan object [{$key}] should be cleaned up");
        }
    }
}
