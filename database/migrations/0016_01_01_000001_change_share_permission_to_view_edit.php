<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Convert all 'download' permissions to 'view'
        // (view now includes download capability)
        DB::table('shares')
            ->where('permission', 'download')
            ->update(['permission' => 'view']);
    }

    public function down(): void
    {
        // Cannot reliably reverse — no way to know which 'view' shares
        // were originally 'download'. This is intentionally a no-op.
    }
};
