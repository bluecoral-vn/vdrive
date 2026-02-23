<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $regularUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $adminRole = Role::query()->where('slug', 'admin')->firstOrFail();
        $userRole = Role::query()->where('slug', 'user')->firstOrFail();

        $this->admin = User::factory()->create();
        $this->admin->roles()->attach($adminRole);

        $this->regularUser = User::factory()->create();
        $this->regularUser->roles()->attach($userRole);
    }

    public function test_admin_can_list_users(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->getJson('/api/users');

        $response->assertStatus(200)
            ->assertJsonStructure(['data', 'meta']);
    }

    public function test_regular_user_cannot_list_users(): void
    {
        $response = $this->actingAs($this->regularUser, 'api')
            ->getJson('/api/users');

        $response->assertStatus(403);
    }

    public function test_admin_list_users_returns_full_fields(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->getJson('/api/users');

        $response->assertStatus(200)
            ->assertJsonStructure(['data', 'meta']);

        // Admin should see all fields including roles, status, quota
        $firstUser = $response->json('data.0');
        $this->assertArrayHasKey('id', $firstUser);
        $this->assertArrayHasKey('name', $firstUser);
        $this->assertArrayHasKey('email', $firstUser);
        $this->assertArrayHasKey('status', $firstUser);
        $this->assertArrayHasKey('quota_limit_bytes', $firstUser);
        $this->assertArrayHasKey('quota_used_bytes', $firstUser);
        $this->assertArrayHasKey('roles', $firstUser);
        $this->assertArrayHasKey('created_at', $firstUser);
        $this->assertArrayHasKey('updated_at', $firstUser);
    }

    public function test_admin_can_create_user(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->postJson('/api/users', [
                'name' => 'New User',
                'email' => 'new@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'New User')
            ->assertJsonPath('data.email', 'new@example.com');

        $this->assertDatabaseHas('users', ['email' => 'new@example.com']);
    }

    public function test_admin_can_create_user_with_roles(): void
    {
        $userRole = Role::query()->where('slug', 'user')->firstOrFail();

        $response = $this->actingAs($this->admin, 'api')
            ->postJson('/api/users', [
                'name' => 'Role User',
                'email' => 'roleuser@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'roles' => [$userRole->id],
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.roles.0.slug', 'user');
    }

    public function test_regular_user_cannot_create_user(): void
    {
        $response = $this->actingAs($this->regularUser, 'api')
            ->postJson('/api/users', [
                'name' => 'Blocked User',
                'email' => 'blocked@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ]);

        $response->assertStatus(403);
    }

    public function test_admin_can_view_any_user(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->getJson("/api/users/{$this->regularUser->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $this->regularUser->id);
    }

    public function test_user_can_view_self(): void
    {
        $response = $this->actingAs($this->regularUser, 'api')
            ->getJson("/api/users/{$this->regularUser->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $this->regularUser->id);
    }

    public function test_user_cannot_view_other_user(): void
    {
        $response = $this->actingAs($this->regularUser, 'api')
            ->getJson("/api/users/{$this->admin->id}");

        $response->assertStatus(403);
    }

    public function test_admin_can_update_user(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->putJson("/api/users/{$this->regularUser->id}", [
                'name' => 'Updated Name',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'Updated Name');
    }

    public function test_user_can_update_self(): void
    {
        $response = $this->actingAs($this->regularUser, 'api')
            ->putJson("/api/users/{$this->regularUser->id}", [
                'name' => 'Self Updated',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'Self Updated');
    }

    public function test_user_cannot_update_other_user(): void
    {
        $response = $this->actingAs($this->regularUser, 'api')
            ->putJson("/api/users/{$this->admin->id}", [
                'name' => 'Hacked',
            ]);

        $response->assertStatus(403);
    }

    public function test_admin_can_delete_user(): void
    {
        $targetUser = User::factory()->create();

        $response = $this->actingAs($this->admin, 'api')
            ->deleteJson("/api/users/{$targetUser->id}", ['confirm' => true]);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'User permanently deleted.');
        $this->assertDatabaseMissing('users', ['id' => $targetUser->id]);
    }

    public function test_user_cannot_delete_self(): void
    {
        $response = $this->actingAs($this->regularUser, 'api')
            ->deleteJson("/api/users/{$this->regularUser->id}");

        $response->assertStatus(403);
    }

    public function test_user_cannot_delete_other_user(): void
    {
        $response = $this->actingAs($this->regularUser, 'api')
            ->deleteJson("/api/users/{$this->admin->id}");

        $response->assertStatus(403);
    }

    public function test_admin_cannot_delete_self(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->deleteJson("/api/users/{$this->admin->id}");

        $response->assertStatus(403);
    }

    public function test_create_user_validates_input(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->postJson('/api/users', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email', 'password']);
    }

    public function test_create_user_validates_unique_email(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->postJson('/api/users', [
                'name' => 'Duplicate',
                'email' => $this->admin->email,
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_unauthenticated_cannot_access_users(): void
    {
        $response = $this->getJson('/api/users');

        $response->assertUnauthorized();
    }

    // ── Quota Management ─────────────────────────────────

    public function test_admin_can_create_user_with_quota(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->postJson('/api/users', [
                'name' => 'Quota User',
                'email' => 'quota@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'quota_limit_bytes' => 104857600, // 100 MB
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.quota_limit_bytes', 104857600);

        // quota_used_bytes should be 0 (default) for new user
        $this->assertEquals(0, $response->json('data.quota_used_bytes'));

        $this->assertDatabaseHas('users', [
            'email' => 'quota@example.com',
            'quota_limit_bytes' => 104857600,
        ]);
    }

    public function test_admin_can_update_user_quota(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->putJson("/api/users/{$this->regularUser->id}", [
                'quota_limit_bytes' => 52428800, // 50 MB
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.quota_limit_bytes', 52428800);

        $this->regularUser->refresh();
        $this->assertEquals(52428800, $this->regularUser->quota_limit_bytes);
    }

    public function test_admin_can_set_quota_to_null(): void
    {
        $this->regularUser->update(['quota_limit_bytes' => 104857600]);

        $response = $this->actingAs($this->admin, 'api')
            ->putJson("/api/users/{$this->regularUser->id}", [
                'quota_limit_bytes' => null,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.quota_limit_bytes', null);

        $this->regularUser->refresh();
        $this->assertNull($this->regularUser->quota_limit_bytes);
    }

    public function test_user_resource_includes_quota_fields(): void
    {
        $this->regularUser->update([
            'quota_limit_bytes' => 104857600,
            'quota_used_bytes' => 5242880,
        ]);

        $response = $this->actingAs($this->admin, 'api')
            ->getJson("/api/users/{$this->regularUser->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.quota_limit_bytes', 104857600)
            ->assertJsonPath('data.quota_used_bytes', 5242880);
    }
}
