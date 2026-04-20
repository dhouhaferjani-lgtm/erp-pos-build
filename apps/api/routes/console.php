<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule: Lock expired fiscal periods daily at 1:00 AM
Schedule::command('fiscal:lock-expired-periods')
    ->dailyAt('01:00')
    ->withoutOverlapping()
    ->runInBackground();

// Schedule: Fraud pattern detection daily at 2:00 AM
Schedule::command('fraud:detect')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->runInBackground();

// Schedule: Expire old stock reservations every 15 minutes
Schedule::job(\App\Modules\Inventory\Application\Jobs\ExpireReservationsJob::class)
    ->everyFifteenMinutes()
    ->withoutOverlapping();

// Schedule: Check for expired batches daily at 1:30 AM
Schedule::job(\App\Modules\BatchExpiry\Jobs\DailyExpiryCheck::class)
    ->dailyAt('01:30')
    ->withoutOverlapping();

// Schedule: Poll platform for pending enrichment status updates
Schedule::command('enrichment:check-pending')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

// Schedule: Dispatch TechnicianCertificationExpiring events daily at 3:00 AM
Schedule::command(\App\Modules\Workshop\Technician\Infrastructure\Commands\CheckExpiringCertifications::class)
    ->dailyAt('03:00')
    ->withoutOverlapping();
