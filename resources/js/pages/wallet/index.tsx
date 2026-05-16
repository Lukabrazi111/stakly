import { Head, Link } from '@inertiajs/react';
import { ArrowDownToLine, ArrowUpFromLine, History, Wallet as WalletIcon } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { BalanceCard } from '@/components/wallet/balance-card';
import { TransactionRow } from '@/components/wallet/transaction-row';
import PlayerHubLayout from '@/layouts/player-hub-layout';
import { deposit as depositRoute, history as historyRoute, withdraw as withdrawRoute } from '@/routes/wallet';
import type { WalletIndexProps } from '@/types';

export default function WalletIndex({ balance, recentTransactions }: WalletIndexProps) {
    const hasTransactions = recentTransactions.data.length > 0;

    return (
        <PlayerHubLayout>
            <Head title="Wallet" />

            <div className="mx-auto max-w-4xl px-4 py-10 md:py-14">
                <header className="mb-8">
                    <h1 className="font-display text-3xl font-bold tracking-tight md:text-4xl">
                        Wallet
                    </h1>
                    <p className="text-muted-foreground mt-2 text-sm">
                        Deposit, withdraw, and review your USDT activity.
                    </p>
                </header>

                <BalanceCard balance={balance} />

                <div className="mt-6 grid gap-4 md:grid-cols-3">
                    <ActionCard
                        href={depositRoute().url}
                        icon={ArrowDownToLine}
                        title="Deposit"
                        description="Add USDT via TRC20"
                    />
                    <ActionCard
                        href={withdrawRoute().url}
                        icon={ArrowUpFromLine}
                        title="Withdraw"
                        description="Send to a TRC20 address"
                    />
                    <ActionCard
                        href={historyRoute().url}
                        icon={History}
                        title="History"
                        description="All your transactions"
                    />
                </div>

                <section className="mt-10">
                    <div className="mb-4 flex items-center justify-between gap-3">
                        <h2 className="font-display text-foreground text-xl font-semibold">
                            Recent activity
                        </h2>
                        {hasTransactions && (
                            <Link
                                href={historyRoute().url}
                                className="text-primary hover:text-primary/80 text-sm font-medium transition-colors"
                            >
                                View all →
                            </Link>
                        )}
                    </div>

                    {hasTransactions ? (
                        <div className="space-y-2">
                            {recentTransactions.data.map((transaction) => (
                                <TransactionRow
                                    key={transaction.id}
                                    transaction={transaction}
                                />
                            ))}
                        </div>
                    ) : (
                        <div className="border-border/60 rounded-2xl border border-dashed p-10 text-center">
                            <div className="bg-primary/10 text-primary mx-auto inline-flex size-12 items-center justify-center rounded-full">
                                <WalletIcon className="size-5" />
                            </div>
                            <p className="text-foreground mt-3 text-sm font-medium">
                                No transactions yet
                            </p>
                            <p className="text-muted-foreground mt-1 text-sm">
                                Make a deposit to get started.
                            </p>
                            <Link
                                href={depositRoute().url}
                                className="text-primary hover:text-primary/80 mt-4 inline-block text-sm font-medium transition-colors"
                            >
                                Deposit USDT →
                            </Link>
                        </div>
                    )}
                </section>
            </div>
        </PlayerHubLayout>
    );
}

interface ActionCardProps {
    href: string;
    icon: LucideIcon;
    title: string;
    description: string;
}

function ActionCard({ href, icon: Icon, title, description }: ActionCardProps) {
    return (
        <Link
            href={href}
            className="group border-border/60 bg-card/60 hover:border-primary/30 hover:bg-card hover:shadow-glow-sm focus-visible:ring-primary focus-visible:ring-offset-background flex flex-col gap-3 rounded-2xl border p-5 transition-all duration-200 ease-out hover:-translate-y-0.5 focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
        >
            <div className="bg-primary/10 text-primary group-hover:bg-primary/15 inline-flex size-10 items-center justify-center rounded-xl transition-colors">
                <Icon className="size-5" />
            </div>
            <div>
                <h3 className="text-foreground font-semibold">{title}</h3>
                <p className="text-muted-foreground mt-0.5 text-xs">{description}</p>
            </div>
        </Link>
    );
}
