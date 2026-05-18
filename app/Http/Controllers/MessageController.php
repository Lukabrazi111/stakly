<?php

namespace App\Http\Controllers;

use App\Actions\Message\SendMessageAction;
use App\Http\Requests\Message\StoreMessageRequest;
use App\Models\GameMatch;
use Illuminate\Http\RedirectResponse;

/**
 * HTTP entry point for the match chat. Thin adapter — authorize the user
 * as a match participant (via `view` policy, same gate as the match
 * detail page), delegate to `SendMessageAction`, return `back()` so
 * Inertia keeps the user on the match page.
 *
 * The actual UI update on success comes via the Reverb broadcast — the
 * Echo subscriber on the match page appends the new message to local
 * state. Optimistic UI in Slice 3 hides the round-trip latency from the
 * sender themselves.
 *
 * `ThrottleRequestsException` (rate-limit) and `ValidationException`
 * (status gate, content validation) both bubble up — Laravel renders 429
 * / 422 with appropriate payloads.
 */
class MessageController extends Controller
{
    public function store(
        StoreMessageRequest $request,
        GameMatch $match,
        SendMessageAction $action,
    ): RedirectResponse {
        // 404 (not 403) — same convention as the match show page; we don't
        // leak match existence to non-participants who happened to guess an id.
        abort_if($request->user()->cannot('view', $match), 404);

        $action->handle($request->user(), $match, $request->validated('content'));

        return back();
    }
}
