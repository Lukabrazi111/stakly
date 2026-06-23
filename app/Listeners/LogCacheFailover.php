<?php

namespace App\Listeners;

use Illuminate\Cache\Events\CacheFailedOver;
use Illuminate\Support\Facades\Log;

/**
 * M38 — the default cache runs on a `redis → array` failover store. When Redis
 * is unreachable the store degrades to the in-memory tail so the homepage, the
 * circuit breaker, and the rate limiter keep serving instead of 500ing. Laravel
 * fires `CacheFailedOver` once per outage transition (not per call), so logging
 * loudly here is alertable without spam — and it matters: while degraded the
 * breaker is per-process and settlement timing is affected.
 */
class LogCacheFailover
{
    public function handle(CacheFailedOver $event): void
    {
        Log::critical('Cache store failed over — Redis may be unreachable.', [
            'store' => $event->storeName,
            'exception' => $event->exception::class,
            'message' => $event->exception->getMessage(),
        ]);
    }
}
