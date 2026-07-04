<?php

namespace App\Filament\Widgets;

use App\Enums\MatchStatus;
use App\Enums\WalletTransactionType;
use App\Filament\Resources\GameMatches\GameMatchResource;
use App\Models\GameMatch;
use App\Models\Listing;
use App\Models\LobbyParticipant;
use App\Models\Message;
use App\Models\WalletTransaction;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

/**
 * Bundled Stats Overview widget. Combined into one widget (not 4 separate)
 * so Filament's responsive grid lays them out horizontally — separate
 * widgets force `columnSpan = 'full'` and stack vertically.
 */
class OpsOverview extends StatsOverviewWidget
{
    protected ?string $pollingInterval = '30s';

    protected function getColumns(): int
    {
        return 2;
    }

    protected function getStats(): array
    {
        return [
            $this->openDisputesStat(),
            $this->agingDisputesStat(),
            $this->matchesTodayStat(),
            $this->platformEarningsStat(),
            $this->activeUsersStat(),
        ];
    }

    // ─── Open disputes ─────────────────────────────────────────────────────

    private function openDisputesStat(): Stat
    {
        $disputeStatuses = [MatchStatus::Disputed, MatchStatus::ManualReview];

        $count = GameMatch::query()
            ->whereIn('status', $disputeStatuses)
            ->count();

        $oldest = GameMatch::query()
            ->whereIn('status', $disputeStatuses)
            ->oldest('dispute_opened_at')
            ->value('dispute_opened_at');

        [$description, $color] = $this->openDisputesDescription($count, $oldest);

        return Stat::make('Open disputes', (string) $count)
            ->description($description)
            ->descriptionIcon('heroicon-m-clock')
            ->color($color)
            ->url(GameMatchResource::getUrl('index'));
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function openDisputesDescription(int $count, $oldest): array
    {
        if ($count === 0) {
            return ['Queue clear', 'gray'];
        }

        if ($oldest === null) {
            return ["{$count} awaiting review", 'warning'];
        }

        $hours = abs(now()->diffInHours($oldest));

        $color = match (true) {
            $hours >= 6 => 'danger',
            $hours >= 1 => 'warning',
            default => 'success',
        };

        return ["Oldest: {$oldest->diffForHumans(syntax: 1)}", $color];
    }

    // ─── Aging disputes (M27 P4 SLA surface) ───────────────────────────────

    private function agingDisputesStat(): Stat
    {
        $statuses = [MatchStatus::Disputed, MatchStatus::ManualReview];

        // Aging timestamp: `dispute_opened_at` for genuine disputes (and
        // ManualReview from dispute-Unknown). ManualReview from timeout has
        // no dispute event so we fall back to `updated_at` (when status
        // flipped to MR). Matches the source-of-truth used by the table
        // column color in GameMatchesTable.
        $count6h = $this->countAgingDisputesSince($statuses, now()->subHours(6));
        $count12h = $this->countAgingDisputesSince($statuses, now()->subHours(12));

        [$description, $color] = $this->agingDisputesDescription($count6h, $count12h);

        return Stat::make('Aging disputes (≥6h)', (string) $count6h)
            ->description($description)
            ->descriptionIcon('heroicon-m-exclamation-triangle')
            ->color($color)
            ->url(GameMatchResource::getUrl('index'));
    }

    /**
     * @param  array<int, MatchStatus>  $statuses
     */
    private function countAgingDisputesSince(array $statuses, CarbonImmutable $threshold): int
    {
        return GameMatch::query()
            ->whereIn('status', $statuses)
            ->whereRaw('COALESCE(dispute_opened_at, updated_at) <= ?', [$threshold])
            ->count();
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function agingDisputesDescription(int $count6h, int $count12h): array
    {
        if ($count6h === 0) {
            return ['No aging disputes', 'success'];
        }

        if ($count12h > 0) {
            return ["{$count12h} over 12h", 'danger'];
        }

        return ["{$count6h} between 6h–12h", 'warning'];
    }

    // ─── Matches today ─────────────────────────────────────────────────────

    private function matchesTodayStat(): Stat
    {
        $series = $this->lastSevenDaysCounts();

        $today = $series[6];
        $yesterday = $series[5];

        return Stat::make('Matches today', (string) $today)
            ->description($this->matchesTrendDescription($today, $yesterday))
            ->descriptionIcon($this->trendIcon($today, $yesterday))
            ->chart($series)
            ->color($this->trendColor($today, $yesterday));
    }

    /**
     * Daily match counts for the last 7 days (index 0 = 6 days ago … index 6
     * = today). One range GROUP BY zero-filled into buckets instead of a query
     * per day. Excludes team matches that exist pre-fill (LobbyFilling) or were
     * never filled (Cancelled) so they don't inflate the count or chart.
     *
     * @return array<int, int>
     */
    private function lastSevenDaysCounts(): array
    {
        $start = CarbonImmutable::today()->subDays(6);
        $end = CarbonImmutable::today()->endOfDay();

        $countsByDay = GameMatch::query()
            ->whereNotIn('status', [MatchStatus::LobbyFilling, MatchStatus::Cancelled])
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('created_at::date as day, COUNT(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $counts = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = CarbonImmutable::today()->subDays($i)->toDateString();
            $counts[] = (int) ($countsByDay[$day] ?? 0);
        }

        return $counts;
    }

    private function matchesTrendDescription(int $today, int $yesterday): string
    {
        if ($yesterday === 0 && $today === 0) {
            return 'No matches yet';
        }

        $delta = $today - $yesterday;
        $sign = $delta >= 0 ? '+' : '';

        return "{$sign}{$delta} vs yesterday";
    }

    // ─── Platform earnings (this month) ────────────────────────────────────

    private function platformEarningsStat(): Stat
    {
        $thisMonth = $this->sumFeesIn(
            CarbonImmutable::now()->startOfMonth(),
            CarbonImmutable::now(),
        );

        $lastMonth = $this->sumFeesIn(
            CarbonImmutable::now()->subMonthNoOverflow()->startOfMonth(),
            CarbonImmutable::now()->subMonthNoOverflow()->endOfMonth(),
        );

        return Stat::make('Earnings this month', '$'.number_format((float) $thisMonth, 2))
            ->description($this->monthOverMonthDescription($thisMonth, $lastMonth))
            ->descriptionIcon($this->bcTrendIcon($thisMonth, $lastMonth))
            ->color($this->bcTrendColor($thisMonth, $lastMonth));
    }

    private function sumFeesIn(CarbonImmutable $start, CarbonImmutable $end): string
    {
        $sum = WalletTransaction::query()
            ->where('type', WalletTransactionType::Fee)
            ->whereBetween('created_at', [$start, $end])
            ->sum('amount');

        return (string) ($sum ?? '0');
    }

    private function monthOverMonthDescription(string $thisMonth, string $lastMonth): string
    {
        if (bccomp($lastMonth, '0', 2) === 0 && bccomp($thisMonth, '0', 2) === 0) {
            return 'No fees collected yet';
        }

        $delta = bcsub($thisMonth, $lastMonth, 2);
        $sign = bccomp($delta, '0', 2) >= 0 ? '+' : '';

        return $sign.'$'.number_format((float) $delta, 2).' vs last month';
    }

    // ─── Active users (7d) ─────────────────────────────────────────────────

    private function activeUsersStat(): Stat
    {
        $thisWeek = $this->countActiveBetween(
            CarbonImmutable::now()->subDays(7),
            CarbonImmutable::now(),
        );

        $priorWeek = $this->countActiveBetween(
            CarbonImmutable::now()->subDays(14),
            CarbonImmutable::now()->subDays(7),
        );

        return Stat::make('Active users (7d)', (string) $thisWeek)
            ->description($this->activeUsersTrendDescription($thisWeek, $priorWeek))
            ->descriptionIcon($this->trendIcon($thisWeek, $priorWeek))
            ->color($this->trendColor($thisWeek, $priorWeek));
    }

    private function countActiveBetween(CarbonImmutable $start, CarbonImmutable $end): int
    {
        $listings = Listing::query()
            ->select('user_id')
            ->whereBetween('created_at', [$start, $end]);

        $matches = GameMatch::query()
            ->select('taker_user_id as user_id')
            ->whereBetween('created_at', [$start, $end]);

        $messages = Message::query()
            ->select('user_id')
            ->whereNotNull('user_id')
            ->whereBetween('created_at', [$start, $end]);

        // Team-match lobby joiners: for team matches `taker_user_id` is the
        // creator, so the real roster lives in lobby_participants. scopeLive
        // excludes kicked rows (kicked_at IS NULL).
        $lobbyParticipants = LobbyParticipant::query()
            ->select('user_id')
            ->live()
            ->whereBetween('joined_at', [$start, $end]);

        return DB::query()
            ->fromSub(
                $listings->union($matches)->union($messages)->union($lobbyParticipants),
                'activity',
            )
            ->join('users', 'users.id', '=', 'activity.user_id')
            ->where('users.is_platform', false)
            ->distinct()
            ->count('activity.user_id');
    }

    private function activeUsersTrendDescription(int $thisWeek, int $priorWeek): string
    {
        if ($thisWeek === 0 && $priorWeek === 0) {
            return 'No activity yet';
        }

        $delta = $thisWeek - $priorWeek;
        $sign = $delta >= 0 ? '+' : '';

        return "{$sign}{$delta} vs prior 7d";
    }

    // ─── Shared trend helpers ──────────────────────────────────────────────

    private function trendIcon(int $current, int $previous): string
    {
        return $current >= $previous
            ? 'heroicon-m-arrow-trending-up'
            : 'heroicon-m-arrow-trending-down';
    }

    private function trendColor(int $current, int $previous): string
    {
        if ($current === $previous) {
            return 'gray';
        }

        return $current > $previous ? 'success' : 'warning';
    }

    private function bcTrendIcon(string $current, string $previous): string
    {
        return bccomp($current, $previous, 2) >= 0
            ? 'heroicon-m-arrow-trending-up'
            : 'heroicon-m-arrow-trending-down';
    }

    private function bcTrendColor(string $current, string $previous): string
    {
        return match (bccomp($current, $previous, 2)) {
            0 => 'gray',
            1 => 'success',
            default => 'warning',
        };
    }
}
