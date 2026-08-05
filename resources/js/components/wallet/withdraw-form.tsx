import { Link, useForm } from '@inertiajs/react';
import { ShieldCheck, Wallet } from 'lucide-react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useT } from '@/lib/i18n';
import { formatUsdt } from '@/lib/wallet-format';
import { edit as securitySettings } from '@/routes/security';
import { store as withdrawStore } from '@/routes/wallet/withdraw';

interface Props {
    /** Withdrawable balance — total minus winnings still clearing. */
    availableBalance: number;
    minWithdrawal: number;
    platformFee: number;
    estimatedNetworkFee: number;
    /** Whether a TOTP code must accompany every withdrawal (M9 Phase 0d). */
    twoFactorRequired: boolean;
    /** Whether the player has actually set 2FA up. */
    twoFactorEnrolled: boolean;
}

/** Withdraw form. Caps against the AVAILABLE balance, not the total: winnings
 *  inside their insurance window can be staked but not withdrawn. */
export function WithdrawForm({
    availableBalance,
    minWithdrawal,
    platformFee,
    estimatedNetworkFee,
    twoFactorRequired,
    twoFactorEnrolled,
}: Props) {
    const t = useT();
    const { data, setData, post, processing, errors, transform } = useForm<{
        address: string;
        amount: string;
        two_factor_code: string;
    }>({
        address: '',
        amount: '',
        two_factor_code: '',
    });

    // 2FA is required but never set up — there's nothing to type, so prompt
    // enrolment instead of showing a field that can't be satisfied.
    if (twoFactorRequired && !twoFactorEnrolled) {
        return (
            <div className="space-y-4 rounded-xl border border-warning/40 bg-warning/5 p-5">
                <div className="flex items-start gap-3">
                    <ShieldCheck className="mt-0.5 size-5 shrink-0 text-warning" />
                    <div className="space-y-1">
                        <p className="font-medium text-foreground">
                            {t('Two-factor authentication required')}
                        </p>
                        <p className="text-sm text-muted-foreground">
                            {t(
                                'Withdrawals need a code from your authenticator app. Set up two-factor authentication to protect your balance.',
                            )}
                        </p>
                    </div>
                </div>
                <Button asChild variant="gradient" size="pill" className="w-full">
                    <Link href={securitySettings().url}>
                        {t('Set up two-factor authentication')}
                    </Link>
                </Button>
            </div>
        );
    }

    // Trim — pasted addresses often carry a trailing space that the regex rejects.
    transform((d) => ({ ...d, address: d.address.trim() }));

    const amountNumber = data.amount === '' ? 0 : Number(data.amount);
    const exceedsBalance = amountNumber > availableBalance;
    const belowMin = amountNumber > 0 && amountNumber < minWithdrawal;
    const hasAddress = data.address.trim().length > 0;
    const hasCode = !twoFactorRequired || data.two_factor_code.length >= 6;
    const canSubmit =
        !processing &&
        hasAddress &&
        data.amount !== '' &&
        amountNumber > 0 &&
        !exceedsBalance &&
        !belowMin &&
        hasCode;

    const handleMax = () => {
        // Match the server's `decimal:0,2` rule. Floored rather than rounded —
        // `toFixed` rounds up, which would push the amount past the balance and
        // bounce as a validation error on a button labelled "Max".
        setData(
            'amount',
            (Math.floor(availableBalance * 100) / 100).toFixed(2),
        );
    };

    // Gas is deducted by the provider from what we send, so the player receives
    // gross − margin − gas. Shown before submit so the number isn't a surprise.
    const youReceive = Math.max(
        0,
        amountNumber - platformFee - estimatedNetworkFee,
    );

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post(withdrawStore().url);
    };

    return (
        <form onSubmit={handleSubmit} className="space-y-6">
            <div className="space-y-2">
                <Label htmlFor="address">{t('TRC20 USDT address')}</Label>
                <Input
                    id="address"
                    name="address"
                    type="text"
                    spellCheck={false}
                    autoComplete="off"
                    placeholder="T..."
                    value={data.address}
                    onChange={(e) => setData('address', e.target.value)}
                    aria-invalid={errors.address ? true : undefined}
                    className="font-mono"
                />
                <InputError message={errors.address} />
            </div>

            <div className="space-y-2">
                <Label htmlFor="amount">{t('Amount')}</Label>
                <div className="relative flex items-center gap-2">
                    <div className="relative flex-1">
                        <Input
                            id="amount"
                            name="amount"
                            type="number"
                            inputMode="decimal"
                            min={minWithdrawal}
                            max={100000}
                            step="0.01"
                            placeholder="0.00"
                            value={data.amount}
                            onChange={(e) => setData('amount', e.target.value)}
                            aria-invalid={
                                exceedsBalance || belowMin || errors.amount
                                    ? true
                                    : undefined
                            }
                            className="pr-16"
                        />
                        <span className="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm font-medium text-muted-foreground">
                            USDT
                        </span>
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={handleMax}
                        className="shrink-0 rounded-full"
                    >
                        {t('Max')}
                    </Button>
                </div>
                <div className="flex items-center justify-between text-xs">
                    <span className="text-muted-foreground">
                        {t('Min:')}{' '}
                        <span className="font-medium text-foreground">
                            {formatUsdt(minWithdrawal)} USDT
                        </span>
                    </span>
                    <span className="inline-flex items-center gap-1 text-muted-foreground">
                        <Wallet className="size-3" />
                        {t('Available:')}{' '}
                        <span
                            className={
                                exceedsBalance
                                    ? 'font-medium text-destructive'
                                    : 'font-medium text-foreground'
                            }
                        >
                            {formatUsdt(availableBalance)} USDT
                        </span>
                    </span>
                </div>
                {exceedsBalance && (
                    <p className="text-xs text-destructive">
                        {t('Amount exceeds your available balance.')}
                    </p>
                )}
                {belowMin && (
                    <p className="text-xs text-destructive">
                        {t('Minimum withdrawal is :amount USDT.', {
                            amount: formatUsdt(minWithdrawal),
                        })}
                    </p>
                )}
                <InputError message={errors.amount} />
            </div>

            {amountNumber > 0 && (
                <div className="space-y-2 rounded-xl border border-border/60 bg-card/60 p-4 text-sm">
                    <FeeLine
                        label={t('Withdraw')}
                        value={formatUsdt(amountNumber)}
                    />
                    <FeeLine
                        label={t('Network fee (estimated)')}
                        value={`−${formatUsdt(estimatedNetworkFee)}`}
                        muted
                    />
                    <FeeLine
                        label={t('Platform fee')}
                        value={`−${formatUsdt(platformFee)}`}
                        muted
                    />
                    <div className="flex items-baseline justify-between border-t border-border/60 pt-2">
                        <span className="font-medium text-foreground">
                            {t("You'll receive")}
                        </span>
                        <span className="font-display font-semibold text-success tabular-nums">
                            {formatUsdt(youReceive)} USDT
                        </span>
                    </div>
                </div>
            )}

            {twoFactorRequired && (
                <div className="space-y-2">
                    <Label htmlFor="two_factor_code">
                        {t('Authenticator code')}
                    </Label>
                    <Input
                        id="two_factor_code"
                        name="two_factor_code"
                        type="text"
                        inputMode="numeric"
                        autoComplete="one-time-code"
                        placeholder="000000"
                        maxLength={6}
                        value={data.two_factor_code}
                        onChange={(e) =>
                            setData(
                                'two_factor_code',
                                e.target.value.replace(/\D/g, ''),
                            )
                        }
                        aria-invalid={errors.two_factor_code ? true : undefined}
                        className="font-mono tracking-[0.4em]"
                    />
                    <p className="text-xs text-muted-foreground">
                        {t(
                            'Every withdrawal needs a fresh code, so a stolen session cannot move your funds.',
                        )}
                    </p>
                    <InputError message={errors.two_factor_code} />
                </div>
            )}

            <Button
                type="submit"
                variant="gradient"
                size="pill"
                disabled={!canSubmit}
                className="w-full"
            >
                {processing ? t('Submitting…') : t('Withdraw')}
            </Button>
        </form>
    );
}

function FeeLine({
    label,
    value,
    muted = false,
}: {
    label: string;
    value: string;
    muted?: boolean;
}) {
    return (
        <div className="flex items-baseline justify-between">
            <span className="text-muted-foreground">{label}</span>
            <span
                className={`tabular-nums ${muted ? 'text-muted-foreground' : 'text-foreground'}`}
            >
                {value}
            </span>
        </div>
    );
}
