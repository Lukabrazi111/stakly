<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Refund + flip status on listings past their expiry. Per-minute cadence;
// `withoutOverlapping` guards against a slow run colliding with the next tick.
Schedule::command('listings:expire')
    ->everyMinute()
    ->withoutOverlapping();
