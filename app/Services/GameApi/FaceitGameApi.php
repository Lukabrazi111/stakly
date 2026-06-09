<?php

namespace App\Services\GameApi;

use App\Enums\GameApiConfidence;
use App\Enums\MessageType;
use App\Models\GameMatch;
use App\Models\Message;

/**
 * Real-data dispute arbitration for FACEIT-platform matches (M15 P4 Slice 2).
 * Sibling to `ChessGameApi`; same fallback-to-`MockGameApi` shape.
 *
 * Reads the auto-fetched FACEIT card off the match's chat (provider:'faceit',
 * source:'auto_fetch'). The FACEIT card carries `winner_user_id` resolved at
 * job time, so this driver only validates the card exists and the user is a
 * participant — no snapshot cross-check needed at arbitration time.
 *
 * Falls through to the injected `MockGameApi` when:
 *   - No FACEIT auto-fetch card is on the match (job hasn't run yet, or
 *     AC-incomplete / no_winner outcome short-circuited).
 *   - The card carries no `winner_user_id` (draw) — `MockGameApi` decides
 *     whether to surface as a winner-less Unknown or admin-forced result.
 *   - `winner_user_id` doesn't resolve to a match participant (defensive
 *     against a snapshot mutation after the card was posted).
 *
 * Confidence model: matches the chess driver. Auto-fetched cards are
 * `Confirmed` — the auto-fetch job has already enforced the anti-cheat gate
 * and opposing-roster check before posting.
 *
 * Idempotent: re-reading the same persisted card yields the same result.
 */
final class FaceitGameApi implements GameApi
{
    public function __construct(
        private readonly MockGameApi $fallback,
    ) {}

    public function getMatchResult(GameMatch $match): GameApiResult
    {
        $card = $this->loadAutoFetchedCard($match);

        if ($card === null) {
            return $this->fallback->getMatchResult($match);
        }

        $winnerUserId = $this->resolveWinnerUserId($match, $card);

        if ($winnerUserId === null) {
            return $this->fallback->getMatchResult($match);
        }

        return new GameApiResult(
            winner_user_id: $winnerUserId,
            confidence: GameApiConfidence::Confirmed,
            raw_response: [
                'driver' => 'faceit',
                'mode' => 'auto_fetched_card',
                'card' => $card,
            ],
        );
    }

    /**
     * Pull the most recent FACEIT auto-fetched card off the match's chat.
     * Per-match query bounded by chat length.
     *
     * @return array<string, mixed>|null
     */
    private function loadAutoFetchedCard(GameMatch $match): ?array
    {
        $message = Message::query()
            ->where('match_id', $match->id)
            ->where('type', MessageType::System)
            ->whereJsonContains('attachments_json', [['source' => 'auto_fetch', 'provider' => 'faceit']])
            ->latest('id')
            ->first();

        if ($message === null) {
            return null;
        }

        $attachments = is_array($message->attachments_json) ? $message->attachments_json : [];

        foreach ($attachments as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            if (($entry['source'] ?? null) !== 'auto_fetch') {
                continue;
            }

            if (($entry['provider'] ?? null) === 'faceit') {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Read `winner_user_id` directly off the card with a defensive check
     * that the named user is actually one of the match participants.
     * Mirrors `SettleFromCardAction`'s participant guard — a forged card
     * naming a foreign winner is rejected, not paid out.
     *
     * @param  array<string, mixed>  $card
     */
    private function resolveWinnerUserId(GameMatch $match, array $card): ?int
    {
        $winnerUserId = $card['winner_user_id'] ?? null;

        if (! is_int($winnerUserId)) {
            return null;
        }

        $match->loadMissing('listing');

        if ($winnerUserId === $match->listing->user_id) {
            return $winnerUserId;
        }

        if ($winnerUserId === $match->taker_user_id) {
            return $winnerUserId;
        }

        return null;
    }
}
