<?php

namespace App\Services\Provider;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Parses HTTP rate-limit retry hints from a provider response. Honors
 * `Retry-After` (HTTP RFC 9110) first; falls back to `X-RateLimit-Reset`
 * (common but non-standard). Returns null when both headers are absent or
 * unparseable — the caller (M14 Slice 2b auto-fetch jobs) then falls back
 * to the job's default exponential backoff.
 */
class RateLimitHeaderParser
{
    /**
     * Heuristic threshold for distinguishing `X-RateLimit-Reset` as a unix
     * timestamp (large value) vs a seconds-from-now delay (small value).
     * 946684800 = 2000-01-01 — anything past this is unambiguously a
     * timestamp in any plausible production environment.
     */
    private const TIMESTAMP_THRESHOLD = 946684800;

    public static function parseRetryAt(Response $response): ?CarbonInterface
    {
        return self::parseRetryAfter($response->header('Retry-After'))
            ?? self::parseRateLimitReset($response->header('X-RateLimit-Reset'));
    }

    /**
     * `Retry-After` per RFC 9110: integer seconds delay OR an HTTP-date.
     * Non-positive / unparseable → null (caller falls back to default backoff).
     */
    private static function parseRetryAfter(?string $value): ?CarbonInterface
    {
        if ($value === null || $value === '') {
            return null;
        }

        $trimmed = trim($value);

        if (is_numeric($trimmed)) {
            $seconds = (int) $trimmed;

            return $seconds > 0
                ? CarbonImmutable::now()->addSeconds($seconds)
                : null;
        }

        try {
            return CarbonImmutable::parse($trimmed);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * `X-RateLimit-Reset` has no formal standard. Numeric values past the
     * year-2000 epoch threshold are interpreted as unix timestamps; smaller
     * values as seconds-from-now.
     */
    private static function parseRateLimitReset(?string $value): ?CarbonInterface
    {
        if ($value === null || ! is_numeric(trim($value))) {
            return null;
        }

        $num = (int) trim($value);

        if ($num <= 0) {
            return null;
        }

        return $num > self::TIMESTAMP_THRESHOLD
            ? CarbonImmutable::createFromTimestamp($num)
            : CarbonImmutable::now()->addSeconds($num);
    }
}
