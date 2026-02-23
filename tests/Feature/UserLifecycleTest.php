<?php

namespace Tests\Feature;

use App\Jobs\DeleteR2ObjectJob;
use App\Models\File;
use App\Models\Folder;
use App\Models\Role;
use App\Models\Share;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class UserLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $targetUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $adminRole = Role::query()->where('slug', 'admin')->first();

        $this->admin = User::factory()->create();
        $this->admin->roles()->attach($adminRole);

        $this->targetUser = User::factory()->create([
            'email' => 'target@example.com',
        ]);
    }

    // ── Disable Tests ────────────────────────────────────

    public function test_admin_can_disable_active_user(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->patchJson("/api/users/{$this->targetUser->id}/disable", [
                'reason' => 'Violation of terms',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'disabled')
            ->assertJsonPath('data.disabled_reason', 'Violation of terms');

        $this->targetUser->refresh();
        $this->assertEquals('disabled', $this->targetUser->status);
        $this->assertNotNull($this->targetUser->disabled_at);
    }

    public function test_disabled_user_cannot_login(): void
    {
        $this->actingAs($this->admin, 'api')
            ->patchJson("/api/users/{$this->targetUser->id}/disable", [
                'reason' => 'Testing',
            ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'target@example.com',
            'password' => 'password',
        ]);

        $response->assertStatus(401);
    }

    public function test_disabled_user_jwt_rejected_via_middleware(): void
    {
        // Get a valid token first
        $token = auth('api')->login($this->targetUser);

        // Now disable the user (increments token_version)
        $this->targetUser->update([
            'status' => 'disabled',
            'disabled_at' => now(),
            'disabled_reason' => 'Testing middleware',
            'token_version' => $this->targetUser->token_version + 1,
        ]);

        // Old token should be rejected — use withHeaders (not actingAs)
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson('/api/auth/me');

        // Should get 403 (disabled status) or 401 (stale token)
        $this->assertContains($response->status(), [401, 403]);
    }

    public function test_admin_can_re_enable_disabled_user(): void
    {
        // Disable first
        $this->actingAs($this->admin, 'api')
            ->patchJson("/api/users/{$this->targetUser->id}/disable", [
                'reason' => 'Temporary',
            ]);

        // Re-enable
        $response = $this->actingAs($this->admin, 'api')
            ->patchJson("/api/users/{$this->targetUser->id}/enable");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'active');

        $this->targetUser->refresh();
        $this->assertEquals('active', $this->targetUser->status);
        $this->assertNull($this->targetUser->disabled_at);
        $this->assertNull($this->targetUser->disabled_reason);
    }

    public function test_re_enabled_user_can_login(): void
    {
        // Disable then re-enable
        $this->actingAs($this->admin, 'api')
            ->patchJson("/api/users/{$this->targetUser->id}/disable", [
                'reason' => 'Temp',
            ]);
        $this->actingAs($this->admin, 'api')
            ->patchJson("/api/users/{$this->targetUser->id}/enable");

        $response = $this->postJson('/api/auth/login', [
            'email' => 'target@example.com',
            'password' => 'password',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['access_token']);
    }

    public function test_cannot_disable_self(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->patchJson("/api/users/{$this->admin->id}/disable", [
                'reason' => 'Self-disable attempt',
            ]);

        $response->assertStatus(403);
    }

    public function test_non_admin_cannot_disable(): void
    {
        $userRole = Role::query()->where('slug', 'user')->first();
        $regularUser = User::factory()->create();
        $regularUser->roles()->attach($userRole);

        $response = $this->actingAs($regularUser, 'api')
            ->patchJson("/api/users/{$this->targetUser->id}/disable", [
                'reason' => 'No permission',
            ]);

        $response->assertStatus(403);
    }

    public function test_disable_requires_reason(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->patchJson("/api/users/{$this->targetUser->id}/disable");

        $response->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    public function test_disable_already_disabled_returns_422(): void
    {
        $this->actingAs($this->admin, 'api')
            ->patchJson("/api/users/{$this->targetUser->id}/disable", [
                'reason' => 'First time',
            ]);

        $response = $this->actingAs($this->admin, 'api')
            ->patchJson("/api/users/{$this->targetUser->id}/disable", [
                'reason' => 'Second time',
            ]);

        $response->assertStatus(422);
    }

    public function test_enable_already_active_returns_422(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->patchJson("/api/users/{$this->targetUser->id}/enable");

        $response->assertStatus(422);
    }

    public function test_disabled_user_shared_files_remain_accessible(): void
    {
        // Create a folder owned by target user and share it with admin
        $folder = Folder::factory()->create(['owner_id' => $this->targetUser->id]);

        Share::query()->create([
            'folder_id' => $folder->id,
            'shared_by' => $this->targetUser->id,
            'shared_with' => $this->admin->id,
            'permission' => 'view',
            'token_hash' => hash('sha256', 'test-share-token'),
        ]);

        // Disable the target user
        $this->actingAs($this->admin, 'api')
            ->patchJson("/api/users/{$this->targetUser->id}/disable", [
                'reason' => 'Testing share access',
            ]);

        // Share relationship should still exist
        $this->assertDatabaseHas('shares', [
            'folder_id' => $folder->id,
            'shared_with' => $this->admin->id,
        ]);

        // Folder should still exist
        $this->assertDatabaseHas('folders', ['id' => $folder->id]);
    }

    // ── Delete Tests ──────────────────────────────────────

    public function test_admin_can_delete_user(): void
    {
        Queue::fake([DeleteR2ObjectJob::class]);

        $response = $this->actingAs($this->admin, 'api')
            ->deleteJson("/api/users/{$this->targetUser->id}", [
                'confirm' => true,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'User permanently deleted.');

        $this->assertDatabaseMissing('users', ['id' => $this->targetUser->id]);
    }

    public function test_delete_removes_all_files(): void
    {
        Queue::fake([DeleteR2ObjectJob::class]);

        $folder = Folder::factory()->create(['owner_id' => $this->targetUser->id]);
        $file1 = File::factory()->create([
            'owner_id' => $this->targetUser->id,
            'folder_id' => $folder->id,
            'r2_object_key' => 'test/file1.txt',
        ]);
        $file2 = File::factory()->create([
            'owner_id' => $this->targetUser->id,
            'folder_id' => $folder->id,
            'r2_object_key' => 'test/file2.txt',
        ]);

        $this->actingAs($this->admin, 'api')
            ->deleteJson("/api/users/{$this->targetUser->id}", [
                'confirm' => true,
            ]);

        $this->assertDatabaseMissing('files', ['id' => $file1->id]);
        $this->assertDatabaseMissing('files', ['id' => $file2->id]);

        Queue::assertPushed(DeleteR2ObjectJob::class, 2);
    }

    public function test_delete_removes_all_shares(): void
    {
        Queue::fake([DeleteR2ObjectJob::class]);

        $folder = Folder::factory()->create(['owner_id' => $this->targetUser->id]);
        $share = Share::query()->create([
            'folder_id' => $folder->id,
            'shared_by' => $this->targetUser->id,
            'shared_with' => $this->admin->id,
            'permission' => 'view',
            'token_hash' => hash('sha256', 'test-share-token-2'),
        ]);

        $this->actingAs($this->admin, 'api')
            ->deleteJson("/api/users/{$this->targetUser->id}", [
                'confirm' => true,
            ]);

        $this->assertDatabaseMissing('shares', ['id' => $share->id]);
    }

    public function test_delete_removes_all_folders(): void
    {
        Queue::fake([DeleteR2ObjectJob::class]);

        $parent = Folder::factory()->create(['owner_id' => $this->targetUser->id]);
        $child = Folder::factory()->create([
            'owner_id' => $this->targetUser->id,
            'parent_id' => $parent->id,
        ]);

        $this->actingAs($this->admin, 'api')
            ->deleteJson("/api/users/{$this->targetUser->id}", [
                'confirm' => true,
            ]);

        $this->assertDatabaseMissing('folders', ['id' => $parent->id]);
        $this->assertDatabaseMissing('folders', ['id' => $child->id]);
    }

    public function test_delete_returns_stats(): void
    {
        Queue::fake([DeleteR2ObjectJob::class]);

        $folder = Folder::factory()->create(['owner_id' => $this->targetUser->id]);
        File::factory()->count(3)->create([
            'owner_id' => $this->targetUser->id,
            'folder_id' => $folder->id,
        ]);

        $response = $this->actingAs($this->admin, 'api')
            ->deleteJson("/api/users/{$this->targetUser->id}", [
                'confirm' => true,
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'stats' => ['files_deleted', 'folders_deleted', 'shares_deleted', 'r2_objects_deleted'],
            ]);

        $this->assertEquals(3, $response->json('stats.files_deleted'));
        $this->assertEquals(1, $response->json('stats.folders_deleted'));
    }

    public function test_cannot_delete_self(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->deleteJson("/api/users/{$this->admin->id}", [
                'confirm' => true,
            ]);

        $response->assertStatus(403);
    }

    public function test_non_admin_cannot_delete(): void
    {
        $userRole = Role::query()->where('slug', 'user')->first();
        $regularUser = User::factory()->create();
        $regularUser->roles()->attach($userRole);

        $response = $this->actingAs($regularUser, 'api')
            ->deleteJson("/api/users/{$this->targetUser->id}", [
                'confirm' => true,
            ]);

        $response->assertStatus(403);
    }

    public function test_delete_requires_confirm(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->deleteJson("/api/users/{$this->targetUser->id}");

        $response->assertStatus(422)
            ->assertJsonValidationErrors('confirm');
    }

    public function test_delete_async_returns_202(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->admin, 'api')
            ->deleteJson("/api/users/{$this->targetUser->id}", [
                'confirm' => true,
                'async' => true,
            ]);

        $response->assertStatus(202)
            ->assertJsonPath('message', 'User deletion scheduled.');

        // User should be disabled (not yet deleted)
        $this->targetUser->refresh();
        $this->assertEquals('disabled', $this->targetUser->status);
    }

    public function test_delete_also_removes_soft_deleted_files(): void
    {
        Queue::fake([DeleteR2ObjectJob::class]);

        $folder = Folder::factory()->create(['owner_id' => $this->targetUser->id]);

        // Active file
        File::factory()->create([
            'owner_id' => $this->targetUser->id,
            'folder_id' => $folder->id,
            'r2_object_key' => 'test/active.txt',
        ]);

        // Soft-deleted file
        File::factory()->create([
            'owner_id' => $this->targetUser->id,
            'folder_id' => $folder->id,
            'r2_object_key' => 'test/trashed.txt',
            'deleted_at' => now(),
            'deleted_by' => $this->targetUser->id,
            'purge_at' => now()->addDays(30),
        ]);

        $response = $this->actingAs($this->admin, 'api')
            ->deleteJson("/api/users/{$this->targetUser->id}", [
                'confirm' => true,
            ]);

        $response->assertStatus(200);
        $this->assertEquals(2, $response->json('stats.files_deleted'));

        Queue::assertPushed(DeleteR2ObjectJob::class, 2);
    }

    public function test_user_resource_includes_status_fields(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->getJson("/api/users/{$this->targetUser->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.disabled_at', null);
    }

    public function test_token_version_incremented_on_disable(): void
    {
        $originalVersion = $this->targetUser->token_version;

        $this->actingAs($this->admin, 'api')
            ->patchJson("/api/users/{$this->targetUser->id}/disable", [
                'reason' => 'Token test',
            ]);

        $this->targetUser->refresh();
        $this->assertEquals($originalVersion + 1, $this->targetUser->token_version);
    }

    // ── Reset Password Tests ────────────────────────────────

    public function test_admin_can_reset_user_password(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->patchJson("/api/users/{$this->targetUser->id}/reset-password", [
                'new_password' => 'NewSecure123!',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Password has been reset.');
    }

    public function test_user_can_login_with_new_password_after_reset(): void
    {
        $this->actingAs($this->admin, 'api')
            ->patchJson("/api/users/{$this->targetUser->id}/reset-password", [
                'new_password' => 'NewSecure123!',
            ]);

        // Old password should fail
        $this->postJson('/api/auth/login', [
            'email' => 'target@example.com',
            'password' => 'password',
        ])->assertStatus(401);

        // New password should work
        $this->postJson('/api/auth/login', [
            'email' => 'target@example.com',
            'password' => 'NewSecure123!',
        ])->assertStatus(200)
            ->assertJsonStructure(['access_token']);
    }

    public function test_reset_password_invalidates_existing_tokens(): void
    {
        $originalVersion = $this->targetUser->token_version;

        $this->actingAs($this->admin, 'api')
            ->patchJson("/api/users/{$this->targetUser->id}/reset-password", [
                'new_password' => 'NewSecure123!',
            ]);

        $this->targetUser->refresh();
        $this->assertEquals($originalVersion + 1, $this->targetUser->token_version);
    }

    public function test_cannot_reset_own_password_via_admin_endpoint(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->patchJson("/api/users/{$this->admin->id}/reset-password", [
                'new_password' => 'NewSecure123!',
            ]);

        $response->assertStatus(403);
    }

    public function test_non_admin_cannot_reset_password(): void
    {
        $userRole = Role::query()->where('slug', 'user')->first();
        $regularUser = User::factory()->create();
        $regularUser->roles()->attach($userRole);

        $response = $this->actingAs($regularUser, 'api')
            ->patchJson("/api/users/{$this->targetUser->id}/reset-password", [
                'new_password' => 'NewSecure123!',
            ]);

        $response->assertStatus(403);
    }

    public function test_reset_password_validates_min_length(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->patchJson("/api/users/{$this->targetUser->id}/reset-password", [
                'new_password' => 'short',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('new_password');
    }

    public function test_reset_password_requires_new_password(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->patchJson("/api/users/{$this->targetUser->id}/reset-password");

        $response->assertStatus(422)
            ->assertJsonValidationErrors('new_password');
    }
}
