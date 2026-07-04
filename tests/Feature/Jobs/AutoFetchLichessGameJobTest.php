<?php

use App\Actions\GameMatch\RecordAutoFetchAttemptAction;
use App\Actions\GameMatch\SettleFromCardAction;
use App\Actions\Message\PostSystemMessageAction;
use App\Enums\LinkedAccountProvider;
use App\Enums\MatchStatus;
use App\Enums\MessageType;
use App\Events\MessageSent;
use App\Jobs\AutoFetchLichessGameJob;
use App\Models\GameMatch;
use App\Models\Listing;
use App\Models\MatchProviderSnapshot;
use App\Models\Message;
use App\Models\User;
use App\Services\Provider\Exceptions\TransientProviderError;
use App\Services\Provider\LichessGameClient;
use App\Services\Provider\ProviderCircuitBreaker;
use App\Services\Wallet;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Behaviour for the auto-fetch path. The decision logic (single-completed-
 * candidate filter, snapshot cross-check, idempotency) is exercised here;
 * the underlying API parsing is covered in `LichessGameClientTest`. M16
 * — the job now ALSO invokes `SettleFromCardAction` after posting the
 * card; settle assertions live alongside the card assertions.
 */
/**
 * @param  list<array{side?: string, provider?: LinkedAccountProvider, username?: string}>|null  $snapshots
 *                                                                                                           Null = default both-sides Lichess. Empty array = no snapshots.
 */
function autoFetchMatch(?array $snapshots = null): GameMatch
{
    // Wallet escrow is needed for the settle path — without held stakes,
    // SettleMatchAction's payout / SettleDrawMatchAction's refund would
    // throw on negative balance. We do this via the global pendingMatch()
    // helper which seeds the platform user too.
    platformUser();

    $creator = User::factory()->active()->withLichess('alice-lichess')->create();
    $taker = User::factory()->withLichess('bob-lichess')->create();
    Wallet::deposit($creator, '500', reference: "test:deposit:c:{$creator->id}");
    Wallet::deposit($taker, '500', reference: "test:deposit:t:{$taker->id}");

    // Listing TC matches the fixture default (blitz) so happy-path tests pass
    // deterministically; TC-mismatch tests override this to another control.
    $listing = Listing::factory()->taken()->forLichess()->for($creator)
        ->state(['stake_amount' => '100', 'time_control' => 'blitz'])
        ->create();
    Wallet::hold(user: $creator, amount: '100', listing: $listing, reference: "listing-create:{$listing->id}");
    Wallet::hold(user: $taker, amount: '100', listing: $listing, reference: "match-take:{$listing->id}");

    $match = GameMatch::factory()->create([
        'listing_id' => $listing->id,
        'taker_user_id' => $taker->id,
        'status' => MatchStatus::Pending,
    ]);

    $snapshots ??= [
        ['side' => GameMatch::SIDE_CREATOR, 'provider' => LinkedAccountProvider::Lichess, 'username' => 'alice-lichess'],
        ['side' => GameMatch::SIDE_TAKER, 'provider' => LinkedAccountProvider::Lichess, 'username' => 'bob-lichess'],
    ];

    foreach ($snapshots as $row) {
        MatchProviderSnapshot::create([
            'match_id' => $match->id,
            ...$row,
        ]);
    }

    return $match->fresh(['listing.user', 'taker', 'providerSnapshots']);
}

function runAutoFetch(GameMatch $match): void
{
    (new AutoFetchLichessGameJob($match))
        ->handle(
            app(LichessGameClient::class),
            app(PostSystemMessageAction::class),
            app(SettleFromCardAction::class),
            app(RecordAutoFetchAttemptAction::class),
            app(ProviderCircuitBreaker::class),
        );
}

// ─── Happy path — decisive game posts card AND settles ─────────────────────

test('single decisive game posts a verified system game_card AND settles the match', function () {
    $match = autoFetchMatch();
    // Fixture has white = alice (creator), black = bob, winner = white.
    $body = json_encode(lichessGameFixture(['id' => 'abcdefgh']));

    Http::fake([
        'lichess.org/api/games/user/*' => Http::response($body, 200),
    ]);
    Event::fake([MessageSent::class]);

    runAutoFetch($match);

    $system = Message::query()
        ->where('match_id', $match->id)
        ->where('type', MessageType::System)
        ->orderBy('id')
        ->first();

    expect($system)->not->toBeNull()
        ->and($system->content)->toBe('Verified Lichess game record.')
        ->and($system->attachments_json)->toHaveCount(1);

    $card = $system->attachments_json[0];
    expect($card['type'])->toBe('game_card')
        ->and($card['provider'])->toBe('lichess')
        ->and($card['source'])->toBe('auto_fetch')
        ->and($card['game_id'])->toBe('abcdefgh')
        ->and($card['verified'])->toBeTrue()
        ->and($card['white_username'])->toBe('alice-lichess')
        ->and($card['black_username'])->toBe('bob-lichess')
        ->and($card['winner_username'])->toBe('alice-lichess');

    // M16 — card-then-settle. Match is now Settled, creator (white = winner) won.
    $match->refresh();
    expect($match->status)->toBe(MatchStatus::Settled)
        ->and($match->winner_user_id)->toBe($match->listing->user_id);

    Event::assertDispatched(MessageSent::class);
});

test('drawn game posts a card AND settles as draw (M16 — draws are valid completions)', function () {
    $match = autoFetchMatch();

    $drawn = lichessGameFixture(['id' => 'drawnone', 'status' => 'draw']);
    unset($drawn['winner']);

    Http::fake([
        'lichess.org/api/games/user/*' => Http::response(json_encode($drawn), 200),
    ]);

    runAutoFetch($match);

    $system = Message::query()
        ->where('match_id', $match->id)
        ->where('type', MessageType::System)
        ->where('content', 'Verified Lichess game record.')
        ->first();

    expect($system)->not->toBeNull()
        ->and($system->attachments_json[0]['game_id'])->toBe('drawnone')
        ->and($system->attachments_json[0]['winner_color'])->toBeNull()
        ->and($system->attachments_json[0]['winner_username'])->toBeNull();

    // Settled as draw — winner_user_id null, both stakes refunded.
    $match->refresh();
    expect($match->status)->toBe(MatchStatus::Settled)
        ->and($match->winner_user_id)->toBeNull();
});

test('aborted game posts a card AND settles as draw (M14 Slice 3b — cooperative-exit refund)', function () {
    $match = autoFetchMatch();

    $aborted = lichessGameFixture(['id' => 'abortedX', 'status' => 'aborted']);
    unset($aborted['winner']);

    Http::fake([
        'lichess.org/api/games/user/*' => Http::response(json_encode($aborted), 200),
    ]);

    runAutoFetch($match);

    $system = Message::query()
        ->where('match_id', $match->id)
        ->where('type', MessageType::System)
        ->where('content', 'Verified Lichess game record.')
        ->first();

    expect($system)->not->toBeNull()
        ->and($system->attachments_json[0]['game_id'])->toBe('abortedX')
        ->and($system->attachments_json[0]['status'])->toBe('aborted')
        ->and($system->attachments_json[0]['winner_color'])->toBeNull()
        ->and($system->attachments_json[0]['winner_username'])->toBeNull();

    // Settled as draw — both stakes refunded, no winner.
    $match->refresh();
    expect($match->status)->toBe(MatchStatus::Settled)
        ->and($match->winner_user_id)->toBeNull();
});

// ─── Single-candidate-or-skip heuristic ─────────────────────────────────────

test('no games found → no system message posted, match stays Pending', function () {
    $match = autoFetchMatch();

    Http::fake([
        'lichess.org/api/games/user/*' => Http::response('', 200),
    ]);

    runAutoFetch($match);

    expect(Message::query()->where('match_id', $match->id)->where('type', MessageType::System)->count())
        ->toBe(0);
    expect($match->fresh()->status)->toBe(MatchStatus::Pending);
});

test('multiple decisive games with time-control mismatch → ambiguous, no post (M14 Slice 3c)', function () {
    $match = autoFetchMatch();
    // Force listing time-control away from the fixture default (blitz)
    // so the picker's TC filter eliminates both candidates.
    $match->listing->update(['time_control' => 'rapid']);

    $g1 = json_encode(lichessGameFixture(['id' => 'game0001']));
    $g2 = json_encode(lichessGameFixture(['id' => 'game0002', 'winner' => 'black']));

    Http::fake([
        'lichess.org/api/games/user/*' => Http::response($g1."\n".$g2, 200),
    ]);

    runAutoFetch($match);

    expect(Message::query()->where('match_id', $match->id)->where('type', MessageType::System)->count())
        ->toBe(0);
    expect($match->fresh()->status)->toBe(MatchStatus::Pending);
});

test('multiple completions (decisive + drawn) with time-control mismatch → ambiguous, no post', function () {
    $match = autoFetchMatch();
    $match->listing->update(['time_control' => 'rapid']);

    $decisive = lichessGameFixture(['id' => 'winnergg']);
    $drawn = lichessGameFixture(['id' => 'drawnone', 'status' => 'draw']);
    unset($drawn['winner']);

    Http::fake([
        'lichess.org/api/games/user/*' => Http::response(
            json_encode($decisive)."\n".json_encode($drawn),
            200,
        ),
    ]);

    runAutoFetch($match);

    expect(Message::query()->where('match_id', $match->id)->where('type', MessageType::System)->count())
        ->toBe(0);
    expect($match->fresh()->status)->toBe(MatchStatus::Pending);
});

test('multiple decisive games with time-control match → picker picks game closest to match.created_at (M14 Slice 3c)', function () {
    $match = autoFetchMatch();
    $match->listing->update(['time_control' => 'blitz']);

    // match.created_at is approximately "now". Build two games:
    //   - first played soon after match creation
    //   - second played much later
    // Picker should pick the first (closer to match.created_at).
    $earlyTs = $match->created_at->copy()->addMinutes(2)->getTimestampMs();
    $lateTs = $match->created_at->copy()->addMinutes(30)->getTimestampMs();

    $early = lichessGameFixture([
        'id' => 'earlygame',
        'createdAt' => $earlyTs,
        'lastMoveAt' => $earlyTs,
    ]);
    $late = lichessGameFixture([
        'id' => 'lategame0',
        'createdAt' => $lateTs,
        'lastMoveAt' => $lateTs,
    ]);

    Http::fake([
        'lichess.org/api/games/user/*' => Http::response(
            json_encode($early)."\n".json_encode($late),
            200,
        ),
    ]);

    runAutoFetch($match);

    $system = Message::query()
        ->where('match_id', $match->id)
        ->where('type', MessageType::System)
        ->first();

    expect($system)->not->toBeNull()
        ->and($system->attachments_json[0]['game_id'])->toBe('earlygame');
    expect($match->fresh()->status)->toBe(MatchStatus::Settled);
});

test('picker tie-breaks on game id when delta is equal (M14 Slice 3c)', function () {
    $match = autoFetchMatch();
    $match->listing->update(['time_control' => 'blitz']);

    // Both games at the same lastMoveAt — picker falls back to id sort.
    // 'aaaagame' < 'zzzzgame' lexically → 'aaaagame' wins.
    $ts = $match->created_at->copy()->addMinutes(5)->getTimestampMs();

    $first = lichessGameFixture([
        'id' => 'aaaagame',
        'lastMoveAt' => $ts,
    ]);
    $second = lichessGameFixture([
        'id' => 'zzzzgame',
        'winner' => 'black',
        'lastMoveAt' => $ts,
    ]);

    Http::fake([
        'lichess.org/api/games/user/*' => Http::response(
            json_encode($second)."\n".json_encode($first), // late in ndjson, but earlier id
            200,
        ),
    ]);

    runAutoFetch($match);

    $system = Message::query()
        ->where('match_id', $match->id)
        ->where('type', MessageType::System)
        ->first();

    expect($system->attachments_json[0]['game_id'])->toBe('aaaagame');
});

test('one decisive + one aborted → posts the decisive one + settles (decisive wins primary pass)', function () {
    $match = autoFetchMatch();

    $decisive = lichessGameFixture(['id' => 'winnergg']);
    $aborted = lichessGameFixture(['id' => 'abortone', 'status' => 'aborted']);
    unset($aborted['winner']);

    Http::fake([
        'lichess.org/api/games/user/*' => Http::response(
            json_encode($decisive)."\n".json_encode($aborted),
            200,
        ),
    ]);

    runAutoFetch($match);

    $system = Message::query()
        ->where('match_id', $match->id)
        ->where('type', MessageType::System)
        ->where('content', 'Verified Lichess game record.')
        ->first();

    expect($system)->not->toBeNull()
        ->and($system->attachments_json[0]['game_id'])->toBe('winnergg');
    expect($match->fresh()->status)->toBe(MatchStatus::Settled);
});

// ─── Failure modes ──────────────────────────────────────────────────────────

test('provider 5xx → no post, no settle (re-throws for queue retry)', function () {
    $match = autoFetchMatch();

    Http::fake([
        'lichess.org/api/games/user/*' => Http::response('', 503),
    ]);

    expect(fn () => runAutoFetch($match))
        ->toThrow(TransientProviderError::class);

    expect(Message::query()->where('match_id', $match->id)->where('type', MessageType::System)->count())
        ->toBe(0);
    expect($match->fresh()->status)->toBe(MatchStatus::Pending);
});

test('missing snapshot username → no-op', function () {
    // Only taker snapshot present — creator's Lichess unlink/never-linked
    // means we have nothing to cross-check the white side against.
    $match = autoFetchMatch(snapshots: [
        ['side' => GameMatch::SIDE_TAKER, 'provider' => LinkedAccountProvider::Lichess, 'username' => 'bob-lichess'],
    ]);

    // Don't even fake — if the job tries to call out, the missing fake will
    // throw and the test will flag the bug.
    Http::preventStrayRequests();

    runAutoFetch($match);

    expect(Message::query()->where('match_id', $match->id)->where('type', MessageType::System)->count())
        ->toBe(0);
});

// ─── Idempotency ───────────────────────────────────────────────────────────

test('re-running the job does not double-post (idempotency via attachments_json scan)', function () {
    $match = autoFetchMatch();
    $body = json_encode(lichessGameFixture(['id' => 'abcdefgh']));

    Http::fake([
        'lichess.org/api/games/user/*' => Http::response($body, 200),
    ]);

    runAutoFetch($match);

    // After the first run the match is Settled. SettleFromCardAction on a
    // re-run no-ops on non-Pending status. The job's `alreadyPosted()`
    // also short-circuits the API call. Either guard alone is enough; we
    // assert the combined outcome: still exactly one auto_fetch card.
    runAutoFetch($match);

    expect(Message::query()
        ->where('match_id', $match->id)
        ->where('type', MessageType::System)
        ->whereJsonContains('attachments_json', [['source' => 'auto_fetch']])
        ->count())
        ->toBe(1);
});

test('paste-source game_card does NOT block a later auto-fetch post', function () {
    // A user pasting a Lichess URL first creates a card with source=paste
    // on their own Text message. Auto-fetch should still fire — the
    // idempotency check is scoped to system messages with
    // source=auto_fetch specifically.
    $match = autoFetchMatch();

    Message::factory()->create([
        'match_id' => $match->id,
        'user_id' => $match->taker_user_id,
        'content' => 'pasted',
        'attachments_json' => [[
            'type' => 'game_card',
            'source' => 'paste',
            'game_id' => 'pasteddd',
        ]],
    ]);

    Http::fake([
        'lichess.org/api/games/user/*' => Http::response(
            json_encode(lichessGameFixture(['id' => 'autoabcd'])),
            200,
        ),
    ]);

    runAutoFetch($match);

    $system = Message::query()
        ->where('match_id', $match->id)
        ->where('type', MessageType::System)
        ->where('content', 'Verified Lichess game record.')
        ->first();

    expect($system)->not->toBeNull()
        ->and($system->attachments_json[0]['source'])->toBe('auto_fetch')
        ->and($system->attachments_json[0]['game_id'])->toBe('autoabcd');
});

// ─── M35 P2 — self-throttle wiring ─────────────────────────────────────────

test('AutoFetchLichessGameJob declares the lichess-api RateLimited middleware', function () {
    $match = autoFetchMatch();
    $job = new AutoFetchLichessGameJob($match);

    $middleware = $job->middleware();

    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(RateLimited::class);
});

test('lichess-api rate limiter reflects services.lichess.requests_per_minute config', function () {
    config(['services.lichess.requests_per_minute' => 11]);

    $resolver = RateLimiter::limiter('lichess-api');
    expect($resolver)->not->toBeNull();

    $limit = $resolver(new stdClass);

    expect($limit)->toBeInstanceOf(Limit::class)
        ->and($limit->maxAttempts)->toBe(11);
});
