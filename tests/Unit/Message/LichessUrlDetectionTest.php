<?php

use App\Actions\Message\SendMessageAction;

/**
 * `SendMessageAction::extractLichessGameId` is the gate that decides which
 * URLs in a chat message get the verified-card pipeline vs the generic OG
 * fetcher. False positives misroute non-game URLs to the API quota; false
 * negatives silently degrade game pastes to plain link cards.
 */

// ─── Positive matches ───────────────────────────────────────────────────────

test('canonical 8-char game URL is recognised', function () {
    expect(SendMessageAction::extractLichessGameId('https://lichess.org/abcdefgh'))
        ->toBe('abcdefgh');
});

test('http (no s) is accepted', function () {
    expect(SendMessageAction::extractLichessGameId('http://lichess.org/abcdefgh'))
        ->toBe('abcdefgh');
});

test('www subdomain is accepted', function () {
    expect(SendMessageAction::extractLichessGameId('https://www.lichess.org/abcdefgh'))
        ->toBe('abcdefgh');
});

test('embed wrapper is unwrapped to the inner game ID', function () {
    expect(SendMessageAction::extractLichessGameId('https://lichess.org/embed/abcdefgh'))
        ->toBe('abcdefgh');
});

test('trailing color segment does not block detection', function () {
    expect(SendMessageAction::extractLichessGameId('https://lichess.org/abcdefgh/white'))
        ->toBe('abcdefgh')
        ->and(SendMessageAction::extractLichessGameId('https://lichess.org/abcdefgh/black'))
        ->toBe('abcdefgh');
});

test('move-anchor suffix does not block detection', function () {
    expect(SendMessageAction::extractLichessGameId('https://lichess.org/abcdefgh#5'))
        ->toBe('abcdefgh');
});

test('query string suffix does not block detection', function () {
    expect(SendMessageAction::extractLichessGameId('https://lichess.org/abcdefgh?foo=bar'))
        ->toBe('abcdefgh');
});

test('12-char internal game ID is recognised', function () {
    expect(SendMessageAction::extractLichessGameId('https://lichess.org/abcdefghijkl'))
        ->toBe('abcdefghijkl');
});

test('mixed-case game ID preserves case', function () {
    expect(SendMessageAction::extractLichessGameId('https://lichess.org/AbCdEfGh'))
        ->toBe('AbCdEfGh');
});

// ─── Negative matches (reserved Lichess paths) ──────────────────────────────

test('reserved path "training" is not a game ID', function () {
    expect(SendMessageAction::extractLichessGameId('https://lichess.org/training'))
        ->toBeNull();
});

test('reserved path "analysis" is not a game ID', function () {
    expect(SendMessageAction::extractLichessGameId('https://lichess.org/analysis'))
        ->toBeNull();
});

test('reserved path "tournament" is not a game ID', function () {
    expect(SendMessageAction::extractLichessGameId('https://lichess.org/tournament'))
        ->toBeNull();
});

test('reserved path "streamer" is not a game ID', function () {
    expect(SendMessageAction::extractLichessGameId('https://lichess.org/streamer'))
        ->toBeNull();
});

test('reserved path "practice" is not a game ID', function () {
    expect(SendMessageAction::extractLichessGameId('https://lichess.org/practice'))
        ->toBeNull();
});

// ─── Negative matches (other shapes) ────────────────────────────────────────

test('non-lichess host is not a game ID', function () {
    expect(SendMessageAction::extractLichessGameId('https://chess.com/game/12345678'))
        ->toBeNull()
        ->and(SendMessageAction::extractLichessGameId('https://lichess.example.com/abcdefgh'))
        ->toBeNull();
});

test('too-short slug is not a game ID', function () {
    expect(SendMessageAction::extractLichessGameId('https://lichess.org/abc'))
        ->toBeNull()
        ->and(SendMessageAction::extractLichessGameId('https://lichess.org/abcdefg'))
        ->toBeNull();
});

test('too-long slug is not a game ID', function () {
    // 13 chars — outside the 8-12 window.
    expect(SendMessageAction::extractLichessGameId('https://lichess.org/abcdefghijklm'))
        ->toBeNull();
});

test('multi-segment reserved paths (study, team, tournament/foo) are not game IDs', function () {
    expect(SendMessageAction::extractLichessGameId('https://lichess.org/study/abcdefgh/chapter'))
        ->toBeNull()
        ->and(SendMessageAction::extractLichessGameId('https://lichess.org/tournament/abcdefgh'))
        ->toBeNull()
        ->and(SendMessageAction::extractLichessGameId('https://lichess.org/team/abcdefgh'))
        ->toBeNull();
});

test('plain text without a URL is not a game ID', function () {
    expect(SendMessageAction::extractLichessGameId('abcdefgh'))->toBeNull();
});
