<?php

namespace App\Http\Controllers;

use App\Actions\Lobby\JoinLobbyAction;
use App\Actions\Lobby\KickParticipantAction;
use App\Actions\Lobby\LeaveLobbyAction;
use App\Actions\Lobby\ToggleReadyAction;
use App\Enums\LinkedAccountProvider;
use App\Enums\ListingStatus;
use App\Models\Listing;
use App\Models\LobbyParticipant;
use App\Models\User;
use App\Support\LobbyInvitePass;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * M34 P3 — lobby page + soft-join/leave/ready/kick action endpoints.
 *
 * Routing shape:
 *   GET    /lobbies/{listing}                       canonical lobby URL
 *   GET    /lobbies/{token}                         invite token resolution
 *   POST   /lobbies/{listing}/join                  soft-join
 *   POST   /lobbies/{listing}/leave                 leave
 *   POST   /lobbies/{listing}/ready                 toggle Ready
 *   DELETE /lobbies/{listing}/participants/{user}   kick (owner only)
 *
 * 404 (not 403) on unauthorised view so private lobbies don't leak.
 */
class LobbyController extends Controller
{
    /**
     * Legacy `/lobbies/{listing}` URL — collapsed into `/listings/{listing}`
     * for team-play in M34 P3.1 Slice B.1. Returns a 301 (permanent) redirect
     * so previously-shared links + crawled URLs land on the new canonical
     * page. Route name retained for back-compat with Wayfinder helpers /
     * any in-flight test paths that still use it.
     */
    public function show(Listing $listing): RedirectResponse
    {
        abort_if(! $listing->isTeamPlay(), 404);

        return to_route('listings.show', ['listing' => $listing], 301);
    }

    /**
     * Resolve an invite token to its listing and 301-redirect to the
     * canonical listing URL (now hosting the lobby UI). 404 on missing /
     * non-Open / locked / cancelled / expired so leaked tokens don't point
     * at dead lobbies.
     */
    public function showByToken(string $token): RedirectResponse
    {
        $listing = Listing::query()
            ->where('invite_token', $token)
            ->first();

        if ($listing === null) {
            abort(404);
        }

        if ($listing->status !== ListingStatus::Open) {
            abort(404);
        }

        if (in_array($listing->lobby_state, ['locked', 'cancelled', 'expired'], true)) {
            abort(404);
        }

        // The token IS the access credential (M34 P5): record a per-session
        // invite pass so the canonical /listings/{id} page (the redirect
        // target) authorizes this viewer. 302, not 301 — a cached permanent
        // redirect would skip this pass-granting hop on later clicks.
        LobbyInvitePass::grant($listing);

        return to_route('listings.show', ['listing' => $listing]);
    }

    public function join(Request $request, Listing $listing, JoinLobbyAction $action): RedirectResponse
    {
        Gate::authorize('joinLobby', $listing);

        $data = $request->validate([
            'side' => ['required', 'string', Rule::in([LobbyParticipant::SIDE_A, LobbyParticipant::SIDE_B])],
        ]);

        $result = $action->handle($request->user(), $listing, $data['side']);

        Inertia::flash('toast', $this->joinToast($result, $listing));

        return back();
    }

    public function leave(Request $request, Listing $listing, LeaveLobbyAction $action): RedirectResponse
    {
        Gate::authorize('leaveLobby', $listing);

        $result = $action->handle($request->user(), $listing);

        Inertia::flash('toast', match ($result) {
            'left' => ['type' => 'info', 'message' => __('You left the lobby.')],
            'creator_cancelled' => ['type' => 'info', 'message' => __('Lobby cancelled. Any held stakes have been refunded.')],
            'locked' => ['type' => 'warning', 'message' => __('Lobby has locked — leaving now is a forfeit.')],
            'not_in_lobby' => ['type' => 'warning', 'message' => __('You\'re not in this lobby.')],
            default => ['type' => 'warning', 'message' => __('Could not leave the lobby.')],
        });

        return back();
    }

    public function toggleReady(Request $request, Listing $listing, ToggleReadyAction $action): RedirectResponse
    {
        Gate::authorize('toggleReady', $listing);

        $result = $action->handle($request->user(), $listing);

        Inertia::flash('toast', match ($result) {
            'readied' => ['type' => 'success', 'message' => __('Ready — stake escrowed.')],
            'unreadied' => ['type' => 'info', 'message' => __('Un-Ready — stake refunded.')],
            'locked_now' => ['type' => 'success', 'message' => __('All players Ready — match starting.')],
            'insufficient_balance' => ['type' => 'warning', 'message' => __('Top up your balance to ready up.')],
            'locked' => ['type' => 'warning', 'message' => __('Lobby has already locked.')],
            'not_in_lobby' => ['type' => 'warning', 'message' => __('You\'re not in this lobby.')],
            default => ['type' => 'warning', 'message' => __('Could not update Ready state.')],
        });

        return back();
    }

    public function kick(
        Request $request,
        Listing $listing,
        User $user,
        KickParticipantAction $action,
    ): RedirectResponse {
        Gate::authorize('kickFromLobby', $listing);

        $result = $action->handle($request->user(), $listing, $user);

        Inertia::flash('toast', match ($result) {
            'kicked' => ['type' => 'info', 'message' => __(':name removed from the lobby.', ['name' => $user->name])],
            'not_owner' => ['type' => 'warning', 'message' => __('Only the lobby owner can kick.')],
            'cant_kick_self' => ['type' => 'warning', 'message' => __('You can\'t kick yourself — leave the lobby to cancel it.')],
            'target_not_in_lobby' => ['type' => 'warning', 'message' => __('That player isn\'t in the lobby.')],
            'locked' => ['type' => 'warning', 'message' => __('Lobby has locked — kicks no longer apply.')],
            default => ['type' => 'warning', 'message' => __('Could not kick that player.')],
        });

        return back();
    }

    /**
     * Map the join-action sentinel to a flash toast payload. Surfaces the
     * platform name on the `not_linked` path to give users a clear hint.
     *
     * @return array{type: string, message: string}
     */
    private function joinToast(LobbyParticipant|string $result, Listing $listing): array
    {
        if ($result instanceof LobbyParticipant) {
            return ['type' => 'success', 'message' => __('Joined the lobby.')];
        }

        $platformName = LinkedAccountProvider::from($listing->platform->value)->displayName();

        return match ($result) {
            'not_team_play' => ['type' => 'warning', 'message' => __('This isn\'t a team-play listing.')],
            'not_linked' => ['type' => 'info', 'message' => __('Link a :platform account to join.', ['platform' => $platformName])],
            'already_in_lobby' => ['type' => 'warning', 'message' => __('You\'re already in another active lobby.')],
            'kick_cooldown' => ['type' => 'warning', 'message' => __('You were removed from this lobby — you can rejoin in a few minutes.')],
            'listing_unavailable' => ['type' => 'info', 'message' => __('This lobby isn\'t accepting joins.')],
            'no_open_slots' => ['type' => 'info', 'message' => __('No open slots on that side.')],
            default => ['type' => 'warning', 'message' => __('Could not join the lobby.')],
        };
    }
}
