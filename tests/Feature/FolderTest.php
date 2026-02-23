<?php

namespace Tests\Feature;

use App\Models\Folder;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FolderTest extends TestCase
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

        $this->admin = User::factory()->create();
        $this->admin->roles()->attach($adminRole);

        $this->user = User::factory()->create();
        $this->user->roles()->attach($userRole);
    }

    // ── Create ────────────────────────────────────────────

    public function test_user_can_create_root_folder(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/folders', ['name' => 'Documents']);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Documents')
            ->assertJsonPath('data.parent_id', null)
            ->assertJsonPath('data.owner.id', $this->user->id);

        $this->assertDatabaseHas('folders', [
            'name' => 'Documents',
            'owner_id' => $this->user->id,
        ]);
    }

    public function test_user_can_create_subfolder(): void
    {
        $parent = Folder::factory()->create(['owner_id' => $this->user->id, 'name' => 'Root']);

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/folders', [
                'name' => 'Child',
                'parent_id' => $parent->uuid,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Child')
            ->assertJsonPath('data.parent_id', $parent->uuid);
    }

    public function test_create_folder_validates_name(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/folders', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_create_folder_validates_parent_exists(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/folders', [
                'name' => 'Orphan',
                'parent_id' => 9999,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['parent_id']);
    }

    // ── Show ──────────────────────────────────────────────

    public function test_owner_can_view_folder(): void
    {
        $folder = Folder::factory()->create(['owner_id' => $this->user->id]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/folders/{$folder->uuid}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $folder->uuid);
    }

    public function test_admin_can_view_any_folder(): void
    {
        $folder = Folder::factory()->create(['owner_id' => $this->user->id]);

        $response = $this->actingAs($this->admin, 'api')
            ->getJson("/api/folders/{$folder->uuid}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $folder->uuid);
    }

    public function test_non_owner_cannot_view_folder(): void
    {
        $otherUser = User::factory()->create();
        $otherUser->roles()->attach(Role::query()->where('slug', 'user')->first());
        $folder = Folder::factory()->create(['owner_id' => $otherUser->id]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/folders/{$folder->uuid}");

        $response->assertStatus(403);
    }

    // ── Children (pagination) ─────────────────────────────

    public function test_owner_can_list_children(): void
    {
        $parent = Folder::factory()->create(['owner_id' => $this->user->id]);
        Folder::factory()->count(3)->create([
            'parent_id' => $parent->id,
            'owner_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/folders/{$parent->uuid}/children");

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure(['data', 'meta']);
    }

    public function test_children_are_cursor_paginated(): void
    {
        $parent = Folder::factory()->create(['owner_id' => $this->user->id]);
        Folder::factory()->count(5)->create([
            'parent_id' => $parent->id,
            'owner_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/folders/{$parent->uuid}/children?limit=2");

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');

        $nextCursor = $response->json('meta.next_cursor');
        $this->assertNotNull($nextCursor);

        $response2 = $this->actingAs($this->user, 'api')
            ->getJson("/api/folders/{$parent->uuid}/children?limit=2&cursor={$nextCursor}");

        $response2->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_children_limit_capped_at_100(): void
    {
        $parent = Folder::factory()->create(['owner_id' => $this->user->id]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/folders/{$parent->uuid}/children?limit=999");

        $response->assertStatus(200);
    }

    // ── Delete ────────────────────────────────────────────

    public function test_owner_can_delete_folder(): void
    {
        $folder = Folder::factory()->create(['owner_id' => $this->user->id]);

        $response = $this->actingAs($this->user, 'api')
            ->deleteJson("/api/folders/{$folder->uuid}");

        $response->assertStatus(204);

        // Soft delete: record still exists but is trashed
        $folder->refresh();
        $this->assertNotNull($folder->deleted_at);
    }

    public function test_delete_cascades_to_children(): void
    {
        $parent = Folder::factory()->create(['owner_id' => $this->user->id]);
        $child = Folder::factory()->create([
            'parent_id' => $parent->id,
            'owner_id' => $this->user->id,
        ]);

        $this->actingAs($this->user, 'api')
            ->deleteJson("/api/folders/{$parent->uuid}")
            ->assertStatus(204);

        // Soft delete cascades
        $parent->refresh();
        $child->refresh();
        $this->assertNotNull($parent->deleted_at);
        $this->assertNotNull($child->deleted_at);
    }

    public function test_admin_can_delete_any_folder(): void
    {
        $folder = Folder::factory()->create(['owner_id' => $this->user->id]);

        $response = $this->actingAs($this->admin, 'api')
            ->deleteJson("/api/folders/{$folder->uuid}");

        $response->assertStatus(204);
    }

    public function test_non_owner_cannot_delete_folder(): void
    {
        $otherUser = User::factory()->create();
        $otherUser->roles()->attach(Role::query()->where('slug', 'user')->first());
        $folder = Folder::factory()->create(['owner_id' => $otherUser->id]);

        $response = $this->actingAs($this->user, 'api')
            ->deleteJson("/api/folders/{$folder->uuid}");

        $response->assertStatus(403);
    }

    // ── Auth ──────────────────────────────────────────────

    public function test_unauthenticated_cannot_access_folders(): void
    {
        $response = $this->postJson('/api/folders', ['name' => 'Test']);
        $response->assertUnauthorized();
    }
}
