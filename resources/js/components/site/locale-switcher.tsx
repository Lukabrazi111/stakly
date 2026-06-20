import { router, usePage } from '@inertiajs/react';
import { Check, Languages } from 'lucide-react';
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
                    variant={compact ? 'ghost' : 'outline'}
                    size={compact ? 'icon' : 'sm'}
                    aria-label="Language"
                    className={cn(
                        'cursor-pointer transition-colors duration-150 ease-out',
                        compact
                            ? 'size-10 rounded-full text-muted-foreground hover:bg-primary/10 hover:text-primary data-[state=open]:bg-primary/10 data-[state=open]:text-primary'
                            : 'rounded-full text-foreground data-[state=open]:border-primary/40 data-[state=open]:bg-primary/10 [&_svg]:text-muted-foreground hover:[&_svg]:text-primary data-[state=open]:[&_svg]:text-primary',
                    )}
                >
                    <Languages
                        className={compact ? 'size-5' : 'size-4'}
                        aria-hidden
                    />
                    <span className={cn(compact && 'sr-only')}>
                        {current?.native_label ?? locale.toUpperCase()}
                    </span>
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                align={align}
                sideOffset={8}
                className="w-48 overflow-hidden p-0"
            >
                {availableLocales.map((entry) => {
                    const isActive = entry.code === locale;

                    return (
                        <DropdownMenuItem
                            key={entry.code}
                            onSelect={() => switchTo(entry.code)}
                            className={cn(
                                'flex cursor-pointer items-center justify-between gap-2 rounded-none px-4 py-2.5 text-sm font-medium transition-colors duration-150 ease-out',
                                isActive
                                    ? 'bg-primary/25 text-foreground hover:bg-primary/25 focus:bg-primary/25'
                                    : 'text-muted-foreground hover:bg-primary/10 hover:text-foreground',
                            )}
                        >
                            <span className="truncate">
                                {entry.native_label}
                            </span>
                            {isActive && (
                                <Check
                                    className="size-4 shrink-0 text-primary"
                                    aria-hidden
                                />
                            )}
                        </DropdownMenuItem>
                    );
                })}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
