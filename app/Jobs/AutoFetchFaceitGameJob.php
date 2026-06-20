<?php

namespace App\Jobs;

use App\Actions\GameMatch\RecordAutoFetchAttemptAction;
use App\Actions\GameMatch\SettleFromCardAction;
use App\Actions\Message\PostSystemMessageAction;
use App\Enums\AutoFetchOutcome;
use App\Enums\LinkedAccountProvider;
use App\Enums\MessageType;
use App\Models\GameMatch;
use App\Models\Message;
use App\Services\Provider\Exceptions\PermanentProviderError;
use App\Services\Provider\Exceptions\ProviderError;
use App\Services\Provider\Exceptions\RateLimitedError;
use App\Services\Provider\FaceitGameClient;
use App\Services\Provider\FaceitMatchResult;
use App\Services\Provider\FaceitRosterPlayer;
use App\Services\Provider\ProviderCircuitBreaker;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;

/**
 * Posts an auto-fetched FACEIT match card for a Pending CS2 match, then settles
 * via `SettleFromCardAction`. Sibling to `AutoFetchChessComGameJob` /
 * `AutoFetchLichessGameJob`; same retry + circuit-breaker shape.
 *
 * Match-finding strategy (M15 P4 Slice 2):
 *   - Query creator's FACEIT `/players/{guid}/history` first.
 *   - For each candidate match_id, call `fetchMatch()` to get rosters.
 *   - Verify creator + taker GUIDs are on OPPOSING factions before posting.
 *   - Fall back to taker's history if creator's yields nothing.
 *
 * Anti-cheat gate: only settles matches where every player on both rosters
 * has `anticheat_required === true`. AC-incomplete matches are a terminal
 * `AcIncomplete` outcome (no card, no retry) — admin sees the audit row
 * and the match falls to ManualReview via the existing timeout path.
 *
 * Retry policy (mirrors chess):
 *   - no_match: explicit `release()` per `RETRY_DELAYS` ([5, 15, 45]s).
 *   - HTTP transient error: re-throw + Laravel `backoff()` ([5, 15, 30]s).
 *   - `PermanentProviderError` → audit row + `$this->fail($e)`, no retry.
 *   - `retryUntil()` caps the chain at the match-confirmation timeout.
 *   - `AcIncomplete` → terminal, no retry (the answer doesn't change).
 */
class AutoFetchFaceitGameJob implements ShouldBeUnique, ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * M35 P3 — widened from 7 to 15 to absorb `RateLimited` middleware
     * releases. Each throttle release consumes an attempt without running
     * the handler; `retryUntil()` is the real safety net.
     */
    public int $tries = 15;

    public int $timeout = 30;

    public int $uniqueFor = 360;

    /**
     * Per-attempt delays for the no_match retry chain. HTTP transient errors
     * use `backoff()` instead.
     *
     * @var list<int>
     */
    private const RETRY_DELAYS = [5, 15, 45];

    /**
     * How many of a player's most-recent matches we'll consider. Typical
     * match-confirmation polling needs the top 1–3; ten is comfortable
     * head-room before falling back to the other player's history.
     */
    private const CANDIDATE_LIMIT = 10;

    /**
     * Per-team slot ceiling for the candidate-seed search (5v5). With the
     * interleaved seeding order (slot 0 A → slot 0 B → slot 1 A → slot 1 B),
     * depth = 2 caps us at 4 history queries per settlement attempt. Going
     * deeper has diminishing returns — if the match isn't in either of two
     * randomly-chosen players' histories on each team, it's almost
     * certainly not in the other three either, and we burn API budget for
     * everyone else still waiting to settle.
     *
     * 1v1 paths populate single-element team arrays; the inner `isset` on
     * `$team*Guids[$slot]` skips empty slots.
     */
    private const MAX_SEED_DEPTH = 2;

    public function __construct(
        public GameMatch $match,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->match->id;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 15, 30];
    }

    public function retryUntil(): DateTimeInterface
    {
        return $this->match->created_at
            ->copy()
            ->addHours((int) config('stakly.match_confirmation_timeout_hours'));
    }

    /**
     * Self-throttle (M35 P3). `faceit-api` limiter is defined in
     * `AppServiceProvider::registerProviderRateLimiters()` and reads
     * `config('services.faceit.requests_per_minute')`. When the cap is
     * hit, this middleware releases the job back to the queue (consuming
     * one of the `$tries` budget) and retries after the limit window.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RateLimited('faceit-api')];
    }

    public function handle(
        FaceitGameClient $client,
        PostSystemMessageAction $postSystem,
        SettleFromCardAction $settleFromCard,
        RecordAutoFetchAttemptAction $recordAttempt,
        ProviderCircuitBreaker $breaker,
    ): void {
        if ($this->alreadyPosted()) {
            $this->record($recordAttempt, AutoFetchOutcome::Skipped, [
                'outcome_reason' => 'already_posted',
            ]);

            return;
        }

        $this->match->loadMissing(['providerSnapshots', 'listing.lobbyParticipants']);

        $isTeamPlay = $this->match->listing->team_size > 1;

        if ($isTeamPlay) {
            $team1Guids = $this->match->snapshotProviderUserIds('a', LinkedAccountProvider::Faceit);
            $team2Guids = $this->match->snapshotProviderUserIds('b', LinkedAccountProvider::Faceit);
            $team1Label = 'a';
            $team2Label = 'b';
            $expectedPerTeam = $this->match->listing->team_size;
        } else {
            $creatorGuid = $this->match->snapshotProviderUserId(
                GameMatch::SIDE_CREATOR,
                LinkedAccountProvider::Faceit,
            );
            $takerGuid = $this->match->snapshotProviderUserId(
                GameMatch::SIDE_TAKER,
                LinkedAccountProvider::Faceit,
            );
            $team1Guids = $creatorGuid === null ? [] : [$creatorGuid];
            $team2Guids = $takerGuid === null ? [] : [$takerGuid];
            $team1Label = GameMatch::SIDE_CREATOR;
            $team2Label = GameMatch::SIDE_TAKER;
            $expectedPerTeam = 1;
        }

        // Strict snapshot completeness: every player we expected to play must
        // have a FACEIT GUID on file. Missing players poison the strict
        // roster check downstream — bail early with an audit row so admin
        // sees why we never settled.
        if (count($team1Guids) < $expectedPerTeam || count($team2Guids) < $expectedPerTeam) {
            $this->record($recordAttempt, AutoFetchOutcome::Skipped, [
                'outcome_reason' => 'snapshot_missing',
            ]);

            return;
        }

        $start = microtime(true);

        try {
            $faceitMatch = $this->findOpposingTeamMatch($client, $team1Guids, $team2Guids);
        } catch (PermanentProviderError $e) {
            $this->record($recordAttempt, AutoFetchOutcome::Error, [
                'error_message' => $e->getMessage(),
                'latency_ms' => $this->elapsedMs($start),
                'outcome_reason' => 'permanent',
            ]);
            $breaker->recordFailure(LinkedAccountProvider::Faceit);
            $this->fail($e);

            return;
        } catch (RateLimitedError $e) {
            $this->record($recordAttempt, AutoFetchOutcome::Error, [
                'error_message' => $e->getMessage(),
                'latency_ms' => $this->elapsedMs($start),
                'outcome_reason' => $this->errorRetriesExhausted() ? 'retry_exhausted' : null,
            ]);
            $breaker->recordFailure(LinkedAccountProvider::Faceit);

            $retryAt = $e->retryAt();
            // `now()->getTimestamp()` (not PHP's `time()`) so Carbon's
            // `setTestNow` mocking carries through to tests.
            $nowTs = now()->getTimestamp();
            if ($retryAt !== null
                && $retryAt->getTimestamp() > $nowTs
                && ! $this->errorRetriesExhausted()
            ) {
                $this->release(max(1, $retryAt->getTimestamp() - $nowTs));

                return;
            }

            throw $e;
        } catch (ProviderError $e) {
            $this->record($recordAttempt, AutoFetchOutcome::Error, [
                'error_message' => $e->getMessage(),
                'latency_ms' => $this->elapsedMs($start),
                'outcome_reason' => $this->errorRetriesExhausted() ? 'retry_exhausted' : null,
            ]);
            $breaker->recordFailure(LinkedAccountProvider::Faceit);
            throw $e;
        }

        $breaker->recordSuccess(LinkedAccountProvider::Faceit);
        $latencyMs = $this->elapsedMs($start);

        if ($faceitMatch === null) {
            $reason = $this->noMatchRetriesExhausted() ? 'retry_exhausted' : null;
            $this->record($recordAttempt, AutoFetchOutcome::NoMatch, [
                'candidates_count' => 0,
                'latency_ms' => $latencyMs,
                'outcome_reason' => $reason,
            ]);

            $this->retryNoMatchIfBudgetRemains();

            return;
        }

        if (! $faceitMatch->isAntiCheatComplete()) {
            // Terminal outcome — admin sees the audit row, match falls to
            // ManualReview via the existing confirmation-timeout path.
            $this->record($recordAttempt, AutoFetchOutcome::AcIncomplete, [
                'candidates_count' => 1,
                'latency_ms' => $latencyMs,
                'outcome_reason' => 'ac_incomplete',
            ]);

            return;
        }

        if ($faceitMatch->winnerFaction === null) {
            // FINISHED + AC-complete + no winner shouldn't happen in CS2 (ties
            // play to overtime), but defensive — surface as ambiguous rather
            // than posting a malformed card.
            $this->record($recordAttempt, AutoFetchOutcome::Ambiguous, [
                'candidates_count' => 1,
                'latency_ms' => $latencyMs,
                'outcome_reason' => 'no_winner',
            ]);

            return;
        }

        $winnerTeamIndex = $this->resolveWinningTeamIndex($faceitMatch, $team1Guids, $team2Guids);

        if ($winnerTeamIndex === null) {
            // `findOpposingTeamMatch` already verified rosters are strictly
            // opposed, so this branch shouldn't fire. Defensive.
            $this->record($recordAttempt, AutoFetchOutcome::Ambiguous, [
                'candidates_count' => 1,
                'latency_ms' => $latencyMs,
                'outcome_reason' => 'winner_unresolvable',
            ]);

            return;
        }

        $winningSide = $winnerTeamIndex === 1 ? $team1Label : $team2Label;
        $winnerUserIds = $this->resolveWinnerUserIds($winningSide, $isTeamPlay);

        if (count($winnerUserIds) === 0) {
            // No live participants on the winning side — the lobby roster
            // doesn't match the snapshot side we just resolved. Should be
            // impossible after a successful LobbyLockAction; surface as
            // ambiguous rather than building a card we'd reject downstream.
            $this->record($recordAttempt, AutoFetchOutcome::Ambiguous, [
                'candidates_count' => 1,
                'latency_ms' => $latencyMs,
                'outcome_reason' => 'winner_roster_empty',
            ]);

            return;
        }

        // First winner's snapshot drives the card's `winner_username`
        // (legacy 1v1 field). For team-play this is the slot-0 player's
        // handle — purely informational; settlement reads `winner_user_ids`.
        $winnerUsername = $this->match->snapshotUsername($winningSide, LinkedAccountProvider::Faceit);

        $card = $this->postCard(
            $postSystem,
            $faceitMatch,
            $winnerUserIds,
            $winnerUsername,
            $winningSide,
        );

        $this->record($recordAttempt, AutoFetchOutcome::Matched, [
            'winner_username' => $winnerUsername,
            'candidates_count' => 1,
            'latency_ms' => $latencyMs,
        ]);

        $settleFromCard->handle($this->match, $card);
    }

    /**
     * Live participant user IDs on the {side} of the locked listing. For
     * team-play reads `lobby_participants`; for 1v1 reads creator / taker
     * straight off the match.
     *
     * @return list<int>
     */
    private function resolveWinnerUserIds(string $side, bool $isTeamPlay): array
    {
        if (! $isTeamPlay) {
            return [
                $side === GameMatch::SIDE_CREATOR
                    ? $this->match->listing->user_id
                    : $this->match->taker_user_id,
            ];
        }

        return $this->match->listing->lobbyParticipants
            ->whereNull('kicked_at')
            ->where('side', $side)
            ->sortBy('slot_index')
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Look for our Stakly match in FACEIT's history. Seed strategy:
     *   - 1v1: try creator's history, then taker's.
     *   - 5v5: try team 1 slot 0, then team 2 slot 0, then team 1 slot 1,
     *     then team 2 slot 1 — capped at the first two slots per team so the
     *     worst-case API budget stays bounded (4 seed queries × up to
     *     CANDIDATE_LIMIT candidate fetches).
     *
     * For every candidate FACEIT match the strict `isOpposingTeamRosters`
     * check rejects partial overlaps — if even one Stakly player is missing
     * from the candidate's factions, it's not our match.
     *
     * @param  list<string>  $team1Guids
     * @param  list<string>  $team2Guids
     */
    private function findOpposingTeamMatch(
        FaceitGameClient $client,
        array $team1Guids,
        array $team2Guids,
    ): ?FaceitMatchResult {
        // Interleave so we widen across both teams before going deeper on
        // either: slot 0 A → slot 0 B → slot 1 A → slot 1 B. Capping the
        // depth at MAX_SEED_DEPTH per team keeps team-play within budget
        // (slot 2+ players' histories are very unlikely to be unique
        // signal — if the first two seeds don't find the match, the
        // candidate isn't there).
        $seedGuids = [];

        for ($slot = 0; $slot < self::MAX_SEED_DEPTH; $slot++) {
            if (isset($team1Guids[$slot])) {
                $seedGuids[] = $team1Guids[$slot];
            }
            if (isset($team2Guids[$slot])) {
                $seedGuids[] = $team2Guids[$slot];
            }
        }

        foreach ($seedGuids as $seedGuid) {
            $matchIds = $client->searchPlayerMatches(
                playerId: $seedGuid,
                since: $this->match->created_at,
                game: 'cs2',
                limit: self::CANDIDATE_LIMIT,
            );

            foreach ($matchIds as $matchId) {
                $candidate = $client->fetchMatch($matchId);

                if ($candidate === null) {
                    continue;
                }

                if ($this->isOpposingTeamRosters($candidate, $team1Guids, $team2Guids)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * @param  list<FaceitRosterPlayer>  $roster
     */
    private function guidInRoster(array $roster, string $guid): bool
    {
        foreach ($roster as $player) {
            if ($player->playerId === $guid) {
                return true;
            }
        }

        return false;
    }

    /**
     * Team-play generalisation of `isOpposingRosterPair`. Strict: every GUID
     * in $team1Guids must sit on ONE FACEIT faction, AND every GUID in
     * $team2Guids on the OTHER faction. Any partial overlap or missing
     * player rejects the candidate — at money level we don't settle a match
     * unless every player we expected to play actually played.
     *
     * Works for both 1v1 (single-element arrays) and 5v5 (5-element arrays).
     *
     * @param  list<string>  $team1Guids
     * @param  list<string>  $team2Guids
     */
    private function isOpposingTeamRosters(
        FaceitMatchResult $match,
        array $team1Guids,
        array $team2Guids,
    ): bool {
        if (count($team1Guids) === 0 || count($team2Guids) === 0) {
            return false;
        }

        $team1On1 = $this->allGuidsInRoster($match->faction1Roster, $team1Guids);
        $team2On2 = $this->allGuidsInRoster($match->faction2Roster, $team2Guids);

        if ($team1On1 && $team2On2) {
            return true;
        }

        $team1On2 = $this->allGuidsInRoster($match->faction2Roster, $team1Guids);
        $team2On1 = $this->allGuidsInRoster($match->faction1Roster, $team2Guids);

        return $team1On2 && $team2On1;
    }

    /**
     * Resolves which Stakly team won. Returns 1 if every $team1Guid is on
     * the winning roster, 2 if every $team2Guid is, null on ambiguity
     * (no team wholly present on the winner roster — shouldn't happen if
     * `isOpposingTeamRosters` already accepted the candidate, but stays
     * defensive in case the FACEIT match's winner_faction field is stale).
     *
     * Caller maps 1 / 2 to the actual side label — 'creator' / 'taker' for
     * 1v1 chess, 'a' / 'b' for 5v5 team-play.
     *
     * @param  list<string>  $team1Guids
     * @param  list<string>  $team2Guids
     */
    private function resolveWinningTeamIndex(
        FaceitMatchResult $match,
        array $team1Guids,
        array $team2Guids,
    ): ?int {
        $winnerRoster = $match->winnerRoster();

        if (count($team1Guids) > 0 && $this->allGuidsInRoster($winnerRoster, $team1Guids)) {
            return 1;
        }

        if (count($team2Guids) > 0 && $this->allGuidsInRoster($winnerRoster, $team2Guids)) {
            return 2;
        }

        return null;
    }

    /**
     * @param  list<FaceitRosterPlayer>  $roster
     * @param  list<string>  $guids
     */
    private function allGuidsInRoster(array $roster, array $guids): bool
    {
        foreach ($guids as $guid) {
            if (! $this->guidInRoster($roster, $guid)) {
                return false;
            }
        }

        return true;
    }

    private function elapsedMs(float $start): int
    {
        return (int) round((microtime(true) - $start) * 1000);
    }

    private function noMatchRetriesExhausted(): bool
    {
        return $this->attempts() >= count(self::RETRY_DELAYS) + 1;
    }

    private function errorRetriesExhausted(): bool
    {
        return $this->attempts() >= $this->tries;
    }

    private function retryNoMatchIfBudgetRemains(): void
    {
        if ($this->noMatchRetriesExhausted()) {
            return;
        }

        $this->release(self::RETRY_DELAYS[$this->attempts() - 1]);
    }

    /**
     * @param  list<int>  $winnerUserIds
     * @return array<string, mixed> card payload — passed to SettleFromCardAction so
     *                              the settle decision uses the same data the players see.
     */
    private function postCard(
        PostSystemMessageAction $postSystem,
        FaceitMatchResult $match,
        array $winnerUserIds,
        ?string $winnerUsername,
        string $winningSide,
    ): array {
        $card = $this->buildEntry($match, $winnerUserIds, $winnerUsername, $winningSide);

        $postSystem->handle(
            $this->match,
            __('Verified FACEIT match record.'),
            [$card],
        );

        return $card;
    }

    private function alreadyPosted(): bool
    {
        return Message::query()
            ->where('match_id', $this->match->id)
            ->where('type', MessageType::System)
            ->whereJsonContains('attachments_json', [['source' => 'auto_fetch', 'provider' => 'faceit']])
            ->exists();
    }

    /**
     * Card payload carries BOTH the legacy 1v1 fields (`winner_user_id`,
     * `winner_username`) AND the team-play fields (`winning_team`,
     * `winner_user_ids`). The legacy fields hold the first winner so any
     * code paths still reading them get a sane scalar; settlement opts
     * into the array via `winner_user_ids` + `winning_team` and ignores
     * the legacy fields when team_size > 1.
     *
     * @param  list<int>  $winnerUserIds
     * @return array<string, mixed>
     */
    private function buildEntry(
        FaceitMatchResult $match,
        array $winnerUserIds,
        ?string $winnerUsername,
        string $winningSide,
    ): array {
        return [
            'type' => 'game_card',
            'provider' => 'faceit',
            'source' => 'auto_fetch',
            'match_id' => $match->id,
            'url' => "https://www.faceit.com/en/{$match->game}/room/{$match->id}",
            'verified' => true,
            'game' => $match->game,
            'competition_type' => $match->competitionType,
            'status' => $match->status,
            'winner_faction' => $match->winnerFaction,
            'winner_username' => $winnerUsername,
            'winner_user_id' => $winnerUserIds[0] ?? null,
            'winning_team' => $winningSide,
            'winner_user_ids' => $winnerUserIds,
            'ac_complete' => true,
            'started_at' => $match->startedAt?->toIso8601String(),
            'finished_at' => $match->finishedAt?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $extras
     */
    private function record(
        RecordAutoFetchAttemptAction $action,
        AutoFetchOutcome $outcome,
        array $extras = [],
    ): void {
        $action->handle(
            matchId: $this->match->id,
            provider: LinkedAccountProvider::Faceit,
            outcome: $outcome,
            extras: [
                'attempt_number' => $this->attempts(),
                ...$extras,
            ],
        );
    }
}
