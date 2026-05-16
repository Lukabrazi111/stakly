import { Skeleton } from '@/components/ui/skeleton';

/**
 * Silhouette of `MatchListRow` for in-flight (filter / page change) states.
 * Mirrors the table-row visual: no individual border, `border-t` for
 * separation inside the wrapping container.
 */
export function MatchListRowSkeleton() {
    return (
        <div
            aria-hidden
            className="border-border/40 flex flex-col gap-4 border-t px-4 py-4 first:border-t-0 md:flex-row md:items-center md:gap-6 md:px-5"
        >
            {/* Opponent */}
            <div className="flex min-w-0 items-center gap-3 md:w-52 md:shrink-0">
                <Skeleton className="size-10 shrink-0 rounded-full" />
                <div className="flex min-w-0 flex-1 flex-col gap-1.5">
                    <Skeleton className="h-3.5 w-24" />
                    <Skeleton className="h-3 w-20" />
                </div>
            </div>

            {/* Status + time-control badges */}
            <div className="flex flex-wrap items-center gap-2 md:flex-1">
                <Skeleton className="h-6 w-20 rounded-full" />
                <Skeleton className="h-6 w-16 rounded-full" />
                <Skeleton className="h-6 w-24 rounded-full" />
            </div>

            {/* Date */}
            <div className="md:flex md:w-20 md:shrink-0 md:justify-end">
                <Skeleton className="h-4 w-14" />
            </div>

            {/* Stake */}
            <div className="md:flex md:w-28 md:shrink-0 md:justify-end">
                <Skeleton className="h-7 w-20" />
            </div>
        </div>
    );
}
