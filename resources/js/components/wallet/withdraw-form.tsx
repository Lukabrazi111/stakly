import { useForm } from '@inertiajs/react';
import { Wallet } from 'lucide-react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatUsdt } from '@/lib/wallet-format';
import { store as withdrawStore } from '@/routes/wallet/withdraw';

interface Props {
    balance: number;
    minWithdrawal: number;
}

/** Withdraw form. Backend short-circuits the POST with a flash notice
 *  pending M9; all validation paths still fire. */
export function WithdrawForm({ balance, minWithdrawal }: Props) {
    const { data, setData, post, processing, errors, transform } = useForm<{
        address: string;
        amount: string;
    }>({
        address: '',
        amount: '',
    });

    // Trim — pasted addresses often carry a trailing space that the regex rejects.
    transform((d) => ({ ...d, address: d.address.trim() }));

    const amountNumber = data.amount === '' ? 0 : Number(data.amount);
    const exceedsBalance = amountNumber > balance;
    const belowMin = amountNumber > 0 && amountNumber < minWithdrawal;
    const hasAddress = data.address.trim().length > 0;
    const canSubmit =
        !processing &&
        hasAddress &&
        data.amount !== '' &&
        amountNumber > 0 &&
        !exceedsBalance &&
        !belowMin;

    const handleMax = () => {
        // Match the server's `decimal:0,2` rule.
        setData('amount', balance.toFixed(2));
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post(withdrawStore().url);
    };

    return (
        <form onSubmit={handleSubmit} className="space-y-6">
            <div className="space-y-2">
                <Label htmlFor="address">TRC20 USDT address</Label>
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
                <Label htmlFor="amount">Amount</Label>
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
                        Max
                    </Button>
                </div>
                <div className="flex items-center justify-between text-xs">
                    <span className="text-muted-foreground">
                        Min:{' '}
                        <span className="font-medium text-foreground">
                            {formatUsdt(minWithdrawal)} USDT
                        </span>
                    </span>
                    <span className="inline-flex items-center gap-1 text-muted-foreground">
                        <Wallet className="size-3" />
                        Available:{' '}
                        <span
                            className={
                                exceedsBalance
                                    ? 'font-medium text-destructive'
                                    : 'font-medium text-foreground'
                            }
                        >
                            {formatUsdt(balance)} USDT
                        </span>
                    </span>
                </div>
                {exceedsBalance && (
                    <p className="text-xs text-destructive">
                        Amount exceeds your available balance.
                    </p>
                )}
                {belowMin && (
                    <p className="text-xs text-destructive">
                        Minimum withdrawal is {formatUsdt(minWithdrawal)} USDT.
                    </p>
                )}
                <InputError message={errors.amount} />
            </div>

            <Button
                type="submit"
                variant="gradient"
                size="pill"
                disabled={!canSubmit}
                className="w-full"
            >
                {processing ? 'Submitting…' : 'Withdraw'}
            </Button>
        </form>
    );
}
