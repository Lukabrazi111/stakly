<?php

namespace App\Actions\LinkedAccount;

use App\Enums\LinkedAccountProvider;
use App\Models\LinkedAccount;
use App\Models\User;
use App\Services\Provider\Exceptions\ProfileNotFoundException;
use App\Services\Provider\FaceitProfile;
use App\Services\Provider\FaceitProfileClient;
use Illuminate\Database\UniqueConstraintViolationException;
use Laravel\Socialite\Two\User as SocialiteOAuth2User;

/**
 * Completes a FACEIT link from the OAuth callback (M15 Phase 2). Socialite
 * has already exchanged the authorization code for an access token and
 * fetched the userinfo payload (`guid` + `nickname`); this Action uses
 * that token to call FACEIT's Data API for the CS2 ELO + skill level
 * before upserting the LinkedAccount row.
 *
 * Returns one of: `'linked'`, `'profile-not-found'`, `'username-claimed'`.
 * Symmetric to `VerifyLinkedAccountAction` for the chess bio-code flow.
 */
class LinkFaceitAccountAction
{
    public function __construct(
        private readonly FaceitProfileClient $faceitClient,
    ) {}

    public function handle(User $user, SocialiteOAuth2User $oauthUser): string
    {
        try {
            $profile = $this->faceitClient->fetchPlayer(playerId: $oauthUser->id);
        } catch (ProfileNotFoundException) {
            return 'profile-not-found';
        }

        try {
            $this->upsertLink($user, $oauthUser, $profile);
        } catch (UniqueConstraintViolationException) {
            return 'username-claimed';
        }

        return 'linked';
    }

    /**
     * Identity (`provider_user_id`, `username`) comes from the OAuth
     * userinfo payload — FACEIT signed our access token to that identity,
     * so it's the canonical source. The Data API profile is only consulted
     * for the per-game ELO (`skill_rating`); when no API key is configured
     * (dev mode) the profile is null and `skill_rating` stays null.
     */
    private function upsertLink(User $user, SocialiteOAuth2User $oauthUser, ?FaceitProfile $profile): void
    {
        LinkedAccount::updateOrCreate(
            [
                'user_id' => $user->id,
                'provider' => LinkedAccountProvider::Faceit->value,
            ],
            [
                'username' => $oauthUser->nickname,
                'provider_user_id' => $oauthUser->id,
                'skill_rating' => $profile?->cs2Elo,
                // M41 P1 — stamp the sync time only when the Data API actually
                // answered (profile non-null). Dev without an API key leaves it
                // NULL so the lazy refresh path fetches once a key is set.
                'skill_rating_synced_at' => $profile !== null ? now() : null,
                'verified_at' => now(),
            ],
        );
    }
}
