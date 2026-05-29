<?php

namespace App\Models;

use App\Enums\AutoFetchOutcome;
use App\Enums\LinkedAccountProvider;
use Database\Factories\MatchAutoFetchAttemptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * M14 Phase 1 — append-only audit row for one auto-fetch attempt against
 * the game-API pipeline. Written by `RecordAutoFetchAttemptAction`, which
 * is the only authorised entry point (mirrors the Wallet/ledger pattern).
 *
 * Rows are written from:
 *   - `DispatchAutoFetchAction` — pre-flight skip outcomes (`not_pending`,
 *     `snapshot_missing`). The dispatcher knows the platform from the
 *     listing, so `provider` is always populated.
 *   - `AutoFetchLichessGameJob` / `AutoFetchChessComGameJob` — per-attempt
 *     outcomes after the provider call (matched / no_match / ambiguous /
 *     error) plus defensive skip rows (`already_posted`, `snapshot_missing`).
 *
 * No `updated_at` — append-only by convention + by the migration's column
 * shape. Setting `UPDATED_AT = null` keeps Eloquent from generating
 * `update`-statement timestamps if a row is ever touched in code.
 */
class MatchAutoFetchAttempt extends Model
{
    /** @use HasFactory<MatchAutoFetchAttemptFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'match_id',
        'provider',
        'outcome',
        'outcome_reason',
        'attempt_number',
        'winner_username',
        'candidates_count',
        'error_message',
        'latency_ms',
    ];

    protected function casts(): array
    {
        return [
            'provider' => LinkedAccountProvider::class,
            'outcome' => AutoFetchOutcome::class,
            'attempt_number' => 'integer',
            'candidates_count' => 'integer',
            'latency_ms' => 'integer',
        ];
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(GameMatch::class, 'match_id');
    }
}
