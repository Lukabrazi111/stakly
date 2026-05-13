import { Link } from '@inertiajs/react';
import { TransactionTypeChip } from '@/components/wallet/transaction-type-chip';
import { formatSignedAmount, formatTransactionDate, formatUsdt } from '@/lib/wallet-format';
import { show as showListing } from '@/routes/listings';
import type { WalletTransaction } from '@/types';

interface Props {
    transaction: WalletTransaction;
}

/**
 * One transaction line — works in both the /wallet recent activity slot and
 * the /wallet/history feed. Stacks vertically on mobile (chip + amount on
 * row 1, description + balance below) and lays out horizontally on md+.
 *
 * If the row references a listing, the description anchor links to it. The
 * row itself is not a Link — the only navigation target is the listing
 * reference, which is opt-in.
 */
export function TransactionRow({ transaction }: Props) {
    const isCredit = transaction.amount > 0;
    const description = transaction.description ?? defaultDescription(transaction.type);

    return (
        <article className="border-border/60 bg-card/60 flex flex-col gap-3 rounded-xl border p-4 transition-colors md:flex-row md:items-center md:gap-4">
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
                <div className="text-foreground truncate text-sm">
                    {description}
                    {transaction.related_listing && (
                        <>
                            {' '}
                            <Link
                                href={showListing(transaction.related_listing.id).url}
                                className="text-primary hover:text-primary/80 font-medium underline-offset-2 transition-colors hover:underline"
                            >
                                #{transaction.related_listing.id}
                            </Link>
                        </>
                    )}
                </div>
                <div className="text-muted-foreground mt-0.5 text-xs">
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
                <div className="text-muted-foreground mt-0.5 font-mono text-xs">
                    Balance: {formatUsdt(transaction.balance_after)}
                </div>
            </div>

            {/* Mobile-only balance-after line, since the desktop column is hidden. */}
            <div className="text-muted-foreground -mt-1 font-mono text-xs md:hidden">
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
