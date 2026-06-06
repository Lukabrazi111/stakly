<?php

namespace App\Actions\GameMatch;

use App\Actions\Admin\NotifyAdminsAction;
use App\Actions\Message\PostSystemMessageAction;
use App\Actions\Message\SendMessageAction;
use App\Enums\Game;
use App\Enums\MatchStatus;
use App\Enums\MessageType;
use App\Events\MessageSent;
use App\Filament\Resources\GameMatches\GameMatchResource;
use App\Models\GameMatch;
use App\Models\Message;
use App\Models\User;
use App\Notifications\DisputeOpenedNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Player-triggered escalation during the Pending window. Match flips to `Disputed`
 * and lands in the admin review queue.
 *
 * M14 Slice 4a — when `config('stakly.dispute_fast_path_enabled')` is true AND
 * the match is chess, `ResolveDisputeAction` runs synchronously right after
 * the status flip. Skips the wait for the next 5-min cron tick. Default off
 * until Slice 4b's Phase-1-metrics checkpoint.
 *
 * Returns true if opened, false on race (lock acquired after status moved off Pending).
 */
class OpenDisputeAction
{
    public function __construct(
        private readonly PostSystemMessageAction $postSystem,
        private readonly NotifyAdminsAction $notifyAdmins,
        private readonly ResolveDisputeAction $resolveDispute,
    ) {}

    public function handle(
        User $user,
        GameMatch $match,
        ?string $reason = null,
        ?UploadedFile $evidence = null,
    ): bool {
        $opened = DB::transaction(function () use ($match, $user, $reason, $evidence) {
            $locked = GameMatch::query()->lockForUpdate()->findOrFail($match->id);

            if ($locked->status !== MatchStatus::Pending) {
                return false;
            }

            $this->flipToDisputed($locked, $user);

            $this->postSystem->handle(
                $locked,
                __('Dispute opened by :name. Please post any evidence (screenshots, game URLs, PGN) in this chat — an admin will review.', [
                    'name' => $user->name,
                ]),
                [['type' => 'dispute_prompt']],
            );

            $this->postOpenerClaim($locked, $user, $reason, $evidence);

            return true;
        });

        // After-commit: inside the transaction the notification would fire even on rollback,
        // pointing the bell at a match that "didn't happen."
        if ($opened) {
            $fresh = $match->fresh(['listing.user', 'taker']);
            $this->notifyAdminsOfDispute($fresh);
            $this->notifyOpponent($fresh, $user);

            $this->runFastPathIfEnabled($fresh);
        }

        return $opened;
    }

    /**
     * M14 Slice 4a — flag-gated synchronous arbitration. Chess only today;
     * `ResolveDisputeAction` short-circuits on terminal statuses, so a race
     * where admin resolves the same match while we're querying is safe.
     */
    private function runFastPathIfEnabled(GameMatch $match): void
    {
        if (! config('stakly.dispute_fast_path_enabled')) {
            return;
        }

        $game = $match->listing?->game;
        if ($game !== Game::Chess) {
            return;
        }

        $this->resolveDispute->handle($match);
    }

    /**
     * Posts the disputing user's reason + optional evidence image as a chat
     * message authored by them, tagged with the `dispute_opening` attachment
     * marker so the bubble renders a "Reason for dispute" header. Closes the
     * fairness gap of "opponent sees a banner but doesn't know what's being
     * claimed" — and gives admin a single anchor message to read first when
     * a dispute lands in ManualReview.
     */
    private function postOpenerClaim(
        GameMatch $match,
        User $user,
        ?string $reason,
        ?UploadedFile $evidence,
    ): void {
        $message = Message::create([
            'match_id' => $match->id,
            'user_id' => $user->id,
            'type' => MessageType::Text,
            'content' => $reason,
            'attachments_json' => [['type' => 'dispute_opening']],
        ]);

        if ($evidence !== null) {
            SendMessageAction::attachFileTo($message, $evidence);
            $message->load('media');
        }

        MessageSent::dispatch($message);
    }

    private function notifyOpponent(GameMatch $match, User $opener): void
    {
        $opponent = $match->listing->user_id === $opener->id
            ? $match->taker
            : $match->listing->user;

        $opponent->notify(new DisputeOpenedNotification($match, $opener));
    }

    private function flipToDisputed(GameMatch $match, User $opener): void
    {
        $match->update([
            'status' => MatchStatus::Disputed,
            'dispute_opened_at' => now(),
            'dispute_opened_by' => $opener->id,
        ]);
    }

    private function notifyAdminsOfDispute(GameMatch $match): void
    {
        $match->loadMissing(['listing.user', 'taker', 'disputeOpener']);

        $creator = $match->listing?->user?->username ?? 'unknown';
        $taker = $match->taker?->username ?? 'unknown';
        $stake = number_format((float) ($match->listing?->stake_amount ?? 0), 2);
        $openedBy = $match->disputeOpener?->name ?? 'a player';

        $this->notifyAdmins->handle(
            title: "Dispute opened — match #{$match->id}",
            body: "{$openedBy} reported a problem. {$creator} vs {$taker}, \${$stake} stake each. Please review.",
            url: GameMatchResource::getUrl('view', ['record' => $match]),
            color: 'warning',
        );
    }
}
