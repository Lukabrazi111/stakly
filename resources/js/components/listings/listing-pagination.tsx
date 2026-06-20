import { router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useT } from '@/lib/i18n';
import { buildListingsQuery } from '@/lib/listings-query';
import { index as listingsIndex } from '@/routes/listings';
import type { ListingFilters } from '@/types';

interface Props {
    currentPage: number;
    lastPage: number;
    filters: ListingFilters;
}

const ELLIPSIS = '…';

/** Pages with ellipsis: always shows page 1, last, current, and ±1 of current.
 *  e.g. current=5, last=20 → [1, …, 4, 5, 6, …, 20]. */
function visiblePages(
    current: number,
    last: number,
): (number | typeof ELLIPSIS)[] {
    if (last <= 7) {
        return Array.from({ length: last }, (_, i) => i + 1);
    }

    const pages: (number | typeof ELLIPSIS)[] = [1];

    const start = Math.max(2, current - 1);
    const end = Math.min(last - 1, current + 1);

    if (start > 2) {
        pages.push(ELLIPSIS);
    }

    for (let i = start; i <= end; i++) {
        pages.push(i);
    }

    if (end < last - 1) {
        pages.push(ELLIPSIS);
    }

    pages.push(last);

    return pages;
}

export function ListingPagination({ currentPage, lastPage, filters }: Props) {
    const t = useT();

    if (lastPage <= 1) {
        return null;
    }

    const goToPage = (page: number) => {
        if (page < 1 || page > lastPage || page === currentPage) {
            return;
        }

        router.get(listingsIndex().url, buildListingsQuery(filters, { page }), {
            preserveState: true,
            preserveScroll: false,
        });
    };

    const pages = visiblePages(currentPage, lastPage);

    return (
        <nav
            aria-label={t('Pagination')}
            className="mt-8 flex flex-wrap items-center justify-center gap-1.5"
        >
            <PageButton
                aria-label={t('Previous page')}
                disabled={currentPage === 1}
                onClick={() => goToPage(currentPage - 1)}
            >
                <ChevronLeft className="size-4" />
            </PageButton>

            {pages.map((page, idx) =>
                page === ELLIPSIS ? (
                    <span
                        key={`ellipsis-${idx}`}
                        aria-hidden
                        className="inline-flex h-9 w-9 items-center justify-center text-sm text-muted-foreground"
                    >
                        {ELLIPSIS}
                    </span>
                ) : (
                    <PageButton
                        key={page}
                        aria-label={t('Page :page', { page })}
                        aria-current={page === currentPage ? 'page' : undefined}
                        active={page === currentPage}
                        onClick={() => goToPage(page)}
                    >
                        {page}
                    </PageButton>
                ),
            )}

            <PageButton
                aria-label={t('Next page')}
                disabled={currentPage === lastPage}
                onClick={() => goToPage(currentPage + 1)}
            >
                <ChevronRight className="size-4" />
            </PageButton>
        </nav>
    );
}

interface PageButtonProps extends React.ButtonHTMLAttributes<HTMLButtonElement> {
    active?: boolean;
}

function PageButton({
    active = false,
    className,
    children,
    ...props
}: PageButtonProps) {
    const base =
        'inline-flex h-9 min-w-9 cursor-pointer items-center justify-center rounded-full px-3 text-sm font-medium transition-colors duration-150 ease-out focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/25 focus-visible:ring-offset-2 focus-visible:ring-offset-background disabled:cursor-not-allowed disabled:opacity-40';

    const stateClasses = active
        ? 'border-primary/40 bg-primary/15 text-foreground border'
        : 'border-border/60 bg-card/60 text-muted-foreground hover:bg-primary/10 hover:text-foreground border';

    return (
        <button
            type="button"
            className={`${base} ${stateClasses} ${className ?? ''}`}
            {...props}
        >
            {children}
        </button>
    );
}
