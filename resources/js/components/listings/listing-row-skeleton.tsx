import { Skeleton } from '@/components/ui/skeleton';

/** Silhouette of `ListingRow` for in-flight states. */
export function ListingRowSkeleton() {
    return (
        <div
            aria-hidden
            className="flex flex-col gap-4 rounded-2xl border border-border/60 bg-card/60 p-4 md:flex-row md:items-center md:gap-6 md:p-5"
        >
            <div className="flex min-w-0 items-center gap-3 md:w-48 md:shrink-0">
                <Skeleton className="size-11 shrink-0 rounded-full" />
                <div className="flex min-w-0 flex-1 flex-col gap-1.5">
                    <Skeleton className="h-3.5 w-24" />
                    <Skeleton className="h-3 w-32" />
                </div>
            </div>

            <div className="flex flex-wrap items-center gap-2 md:flex-1">
                <Skeleton className="h-5 w-16 rounded-full" />
                <Skeleton className="h-6 w-24 rounded-full" />
                <Skeleton className="h-6 w-20 rounded-full" />
                <Skeleton className="h-6 w-24 rounded-full" />
            </div>

            <div className="md:flex md:w-28 md:shrink-0 md:justify-end">
                <Skeleton className="h-4 w-20" />
            </div>

            <div className="md:flex md:w-32 md:shrink-0 md:justify-end">
                <Skeleton className="h-7 w-20" />
            </div>

            <div className="md:flex md:w-44 md:shrink-0 md:justify-end">
                <Skeleton className="h-9 w-24 rounded-full" />
            </div>
        </div>
    );
}
