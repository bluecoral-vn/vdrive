<?php

namespace App\Console\Commands;

use App\Services\DemoResetService;
use Illuminate\Console\Command;

class DemoReset extends Command
{
    protected $signature = 'demo:reset
        {--force : Skip APP_ENV check}';

    protected $description = 'Reset demo environment: delete all uploads from DB and R2, re-seed initial users';

    public function handle(DemoResetService $service): int
    {
        if (! app()->environment('demo') && ! $this->option('force')) {
            $this->error('Demo reset can only run when APP_ENV=demo. Use --force to override.');

            return self::FAILURE;
        }

        $this->info('Starting demo reset...');

        try {
            // Temporarily set environment to demo if --force was used
            $originalEnv = app()->environment();
            if ($this->option('force') && ! app()->environment('demo')) {
                app()->detectEnvironment(fn () => 'demo');
            }

            $stats = $service->reset();

            // Restore original environment
            if ($this->option('force') && $originalEnv !== 'demo') {
                app()->detectEnvironment(fn () => $originalEnv);
            }

            $this->newLine();
            $this->info('✅ Demo reset completed!');
            $this->table(
                ['Metric', 'Count'],
                [
                    ['Files deleted', $stats['files_deleted']],
                    ['Folders deleted', $stats['folders_deleted']],
                    ['R2 objects deleted', $stats['r2_objects_deleted']],
                    ['Users re-seeded', $stats['users_reseeded']],
                ],
            );

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Demo reset failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
