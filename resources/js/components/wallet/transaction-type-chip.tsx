import {
    ArrowDownToLine,
    ArrowUpFromLine,
    Coins,
    Lock,
    RotateCcw,
    Trophy,
    Undo2,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useT } from '@/lib/i18n';
import type { WalletTransactionType } from '@/types';

interface TypeMeta {
    label: string;
    icon: LucideIcon;
    classes: string;
}

// Semantic colors: completed credits → success, escrow hold → warning,
// withdrawal → destructive, fee → muted.
const TYPE_META: Record<WalletTransactionType, TypeMeta> = {
    deposit: {
        label: 'Deposit',
        icon: ArrowDownToLine,
        classes: 'bg-success/10 text-success border-success/30',
    },
    payout: {
        label: 'Payout',
        icon: Trophy,
        classes: 'bg-success/10 text-success border-success/30',
    },
    escrow_release: {
        label: 'Refund',
        icon: Undo2,
        classes: 'bg-success/10 text-success border-success/30',
    },
    escrow_hold: {
        label: 'Escrow hold',
        icon: Lock,
        classes: 'bg-warning/10 text-warning border-warning/30',
    },
    withdrawal: {
        label: 'Withdrawal',
        icon: ArrowUpFromLine,
        classes: 'bg-destructive/10 text-destructive border-destructive/30',
    },
    fee: {
        label: 'Platform fee',
        icon: Coins,
        classes: 'bg-muted text-muted-foreground border-border',
    },
    withdrawal_reversal: {
        label: 'Withdrawal returned',
        icon: RotateCcw,
        classes: 'bg-primary/10 text-primary border-primary/30',
    },
};

interface Props {
    type: WalletTransactionType;
}

export function TransactionTypeChip({ type }: Props) {
    const t = useT();
    const { label, icon: Icon, classes } = TYPE_META[type];

    return (
        <span
            className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-medium ${classes}`}
        >
            <Icon className="size-3" />
            {t(label)}
        </span>
    );
}
