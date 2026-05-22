<?php

use App\Actions\Message\SendMessageAction;

/**
 * `SendMessageAction::extractChessComGameUrl` decides which URLs hit the
 * chess.com paste-path pipeline. Same precision discipline as the Lichess
 * detector: false positives misroute non-game URLs at the cost of API
 * quota; false negatives silently degrade game pastes to plain link cards.
 */

// ─── Positive matches ───────────────────────────────────────────────────────

test('canonical live game URL is recognised', function () {
    expect(SendMessageAction::extractChessComGameUrl('https://www.chess.com/game/live/12345678901'))
        ->toBe('https://www.chess.com/game/live/12345678901');
});

test('daily game URL is recognised', function () {
    expect(SendMessageAction::extractChessComGameUrl('https://www.chess.com/game/daily/98765432109'))
        ->toBe('https://www.chess.com/game/daily/98765432109');
});

test('legacy /live/game/{id} URL is recognised', function () {
    expect(SendMessageAction::extractChessComGameUrl('https://www.chess.com/live/game/12345678901'))
        ->toBe('https://www.chess.com/live/game/12345678901');
});

test('analysis URL is recognised', function () {
    expect(SendMessageAction::extractChessComGameUrl('https://www.chess.com/analysis/game/live/12345678901'))
        ->toBe('https://www.chess.com/analysis/game/live/12345678901');
});

test('http (no s) is accepted', function () {
    expect(SendMessageAction::extractChessComGameUrl('http://www.chess.com/game/live/12345678901'))
        ->toBe('http://www.chess.com/game/live/12345678901');
});

test('missing www subdomain is accepted', function () {
    expect(SendMessageAction::extractChessComGameUrl('https://chess.com/game/live/12345678901'))
        ->toBe('https://chess.com/game/live/12345678901');
});

test('URL with trailing path/segments is still recognised by prefix match', function () {
    expect(SendMessageAction::extractChessComGameUrl('https://www.chess.com/game/live/12345678901?move=5'))
        ->toBe('https://www.chess.com/game/live/12345678901?move=5');
});

// ─── Negative matches ───────────────────────────────────────────────────────

test('chess.com lessons URL is NOT a game', function () {
    expect(SendMessageAction::extractChessComGameUrl('https://www.chess.com/lessons/some-lesson'))
        ->toBeNull();
});

test('chess.com tournament URL is NOT a game', function () {
    expect(SendMessageAction::extractChessComGameUrl('https://www.chess.com/tournament/some-tournament'))
        ->toBeNull();
});

test('chess.com player profile URL is NOT a game', function () {
    expect(SendMessageAction::extractChessComGameUrl('https://www.chess.com/member/alice-chesscom'))
        ->toBeNull();
});

test('chess.com news URL is NOT a game', function () {
    expect(SendMessageAction::extractChessComGameUrl('https://www.chess.com/news/view/some-article'))
        ->toBeNull();
});

test('non-chess.com host is NOT a chess.com game', function () {
    expect(SendMessageAction::extractChessComGameUrl('https://www.chess.example.com/game/live/12345678901'))
        ->toBeNull()
        ->and(SendMessageAction::extractChessComGameUrl('https://lichess.org/abcdefgh'))
        ->toBeNull();
});

test('URL without numeric game id is NOT recognised', function () {
    expect(SendMessageAction::extractChessComGameUrl('https://www.chess.com/game/live/not-a-number'))
        ->toBeNull();
});

test('URL without /game/ path is NOT recognised', function () {
    expect(SendMessageAction::extractChessComGameUrl('https://www.chess.com/live/12345678901'))
        ->toBeNull();
});

test('plain text is not a URL', function () {
    expect(SendMessageAction::extractChessComGameUrl('12345678901'))
        ->toBeNull();
});
