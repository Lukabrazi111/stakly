<?php

namespace App\Services\Provider;

use Carbon\CarbonImmutable;

/**
 * Immutable DTO for a chess.com game from the Published Data API.
 *
 * Outcome semantics: chess.com uses `result` strings per-side. One side
 * `result === 'win'` → that side is the winner. Neither has `'win'` → draw
 * (or aborted — counted as non-decisive, not as draw).
 *
 * Usernames preserve chess.com's case. Cross-check against snapshotted
 * handles must lowercase both sides.
 */
final readonly class ChessComGameResult
{
    private const DRAW_RESULTS = [
        'agreed',
        'repetition',
        'stalemate',
        'insufficient',
        '50move',
        'timevsinsufficient',
    ];

    /**
     * M14 Slice 3b — chess.com's `abandoned` result is the analog to
     * Lichess's `aborted`. Both sides record `result: 'abandoned'` when
     * neither player engaged the game (the parser then sets `status` to
     * the white side's result string). Treated as cooperative-exit refund.
     */
    private const ABORTED_RESULTS = ['abandoned'];

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

    public function isAborted(): bool
    {
        return $this->winnerColor === null
            && in_array($this->status, self::ABORTED_RESULTS, true);
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
