<?php

use App\Actions\Message\SendMessageAction;

/**
 * URL detection rules used by Phase 3 Slice 2 to find chat URLs worth
 * unfurling. The extractor is the gatekeeper between
 * "user typed something into chat" and "we queued an outbound HTTP
 * fetch" — its rules need to be tight on both ends (no false positives
 * that waste queue work, no false negatives that drop real URLs).
 */
test('extracts a single http(s) url', function () {
    $urls = SendMessageAction::extractLinkUrls('look at https://lichess.org/abc');

    expect($urls)->toBe(['https://lichess.org/abc']);
});

test('extracts multiple urls from a single message', function () {
    $urls = SendMessageAction::extractLinkUrls(
        'first https://lichess.org/abc then https://chess.com/game/123',
    );

    expect($urls)->toBe([
        'https://lichess.org/abc',
        'https://chess.com/game/123',
    ]);
});

test('case-insensitive http scheme detection', function () {
    $urls = SendMessageAction::extractLinkUrls('HTTP://EXAMPLE.COM/Path');

    expect($urls)->toBe(['HTTP://EXAMPLE.COM/Path']);
});

test('trims trailing punctuation that chat sentences attach to urls', function () {
    expect(SendMessageAction::extractLinkUrls('check https://foo.com.'))
        ->toBe(['https://foo.com'])
        ->and(SendMessageAction::extractLinkUrls('see https://foo.com,'))
        ->toBe(['https://foo.com'])
        ->and(SendMessageAction::extractLinkUrls('Wow! https://foo.com!'))
        ->toBe(['https://foo.com'])
        ->and(SendMessageAction::extractLinkUrls('here (https://foo.com)'))
        ->toBe(['https://foo.com']);
});

test('preserves trailing path segments that look like punctuation but are real', function () {
    // /path/sub is real path — the trim should only eat trailing
    // punctuation, not internal slashes or alphanumerics.
    expect(SendMessageAction::extractLinkUrls('see https://foo.com/path/sub'))
        ->toBe(['https://foo.com/path/sub']);
});

test('de-duplicates identical urls in the same message', function () {
    $urls = SendMessageAction::extractLinkUrls(
        'one https://foo.com/a and again https://foo.com/a',
    );

    expect($urls)->toBe(['https://foo.com/a']);
});

test('caps url list at the configured maximum', function () {
    // Build a message with 8 distinct URLs.
    $content = collect(range(1, 8))
        ->map(fn ($i) => "https://example-{$i}.com")
        ->implode(' ');

    $urls = SendMessageAction::extractLinkUrls($content);

    expect(count($urls))->toBe(5);
});

test('rejects bare-domain text without http scheme', function () {
    $urls = SendMessageAction::extractLinkUrls('check lichess.org and chess.com');

    expect($urls)->toBe([]);
});

test('rejects non-http schemes', function () {
    $urls = SendMessageAction::extractLinkUrls(
        'ftp://foo.com or file:///etc/passwd or javascript:alert(1)',
    );

    expect($urls)->toBe([]);
});

test('rejects urls with private IP literals at the pre-flight stage', function () {
    $urls = SendMessageAction::extractLinkUrls(
        'try http://127.0.0.1/admin and http://169.254.169.254/meta',
    );

    expect($urls)->toBe([]);
});

test('returns empty list for content with no urls', function () {
    expect(SendMessageAction::extractLinkUrls('just a chat message with no link'))
        ->toBe([]);
});
