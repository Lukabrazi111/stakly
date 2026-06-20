<?php

namespace App\Services\Provider;

use Carbon\CarbonImmutable;

/**
 * Immutable DTO for a fetched/searched Lichess game. Cross-check against
 * snapshotted handles must lowercase both sides — Lichess's canonical handle
 * is case-insensitive.
 */
final readonly class LichessGameResult
{
    /**
     * Lichess `status` values that signal a clear winner. Drawn games carry
     * `draw` / `stalemate`; aborted games carry `aborted` / `noStart`;
     * `unknown` falls through to "not classified" (silent skip).
     */
    private const DECISIVE_STATUSES = ['mate', 'resign', 'outoftime', 'timeout', 'cheat'];

    private const DRAW_STATUSES = ['draw', 'stalemate'];

    /**
     * M14 Slice 3b — aborted games are cooperative-exit refunds. `aborted`
     * fires when the game ends with 0-1 moves played (someone disconnects
     * or refuses to move); `noStart` is the never-began variant. Both
     * settle as a draw with both stakes refunded.
     */
    private const ABORTED_STATUSES = ['aborted', 'noStart'];

    public function __construct(
        public string $id,
        public string $whiteUsername,
        public string $blackUsername,
        public ?string $winnerColor,
        public string $status,
        public string $speed,
        public string $variant,
        public bool $rated,
        public CarbonImmutable $createdAt,
        public CarbonImmutable $lastMoveAt,
    ) {}

    public function isDecisive(): bool
    {
        return $this->winnerColor !== null
            && in_array($this->status, self::DECISIVE_STATUSES, true);
    }

    public function isDraw(): bool
    {
        return $this->winnerColor === null
            && in_array($this->status, self::DRAW_STATUSES, true);
    }

    public function isAborted(): bool
    {
        return $this->winnerColor === null
            && in_array($this->status, self::ABORTED_STATUSES, true);
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
