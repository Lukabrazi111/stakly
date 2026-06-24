<?php

/*
 * M38 P4 — DigitalOcean Managed Redis enforces TLS, which Laravel enables only
 * via the per-connection `scheme` key (it never turns on TLS automatically).
 * The settlement pipeline runs on the `queue` connection, so if ANY of the four
 * redis connections were missing `scheme`, that one would silently stay `tcp`
 * and fail against a TLS-only endpoint in production — e.g. settlement jobs
 * could not enqueue while the others worked. These tests pin the invariant:
 * every redis connection honors REDIS_SCHEME, and they can never diverge.
 */

$connections = ['default', 'cache', 'queue', 'session'];

test('every redis connection exposes a scheme so REDIS_SCHEME applies to all of them', function (string $connection) {
    $config = config("database.redis.{$connection}");

    expect($config)->toHaveKey('scheme')
        ->and($config['scheme'])->not->toBeNull();
})->with($connections);

test('all four redis connections resolve the same scheme (queue can never diverge from cache/session)', function () use ($connections) {
    $schemes = collect($connections)
        ->map(fn (string $connection): mixed => config("database.redis.{$connection}.scheme"))
        ->unique();

    expect($schemes)->toHaveCount(1)
        ->and($schemes->first())->toBe('tcp'); // local/test default; prod sets REDIS_SCHEME=tls
});
