<?php

namespace App\Jobs;

use App\Enums\LinkedAccountProvider;
use App\Events\MessageSent;
use App\Models\GameMatch;
use App\Models\Message;
use App\Services\Provider\ChessComGameClient;
use App\Services\Provider\ChessComGameResult;
use App\Services\Provider\Exceptions\ProviderUnavailableException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Resolves a pasted chess.com game URL to a chat evidence card (M8 Phase 4b
 * paste path). Mirror of `FetchLichessGameMetadataJob` adapted to
 * chess.com's archive-based API.
 *
 * Pipeline:
 *   1. Pick a candidate snapshotted chess.com username from the match
 *      (creator's preferred — either side's archive carries the same
 *      game record, so we just need one to query).
 *   2. Call `ChessComGameClient::fetchGame($url, $candidateUsername)`.
 *      The client queries the user's current + previous month archives
 *      (covers month-boundary games) and matches by URL.
 *   3. Cross-check both player usernames against the match's snapshotted
 *      chess.com handles. Same case-insensitive, order-independent rule
 *      as the Lichess job.
 *   4. Append a `type: 'game_card'`, `provider: 'chess_com'` entry to
 *      `attachments_json`, with `verified: true` only when both sides
 *      anchor. Re-broadcast `MessageSent`.
 *
 * Eventual consistency: chess.com archives lag 5-15s after game-end. If
 * the user pastes the URL right after the game, the archive may not yet
 * contain it — the client returns null. We don't retry here on null
 * (paste-path UX is "card appears or doesn't"); the user can re-paste
 * 30s later if it didn't resolve. Auto-fetch's job handles retries
 * (`AutoFetchChessComGameJob`) because it can't be re-triggered manually.
 *
 * Failures (game not found, provider unavailable, any throw) are logged
 * and swallowed — missing card is acceptable; a thrown job in failed-jobs
 * forever clutters the queue.
 */
class FetchChessComGameMetadataJob implements ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(
        public Message $message,
        public string $gameUrl,
    ) {}

    public function handle(ChessComGameClient $client): void
    {
        $match = $this->message->match;

        if ($match === null) {
            return;
        }

        $match->loadMissing('providerSnapshots');

        // Pick the creator's snapshotted chess.com username as the archive
        // to query — either side works (game appears in both archives), and
        // creator is conventionally listed first.
        $candidateUsername = $match->snapshotUsername(
            GameMatch::SIDE_CREATOR,
            LinkedAccountProvider::ChessCom,
        ) ?? $match->snapshotUsername(
            GameMatch::SIDE_TAKER,
            LinkedAccountProvider::ChessCom,
        );

        if ($candidateUsername === null) {
            // Neither player has a chess.com snapshot — we can't query an
            // archive at all. Silent skip (card just doesn't appear).
            return;
        }

        try {
            $game = $client->fetchGame($this->gameUrl, $candidateUsername);
        } catch (ProviderUnavailableException $e) {
            Log::info('chess.com game fetch failed (provider unavailable)', [
                'message_id' => $this->message->id,
                'game_url' => $this->gameUrl,
                'error' => $e->getMessage(),
            ]);

            return;
        } catch (Throwable $e) {
            Log::warning('chess.com game fetch failed (unexpected)', [
                'message_id' => $this->message->id,
                'game_url' => $this->gameUrl,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if ($game === null) {
            // Either URL doesn't correspond to a real game, or the game is
            // too new and not yet in the archive. Silent skip per the
            // paste-path doctrine (the bare URL still appears in chat).
            return;
        }

        $this->appendEntryAndRebroadcast($this->buildEntry($game, $match));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildEntry(ChessComGameResult $game, GameMatch $match): array
    {
        return [
            'type' => 'game_card',
            'provider' => 'chess_com',
            'source' => 'paste',
            'game_id' => $game->id,
            'url' => $game->url,
            'verified' => self::isVerifiedByMatch($game, $match),
            'white_username' => $game->whiteUsername,
            'black_username' => $game->blackUsername,
            'winner_color' => $game->winnerColor,
            'winner_username' => $game->winnerUsername(),
            'status' => $game->status,
            'speed' => $game->speed,
            'variant' => $game->variant,
            'rated' => $game->rated,
            'played_at' => $game->endedAt->toIso8601String(),
        ];
    }

    /**
     * Verified iff BOTH game players' chess.com usernames map onto the
     * match's snapshotted handles (one creator, one taker, in either
     * color). Case-insensitive compare.
     */
    private static function isVerifiedByMatch(ChessComGameResult $game, GameMatch $match): bool
    {
        $creator = $match->snapshotUsername(GameMatch::SIDE_CREATOR, LinkedAccountProvider::ChessCom);
        $taker = $match->snapshotUsername(GameMatch::SIDE_TAKER, LinkedAccountProvider::ChessCom);

        if ($creator === null || $taker === null) {
            return false;
        }

        $white = strtolower($game->whiteUsername);
        $black = strtolower($game->blackUsername);
        $creatorLower = strtolower($creator);
        $takerLower = strtolower($taker);

        $whiteIsCreatorBlackIsTaker = $white === $creatorLower && $black === $takerLower;
        $whiteIsTakerBlackIsCreator = $white === $takerLower && $black === $creatorLower;

        return $whiteIsCreatorBlackIsTaker || $whiteIsTakerBlackIsCreator;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function appendEntryAndRebroadcast(array $entry): void
    {
        $rebroadcastTarget = null;

        DB::transaction(function () use ($entry, &$rebroadcastTarget) {
            $fresh = Message::query()->lockForUpdate()->find($this->message->id);

            if ($fresh === null) {
                return;
            }

            $existing = is_array($fresh->attachments_json) ? $fresh->attachments_json : [];
            $fresh->update(['attachments_json' => array_merge($existing, [$entry])]);

            $rebroadcastTarget = $fresh;
        });

        if ($rebroadcastTarget !== null) {
            MessageSent::dispatch($rebroadcastTarget);
        }
    }
}
