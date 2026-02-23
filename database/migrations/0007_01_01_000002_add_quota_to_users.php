<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('quota_limit_bytes')->nullable()->after('password');
            $table->unsignedBigInteger('quota_used_bytes')->default(0)->after('quota_limit_bytes');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['quota_limit_bytes', 'quota_used_bytes']);
        });
    }
};
