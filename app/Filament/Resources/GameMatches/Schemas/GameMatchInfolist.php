<?php

namespace App\Filament\Resources\GameMatches\Schemas;

use App\Enums\MatchStatus;
use App\Filament\Infolists\Components\ChatHistoryEntry;
use App\Models\GameMatch;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * M12 Phase 2 — read-only match detail view used on `ViewGameMatch`. Five
 * sections from top to bottom: match metadata, creator profile, taker
 * profile, chat history (custom entry rendering a Blade partial — see
 * `ChatHistoryEntry`), and admin resolution history (only renders if
 * resolutions exist).
 *
 * Chat is intentionally inline rather than a separate tab — admins need to
 * scan it to make the resolution decision; tabbing adds a click for no
 * benefit at our scale.
 */
class GameMatchInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Match')
                    ->schema([
                        TextEntry::make('id')
                            ->label('Match #'),

                        TextEntry::make('status')
                            ->badge()
                            ->color(fn (MatchStatus $state): string => match ($state) {
                                MatchStatus::Pending => 'gray',
                                MatchStatus::Disputed => 'warning',
                                MatchStatus::ManualReview => 'danger',
                                MatchStatus::Settled => 'success',
                                MatchStatus::Cancelled => 'gray',
                            })
                            ->formatStateUsing(fn (MatchStatus $state): string => match ($state) {
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

                        TextEntry::make('listing.stake_amount')
                            ->label('Stake (each side)')
                            ->formatStateUsing(fn ($state) => '$'.number_format((float) $state, 2).' USDT'),

                        TextEntry::make('created_at')
                            ->label('Match created')
                            ->dateTime('M j, Y H:i')
                            ->since(),

                        TextEntry::make('dispute_opened_at')
                            ->label('Dispute opened')
                            ->dateTime('M j, Y H:i')
                            ->since()
                            ->placeholder('—'),

                        TextEntry::make('settled_at')
                            ->label('Settled at')
                            ->dateTime('M j, Y H:i')
                            ->since()
                            ->placeholder('—'),

                        TextEntry::make('winner.username')
                            ->label('Winner')
                            ->placeholder('Not yet settled / draw'),
                    ])
                    ->columns(3),

                Section::make('Creator')
                    ->schema([
                        TextEntry::make('listing.user.name')
                            ->label('Name'),
                        TextEntry::make('listing.user.username')
                            ->label('Username')
                            ->copyable(),
                        TextEntry::make('listing.user.email')
                            ->label('Email')
                            ->copyable(),
                        TextEntry::make('listing.user.lichess_username')
                            ->label('Lichess')
                            ->placeholder('— not linked —')
                            ->copyable(),
                        TextEntry::make('listing.user.chess_com_username')
                            ->label('chess.com')
                            ->placeholder('— not linked —')
                            ->copyable(),
                    ])
                    ->columns(3),

                Section::make('Taker')
                    ->schema([
                        TextEntry::make('taker.name')
                            ->label('Name'),
                        TextEntry::make('taker.username')
                            ->label('Username')
                            ->copyable(),
                        TextEntry::make('taker.email')
                            ->label('Email')
                            ->copyable(),
                        TextEntry::make('taker.lichess_username')
                            ->label('Lichess')
                            ->placeholder('— not linked —')
                            ->copyable(),
                        TextEntry::make('taker.chess_com_username')
                            ->label('chess.com')
                            ->placeholder('— not linked —')
                            ->copyable(),
                    ])
                    ->columns(3),

                Section::make('Chat history')
                    ->schema([
                        ChatHistoryEntry::make('messages'),
                    ])
                    ->collapsible(),

                Section::make('Admin resolution history')
                    ->schema([
                        TextEntry::make('admin_resolutions_summary')
                            ->hiddenLabel()
                            ->state(fn (GameMatch $record) => self::resolutionSummary($record))
                            ->html(),
                    ])
                    ->visible(fn (GameMatch $record) => $record->adminResolutions()->exists()),
            ]);
    }

    /**
     * Renders the audit rows for this match as a small HTML list. Inline
     * here rather than a separate custom entry because the markup is
     * trivial — one row per resolution with admin, action, and reason.
     */
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
}
