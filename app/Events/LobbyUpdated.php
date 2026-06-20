<?php

namespace App\Events;

use App\Models\Listing;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Signal that a team-play lobby's roster or state changed. Dispatched from
 * every roster-mutating Lobby action after the DB transaction commits.
 *
 * Payload is intentionally minimal — the frontend uses this purely as a
 * trigger to call `router.reload({ only: ['lobby'] })`. Keeping
 * `LobbyResource` as the single source of derived payload truth avoids
 * having to mirror its aggregate / trust / viewer computation client-side.
 *
 * `ShouldDispatchAfterCommit` defends against an outer transaction rolling
 * back — a broadcast for a roster change that didn't actually persist
 * would leave every connected client showing stale truth.
 */
class LobbyUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Listing $listing) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("lobby.{$this->listing->id}")];
    }

    public function broadcastAs(): string
    {
        return 'lobby.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['listing_id' => $this->listing->id];
    }
}
