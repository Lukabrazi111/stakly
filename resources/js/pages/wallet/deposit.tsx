import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, ChevronLeft, Clock } from 'lucide-react';
import { QRCodeSVG } from 'qrcode.react';
import { AddressDisplay } from '@/components/wallet/address-display';
import SiteLayout from '@/layouts/site-layout';
import { index as walletIndex } from '@/routes/wallet';
import type { WalletDepositProps } from '@/types';

export default function WalletDeposit({ tronAddress }: WalletDepositProps) {
    return (
        <SiteLayout>
            <Head title="Deposit — Wallet" />

            <div className="mx-auto max-w-lg px-4 py-10 md:py-14">
                <Link
                    href={walletIndex().url}
                    className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1 text-sm transition-colors"
                >
                    <ChevronLeft className="size-4" />
                    Back to wallet
                </Link>

                <header className="mt-4 mb-6">
                    <h1 className="font-display text-3xl font-bold tracking-tight md:text-4xl">
                        Deposit USDT
                    </h1>
                    <p className="text-muted-foreground mt-2 text-sm">
                        Send USDT to your unique address to top up your balance.
                    </p>
                </header>

                {/* Network warning — the single most important thing on this page.
                    Send-to-wrong-network is the #1 way users lose funds on
                    crypto platforms; this stays loud, near the address, never
                    collapsed. */}
                <div className="border-warning/30 bg-warning/10 mb-6 flex gap-3 rounded-xl border p-4">
                    <AlertTriangle className="text-warning size-5 shrink-0" />
                    <div className="space-y-1">
                        <p className="text-warning text-sm font-semibold">
                            Only TRC20 USDT (Tron network)
                        </p>
                        <p className="text-warning/90 text-xs">
                            Sending from another network (ERC20, BEP20, Solana, etc.)
                            will lose your funds permanently. Double-check before
                            sending.
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
                    <p className="text-muted-foreground text-xs font-medium tracking-wide uppercase">
                        Your TRC20 address
                    </p>
                    <AddressDisplay address={tronAddress} />
                </div>

                <p className="text-muted-foreground mt-6 inline-flex items-start gap-2 text-xs">
                    <Clock className="mt-0.5 size-3.5 shrink-0" />
                    <span>
                        Deposits typically arrive within 1–2 minutes after the Tron
                        network confirms the transaction.
                    </span>
                </p>
            </div>
        </SiteLayout>
    );
}
