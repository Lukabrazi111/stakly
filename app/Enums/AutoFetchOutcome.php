<?php

namespace App\Enums;

/**
 * Outcome of one auto-fetch attempt, recorded on `match_auto_fetch_attempts`
 * for the audit trail.
 *
 * - NoMatch on chess.com triggers the retry chain (one row per attempt);
 *   after exhaustion the match stays Pending until next dispatch trigger.
 * - Ambiguous is a silent skip — wrong game evidence is worse than no evidence.
 * - AcIncomplete (M15 P4) — FACEIT-specific terminal outcome: the match was
 *   found via API but FACEIT AC wasn't required on every roster slot. No
 *   card posted, no retry, match falls to ManualReview via timeout.
 * - Skipped means the attempt never reached the provider; `outcome_reason`
 *   carries the why (`not_pending`, `snapshot_missing`, `already_posted`).
 */
enum AutoFetchOutcome: string
{
    case Matched = 'matched';
    case NoMatch = 'no_match';
    case Ambiguous = 'ambiguous';
    case AcIncomplete = 'ac_incomplete';
    case Error = 'error';
    case Skipped = 'skipped';

    /**
     * Human label for admin surfaces (Filament infolist timeline +
     * PipelineHealth rollup). Single source of truth so a new case can't
     * render as a raw snake_case value.
     */
    public function label(): string
    {
        return match ($this) {
            self::Matched => 'Matched',
            self::NoMatch => 'No match',
            self::Ambiguous => 'Ambiguous',
            self::AcIncomplete => 'AC incomplete',
            self::Error => 'Error',
            self::Skipped => 'Skipped',
        };
    }

    /**
     * `[foreground, background]` hex pair for the inline-styled badge rendered
     * in `GameMatchInfolist` (raw HTML, can't compose Filament's Badge). Kept
     * on the enum so adding a 7th case is a compile-time obligation here rather
     * than a runtime `UnhandledMatchError` (a 500) on the dispute page.
     *
     * @return array{0: string, 1: string}
     */
    public function badgeColors(): array
    {
        return match ($this) {
            self::Matched => ['#16a34a', '#dcfce7'],
            self::NoMatch => ['#6b7280', '#f3f4f6'],
            self::Ambiguous => ['#d97706', '#fef3c7'],
            self::AcIncomplete => ['#ea580c', '#ffedd5'],
            self::Error => ['#dc2626', '#fee2e2'],
            self::Skipped => ['#6b7280', '#f3f4f6'],
        };
    }
}
