<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            ['name' => 'View Any Users', 'slug' => 'users.view-any', 'description' => 'View the full list of users'],
            ['name' => 'View Users', 'slug' => 'users.view', 'description' => 'View any user profile'],
            ['name' => 'Create Users', 'slug' => 'users.create', 'description' => 'Create new users'],
            ['name' => 'Update Users', 'slug' => 'users.update', 'description' => 'Update any user'],
            ['name' => 'Delete Users', 'slug' => 'users.delete', 'description' => 'Delete any user'],
            ['name' => 'View System Config', 'slug' => 'system-config.view', 'description' => 'View system configuration'],
            ['name' => 'Update System Config', 'slug' => 'system-config.update', 'description' => 'Update system configuration'],
            ['name' => 'Create Folders', 'slug' => 'folders.create', 'description' => 'Create folders'],
            ['name' => 'View Any Folders', 'slug' => 'folders.view-any', 'description' => 'View any user folders'],
            ['name' => 'Delete Any Folders', 'slug' => 'folders.delete-any', 'description' => 'Delete any user folders'],
            ['name' => 'Update Any Folders', 'slug' => 'folders.update-any', 'description' => 'Update (rename/move) any user folders'],
            ['name' => 'View Any Files', 'slug' => 'files.view-any', 'description' => 'View any user files'],
            ['name' => 'Update Any Files', 'slug' => 'files.update-any', 'description' => 'Update any user files'],
            ['name' => 'Delete Any Files', 'slug' => 'files.delete-any', 'description' => 'Delete any user files'],
            ['name' => 'Download Any Files', 'slug' => 'files.download-any', 'description' => 'Download any user files'],
            ['name' => 'Create Shares', 'slug' => 'shares.create', 'description' => 'Create share links'],
            ['name' => 'View Any Shares', 'slug' => 'shares.view-any', 'description' => 'View any share links'],
            ['name' => 'Restore Any Files', 'slug' => 'files.restore-any', 'description' => 'Restore any trashed files'],
            ['name' => 'Force Delete Any Files', 'slug' => 'files.force-delete-any', 'description' => 'Permanently delete any files'],
            ['name' => 'Restore Any Folders', 'slug' => 'folders.restore-any', 'description' => 'Restore any trashed folders'],
            ['name' => 'Force Delete Any Folders', 'slug' => 'folders.force-delete-any', 'description' => 'Permanently delete any folders'],
            ['name' => 'Delete Any Shares', 'slug' => 'shares.delete-any', 'description' => 'Revoke any share links'],
            ['name' => 'View Any Activity Logs', 'slug' => 'activity-logs.view-any', 'description' => 'View all activity logs'],
            ['name' => 'Disable Users', 'slug' => 'users.disable', 'description' => 'Disable and re-enable user accounts'],
            ['name' => 'Hard Delete Users', 'slug' => 'users.hard-delete', 'description' => 'Permanently delete users and all their data'],
            ['name' => 'Reset User Password', 'slug' => 'users.reset-password', 'description' => 'Reset another user password'],
            ['name' => 'View Email Logs', 'slug' => 'email-logs.view', 'description' => 'View email notification logs'],
            ['name' => 'Manage Backups', 'slug' => 'backups.manage', 'description' => 'Manage database backups'],
        ];

        foreach ($permissions as $permissionData) {
            Permission::query()->firstOrCreate(
                ['slug' => $permissionData['slug']],
                $permissionData,
            );
        }

        $admin = Role::query()->firstOrCreate(
            ['slug' => 'admin'],
            ['name' => 'Administrator', 'description' => 'Full system access'],
        );
        $admin->permissions()->sync(Permission::query()->pluck('id'));

        Role::query()->firstOrCreate(
            ['slug' => 'user'],
            ['name' => 'User', 'description' => 'Standard user access'],
        );

        $userRole = Role::query()->where('slug', 'user')->first();
        $userPerms = Permission::query()
            ->whereIn('slug', ['folders.create', 'shares.create'])
            ->pluck('id');
        if ($userRole) {
            $userRole->permissions()->syncWithoutDetaching($userPerms);
        }
    }
}
