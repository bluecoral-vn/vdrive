<?php

namespace Tests\Integration;

use App\Jobs\DeleteR2ObjectJob;
use App\Models\File;
use App\Models\Folder;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\S3CleanupTrait;

/**
 * Integration — Soft Delete & Purge with Real S3 Verification.
 *
 * These tests require real R2/S3 credentials in .env.
 */
class SoftDeletePurgeS3Test extends TestCase
{
    use RefreshDatabase;
    use S3CleanupTrait;

    private User $owner;

    private Folder $folder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootRealR2();

        $this->seed(RolePermissionSeeder::class);

        $userRole = Role::query()->where('slug', 'user')->firstOrFail();

        $this->owner = User::factory()->create(['quota_used_bytes' => 10485760]);
        $this->owner->roles()->attach($userRole);

        $this->folder = Folder::factory()->create(['owner_id' => $this->owner->id]);
    }

    /**
     * Create a file record with a real S3 object (<1KB test payload).
     */
    private function createFileWithS3Object(string $body = 'vdrive-test-data'): File
    {
        $objectKey = "test/{$this->owner->id}/".uniqid('integ_').'/test.txt';

        // Upload to real S3
        $this->s3Client->putObject([
            'Bucket' => $this->s3Bucket,
            'Key' => $objectKey,
            'Body' => $body,
            'ContentType' => 'text/plain',
        ]);
        $this->trackObject($objectKey);

        // Create DB record
        return File::factory()->create([
            'folder_id' => $this->folder->id,
            'owner_id' => $this->owner->id,
            'size_bytes' => strlen($body),
            'r2_object_key' => $objectKey,
        ]);
    }

    // ── Soft Delete ──────────────────────────────────────

    public function test_soft_delete_hides_item_but_s3_object_remains(): void
    {
        $file = $this->createFileWithS3Object();

        // Soft delete via API
        $this->actingAs($this->owner, 'api')
            ->deleteJson("/api/files/{$file->id}")
            ->assertStatus(204);

        // File is trashed in DB
        $file->refresh();
        $this->assertTrue($file->isTrashed());

        // S3 object STILL exists
        $this->assertS3ObjectExists($file->r2_object_key, 'Soft delete must NOT remove S3 object');
    }

    // ── Restore ──────────────────────────────────────────

    public function test_restore_works_correctly(): void
    {
        $file = $this->createFileWithS3Object();

        // Soft delete
        $this->actingAs($this->owner, 'api')
            ->deleteJson("/api/files/{$file->id}");

        // Restore
        $this->actingAs($this->owner, 'api')
            ->postJson("/api/trash/files/{$file->id}/restore")
            ->assertStatus(200);

        $file->refresh();
        $this->assertFalse($file->isTrashed());

        // S3 object still accessible
        $this->assertS3ObjectExists($file->r2_object_key);
    }

    // ── Force Delete ─────────────────────────────────────

    public function test_force_delete_removes_s3_object(): void
    {
        $file = $this->createFileWithS3Object();
        $objectKey = $file->r2_object_key;

        // Force delete (QUEUE_CONNECTION=sync so DeleteR2ObjectJob runs immediately)
        $this->actingAs($this->owner, 'api')
            ->deleteJson("/api/trash/files/{$file->id}")
            ->assertStatus(204);

        // DB record gone
        $this->assertDatabaseMissing('files', ['id' => $file->id]);

        // S3 object removed
        $this->assertS3ObjectNotExists($objectKey, 'Force delete should remove S3 object');

        // Don't need cleanup — already deleted
        $this->trackedObjectKeys = array_filter(
            $this->trackedObjectKeys,
            fn ($k) => $k !== $objectKey
        );
    }

    // ── Purge ────────────────────────────────────────────

    public function test_purge_removes_s3_object_and_metadata(): void
    {
        $file = $this->createFileWithS3Object();
        $objectKey = $file->r2_object_key;

        // Mark as expired
        $file->update([
            'deleted_at' => now()->subDays(20),
            'deleted_by' => $this->owner->id,
            'purge_at' => now()->subDays(5),
        ]);

        // Run purge (sync)
        $this->artisan('trash:purge')
            ->assertExitCode(0);

        // DB record gone
        $this->assertDatabaseMissing('files', ['id' => $file->id]);

        // S3 object removed
        $this->assertS3ObjectNotExists($objectKey, 'Purge should remove S3 object');

        $this->trackedObjectKeys = array_filter(
            $this->trackedObjectKeys,
            fn ($k) => $k !== $objectKey
        );
    }

    public function test_purge_processes_batch(): void
    {
        $objectKeys = [];

        for ($i = 0; $i < 5; $i++) {
            $file = $this->createFileWithS3Object("batch-item-{$i}");
            $file->update([
                'deleted_at' => now()->subDays(20),
                'deleted_by' => $this->owner->id,
                'purge_at' => now()->subDays(5),
            ]);
            $objectKeys[] = $file->r2_object_key;
        }

        $this->artisan('trash:purge')
            ->assertExitCode(0);

        foreach ($objectKeys as $key) {
            $this->assertS3ObjectNotExists($key, "Batch purge should remove object [{$key}]");
        }

        // Clean tracked keys list
        $this->trackedObjectKeys = array_diff($this->trackedObjectKeys, $objectKeys);
    }
}
