<?php

namespace App\Filament\Resources\WalletTransactions\Schemas;

use App\Models\WalletTransaction;
use App\Support\WalletReferenceParser;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Read-only audit view for a single ledger row. Three sections:
 *
 *  - Transaction — primary fields with money formatted via
 *    `WalletTransaction::formatAmount()` so display precision matches the
 *    `decimal(18,6)` column without float round-trips.
 *  - Reference — the raw `reference_id` plus a parsed contextual link
 *    routing into the related entity (public listing page for listing-bound
 *    refs, admin Disputes resource for match-bound refs).
 *  - Sibling transactions — other rows sharing this `reference_id`. The
 *    most common shape is "one event produces two rows" (escrow hold +
 *    release, match-payout + match-fee, cancel-refund pair).
 */
class WalletTransactionInfolist
{
    private const SIBLINGS_LIMIT = 20;

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            self::transactionSection()->columnSpanFull(),
            self::referenceSection()->columnSpanFull(),
            self::siblingsSection()->columnSpanFull(),
        ]);
    }

    private static function transactionSection(): Section
    {
        return Section::make('Transaction')
            ->icon(Heroicon::Banknotes)
            ->columns(3)
            ->schema([
                TextEntry::make('id')->label('Tx #'),

                TextEntry::make('type')
                    ->label('Type')
                    ->badge(),

                TextEntry::make('created_at')
                    ->label('When')
                    ->dateTime('M j, Y H:i:s'),

                TextEntry::make('user.username')
                    ->label('User')
                    ->url(fn (WalletTransaction $record): ?string => $record->user
                        ? route('filament.admin.resources.users.view', $record->user)
                        : null,
                    )
                    ->placeholder('—'),

                TextEntry::make('amount')
                    ->label('Amount')
                    ->formatStateUsing(fn ($state): string => WalletTransaction::formatAmount((string) $state))
                    ->color(fn ($state): string => str_starts_with((string) $state, '-')
                        ? 'danger'
                        : 'success',
                    )
                    ->weight('semibold'),

                TextEntry::make('balance_after')
                    ->label('Balance after')
                    ->formatStateUsing(fn ($state): string => WalletTransaction::formatAmount((string) $state)),
            ]);
    }

    private static function referenceSection(): Section
    {
        return Section::make('Reference')
            ->icon(Heroicon::OutlinedLink)
            ->columns(2)
            ->schema([
                TextEntry::make('reference_id')
                    ->label('Reference ID')
                    ->copyable()
                    ->placeholder('—'),

                TextEntry::make('reference_link')
                    ->label('Related entity')
                    ->state(fn (WalletTransaction $record): ?string => WalletReferenceParser::parse($record->reference_id)['label'] ?? null,
                    )
                    ->url(fn (WalletTransaction $record): ?string => WalletReferenceParser::parse($record->reference_id)['url'] ?? null,
                    )
                    ->openUrlInNewTab()
                    ->placeholder('—'),

                TextEntry::make('description')
                    ->placeholder('—')
                    ->columnSpanFull(),

                TextEntry::make('listing.id')
                    ->label('Related listing (FK)')
                    ->state(fn (WalletTransaction $record): ?string => $record->related_listing_id
                        ? "Listing #{$record->related_listing_id}"
                        : null,
                    )
                    ->url(fn (WalletTransaction $record): ?string => $record->related_listing_id
                        ? route('listings.show', $record->related_listing_id)
                        : null,
                    )
                    ->openUrlInNewTab()
                    ->placeholder('—')
                    ->columnSpanFull(),
            ]);
    }

    private static function siblingsSection(): Section
    {
        return Section::make('Sibling transactions')
            ->description('Other ledger rows referencing the same listing or match (escrow-pair, payout+fee, refund-pair, etc.). reference_id is unique per row; siblings share the underlying entity, not the prefix.')
            ->icon(Heroicon::OutlinedRectangleStack)
            ->collapsible()
            ->visible(fn (WalletTransaction $record): bool => WalletReferenceParser::parseEntity($record->reference_id) !== null,
            )
            ->schema([
                TextEntry::make('siblings_html')
                    ->hiddenLabel()
                    ->state(fn (WalletTransaction $record): string => self::siblingsSummary($record))
                    ->html()
                    ->columnSpanFull(),
            ]);
    }

    private static function siblingsSummary(WalletTransaction $record): string
    {
        $entity = WalletReferenceParser::parseEntity($record->reference_id);

        if ($entity === null) {
            return '<p class="text-sm text-gray-500">No related transactions found.</p>';
        }

        $candidateRefs = WalletReferenceParser::allReferencesFor($entity['kind'], $entity['id']);

        $siblings = WalletTransaction::query()
            ->with('user:id,username')
            ->whereIn('reference_id', $candidateRefs)
            ->where('id', '!=', $record->id)
            ->orderBy('created_at')
            ->limit(self::SIBLINGS_LIMIT)
            ->get();

        if ($siblings->isEmpty()) {
            return '<p class="text-sm text-gray-500">No sibling rows for this entity.</p>';
        }

        $rows = $siblings->map(function (WalletTransaction $tx): string {
            $amount = e(WalletTransaction::formatAmount((string) $tx->amount));
            $color = str_starts_with((string) $tx->amount, '-') ? '#ef4444' : '#10b981';
            $type = e($tx->type->getLabel());
            $when = e($tx->created_at->format('M j, H:i:s'));
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
                        <th class="px-2 py-1">When</th>
                    </tr></thead>
                    <tbody>{$rows}</tbody>
                </table>
            </div>
        HTML;
    }
}
