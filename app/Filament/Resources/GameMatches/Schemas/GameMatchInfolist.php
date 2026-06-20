<?php

namespace App\Filament\Resources\GameMatches\Schemas;

use App\Enums\AutoFetchOutcome;
use App\Enums\MatchStatus;
use App\Filament\Infolists\Components\ChatHistoryEntry;
use App\Models\GameMatch;
use App\Models\MatchAutoFetchAttempt;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Admin match detail view. Sections need `columnSpanFull()` because
 * Filament's default panel grid is 3-column — without it, sections collide
 * on the same row and inner fields wrap awkwardly.
 */
class GameMatchInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                self::statusSection()->columnSpanFull(),
                self::moneySection()->columnSpanFull(),
                self::timelineSection()->columnSpanFull(),
                Grid::make(2)
                    ->columnSpanFull()
                    ->schema([
                        self::creatorSection(),
                        self::takerSection(),
                    ]),
                self::chatSection()->columnSpanFull(),
                self::autoFetchHistorySection()->columnSpanFull(),
                self::resolutionHistorySection()->columnSpanFull(),
            ]);
    }

    private static function statusSection(): Section
    {
        return Section::make('Status & game')
            ->icon(Heroicon::InformationCircle)
            ->schema([
                TextEntry::make('id')
                    ->label('Match #'),

                TextEntry::make('status')
                    ->badge()
                    ->color(fn (MatchStatus $state): string => match ($state) {
                        MatchStatus::LobbyFilling => 'gray',
                        MatchStatus::Pending => 'gray',
                        MatchStatus::Disputed => 'warning',
                        MatchStatus::ManualReview => 'danger',
                        MatchStatus::Settled => 'success',
                        MatchStatus::Cancelled => 'gray',
                    })
                    ->formatStateUsing(fn (MatchStatus $state): string => match ($state) {
                        MatchStatus::LobbyFilling => 'Lobby Filling',
                        MatchStatus::Pending => 'Pending',
                        MatchStatus::Disputed => 'Disputed',
                        MatchStatus::ManualReview => 'Manual Review',
                        MatchStatus::Settled => 'Settled',
                        MatchStatus::Cancelled => 'Cancelled',
                    }),

                TextEntry::make('listing.game')
                    ->label('Game')
                    ->formatStateUsing(fn ($state) => $state?->value ?? '—'),

                TextEntry::make('listing.platform')
                    ->label('Platform')
                    ->formatStateUsing(fn ($state) => $state?->value ?? '—'),

                TextEntry::make('winner.username')
                    ->label('Winner')
                    ->placeholder('—')
                    ->icon(fn (GameMatch $record) => $record->winner_user_id ? Heroicon::Trophy : null)
                    ->iconColor('warning'),
            ])
            ->columns(5);
    }

    private static function moneySection(): Section
    {
        $feeRate = (float) config('stakly.platform_fee_rate');

        return Section::make('Money breakdown')
            ->icon(Heroicon::Banknotes)
            ->schema([
                TextEntry::make('listing.stake_amount')
                    ->label('Stake (each)')
                    ->formatStateUsing(fn ($state) => '$'.number_format((float) $state, 2)),

                TextEntry::make('listing.stake_amount')
                    ->label('Pot total')
                    ->formatStateUsing(fn ($state) => '$'.number_format((float) $state * 2, 2)),

                TextEntry::make('listing.stake_amount')
                    ->label('Fee ('.($feeRate * 100).'%)')
                    ->formatStateUsing(fn ($state) => '$'.number_format((float) $state * 2 * $feeRate, 2)),

                TextEntry::make('listing.stake_amount')
                    ->label('Winner payout')
                    ->formatStateUsing(fn ($state) => '$'.number_format((float) $state * 2 * (1 - $feeRate), 2)),
            ])
            ->columns(4);
    }

    private static function timelineSection(): Section
    {
        return Section::make('Timeline')
            ->icon(Heroicon::Clock)
            ->schema([
                TextEntry::make('created_at')
                    ->label('Match created')
                    ->dateTime('M j, Y H:i')
                    ->since(),

                TextEntry::make('dispute_opened_at')
                    ->label('Dispute opened')
                    ->dateTime('M j, Y H:i')
                    ->since()
                    ->badge()
                    ->color(function ($state, GameMatch $record): string {
                        // Skip urgency color on terminal matches.
                        if (in_array($record->status, [MatchStatus::Settled, MatchStatus::Cancelled], true)) {
                            return 'gray';
                        }

                        if ($state === null) {
                            return 'gray';
                        }

                        $hours = abs(now()->diffInHours($state));

                        return match (true) {
                            $hours >= 6 => 'danger',
                            $hours >= 1 => 'warning',
                            default => 'success',
                        };
                    })
                    ->placeholder('—'),

                TextEntry::make('disputeOpener.name')
                    ->label('Opened by')
                    ->placeholder('—'),

                TextEntry::make('settled_at')
                    ->label('Settled at')
                    ->dateTime('M j, Y H:i')
                    ->since()
                    ->placeholder('—'),
            ])
            ->columns(4);
    }

    private static function creatorSection(): Section
    {
        return Section::make('Creator')
            ->icon(fn (GameMatch $record): ?Heroicon => self::isWinner($record, $record->listing?->user_id)
                ? Heroicon::Trophy
                : null,
            )
            ->iconColor('warning')
            ->schema(self::playerEntries('listing.user'));
    }

    private static function takerSection(): Section
    {
        return Section::make('Taker')
            ->icon(fn (GameMatch $record): ?Heroicon => self::isWinner($record, $record->taker_user_id)
                ? Heroicon::Trophy
                : null,
            )
            ->iconColor('warning')
            ->schema(self::playerEntries('taker'));
    }

    /**
     * @return array<int, TextEntry>
     */
    private static function playerEntries(string $relationPath): array
    {
        return [
            TextEntry::make("{$relationPath}.name")
                ->label('Name'),

            TextEntry::make("{$relationPath}.username")
                ->label('Username')
                ->copyable(),

            TextEntry::make("{$relationPath}.email")
                ->label('Email')
                ->copyable(),

            TextEntry::make("{$relationPath}.lichess_username")
                ->label('Lichess')
                ->copyable()
                ->hidden(fn ($state) => blank($state)),

            TextEntry::make("{$relationPath}.chess_com_username")
                ->label('chess.com')
                ->copyable()
                ->hidden(fn ($state) => blank($state)),
        ];
    }

    private static function chatSection(): Section
    {
        return Section::make('Chat history')
            ->icon(Heroicon::ChatBubbleLeftRight)
            ->schema([
                ChatHistoryEntry::make('messages')
                    ->hiddenLabel(),
            ]);
    }

    private static function resolutionHistorySection(): Section
    {
        return Section::make('Admin resolution history')
            ->icon(Heroicon::ClipboardDocumentList)
            ->schema([
                TextEntry::make('admin_resolutions_summary')
                    ->hiddenLabel()
                    ->state(fn (GameMatch $record) => self::resolutionSummary($record))
                    ->html(),
            ])
            ->visible(fn (GameMatch $record) => $record->adminResolutions()->exists());
    }

    /**
     * Per-match auto-fetch audit. Lets admins answer "why is this match in
     * ManualReview?" by reading the full attempt history.
     */
    private static function autoFetchHistorySection(): Section
    {
        return Section::make('Auto-fetch history')
            ->icon(Heroicon::ArrowPath)
            ->schema([
                TextEntry::make('auto_fetch_summary')
                    ->hiddenLabel()
                    ->state(fn (GameMatch $record) => self::autoFetchSummary($record))
                    ->html(),
            ])
            ->visible(fn (GameMatch $record) => $record->autoFetchAttempts()->exists());
    }

    private static function isWinner(GameMatch $record, ?int $playerId): bool
    {
        return $record->status === MatchStatus::Settled
            && $playerId !== null
            && $record->winner_user_id === $playerId;
    }

    private static function resolutionSummary(GameMatch $record): string
    {
        $rows = $record->adminResolutions()->with('admin', 'winner')->get();

        if ($rows->isEmpty()) {
            return '<em>No admin resolutions yet.</em>';
        }

        $html = '<div class="space-y-2">';
        foreach ($rows as $row) {
            $when = $row->created_at->format('M j, Y H:i');
            $admin = e($row->admin->name);
            $action = e($row->action->label());
            $winner = $row->winner ? ' → '.e($row->winner->username) : '';
            $reason = e($row->reason);
            $html .= "<div><strong>{$when}</strong> · {$admin} · {$action}{$winner}<br><span class=\"text-sm opacity-75\">{$reason}</span></div>";
        }
        $html .= '</div>';

        return $html;
    }

    private static function autoFetchSummary(GameMatch $record): string
    {
        $rows = $record->autoFetchAttempts()->get();

        if ($rows->isEmpty()) {
            return '<em>No auto-fetch attempts yet.</em>';
        }

        $html = '<div class="space-y-2">';
        foreach ($rows as $row) {
            $html .= self::autoFetchRowHtml($row);
        }
        $html .= '</div>';

        return $html;
    }

    private static function autoFetchRowHtml(MatchAutoFetchAttempt $row): string
    {
        $when = $row->created_at->format('M j, Y H:i:s');
        $provider = e($row->provider->value);
        $badge = self::outcomeBadge($row->outcome);
        $detail = self::outcomeDetail($row);
        $attempt = $row->attempt_number > 1 ? " · attempt #{$row->attempt_number}" : '';

        return "<div><strong>{$when}</strong> · {$provider} · {$badge}{$attempt}<br>"
            ."<span class=\"text-sm opacity-75\">{$detail}</span></div>";
    }

    /**
     * Inline-styled span — we're rendering raw HTML inside a TextEntry so we
     * can't compose Filament's Badge component. Colors match Filament's badge
     * palette so the timeline reads consistently with the status section.
     */
    private static function outcomeBadge(AutoFetchOutcome $outcome): string
    {
        $palette = match ($outcome) {
            AutoFetchOutcome::Matched => ['#16a34a', '#dcfce7'],
            AutoFetchOutcome::NoMatch => ['#6b7280', '#f3f4f6'],
            AutoFetchOutcome::Ambiguous => ['#d97706', '#fef3c7'],
            AutoFetchOutcome::Error => ['#dc2626', '#fee2e2'],
            AutoFetchOutcome::Skipped => ['#6b7280', '#f3f4f6'],
        };
        [$fg, $bg] = $palette;
        $label = e($outcome->value);

        return "<span style=\"display:inline-block;padding:2px 8px;border-radius:9999px;font-size:0.75rem;font-weight:600;color:{$fg};background:{$bg};\">{$label}</span>";
    }

    private static function outcomeDetail(MatchAutoFetchAttempt $row): string
    {
        return match ($row->outcome) {
            AutoFetchOutcome::Matched => 'Winner: '.e($row->winner_username ?? '—')
                .' · '.self::latencyLabel($row->latency_ms),
            AutoFetchOutcome::NoMatch => '0 candidates'
                .($row->outcome_reason ? ' · '.e($row->outcome_reason) : '')
                .' · '.self::latencyLabel($row->latency_ms),
            AutoFetchOutcome::Ambiguous => ($row->candidates_count ?? 0).' candidates'
                .($row->outcome_reason ? ' · '.e($row->outcome_reason) : '')
                .' · '.self::latencyLabel($row->latency_ms),
            AutoFetchOutcome::Error => e($row->error_message ?? 'Unknown error')
                .' · '.self::latencyLabel($row->latency_ms),
            AutoFetchOutcome::Skipped => 'Reason: '.e($row->outcome_reason ?? 'unspecified'),
        };
    }

    private static function latencyLabel(?int $ms): string
    {
        return $ms === null ? 'no provider call' : "{$ms}ms";
    }
}
