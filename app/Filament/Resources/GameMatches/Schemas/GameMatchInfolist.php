<?php

namespace App\Filament\Resources\GameMatches\Schemas;

use App\Enums\MatchStatus;
use App\Filament\Infolists\Components\ChatHistoryEntry;
use App\Models\GameMatch;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * M12 Phase 2 — admin match detail view used on `ViewGameMatch`.
 *
 * Layout (top → bottom):
 *   1. Match — full-width banner; 6-col compact grid of key facts. The one
 *      thing admin scans first to size up the dispute.
 *   2. Creator + Taker — 2-column Grid side-by-side. When settled, the
 *      winner's section gets a gold trophy icon in its header.
 *   3. Chat history — full-width, primary content (where the admin makes
 *      the decision). Not collapsible — primary content shouldn't hide.
 *   4. Admin resolution history — full-width, only renders if rows exist.
 *
 * Linked-account rows hide themselves when empty (no "— not linked —"
 * noise filling the cards for unlinked players).
 */
class GameMatchInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                self::matchSection(),
                Grid::make(2)
                    ->schema([
                        self::creatorSection(),
                        self::takerSection(),
                    ]),
                self::chatSection(),
                self::resolutionHistorySection(),
            ]);
    }

    private static function matchSection(): Section
    {
        return Section::make('Match')
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
                    ->label('Stake (each)')
                    ->formatStateUsing(fn ($state) => '$'.number_format((float) $state, 2)),

                TextEntry::make('listing.stake_amount')
                    ->label('Pot total')
                    ->formatStateUsing(fn ($state) => '$'.number_format((float) $state * 2, 2)),

                TextEntry::make('created_at')
                    ->label('Match created')
                    ->dateTime('M j, Y H:i')
                    ->since(),

                TextEntry::make('dispute_opened_at')
                    ->label('Dispute opened')
                    ->dateTime('M j, Y H:i')
                    ->since()
                    ->placeholder('—'),

                TextEntry::make('disputeOpener.name')
                    ->label('Opened by')
                    ->placeholder('—'),

                TextEntry::make('settled_at')
                    ->label('Settled at')
                    ->dateTime('M j, Y H:i')
                    ->since()
                    ->placeholder('—'),

                TextEntry::make('winner.username')
                    ->label('Winner')
                    ->placeholder('—')
                    ->icon(fn (GameMatch $record) => $record->winner_user_id ? Heroicon::Trophy : null)
                    ->iconColor('warning'),
            ])
            ->columns(6);
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
     * Shared schema for the Creator + Taker cards. `relationPath` is either
     * `listing.user` or `taker` so the same set of TextEntries works on
     * both sides.
     *
     * Linked-account rows hide themselves when empty so the cards stay
     * tight when a player isn't linked on a given platform.
     *
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

    private static function isWinner(GameMatch $record, ?int $playerId): bool
    {
        return $record->status === MatchStatus::Settled
            && $playerId !== null
            && $record->winner_user_id === $playerId;
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
