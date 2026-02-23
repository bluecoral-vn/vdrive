<?php

use App\Models\Folder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('folders', function (Blueprint $table) {
            $table->string('path', 2048)->default('')->after('name');
            $table->index('path');
        });

        // Backfill paths for existing folders using iterative BFS
        $this->backfillPaths();
    }

    public function down(): void
    {
        Schema::table('folders', function (Blueprint $table) {
            $table->dropIndex(['path']);
            $table->dropColumn('path');
        });
    }

    private function backfillPaths(): void
    {
        // Process root folders first, then children level by level
        $rootFolders = Folder::query()->whereNull('parent_id')->get();

        foreach ($rootFolders as $folder) {
            $path = '/'.$folder->id.'/';
            Folder::query()->where('id', $folder->id)->update(['path' => $path]);
        }

        // Now process children level by level (BFS)
        $parentIds = $rootFolders->pluck('id')->all();

        while (! empty($parentIds)) {
            $children = Folder::query()
                ->whereIn('parent_id', $parentIds)
                ->get(['id', 'parent_id']);

            $nextParentIds = [];

            foreach ($children as $child) {
                $parentPath = Folder::query()
                    ->where('id', $child->parent_id)
                    ->value('path');

                $childPath = $parentPath.$child->id.'/';
                Folder::query()->where('id', $child->id)->update(['path' => $childPath]);
                $nextParentIds[] = $child->id;
            }

            $parentIds = $nextParentIds;
        }
    }
};
