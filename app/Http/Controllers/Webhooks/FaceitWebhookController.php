<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\LinkedAccountProvider;
use App\Enums\MatchStatus;
use App\Http\Controllers\Controller;
use App\Jobs\AutoFetchFaceitGameJob;
use App\Models\GameMatch;
use App\Models\MatchProviderSnapshot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Receives FACEIT webhook deliveries (M15 P4 Slice 4).
 *
 * Always responds 200 OK on authenticated requests — we treat the webhook
 * as a "hurry up and check now" signal, never as the source of outcome
 * truth. Actual verification + settlement happens inside the dispatched
 * `AutoFetchFaceitGameJob`, which calls the FACEIT Data API and runs the
 * existing AC + opposing-roster + winner-resolution checks.
 *
 * Why we don't narrow on event type or FACEIT match_id at this layer:
 *   - FACEIT's exact event envelope schema isn't publicly confirmed (Phase
 *     0 community research only). Hardcoding to a guessed field name would
 *     make us brittle to envelope changes. The receiver instead extracts
 *     every player guid it can find from the payload and uses it to locate
 *     candidate Pending Stakly matches via the (provider, provider_user_id)
 *     index on `match_provider_snapshots`.
 *   - The dispatched job's strict checks filter false positives — a
 *     dispatch for the wrong match no-ops via `AcIncomplete` / `NoMatch` /
 *     `Ambiguous` audit rows.
 *   - Duplicate webhooks are no-ops: the job is `ShouldBeUnique` keyed on
 *     Stakly match_id, and `alreadyPosted()` short-circuits when a card is
 *     already on the chat.
 *
 * Polling pipeline (Slices 1–3) remains the safety net — even with this
 * receiver disabled, all Pending matches still settle via cron.
 */
class FaceitWebhookController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $playerGuids = $this->extractPlayerGuids($request->json()->all());

        if ($playerGuids === []) {
            Log::info('FACEIT webhook received but no player guids extracted', [
                'ip' => $request->ip(),
            ]);

            return response('No actionable payload.', Response::HTTP_OK);
        }

        $candidateMatchIds = MatchProviderSnapshot::query()
            ->where('provider', LinkedAccountProvider::Faceit)
            ->whereIn('provider_user_id', $playerGuids)
            ->whereHas('match', fn ($q) => $q->where('status', MatchStatus::Pending))
            ->pluck('match_id')
            ->unique()
            ->values();

        if ($candidateMatchIds->isEmpty()) {
            Log::info('FACEIT webhook received but no Pending Stakly matches involve these players', [
                'player_guids_count' => count($playerGuids),
            ]);

            return response('No candidate matches.', Response::HTTP_OK);
        }

        $matches = GameMatch::query()->findMany($candidateMatchIds);
        foreach ($matches as $match) {
            AutoFetchFaceitGameJob::dispatch($match);
        }

        Log::info('FACEIT webhook dispatched auto-fetch jobs', [
            'match_ids' => $candidateMatchIds->all(),
        ]);

        return response('Dispatched '.$matches->count().' job(s).', Response::HTTP_OK);
    }

    /**
     * Pull every plausibly-FACEIT player guid out of the webhook payload.
     * Defensive against unknown envelope shapes — Phase 0 only has a
     * community-docs sketch. Looks in both the root and a `payload`
     * sub-key, then scans any `roster` arrays for `player_id` strings.
     *
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function extractPlayerGuids(array $payload): array
    {
        $guids = [];

        foreach ([$payload, $payload['payload'] ?? null] as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $teams = $candidate['teams'] ?? null;
            if (! is_array($teams)) {
                continue;
            }

            foreach ($teams as $team) {
                $roster = is_array($team) ? ($team['roster'] ?? null) : null;
                if (! is_array($roster)) {
                    continue;
                }

                foreach ($roster as $player) {
                    $guid = is_array($player) ? ($player['player_id'] ?? null) : null;
                    if (is_string($guid) && $guid !== '') {
                        $guids[] = $guid;
                    }
                }
            }
        }

        return array_values(array_unique($guids));
    }
}
