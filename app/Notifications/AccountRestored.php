<?php

namespace App\Notifications;

use App\Models\UserModerationLog;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * M30 Phase 4 — dispatched the moment an admin lifts a user's ban. Mirror
 * of `AccountBanned`: three channels unconditionally so the user knows the
 * ban was lifted across whichever surface they next visit (bell, page
 * load, inbox).
 */
class AccountRestored extends PlayerNotification
{
    public function __construct(public readonly UserModerationLog $log) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast', 'mail'];
    }

    public function eventType(): string
    {
        return 'account_restored';
    }

    public function title(): string
    {
        return __('Your Stakly account has been restored');
    }

    public function body(): string
    {
        return __('You can create listings, take matches, and update your profile again.');
    }

    public function actionUrl(): string
    {
        return route('home');
    }

    public function relatedId(): ?int
    {
        return $this->log->id;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Your Stakly account has been restored'))
            ->greeting(__('Welcome back,'))
            ->line(__('Your Stakly account has been restored. You can now log in, create listings, take matches, and update your profile as before.'))
            ->line(__('Your wallet balance is unchanged from when the suspension began.'))
            ->action(__('Open Stakly'), route('home'))
            ->salutation(__('— The Stakly team'));
    }
}
