<?php

namespace App\Services\Provider;

use Carbon\CarbonImmutable;

/**
 * Immutable DTO for a fetched FACEIT match. Mirrors `LichessGameResult` /
 * `ChessComGameResult` for the chess pipeline.
 *
 * Anti-cheat semantics: Stakly only settles matches where every player on
 * both rosters has `anticheat_required === true`. This is queue-agnostic —
 * FACEIT AC is mandatory on `competition_type === 'matchmaking'` but is
 * opt-in for Hubs, so the per-player boolean is the only reliable signal
 * that AC ran on both teams (M15 Phase 0 verdict).
 */
final readonly class FaceitMatchResult
{
    /**
     * @param  list<FaceitRosterPlayer>  $faction1Roster
     * @param  list<FaceitRosterPlayer>  $faction2Roster
     */
    public function __construct(
        public string $id,
        public string $game,
        public ?string $region,
        public string $competitionType,
        public string $status,
        public ?string $winnerFaction,
        public array $faction1Roster,
        public array $faction2Roster,
        public ?CarbonImmutable $startedAt,
        public ?CarbonImmutable $finishedAt,
    ) {}

    public function isFinished(): bool
    {
        return mb_strtoupper($this->status) === 'FINISHED';
    }

    /**
     * Every player on both rosters has FACEIT AC required. The settlement
     * gate — false if any slot opted out (Hub without AC enabled).
     */
    public function isAntiCheatComplete(): bool
    {
        foreach ([...$this->faction1Roster, ...$this->faction2Roster] as $player) {
            if (! $player->anticheatRequired) {
                return false;
            }
        }

        return true;
    }

    /**
     * Match has a definitive winner AND AC ran on every slot. The
     * conjunction settlement code reads — anything else routes through the
     * dispute fast-path (M15 Phase 5).
     */
    public function isDecisive(): bool
    {
        return $this->isFinished()
            && $this->winnerFaction !== null
            && $this->isAntiCheatComplete();
    }

    /**
     * Roster of the winning faction, or `[]` if the match has no winner
     * (aborted / cancelled / incomplete).
     *
     * @return list<FaceitRosterPlayer>
     */
    public function winnerRoster(): array
    {
        return match ($this->winnerFaction) {
            'faction1' => $this->faction1Roster,
            'faction2' => $this->faction2Roster,
            default => [],
        };
    }
}
