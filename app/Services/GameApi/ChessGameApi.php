<?php

namespace App\Services\GameApi;

use App\Enums\GameApiConfidence;
use App\Enums\LinkedAccountProvider;
use App\Enums\MessageType;
use App\Models\GameMatch;
use App\Models\Message;

/**
 * Real-data dispute arbitration for chess matches. Reads the auto-fetched
 * card from the match's chat — regardless of provider (Lichess or
 * chess.com) — and returns the winner the card names.
 *
 * The card's `provider` field is the discriminator: a `provider: 'lichess'`
 * card uses the Lichess snapshot for cross-check, a `provider: 'chess_com'`
 * card uses the chess.com snapshot. M15's per-game adapters (FACEIT,
 * Riot, etc.) get their own arbitration drivers — this class is chess-only.
 *
 * Falls through to the injected `GameApi` fallback when:
 *   - No auto-fetched card exists (race window / unlinked players / no
 *     decisive game in the search / both jobs ran but found nothing).
 *   - The card's winner_username doesn't map to either snapshotted handle
 *     (defensive — auto-fetch validates upstream, but a snapshot mutated
 *     post-card-post shouldn't crash arbitration).
 *
 * M15 P5 — fallback type widened to the `GameApi` interface so this driver
 * can be composed with non-chess drivers (e.g. wrapped by `FaceitGameApi`
 * in `AppServiceProvider::bindGameApi()`). The default production chain
 * is `FaceitGameApi → ChessGameApi → MockGameApi`; each link handles its
 * own provider's cards and falls through to the next on miss.
 *
 * Confidence model: auto-fetched cards are always `Confirmed` because the
 * auto-fetch jobs already enforce single-decisive-game-in-window +
 * snapshot cross-check before posting. The card existing IS the
 * high-confidence signal.
 *
 * Idempotent: re-reading the same persisted card yields the same result,
 * so `ResolveDisputeAction`'s row-lock + status-guard re-entry is safe.
 */
final class ChessGameApi implements GameApi
{
    public function __construct(
        private readonly GameApi $fallback,
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
                'driver' => 'chess',
                'mode' => 'auto_fetched_card',
                'card' => $card,
            ],
        );
    }

    /**
     * Pull the most recent auto-fetched chess card off the match's chat —
     * either provider. Per-match query bounded by chat length.
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

            if (($entry['source'] ?? null) !== 'auto_fetch') {
                continue;
            }

            $provider = $entry['provider'] ?? null;

            if ($provider === 'lichess' || $provider === 'chess_com') {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Map the card's winner_username back to a Stakly user_id via the
     * match's snapshotted handles for THE CARD'S PROVIDER. The card's
     * `provider` field selects which side of the snapshot we read.
     *
     * @param  array<string, mixed>  $card
     */
    private function resolveWinnerUserId(GameMatch $match, array $card): ?int
    {
        $winnerUsername = $card['winner_username'] ?? null;

        if (! is_string($winnerUsername) || $winnerUsername === '') {
            return null;
        }

        $provider = match ($card['provider'] ?? null) {
            'lichess' => LinkedAccountProvider::Lichess,
            'chess_com' => LinkedAccountProvider::ChessCom,
            default => null,
        };

        if ($provider === null) {
            return null;
        }

        $match->loadMissing('providerSnapshots', 'listing');

        $winnerLower = strtolower($winnerUsername);

        $creatorSnap = $match->snapshotUsername(GameMatch::SIDE_CREATOR, $provider);
        if ($creatorSnap !== null && strtolower($creatorSnap) === $winnerLower) {
            return $match->listing->user_id;
        }

        $takerSnap = $match->snapshotUsername(GameMatch::SIDE_TAKER, $provider);
        if ($takerSnap !== null && strtolower($takerSnap) === $winnerLower) {
            return $match->taker_user_id;
        }

        return null;
    }
}
