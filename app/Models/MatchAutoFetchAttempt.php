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
