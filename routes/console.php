<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('app:purge-anonymized-criminal-records')->dailyAt('03:00');
Schedule::command('app:notify-expiring-criminal-records')->dailyAt('08:00');
Schedule::command('notifications:check-expirations')->dailyAt('07:00');
Schedule::command('sanctum:prune-expired --hours=24')->dailyAt('03:30');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
