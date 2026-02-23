<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Seed roles & permissions first
        $this->call(RolePermissionSeeder::class);

        // Admin user
        $admin = User::factory()->create([
            'name' => 'Blue Coral',
            'email' => 'admin@bluecoral.vn',
            'password' => 'admin',
            'quota_limit_bytes' => null, // unlimited
        ]);
        $admin->roles()->sync(
            Role::query()->where('slug', 'admin')->pluck('id')
        );

        // Regular user
        $user = User::factory()->create([
            'name' => 'A member of Blue Coral',
            'email' => 'user@bluecoral.vn',
            'password' => 'user',
            'quota_limit_bytes' => 10485760, // 10 MB
        ]);
        $user->roles()->sync(
            Role::query()->where('slug', 'user')->pluck('id')
        );
    }
}
