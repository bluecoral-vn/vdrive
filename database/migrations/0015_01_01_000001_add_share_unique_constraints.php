<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add unique constraints to the shares table for user-to-user shares
 * and clean up any existing duplicate rows first.
 *
 * Guest links (shared_with IS NULL) are protected at application level
 * because SQL NULL != NULL prevents unique index enforcement.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Step 1: Clean duplicate user-to-user FILE shares ──
        $fileUserDupes = DB::select(<<<'SQL'
            SELECT file_id, shared_by, shared_with
            FROM shares
            WHERE file_id IS NOT NULL AND shared_with IS NOT NULL
            GROUP BY file_id, shared_by, shared_with
            HAVING COUNT(*) > 1
        SQL);

        foreach ($fileUserDupes as $dupe) {
            $keep = DB::selectOne(<<<'SQL'
                SELECT id FROM shares
                WHERE file_id = ? AND shared_by = ? AND shared_with = ?
                ORDER BY created_at DESC
                LIMIT 1
            SQL, [$dupe->file_id, $dupe->shared_by, $dupe->shared_with]);

            DB::delete(<<<'SQL'
                DELETE FROM shares
                WHERE file_id = ? AND shared_by = ? AND shared_with = ? AND id != ?
            SQL, [$dupe->file_id, $dupe->shared_by, $dupe->shared_with, $keep->id]);
        }

        // ── Step 2: Clean duplicate user-to-user FOLDER shares ──
        $folderUserDupes = DB::select(<<<'SQL'
            SELECT folder_id, shared_by, shared_with
            FROM shares
            WHERE folder_id IS NOT NULL AND shared_with IS NOT NULL
            GROUP BY folder_id, shared_by, shared_with
            HAVING COUNT(*) > 1
        SQL);

        foreach ($folderUserDupes as $dupe) {
            $keep = DB::selectOne(<<<'SQL'
                SELECT id FROM shares
                WHERE folder_id = ? AND shared_by = ? AND shared_with = ?
                ORDER BY created_at DESC
                LIMIT 1
            SQL, [$dupe->folder_id, $dupe->shared_by, $dupe->shared_with]);

            DB::delete(<<<'SQL'
                DELETE FROM shares
                WHERE folder_id = ? AND shared_by = ? AND shared_with = ? AND id != ?
            SQL, [$dupe->folder_id, $dupe->shared_by, $dupe->shared_with, $keep->id]);
        }

        // ── Step 3: Clean duplicate guest-link FILE shares ──
        $fileGuestDupes = DB::select(<<<'SQL'
            SELECT file_id, shared_by
            FROM shares
            WHERE file_id IS NOT NULL AND shared_with IS NULL
            GROUP BY file_id, shared_by
            HAVING COUNT(*) > 1
        SQL);

        foreach ($fileGuestDupes as $dupe) {
            $keep = DB::selectOne(<<<'SQL'
                SELECT id FROM shares
                WHERE file_id = ? AND shared_by = ? AND shared_with IS NULL
                ORDER BY created_at DESC
                LIMIT 1
            SQL, [$dupe->file_id, $dupe->shared_by]);

            DB::delete(<<<'SQL'
                DELETE FROM shares
                WHERE file_id = ? AND shared_by = ? AND shared_with IS NULL AND id != ?
            SQL, [$dupe->file_id, $dupe->shared_by, $keep->id]);
        }

        // ── Step 4: Clean duplicate guest-link FOLDER shares ──
        $folderGuestDupes = DB::select(<<<'SQL'
            SELECT folder_id, shared_by
            FROM shares
            WHERE folder_id IS NOT NULL AND shared_with IS NULL
            GROUP BY folder_id, shared_by
            HAVING COUNT(*) > 1
        SQL);

        foreach ($folderGuestDupes as $dupe) {
            $keep = DB::selectOne(<<<'SQL'
                SELECT id FROM shares
                WHERE folder_id = ? AND shared_by = ? AND shared_with IS NULL
                ORDER BY created_at DESC
                LIMIT 1
            SQL, [$dupe->folder_id, $dupe->shared_by]);

            DB::delete(<<<'SQL'
                DELETE FROM shares
                WHERE folder_id = ? AND shared_by = ? AND shared_with IS NULL AND id != ?
            SQL, [$dupe->folder_id, $dupe->shared_by, $keep->id]);
        }

        // ── Step 5: Add unique constraint for user-to-user shares ──
        Schema::table('shares', function (Blueprint $table) {
            $table->unique(
                ['file_id', 'shared_by', 'shared_with'],
                'shares_file_user_unique'
            );
            $table->unique(
                ['folder_id', 'shared_by', 'shared_with'],
                'shares_folder_user_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('shares', function (Blueprint $table) {
            $table->dropUnique('shares_file_user_unique');
            $table->dropUnique('shares_folder_user_unique');
        });
    }
};
