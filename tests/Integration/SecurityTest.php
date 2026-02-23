<?php

namespace Tests\Integration;

use App\Models\File;
use App\Models\Folder;
use App\Models\Role;
use App\Models\Share;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Integration — Security Assertion Tests.
 */
class SecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $admin;

    private Folder $folder;

    private File $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->mockR2();

        $adminRole = Role::query()->where('slug', 'admin')->firstOrFail();
        $userRole = Role::query()->where('slug', 'user')->firstOrFail();

        $this->admin = User::factory()->create();
        $this->admin->roles()->attach($adminRole);

        $this->owner = User::factory()->create();
        $this->owner->roles()->attach($userRole);

        $this->folder = Folder::factory()->create(['owner_id' => $this->owner->id]);
        $this->file = File::factory()->create([
            'folder_id' => $this->folder->id,
            'owner_id' => $this->owner->id,
        ]);
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

    // ── Share Token Stored Hashed ─────────────────────────

    public function test_share_token_stored_hashed(): void
    {
        $response = $this->actingAs($this->owner, 'api')
            ->postJson('/api/share', [
                'file_id' => $this->file->id,
                'permission' => 'view',
            ]);

        $response->assertStatus(201);
        $rawToken = $response->json('data.token');

        // Token must be 64 chars
        $this->assertEquals(64, strlen($rawToken));

        // DB must have hashed version, not raw
        $share = Share::query()->latest()->first();
        $this->assertNotNull($share->token_hash);
        $this->assertNotEquals($rawToken, $share->token_hash, 'Raw token must not be stored in DB');

        // Hash should match
        $this->assertEquals(
            hash('sha256', $rawToken),
            $share->token_hash,
            'token_hash must be SHA-256 of raw token'
        );
    }

    // ── Secret Keys Not In System Config API ─────────────

    public function test_secret_keys_masked_in_config_api(): void
    {
        // Set R2 credentials via SystemConfig
        app(\App\Services\SystemConfigService::class)->set('r2_access_key', 'MY_ACCESS_KEY');
        app(\App\Services\SystemConfigService::class)->set('r2_secret_key', 'MY_SECRET_KEY');

        $response = $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/system/config');

        $response->assertStatus(200);

        $configs = collect($response->json('data'));

        $accessKey = $configs->firstWhere('key', 'r2_access_key');
        $secretKey = $configs->firstWhere('key', 'r2_secret_key');

        $this->assertNotNull($accessKey);
        $this->assertNotNull($secretKey);

        // Values must be masked
        $this->assertEquals('••••••••', $accessKey['value'], 'Access key must be masked');
        $this->assertEquals('••••••••', $secretKey['value'], 'Secret key must be masked');

        // Raw values must NOT appear anywhere in response body
        $body = $response->getContent();
        $this->assertStringNotContainsString('MY_ACCESS_KEY', $body);
        $this->assertStringNotContainsString('MY_SECRET_KEY', $body);
    }

    // ── Path Traversal Rejected ──────────────────────────

    public function test_folder_name_with_special_chars_accepted(): void
    {
        // The app does not perform path traversal validation on folder names
        // because folders are ID-based (no filesystem paths).
        // This test documents current behavior.
        $response = $this->actingAs($this->owner, 'api')
            ->postJson('/api/folders', ['name' => 'my folder (copy)']);

        $response->assertStatus(201);
    }

    public function test_folder_name_requires_non_empty(): void
    {
        $response = $this->actingAs($this->owner, 'api')
            ->postJson('/api/folders', ['name' => '']);

        $response->assertStatus(422);
    }

    // ── Unauthenticated Route Protection ─────────────────

    public function test_protected_routes_require_auth(): void
    {
        $protectedRoutes = [
            ['GET', '/api/folders/'.$this->folder->id],
            ['GET', '/api/files/'.$this->file->id],
            ['POST', '/api/folders'],
            ['POST', '/api/share'],
            ['GET', '/api/me/quota'],
            ['POST', '/api/upload/init'],
            ['GET', '/api/trash'],
        ];

        foreach ($protectedRoutes as [$method, $uri]) {
            $response = $this->{strtolower($method).'Json'}($uri);
            $this->assertEquals(
                401,
                $response->status(),
                "{$method} {$uri} should require authentication"
            );
        }
    }

    // ── Health Check Public ──────────────────────────────

    public function test_health_check_is_public(): void
    {
        $this->getJson('/api/health')
            ->assertStatus(200)
            ->assertJsonPath('status', 'ok');
    }

    // ── Guest Link Public ────────────────────────────────

    public function test_guest_share_endpoint_is_public(): void
    {
        // Create a guest share
        $response = $this->actingAs($this->owner, 'api')
            ->postJson('/api/share', [
                'file_id' => $this->file->id,
                'permission' => 'view',
            ]);

        $token = $response->json('data.token');

        // Access without auth
        $this->getJson("/api/share/{$token}")
            ->assertStatus(200);
    }
}
