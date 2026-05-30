<?php

namespace App\Jobs;

use App\Enums\LinkedAccountProvider;
use App\Events\MessageSent;
use App\Models\GameMatch;
use App\Models\Message;
use App\Services\Provider\Exceptions\ProviderUnavailableException;
use App\Services\Provider\LichessGameClient;
use App\Services\Provider\LichessGameResult;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Resolves a pasted Lichess game URL to a chat evidence card (paste path).
 * Cross-checks player usernames against snapshots; unverified cards still render
 * with the badge dropped (chat sees the game existed but can't confirm participants).
 *
 * Failures are logged + swallowed — missing card is acceptable, a thrown job clutters failed-jobs forever.
 */
class FetchLichessGameMetadataJob implements ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(
        public Message $message,
        public string $gameId,
    ) {}

    public function handle(LichessGameClient $client): void
    {
        try {
            $game = $client->fetchGame($this->gameId);
        } catch (ProviderUnavailableException $e) {
            Log::info('Lichess game fetch failed (provider unavailable)', [
                'message_id' => $this->message->id,
                'game_id' => $this->gameId,
                'error' => $e->getMessage(),
            ]);

            return;
        } catch (Throwable $e) {
            Log::warning('Lichess game fetch failed (unexpected)', [
                'message_id' => $this->message->id,
                'game_id' => $this->gameId,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if ($game === null) {
            // 404 → user pasted a non-existent game URL. Silent skip; bare URL still renders.
            return;
        }

        $match = $this->message->match;

        if ($match === null) {
            return;
        }

        $match->loadMissing('providerSnapshots');

        $this->appendEntryAndRebroadcast($this->buildEntry($game, $match));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildEntry(LichessGameResult $game, GameMatch $match): array
    {
        return [
            'type' => 'game_card',
            'provider' => 'lichess',
            'source' => 'paste',
            'game_id' => $game->id,
            'url' => 'https://lichess.org/'.$game->id,
            'verified' => self::isVerifiedByMatch($game, $match),
            'white_username' => $game->whiteUsername,
            'black_username' => $game->blackUsername,
            'winner_color' => $game->winnerColor,
            'winner_username' => $game->winnerUsername(),
            'status' => $game->status,
            'speed' => $game->speed,
            'variant' => $game->variant,
            'rated' => $game->rated,
            'played_at' => $game->lastMoveAt->toIso8601String(),
        ];
    }

    /**
     * Verified iff BOTH players map onto the snapshotted handles (one creator, one taker, either color).
     * Case-insensitive — `players.{color}.user.name` preserves the user's case display.
     */
    private static function isVerifiedByMatch(LichessGameResult $game, GameMatch $match): bool
    {
        $creator = $match->snapshotUsername(GameMatch::SIDE_CREATOR, LinkedAccountProvider::Lichess);
        $taker = $match->snapshotUsername(GameMatch::SIDE_TAKER, LinkedAccountProvider::Lichess);

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
     * Row lock prevents read-modify-write race on the JSON column when a concurrent job
     * (e.g. auto-fetch firing close to a paste) appends entries.
     *
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
