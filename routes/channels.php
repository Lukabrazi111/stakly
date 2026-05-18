<?php

use App\Broadcasting\MatchChannel;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Match chat private channel (M8 Phase 2). Auth logic + rationale live in
// the channel class so it's directly testable.
Broadcast::channel('match.{matchId}', MatchChannel::class);
