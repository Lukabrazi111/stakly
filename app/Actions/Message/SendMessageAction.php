<?php

namespace App\Actions\Message;

use App\Enums\MatchStatus;
use App\Enums\MessageType;
use App\Events\MessageSent;
use App\Models\GameMatch;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Posts a human chat message to a match's thread.
 *
 * The HTTP path always produces `Text` messages from the authenticated user.
 * System messages (Phase 5) go through a separate Action so the type is
 * never caller-supplied — no path through this Action can produce one.
 *
 * Three guards, ordered cheapest-first:
 *
 *   1. Rate limit (10 messages / 10s per user) — anti-spam at the user
 *      level, not per-match. Uses Laravel's `RateLimiter` for a sliding
 *      window. The slot is consumed before the DB write so a flood doesn't
 *      eat DB cycles.
 *
 *   2. Status gate — `Settled` and `ManualReview` matches reject sends with
 *      a `ValidationException` (becomes 422 with a friendly message). The
 *      check reads `$match->fresh()` so a stale Eloquent instance can't
 *      bypass a recent settlement. Race window between fresh + insert is
 *      sub-millisecond and worst case is one stray message — acceptable.
 *
 *   3. Participant — caller's responsibility via the controller's
 *      `view` policy gate on `GameMatch`. This Action trusts that.
 *
 * Broadcast: dispatches `MessageSent` (ShouldBroadcastAfterCommit) on the
 * `match.{id}` private channel. Echo subscribers receive the payload shape
 * declared in `MessageSent::broadcastWith()`.
 */
class SendMessageAction
{
    private const MAX_MESSAGES_PER_WINDOW = 10;

    private const WINDOW_SECONDS = 10;

    public function handle(User $user, GameMatch $match, string $content): Message
    {
        $this->assertNotRateLimited($user);
        $this->assertChatIsOpen($match->fresh());

        $message = Message::create([
            'match_id' => $match->id,
            'user_id' => $user->id,
            'type' => MessageType::Text,
            'content' => $content,
        ]);

        MessageSent::dispatch($message);

        return $message;
    }

    /**
     * Sliding-window rate limit via Laravel's RateLimiter. Each `hit` decays
     * after WINDOW_SECONDS — 10 hits within 10s blocks the 11th.
     */
    private function assertNotRateLimited(User $user): void
    {
        $key = self::rateLimitKey($user);

        if (RateLimiter::tooManyAttempts($key, self::MAX_MESSAGES_PER_WINDOW)) {
            throw new ThrottleRequestsException(
                __('You\'re sending messages too quickly. Wait a moment and try again.')
            );
        }

        RateLimiter::hit($key, self::WINDOW_SECONDS);
    }

    /**
     * Status guard. Chat is read-only after a match resolves — Settled
     * (winner or draw refund) AND ManualReview both lock new sends. Disputed
     * stays open because the chat is the evidence record. Pending is the
     * default-open state.
     */
    private function assertChatIsOpen(GameMatch $match): void
    {
        if ($match->status === MatchStatus::Settled || $match->status === MatchStatus::ManualReview) {
            throw ValidationException::withMessages([
                'content' => __('This match is settled — chat is read-only.'),
            ]);
        }
    }

    /**
     * Exposed so tests can clear the limiter between cases without
     * duplicating the key construction.
     */
    public static function rateLimitKey(User $user): string
    {
        return 'chat:'.$user->id;
    }
}
