import { router, usePage } from '@inertiajs/react';
import { Languages } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';

/**
 * Swaps the leading `/{locale}/` segment of the current URL for the selected
 * locale and navigates. Cookie persistence is handled server-side: the
 * `SetLocale` middleware queues the `stakly_locale` cookie on every
 * locale-prefixed request, so the user's choice survives the redirect
 * middleware on subsequent unprefixed visits.
 */
interface Props {
    align?: 'start' | 'end';
    /** Render in a compact (icon-only) form. Used by the desktop header
     *  alongside other right-side controls; the mobile menu uses the
     *  expanded form with the native label visible at all sizes. */
    compact?: boolean;
}

export function LocaleSwitcher({ align = 'end', compact = false }: Props) {
    const { url, props } = usePage();
    const { locale, availableLocales } = props;

    const switchTo = (next: string) => {
        if (next === locale) {
            return;
        }

        const nextUrl = url.replace(/^\/[a-z]{2,3}(?=\/|$)/, `/${next}`);

        router.visit(nextUrl, { preserveScroll: true });
    };

    const current = availableLocales.find((entry) => entry.code === locale);

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="sm"
                    aria-label="Language"
                    className={cn(
                        'inline-flex items-center gap-2 text-muted-foreground hover:text-foreground',
                        compact && 'px-2',
                    )}
                >
                    <Languages className="size-4" aria-hidden />
                    <span className={cn(compact && 'sr-only')}>
                        {current?.native_label ?? locale.toUpperCase()}
                    </span>
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align={align} className="min-w-[10rem]">
                {availableLocales.map((entry) => (
                    <DropdownMenuItem
                        key={entry.code}
                        onSelect={() => switchTo(entry.code)}
                        className={cn(
                            'cursor-pointer',
                            entry.code === locale &&
                                'bg-primary/15 text-foreground',
                        )}
                    >
                        {entry.native_label}
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
