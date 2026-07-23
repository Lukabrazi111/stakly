<?php

namespace Tests\Support;

use Illuminate\Contracts\Cache\Store;
use RuntimeException;

/**
 * A cache store whose every operation throws — stands in for an unreachable
 * Redis so the `failover` driver's graceful-degradation path can be exercised
 * without a live broken server. `FailoverStore` catches `Throwable`, so a plain
 * `RuntimeException` reproduces the same fall-through as a real `RedisException`.
 */
class ThrowingCacheStore implements Store
{
    private function boom(): never
    {
        throw new RuntimeException('Simulated cache backend outage.');
    }

    public function get($key)
    {
        $this->boom();
    }

    public function many(array $keys)
    {
        $this->boom();
    }

    public function put($key, $value, $seconds)
    {
        $this->boom();
    }

    public function putMany(array $values, $seconds)
    {
        $this->boom();
    }

    public function increment($key, $value = 1)
    {
        $this->boom();
    }

    public function decrement($key, $value = 1)
    {
        $this->boom();
    }

    public function forever($key, $value)
    {
        $this->boom();
    }

    public function forget($key)
    {
        $this->boom();
    }

    public function touch($key, $seconds)
    {
        $this->boom();
    }

    public function flush()
    {
        $this->boom();
    }

    public function getPrefix()
    {
        return '';
    }
}
