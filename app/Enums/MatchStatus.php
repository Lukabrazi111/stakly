<?php

namespace App\Enums;

/**
 * Match lifecycle states. See milestones.md M6 + M10 + M16 + M34 for the full
 * state machine.
 *
 * LobbyFilling — M34: team-play (team_size > 1) match row exists from the
 *                moment the listing is created, in this pre-Pending state, so
 *                lobby chat (Message rows keyed on match_id) works from day 1
 *                of the lobby. Auto-fetch is skipped, dispute / cancellation
 *                are off (the lobby owns its own leave / kick affordances).
 *                Transitions to Pending when `LobbyLockAction` fires (all
 *                participants Ready'd).
 * Pending      — match is live; auto-fetch polling the game API for a result.
 *                4-hour deadline: if no result is found, the match flips to
 *                ManualReview via `ResolveMatchTimeoutAction`.
 * Disputed     — a participant clicked "Report a problem" while Pending. The
 *                game API is queried; resolves to Settled or ManualReview.
 * Settled      — winner determined (or draw — `winner_user_id IS NULL`),
 *                payout + fee posted to the ledger. Terminal.
 * ManualReview — game API couldn't determine a winner OR the match timed out
 *                without a result. Money stays locked until an admin resolves
 *                manually via the Filament panel (M12).
 * Cancelled    — both players agreed to call the match off (one requested,
 *                the other accepted). Both stakes refunded via `Wallet::release`,
 *                no platform fee charged. Terminal. Distinct from `Settled` w/ no
 *                winner (draw) — draws count as a played match, cancellation does
 *                not.
 */
enum MatchStatus: string
{
    case LobbyFilling = 'lobby_filling';
    case Pending = 'pending';
    case Disputed = 'disputed';
    case Settled = 'settled';
    case ManualReview = 'manual_review';
    case Cancelled = 'cancelled';

    /**
     * Non-terminal states where a participant is mid-flight: the match has
     * started but hasn't reached Settled / Cancelled. Drives the M36
     * "In Progress" matches view + the sidebar active-count badge.
     * LobbyFilling is excluded — the lobby owns that phase, no match is being
     * played yet.
     *
     * @return array<int, self>
     */
    public static function inProgress(): array
    {
        return [self::Pending, self::Disputed, self::ManualReview];
    }

    /**
     * The string values of {@see self::inProgress()}, for `whereIn` queries.
     *
     * @return array<int, string>
     */
    public static function inProgressValues(): array
    {
        return array_map(fn (self $status): string => $status->value, self::inProgress());
    }
}
