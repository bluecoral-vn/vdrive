<?php

use App\Jobs\RunDatabaseBackupJob;
use App\Services\DatabaseBackupService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('activity:purge --dispatch')->daily()->at('02:00');
Schedule::command('email-logs:purge --dispatch')->daily()->at('02:05');
Schedule::command('backup:cleanup --dispatch')->daily()->at('03:00');

Schedule::call(function () {
    $service = app(DatabaseBackupService::class);
    if ($service->isEnabled() && $service->shouldRunNow()) {
        RunDatabaseBackupJob::dispatch();
    }
})->everyMinute()->name('database-backup-check')->withoutOverlapping();

Schedule::command('demo:reset')
    ->everyThirtyMinutes()
    ->when(fn () => app()->environment('demo'))
    ->withoutOverlapping()
    ->name('demo-reset');
