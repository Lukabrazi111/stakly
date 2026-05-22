<?php

namespace App\Services\GameApi;

use App\Enums\GameApiConfidence;
use App\Enums\LinkedAccountProvider;
use App\Enums\MessageType;
use App\Models\GameMatch;
use App\Models\Message;

/**
 * Real-data dispute arbitration for chess matches with Lichess-verified
 * players. Reads the auto-fetched game card posted by
 * `AutoFetchLichessGameJob` into the match's chat and returns the winner
 * that card names.
 *
 * Falls through to the injected `MockGameApi` fallback when:
 *   - No auto-fetch card exists (no Lichess-linked players, the
 *     single-decisive-game search returned zero or multiple candidates,
 *     or the queue worker hasn't processed the auto-fetch yet — race
 *     window during back-to-back confirms).
 *   - The card's winner_username doesn't resolve to either participant
 *     (defensive — auto-fetch already validates the snapshot match, but
 *     a snapshot mutated post-card-post shouldn't crash the dispute).
 *   - The provider is anything other than `lichess` (chess.com cards land
 *     in Phase 4b via its own driver; non-chess via M15 drivers).
 *
 * Confidence model: auto-fetched cards are always `Confirmed` because
 * `AutoFetchLichessGameJob` enforces single-decisive-game-in-window +
 * snapshot cross-check before posting. The card existing IS the
 * high-confidence signal — no further confidence math needed here.
 *
 * Idempotent: re-reading the same persisted card yields the same result,
 * so `ResolveDisputeAction`'s row-lock + status-guard re-entry is safe.
 */
final class LichessGameApi implements GameApi
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
                'driver' => 'lichess',
                'mode' => 'auto_fetched_card',
                'card' => $card,
            ],
        );
    }

    /**
     * Pull the most recent auto-fetched Lichess card off the match's chat.
     * Per-match query (bounded by chat length) — index on `match_id` covers
     * it.
     *
     * @return array<string, mixed>|null
     */
    private function loadAutoFetchedCard(GameMatch $match): ?array
    {
        $message = Message::query()
            ->where('match_id', $match->id)
            ->where('type', MessageType::System)
            ->whereJsonContains('attachments_json', [['source' => 'auto_fetch']])
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

            if (($entry['source'] ?? null) === 'auto_fetch'
                && ($entry['provider'] ?? null) === 'lichess') {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Map the card's `winner_username` back to the Stakly user_id via the
     * match's snapshotted Lichess handles. Case-insensitive — Lichess
     * canonicalises usernames as lowercase but their JSON preserves the
     * user's display case.
     *
     * @param  array<string, mixed>  $card
     */
    private function resolveWinnerUserId(GameMatch $match, array $card): ?int
    {
        $winnerUsername = $card['winner_username'] ?? null;

        if (! is_string($winnerUsername) || $winnerUsername === '') {
            return null;
        }

        $match->loadMissing('providerSnapshots', 'listing');

        $winnerLower = strtolower($winnerUsername);

        $creatorSnap = $match->snapshotUsername(GameMatch::SIDE_CREATOR, LinkedAccountProvider::Lichess);
        if ($creatorSnap !== null && strtolower($creatorSnap) === $winnerLower) {
            return $match->listing->user_id;
        }

        $takerSnap = $match->snapshotUsername(GameMatch::SIDE_TAKER, LinkedAccountProvider::Lichess);
        if ($takerSnap !== null && strtolower($takerSnap) === $winnerLower) {
            return $match->taker_user_id;
        }

        return null;
    }
}
