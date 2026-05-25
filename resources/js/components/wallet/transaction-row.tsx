import { Link } from '@inertiajs/react';
import { TransactionTypeChip } from '@/components/wallet/transaction-type-chip';
import {
    formatSignedAmount,
    formatTransactionDate,
    formatUsdt,
} from '@/lib/wallet-format';
import { show as showListing } from '@/routes/listings';
import { show as showMatch } from '@/routes/matches';
import type { WalletTransaction } from '@/types';

interface Props {
    transaction: WalletTransaction;
}

// Transactions where the match itself is the most relevant context for the
// row — clicking through should land on /matches/{id}, not /listings/{id}.
// Hold / Release stay on the listing because that's where the money was
// locked (and the listing might have been cancelled/expired, with no match
// ever created).
const MATCH_LINKED_TYPES: ReadonlySet<WalletTransaction['type']> = new Set([
    'payout',
    'fee',
]);

/**
 * One transaction line — works in both the /wallet recent activity slot and
 * the /wallet/history feed. Stacks vertically on mobile (chip + amount on
 * row 1, description + balance below) and lays out horizontally on md+.
 *
 * If the row references a match (Payout / Fee), the description anchor links
 * to the match detail page. Otherwise it links to the listing, when present.
 * The row itself is not a Link — navigation is opt-in via the reference.
 */
export function TransactionRow({ transaction }: Props) {
    const isCredit = transaction.amount > 0;
    const description =
        transaction.description ?? defaultDescription(transaction.type);

    const linksToMatch =
        MATCH_LINKED_TYPES.has(transaction.type) &&
        transaction.related_match !== null;

    return (
        <article className="flex flex-col gap-3 rounded-xl border border-border/60 bg-card/60 p-4 transition-colors md:flex-row md:items-center md:gap-4">
            <div className="flex items-center justify-between gap-3 md:w-44 md:shrink-0">
                <TransactionTypeChip type={transaction.type} />
                <span
                    className={`font-mono text-sm font-semibold md:hidden ${
                        isCredit ? 'text-success' : 'text-foreground'
                    }`}
                >
                    {formatSignedAmount(transaction.amount)}
                </span>
            </div>

            <div className="min-w-0 flex-1">
                <div className="truncate text-sm text-foreground">
                    {description}
                    {linksToMatch && transaction.related_match && (
                        <>
                            {' '}
                            <Link
                                href={
                                    showMatch(transaction.related_match.id).url
                                }
                                className="font-medium text-primary underline-offset-2 transition-colors hover:text-primary/80 hover:underline"
                            >
                                Match #{transaction.related_match.id}
                            </Link>
                        </>
                    )}
                    {!linksToMatch && transaction.related_listing && (
                        <>
                            {' '}
                            <Link
                                href={
                                    showListing(transaction.related_listing.id)
                                        .url
                                }
                                className="font-medium text-primary underline-offset-2 transition-colors hover:text-primary/80 hover:underline"
                            >
                                Listing #{transaction.related_listing.id}
                            </Link>
                        </>
                    )}
                </div>
                <div className="mt-0.5 text-xs text-muted-foreground">
                    {formatTransactionDate(transaction.created_at)}
                </div>
            </div>

            <div className="hidden text-right md:block md:w-36 md:shrink-0">
                <div
                    className={`font-mono text-sm font-semibold ${
                        isCredit ? 'text-success' : 'text-foreground'
                    }`}
                >
                    {formatSignedAmount(transaction.amount)} USDT
                </div>
                <div className="mt-0.5 font-mono text-xs text-muted-foreground">
                    Balance: {formatUsdt(transaction.balance_after)}
                </div>
            </div>

            {/* Mobile-only balance-after line, since the desktop column is hidden. */}
            <div className="-mt-1 font-mono text-xs text-muted-foreground md:hidden">
                Balance: {formatUsdt(transaction.balance_after)}
            </div>
        </article>
    );
}

/**
 * When the backend didn't attach a description (e.g. seeded data or future
 * paths that forget to pass one), fall back to a humanized label. Keeps the
 * UI from rendering an awkward dash.
 */
function defaultDescription(type: WalletTransaction['type']): string {
    const labels: Record<WalletTransaction['type'], string> = {
        deposit: 'USDT deposit',
        withdrawal: 'USDT withdrawal',
        escrow_hold: 'Stake escrowed for listing',
        escrow_release: 'Stake refunded from listing',
        payout: 'Match payout',
        fee: 'Platform fee',
    };

    return labels[type];
}
