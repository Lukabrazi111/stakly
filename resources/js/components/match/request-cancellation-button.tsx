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

/**
 * Predefined cancellation reasons. The submitted `reason` field is the
 * literal label string — sending the visible text (not a code) keeps the
 * backend dumb (no enum to maintain on the server) and the persisted
 * value self-describing for admin / chat review. Trade: copy changes
 * here don't retroactively update historical records, which is fine —
 * the persisted reason is a snapshot of what the user picked at request
 * time.
 *
 * "Other" reveals a free-text textarea. If the user leaves it blank, the
 * persisted reason is the literal "Other" — still meaningful to the
 * opponent ("they didn't want to specify").
 */
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
    /**
     * Set to a positive number when the viewer is mid-cooldown after a
     * previous rejected request. The button stays visible (so the user
     * sees the affordance) but disables with a tooltip showing the
     * remaining minutes. `0` or `undefined` ⇒ enabled.
     */
    cooldownMinutesRemaining?: number;
}

/**
 * Sibling escape hatch to `OpenDisputeButton` — same subordinate inline
 * style (small text link with icon, not a primary button), since the
 * cooperative confirm path is the intended default. The pair sits side
 * by side at the bottom of the Pending action card.
 *
 * Per-user 30-min cooldown after a rejected request is reflected here
 * via the `cooldownMinutesRemaining` prop — parent computes it from
 * `match.cancellation.rejected_at` so the value is fresh on every
 * render without a separate clock subscription.
 *
 * The reason flow is hybrid: a short radio list of preset reasons +
 * "Other" with an optional free-text fallback. Hybrid trades off:
 *
 *   - Discoverability (users don't have to think of a reason from scratch)
 *   - Speed (one click vs typing for 95% of cases)
 *   - Mild abuse mitigation (preset paths can't carry URLs / handles)
 *   - Flexibility (Other catches the long tail without forcing rigidity)
 *
 * Whitespace-only "Other" text normalizes to `null` server-side
 * (`RequestCancellationRequest::prepareForValidation`); when Other is
 * picked with no text, the submitted value is the literal string
 * "Other" so the opponent sees an intent indicator instead of a blank.
 */
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
        if (!canSubmit) return;

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
                className="inline-flex cursor-pointer items-center gap-1.5 rounded-sm text-xs text-muted-foreground underline-offset-4 transition-colors hover:text-foreground hover:underline focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-60 disabled:hover:no-underline disabled:hover:text-muted-foreground"
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
                            accepts. If they decline, the match continues
                            and you'll wait 30 minutes before you can
                            request again.
                        </DialogDescription>
                    </DialogHeader>

                    <fieldset
                        className="flex flex-col gap-2 py-2"
                        disabled={processing}
                    >
                        <legend className="text-foreground mb-1 text-sm font-medium">
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
                                />
                                <p className="text-muted-foreground text-right text-xs tabular-nums">
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

/**
 * Stakly-skinned radio row using a native `<input type="radio">` for
 * full keyboard + screen-reader semantics. Avoids pulling in a new
 * shadcn primitive for a single feature; we can swap to a shared
 * `RadioGroup` later if more places need radios.
 *
 * Selected state: primary tint + ring (mirrors the toggle / select
 * patterns elsewhere). Hover: soft primary wash. Focus-within: visible
 * ring on the surrounding label so keyboard nav reads cleanly.
 */
function ReasonOption({ label, checked, onSelect }: ReasonOptionProps) {
    return (
        <label
            className={cn(
                'flex cursor-pointer items-center gap-3 rounded-lg border px-3 py-2.5 transition-colors',
                'focus-within:ring-2 focus-within:ring-primary/25',
                checked
                    ? 'border-primary/40 bg-primary/10'
                    : 'border-border/60 bg-card/40 hover:border-primary/30 hover:bg-primary/5',
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
                {checked && (
                    <span className="bg-primary size-2 rounded-full" />
                )}
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
