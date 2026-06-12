<?php

use App\Broadcasting\LobbyChannel;
use App\Broadcasting\MatchChannel;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Match chat private channel (M8 Phase 2). Auth logic + rationale live in
// the channel class so it's directly testable.
Broadcast::channel('match.{matchId}', MatchChannel::class);

// Lobby roster/state channel (M34 P3.2). Auth via `LobbyChannel::join`
// (mirrors `ListingPolicy::viewLobby`). Implicit model binding resolves
// `{listing}` to a `Listing` model.
Broadcast::channel('lobby.{listing}', LobbyChannel::class);
