<?php

namespace App\Http\Controllers;

use App\Actions\GameMatch\AcceptCancellationAction;
use App\Actions\GameMatch\DispatchAutoFetchAction;
use App\Actions\GameMatch\OpenDisputeAction;
use App\Actions\GameMatch\RejectCancellationAction;
use App\Actions\GameMatch\RequestCancellationAction;
use App\Actions\GameMatch\TakeListingAction;
use App\Enums\MatchStatus;
use App\Exceptions\InsufficientBalanceException;
use App\Http\Requests\GameMatch\IndexMatchesRequest;
use App\Http\Requests\GameMatch\RequestCancellationRequest;
use App\Http\Requests\GameMatch\TakeRequest;
use App\Http\Requests\Match\OpenDisputeRequest;
use App\Http\Resources\GameMatchResource;
use App\Http\Resources\MessageResource;
use App\Models\GameMatch;
use App\Models\Listing;
use App\Services\ParticipantStats;
use App\Services\RecentForm;
use App\Services\SellerTrust;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class GameMatchController extends Controller
{
    private const MATCHES_PER_PAGE = 12;

    private const MESSAGES_PER_PAGE = 200;

    /**
     * Authenticated player's own matches — both as creator (via the related
     * listing's user_id) and as taker. Same Spatie query-builder URL contract
     * as the listings index:
     *
     *   /matches?filter[status]=pending&page=2
     *
     * Newest first by created_at — Pending matches naturally surface at the
     * top because they're recent. Status filter chips on the frontend cover
     * the cross-section a user actually wants to slice.
     */
    public function index(IndexMatchesRequest $request): Response
    {
        $user = $request->user();

        $query = GameMatch::query()
            ->forRosterParticipant($user->id)
            ->with([
                // Closure form (was a `listing:...` column string) so we can
                // hang a live-fill withCount off the listing for the M44
                // recruiting-lobby card. `lobby_state` added for the card's
                // "Recruiting" vs "Ready check" label.
                'listing' => fn ($q) => $q
                    ->select('id', 'user_id', 'game', 'stake_amount', 'platform', 'time_control', 'status', 'team_size', 'lobby_state')
                    ->withCount(['lobbyParticipants as live_participant_count' => fn ($p) => $p->live()]),
                'listing.user:id,name,username',
                'taker:id,name,username',
                'winner:id,name,username',
                // GameMatchResource exposes snapshotted usernames per
                // listing.platform — without this eager-load the
                // resource transformer N+1s on the snapshot table.
                'providerSnapshots',
            ]);

        if ($request->view() === 'all') {
            // All view: every status, sliced by the optional chip filter.
            $query = QueryBuilder::for($query)
                ->allowedFilters(AllowedFilter::exact('status'));
        } else {
            // In Progress view (default): active locked matches + any recruiting
            // lobby the viewer is live in (M44). Recruiting lobbies pin to the
            // top — they're the most actionable thing to return to.
            $query->inProgressForViewer($user->id)
                ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [MatchStatus::LobbyFilling->value]);
        }

        $matches = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::MATCHES_PER_PAGE)
            ->withQueryString();

        return Inertia::render('match/index', [
            'matches' => GameMatchResource::collection($matches),
            'filters' => $request->filters(),
        ]);
    }

    /**
     * Take an open listing. Business logic lives in `TakeListingAction`;
     * this method is the HTTP adapter: map domain sentinels to flash toasts
     * and redirects.
     *
     * Sentinels returned by the Action:
     *   - `GameMatch` instance → match created; redirect to match page.
     *   - `'race_lost'`        → listing state changed during the request
     *                            (Taken / Expired / Cancelled / past-expiry).
     *   - `'owner_inactive'`   → owner flipped to Inactive Mode mid-flight.
     *   - `'already_in_match'` → taker is already in an in-flight match for this
     *                            game (M37 one-active-match-per-game).
     *   - `'owner_busy'`       → the listing owner is already in an in-flight
     *                            match for this game.
     *
     * `InsufficientBalanceException` is the rare race where balance dropped
     * between TakeRequest's pre-check and the wallet's row-locked re-check
     * — mapped to a `ValidationException` keyed on `amount`.
     */
    public function take(TakeRequest $request, Listing $listing, TakeListingAction $action): RedirectResponse
    {
        try {
            $result = $action->handle($request->user(), $listing);
        } catch (InsufficientBalanceException) {
            throw ValidationException::withMessages([
                'amount' => __('Stake exceeds your available balance.'),
            ]);
        }

        if ($result === 'not_linked') {
            Inertia::flash('toast', [
                'type' => 'info',
                'message' => __('Link a :platform account before taking this match.', [
                    'platform' => $listing->platform->displayName(),
                ]),
            ]);

            return to_route('linked-accounts.edit');
        }

        if ($result === 'not_takeable') {
            Inertia::flash('toast', [
                'type' => 'info',
                'message' => __('This is a team-play lobby — join from the lobby page instead.'),
            ]);

            return to_route('listings.show', $listing);
        }

        if ($result === 'owner_inactive') {
            Inertia::flash('toast', [
                'type' => 'info',
                'message' => __('This player is currently inactive. Their listings are temporarily unavailable.'),
            ]);

            return to_route('listings.show', $listing);
        }

        if ($result === 'already_in_match') {
            Inertia::flash('toast', [
                'type' => 'warning',
                'message' => __('Finish your current :game match before taking another.', [
                    'game' => $listing->game->displayName(),
                ]),
            ]);

            return to_route('listings.show', $listing);
        }

        if ($result === 'owner_busy') {
            Inertia::flash('toast', [
                'type' => 'info',
                'message' => __('This player is in another match right now. Their listing will be available again soon.'),
            ]);

            return to_route('listings.show', $listing);
        }

        if ($result === 'race_lost') {
            Inertia::flash('toast', [
                'type' => 'info',
                'message' => __('This listing is no longer available.'),
            ]);

            return to_route('listings.show', $listing);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Match started.'),
        ]);

        return to_route('matches.show', $result);
    }

    /**
     * Match detail page. Renders the "waiting for game" state (Pending —
     * card auto-fetch is the settlement trigger), settlement summary
     * (Settled), cancellation banner (Cancelled), or dispute banner
     * (Disputed / ManualReview) based on status.
     *
     * Non-participants get 404 (not 403) to avoid leaking match existence.
     *
     * M16 Phase 2 — every Pending page-visit dispatches the auto-fetch
     * job. Idempotency lives at the job layer (`ShouldBeUnique` plus
     * `alreadyPosted()`); F5-spam is harmless.
     */
    public function show(GameMatch $match, DispatchAutoFetchAction $dispatchAutoFetch): Response|RedirectResponse
    {
        $match->load([
            'listing:id,user_id,game,stake_amount,platform,time_control,status,team_size',
            'listing.user:id,name,username',
            'taker:id,name,username',
            'winner:id,name,username',
            'providerSnapshots',
            // M34 P6 — team rosters for `GameMatchResource::team_a` /
            // `team_b`. Cheap for 1v1 (empty collection); essential for
            // team play to avoid N+1 on the avatar accessor.
            'listing.lobbyParticipants' => fn ($q) => $q->orderBy('side')->orderBy('slot_index'),
            'listing.lobbyParticipants.user:id,name,username',
            'listing.lobbyParticipants.user.media',
            // M34 P8 Slice A — `linkedAccounts` feeds the per-player skill
            // rating in the rich roster cards. Cheap for 1v1 (no roster).
            'listing.lobbyParticipants.user.linkedAccounts',
        ]);

        abort_if(request()->user()->cannot('view', $match), 404);

        // M34 P8 Slice A — batch attach `seller_trust` + `platform_stats`
        // to each roster user so `GameMatchResource::platformStatsFor`
        // reads the attributes without N+1. Mirrors the lobby's
        // `ListingController::showTeamPlay` pattern. No-ops for 1v1
        // (empty roster) and for non-team-play matches generally.
        if ($match->listing->isTeamPlay() && $match->listing->relationLoaded('lobbyParticipants')) {
            $userIds = $match->listing->lobbyParticipants
                ->whereNull('kicked_at')
                ->pluck('user_id')
                ->unique()
                ->values()
                ->all();

            if (count($userIds) > 0) {
                $trust = SellerTrust::forBatch($userIds);
                $stats = ParticipantStats::forBatch($userIds);
                // M34 — recent W/L form for the match roster cards, matching the
                // lobby slot cards. Scoped to the listing's game (team play = CS2)
                // so the strip stays coherent with the FACEIT dial beside it.
                $forms = RecentForm::forBatch($userIds, $match->listing->game);

                foreach ($match->listing->lobbyParticipants as $participant) {
                    if ($participant->kicked_at !== null) {
                        continue;
                    }

                    $participant->user->setAttribute(
                        'seller_trust',
                        $trust[$participant->user_id] ?? ['rate_30d' => null, 'settled_lifetime' => 0],
                    );
                    $participant->user->setAttribute(
                        'platform_stats',
                        $stats[$participant->user_id] ?? null,
                    );
                    $participant->user->setAttribute(
                        'recent_form',
                        $forms[$participant->user_id] ?? [],
                    );
                }
            }
        }

        // M34 P1 — `LobbyFilling` matches don't yet have a lobby UI (lands in
        // P3). Redirect to the listing detail page so users see the listing
        // they came from rather than the half-rendered match page. P3 swaps
        // the target to `route('lobbies.show', $match->listing)`.
        if ($match->status === MatchStatus::LobbyFilling) {
            return to_route('listings.show', $match->listing);
        }

        // Only Pending matches can auto-settle. Dispatching for a resolved
        // match just records a `not_pending` skip row on every page view —
        // unbounded write growth + admin-infolist noise. The cron / chat-send /
        // stream trigger sites keep their own defensive skip for the genuine
        // status-race window; this high-frequency page-visit path doesn't need it.
        if ($match->status === MatchStatus::Pending) {
            $dispatchAutoFetch->handle($match);
        }

        // M8 Phase 2 — last 200 messages, chrono order. The composite
        // (match_id, id) index makes this cheap; a busy match should not
        // generate enough messages for pagination concerns in v1. If
        // long-running disputes balloon over 200 we can add cursor
        // pagination later — for now a flat slice keeps the frontend simple.
        //
        // `media` eager-loaded for the Phase 3 Slice 1 image attachments —
        // without it, `MessageAttachmentsPayload::forMessage` would N+1 across
        // every message that has an image (or even ones that don't, since
        // `getMedia` calls the relation).
        $messages = $match->messages()
            ->with(['user:id,name,username', 'media'])
            ->orderByDesc('id')
            ->limit(self::MESSAGES_PER_PAGE)
            ->get()
            ->reverse()
            ->values();

        return Inertia::render('match/show', [
            'match' => (new GameMatchResource($match))->resolve(),
            'messages' => MessageResource::collection($messages),
        ]);
    }

    /**
     * Player-triggered escalation during the Pending window. Either
     * participant can open a dispute — the match flips to `Disputed` and
     * lands in the M12 admin review queue. M16 removed player self-reports,
     * so this button is the ONLY player-driven escalation path during
     * Pending (alongside `RequestCancellationAction` for cooperative exit).
     * Business logic lives in `OpenDisputeAction`.
     */
    public function openDispute(OpenDisputeRequest $request, GameMatch $match, OpenDisputeAction $action): RedirectResponse
    {
        $user = $request->user();

        abort_if($user->cannot('openDispute', $match), 403);

        $opened = $action->handle(
            $user,
            $match,
            $request->input('reason'),
            $request->file('evidence'),
        );

        Inertia::flash('toast', $opened
            ? ['type' => 'warning', 'message' => __('Dispute opened — an admin will review and resolve this match.')]
            : ['type' => 'info', 'message' => __('This match has already been resolved.')],
        );

        return back();
    }

    /**
     * M10 — propose mutual cancellation. Either participant can request;
     * the other accepts (refund both stakes) or rejects (request closed,
     * 30-min cooldown for the requester). Authorization gates participant,
     * Pending status, no-open-request, and past-cooldown — see
     * `GameMatchPolicy::requestCancellation`. Request body carries an
     * optional reason capped at 200 chars.
     */
    public function requestCancellation(
        RequestCancellationRequest $request,
        GameMatch $match,
        RequestCancellationAction $action,
    ): RedirectResponse {
        $user = $request->user();

        abort_if($user->cannot('requestCancellation', $match), 403);

        $resolution = $action->handle(
            $user,
            $match,
            $request->validated('reason'),
        );

        Inertia::flash('toast', match ($resolution) {
            'requested' => [
                'type' => 'success',
                'message' => __('Cancellation request sent — waiting for your opponent.'),
            ],
            'race_lost' => [
                'type' => 'info',
                'message' => __('This match has already been resolved.'),
            ],
            default => [
                'type' => 'info',
                'message' => __('Cancellation request not recorded.'),
            ],
        });

        return back();
    }

    /**
     * M10 — accept the opponent's pending cancellation request. Refunds
     * both stakes, flips match + listing to Cancelled.
     * Policy: participant who is NOT the requester, Pending status, open
     * request must exist.
     */
    public function acceptCancellation(
        Request $request,
        GameMatch $match,
        AcceptCancellationAction $action,
    ): RedirectResponse {
        $user = $request->user();

        abort_if($user->cannot('acceptCancellation', $match), 403);

        $resolution = $action->handle($user, $match);

        Inertia::flash('toast', match ($resolution) {
            'cancelled', 'already_cancelled' => [
                'type' => 'success',
                'message' => __('Match cancelled. Both stakes refunded.'),
            ],
            'race_lost' => [
                'type' => 'info',
                'message' => __('This match has already been resolved.'),
            ],
            'request_missing' => [
                'type' => 'info',
                'message' => __('There is no open cancellation request.'),
            ],
            default => [
                'type' => 'warning',
                'message' => __('Could not accept cancellation.'),
            ],
        });

        return back();
    }

    /**
     * M10 — decline the opponent's pending cancellation request. Match
     * stays Pending; the requester enters the 30-min per-user cooldown
     * before they can request again. Policy: participant who is NOT the
     * requester, Pending status, open request must exist.
     */
    public function rejectCancellation(
        Request $request,
        GameMatch $match,
        RejectCancellationAction $action,
    ): RedirectResponse {
        $user = $request->user();

        abort_if($user->cannot('rejectCancellation', $match), 403);

        $resolution = $action->handle($user, $match);

        Inertia::flash('toast', match ($resolution) {
            'rejected' => [
                'type' => 'info',
                'message' => __('Cancellation request declined. Match continues.'),
            ],
            'race_lost' => [
                'type' => 'info',
                'message' => __('This match has already been resolved.'),
            ],
            'request_missing' => [
                'type' => 'info',
                'message' => __('There is no open cancellation request.'),
            ],
            default => [
                'type' => 'warning',
                'message' => __('Could not decline cancellation.'),
            ],
        });

        return back();
    }
}
