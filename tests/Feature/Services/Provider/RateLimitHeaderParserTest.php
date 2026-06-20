<?php

use App\Services\Provider\RateLimitHeaderParser;
use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;

/**
 * Build a Response with arbitrary headers from a fake PSR-7 response, the
 * same way Laravel's `Http::fake()` does internally.
 *
 * @param  array<string, string|int>  $headers
 */
function rateLimitResponse(array $headers): Response
{
    return new Response(new PsrResponse(429, $headers));
}

test('returns null when no relevant headers are present', function () {
    $response = rateLimitResponse([]);

    expect(RateLimitHeaderParser::parseRetryAt($response))->toBeNull();
});

// ─── Retry-After ───────────────────────────────────────────────────────────

test('Retry-After numeric: parses as seconds-from-now', function () {
    CarbonImmutable::setTestNow('2026-06-06T12:00:00Z');

    $response = rateLimitResponse(['Retry-After' => '120']);

    $retryAt = RateLimitHeaderParser::parseRetryAt($response);

    expect($retryAt)->not->toBeNull();
    expect($retryAt->getTimestamp())->toBe(CarbonImmutable::parse('2026-06-06T12:02:00Z')->getTimestamp());
});

test('Retry-After HTTP-date: parses as absolute time', function () {
    $response = rateLimitResponse(['Retry-After' => 'Wed, 21 Oct 2026 07:28:00 GMT']);

    $retryAt = RateLimitHeaderParser::parseRetryAt($response);

    expect($retryAt)->not->toBeNull();
    expect($retryAt->getTimestamp())->toBe(CarbonImmutable::parse('2026-10-21T07:28:00Z')->getTimestamp());
});

test('Retry-After zero: returns null (no specific instruction)', function () {
    $response = rateLimitResponse(['Retry-After' => '0']);

    expect(RateLimitHeaderParser::parseRetryAt($response))->toBeNull();
});

test('Retry-After negative: returns null', function () {
    $response = rateLimitResponse(['Retry-After' => '-5']);

    expect(RateLimitHeaderParser::parseRetryAt($response))->toBeNull();
});

test('Retry-After garbage: returns null', function () {
    $response = rateLimitResponse(['Retry-After' => 'not a real date']);

    expect(RateLimitHeaderParser::parseRetryAt($response))->toBeNull();
});

// ─── X-RateLimit-Reset ─────────────────────────────────────────────────────

test('X-RateLimit-Reset as unix timestamp: parses as absolute time', function () {
    $timestamp = CarbonImmutable::parse('2026-06-06T12:30:00Z')->getTimestamp();

    $response = rateLimitResponse(['X-RateLimit-Reset' => (string) $timestamp]);

    $retryAt = RateLimitHeaderParser::parseRetryAt($response);

    expect($retryAt)->not->toBeNull();
    expect($retryAt->getTimestamp())->toBe($timestamp);
});

test('X-RateLimit-Reset as small numeric: parses as seconds-from-now', function () {
    CarbonImmutable::setTestNow('2026-06-06T12:00:00Z');

    $response = rateLimitResponse(['X-RateLimit-Reset' => '60']);

    $retryAt = RateLimitHeaderParser::parseRetryAt($response);

    expect($retryAt)->not->toBeNull();
    expect($retryAt->getTimestamp())->toBe(CarbonImmutable::parse('2026-06-06T12:01:00Z')->getTimestamp());
});

test('X-RateLimit-Reset zero: returns null', function () {
    $response = rateLimitResponse(['X-RateLimit-Reset' => '0']);

    expect(RateLimitHeaderParser::parseRetryAt($response))->toBeNull();
});

test('X-RateLimit-Reset non-numeric: returns null', function () {
    $response = rateLimitResponse(['X-RateLimit-Reset' => 'soon']);

    expect(RateLimitHeaderParser::parseRetryAt($response))->toBeNull();
});

// ─── Precedence ───────────────────────────────────────────────────────────

test('Retry-After wins over X-RateLimit-Reset when both present', function () {
    CarbonImmutable::setTestNow('2026-06-06T12:00:00Z');

    $response = rateLimitResponse([
        'Retry-After' => '30',
        'X-RateLimit-Reset' => (string) CarbonImmutable::parse('2026-06-06T13:00:00Z')->getTimestamp(),
    ]);

    $retryAt = RateLimitHeaderParser::parseRetryAt($response);

    // Retry-After (30s from now) wins over X-RateLimit-Reset (1h from now).
    expect($retryAt->getTimestamp())->toBe(CarbonImmutable::parse('2026-06-06T12:00:30Z')->getTimestamp());
});

test('Retry-After fallback to X-RateLimit-Reset when Retry-After unparseable', function () {
    $timestamp = CarbonImmutable::parse('2026-06-06T12:30:00Z')->getTimestamp();

    $response = rateLimitResponse([
        'Retry-After' => 'garbage',
        'X-RateLimit-Reset' => (string) $timestamp,
    ]);

    expect(RateLimitHeaderParser::parseRetryAt($response)->getTimestamp())->toBe($timestamp);
});
