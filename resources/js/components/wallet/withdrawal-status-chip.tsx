import { CheckCircle2, Clock, Send, XCircle } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useT } from '@/lib/i18n';
import type { WithdrawalStatus } from '@/types';

interface StatusMeta {
    label: string;
    icon: LucideIcon;
    classes: string;
}

// Semantic colors mirror App\Enums\WithdrawalStatus::getColor() so the admin
// panel and the player-facing UI never disagree about what a status means.
const STATUS_META: Record<WithdrawalStatus, StatusMeta> = {
    pending: {
        label: 'Queued',
        icon: Clock,
        classes: 'bg-warning/10 text-warning border-warning/30',
    },
    sending: {
        label: 'Sending',
        icon: Send,
        classes: 'bg-primary/10 text-primary border-primary/30',
    },
    completed: {
        label: 'Sent',
        icon: CheckCircle2,
        classes: 'bg-success/10 text-success border-success/30',
    },
    rejected: {
        label: 'Rejected',
        icon: XCircle,
        classes: 'bg-destructive/10 text-destructive border-destructive/30',
    },
    failed: {
        label: 'Failed',
        icon: XCircle,
        classes: 'bg-destructive/10 text-destructive border-destructive/30',
    },
};

interface Props {
    status: WithdrawalStatus;
}

export function WithdrawalStatusChip({ status }: Props) {
    const t = useT();
    const { label, icon: Icon, classes } = STATUS_META[status];

    return (
        <span
            className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-medium ${classes}`}
        >
            <Icon className="size-3" />
            {t(label)}
        </span>
    );
}
