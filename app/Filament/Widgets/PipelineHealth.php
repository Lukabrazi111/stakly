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
        return [
            $this->settlementsStat(),
            $this->errorsStat(),
            $this->avgLatencyStat(),
            $this->volumeStat(),
        ];
    }

    // ─── Settlements via auto-fetch (24h) ──────────────────────────────────

    private function settlementsStat(): Stat
    {
        $today = $this->countMatchedOn(CarbonImmutable::today());
        $last7 = $this->lastSevenDaysCounts(AutoFetchOutcome::Matched);

        return Stat::make('Auto-settlements (24h)', (string) $today)
            ->description($today > 0 ? 'API resolved matches today' : 'No auto-settlements today')
            ->descriptionIcon($today > 0 ? 'heroicon-m-check-circle' : 'heroicon-m-minus-circle')
            ->chart($last7)
            ->color($today > 0 ? 'success' : 'gray');
    }

    // ─── Errors (24h) ──────────────────────────────────────────────────────

    private function errorsStat(): Stat
    {
        $today = $this->countOutcomeOn(AutoFetchOutcome::Error, CarbonImmutable::today());
        $last7 = $this->lastSevenDaysCounts(AutoFetchOutcome::Error);

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

        if ($today >= self::DANGER_ERROR_THRESHOLD) {
            return ["{$today} provider failures — investigate", 'danger'];
        }

        return ["{$today} provider failures today", 'warning'];
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

        // matched first (success signal), errors next (attention).
        $order = [
            AutoFetchOutcome::Matched->value,
            AutoFetchOutcome::Error->value,
            AutoFetchOutcome::Ambiguous->value,
            AutoFetchOutcome::NoMatch->value,
            AutoFetchOutcome::Skipped->value,
        ];

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
     * @return array<int, int>
     */
    private function lastSevenDaysCounts(AutoFetchOutcome $outcome): array
    {
        $counts = [];
        for ($i = 6; $i >= 0; $i--) {
            $counts[] = $this->countOutcomeOn($outcome, CarbonImmutable::today()->subDays($i));
        }

        return $counts;
    }

    /**
     * `skipped` rows excluded — they never called the provider, so their
     * NULL latency would just be noise in the avg.
     */
    private function avgLatencyBetween(CarbonImmutable $start, CarbonImmutable $end): ?int
    {
        $providerCallOutcomes = [
            AutoFetchOutcome::Matched->value,
            AutoFetchOutcome::NoMatch->value,
            AutoFetchOutcome::Ambiguous->value,
            AutoFetchOutcome::Error->value,
        ];

        $avg = MatchAutoFetchAttempt::query()
            ->whereIn('outcome', $providerCallOutcomes)
            ->whereNotNull('latency_ms')
            ->whereBetween('created_at', [$start, $end])
            ->avg('latency_ms');

        return $avg === null ? null : (int) round((float) $avg);
    }
}
