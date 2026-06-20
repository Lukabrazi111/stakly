export type NotificationEventType =
    | 'listing_taken'
    | 'listing_expired'
    | 'team_match_started'
    | 'match_settled'
    | 'match_manual_review'
    | 'dispute_opened'
    | 'dispute_resolved'
    | 'cancellation_requested'
    | 'cancellation_accepted'
    | 'cancellation_rejected'
    | 'account_banned'
    | 'account_restored';

export interface Notification {
    id: string;
    event_type: NotificationEventType | null;
    title: string;
    body: string;
    action_url: string | null;
    related_id: number | null;
    read_at: string | null;
    created_at: string;
}
