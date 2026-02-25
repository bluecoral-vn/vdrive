<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\Folder;
use App\Models\Role;
use App\Models\Share;
use App\Models\SystemConfig;
use App\Models\Tag;
use App\Models\User;
use App\Services\R2ClientService;
use Aws\Result;
use Aws\S3\S3Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class DemoResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockR2Client();
    }

    /** Mock R2ClientService to avoid real S3 calls */
    private function mockR2Client(array $objectKeys = []): void
    {
        $contents = array_map(fn ($key) => ['Key' => $key], $objectKeys);

        $s3Client = Mockery::mock(S3Client::class);
        $s3Client->shouldReceive('listObjectsV2')
            ->andReturn(new Result([
                'Contents' => $contents,
                'IsTruncated' => false,
            ]));
        $s3Client->shouldReceive('deleteObjects')->andReturn(new Result([]));

        $r2Service = Mockery::mock(R2ClientService::class);
        $r2Service->shouldReceive('client')->andReturn($s3Client);
        $r2Service->shouldReceive('bucket')->andReturn('test-bucket');

        $this->app->instance(R2ClientService::class, $r2Service);
    }

    /** Helper: set APP_ENV to demo */
    private function setDemoEnv(): void
    {
        app()->detectEnvironment(fn () => 'demo');
    }

    /** Helper: seed some user data (files, folders, tags) */
    private function seedUserData(): User
    {
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $admin = User::factory()->create([
            'email' => 'admin@bluecoral.vn',
            'quota_used_bytes' => 5000,
        ]);
        $admin->roles()->sync(Role::query()->where('slug', 'admin')->pluck('id'));

        $folder = Folder::factory()->create(['owner_id' => $admin->id]);

        File::factory()->count(3)->create([
            'owner_id' => $admin->id,
            'folder_id' => $folder->id,
            'r2_object_key' => fn () => $admin->id . '/' . fake()->uuid() . '/test.jpg',
            'thumbnail_path' => fn () => 'thumbnails/' . fake()->uuid() . '/750.webp',
        ]);

        Tag::create([
            'user_id' => $admin->id,
            'name' => 'Test Tag',
            'color' => '#ff0000',
        ]);

        return $admin;
    }

    public function test_demo_reset_deletes_all_files_and_folders(): void
    {
        $this->setDemoEnv();
        $this->seedUserData();

        $this->assertGreaterThan(0, File::query()->count());
        $this->assertGreaterThan(0, Folder::query()->count());

        $this->artisan('demo:reset')
            ->assertExitCode(0);

        $this->assertEquals(0, File::query()->count());
        $this->assertEquals(0, Folder::query()->count());
        $this->assertEquals(0, Share::query()->count());
        $this->assertEquals(0, Tag::query()->count());
    }

    public function test_demo_reset_wipes_all_r2_objects(): void
    {
        $this->setDemoEnv();

        // Mock R2 with some objects in bucket
        $objectKeys = ['file1.jpg', 'file2.pdf', 'thumbnails/abc/750.webp', 'orphaned/old-file.zip'];
        $this->mockR2Client($objectKeys);

        $this->seedUserData();

        $this->artisan('demo:reset')
            ->assertExitCode(0)
            ->expectsOutputToContain('R2 objects deleted');
    }

    public function test_demo_reset_handles_empty_r2_bucket(): void
    {
        $this->setDemoEnv();
        $this->mockR2Client([]); // empty bucket
        $this->seedUserData();

        $this->artisan('demo:reset')
            ->assertExitCode(0);

        $this->assertEquals(0, File::query()->count());
    }

    public function test_demo_reset_handles_paginated_r2_listing(): void
    {
        $this->setDemoEnv();

        // Mock paginated response (first page truncated, second page final)
        $s3Client = Mockery::mock(S3Client::class);
        $s3Client->shouldReceive('listObjectsV2')
            ->once()
            ->withArgs(fn ($params) => ! isset($params['ContinuationToken']))
            ->andReturn(new Result([
                'Contents' => [['Key' => 'page1-file1.jpg'], ['Key' => 'page1-file2.jpg']],
                'IsTruncated' => true,
                'NextContinuationToken' => 'token-abc',
            ]));
        $s3Client->shouldReceive('listObjectsV2')
            ->once()
            ->withArgs(fn ($params) => ($params['ContinuationToken'] ?? null) === 'token-abc')
            ->andReturn(new Result([
                'Contents' => [['Key' => 'page2-file1.jpg']],
                'IsTruncated' => false,
            ]));
        $s3Client->shouldReceive('deleteObjects')->twice()->andReturn(new Result([]));

        $r2Service = Mockery::mock(R2ClientService::class);
        $r2Service->shouldReceive('client')->andReturn($s3Client);
        $r2Service->shouldReceive('bucket')->andReturn('test-bucket');

        $this->app->instance(R2ClientService::class, $r2Service);

        $this->seedUserData();

        $this->artisan('demo:reset')
            ->assertExitCode(0);
    }

    public function test_demo_reset_preserves_system_config(): void
    {
        $this->setDemoEnv();
        $this->seedUserData();

        SystemConfig::query()->updateOrCreate(
            ['key' => 'r2_bucket'],
            ['value' => 'test-bucket'],
        );

        $this->artisan('demo:reset')
            ->assertExitCode(0);

        $this->assertEquals('test-bucket', SystemConfig::query()->where('key', 'r2_bucket')->value('value'));
    }

    public function test_demo_reset_reseeds_users(): void
    {
        $this->setDemoEnv();
        $this->seedUserData();

        $this->artisan('demo:reset')
            ->assertExitCode(0);

        $this->assertEquals(2, User::query()->count());
        $this->assertDatabaseHas('users', ['email' => 'admin@bluecoral.vn']);
        $this->assertDatabaseHas('users', ['email' => 'user@bluecoral.vn']);
    }

    public function test_demo_reset_resets_quota(): void
    {
        $this->setDemoEnv();
        $this->seedUserData();

        $this->artisan('demo:reset')
            ->assertExitCode(0);

        $users = User::all();
        foreach ($users as $user) {
            $this->assertEquals(0, $user->quota_used_bytes);
        }
    }

    public function test_demo_reset_fails_when_not_demo_env(): void
    {
        // Default test env is 'testing', not 'demo'
        $this->artisan('demo:reset')
            ->assertExitCode(1);
    }

    public function test_demo_reset_force_flag_works(): void
    {
        // Ensure env is NOT demo
        app()->detectEnvironment(fn () => 'testing');

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $user = User::factory()->create(['quota_used_bytes' => 1000]);
        File::factory()->count(2)->create([
            'owner_id' => $user->id,
            'r2_object_key' => fn () => $user->id . '/' . fake()->uuid() . '/test.jpg',
            'thumbnail_path' => fn () => 'thumbnails/' . fake()->uuid() . '/750.webp',
        ]);

        $this->artisan('demo:reset', ['--force' => true])
            ->assertExitCode(0);

        $this->assertEquals(0, File::query()->count());
    }
}
