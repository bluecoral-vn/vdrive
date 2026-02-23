<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->string('thumbnail_path')->nullable()->after('exif_data');
            $table->unsignedSmallInteger('thumbnail_width')->nullable()->after('thumbnail_path');
            $table->unsignedSmallInteger('thumbnail_height')->nullable()->after('thumbnail_width');
            $table->string('blurhash', 50)->nullable()->after('thumbnail_height');
        });
    }

    public function down(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->dropColumn(['thumbnail_path', 'thumbnail_width', 'thumbnail_height', 'blurhash']);
        });
    }
};
