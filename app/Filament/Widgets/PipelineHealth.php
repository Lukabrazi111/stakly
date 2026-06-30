<?php

namespace App\Filament\Widgets;

use App\Enums\AutoFetchOutcome;
use App\Models\MatchAutoFetchAttempt;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Health snapshot of the auto-fetch settlement pipeline.
 */
class PipelineHealth extends StatsOverviewWidget
{
    protected ?string $pollingInterval = '30s';

    /**
     * Daily error count at which the stat flips to `danger`. Tunable as we
     * learn what "normal" volume looks like at launch.
     */
    private const DANGER_ERROR_THRESHOLD = 10;

    protected function getColumns(): int
    {
        return 2;
    }

    protected function getStats(): array
    {
        $sparklines = $this->lastSevenDaysSparklines();

        return [
            $this->settlementsStat($sparklines[AutoFetchOutcome::Matched->value]),
            $this->errorsStat($sparklines[AutoFetchOutcome::Error->value]),
            $this->avgLatencyStat(),
            $this->volumeStat(),
        ];
    }

    // ─── Settlements via auto-fetch (24h) ──────────────────────────────────

    /**
     * @param  array<int, int>  $last7
     */
    private function settlementsStat(array $last7): Stat
    {
        $today = $this->countMatchedOn(CarbonImmutable::today());

        return Stat::make('Auto-settlements (24h)', (string) $today)
            ->description($this->settlementsDescription($today))
            ->descriptionIcon($today > 0 ? 'heroicon-m-check-circle' : 'heroicon-m-minus-circle')
            ->chart($last7)
            ->color($today > 0 ? 'success' : 'gray');
    }

    private function settlementsDescription(int $today): string
    {
        if ($today === 0) {
            return 'No auto-settlements today';
        }

        $breakdown = $this->providerBreakdown(AutoFetchOutcome::Matched, CarbonImmutable::today());

        return $breakdown === ''
            ? 'API resolved matches today'
            : "API resolved · {$breakdown}";
    }

    // ─── Errors (24h) ──────────────────────────────────────────────────────

    /**
     * @param  array<int, int>  $last7
     */
    private function errorsStat(array $last7): Stat
    {
        $today = $this->countOutcomeOn(AutoFetchOutcome::Error, CarbonImmutable::today());

        [$description, $color] = $this->errorsDescription($today);

        return Stat::make('Pipeline errors (24h)', (string) $today)
            ->description($description)
            ->descriptionIcon('heroicon-m-exclamation-triangle')
            ->chart($last7)
            ->color($color);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function errorsDescription(int $today): array
    {
        if ($today === 0) {
            return ['No provider failures today', 'success'];
        }

        $breakdown = $this->providerBreakdown(AutoFetchOutcome::Error, CarbonImmutable::today());
        $suffix = $breakdown === '' ? '' : " · {$breakdown}";

        if ($today >= self::DANGER_ERROR_THRESHOLD) {
            return ["{$today} provider failures — investigate{$suffix}", 'danger'];
        }

        return ["{$today} provider failures today{$suffix}", 'warning'];
    }

    /**
     * Compact `chess_com 2 / lichess 1 / faceit 1` rollup for a given outcome
     * on a given day. M15 P5 Item 2 — lets admin tell at a glance which
     * provider is generating today's settlements / errors instead of just
     * seeing aggregate totals. Empty when no rows match (caller appends
     * a generic suffix instead).
     */
    private function providerBreakdown(AutoFetchOutcome $outcome, CarbonImmutable $day): string
    {
        $counts = MatchAutoFetchAttempt::query()
            ->where('outcome', $outcome)
            ->whereDate('created_at', $day)
            ->selectRaw('provider, COUNT(*) as total')
            ->groupBy('provider')
            ->pluck('total', 'provider')
            ->toArray();

        if ($counts === []) {
            return '';
        }

        // Stable order so the rollup reads the same day-to-day. New
        // providers append at the end via `array_diff` against the
        // explicit head.
        $order = ['chess_com', 'lichess', 'faceit'];
        $tail = array_diff(array_keys($counts), $order);
        $ordered = [...$order, ...$tail];

        $parts = [];
        foreach ($ordered as $provider) {
            $count = $counts[$provider] ?? 0;
            if ($count > 0) {
                $parts[] = "{$provider} {$count}";
            }
        }

        return implode(' / ', $parts);
    }

    // ─── Avg latency (7d) ──────────────────────────────────────────────────

    private function avgLatencyStat(): Stat
    {
        $thisWeek = $this->avgLatencyBetween(
            CarbonImmutable::now()->subDays(7),
            CarbonImmutable::now(),
        );

        $priorWeek = $this->avgLatencyBetween(
            CarbonImmutable::now()->subDays(14),
            CarbonImmutable::now()->subDays(7),
        );

        $label = $thisWeek === null ? '—' : "{$thisWeek}ms";

        return Stat::make('Avg provider latency (7d)', $label)
            ->description($this->latencyTrendDescription($thisWeek, $priorWeek))
            ->descriptionIcon($this->latencyTrendIcon($thisWeek, $priorWeek))
            ->color($this->latencyTrendColor($thisWeek, $priorWeek));
    }

    private function latencyTrendDescription(?int $thisWeek, ?int $priorWeek): string
    {
        if ($thisWeek === null) {
            return 'No provider calls in 7d window';
        }

        if ($priorWeek === null) {
            return 'First week of provider calls';
        }

        $delta = $thisWeek - $priorWeek;
        $sign = $delta >= 0 ? '+' : '';

        return "{$sign}{$delta}ms vs prior 7d";
    }

    private function latencyTrendIcon(?int $thisWeek, ?int $priorWeek): string
    {
        if ($thisWeek === null || $priorWeek === null) {
            return 'heroicon-m-clock';
        }

        return $thisWeek <= $priorWeek
            ? 'heroicon-m-arrow-trending-down'
            : 'heroicon-m-arrow-trending-up';
    }

    private function latencyTrendColor(?int $thisWeek, ?int $priorWeek): string
    {
        if ($thisWeek === null || $priorWeek === null) {
            return 'gray';
        }

        if ($thisWeek === $priorWeek) {
            return 'gray';
        }

        // Lower latency = healthier — inverted vs count-based trend colors.
        return $thisWeek < $priorWeek ? 'success' : 'warning';
    }

    // ─── Volume (24h) ──────────────────────────────────────────────────────

    private function volumeStat(): Stat
    {
        $today = CarbonImmutable::today();
        $endOfDay = CarbonImmutable::today()->endOfDay();

        $totals = MatchAutoFetchAttempt::query()
            ->whereBetween('created_at', [$today, $endOfDay])
            ->selectRaw('outcome, COUNT(*) as total')
            ->groupBy('outcome')
            ->pluck('total', 'outcome')
            ->toArray();

        $total = array_sum($totals);
        $breakdown = $this->volumeBreakdown($totals);

        return Stat::make('Pipeline attempts (24h)', (string) $total)
            ->description($breakdown)
            ->descriptionIcon('heroicon-m-rectangle-stack')
            ->color($total > 0 ? 'success' : 'gray');
    }

    /**
     * @param  array<string, int>  $totals
     */
    private function volumeBreakdown(array $totals): string
    {
        if (array_sum($totals) === 0) {
            return 'No attempts today';
        }

        // Priority head reads success-first (matched) then attention (error);
        // every remaining case is appended straight from the enum so a newly
        // added outcome can never be silently dropped from the headline total.
        $priority = [
            AutoFetchOutcome::Matched->value,
            AutoFetchOutcome::Error->value,
            AutoFetchOutcome::Ambiguous->value,
            AutoFetchOutcome::NoMatch->value,
        ];

        $allOutcomes = array_map(
            static fn (AutoFetchOutcome $outcome): string => $outcome->value,
            AutoFetchOutcome::cases(),
        );

        $order = [...$priority, ...array_diff($allOutcomes, $priority)];

        $parts = [];
        foreach ($order as $key) {
            $count = $totals[$key] ?? 0;
            if ($count > 0) {
                $parts[] = "{$count} {$key}";
            }
        }

        return implode(' · ', $parts);
    }

    // ─── Shared query helpers ──────────────────────────────────────────────

    private function countMatchedOn(CarbonImmutable $day): int
    {
        return $this->countOutcomeOn(AutoFetchOutcome::Matched, $day);
    }

    private function countOutcomeOn(AutoFetchOutcome $outcome, CarbonImmutable $day): int
    {
        return MatchAutoFetchAttempt::query()
            ->where('outcome', $outcome)
            ->whereDate('created_at', $day)
            ->count();
    }

    /**
     * Both sparkline series (matched + error) over the last 7 days in ONE
     * range GROUP BY, pivoted in PHP into two day-ordered, zero-filled arrays.
     * Replaces the old per-day loop that fired 14 `COUNT` round-trips every
     * 30s poll and defeated the `(outcome, created_at)` index with `whereDate`.
     * Computed once in `getStats()` and handed to both stats.
     *
     * @return array<string, array<int, int>>
     */
    private function lastSevenDaysSparklines(): array
    {
        $start = CarbonImmutable::today()->subDays(6);
        $end = CarbonImmutable::today()->endOfDay();

        $rows = MatchAutoFetchAttempt::query()
            ->whereBetween('created_at', [$start, $end])
            ->whereIn('outcome', [AutoFetchOutcome::Matched->value, AutoFetchOutcome::Error->value])
            ->selectRaw('outcome, created_at::date as day, COUNT(*) as total')
            ->groupBy('outcome', 'day')
            ->get();

        $series = [
            AutoFetchOutcome::Matched->value => $this->zeroFilledByDay($start),
            AutoFetchOutcome::Error->value => $this->zeroFilledByDay($start),
        ];

        foreach ($rows as $row) {
            $outcome = $row->outcome->value;
            $day = CarbonImmutable::parse($row->day)->toDateString();

            if (isset($series[$outcome][$day])) {
                $series[$outcome][$day] = (int) $row->total;
            }
        }

        return [
            AutoFetchOutcome::Matched->value => array_values($series[AutoFetchOutcome::Matched->value]),
            AutoFetchOutcome::Error->value => array_values($series[AutoFetchOutcome::Error->value]),
        ];
    }

    /**
     * Seven consecutive days from `$start` (oldest first), each keyed by its
     * `Y-m-d` date string and zero-filled, ready for the GROUP BY pivot.
     *
     * @return array<string, int>
     */
    private function zeroFilledByDay(CarbonImmutable $start): array
    {
        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $days[$start->addDays($i)->toDateString()] = 0;
        }

        return $days;
    }

    /**
     * Averages latency across every provider-touching attempt in the window.
     * Excluding only `skipped` (which never reached the provider) — rather than
     * allow-listing specific outcomes — means any new provider-touching case,
     * e.g. `ac_incomplete`, is counted automatically. `whereNotNull` still
     * guards any stray NULL latency so it can't skew the average.
     */
    private function avgLatencyBetween(CarbonImmutable $start, CarbonImmutable $end): ?int
    {
        $avg = MatchAutoFetchAttempt::query()
            ->where('outcome', '!=', AutoFetchOutcome::Skipped->value)
            ->whereNotNull('latency_ms')
            ->whereBetween('created_at', [$start, $end])
            ->avg('latency_ms');

        return $avg === null ? null : (int) round((float) $avg);
    }
}
