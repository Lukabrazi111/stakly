import { Link } from '@inertiajs/react';
import { Clock } from 'lucide-react';
import { TransactionTypeChip } from '@/components/wallet/transaction-type-chip';
import { useT } from '@/lib/i18n';
import {
    formatSignedAmount,
    formatTimeUntil,
    formatTransactionDate,
    formatUsdt,
} from '@/lib/wallet-format';
import { show as showListing } from '@/routes/listings';
import { show as showMatch } from '@/routes/matches';
import type { WalletTransaction } from '@/types';

interface Props {
    transaction: WalletTransaction;
}

// Payout/Fee link to the match; Hold/Release stay on the listing (the
// listing may have been cancelled with no match ever created).
const MATCH_LINKED_TYPES: ReadonlySet<WalletTransaction['type']> = new Set([
    'payout',
    'fee',
]);

/** One transaction line, used in both `/wallet` and `/wallet/history`. */
export function TransactionRow({ transaction }: Props) {
    const t = useT();
    const isCredit = transaction.amount > 0;
    const description =
        transaction.description ?? t(defaultDescription(transaction.type));
    // Only payouts carry a clearance; null once the window has passed.
    const clearsIn = formatTimeUntil(transaction.clears_at);

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
                                    showMatch({
                                        match: transaction.related_match.id,
                                    }).url
                                }
                                className="font-medium text-primary underline-offset-2 transition-colors hover:text-primary/80 hover:underline"
                            >
                                {t('Match #:id', {
                                    id: transaction.related_match.id,
                                })}
                            </Link>
                        </>
                    )}
                    {!linksToMatch && transaction.related_listing && (
                        <>
                            {' '}
                            <Link
                                href={
                                    showListing({
                                        listing: transaction.related_listing.id,
                                    }).url
                                }
                                className="font-medium text-primary underline-offset-2 transition-colors hover:text-primary/80 hover:underline"
                            >
                                {t('Listing #:id', {
                                    id: transaction.related_listing.id,
                                })}
                            </Link>
                        </>
                    )}
                </div>
                <div className="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                    <span>{formatTransactionDate(transaction.created_at)}</span>
                    {clearsIn && (
                        <span className="inline-flex items-center gap-1 rounded-full border border-warning/30 bg-warning/10 px-2 py-0.5 font-medium text-warning">
                            <Clock className="size-3" />
                            {t('Withdrawable')} {t(clearsIn)}
                        </span>
                    )}
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
                    {t('Balance: :amount', {
                        amount: formatUsdt(transaction.balance_after),
                    })}
                </div>
            </div>

            <div className="-mt-1 font-mono text-xs text-muted-foreground md:hidden">
                Balance: {formatUsdt(transaction.balance_after)}
            </div>
        </article>
    );
}

function defaultDescription(type: WalletTransaction['type']): string {
    const labels: Record<WalletTransaction['type'], string> = {
        deposit: 'USDT deposit',
        withdrawal: 'USDT withdrawal',
        escrow_hold: 'Stake escrowed for listing',
        escrow_release: 'Stake refunded from listing',
        payout: 'Match payout',
        fee: 'Platform fee',
        withdrawal_reversal: 'Withdrawal returned',
    };

    return labels[type];
}
