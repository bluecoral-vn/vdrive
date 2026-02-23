<?php

namespace App\Console\Commands;

use App\Models\File;
use App\Services\R2ClientService;
use Illuminate\Console\Command;

class BackfillChecksumsCommand extends Command
{
    protected $signature = 'files:backfill-checksums
                            {--chunk=50 : Number of files to process per batch}
                            {--dry-run : Show what would be updated without making changes}';

    protected $description = 'Compute and backfill SHA256 checksums for files that have null checksum_sha256';

    public function handle(R2ClientService $r2ClientService): int
    {
        $s3 = $r2ClientService->client();
        $bucket = $r2ClientService->bucket();
        $chunkSize = (int) $this->option('chunk');
        $dryRun = (bool) $this->option('dry-run');

        $total = File::query()->whereNull('checksum_sha256')->whereNull('deleted_at')->count();

        if ($total === 0) {
            $this->info('No files need checksum backfill.');

            return self::SUCCESS;
        }

        $this->info(sprintf('%s %d file(s) with missing checksums...', $dryRun ? '[DRY RUN] Found' : 'Processing', $total));

        $updated = 0;
        $failed = 0;

        File::query()
            ->whereNull('checksum_sha256')
            ->whereNull('deleted_at')
            ->chunkById($chunkSize, function ($files) use ($s3, $bucket, $dryRun, &$updated, &$failed) {
                foreach ($files as $file) {
                    try {
                        if ($dryRun) {
                            $this->line("  Would update: {$file->name} ({$file->id})");
                            $updated++;

                            continue;
                        }

                        $result = $s3->getObject([
                            'Bucket' => $bucket,
                            'Key'    => $file->r2_object_key,
                        ]);

                        $hash = hash('sha256', (string) $result['Body']);
                        $file->update(['checksum_sha256' => $hash]);

                        $this->line("  ✓ {$file->name} → {$hash}");
                        $updated++;
                    } catch (\Throwable $e) {
                        $this->warn("  ✗ {$file->name}: {$e->getMessage()}");
                        $failed++;
                    }
                }
            });

        $this->newLine();
        $this->info("Done. Updated: {$updated}, Failed: {$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
