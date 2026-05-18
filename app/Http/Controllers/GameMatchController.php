<?php

namespace App\Http\Controllers;

use App\Actions\GameMatch\ConfirmOutcomeAction;
use App\Actions\GameMatch\OpenDisputeAction;
use App\Actions\GameMatch\TakeListingAction;
use App\Enums\MatchOutcome;
use App\Exceptions\InsufficientBalanceException;
use App\Http\Requests\GameMatch\ConfirmRequest;
use App\Http\Requests\GameMatch\IndexMatchesRequest;
use App\Http\Requests\GameMatch\TakeRequest;
use App\Http\Resources\GameMatchResource;
use App\Http\Resources\MessageResource;
use App\Models\GameMatch;
use App\Models\Listing;
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

        $matches = QueryBuilder::for(
            GameMatch::query()
                ->forParticipant($user->id)
                ->with([
                    'listing:id,user_id,game,stake_amount,time_control,status',
                    'listing.user:id,name,username',
                    'taker:id,name,username',
                    'winner:id,name,username',
                ]),
        )
            ->allowedFilters(
                AllowedFilter::exact('status'),
            )
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

        if ($result === 'owner_inactive') {
            Inertia::flash('toast', [
                'type' => 'info',
                'message' => __('This player is currently inactive. Their listings are temporarily unavailable.'),
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
     * Match detail page. Renders confirm UI (Pending), settlement summary
     * (Settled), or dispute banner (Disputed / ManualReview) based on status.
     *
     * Non-participants get 404 (not 403) to avoid leaking match existence.
     */
    public function show(GameMatch $match): Response
    {
        $match->load([
            'listing:id,user_id,game,stake_amount,time_control,status',
            'listing.user:id,name,username',
            'taker:id,name,username',
            'winner:id,name,username',
        ]);

        abort_if(request()->user()->cannot('view', $match), 404);

        // M8 Phase 2 — last 200 messages, chrono order. The composite
        // (match_id, id) index makes this cheap; a busy match should not
        // generate enough messages for pagination concerns in v1. If
        // long-running disputes balloon over 200 we can add cursor
        // pagination later — for now a flat slice keeps the frontend simple.
        $messages = $match->messages()
            ->with('user:id,name,username')
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
     * Record a player's outcome confirmation. Business logic + resolution
     * branching live in `ConfirmOutcomeAction`. This method authorizes,
     * delegates, and maps the returned sentinel to a flash toast.
     */
    public function confirm(ConfirmRequest $request, GameMatch $match, ConfirmOutcomeAction $action): RedirectResponse
    {
        $user = $request->user();
        $newOutcome = MatchOutcome::from($request->validated('outcome'));

        abort_if($user->cannot('confirm', $match), 403);

        $resolution = $action->handle($user, $match, $newOutcome);

        return $this->confirmRedirect($resolution);
    }

    /**
     * Manual escalation to game-API resolution during the player-confirm
     * window. Either participant can open a dispute — the API winner is
     * authoritative and overrides player self-reports. Business logic lives
     * in `OpenDisputeAction`.
     *
     * Note on the abuse vector: opening a dispute before the opponent has
     * had a chance to confirm IS allowed (the 4h timeout would otherwise
     * be the only path forward). The API is the source of truth, so
     * escalating early doesn't bias the outcome. Spammy / malicious
     * dispute behaviour falls under future anti-abuse tooling.
     */
    public function openDispute(Request $request, GameMatch $match, OpenDisputeAction $action): RedirectResponse
    {
        $user = $request->user();

        abort_if($user->cannot('openDispute', $match), 403);

        $resolution = $action->handle($user, $match);

        if ($resolution === null) {
            Inertia::flash('toast', [
                'type' => 'info',
                'message' => __('This match has already been resolved.'),
            ]);

            return back();
        }

        Inertia::flash('toast', match ($resolution) {
            'settled-by-api' => ['type' => 'success', 'message' => __('Dispute resolved — game API determined the winner.')],
            'settled-by-api-draw' => ['type' => 'success', 'message' => __('Dispute resolved — game API ruled it a draw. Stakes refunded.')],
            'manual-review' => ['type' => 'warning', 'message' => __('Dispute opened — game API could not determine a winner. Match flagged for admin review.')],
            default => ['type' => 'warning', 'message' => __('Dispute opened — awaiting resolution.')],
        });

        return back();
    }

    /**
     * Map a `ConfirmOutcomeAction` resolution sentinel to its flash toast.
     * `too-late` gets its own pre-branch since it's the only resolution
     * that explicitly cancels the user's action (everything else either
     * recorded their claim or progressed the match).
     */
    private function confirmRedirect(string $resolution): RedirectResponse
    {
        if ($resolution === 'too-late') {
            Inertia::flash('toast', [
                'type' => 'info',
                'message' => __('This match has already been resolved.'),
            ]);

            return back();
        }

        Inertia::flash('toast', match ($resolution) {
            'settled' => ['type' => 'success', 'message' => __('Both players agreed — match settled.')],
            'settled-as-draw' => ['type' => 'success', 'message' => __('Both players agreed it was a draw. Stakes refunded.')],
            'settled-by-api' => ['type' => 'success', 'message' => __('Players disagreed — game API resolved the match.')],
            'settled-by-api-draw' => ['type' => 'success', 'message' => __('Players disagreed — game API ruled it a draw. Stakes refunded.')],
            'manual-review' => ['type' => 'warning', 'message' => __('Game API could not determine a winner — match flagged for admin review.')],
            'disputed' => ['type' => 'warning', 'message' => __('Both players disagree — match flagged for review.')],
            'no-change' => ['type' => 'info', 'message' => __("You've already chosen that outcome.")],
            default => ['type' => 'success', 'message' => __('Confirmation recorded.')],
        });

        return back();
    }
}
