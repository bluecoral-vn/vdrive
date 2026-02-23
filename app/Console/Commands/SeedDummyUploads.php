<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\R2ClientService;
use App\Services\UploadService;
use GuzzleHttp\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class SeedDummyUploads extends Command
{
    protected $signature = 'uploads:seed-dummy
        {--count=10 : Number of files to upload}
        {--min=1 : Minimum file size in MB}
        {--max=12 : Maximum file size in MB}
        {--user= : User ID to own the files (defaults to first user)}';

    protected $description = 'Upload dummy files to R2 for testing';

    private const CHUNK_SIZE = 5 * 1024 * 1024; // 5MB per part (R2 minimum)

    private array $mimeTypes = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'png' => 'image/png',
        'zip' => 'application/zip',
        'mp4' => 'video/mp4',
        'txt' => 'text/plain',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'csv' => 'text/csv',
    ];

    public function handle(UploadService $uploadService, R2ClientService $r2ClientService): int
    {
        $count = (int) $this->option('count');
        $minMb = (int) $this->option('min');
        $maxMb = (int) $this->option('max');

        $user = $this->option('user')
            ? User::findOrFail($this->option('user'))
            : User::first();

        if (! $user) {
            $this->error('No users found. Run migrations and seeders first.');

            return self::FAILURE;
        }

        $this->info("Uploading {$count} dummy files to R2 as user [{$user->id}] {$user->name}");
        $this->newLine();

        $s3 = $r2ClientService->client();
        $bucket = $r2ClientService->bucket();
        $http = new Client(['verify' => false, 'timeout' => 120]);

        $bar = $this->output->createProgressBar($count);
        $bar->setFormat(' %current%/%max% [%bar%] %message%');

        $totalBytes = 0;

        for ($i = 1; $i <= $count; $i++) {
            $sizeMb = rand($minMb, $maxMb);
            $sizeBytes = $sizeMb * 1024 * 1024;
            $ext = array_rand($this->mimeTypes);
            $mime = $this->mimeTypes[$ext];
            $filename = 'dummy-'.Str::random(6).".{$ext}";

            $bar->setMessage("{$filename} ({$sizeMb}MB)");

            try {
                // 1. Init multipart upload
                $session = $uploadService->initUpload($user, $filename, $mime, $sizeBytes, null);

                // 2. Upload parts
                $parts = [];
                $partNumber = 1;
                $remaining = $sizeBytes;

                while ($remaining > 0) {
                    $chunkSize = min(self::CHUNK_SIZE, $remaining);

                    // Generate random bytes for this part
                    $data = random_bytes($chunkSize);

                    // Get presigned URL
                    $url = $uploadService->presignPart($session, $partNumber);

                    // PUT directly to R2
                    $response = $http->put($url, [
                        'body' => $data,
                        'headers' => [
                            'Content-Length' => $chunkSize,
                        ],
                    ]);

                    $etag = $response->getHeader('ETag')[0] ?? '';

                    $parts[] = [
                        'part_number' => $partNumber,
                        'etag' => $etag,
                    ];

                    $remaining -= $chunkSize;
                    $partNumber++;

                    // Free memory
                    unset($data);
                }

                // 3. Complete
                $file = $uploadService->completeUpload($session, $parts);
                $totalBytes += $sizeBytes;

            } catch (\Throwable $e) {
                $this->newLine();
                $this->error("  ✗ {$filename}: {$e->getMessage()}");

                continue;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $totalMb = round($totalBytes / 1024 / 1024, 1);
        $this->info("✅ Done! Uploaded {$count} files ({$totalMb}MB total) to R2.");

        return self::SUCCESS;
    }
}
