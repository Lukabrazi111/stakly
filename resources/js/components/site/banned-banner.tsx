import { Ban } from 'lucide-react';

interface Props {
    reason: string;
}

const SUPPORT_EMAIL = 'support@stakly.com';

export function BannedBanner({ reason }: Props) {
    return (
        <div
            role="alert"
            aria-live="assertive"
            className="border-b border-destructive/40 bg-destructive/10"
        >
            <div className="mx-auto flex max-w-7xl flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-6 sm:py-3.5">
                <div className="flex items-start gap-3">
                    <span className="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-full bg-destructive/20 text-destructive sm:size-10">
                        <Ban className="size-5" aria-hidden="true" />
                    </span>
                    <div className="min-w-0 flex-1">
                        <p className="font-display text-sm font-semibold text-destructive sm:text-base">
                            Your account has been suspended
                        </p>
                        <p className="mt-0.5 text-xs leading-relaxed text-muted-foreground sm:text-sm">
                            <span className="text-foreground/80">Reason:</span>{' '}
                            {reason}
                        </p>
                    </div>
                </div>

                <a
                    href={`mailto:${SUPPORT_EMAIL}?subject=Account%20suspension%20appeal`}
                    className="inline-flex shrink-0 cursor-pointer items-center justify-center self-start rounded-full border border-destructive/40 bg-destructive/15 px-4 py-2 text-xs font-medium text-destructive transition-colors duration-200 hover:bg-destructive/25 hover:text-destructive focus-visible:ring-2 focus-visible:ring-destructive/40 focus-visible:outline-none sm:self-auto sm:text-sm"
                >
                    Contact support
                </a>
            </div>
        </div>
    );
}
