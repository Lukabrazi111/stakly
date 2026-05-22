<?php

namespace App\Services\Provider;

use Carbon\CarbonImmutable;

/**
 * Immutable DTO for a fetched/searched Lichess game.
 *
 * Mirrors the subset of fields chat game cards display + the fields the
 * auto-fetch path needs to make the "decisive in window" decision. PGN,
 * clocks, evals, openings are deliberately dropped — none belong in a chat
 * card and they inflate the API response.
 *
 * Usernames are passed through as Lichess returns them (`players.{color}.user.name`,
 * case-preserving). Cross-check against `game_matches.{side}_lichess_username`
 * lowercases both sides since Lichess's canonical handle is case-insensitive.
 */
final readonly class LichessGameResult
{
    /**
     * Lichess `status` values that signal a clear winner. Drawn games carry
     * `status = draw` / `stalemate` (no winner); aborted / noStart / unknown
     * fall through to "not decisive".
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
     * A game with a clear winner. `AutoFetchLichessGameJob` only posts cards
     * for decisive games — a draw or aborted game shouldn't auto-narrate
     * "X won" in chat.
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

    /**
     * Lichess username of the winning side. Null on draw / aborted / unknown.
     */
    public function winnerUsername(): ?string
    {
        return match ($this->winnerColor) {
            'white' => $this->whiteUsername,
            'black' => $this->blackUsername,
            default => null,
        };
    }
}
