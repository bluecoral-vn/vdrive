<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('status')->default('active')->after('password');
            $table->timestamp('disabled_at')->nullable()->after('status');
            $table->string('disabled_reason')->nullable()->after('disabled_at');
            $table->unsignedBigInteger('token_version')->default(0)->after('disabled_reason');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn(['status', 'disabled_at', 'disabled_reason', 'token_version']);
        });
    }
};
