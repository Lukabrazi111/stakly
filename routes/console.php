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

// Resolve matches stuck in Pending past their 4h confirmation window.
// Every ten minutes is enough granularity — the timer on the frontend
// already shows "Expired" so the user knows resolution is pending.
// `withoutOverlapping` guards against backlogs causing collisions.
Schedule::command('matches:resolve-timeouts')
    ->everyTenMinutes()
    ->withoutOverlapping();
