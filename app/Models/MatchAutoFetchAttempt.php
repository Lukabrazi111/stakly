<?php

namespace App\Models;

use App\Enums\AutoFetchOutcome;
use App\Enums\LinkedAccountProvider;
use Database\Factories\MatchAutoFetchAttemptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit row for one auto-fetch attempt. Written by
 * `RecordAutoFetchAttemptAction` — the only authorised entry point (mirrors
 * the Wallet/ledger pattern). `UPDATED_AT = null` keeps Eloquent from
 * generating timestamps if a row is ever touched in code.
 */
class MatchAutoFetchAttempt extends Model
{
    /** @use HasFactory<MatchAutoFetchAttemptFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * Human labels for `outcome_reason` codes, for admin surfaces (M46 P5).
     * These are ADMIN-facing — they may describe detection internals (e.g. the
     * anti pre-play guard) and must NOT be surfaced to players, who get a
     * coarse timeout-vs-dispute explanation instead.
     *
     * @var array<string, string>
     */
    private const REASON_LABELS = [
        'retry_exhausted' => 'No game found after all retries',
        'stale_game_rejected' => 'Found a game, but it started before the stake',
        'time_control_mismatch' => 'Wrong time control',
        'permanent' => 'Provider returned a permanent error',
        'snapshot_missing' => "Player's provider account not snapshotted",
        'already_posted' => 'A result card was already posted',
        'not_pending' => 'Match was no longer pending',
        'circuit_open' => 'Provider temporarily unavailable',
        'ac_incomplete' => 'FACEIT anti-cheat not enforced on every player',
        'no_winner' => 'No decisive winner',
        'winner_roster_empty' => 'Winning roster not identifiable',
        'winner_unresolvable' => 'Winner not resolvable to a side',
    ];

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

    /**
     * Human-readable label for an `outcome_reason` code (M46 P5 — admin
     * surfaces). Unmapped codes fall back to a humanised form so a newly
     * added reason never renders as raw snake_case. Null / empty in → null out.
     */
    public static function reasonLabel(?string $reason): ?string
    {
        if ($reason === null || $reason === '') {
            return null;
        }

        return self::REASON_LABELS[$reason] ?? ucfirst(str_replace('_', ' ', $reason));
    }
}
