<?php

namespace App\Notifications;

use App\Models\Withdrawal;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Fired when a withdrawal is held because it targets a never-used address
 * (M9 Phase 0e).
 *
 * The whole point of the hold is that the account's real owner gets a chance to
 * notice a payout they didn't request, so this ships on all three channels
 * unconditionally — including mail, since an attacker sitting in the session
 * would simply not read the in-app bell.
 */
class WithdrawalHeldNotification extends PlayerNotification
{
    public function __construct(public readonly Withdrawal $withdrawal) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast', 'mail'];
    }

    public function eventType(): string
    {
        return 'withdrawal_held';
    }

    public function title(): string
    {
        return __('Withdrawal to a new address is on hold');
    }

    public function body(): string
    {
        return __('Sending :amount USDT to :address after a security hold.', [
            'amount' => (string) $this->withdrawal->amount,
            'address' => $this->truncatedAddress(),
        ]);
    }

    public function actionUrl(): string
    {
        return route('wallet.withdrawals', ['locale' => app()->getLocale()]);
    }

    public function relatedId(): ?int
    {
        return $this->withdrawal->id;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Withdrawal to a new address is on hold'))
            ->greeting(__('Hello,'))
            ->line(__('A withdrawal from your Stakly wallet is going to an address you have not used before, so we are holding it briefly as a security precaution.'))
            ->line(__('Amount: :amount USDT', ['amount' => (string) $this->withdrawal->amount]))
            ->line(__('Address: :address', ['address' => $this->withdrawal->destination_address]))
            ->line(__('It will be sent automatically after the hold unless you tell us otherwise.'))
            ->action(__('Review your withdrawals'), $this->actionUrl())
            ->line(__('If you did NOT request this, contact support@stakly.com immediately and change your password — we can stop the payout while it is still on hold.'))
            ->salutation(__('— The Stakly team'));
    }

    private function truncatedAddress(): string
    {
        $address = $this->withdrawal->destination_address;

        return mb_substr($address, 0, 6).'…'.mb_substr($address, -4);
    }
}
