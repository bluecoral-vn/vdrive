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
        Schema::table('tags', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->after('id');
        });

        // Populate existing rows with UUIDs
        DB::table('tags')->whereNull('uuid')->orderBy('id')->each(function ($tag) {
            DB::table('tags')->where('id', $tag->id)->update(['uuid' => Str::uuid()->toString()]);
        });

        Schema::table('tags', function (Blueprint $table) {
            $table->uuid('uuid')->nullable(false)->unique()->change();
        });
    }

    public function down(): void
    {
        Schema::table('tags', function (Blueprint $table) {
            $table->dropColumn('uuid');
        });
    }
};
