import { Check, Copy } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';

interface Props {
    address: string;
    /** Render a shortened middle-ellipsis form. Full address still copies. */
    truncate?: boolean;
    className?: string;
}

/** Wallet address + one-click copy. Always copies the full address even
 *  when rendered truncated. */
export function AddressDisplay({
    address,
    truncate = false,
    className = '',
}: Props) {
    const t = useT();
    const [copied, setCopied] = useState(false);

    const handleCopy = async () => {
        try {
            await navigator.clipboard.writeText(address);
            setCopied(true);
            toast.success(t('Address copied'));
            setTimeout(() => setCopied(false), 2000);
        } catch {
            toast.error(t('Could not copy — try selecting it manually.'));
        }
    };

    const displayed =
        truncate && address.length > 14
            ? `${address.slice(0, 8)}…${address.slice(-8)}`
            : address;

    return (
        <div
            className={`flex items-center gap-2 rounded-xl border border-border/60 bg-card/60 p-3 ${className}`}
        >
            <code
                className="flex-1 font-mono text-sm break-all text-foreground select-all"
                title={address}
            >
                {displayed}
            </code>
            <Button
                type="button"
                variant="ghost"
                size="icon"
                onClick={handleCopy}
                aria-label={copied ? t('Address copied') : t('Copy address')}
                className="shrink-0"
            >
                {copied ? (
                    <Check className="size-4 text-success" />
                ) : (
                    <Copy className="size-4" />
                )}
            </Button>
        </div>
    );
}
