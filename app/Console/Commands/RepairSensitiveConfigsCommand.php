<?php

namespace App\Console\Commands;

use App\Models\SystemConfig;
use App\Services\SystemConfigService;
use Illuminate\Console\Command;

class RepairSensitiveConfigsCommand extends Command
{
    protected $signature = 'config:repair-secrets';

    protected $description = 'Encrypt any sensitive config values that were stored as plain text (is_secret=false)';

    public function handle(SystemConfigService $service): int
    {
        $sensitiveKeys = [
            'r2_access_key',
            'r2_secret_key',
            'smtp_password',
        ];

        $repaired = 0;

        foreach ($sensitiveKeys as $key) {
            $record = SystemConfig::query()->where('key', $key)->first();

            if (! $record || $record->value === null) {
                $this->line("  skip  {$key} — not set");
                continue;
            }

            if ($record->is_secret) {
                $this->line("  ok    {$key} — already encrypted");
                continue;
            }

            // Value is plain text with is_secret=false → re-save via service to encrypt
            $plainValue = $record->value;
            $service->set($key, $plainValue);
            $repaired++;
            $this->info("  fixed {$key} — encrypted + is_secret=true");
        }

        if ($repaired > 0) {
            $this->info("Repaired {$repaired} config(s).");
        } else {
            $this->info('All sensitive configs are already secure.');
        }

        return self::SUCCESS;
    }
}
