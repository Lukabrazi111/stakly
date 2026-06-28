<?php

namespace App\Filament\Resources\Listings\Schemas;

use App\Enums\LinkedAccountProvider;
use App\Enums\ListingStatus;
use App\Models\Listing;
use App\Models\WalletTransaction;
use App\Support\WalletReferenceParser;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Read-only audit view for a single listing. Three sections:
 *
 *  - Listing details — every column rendered with a creator link into
 *    UserResource and the platform / status auto-styled from enums.
 *  - Related match — visible only when status is Taken; surfaces the match
 *    id + taker + status + link into the M12 `GameMatchResource` (admin
 *    Disputes panel).
 *  - Wallet transactions — every `wallet_transactions` row tied to this
 *    listing. Query combines `related_listing_id` FK matches with
 *    parser-known reference prefixes (`listing-create:` /
 *    `listing-cancel:` / `listing-expire:` / `match-take:`) — same indexed
 *    `whereIn` approach M31's sibling lookup uses.
 */
class ListingInfolist
{
    private const WALLET_ROWS_LIMIT = 30;

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            self::detailsSection()->columnSpanFull(),
            self::matchSection()->columnSpanFull(),
            self::walletSection()->columnSpanFull(),
        ]);
    }

    private static function detailsSection(): Section
    {
        return Section::make('Listing')
            ->icon(Heroicon::OutlinedClipboardDocumentList)
            ->columns(3)
            ->schema([
                TextEntry::make('id')->label('Listing #'),

                TextEntry::make('status')
                    ->label('Status')
                    ->badge(),

                TextEntry::make('created_at')
                    ->label('Created')
                    ->dateTime('M j, Y H:i:s'),

                TextEntry::make('user.username')
                    ->label('Creator')
                    ->url(fn (Listing $record): ?string => $record->user
                        ? route('filament.admin.resources.users.view', $record->user)
                        : null,
                    )
                    ->placeholder('—'),

                TextEntry::make('game')->label('Game'),

                TextEntry::make('platform')
                    ->label('Platform')
                    ->formatStateUsing(fn (LinkedAccountProvider $state): string => match ($state) {
                        LinkedAccountProvider::ChessCom => 'chess.com',
                        LinkedAccountProvider::Lichess => 'Lichess',
                    }),

                TextEntry::make('stake_amount')
                    ->label('Stake')
                    ->formatStateUsing(fn ($state): string => '$'.number_format((float) $state, 2).' USDT')
                    ->weight('semibold'),

                TextEntry::make('time_control')
                    ->label('Time control')
                    ->state(fn (Listing $record): string => self::formatTimeControl($record)),

                TextEntry::make('region')
                    ->label('Region')
                    ->placeholder('—'),

                TextEntry::make('language')
                    ->label('Languages')
                    ->state(fn (Listing $record): string => self::formatLanguages($record)),

                TextEntry::make('expires_at')
                    ->label('Expires')
                    ->dateTime('M j, Y H:i:s'),
            ]);
    }

    private static function matchSection(): Section
    {
        return Section::make('Related match')
            ->icon(Heroicon::OutlinedTrophy)
            ->visible(fn (Listing $record): bool => $record->status === ListingStatus::Taken,
            )
            ->columns(3)
            ->schema([
                TextEntry::make('gameMatch.id')
                    ->label('Match #')
                    ->url(fn (Listing $record): ?string => $record->gameMatch
                        ? route('filament.admin.resources.disputes.view', $record->gameMatch)
                        : null,
                    )
                    ->placeholder('—'),

                TextEntry::make('gameMatch.status')
                    ->label('Match status')
                    ->badge()
                    ->placeholder('—'),

                TextEntry::make('gameMatch.taker.username')
                    ->label('Taker')
                    ->url(fn (Listing $record): ?string => $record->gameMatch?->taker
                        ? route('filament.admin.resources.users.view', $record->gameMatch->taker)
                        : null,
                    )
                    ->placeholder('—'),
            ]);
    }

    private static function walletSection(): Section
    {
        return Section::make('Wallet transactions')
            ->description('Every ledger row tied to this listing — escrow holds, refunds, payouts, fees. Sourced via FK + reference-prefix lookup.')
            ->icon(Heroicon::OutlinedBanknotes)
            ->collapsible()
            ->schema([
                TextEntry::make('wallet_rows_html')
                    ->hiddenLabel()
                    ->state(fn (Listing $record): string => self::walletRowsSummary($record))
                    ->html()
                    ->columnSpanFull(),
            ]);
    }

    private static function walletRowsSummary(Listing $record): string
    {
        $listingRefs = WalletReferenceParser::allReferencesFor('listing', $record->id);
        $matchId = $record->gameMatch?->id;
        $matchRefs = $matchId !== null
            ? WalletReferenceParser::allReferencesFor('match', $matchId)
            : [];

        $candidateRefs = array_merge($listingRefs, $matchRefs);

        $rows = WalletTransaction::query()
            ->with('user:id,username')
            ->where(function ($q) use ($candidateRefs, $record): void {
                $q->whereIn('reference_id', $candidateRefs)
                    ->orWhere('related_listing_id', $record->id);
            })
            ->orderBy('created_at')
            ->limit(self::WALLET_ROWS_LIMIT)
            ->get();

        if ($rows->isEmpty()) {
            return '<p class="text-sm text-gray-500">No wallet rows found for this listing.</p>';
        }

        $body = $rows->map(function (WalletTransaction $tx): string {
            $amount = e(WalletTransaction::formatAmount((string) $tx->amount));
            $color = str_starts_with((string) $tx->amount, '-') ? '#ef4444' : '#10b981';
            $type = e($tx->type->getLabel());
            $when = e($tx->created_at->format('M j, H:i:s'));
            $ref = e($tx->reference_id ?? '—');
            $userLabel = $tx->user
                ? '@'.e($tx->user->username)
                : "User #{$tx->user_id}";
            $userUrl = $tx->user
                ? e(route('filament.admin.resources.users.view', $tx->user))
                : '#';

            return <<<HTML
                <tr>
                    <td class="px-2 py-1">#{$tx->id}</td>
                    <td class="px-2 py-1">{$type}</td>
                    <td class="px-2 py-1"><a href="{$userUrl}" class="underline">{$userLabel}</a></td>
                    <td class="px-2 py-1 text-right" style="color: {$color}; font-weight: 600;">{$amount}</td>
                    <td class="px-2 py-1 text-gray-500">{$ref}</td>
                    <td class="px-2 py-1 text-gray-500">{$when}</td>
                </tr>
            HTML;
        })->join('');

        return <<<HTML
            <div class="overflow-x-auto">
                <table class="text-sm w-full">
                    <thead><tr class="text-left text-gray-500 border-b">
                        <th class="px-2 py-1">Tx #</th>
                        <th class="px-2 py-1">Type</th>
                        <th class="px-2 py-1">User</th>
                        <th class="px-2 py-1 text-right">Amount</th>
                        <th class="px-2 py-1">Reference</th>
                        <th class="px-2 py-1">When</th>
                    </tr></thead>
                    <tbody>{$body}</tbody>
                </table>
            </div>
        HTML;
    }

    private static function formatTimeControl(Listing $record): string
    {
        return $record->time_control !== null
            ? ucfirst($record->time_control->value)
            : '—';
    }

    private static function formatLanguages(Listing $record): string
    {
        return implode(', ', $record->language ?? []) ?: '—';
    }
}
