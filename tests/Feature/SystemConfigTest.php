<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\SystemConfigService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SystemConfigTest extends TestCase
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

    public function test_admin_can_view_system_config(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/system/config');

        $response->assertStatus(200)
            ->assertJsonStructure(['data']);
    }

    public function test_regular_user_cannot_view_system_config(): void
    {
        $response = $this->actingAs($this->regularUser, 'api')
            ->getJson('/api/admin/system/config');

        $response->assertStatus(403);
    }

    public function test_admin_can_update_system_config(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->putJson('/api/admin/system/config', [
                'configs' => [
                    ['key' => 'r2_endpoint', 'value' => 'https://r2.example.com'],
                    ['key' => 'r2_bucket', 'value' => 'my-bucket'],
                    ['key' => 'upload_chunk_size', 'value' => '5242880'],
                    ['key' => 'max_items_per_folder', 'value' => '1000'],
                ],
            ]);

        $response->assertStatus(200)
            ->assertJson(['message' => 'Configuration updated.']);

        $this->assertDatabaseHas('system_configs', [
            'key' => 'r2_endpoint',
            'value' => 'https://r2.example.com',
        ]);
        $this->assertDatabaseHas('system_configs', [
            'key' => 'r2_bucket',
            'value' => 'my-bucket',
        ]);
    }

    public function test_sensitive_keys_are_encrypted(): void
    {
        $this->actingAs($this->admin, 'api')
            ->putJson('/api/admin/system/config', [
                'configs' => [
                    ['key' => 'r2_secret_key', 'value' => 'my-secret-value'],
                ],
            ]);

        $config = \App\Models\SystemConfig::query()->where('key', 'r2_secret_key')->first();

        $this->assertTrue($config->is_secret);
        $this->assertNotEquals('my-secret-value', $config->value);

        $service = app(SystemConfigService::class);
        $this->assertEquals('my-secret-value', $service->get('r2_secret_key'));
    }

    public function test_secrets_are_masked_in_listing(): void
    {
        $this->actingAs($this->admin, 'api')
            ->putJson('/api/admin/system/config', [
                'configs' => [
                    ['key' => 'r2_secret_key', 'value' => 'super-secret'],
                    ['key' => 'r2_endpoint', 'value' => 'https://r2.example.com'],
                ],
            ]);

        $response = $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/system/config');

        $data = collect($response->json('data'));

        $secret = $data->firstWhere('key', 'r2_secret_key');
        $this->assertEquals('••••••••', $secret['value']);
        $this->assertTrue($secret['is_secret']);

        $endpoint = $data->firstWhere('key', 'r2_endpoint');
        $this->assertEquals('https://r2.example.com', $endpoint['value']);
        $this->assertFalse($endpoint['is_secret']);
    }

    public function test_regular_user_cannot_update_system_config(): void
    {
        $response = $this->actingAs($this->regularUser, 'api')
            ->putJson('/api/admin/system/config', [
                'configs' => [
                    ['key' => 'r2_endpoint', 'value' => 'https://hacked.com'],
                ],
            ]);

        $response->assertStatus(403);
    }

    public function test_invalid_config_key_is_rejected(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->putJson('/api/admin/system/config', [
                'configs' => [
                    ['key' => 'invalid_key', 'value' => 'some-value'],
                ],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['configs.0.key']);
    }

    public function test_config_value_can_be_null(): void
    {
        $this->actingAs($this->admin, 'api')
            ->putJson('/api/admin/system/config', [
                'configs' => [
                    ['key' => 'r2_endpoint', 'value' => 'https://r2.example.com'],
                ],
            ]);

        $response = $this->actingAs($this->admin, 'api')
            ->putJson('/api/admin/system/config', [
                'configs' => [
                    ['key' => 'r2_endpoint', 'value' => null],
                ],
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('system_configs', [
            'key' => 'r2_endpoint',
            'value' => null,
        ]);
    }

    public function test_unauthenticated_cannot_access_config(): void
    {
        $response = $this->getJson('/api/admin/system/config');
        $response->assertUnauthorized();
    }

    public function test_service_get_returns_decrypted_value(): void
    {
        $service = app(SystemConfigService::class);

        $service->set('r2_access_key', 'access-key-123');

        $this->assertEquals('access-key-123', $service->get('r2_access_key'));
    }

    public function test_service_get_returns_null_for_missing_key(): void
    {
        $service = app(SystemConfigService::class);

        $this->assertNull($service->get('upload_chunk_size'));
    }

    public function test_update_response_masks_secrets(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->putJson('/api/admin/system/config', [
                'configs' => [
                    ['key' => 'r2_access_key', 'value' => 'access-key-123'],
                    ['key' => 'r2_secret_key', 'value' => 'secret-key-456'],
                    ['key' => 'smtp_password', 'value' => 'smtp-pass-789'],
                    ['key' => 'r2_endpoint', 'value' => 'https://r2.example.com'],
                ],
            ]);

        $response->assertStatus(200);

        $data = collect($response->json('data'));

        // Secrets must be masked in PUT response
        $this->assertEquals('••••••••', $data->firstWhere('key', 'r2_access_key')['value']);
        $this->assertEquals('••••••••', $data->firstWhere('key', 'r2_secret_key')['value']);
        $this->assertEquals('••••••••', $data->firstWhere('key', 'smtp_password')['value']);

        // Non-secrets must be visible
        $this->assertEquals('https://r2.example.com', $data->firstWhere('key', 'r2_endpoint')['value']);
    }

    public function test_masked_placeholder_does_not_overwrite_real_secret(): void
    {
        $service = app(SystemConfigService::class);

        // Store real credentials
        $service->set('r2_secret_key', 'real-secret-key');
        $service->set('r2_access_key', 'real-access-key');
        $service->set('smtp_password', 'real-smtp-password');

        // Simulate frontend sending back masked placeholders alongside other changes
        $this->actingAs($this->admin, 'api')
            ->putJson('/api/admin/system/config', [
                'configs' => [
                    ['key' => 'r2_secret_key', 'value' => '••••••••'],
                    ['key' => 'r2_access_key', 'value' => '••••••••'],
                    ['key' => 'smtp_password', 'value' => '••••••••'],
                    ['key' => 'r2_endpoint', 'value' => 'https://updated.example.com'],
                ],
            ])
            ->assertStatus(200);

        // Real credentials must be preserved — not overwritten with mask
        $this->assertEquals('real-secret-key', $service->get('r2_secret_key'));
        $this->assertEquals('real-access-key', $service->get('r2_access_key'));
        $this->assertEquals('real-smtp-password', $service->get('smtp_password'));

        // Non-secret should be updated normally
        $this->assertEquals('https://updated.example.com', $service->get('r2_endpoint'));
    }
}
