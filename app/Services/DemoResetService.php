<?php

namespace App\Services;

use App\Jobs\DeleteR2ObjectJob;
use App\Models\File;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DemoResetService
{
    /**
     * Perform a full demo environment reset.
     *
     * Deletes all uploaded files from R2 and database,
     * then re-seeds the initial admin + user accounts.
     *
     * @return array{r2_objects_queued: int, files_deleted: int, folders_deleted: int, users_reseeded: int}
     */
    public function reset(): array
    {
        if (! app()->environment('demo')) {
            throw new \RuntimeException('Demo reset can only run when APP_ENV=demo');
        }

        $stats = [
            'r2_objects_queued' => 0,
            'files_deleted' => 0,
            'folders_deleted' => 0,
            'users_reseeded' => 0,
        ];

        // Step 1 — Queue R2 object deletion for all files (object keys + thumbnails)
        File::query()->chunkById(200, function ($files) use (&$stats): void {
            foreach ($files as $file) {
                if ($file->r2_object_key) {
                    DeleteR2ObjectJob::dispatch($file->r2_object_key);
                    $stats['r2_objects_queued']++;
                }
                if ($file->thumbnail_path) {
                    DeleteR2ObjectJob::dispatch($file->thumbnail_path);
                    $stats['r2_objects_queued']++;
                }
            }
        });

        // Step 2 — Truncate all user-data tables (order: dependents first)
        $stats['files_deleted'] = File::query()->count();
        $stats['folders_deleted'] = DB::table('folders')->count();

        // Disable FK checks for clean truncation
        DB::statement('PRAGMA foreign_keys = OFF');

        try {
            DB::table('taggables')->delete();
            DB::table('user_favorites')->delete();
            DB::table('shares')->delete();
            DB::table('sync_events')->delete();
            DB::table('activity_logs')->delete();
            DB::table('upload_sessions')->delete();
            DB::table('email_logs')->delete();
            DB::table('database_backups')->delete();
            DB::table('password_reset_tokens')->delete();
            DB::table('files')->delete();
            DB::table('folders')->delete();
            DB::table('tags')->delete();
            DB::table('role_user')->delete();
            DB::table('users')->delete();
        } finally {
            DB::statement('PRAGMA foreign_keys = ON');
        }

        // Step 3 — Re-seed initial users (admin + user with correct roles)
        $seeder = new DatabaseSeeder();
        $seeder->run();
        $stats['users_reseeded'] = 2;

        Log::info('Demo reset completed', $stats);

        return $stats;
    }
}
