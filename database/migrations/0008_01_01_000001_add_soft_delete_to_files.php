<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->timestamp('deleted_at')->nullable();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('purge_at')->nullable();

            $table->index('deleted_at');
            $table->index('purge_at');
        });
    }

    public function down(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->dropForeign(['deleted_by']);
            $table->dropIndex(['deleted_at']);
            $table->dropIndex(['purge_at']);
            $table->dropColumn(['deleted_at', 'deleted_by', 'purge_at']);
        });
    }
};
