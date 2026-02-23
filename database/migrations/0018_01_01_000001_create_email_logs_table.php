<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('recipient');
            $table->string('subject');
            $table->string('status')->default('queued'); // queued, success, failed
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('resource_type'); // file, folder
            $table->string('resource_id');
            $table->uuid('share_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('recipient');
            $table->index('share_id');
            $table->foreign('share_id')->references('id')->on('shares')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_logs');
    }
};
