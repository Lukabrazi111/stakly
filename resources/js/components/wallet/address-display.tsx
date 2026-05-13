import { Check, Copy } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';

interface Props {
    address: string;
    /** Render a shortened middle-ellipsis form. Full address still copies. */
    truncate?: boolean;
    className?: string;
}

/**
 * Displays a wallet address with a one-click copy button.
 *
 * Always copies the full address regardless of how it's rendered. The 2-second
 * `copied` state gives optimistic confirmation; the Sonner toast is the
 * authoritative success cue.
 */
export function AddressDisplay({ address, truncate = false, className = '' }: Props) {
    const [copied, setCopied] = useState(false);

    const handleCopy = async () => {
        try {
            await navigator.clipboard.writeText(address);
            setCopied(true);
            toast.success('Address copied');
            setTimeout(() => setCopied(false), 2000);
        } catch {
            toast.error('Could not copy — try selecting it manually.');
        }
    };

    const displayed = truncate && address.length > 14
        ? `${address.slice(0, 8)}…${address.slice(-8)}`
        : address;

    return (
        <div
            className={`border-border/60 bg-card/60 flex items-center gap-2 rounded-xl border p-3 ${className}`}
        >
            <code
                className="text-foreground flex-1 break-all font-mono text-sm select-all"
                title={address}
            >
                {displayed}
            </code>
            <Button
                type="button"
                variant="ghost"
                size="icon"
                onClick={handleCopy}
                aria-label={copied ? 'Address copied' : 'Copy address'}
                className="shrink-0"
            >
                {copied ? (
                    <Check className="text-success size-4" />
                ) : (
                    <Copy className="size-4" />
                )}
            </Button>
        </div>
    );
}
