<?php

namespace App\Jobs;

use App\Enums\LinkedAccountProvider;
use App\Events\MessageSent;
use App\Models\GameMatch;
use App\Models\Message;
use App\Services\Provider\ChessComGameClient;
use App\Services\Provider\ChessComGameResult;
use App\Services\Provider\Exceptions\ProviderError;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Resolves a pasted chess.com game URL to a chat evidence card (paste path).
 *
 * No retry on null — paste-path UX is "card appears or doesn't"; the user can re-paste
 * if archive lag (5-15s) missed it. Failures are logged + swallowed; a missing card is
 * acceptable, a thrown job in failed-jobs forever clutters the queue.
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

        // Either side's archive carries the same game — creator preferred for convention.
        $candidateUsername = $match->snapshotUsername(
            GameMatch::SIDE_CREATOR,
            LinkedAccountProvider::ChessCom,
        ) ?? $match->snapshotUsername(
            GameMatch::SIDE_TAKER,
            LinkedAccountProvider::ChessCom,
        );

        if ($candidateUsername === null) {
            // Neither player has a chess.com snapshot — no archive to query. Silent skip.
            return;
        }

        try {
            $game = $client->fetchGame($this->gameUrl, $candidateUsername);
        } catch (ProviderError $e) {
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
            // Game doesn't exist or hasn't hit the archive yet. Silent skip (bare URL still shows).
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
     * Verified iff BOTH players map onto the snapshotted handles (case-insensitive, either color).
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
