<?php

namespace Tests\Integration;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Integration — Authentication & Role Tests.
 */
class AuthRoleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $adminRole = Role::query()->where('slug', 'admin')->firstOrFail();
        $userRole = Role::query()->where('slug', 'user')->firstOrFail();

        // Let the User model auto-hash via $casts
        $this->admin = User::factory()->create(['password' => 'password123']);
        $this->admin->roles()->attach($adminRole);

        $this->user = User::factory()->create(['password' => 'password123']);
        $this->user->roles()->attach($userRole);
    }

    // ── Login ────────────────────────────────────────────

    public function test_login_returns_jwt_token(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => $this->user->email,
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['access_token', 'token_type', 'expires_in']);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => $this->user->email,
            'password' => 'wrong',
        ]);

        $response->assertStatus(401);
    }

    // ── Refresh ──────────────────────────────────────────

    public function test_refresh_extends_token(): void
    {
        $token = auth('api')->login($this->user);

        $response = $this->postJson('/api/auth/refresh', [], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['access_token', 'token_type', 'expires_in']);
    }

    // ── Me ───────────────────────────────────────────────

    public function test_me_returns_authenticated_user(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/auth/me');

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $this->user->id);
    }

    public function test_me_fails_without_auth(): void
    {
        $response = $this->getJson('/api/auth/me');
        $response->assertStatus(401);
    }

    // ── Role Enforcement ─────────────────────────────────

    public function test_regular_user_cannot_access_admin_config(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/admin/system/config');

        $response->assertStatus(403);
    }

    public function test_admin_can_access_admin_config(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/system/config');

        $response->assertStatus(200);
    }

    public function test_regular_user_cannot_create_other_users(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/users', [
                'name' => 'New User',
                'email' => 'new@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ]);

        $response->assertStatus(403);
    }

    public function test_admin_can_create_users(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->postJson('/api/users', [
                'name' => 'New User',
                'email' => 'new@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ]);

        $response->assertStatus(201);
    }
}
