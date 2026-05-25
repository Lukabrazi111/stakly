// Shared formatting helpers for wallet UI. Used by:
//   - BalanceCard (overview hero + compact)
//   - TransactionRow (recent activity + history)
//   - AddressDisplay (deposit page)

/**
 * Formats a USDT amount with thousands separators and exactly 2 decimals.
 * Sign-agnostic — pass the absolute value if you want unsigned display.
 *
 *   1234.56 → "1,234.56"
 *   0       → "0.00"
 *   -50     → "-50.00"
 */
export function formatUsdt(amount: number): string {
    return amount.toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

/**
 * Formats a signed USDT amount with an explicit `+` for credits. The minus sign
 * comes naturally from `toLocaleString` for negative values. Used in transaction
 * rows where the sign communicates direction at a glance.
 *
 *   50    → "+50.00"
 *   -25.5 → "-25.50"
 *   0     → "+0.00" (edge case — shouldn't occur in real data)
 */
export function formatSignedAmount(amount: number): string {
    const formatted = formatUsdt(Math.abs(amount));

    return amount < 0 ? `-${formatted}` : `+${formatted}`;
}

/**
 * Friendlier display for transaction timestamps. Falls back to ISO string
 * components rather than pulling in a date library — the precision we need
 * (today/yesterday/short-date/full-date) is small enough to do by hand.
 */
export function formatTransactionDate(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    const date = new Date(iso);
    const now = new Date();
    const diffMs = now.getTime() - date.getTime();
    const diffHours = diffMs / (1000 * 60 * 60);

    // Same calendar day: show time only.
    if (
        date.getFullYear() === now.getFullYear() &&
        date.getMonth() === now.getMonth() &&
        date.getDate() === now.getDate()
    ) {
        return date.toLocaleTimeString('en-US', {
            hour: 'numeric',
            minute: '2-digit',
        });
    }

    // Within the last 24h but a different calendar day → "Yesterday".
    if (diffHours < 48) {
        return 'Yesterday';
    }

    // Same year: month + day.
    if (date.getFullYear() === now.getFullYear()) {
        return date.toLocaleDateString('en-US', {
            month: 'short',
            day: 'numeric',
        });
    }

    // Older: month + day + year.
    return date.toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
}

/**
 * Compact display for a Tron address: first 6 + last 6 chars with an ellipsis
 * in the middle. Full address is still copyable from the AddressDisplay's
 * data-attribute / clipboard handler.
 *
 *   "TXXXXXXXXXXX...YYYYYYYYY" → "TXXXXX…YYYYYY"
 */
export function truncateAddress(address: string): string {
    if (address.length <= 14) {
        return address;
    }

    return `${address.slice(0, 6)}…${address.slice(-6)}`;
}
