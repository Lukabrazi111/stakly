<?php

use App\Support\SsrfGuard;

/**
 * SSRF guard coverage — the two entry points (`isPlausiblySafe` for the
 * synchronous request path, `isUrlSafe` for the queued-worker dial-time
 * check). The IP-literal cases are deterministic; for the DNS path we
 * lean on universally-resolvable hostnames (`localhost` → 127.0.0.1) and
 * RFC-2606 reserved invalid TLDs (`.invalid`) so the suite doesn't need
 * a network round-trip to a real public host.
 */

// ─── isPlausiblySafe (cheap pre-flight) ────────────────────────────────

test('plausibly-safe rejects non-http schemes', function (string $url) {
    expect(SsrfGuard::isPlausiblySafe($url))->toBeFalse();
})->with([
    'ftp' => ['ftp://example.com/file'],
    'file' => ['file:///etc/passwd'],
    'javascript' => ['javascript:alert(1)'],
    'data' => ['data:text/html,<script>x</script>'],
    'gopher' => ['gopher://example.com/'],
]);

test('plausibly-safe rejects private and reserved IP literals', function (string $url) {
    expect(SsrfGuard::isPlausiblySafe($url))->toBeFalse();
})->with([
    'loopback v4' => ['http://127.0.0.1/'],
    'loopback v4 zero' => ['http://0.0.0.0/'],
    'private 10' => ['http://10.0.0.1/'],
    'private 172.16' => ['http://172.16.0.1/'],
    'private 172.31' => ['http://172.31.0.1/'],
    'private 192.168' => ['http://192.168.1.1/'],
    'link-local' => ['http://169.254.169.254/'],
    'docker bridge' => ['http://172.17.0.1/'],
    'multicast' => ['http://224.0.0.1/'],
]);

test('plausibly-safe accepts public IP literals', function () {
    expect(SsrfGuard::isPlausiblySafe('http://8.8.8.8/'))->toBeTrue()
        ->and(SsrfGuard::isPlausiblySafe('https://1.1.1.1/'))->toBeTrue();
});

test('plausibly-safe lets hostnames through without DNS', function () {
    // The cheap pre-flight intentionally does not resolve hostnames — that
    // would block the chat-send request path. Even an obviously-internal
    // name like 'localhost' passes here (the queued worker rejects it
    // post-resolution via isUrlSafe).
    expect(SsrfGuard::isPlausiblySafe('http://localhost/'))->toBeTrue()
        ->and(SsrfGuard::isPlausiblySafe('https://lichess.org/abc'))->toBeTrue();
});

test('plausibly-safe rejects malformed URLs', function (string $url) {
    expect(SsrfGuard::isPlausiblySafe($url))->toBeFalse();
})->with([
    'empty' => [''],
    'no scheme' => ['lichess.org/abc'],
    'no host' => ['http://'],
    'garbage' => ['not a url'],
]);

// ─── isUrlSafe (DNS-resolved, queued-worker path) ──────────────────────

test('url-safe rejects hostnames that resolve to private space', function () {
    // `localhost` is the universal example: every libc resolver returns
    // 127.0.0.1, which the IP-range check rejects.
    expect(SsrfGuard::isUrlSafe('http://localhost/'))->toBeFalse();
});

test('url-safe rejects unresolvable hostnames', function () {
    // RFC 2606 reserves `.invalid` for guaranteed-non-resolvable use.
    expect(SsrfGuard::isUrlSafe('http://nonexistent-1234567890.invalid/'))->toBeFalse();
});

test('url-safe inherits the plausibly-safe checks', function () {
    expect(SsrfGuard::isUrlSafe('ftp://example.com/'))->toBeFalse()
        ->and(SsrfGuard::isUrlSafe('http://127.0.0.1/'))->toBeFalse()
        ->and(SsrfGuard::isUrlSafe('http://10.0.0.1/'))->toBeFalse();
});

test('url-safe accepts IP literals in public space without DNS', function () {
    // IP literals skip DNS resolution — they are what they are.
    expect(SsrfGuard::isUrlSafe('http://8.8.8.8/'))->toBeTrue();
});
