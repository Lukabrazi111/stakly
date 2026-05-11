import { Toaster as Sonner, type ToasterProps } from 'sonner';

function Toaster({ ...props }: ToasterProps) {
    return (
        <Sonner
            theme="dark"
            position="top-center"
            className="toaster group"
            toastOptions={{
                unstyled: false,
                classNames: {
                    toast: 'group toast border-border/60 bg-card/95 text-foreground rounded-xl border px-4 py-3 shadow-[0_12px_40px_-12px_rgba(0,0,0,0.8),0_0_60px_-12px_var(--gradient-glow),inset_0_1px_0_0_color-mix(in_srgb,var(--gradient-glow)_18%,transparent)] backdrop-blur-md',
                    title: 'text-foreground text-sm font-medium',
                    description: 'text-muted-foreground text-xs',
                    actionButton:
                        '!bg-primary !text-primary-foreground !rounded-full !px-3 !py-1 !text-xs !font-medium',
                    cancelButton:
                        '!bg-muted !text-muted-foreground !rounded-full !px-3 !py-1 !text-xs',
                    closeButton:
                        '!border-border !bg-card !text-muted-foreground hover:!text-foreground',
                    success:
                        '!border-success/50 [&_[data-icon]>svg]:!text-success',
                    error: '!border-destructive/50 [&_[data-icon]>svg]:!text-destructive',
                    warning:
                        '!border-warning/50 [&_[data-icon]>svg]:!text-warning',
                    info: '!border-accent/50 [&_[data-icon]>svg]:!text-accent',
                },
            }}
            style={
                {
                    '--normal-bg': 'var(--card)',
                    '--normal-text': 'var(--foreground)',
                    '--normal-border': 'var(--border)',
                } as React.CSSProperties
            }
            {...props}
        />
    );
}

export { Toaster };
