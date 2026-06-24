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

// M16 Phase 2 — periodic auto-fetch backstop. Catches matches where the
// page-visit / chat-send triggers haven't fired (player walked away after
// the game). 5-min cadence is the floor — finer would burn provider
// quota; coarser would let absent-player matches sit longer than needed.
// `withoutOverlapping` prevents stacked runs if a backlog ever forms.
Schedule::command('stakly:auto-fetch-pending')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// M34 P1 — ready-check timeout sweep. 5-min deadline needs sub-5-min
// cadence; per-minute keeps the timer feeling responsive. The action is
// row-locked + state-guarded so back-to-back runs on the same listing
// no-op safely.
Schedule::command('lobbies:sweep-ready-check-timeouts')
    ->everyMinute()
    ->withoutOverlapping();

// M34 P1 — 24h listing-fill timeout. Hourly cadence is plenty for a 24h
// timer; mirrors `listings:expire`'s posture for the non-team-play case.
Schedule::command('lobbies:sweep-fill-timeouts')
    ->hourly()
    ->withoutOverlapping();

// M38 P3 — Horizon metrics snapshot. The dashboard's throughput / runtime
// graphs stay blank until snapshots accumulate; 5-min cadence matches
// Horizon's documented default + the `metrics.trim_snapshots` retention.
Schedule::command('horizon:snapshot')->everyFiveMinutes();
