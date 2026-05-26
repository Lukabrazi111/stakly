import { cva, type VariantProps } from 'class-variance-authority';
import { Tabs as TabsPrimitive } from 'radix-ui';
import * as React from 'react';

import { cn } from '@/lib/utils';

// Stakly-skinned shadcn Tabs primitive. Diverges from the upstream defaults
// in three places:
//   1. `line` variant uses the brand pink for the active underline
//      (`after:bg-primary`) rather than `after:bg-foreground` — gives the
//      tab strip the same identity signal as our gradient CTAs.
//   2. `default` variant active fill is `bg-primary/15` instead of
//      `bg-background` (per CLAUDE.md's "Stakly-skin shadcn at the source"
//      rule — no `bg-accent` / `bg-input` leaking through).
//   3. Focus ring is `ring-2 ring-primary/25` not the upstream
//      `ring-[3px] ring-ring/50` (which reads as a bug on our dark theme).
//
// Dark-mode duplicates are dropped — Stakly is dark-only, so the `dark:*`
// modifiers in the upstream defaults never differ from the base value here.
function Tabs({
    className,
    orientation = 'horizontal',
    ...props
}: React.ComponentProps<typeof TabsPrimitive.Root>) {
    return (
        <TabsPrimitive.Root
            data-slot="tabs"
            data-orientation={orientation}
            orientation={orientation}
            className={cn(
                'group/tabs flex gap-2 data-[orientation=horizontal]:flex-col',
                className,
            )}
            {...props}
        />
    );
}

const tabsListVariants = cva(
    // No `justify-center` in the base — the `line` variant spans `w-full`,
    // and centering its triggers makes the strip look adrift on wide pages.
    // The `default` pill variant is `w-fit` so its content fills the box
    // either way; no need to add justify-center back for it.
    'group/tabs-list inline-flex items-center text-muted-foreground group-data-[orientation=vertical]/tabs:h-fit group-data-[orientation=vertical]/tabs:flex-col',
    {
        variants: {
            variant: {
                // Pill-style list: card-tinted background, rounded.
                default:
                    'w-fit rounded-lg border border-border/60 bg-card p-[3px] group-data-[orientation=horizontal]/tabs:h-9',
                // Underline-style list: full-width strip with a subtle
                // bottom border that the active underline sits on top of.
                // Matches the Stakly convention from `MineTabs` so /listings/mine
                // and /users/{username} feel like one app.
                line: 'w-full gap-6 rounded-none border-b border-border/60 bg-transparent',
            },
        },
        defaultVariants: {
            variant: 'default',
        },
    },
);

function TabsList({
    className,
    variant = 'default',
    ...props
}: React.ComponentProps<typeof TabsPrimitive.List> &
    VariantProps<typeof tabsListVariants>) {
    return (
        <TabsPrimitive.List
            data-slot="tabs-list"
            data-variant={variant}
            className={cn(tabsListVariants({ variant }), className)}
            {...props}
        />
    );
}

function TabsTrigger({
    className,
    ...props
}: React.ComponentProps<typeof TabsPrimitive.Trigger>) {
    return (
        <TabsPrimitive.Trigger
            data-slot="tabs-trigger"
            className={cn(
                // Base — shared across variants.
                'relative inline-flex cursor-pointer items-center justify-center gap-1.5 text-sm font-medium whitespace-nowrap text-muted-foreground transition-colors duration-150 ease-out focus-visible:ring-2 focus-visible:ring-primary/25 focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none disabled:pointer-events-none disabled:opacity-50 hover:text-foreground group-data-[orientation=vertical]/tabs:w-full group-data-[orientation=vertical]/tabs:justify-start [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*=size-])]:size-4',
                // Default variant: pill-shaped active fill (Stakly pink wash).
                'group-data-[variant=default]/tabs-list:h-[calc(100%-1px)] group-data-[variant=default]/tabs-list:flex-1 group-data-[variant=default]/tabs-list:rounded-md group-data-[variant=default]/tabs-list:px-2 group-data-[variant=default]/tabs-list:py-1 group-data-[variant=default]/tabs-list:data-[state=active]:bg-primary/15 group-data-[variant=default]/tabs-list:data-[state=active]:text-foreground group-data-[variant=default]/tabs-list:hover:bg-primary/10',
                // Line variant: pink underline on active, no fill, no padding-x
                // fight. The `after:` element is the underline.
                'group-data-[variant=line]/tabs-list:relative group-data-[variant=line]/tabs-list:-mb-px group-data-[variant=line]/tabs-list:rounded-none group-data-[variant=line]/tabs-list:px-1 group-data-[variant=line]/tabs-list:py-3 group-data-[variant=line]/tabs-list:after:absolute group-data-[variant=line]/tabs-list:after:inset-x-0 group-data-[variant=line]/tabs-list:after:-bottom-px group-data-[variant=line]/tabs-list:after:h-[2px] group-data-[variant=line]/tabs-list:after:bg-primary group-data-[variant=line]/tabs-list:after:opacity-0 group-data-[variant=line]/tabs-list:after:transition-opacity group-data-[variant=line]/tabs-list:data-[state=active]:text-foreground group-data-[variant=line]/tabs-list:data-[state=active]:after:opacity-100',
                className,
            )}
            {...props}
        />
    );
}

function TabsContent({
    className,
    ...props
}: React.ComponentProps<typeof TabsPrimitive.Content>) {
    return (
        <TabsPrimitive.Content
            data-slot="tabs-content"
            className={cn('flex-1 outline-none', className)}
            {...props}
        />
    );
}

export { Tabs, TabsContent, TabsList, TabsTrigger, tabsListVariants };
