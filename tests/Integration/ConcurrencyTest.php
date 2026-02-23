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
 * Integration — Concurrency Tests.
 */
class ConcurrencyTest extends TestCase
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
            'quota_limit_bytes' => 104857600,
        ]);
        $this->user->roles()->attach($userRole);

        $this->folder = Folder::factory()->create(['owner_id' => $this->user->id]);
    }

    // ── Parallel Upload Init (No Collision) ──────────────

    public function test_parallel_upload_init_produces_unique_keys(): void
    {
        $sessions = [];

        // Simulate 10 rapid upload inits
        for ($i = 0; $i < 10; $i++) {
            $response = $this->actingAs($this->user, 'api')
                ->postJson('/api/upload/init', [
                    'filename' => "concurrent-{$i}.txt",
                    'mime_type' => 'text/plain',
                    'size_bytes' => 100,
                    'folder_id' => $this->folder->id,
                ]);

            $response->assertStatus(201);
            $sessions[] = $response->json('data');
        }

        // All session IDs must be unique
        $sessionIds = array_column($sessions, 'session_id');
        $this->assertCount(10, array_unique($sessionIds), 'All upload sessions must have unique IDs');

        // Abort all sessions to clean up R2 multipart uploads
        foreach ($sessions as $session) {
            $this->actingAs($this->user, 'api')
                ->postJson('/api/upload/abort', [
                    'session_id' => $session['session_id'],
                ]);
        }
    }

    // ── No Orphan S3 Objects After Abort ─────────────────

    public function test_no_orphan_objects_after_abort(): void
    {
        // Create a file with a real S3 object
        $objectKey = "test/{$this->user->id}/".uniqid('conc_').'/orphan-check.txt';
        $this->putTestObject($objectKey, 'orphan-check');

        $file = File::factory()->create([
            'folder_id' => $this->folder->id,
            'owner_id' => $this->user->id,
            'size_bytes' => 12,
            'r2_object_key' => $objectKey,
        ]);

        // Force delete removes S3 object
        $this->actingAs($this->user, 'api')
            ->deleteJson("/api/trash/files/{$file->id}")
            ->assertStatus(204);

        // Verify S3 object is gone
        $this->assertS3ObjectNotExists($objectKey, 'No orphan objects should remain');

        $this->trackedObjectKeys = array_filter(
            $this->trackedObjectKeys,
            fn ($k) => $k !== $objectKey
        );
    }

    // ── Rapid Soft Delete + Restore ──────────────────────

    public function test_rapid_soft_delete_and_restore_cycle(): void
    {
        $objectKey = "test/{$this->user->id}/".uniqid('cycle_').'/cycle.txt';
        $this->putTestObject($objectKey, 'cycle-test');

        $file = File::factory()->create([
            'folder_id' => $this->folder->id,
            'owner_id' => $this->user->id,
            'size_bytes' => 10,
            'r2_object_key' => $objectKey,
        ]);

        // 5 rapid cycles of soft delete + restore
        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($this->user, 'api')
                ->deleteJson("/api/files/{$file->id}")
                ->assertStatus(204);

            $this->actingAs($this->user, 'api')
                ->postJson("/api/trash/files/{$file->id}/restore")
                ->assertStatus(200);
        }

        // File should be alive and S3 object intact
        $file->refresh();
        $this->assertFalse($file->isTrashed());
        $this->assertS3ObjectExists($objectKey, 'S3 object must survive rapid delete/restore cycles');
    }
}
