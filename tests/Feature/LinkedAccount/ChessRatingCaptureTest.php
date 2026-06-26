<?php

use App\Actions\LinkedAccount\VerifyLinkedAccountAction;
use App\Enums\LinkedAccountProvider;
use App\Jobs\RefreshChessRatingJob;
use App\Models\PendingVerification;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
 * M41 P3b — a successful chess link captures per-time-control ratings by
 * dispatching RefreshChessRatingJob (the brand-new account is stale, so it
 * fires immediately). Routing capture through the job keeps the fetch+upsert in
 * one place and means a rating-provider hiccup never fails the link itself.
 */

beforeEach(fn () => Queue::fake());

function pendingChess(LinkedAccountProvider $provider, string $code, string $username = 'alice'): User
{
    $user = User::factory()->create();
    PendingVerification::create([
        'user_id' => $user->id,
        'provider' => $provider->value,
        'username' => $username,
        'code' => $code,
        'expires_at' => now()->addMinutes(15),
    ]);

    return $user;
}

it('dispatches the chess rating refresh after a successful chess.com verify', function () {
    $user = pendingChess(LinkedAccountProvider::ChessCom, 'stakly-ABCDEFGHJK');
    Http::fake([
        'api.chess.com/pub/player/alice' => Http::response([
            'username' => 'alice',
            'location' => 'stakly-ABCDEFGHJK',
        ], 200),
    ]);

    expect(app(VerifyLinkedAccountAction::class)->handle($user))->toBe('verified');

    Queue::assertPushed(
        RefreshChessRatingJob::class,
        fn (RefreshChessRatingJob $job) => $job->linkedAccount->user_id === $user->id
            && $job->linkedAccount->provider === LinkedAccountProvider::ChessCom,
    );
});

it('dispatches the chess rating refresh after a successful Lichess verify', function () {
    $user = pendingChess(LinkedAccountProvider::Lichess, 'stakly-LMNOPQRSTUV');
    Http::fake([
        'lichess.org/api/user/alice' => Http::response([
            'username' => 'alice',
            'profile' => ['bio' => 'gg — stakly-LMNOPQRSTUV'],
        ], 200),
    ]);

    expect(app(VerifyLinkedAccountAction::class)->handle($user))->toBe('verified');

    Queue::assertPushed(
        RefreshChessRatingJob::class,
        fn (RefreshChessRatingJob $job) => $job->linkedAccount->provider === LinkedAccountProvider::Lichess,
    );
});

it('does not dispatch a rating refresh when verification fails', function () {
    $user = pendingChess(LinkedAccountProvider::ChessCom, 'stakly-NOTINBIO');
    Http::fake([
        'api.chess.com/pub/player/alice' => Http::response([
            'username' => 'alice',
            'location' => 'no code here',
        ], 200),
    ]);

    expect(app(VerifyLinkedAccountAction::class)->handle($user))->toBe('code-not-found');

    Queue::assertNotPushed(RefreshChessRatingJob::class);
});
