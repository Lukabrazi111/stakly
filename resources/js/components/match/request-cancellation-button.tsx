import { router } from '@inertiajs/react';
import { Handshake } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import { request as requestCancellationRoute } from '@/routes/matches/cancellation';

const REASON_MAX = 200;

const OTHER = 'Other';

// Submitted `reason` is the literal label string — no server enum, persisted
// value is self-describing. Copy changes don't retroactively update history.
const PRESET_REASONS = [
    'Something came up, I have to go',
    'Technical / connection issues',
    "Couldn't connect with my opponent",
    'We agreed in chat to cancel',
    'Accidentally took this match',
    OTHER,
] as const;

interface RequestCancellationButtonProps {
    matchId: number;
    /** Positive number while mid-cooldown after a rejected request;
     *  0/undefined → enabled. */
    cooldownMinutesRemaining?: number;
}

export function RequestCancellationButton({
    matchId,
    cooldownMinutesRemaining,
}: RequestCancellationButtonProps) {
    const [open, setOpen] = useState(false);
    const [selected, setSelected] = useState<string | null>(null);
    const [otherText, setOtherText] = useState('');
    const [processing, setProcessing] = useState(false);

    const isCooldown = (cooldownMinutesRemaining ?? 0) > 0;
    const isOther = selected === OTHER;
    const canSubmit = selected !== null && !processing;

    const resetForm = () => {
        setSelected(null);
        setOtherText('');
    };

    const handleSubmit = () => {
        if (!canSubmit) {
            return;
        }

        const reasonPayload = isOther
            ? otherText.trim() === ''
                ? OTHER
                : otherText.trim()
            : selected;

        setProcessing(true);
        router.post(
            requestCancellationRoute(matchId).url,
            { reason: reasonPayload },
            {
                preserveScroll: true,
                onFinish: () => {
                    setProcessing(false);
                    setOpen(false);
                    resetForm();
                },
            },
        );
    };

    const handleOpenChange = (next: boolean) => {
        if (!next && !processing) {
            resetForm();
        }

        setOpen(next);
    };

    const triggerLabel = isCooldown
        ? `Request cancellation (${cooldownMinutesRemaining}m cooldown)`
        : 'Request cancellation';

    const cooldownTitle = isCooldown
        ? `You can request again in ${cooldownMinutesRemaining} minute${cooldownMinutesRemaining === 1 ? '' : 's'}.`
        : undefined;

    return (
        <>
            <button
                type="button"
                onClick={() => !isCooldown && setOpen(true)}
                disabled={isCooldown}
                title={cooldownTitle}
                className="inline-flex cursor-pointer items-center gap-1.5 rounded-sm text-xs text-muted-foreground underline-offset-4 transition-colors hover:text-foreground hover:underline focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-60 disabled:hover:text-muted-foreground disabled:hover:no-underline"
            >
                <Handshake className="size-3.5" aria-hidden="true" />
                {triggerLabel}
            </button>

            <Dialog open={open} onOpenChange={handleOpenChange}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Request to cancel match</DialogTitle>
                        <DialogDescription>
                            Both stakes will be refunded if your opponent
                            accepts. If they decline, the match continues and
                            you'll wait 30 minutes before you can request again.
                        </DialogDescription>
                    </DialogHeader>

                    <fieldset
                        className="flex flex-col gap-2 py-2"
                        disabled={processing}
                    >
                        <legend className="mb-1 text-sm font-medium text-foreground">
                            Reason
                        </legend>
                        {PRESET_REASONS.map((reason) => (
                            <ReasonOption
                                key={reason}
                                label={reason}
                                checked={selected === reason}
                                onSelect={() => setSelected(reason)}
                            />
                        ))}

                        {isOther && (
                            <div className="mt-1 grid gap-2 pl-1">
                                <Textarea
                                    id="cancellation-other-reason"
                                    aria-label="Other reason"
                                    value={otherText}
                                    onChange={(e) =>
                                        setOtherText(
                                            e.target.value.slice(0, REASON_MAX),
                                        )
                                    }
                                    placeholder="Optional — add a short note for your opponent."
                                    maxLength={REASON_MAX}
                                    rows={3}
                                    className="border-0"
                                />
                                <p className="text-right text-xs text-muted-foreground tabular-nums">
                                    {otherText.length}/{REASON_MAX}
                                </p>
                            </div>
                        )}
                    </fieldset>

                    <DialogFooter>
                        <Button
                            variant="ghost"
                            onClick={() => setOpen(false)}
                            disabled={processing}
                        >
                            Cancel
                        </Button>
                        <Button
                            variant="default"
                            onClick={handleSubmit}
                            disabled={!canSubmit}
                        >
                            {processing ? 'Sending…' : 'Send request'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

interface ReasonOptionProps {
    label: string;
    checked: boolean;
    onSelect: () => void;
}

/** Native radio + Stakly skin. `has-[:focus-visible]` so the focus ring
 *  only shows for keyboard users, not on mouse clicks. */
function ReasonOption({ label, checked, onSelect }: ReasonOptionProps) {
    return (
        <label
            className={cn(
                'flex cursor-pointer items-center gap-3 rounded-lg border bg-card/40 px-3 py-2.5 transition-colors',
                'has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-primary/25',
                checked
                    ? 'border-primary'
                    : 'border-border/60 hover:border-primary/30 hover:bg-primary/5',
            )}
        >
            <input
                type="radio"
                name="cancellation-reason"
                value={label}
                checked={checked}
                onChange={onSelect}
                className="sr-only"
            />
            <span
                aria-hidden="true"
                className={cn(
                    'inline-flex size-4 shrink-0 items-center justify-center rounded-full border transition-colors',
                    checked
                        ? 'border-primary bg-primary/20'
                        : 'border-muted-foreground/40 bg-background',
                )}
            >
                {checked && <span className="size-2 rounded-full bg-primary" />}
            </span>
            <span
                className={cn(
                    'text-sm transition-colors',
                    checked ? 'text-foreground' : 'text-muted-foreground',
                )}
            >
                {label}
            </span>
        </label>
    );
}
