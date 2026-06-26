<?php

namespace App\Actions\Listing;

use App\Actions\LinkedAccount\RefreshLinkedAccountRatingAction;
use App\Enums\LinkedAccountProvider;
use App\Enums\ListingStatus;
use App\Enums\MatchStatus;
use App\Models\GameMatch;
use App\Models\Listing;
use App\Models\LobbyParticipant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates a team-play listing (`team_size > 1`) and its paired lobby.
 *
 * Unlike `CreateListingAction` (1v1, escrows the creator's stake at creation),
 * team-play listings DON'T escrow at creation — the per-player stake commits
 * one Ready click at a time via `ToggleReadyAction`. The creator is auto-
 * soft-joined to their picked slot but is NOT auto-Ready'd (locked decision:
 * creator is just another participant, must click Ready themselves).
 *
 * The paired `GameMatch` row is created in `LobbyFilling` state so
 * `messages.match_id`-keyed chat works from day 1 of the lobby. The row
 * flips to `Pending` when `LobbyLockAction` fires.
 *
 * Sentinel returns:
 *   - `Listing` instance → success
 *   - `'not_linked'`     → creator has no verified provider account on the
 *                          listing's platform (same gate as CreateListingAction)
 *   - `'already_in_lobby'` → creator is already in another team-play lobby
 *                            (global single-lobby rule)
 */
class CreateTeamPlayListingAction
{
    public function __construct(
        private readonly RefreshLinkedAccountRatingAction $refreshRating,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Validated input — same shape as
     *                                      CreateListingAction's data plus
     *                                      `team_size` (int >1), `creator_side`
     *                                      ('a' | 'b'), `is_public` (bool).
     */
    public function handle(User $user, array $data): Listing|string
    {
        $platform = LinkedAccountProvider::from($data['platform']);

        if (! $user->isVerifiedOn($platform)) {
            return 'not_linked';
        }

        if ($user->activeLobbyParticipation() !== null) {
            return 'already_in_lobby';
        }

        $listing = DB::transaction(function () use ($user, $data, $platform) {
            $listing = $this->createListing($user, $data, $platform);
            $match = $this->createPairedMatch($listing);
            $this->autoSoftJoinCreator($listing, $user);

            // Avoid re-querying — caller often wants both the listing and the
            // paired match. The relation hydration here is cheap.
            $listing->setRelation('gameMatch', $match);

            return $listing;
        });

        $this->refreshCreatorRating($user, $platform);

        return $listing;
    }

    /**
     * M41 P1 — keep the creator's displayed rating current at post time.
     * No-ops for chess platforms and when the cached rating is still fresh
     * (the gate lives in RefreshLinkedAccountRatingAction). Fired after the
     * listing commits, so the displayed rating reflects the latest pull.
     */
    private function refreshCreatorRating(User $user, LinkedAccountProvider $platform): void
    {
        $account = $user->linkedAccounts()
            ->where('provider', $platform->value)
            ->first();

        if ($account !== null) {
            $this->refreshRating->handle($account);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createListing(User $user, array $data, LinkedAccountProvider $platform): Listing
    {
        return $user->listings()->create([
            'game' => $data['game'],
            'platform' => $platform,
            'stake_amount' => $data['stake_amount'],
            'skill_min' => $data['skill_min'] ?? null,
            'skill_max' => $data['skill_max'] ?? null,
            'time_control' => $data['time_control'] ?? [],
            'region' => $data['region'] ?? null,
            'language' => $data['language'] ?? null,
            'expires_at' => now()->addHours((int) $data['duration_hours']),
            'status' => ListingStatus::Open,
            'team_size' => (int) $data['team_size'],
            'creator_side' => $data['creator_side'],
            'lobby_state' => 'recruiting',
            'is_public' => (bool) ($data['is_public'] ?? true),
            'invite_token' => ! ($data['is_public'] ?? true) ? Str::random(32) : null,
        ]);
    }

    /**
     * Pre-Pending match row carrying the lobby chat from day 1. Taker is set
     * to the creator as a placeholder — `LobbyLockAction` reassigns taker
     * semantics when the match flips to Pending (for team-play, "taker" is
     * a 1v1-centric concept; the lobby roster is the actual participant set
     * via `match_provider_snapshots`).
     */
    private function createPairedMatch(Listing $listing): GameMatch
    {
        return GameMatch::create([
            'listing_id' => $listing->id,
            'taker_user_id' => $listing->user_id,
            'status' => MatchStatus::LobbyFilling,
        ]);
    }

    /**
     * Auto-soft-join the creator to their picked slot 0 on `creator_side`.
     * Not Ready'd — they must click Ready themselves like everyone else.
     */
    private function autoSoftJoinCreator(Listing $listing, User $user): void
    {
        LobbyParticipant::create([
            'listing_id' => $listing->id,
            'user_id' => $user->id,
            'side' => $listing->creator_side,
            'slot_index' => 0,
            'is_ready' => false,
            'stake_held_at' => null,
            'kicked_at' => null,
            'joined_at' => now(),
        ]);
    }
}
