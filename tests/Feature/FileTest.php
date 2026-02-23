<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\Folder;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FileTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $user;

    private Folder $folder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $adminRole = Role::query()->where('slug', 'admin')->firstOrFail();
        $userRole = Role::query()->where('slug', 'user')->firstOrFail();

        $this->admin = User::factory()->create();
        $this->admin->roles()->attach($adminRole);

        $this->user = User::factory()->create();
        $this->user->roles()->attach($userRole);

        $this->folder = Folder::factory()->create(['owner_id' => $this->user->id]);
    }

    // ── Show ──────────────────────────────────────────────

    public function test_owner_can_view_file(): void
    {
        $file = File::factory()->create([
            'folder_id' => $this->folder->id,
            'owner_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/files/{$file->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $file->id)
            ->assertJsonPath('data.name', $file->name)
            ->assertJsonPath('data.mime_type', $file->mime_type);
    }

    public function test_admin_can_view_any_file(): void
    {
        $file = File::factory()->create([
            'folder_id' => $this->folder->id,
            'owner_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->admin, 'api')
            ->getJson("/api/files/{$file->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $file->id);
    }

    public function test_non_owner_cannot_view_file(): void
    {
        $otherUser = User::factory()->create();
        $otherUser->roles()->attach(Role::query()->where('slug', 'user')->first());

        $file = File::factory()->create([
            'folder_id' => $this->folder->id,
            'owner_id' => $this->user->id,
        ]);

        $response = $this->actingAs($otherUser, 'api')
            ->getJson("/api/files/{$file->id}");

        $response->assertStatus(403);
    }

    public function test_file_has_uuid_id(): void
    {
        $file = File::factory()->create([
            'folder_id' => $this->folder->id,
            'owner_id' => $this->user->id,
        ]);

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $file->id,
        );
    }

    // ── Files in Folder ───────────────────────────────────

    public function test_owner_can_list_files_in_folder(): void
    {
        File::factory()->count(3)->create([
            'folder_id' => $this->folder->id,
            'owner_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/folders/{$this->folder->uuid}/files");

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure(['data', 'meta']);
    }

    public function test_files_in_folder_are_cursor_paginated(): void
    {
        File::factory()->count(5)->create([
            'folder_id' => $this->folder->id,
            'owner_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/folders/{$this->folder->uuid}/files?limit=2");

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');

        $nextCursor = $response->json('meta.next_cursor');
        $this->assertNotNull($nextCursor);

        $response2 = $this->actingAs($this->user, 'api')
            ->getJson("/api/folders/{$this->folder->uuid}/files?limit=2&cursor={$nextCursor}");

        $response2->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_files_in_folder_empty(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/folders/{$this->folder->uuid}/files");

        $response->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    // ── Delete ────────────────────────────────────────────

    public function test_owner_can_delete_file(): void
    {
        $file = File::factory()->create([
            'folder_id' => $this->folder->id,
            'owner_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->deleteJson("/api/files/{$file->id}");

        $response->assertStatus(204);

        // Soft delete: record still exists but is trashed
        $file->refresh();
        $this->assertNotNull($file->deleted_at);
    }

    public function test_admin_can_delete_any_file(): void
    {
        $file = File::factory()->create([
            'folder_id' => $this->folder->id,
            'owner_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->admin, 'api')
            ->deleteJson("/api/files/{$file->id}");

        $response->assertStatus(204);
    }

    public function test_non_owner_cannot_delete_file(): void
    {
        $otherUser = User::factory()->create();
        $otherUser->roles()->attach(Role::query()->where('slug', 'user')->first());

        $file = File::factory()->create([
            'folder_id' => $this->folder->id,
            'owner_id' => $this->user->id,
        ]);

        $response = $this->actingAs($otherUser, 'api')
            ->deleteJson("/api/files/{$file->id}");

        $response->assertStatus(403);
    }

    // ── Auth ──────────────────────────────────────────────

    public function test_unauthenticated_cannot_access_files(): void
    {
        $file = File::factory()->create([
            'folder_id' => $this->folder->id,
            'owner_id' => $this->user->id,
        ]);

        $response = $this->getJson("/api/files/{$file->id}");
        $response->assertUnauthorized();
    }
}
