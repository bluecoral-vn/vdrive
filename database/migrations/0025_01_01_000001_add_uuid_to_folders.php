<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // Step 1: Add uuid column as NULLABLE (SQLite cannot add NOT NULL without default)
        Schema::table('folders', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->after('id');
        });

        // Step 2: Backfill UUIDs for existing folders
        $folders = DB::table('folders')->select('id')->get();
        foreach ($folders as $folder) {
            DB::table('folders')
                ->where('id', $folder->id)
                ->update(['uuid' => Str::uuid()->toString()]);
        }

        // Step 3: Make column NOT NULL and add unique index
        // SQLite requires column rebuild for NOT NULL change
        if (DB::getDriverName() === 'sqlite') {
            // For SQLite: just add the unique index; the model ensures uuid is always set
            Schema::table('folders', function (Blueprint $table) {
                $table->unique('uuid');
            });
        } else {
            // For MySQL/PostgreSQL: alter column to NOT NULL, then add unique
            Schema::table('folders', function (Blueprint $table) {
                $table->uuid('uuid')->nullable(false)->change();
                $table->unique('uuid');
            });
        }
    }

    public function down(): void
    {
        Schema::table('folders', function (Blueprint $table) {
            $table->dropColumn('uuid');
        });
    }
};
