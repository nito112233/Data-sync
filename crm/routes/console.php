<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

$batchSyncSchedule = Schedule::command('crm:sync-orders-batch')
    ->withoutOverlapping();

match ((int) config('services.erp.batch_sync_interval_seconds', 3600)) {
    1 => $batchSyncSchedule->everySecond(),
    2 => $batchSyncSchedule->everyTwoSeconds(),
    5 => $batchSyncSchedule->everyFiveSeconds(),
    10 => $batchSyncSchedule->everyTenSeconds(),
    15 => $batchSyncSchedule->everyFifteenSeconds(),
    20 => $batchSyncSchedule->everyTwentySeconds(),
    30 => $batchSyncSchedule->everyThirtySeconds(),
    60 => $batchSyncSchedule->everyMinute(),
    300 => $batchSyncSchedule->everyFiveMinutes(),
    600 => $batchSyncSchedule->everyTenMinutes(),
    900 => $batchSyncSchedule->everyFifteenMinutes(),
    1800 => $batchSyncSchedule->everyThirtyMinutes(),
    default => $batchSyncSchedule->hourly(),
};
