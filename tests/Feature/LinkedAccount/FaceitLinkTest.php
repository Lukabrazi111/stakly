<?php

use App\Enums\LinkedAccountProvider;
use App\Models\LinkedAccount;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteOAuth2User;

/*
|--------------------------------------------------------------------------
| FaceitLinkController (M15 Phase 2)
|--------------------------------------------------------------------------
|
| HTTP-level tests for the FACEIT OAuth link flow.
|   GET /{locale}/auth/faceit/redirect  — initiates Socialite redirect,
|                                          stashes locale in session.
|   GET /auth/faceit/callback           — locale-agnostic callback;
|                                          dispatches LinkFaceitAccountAction.
|
| Socialite is mocked via Facade `shouldReceive('driver->user')`. The Data
| API call inside `FaceitProfileClient` is mocked via `Http::fake()`.
|
*/

beforeEach(function () {
    // Most callback tests assume the Data API is in play, so set a fake
    // API key. The "no API key" test overrides this back to null.
    config(['services.faceit.api_key' => 'test-faceit-api-key']);
});

function fakeSocialiteUser(
    string $playerId = 'faceit-guid-9b6e',
    string $nickname = 'TestUser',
    string $token = 'oauth-access-token',
): SocialiteOAuth2User {
    /** @var SocialiteOAuth2User $user */
    $user = (new SocialiteOAuth2User)->map([
        'id' => $playerId,
        'nickname' => $nickname,
        'email' => null,
        'avatar' => null,
    ]);
    $user->token = $token;

    return $user;
}

function fakeFaceitProfileResponse(string $playerId, ?int $cs2Elo = 1850, ?int $cs2SkillLevel = 8): void
{
    Http::fake([
        "open.faceit.com/data/v4/players/{$playerId}" => Http::response([
            'player_id' => $playerId,
            'nickname' => 'TestUser',
            'games' => [
                'cs2' => [
                    'faceit_elo' => $cs2Elo,
                    'skill_level' => $cs2SkillLevel,
                ],
            ],
        ], 200),
    ]);
}

// ─── redirect ────────────────────────────────────────────────────────────

test('redirect requires authentication', function () {
    $this->get('/en/auth/faceit/redirect')
        ->assertRedirect(route('login'));
});

test('redirect requires verified email', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->get('/en/auth/faceit/redirect')
        ->assertRedirect();
});

test('redirect stashes locale in session and bounces to Socialite', function () {
    $user = User::factory()->create();
    Socialite::shouldReceive('driver->redirect')
        ->andReturn(redirect('https://accounts.faceit.com/?response_type=code&client_id=test&code_challenge=abc&code_challenge_method=S256'));

    $this->actingAs($user)
        ->get('/en/auth/faceit/redirect')
        ->assertRedirect('https://accounts.faceit.com/?response_type=code&client_id=test&code_challenge=abc&code_challenge_method=S256')
        ->assertSessionHas('faceit_oauth_locale', 'en');
});

// ─── callback: error paths ───────────────────────────────────────────────

test('callback handles declined consent with an info toast', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/auth/faceit/callback?error=access_denied')
        ->assertRedirect(route('linked-accounts.edit', ['locale' => 'en']))
        ->assertInertiaFlash('toast', ['type' => 'info', 'message' => 'FACEIT link cancelled.']);

    expect(LinkedAccount::count())->toBe(0);
});

test('callback handles state CSRF mismatch with a destructive toast', function () {
    $user = User::factory()->create();
    Socialite::shouldReceive('driver->user')
        ->andThrow(new InvalidStateException);

    $this->actingAs($user)
        ->get('/auth/faceit/callback?code=abc123&state=invalid')
        ->assertRedirect(route('linked-accounts.edit', ['locale' => 'en']))
        ->assertInertiaFlash('toast', ['type' => 'destructive', 'message' => 'FACEIT link session expired. Please try again.']);

    expect(LinkedAccount::count())->toBe(0);
});

test('callback shows destructive toast when Data API is transiently unavailable', function () {
    $user = User::factory()->create();
    $socialiteUser = fakeSocialiteUser('faceit-guid-down', 'TestUser', 'oauth-token');

    Socialite::shouldReceive('driver->user')->andReturn($socialiteUser);
    Http::fake([
        'open.faceit.com/data/v4/players/faceit-guid-down' => Http::response([], 503),
    ]);

    $this->actingAs($user)
        ->get('/auth/faceit/callback?code=abc&state=valid')
        ->assertInertiaFlash('toast', ['type' => 'destructive', 'message' => 'FACEIT is temporarily unavailable. Please try again in a moment.']);

    expect(LinkedAccount::count())->toBe(0);
});

test('callback shows destructive toast when FACEIT profile is not found', function () {
    $user = User::factory()->create();
    $socialiteUser = fakeSocialiteUser('missing-guid', 'TestUser', 'oauth-token');

    Socialite::shouldReceive('driver->user')->andReturn($socialiteUser);
    Http::fake([
        'open.faceit.com/data/v4/players/missing-guid' => Http::response([], 404),
    ]);

    $this->actingAs($user)
        ->get('/auth/faceit/callback?code=abc&state=valid')
        ->assertInertiaFlash('toast', ['type' => 'destructive', 'message' => 'We could not load your FACEIT profile. Please try again.']);

    expect(LinkedAccount::count())->toBe(0);
});

// ─── callback: happy path ────────────────────────────────────────────────

test('callback creates LinkedAccount with M15 columns on happy path', function () {
    $user = User::factory()->create();
    $socialiteUser = fakeSocialiteUser('faceit-guid-9b6e', 'TestUser', 'oauth-token');

    Socialite::shouldReceive('driver->user')->andReturn($socialiteUser);
    fakeFaceitProfileResponse('faceit-guid-9b6e', 1850, 8);

    $this->actingAs($user)
        ->get('/auth/faceit/callback?code=abc123&state=valid')
        ->assertRedirect(route('linked-accounts.edit', ['locale' => 'en']))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'FACEIT account linked.']);

    $link = LinkedAccount::where('user_id', $user->id)
        ->where('provider', LinkedAccountProvider::Faceit->value)
        ->firstOrFail();

    expect($link->username)->toBe('TestUser')
        ->and($link->provider_user_id)->toBe('faceit-guid-9b6e')
        ->and($link->skill_rating)->toBe(1850)
        ->and($link->skill_rating_synced_at)->not->toBeNull()
        ->and($link->verified_at)->not->toBeNull();
});

test('callback links the account with null skill_rating when no FACEIT_API_KEY is configured', function () {
    // Dev environments without the server-side API key still get a working
    // link flow — provider_user_id + username come from OAuth userinfo. The
    // Data API call is skipped entirely (the client returns null) and
    // skill_rating stays null until the key is configured.
    config(['services.faceit.api_key' => null]);
    Http::preventStrayRequests();

    $user = User::factory()->create();
    $socialiteUser = fakeSocialiteUser('faceit-guid-no-key', 'TestUser', 'oauth-token');
    Socialite::shouldReceive('driver->user')->andReturn($socialiteUser);

    $this->actingAs($user)
        ->get('/auth/faceit/callback?code=abc&state=valid')
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'FACEIT account linked.']);

    $link = LinkedAccount::where('user_id', $user->id)
        ->where('provider', LinkedAccountProvider::Faceit->value)
        ->firstOrFail();

    expect($link->username)->toBe('TestUser')
        ->and($link->provider_user_id)->toBe('faceit-guid-no-key')
        ->and($link->skill_rating)->toBeNull()
        ->and($link->skill_rating_synced_at)->toBeNull();
});

test('callback populates skill_rating as null when user has no CS2 block', function () {
    // A FACEIT user who has never played CS2 has no `games.cs2` key in the
    // Data API response. We still link the account, just with null ELO —
    // the column is nullable and the user can pick CS2 up later.
    $user = User::factory()->create();
    $socialiteUser = fakeSocialiteUser('faceit-guid-no-cs2', 'TestUser', 'oauth-token');

    Socialite::shouldReceive('driver->user')->andReturn($socialiteUser);
    Http::fake([
        'open.faceit.com/data/v4/players/faceit-guid-no-cs2' => Http::response([
            'player_id' => 'faceit-guid-no-cs2',
            'nickname' => 'TestUser',
            'games' => [], // empty — no CS2
        ], 200),
    ]);

    $this->actingAs($user)
        ->get('/auth/faceit/callback?code=abc&state=valid')
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'FACEIT account linked.']);

    $link = LinkedAccount::where('user_id', $user->id)
        ->where('provider', LinkedAccountProvider::Faceit->value)
        ->firstOrFail();

    expect($link->skill_rating)->toBeNull();
});

// ─── callback: idempotency on re-link ────────────────────────────────────

test('callback idempotency: re-link updates the existing row, no duplicates', function () {
    // User already linked FACEIT (e.g. via earlier Phase 1 fixture / factory).
    // Running the callback again — with a different FACEIT account on the
    // same Stakly user — should UPDATE the row, not create a second one.
    $user = User::factory()->withFaceit('OldNick', 'old-guid', 1500)->create();

    $socialiteUser = fakeSocialiteUser('new-guid', 'NewNick', 'fresh-oauth-token');
    Socialite::shouldReceive('driver->user')->andReturn($socialiteUser);
    fakeFaceitProfileResponse('new-guid', 2000, 10);

    $this->actingAs($user)
        ->get('/auth/faceit/callback?code=abc&state=valid')
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'FACEIT account linked.']);

    $links = LinkedAccount::where('user_id', $user->id)
        ->where('provider', LinkedAccountProvider::Faceit->value)
        ->get();

    expect($links)->toHaveCount(1)
        ->and($links->first()->username)->toBe('NewNick')
        ->and($links->first()->provider_user_id)->toBe('new-guid')
        ->and($links->first()->skill_rating)->toBe(2000);
});

// ─── callback: claim conflict (unique constraint) ───────────────────────

test('callback rejects when FACEIT account is already linked to another user', function () {
    // User A has FACEIT account "shared-guid" linked.
    User::factory()->withFaceit('SharedNick', 'shared-guid', 1700)->create();

    // User B tries to link the same FACEIT account. UNIQUE (provider,
    // provider_user_id) fires → 'username-claimed' sentinel → destructive
    // toast; no LinkedAccount row is created for user B.
    $userB = User::factory()->create();

    $socialiteUser = fakeSocialiteUser('shared-guid', 'SharedNick', 'oauth-token');
    Socialite::shouldReceive('driver->user')->andReturn($socialiteUser);
    fakeFaceitProfileResponse('shared-guid', 1700, 7);

    $this->actingAs($userB)
        ->get('/auth/faceit/callback?code=abc&state=valid')
        ->assertInertiaFlash('toast', ['type' => 'destructive', 'message' => 'That FACEIT account is already linked to another Stakly user.']);

    expect(LinkedAccount::where('user_id', $userB->id)
        ->where('provider', LinkedAccountProvider::Faceit->value)
        ->count())->toBe(0);
});
