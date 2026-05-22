<?php

namespace App\Services\Provider;

use Carbon\CarbonImmutable;

/**
 * Immutable DTO for a chess.com game fetched from the Published Data API.
 *
 * chess.com's API returns games via a per-user monthly archive
 * (`/pub/player/{username}/games/{YYYY}/{MM}`); a single game appears in
 * both players' archives. We parse the subset of fields the chat card
 * renderer + dispute arbitration need.
 *
 * Outcome semantics — chess.com uses `result` strings per-side. We map:
 *   - One side `result === 'win'` → that side is the winner.
 *   - Neither has `'win'` → draw (or aborted). Treat as draw for the
 *     `isDraw()` helper; aborted games still count as no-winner / non-decisive.
 *
 * Usernames preserve case as chess.com surfaces them
 * (`players.{color}.username`); cross-check against snapshotted handles
 * uses `strtolower(...)` on both sides for case-insensitive compare.
 */
final readonly class ChessComGameResult
{
    /**
     * Per-side result strings chess.com uses to indicate a draw outcome.
     */
    private const DRAW_RESULTS = [
        'agreed',
        'repetition',
        'stalemate',
        'insufficient',
        '50move',
        'timevsinsufficient',
    ];

    public function __construct(
        public string $id,
        public string $url,
        public string $whiteUsername,
        public string $blackUsername,
        public ?string $winnerColor,
        public string $status,
        public string $speed,
        public string $variant,
        public bool $rated,
        public CarbonImmutable $createdAt,
        public CarbonImmutable $endedAt,
    ) {}

    public function isDecisive(): bool
    {
        return $this->winnerColor !== null;
    }

    public function isDraw(): bool
    {
        return $this->winnerColor === null
            && in_array($this->status, self::DRAW_RESULTS, true);
    }

    public function winnerUsername(): ?string
    {
        return match ($this->winnerColor) {
            'white' => $this->whiteUsername,
            'black' => $this->blackUsername,
            default => null,
        };
    }
}
