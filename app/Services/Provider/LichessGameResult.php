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
     * `draw` / `stalemate`; aborted / noStart / unknown fall through to
     * "not decisive".
     */
    private const DECISIVE_STATUSES = ['mate', 'resign', 'outoftime', 'timeout', 'cheat'];

    private const DRAW_STATUSES = ['draw', 'stalemate'];

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

    /**
     * `AutoFetchLichessGameJob` only posts cards for decisive games — a draw
     * or aborted game shouldn't auto-narrate "X won" in chat.
     */
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

    public function winnerUsername(): ?string
    {
        return match ($this->winnerColor) {
            'white' => $this->whiteUsername,
            'black' => $this->blackUsername,
            default => null,
        };
    }
}
