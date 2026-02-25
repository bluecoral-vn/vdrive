<?php

namespace App\Services;

use App\Models\File;
use App\Services\R2ClientService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DemoResetService
{
    public function __construct(
        private R2ClientService $r2,
    ) {}

    /**
     * Perform a full demo environment reset.
     *
     * Wipes ALL objects from R2 bucket and database,
     * then re-seeds the initial admin + user accounts.
     *
     * @return array{r2_objects_deleted: int, files_deleted: int, folders_deleted: int, users_reseeded: int}
     */
    public function reset(): array
    {
        if (! app()->environment('demo')) {
            throw new \RuntimeException('Demo reset can only run when APP_ENV=demo');
        }

        $stats = [
            'r2_objects_deleted' => 0,
            'files_deleted' => 0,
            'folders_deleted' => 0,
            'users_reseeded' => 0,
        ];

        // Step 1 — Wipe ALL objects from R2 bucket (including orphaned files)
        $stats['r2_objects_deleted'] = $this->wipeR2Bucket();

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

    /**
     * Delete ALL objects from the R2 bucket.
     *
     * Uses listObjectsV2 pagination + deleteObjects batch API
     * to wipe the entire bucket regardless of DB records.
     */
    private function wipeR2Bucket(): int
    {
        $client = $this->r2->client();
        $bucket = $this->r2->bucket();
        $deleted = 0;

        $params = ['Bucket' => $bucket, 'MaxKeys' => 1000];

        do {
            $result = $client->listObjectsV2($params);
            $objects = $result['Contents'] ?? [];

            if (empty($objects)) {
                break;
            }

            // Build batch delete payload
            $deleteKeys = array_map(
                fn ($obj) => ['Key' => $obj['Key']],
                $objects,
            );

            $client->deleteObjects([
                'Bucket' => $bucket,
                'Delete' => [
                    'Objects' => $deleteKeys,
                    'Quiet' => true,
                ],
            ]);

            $deleted += count($deleteKeys);

            // Use continuation token for next page
            $params['ContinuationToken'] = $result['NextContinuationToken'] ?? null;
        } while ($result['IsTruncated'] ?? false);

        return $deleted;
    }
}
