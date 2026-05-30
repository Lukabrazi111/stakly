<?php

namespace App\Actions\GameMatch;

use App\Actions\Admin\NotifyAdminsAction;
use App\Actions\Message\PostSystemMessageAction;
use App\Enums\MatchStatus;
use App\Filament\Resources\GameMatches\GameMatchResource;
use App\Models\GameMatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Player-triggered escalation during the Pending window. Match flips to `Disputed`
 * and lands in the admin review queue. Resolution is admin-driven (auto-arbitration
 * via `ResolveDisputeAction` is kept for tests + future use).
 *
 * Returns true if opened, false on race (lock acquired after status moved off Pending).
 */
class OpenDisputeAction
{
    public function __construct(
        private readonly PostSystemMessageAction $postSystem,
        private readonly NotifyAdminsAction $notifyAdmins,
    ) {}

    public function handle(User $user, GameMatch $match): bool
    {
        $opened = DB::transaction(function () use ($match, $user) {
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

            return true;
        });

        // After-commit: inside the transaction the notification would fire even on rollback,
        // pointing the bell at a match that "didn't happen."
        if ($opened) {
            $this->notifyAdminsOfDispute($match->fresh());
        }

        return $opened;
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
