import { AlertTriangle, Clock } from 'lucide-react';
import { QRCodeSVG } from 'qrcode.react';
import { BackLink } from '@/components/site/back-link';
import { PageMeta } from '@/components/site/page-meta';
import { AddressDisplay } from '@/components/wallet/address-display';
import PlayerHubLayout from '@/layouts/player-hub-layout';
import { index as walletIndex } from '@/routes/wallet';
import type { WalletDepositProps } from '@/types';

export default function WalletDeposit({ tronAddress }: WalletDepositProps) {
    return (
        <PlayerHubLayout>
            <PageMeta
                title="Deposit — Wallet"
                description="Deposit USDT to your Stakly wallet."
                noindex
            />

            <div className="mx-auto max-w-lg px-4 py-10 md:py-14">
                <BackLink fallback={walletIndex().url} />

                <header className="mt-4 mb-6">
                    <h1 className="font-display text-3xl font-bold tracking-tight md:text-4xl">
                        Deposit USDT
                    </h1>
                    <p className="mt-2 text-sm text-muted-foreground">
                        Send USDT to your unique address to top up your balance.
                    </p>
                </header>

                {/* Network warning — the single most important thing on this page.
                    Send-to-wrong-network is the #1 way users lose funds on
                    crypto platforms; this stays loud, near the address, never
                    collapsed. */}
                <div className="mb-6 flex gap-3 rounded-xl border border-warning/30 bg-warning/10 p-4">
                    <AlertTriangle className="size-5 shrink-0 text-warning" />
                    <div className="space-y-1">
                        <p className="text-sm font-semibold text-warning">
                            Only TRC20 USDT (Tron network)
                        </p>
                        <p className="text-xs text-warning/90">
                            Sending from another network (ERC20, BEP20, Solana,
                            etc.) will lose your funds permanently. Double-check
                            before sending.
                        </p>
                    </div>
                </div>

                {/* QR code on a forced-light card — most phone cameras need a
                    light background to read the dark squares. */}
                <div className="mb-4 flex justify-center">
                    <div className="rounded-2xl bg-white p-5 shadow-lg">
                        <QRCodeSVG
                            value={tronAddress}
                            size={200}
                            level="M"
                            marginSize={0}
                        />
                    </div>
                </div>

                <div className="space-y-2">
                    <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                        Your TRC20 address
                    </p>
                    <AddressDisplay address={tronAddress} />
                </div>

                <p className="mt-6 inline-flex items-start gap-2 text-xs text-muted-foreground">
                    <Clock className="mt-0.5 size-3.5 shrink-0" />
                    <span>
                        Deposits typically arrive within 1–2 minutes after the
                        Tron network confirms the transaction.
                    </span>
                </p>
            </div>
        </PlayerHubLayout>
    );
}
