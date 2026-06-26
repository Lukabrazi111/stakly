<?php

namespace App\Actions\GameMatch;

use App\Actions\Message\PostSystemMessageAction;
use App\Enums\LinkedAccountProvider;
use App\Enums\ListingStatus;
use App\Enums\MatchStatus;
use App\Enums\TimeControl;
use App\Models\GameMatch;
use App\Models\LinkedAccount;
use App\Models\Listing;
use App\Models\MatchProviderSnapshot;
use App\Models\User;
use App\Notifications\ListingTakenNotification;
use App\Services\Wallet;
use Illuminate\Support\Facades\DB;

/**
 * Takes an open listing: escrows the taker's stake and creates the match
 * row, all inside one `DB::transaction` with a row lock on the listing.
 *
 * Four sentinel returns the controller maps to user-facing flows:
 *   - `GameMatch` instance → success; controller redirects to match page.
 *   - `'not_linked'`       → taker has no verified chess provider account
 *                            (M8 Phase 5 take-gate). Controller redirects
 *                            to /settings/linked-accounts with CTA toast.
 *   - `'race_lost'`        → listing state changed between page load + submit
 *                            (Taken / Expired / Cancelled / past-expiry).
 *                            Controller redirects to listing with info toast.
 *   - `'owner_inactive'`   → owner flipped to Inactive Mode between page load
 *                            + submit. Controller info toast + redirect.
 *   - `'already_in_match'` → taker is already in an in-flight match for this
 *                            game (M37). Controller warning toast + redirect.
 *   - `'owner_busy'`       → the listing owner is already in an in-flight match
 *                            for this game; taking would start a second.
 *                            Controller info toast + redirect.
 *
 * Self-take is hard-rejected via `abort(403)` since the UI hides the Take
 * button for owners; reaching the action with a self-take is a hand-crafted
 * request.
 *
 * Insufficient-balance race propagates `InsufficientBalanceException` to
 * the controller, which maps to a `ValidationException` keyed on `amount`.
 *
 * Idempotency: `Wallet::hold` is keyed `match-take:{listing_id}`. A
 * successful Take followed by a retry POST hits the race-lost branch
 * (listing is now Taken) and gets the friendly redirect — double-clicks
 * are safe.
 */
class TakeListingAction
{
    public function __construct(
        private readonly PostSystemMessageAction $postSystem,
    ) {}

    public function handle(User $user, Listing $listing): GameMatch|string
    {
        // M34 P3.1 Slice B.1 — team-play listings own their join flow via
        // `JoinLobbyAction`; reaching the chess Take path means a crafted
        // POST (FE branches the CTA to "View lobby"). Returning a sentinel
        // before any wallet / match writes happen avoids the UNIQUE-constraint
        // 500 that would otherwise fire — the team-play listing already has
        // a `LobbyFilling` `GameMatch` row from `CreateTeamPlayListingAction`.
        if ($listing->isTeamPlay()) {
            return 'not_takeable';
        }

        // Platform-specific take-gate (M8 Phase 5 Slice B). Taker must be
        // verified on the listing's platform — players who only linked the
        // other provider literally couldn't play each other on the right
        // platform. Frontend disables the Take CTA with platform-named
        // copy ("Link Lichess to take"); reaching here means a stale tab
        // or a crafted call.
        if (! $user->isVerifiedOn($listing->platform)) {
            return 'not_linked';
        }

        $result = DB::transaction(function () use ($listing, $user) {
            $locked = Listing::query()->lockForUpdate()->findOrFail($listing->id);

            $this->assertNotSelfTake($locked, $user);

            if ($this->listingIsNoLongerOpen($locked)) {
                return 'race_lost';
            }

            // Lock owner + taker up front (ascending id) so the Active-Mode and
            // per-game concurrency checks below read a consistent, race-proof
            // view — and so two concurrent takes touching the same pair in
            // opposite roles can't deadlock.
            [$owner, $taker] = $this->lockParticipants($locked, $user);

            // Active Mode gate (M6 Phase 6.5): "I'm not available". Must gate
            // the actual match-start, not just marketplace visibility — a stale
            // tab or direct URL would otherwise bypass the intent.
            if (! (bool) $owner->is_active_mode) {
                return 'owner_inactive';
            }

            // M37 — one active match per game. Neither side may be pulled into a
            // second concurrent match for THIS game (the taker starting one, or
            // the owner's listing starting one for them). A different game (a
            // CS2 match) doesn't block — it settles through a separate provider
            // pipeline, so the two can't be confused.
            if ($taker->hasInFlightMatchForGame($locked->game)) {
                return 'already_in_match';
            }

            if ($owner->hasInFlightMatchForGame($locked->game)) {
                return 'owner_busy';
            }

            $this->escrowTakerStake($user, $locked);
            $this->markListingTaken($locked);

            $match = $this->createMatch($locked, $user);

            $this->postSystem->handle(
                $match,
                __('Match started. Play your game on chess.com or Lichess, then return here to confirm the outcome.'),
            );

            return $match;
        });

        if ($result instanceof GameMatch) {
            $result->loadMissing(['listing.user', 'taker']);
            $result->listing->user->notify(new ListingTakenNotification($result));
        }

        return $result;
    }

    private function assertNotSelfTake(Listing $listing, User $user): void
    {
        abort_if($listing->user_id === $user->id, 403, 'You cannot take your own listing.');
    }

    private function listingIsNoLongerOpen(Listing $listing): bool
    {
        return $listing->status !== ListingStatus::Open || $listing->expires_at->isPast();
    }

    /**
     * Lock the owner + taker rows up front, ordered by id. A stable lock order
     * means two concurrent takes that touch the same two users in opposite
     * roles (A takes B's listing while B takes A's) serialize on the shared row
     * instead of deadlocking. Both downstream gates — Active Mode and the M37
     * per-game concurrency guard — read these locked rows, so neither can be
     * raced by a parallel take or an `ActiveModeController` toggle.
     *
     * @return array{0: User, 1: User} [owner, taker]
     */
    private function lockParticipants(Listing $listing, User $taker): array
    {
        $rows = User::query()
            ->whereIn('id', [$listing->user_id, $taker->id])
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        return [$rows[$listing->user_id], $rows[$taker->id]];
    }

    /**
     * Throws `InsufficientBalanceException` for the rare race where the
     * balance dropped between TakeRequest's pre-check and now. The
     * controller catches that and maps to a `ValidationException`.
     */
    private function escrowTakerStake(User $user, Listing $listing): void
    {
        Wallet::hold(
            user: $user,
            amount: (string) $listing->stake_amount,
            listing: $listing,
            reference: "match-take:{$listing->id}",
            description: 'Stake escrowed on match take.',
        );
    }

    private function markListingTaken(Listing $listing): void
    {
        $listing->update(['status' => ListingStatus::Taken]);
    }

    private function createMatch(Listing $listing, User $taker): GameMatch
    {
        $match = GameMatch::create([
            'listing_id' => $listing->id,
            'taker_user_id' => $taker->id,
            'status' => MatchStatus::Pending,
        ]);

        $this->snapshotProviderAccounts($match, $listing, $listing->user, $taker);

        return $match;
    }

    /**
     * One `match_provider_snapshots` row per linked account each player
     * has verified. Mid-match unlinks can't strip these — smart-link
     * enrichment (M8 Phase 4) cross-checks against the snapshot, not the
     * live user record. The snapshot copies `provider_user_id` (stable
     * external ID for FACEIT/Steam/Riot) and `skill_rating` (M15) so the
     * provider's stable identifier + rating at match time survive any
     * subsequent updates to the user's link.
     *
     * M41 P3b — for chess links the snapshot records the rating for the
     * listing's single time control (from `linked_account_ratings`), not the
     * scalar `skill_rating` (which chess never populates). Audit/history only;
     * display reads the live rating.
     *
     * Batch insert via the model query builder so all rows land in a single
     * SQL statement. Timestamps are set explicitly because `insert()`
     * bypasses Eloquent's auto-timestamping. The outer `DB::transaction` in
     * `handle()` covers atomicity — a failed insert here rolls back the
     * match + escrow hold + listing flip.
     */
    private function snapshotProviderAccounts(GameMatch $match, Listing $listing, User $creator, User $taker): void
    {
        $rows = [];
        $now = now();

        foreach ([GameMatch::SIDE_CREATOR => $creator, GameMatch::SIDE_TAKER => $taker] as $side => $user) {
            $user->loadMissing('linkedAccounts.ratings');

            foreach ($user->linkedAccounts as $link) {
                $rows[] = [
                    'match_id' => $match->id,
                    'side' => $side,
                    'provider' => $link->provider->value,
                    'username' => $link->username,
                    'provider_user_id' => $link->provider_user_id,
                    'skill_rating_snapshot' => $this->snapshotRatingFor($link, $listing->time_control),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if (count($rows) > 0) {
            MatchProviderSnapshot::insert($rows);
        }
    }

    /**
     * The rating to snapshot for a link. Chess links carry per-time-control
     * ratings, so snapshot the one for the listing's time control; FACEIT (+
     * future scalar providers) use the cached `skill_rating`. Null when the
     * player has no rating for that time control (Unrated).
     */
    private function snapshotRatingFor(LinkedAccount $link, ?TimeControl $timeControl): ?int
    {
        $isChess = in_array($link->provider, [
            LinkedAccountProvider::ChessCom,
            LinkedAccountProvider::Lichess,
        ], true);

        if ($isChess && $timeControl !== null) {
            return $link->ratings
                ->firstWhere('time_control', $timeControl)
                ?->rating;
        }

        return $link->skill_rating;
    }
}
