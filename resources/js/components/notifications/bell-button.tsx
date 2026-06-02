import { usePage } from '@inertiajs/react';
import { Bell } from 'lucide-react';
import { useState } from 'react';
import { BellDropdown } from '@/components/notifications/bell-dropdown';
import { useNotificationContext } from '@/components/notifications/notification-provider';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Sheet,
    SheetContent,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { useIsMobile } from '@/hooks/use-mobile';

export function BellButton() {
    const { auth } = usePage().props;
    const user = auth.user;
    const isMobile = useIsMobile();
    const [open, setOpen] = useState(false);
    const { unreadCount, clearUnread } = useNotificationContext();

    if (!user) {
        return null;
    }

    const showBadge = unreadCount > 0;
    const displayCount = unreadCount > 9 ? '9+' : String(unreadCount);

    const handleOpenChange = (next: boolean) => {
        setOpen(next);

        if (next && unreadCount > 0) {
            clearUnread();
        }
    };

    const trigger = (
        <Button
            variant="ghost"
            size="icon"
            aria-label={
                showBadge
                    ? `Notifications (${unreadCount} unread)`
                    : 'Notifications'
            }
            className="relative size-10 cursor-pointer rounded-full text-muted-foreground transition-colors duration-150 ease-out hover:bg-primary/10 hover:text-primary data-[state=open]:bg-primary/10 data-[state=open]:text-primary"
        >
            <Bell className="size-5" />
            {showBadge && (
                <span
                    aria-hidden
                    className="absolute -top-0.5 -right-0.5 flex h-5 min-w-5 items-center justify-center rounded-full bg-primary px-1.5 text-[10px] font-semibold text-primary-foreground"
                >
                    {displayCount}
                </span>
            )}
        </Button>
    );

    if (isMobile) {
        return (
            <Sheet open={open} onOpenChange={handleOpenChange}>
                <SheetTrigger asChild>{trigger}</SheetTrigger>
                <SheetContent
                    side="right"
                    className="w-full p-0 sm:max-w-md [&>button.absolute]:hidden"
                >
                    <SheetTitle className="sr-only">Notifications</SheetTitle>
                    <BellDropdown onClose={() => setOpen(false)} fullHeight />
                </SheetContent>
            </Sheet>
        );
    }

    return (
        <Popover open={open} onOpenChange={handleOpenChange}>
            <PopoverTrigger asChild>{trigger}</PopoverTrigger>
            <PopoverContent
                align="end"
                sideOffset={8}
                className="w-96 rounded-xl border border-border/60 bg-card p-0"
            >
                <BellDropdown onClose={() => setOpen(false)} />
            </PopoverContent>
        </Popover>
    );
}
