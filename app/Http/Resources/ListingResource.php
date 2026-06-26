<?php

namespace App\Http\Resources;

use App\Enums\Game;
use App\Enums\LinkedAccountProvider;
use App\Models\Listing;
use App\Support\FaceitLevel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public shape of a listing. Whitelist only — never leak PII from the
 * creator's `User` record (email, two-factor secret, etc).
 *
 * @mixin Listing
 */
class ListingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'game' => $this->game->value,
            // Platform the match must be played on (chess_com | lichess).
            // FE Take button reads this to disable + label "Link {platform}
            // to take" when the viewer hasn't verified the right provider.
            'platform' => $this->platform->value,
            'stake_amount' => (float) $this->stake_amount,
            // Float at the JSON boundary; FE computes pot / fee / payout
            // from this — config is the single source of truth.
            'fee_rate' => (float) config('stakly.platform_fee_rate'),
            'skill_min' => $this->skill_min,
            'skill_max' => $this->skill_max,
            'time_control' => $this->time_control?->value,
            'region' => $this->region,
            'language' => $this->language,
            'expires_at' => $this->expires_at->toIso8601String(),
            'status' => $this->status->value,
            'created_at' => $this->created_at?->toIso8601String(),
            'team_size' => $this->team_size,
            'lobby_state' => $this->lobby_state,
            // Deadline for the lobby's ready-check window. Drives the grid
            // card's amber "Ready check · MM:SS" top banner. Null whenever
            // `lobby_state !== 'ready_checking'`.
            'lobby_ready_check_deadline' => $this->lobby_ready_check_deadline?->toIso8601String(),
            // `withCount(['lobbyParticipants as live_participant_count' =>
            //   fn ($q) => $q->live()])` populates this on consuming queries.
            // Falls back to 0 when not loaded so tests / partial paths don't blow up.
            'live_participant_count' => (int) ($this->live_participant_count ?? 0),
            // Up to 3 live participants, ordered by `joined_at`. Powers the
            // grid-card roster avatar preview (Slice A.2). Empty for chess
            // and for any query that didn't eager-load `lobbyParticipants`.
            'participant_previews' => $this->relationLoaded('lobbyParticipants')
                ? $this->lobbyParticipants
                    ->take(3)
                    ->map(fn ($p) => [
                        'username' => $p->user->username,
                        'name' => $p->user->name,
                        'avatar_thumb_url' => $p->user->avatar_thumb_url,
                    ])
                    ->values()
                    ->all()
                : [],
            'creator' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'username' => $this->user->username,
                // 128×128 thumb; null until upload.
                'avatar_thumb_url' => $this->user->avatar_thumb_url,
                // Surfaced so the listing detail page (which doesn't go
                // through `scopeOnPublicMarketplace`) can disable the Take
                // button + show a banner when the owner is inactive.
                // Server still enforces this independently in
                // `GameMatchController::take`.
                'is_active_mode' => (bool) $this->user->is_active_mode,
                // Batch-loaded by `App\Services\SellerTrust::attachTo()`.
                // Defensive defaults for factory / partial-test paths.
                'completion_rate_30d' => $this->seller_trust['rate_30d'] ?? null,
                'settled_lifetime' => (int) ($this->seller_trust['settled_lifetime'] ?? 0),
                // Requires `user.linkedAccounts` eager-loaded; empty array
                // when not loaded (defensive).
                'verified_providers' => $this->user->relationLoaded('linkedAccounts')
                    ? $this->user->linkedAccounts
                        ->pluck('provider')
                        ->map(fn ($provider) => $provider->value)
                        ->values()
                        ->all()
                    : [],
                // `linked_accounts` carries the username (for the detail-page
                // click-out chip strip); `verified_providers` above is the
                // bare provider list used for the badge.
                'bio' => $this->user->bio,
                'member_since' => $this->user->created_at?->toIso8601String(),
                'linked_accounts' => $this->user->relationLoaded('linkedAccounts')
                    ? $this->user->linkedAccounts
                        ->map(fn ($account) => [
                            'provider' => $account->provider->value,
                            'username' => $account->username,
                        ])
                        ->values()
                        ->all()
                    : [],
                // M41 P2 — verified FACEIT rating for CS2 listings. Level is
                // derived from ELO; "Unrated" when the creator has no FACEIT
                // link or no CS2 ELO yet.
                'faceit_rating' => $this->game === Game::Cs2
                    ? $this->getFaceitRating()
                    : null,
                // M41 P4 — verified chess rating for the listing's platform +
                // time control. Null for non-chess; "Unrated" when the creator
                // has no rating for that time control (or it's provisional).
                'chess_rating' => $this->game === Game::Chess
                    ? $this->getChessRating()
                    : null,
            ],
        ];
    }

    /**
     * The creator's verified FACEIT rating for a CS2 listing — ELO + a level
     * derived from it + an unrated flag. Reads the eager-loaded `linkedAccounts`
     * relation only (no query); returns the unrated shape when the FACEIT link
     * or its ELO is missing.
     *
     * @return array{elo: int|null, level: int|null, is_unrated: bool}
     */
    private function getFaceitRating(): array
    {
        $faceit = $this->user->relationLoaded('linkedAccounts')
            ? $this->user->linkedAccounts->firstWhere('provider', LinkedAccountProvider::Faceit)
            : null;

        $elo = $faceit?->skill_rating;

        return [
            'elo' => $elo,
            'level' => FaceitLevel::fromElo($elo),
            'is_unrated' => $elo === null,
        ];
    }

    /**
     * The creator's verified chess rating for THIS listing's platform + time
     * control (M41 P4). Reads the eager-loaded `linkedAccounts.ratings` only
     * (no query). Provisional ratings + a missing row both surface as
     * "Unrated" — the rating is null in that case so the FE can't show a number.
     *
     * @return array{rating: int|null, is_unrated: bool}
     */
    private function getChessRating(): array
    {
        $account = $this->user->relationLoaded('linkedAccounts')
            ? $this->user->linkedAccounts->firstWhere('provider', $this->platform)
            : null;

        $row = ($account?->relationLoaded('ratings') && $this->time_control !== null)
            ? $account->ratings->firstWhere('time_control', $this->time_control)
            : null;

        $rated = $row !== null && ! $row->is_provisional;

        return [
            'rating' => $rated ? $row->rating : null,
            'is_unrated' => ! $rated,
        ];
    }
}
