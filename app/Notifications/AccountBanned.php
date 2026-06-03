<?php

namespace App\Notifications;

use App\Models\UserModerationLog;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * M30 Phase 4 — dispatched the moment an admin bans a user. Three channels
 * unconditionally (database + broadcast + mail) — bypasses the parent's
 * preference flow because account moderation cannot be silenced. The
 * `database` row also drives the persistent in-app banner (read by the
 * bell + by `HandleInertiaRequests` to derive `auth.user.ban`).
 */
class AccountBanned extends PlayerNotification
{
    public function __construct(public readonly UserModerationLog $log) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast', 'mail'];
    }

    public function eventType(): string
    {
        return 'account_banned';
    }

    public function title(): string
    {
        return __('Your Stakly account has been suspended');
    }

    public function body(): string
    {
        return __('Reason: :reason', ['reason' => $this->log->reason]);
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
            ->subject(__('Your Stakly account has been suspended'))
            ->greeting(__('Hello,'))
            ->line(__('Your Stakly account has been suspended by our moderation team.'))
            ->line(__('Reason: :reason', ['reason' => $this->log->reason]))
            ->line(__('While suspended, you cannot create listings, take listings, change your username, or update your profile. Your existing balance remains in your wallet and is not affected.'))
            ->line(__('If you believe this is a mistake or want to appeal, reply to this email or contact support@stakly.com.'))
            ->salutation(__('— The Stakly team'));
    }
}
