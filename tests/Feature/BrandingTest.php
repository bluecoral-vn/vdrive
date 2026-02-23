<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\SystemConfig;
use App\Models\User;
use App\Services\R2ClientService;
use Aws\CommandInterface;
use Aws\S3\S3Client;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Mockery;
use Tests\TestCase;

class BrandingTest extends TestCase
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

        $this->mockR2Client();
    }

    private function mockR2Client(): void
    {
        $mockS3 = Mockery::mock(S3Client::class);
        $mockS3->shouldReceive('putObject')->andReturn([])->byDefault();
        $mockS3->shouldReceive('deleteObject')->andReturn([])->byDefault();
        $mockS3->shouldReceive('getCommand')
            ->andReturn(Mockery::mock(CommandInterface::class))
            ->byDefault();

        $mockRequest = Mockery::mock(\Psr\Http\Message\RequestInterface::class);
        $mockRequest->shouldReceive('getUri')
            ->andReturn('https://r2.example.com/branding/mock.png?presigned=1')
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

    // ─── Admin GET /api/admin/settings/branding ───

    public function test_admin_can_view_branding(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/settings/branding');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => ['app_name', 'copyright_text', 'tag_line', 'logo_url', 'favicon_url'],
            ]);
    }

    public function test_branding_returns_defaults_when_not_configured(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/settings/branding');

        $response->assertStatus(200)
            ->assertJsonPath('data.app_name', 'Blue Coral')
            ->assertJsonPath('data.copyright_text', '© 2017–2026 Blue Coral. All rights reserved.')
            ->assertJsonPath('data.tag_line', 'Digital agency from Saigon')
            ->assertJsonPath('data.logo_url', null)
            ->assertJsonPath('data.favicon_url', null);
    }

    public function test_regular_user_cannot_view_branding(): void
    {
        $response = $this->actingAs($this->regularUser, 'api')
            ->getJson('/api/admin/settings/branding');

        $response->assertStatus(403);
    }

    public function test_unauthenticated_cannot_access_admin_branding(): void
    {
        $response = $this->getJson('/api/admin/settings/branding');
        $response->assertUnauthorized();
    }

    // ─── Admin POST /api/admin/settings/branding ───

    public function test_admin_can_update_app_name(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/settings/branding', [
                'app_name' => 'My Drive',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.app_name', 'My Drive')
            ->assertJsonPath('message', 'Branding updated.');

        $this->assertDatabaseHas('system_configs', [
            'key' => 'branding.app_name',
            'value' => 'My Drive',
        ]);
    }

    public function test_admin_can_update_copyright_text(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/settings/branding', [
                'copyright_text' => '© 2026 My Company',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.copyright_text', '© 2026 My Company');

        $this->assertDatabaseHas('system_configs', [
            'key' => 'branding.copyright_text',
            'value' => '© 2026 My Company',
        ]);
    }

    public function test_admin_can_update_app_name_and_copyright_together(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/settings/branding', [
                'app_name' => 'My Cloud',
                'copyright_text' => '© 2026 ACME Corp',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.app_name', 'My Cloud')
            ->assertJsonPath('data.copyright_text', '© 2026 ACME Corp');
    }

    public function test_regular_user_cannot_update_branding(): void
    {
        $response = $this->actingAs($this->regularUser, 'api')
            ->postJson('/api/admin/settings/branding', [
                'app_name' => 'Hacked Drive',
            ]);

        $response->assertStatus(403);
    }

    public function test_unauthenticated_cannot_update_branding(): void
    {
        $response = $this->postJson('/api/admin/settings/branding', [
            'app_name' => 'Hacked Drive',
        ]);

        $response->assertUnauthorized();
    }

    // ─── Validation ───

    public function test_app_name_max_length_enforced(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/settings/branding', [
                'app_name' => str_repeat('A', 101),
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['app_name']);
    }

    public function test_copyright_text_max_length_enforced(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/settings/branding', [
                'copyright_text' => str_repeat('X', 256),
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['copyright_text']);
    }

    public function test_invalid_logo_mime_rejected(): void
    {
        $file = UploadedFile::fake()->create('logo.jpg', 100, 'image/jpeg');

        $response = $this->actingAs($this->admin, 'api')
            ->post('/api/admin/settings/branding', [
                'logo' => $file,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['logo']);
    }

    public function test_invalid_favicon_mime_rejected(): void
    {
        $file = UploadedFile::fake()->create('favicon.jpg', 100, 'image/jpeg');

        $response = $this->actingAs($this->admin, 'api')
            ->post('/api/admin/settings/branding', [
                'favicon' => $file,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['favicon']);
    }

    public function test_oversized_logo_rejected(): void
    {
        $file = UploadedFile::fake()->create('logo.png', 2048, 'image/png');

        $response = $this->actingAs($this->admin, 'api')
            ->post('/api/admin/settings/branding', [
                'logo' => $file,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['logo']);
    }

    // ─── Sanitization ───

    public function test_app_name_html_tags_stripped(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/settings/branding', [
                'app_name' => '<script>alert("xss")</script>My Drive',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.app_name', 'alert("xss")My Drive');
    }

    public function test_copyright_text_html_tags_stripped(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/settings/branding', [
                'copyright_text' => '© 2026 <b>Bold</b> Company',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.copyright_text', '© 2026 Bold Company');
    }

    // ─── Null / Reset ───

    public function test_null_app_name_restores_default(): void
    {
        SystemConfig::query()->updateOrCreate(
            ['key' => 'branding.app_name'],
            ['value' => 'Custom Name', 'is_secret' => false],
        );

        $response = $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/settings/branding', [
                'app_name' => null,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.app_name', 'Blue Coral');
    }

    // ─── Public endpoint GET /api/branding ───

    public function test_public_branding_returns_defaults(): void
    {
        $response = $this->getJson('/api/branding');

        $response->assertStatus(200)
            ->assertJsonPath('data.app_name', 'Blue Coral')
            ->assertJsonPath('data.copyright_text', '© 2017–2026 Blue Coral. All rights reserved.')
            ->assertJsonPath('data.tag_line', 'Digital agency from Saigon')
            ->assertJsonPath('data.logo_url', null)
            ->assertJsonPath('data.favicon_url', null);
    }

    public function test_public_branding_returns_configured_values(): void
    {
        SystemConfig::query()->updateOrCreate(
            ['key' => 'branding.app_name'],
            ['value' => 'My Drive', 'is_secret' => false],
        );
        SystemConfig::query()->updateOrCreate(
            ['key' => 'branding.copyright_text'],
            ['value' => '© 2026 Test Corp', 'is_secret' => false],
        );

        $response = $this->getJson('/api/branding');

        $response->assertStatus(200)
            ->assertJsonPath('data.app_name', 'My Drive')
            ->assertJsonPath('data.copyright_text', '© 2026 Test Corp');
    }

    public function test_public_branding_includes_dev_credentials_when_env_show(): void
    {
        config(['app.dev_credentials' => 'show']);

        $response = $this->getJson('/api/branding');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data.dev_credentials')
            ->assertJsonPath('data.dev_credentials.0.label', 'Admin')
            ->assertJsonPath('data.dev_credentials.0.email', 'admin@bluecoral.vn')
            ->assertJsonPath('data.dev_credentials.0.password', 'admin')
            ->assertJsonPath('data.dev_credentials.1.label', 'User')
            ->assertJsonPath('data.dev_credentials.1.email', 'user@bluecoral.vn')
            ->assertJsonPath('data.dev_credentials.1.password', 'user');
    }

    public function test_public_branding_excludes_dev_credentials_when_env_not_show(): void
    {
        config(['app.dev_credentials' => null]);

        $response = $this->getJson('/api/branding');

        $response->assertStatus(200)
            ->assertJsonPath('data.dev_credentials', null);
    }

    // ─── Delete assets ───

    public function test_admin_can_delete_logo(): void
    {
        SystemConfig::query()->updateOrCreate(
            ['key' => 'branding.logo_path'],
            ['value' => 'branding/logo_old.png', 'is_secret' => false],
        );

        $response = $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/settings/branding', [
                'delete_logo' => true,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.logo_url', null);

        $this->assertDatabaseHas('system_configs', [
            'key' => 'branding.logo_path',
            'value' => null,
        ]);
    }

    public function test_admin_can_delete_favicon(): void
    {
        SystemConfig::query()->updateOrCreate(
            ['key' => 'branding.favicon_path'],
            ['value' => 'branding/favicon_old.ico', 'is_secret' => false],
        );

        $response = $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/settings/branding', [
                'delete_favicon' => true,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.favicon_url', null);

        $this->assertDatabaseHas('system_configs', [
            'key' => 'branding.favicon_path',
            'value' => null,
        ]);
    }
}
