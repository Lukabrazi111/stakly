<?php

use App\Enums\MatchStatus;
use App\Models\GameMatch;
use App\Models\User;
use App\Models\UsernameHistory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
});

function patchUsername(User $user, string $username, array $overrides = [])
{
    return test()
        ->actingAs($user)
        ->patch(route('profile.update'), array_merge([
            'name' => $user->name,
            'email' => $user->email,
            'username' => $username,
        ], $overrides));
}

// ─── Happy path ────────────────────────────────────────────────────────────

test('user can rename to a fresh handle', function () {
    $user = User::factory()->create(['username' => 'old-handle']);

    patchUsername($user, 'new-handle')
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->username)->toBe('new-handle');
    expect($user->username_changed_at)->not->toBeNull();
});

test('old handle is written to username_history with 30 day reservation', function () {
    $user = User::factory()->create(['username' => 'old-handle']);

    patchUsername($user, 'new-handle')->assertSessionHasNoErrors();

    $history = UsernameHistory::where('user_id', $user->id)->first();
    expect($history)->not->toBeNull();
    expect($history->username)->toBe('old-handle');
    expect((int) round(abs($history->released_at->diffInDays(CarbonImmutable::now()))))->toBe(30);
});

test('submitting the same username is a no-op', function () {
    $user = User::factory()->create(['username' => 'same-handle']);

    patchUsername($user, 'same-handle')->assertSessionHasNoErrors();

    $user->refresh();
    expect($user->username)->toBe('same-handle');
    expect($user->username_changed_at)->toBeNull();
    expect(UsernameHistory::count())->toBe(0);
});

test('lowercase normalization accepts mixed-case input', function () {
    $user = User::factory()->create(['username' => 'old-handle']);

    patchUsername($user, 'Alice-Pro')->assertSessionHasNoErrors();

    expect($user->refresh()->username)->toBe('alice-pro');
});

// ─── Format validation ─────────────────────────────────────────────────────

test('format rejects invalid handles', function (string $bad) {
    $user = User::factory()->create();

    patchUsername($user, $bad)->assertSessionHasErrors('username');
})->with([
    'leading hyphen' => '-alice',
    'trailing hyphen' => 'alice-',
    'consecutive hyphens' => 'alice--pro',
    'underscore' => 'alice_pro',
    'space' => 'alice pro',
    'dot' => 'alice.pro',
    'too short' => 'al',
    'too long' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', // 31 chars
]);

// ─── Reserved + collision ──────────────────────────────────────────────────

test('reserved words are rejected', function () {
    $user = User::factory()->create();

    patchUsername($user, 'admin')->assertSessionHasErrors('username');
});

test('uniqueness against other users rejects', function () {
    User::factory()->create(['username' => 'taken-handle']);
    $user = User::factory()->create();

    patchUsername($user, 'taken-handle')->assertSessionHasErrors('username');
});

// ─── Reservation window ────────────────────────────────────────────────────

test('reserved handle in window cannot be claimed by another user', function () {
    $alice = User::factory()->create(['username' => 'alice-pro']);

    patchUsername($alice, 'alice-new')->assertSessionHasNoErrors();

    $bob = User::factory()->create();
    patchUsername($bob, 'alice-pro')->assertSessionHasErrors('username');

    expect($bob->refresh()->username)->not->toBe('alice-pro');
});

test('reservation expires after 30 days', function () {
    $alice = User::factory()->create(['username' => 'alice-pro']);
    patchUsername($alice, 'alice-new')->assertSessionHasNoErrors();

    $this->travel(31)->days();

    $bob = User::factory()->create();
    patchUsername($bob, 'alice-pro')->assertSessionHasNoErrors();

    expect($bob->refresh()->username)->toBe('alice-pro');
});

test('the original owner can reclaim their released handle after the window', function () {
    $alice = User::factory()->create(['username' => 'alice-pro']);
    patchUsername($alice, 'alice-new')->assertSessionHasNoErrors();

    $this->travel(31)->days();

    patchUsername($alice->refresh(), 'alice-pro')->assertSessionHasNoErrors();

    expect($alice->refresh()->username)->toBe('alice-pro');
});

// ─── Cooldown ──────────────────────────────────────────────────────────────

test('cooldown blocks a second rename inside 30 days', function () {
    $user = User::factory()->create(['username' => 'first-handle']);

    patchUsername($user, 'second-handle')->assertSessionHasNoErrors();

    $this->travel(15)->days();

    patchUsername($user->refresh(), 'third-handle')->assertSessionHasErrors('username');

    expect($user->refresh()->username)->toBe('second-handle');
});

test('cooldown lifts after 30 days', function () {
    $user = User::factory()->create(['username' => 'first-handle']);

    patchUsername($user, 'second-handle')->assertSessionHasNoErrors();

    $this->travel(31)->days();

    patchUsername($user->refresh(), 'third-handle')->assertSessionHasNoErrors();

    expect($user->refresh()->username)->toBe('third-handle');
});

// ─── In-flight match / dispute / manual review blockers ───────────────────

test('in-flight match blocks rename', function (MatchStatus $status) {
    $user = User::factory()->create(['username' => 'old-handle']);

    GameMatch::factory()
        ->state(['taker_user_id' => $user->id, 'status' => $status])
        ->create();

    patchUsername($user, 'new-handle')->assertSessionHasErrors('username');

    expect($user->refresh()->username)->toBe('old-handle');
})->with([
    'pending' => MatchStatus::Pending,
    'disputed' => MatchStatus::Disputed,
    'manual review' => MatchStatus::ManualReview,
]);

test('terminal matches do not block rename', function (MatchStatus $status) {
    $user = User::factory()->create(['username' => 'old-handle']);

    GameMatch::factory()
        ->state(['taker_user_id' => $user->id, 'status' => $status])
        ->create();

    patchUsername($user, 'new-handle')->assertSessionHasNoErrors();

    expect($user->refresh()->username)->toBe('new-handle');
})->with([
    'settled' => MatchStatus::Settled,
    'cancelled' => MatchStatus::Cancelled,
]);

// ─── User model helpers ────────────────────────────────────────────────────

test('canChangeUsername is true on a fresh account', function () {
    $user = User::factory()->create();

    expect($user->canChangeUsername())->toBeTrue();
    expect($user->usernameChangeBlockers())->toBe([]);
    expect($user->usernameChangeAvailableAt())->toBeNull();
});

test('canChangeUsername is false during cooldown', function () {
    $user = User::factory()->create([
        'username_changed_at' => CarbonImmutable::now()->subDays(10),
    ]);

    expect($user->canChangeUsername())->toBeFalse();
    expect($user->usernameChangeBlockers())->toContain('cooldown');
    expect($user->usernameChangeAvailableAt())->not->toBeNull();
});

test('canChangeUsername is false during in-flight match', function () {
    $user = User::factory()->create();

    GameMatch::factory()
        ->state(['taker_user_id' => $user->id, 'status' => MatchStatus::Disputed])
        ->create();

    expect($user->canChangeUsername())->toBeFalse();
    expect($user->usernameChangeBlockers())->toContain('in_flight_match');
});
