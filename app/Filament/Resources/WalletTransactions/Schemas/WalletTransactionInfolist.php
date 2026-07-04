<?php

namespace App\Filament\Resources\WalletTransactions\Schemas;

use App\Models\WalletTransaction;
use App\Support\WalletReferenceParser;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

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
    // Comfortably exceeds a 5v5's hold/payout/fee/refund row count so the
    // sibling list never truncates a single team match in normal operation.
    private const SIBLINGS_LIMIT = 32;

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
            ->description('Other ledger rows referencing the same listing or match (escrow holds, payout+fee, refund set, etc.). Grouped by the related_listing_id FK so team settlements — whose per-player reference_ids are suffixed and never match each other — still co-appear; reference_id is unique per row.')
            ->icon(Heroicon::OutlinedRectangleStack)
            ->collapsible()
            ->visible(fn (WalletTransaction $record): bool => $record->related_listing_id !== null
                || WalletReferenceParser::parseEntity($record->reference_id) !== null,
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
        $query = self::siblingQuery($record);

        if ($query === null) {
            return '<p class="text-sm text-gray-500">No related transactions found.</p>';
        }

        // Fetch one beyond the cap so we can tell whether the list was truncated.
        $siblings = $query
            ->with('user:id,username')
            ->where('id', '!=', $record->id)
            ->orderBy('created_at')
            ->limit(self::SIBLINGS_LIMIT + 1)
            ->get();

        if ($siblings->isEmpty()) {
            return '<p class="text-sm text-gray-500">No sibling rows for this entity.</p>';
        }

        $capped = $siblings->count() > self::SIBLINGS_LIMIT;
        $siblings = $siblings->take(self::SIBLINGS_LIMIT);

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

        $cappedNote = $capped
            ? '<p class="mt-2 text-xs text-gray-500">Showing first '.self::SIBLINGS_LIMIT.' rows.</p>'
            : '';

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
                {$cappedNote}
            </div>
        HTML;
    }

    /**
     * Builds the sibling-row query. Settlement + escrow rows all carry the
     * `related_listing_id` FK (indexed; one listing maps to one match's full
     * hold/payout/fee/refund set), so that's the authoritative grouping — it
     * spans the cross-bucket gap where escrow rows are listing-keyed but
     * settlement rows are match-keyed, and survives team settlements whose
     * per-player reference_ids are suffixed and never match each other.
     *
     * Falls back to the prefix parser only for rows with no FK
     * (deposits/withdrawals carrying e.g. a `listing-*` reference_id).
     */
    private static function siblingQuery(WalletTransaction $record): ?Builder
    {
        if ($record->related_listing_id !== null) {
            return WalletTransaction::query()
                ->where('related_listing_id', $record->related_listing_id);
        }

        $entity = WalletReferenceParser::parseEntity($record->reference_id);

        if ($entity === null) {
            return null;
        }

        return WalletTransaction::query()->whereIn(
            'reference_id',
            WalletReferenceParser::allReferencesFor($entity['kind'], $entity['id']),
        );
    }
}
