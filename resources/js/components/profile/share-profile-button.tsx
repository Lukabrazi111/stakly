import { Check, Copy, Share2 } from 'lucide-react';
import { QRCodeSVG } from 'qrcode.react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';

interface Props {
    profileUrl: string;
    username: string;
}

/**
 * Owner-only share-profile control. Renders a button that opens a popover
 * with the profile's absolute URL (copy-to-clipboard) + a QR code for
 * cross-device handoff (phone scans the QR on a desktop, lands on the
 * profile without retyping).
 *
 * Mirrors the wallet/deposit QR treatment (white card, level M) and
 * AddressDisplay's copy pattern (Sonner toast + 2-second optimistic
 * checkmark).
 */
export function ShareProfileButton({ profileUrl, username }: Props) {
    const [copied, setCopied] = useState(false);

    const handleCopy = async () => {
        try {
            await navigator.clipboard.writeText(profileUrl);
            setCopied(true);
            toast.success('Profile link copied');
            setTimeout(() => setCopied(false), 2000);
        } catch {
            toast.error('Could not copy — try selecting it manually.');
        }
    };

    return (
        <Popover>
            <PopoverTrigger asChild>
                <Button type="button" variant="outline" size="sm">
                    <Share2 className="size-4" aria-hidden="true" />
                    Share profile
                </Button>
            </PopoverTrigger>
            <PopoverContent className="w-80 space-y-4" align="start">
                <div>
                    <h3 className="font-display text-sm font-semibold text-foreground">
                        Share @{username}
                    </h3>
                    <p className="mt-1 text-xs text-muted-foreground">
                        Anyone with this link can view your public profile.
                    </p>
                </div>

                {/* QR on a forced-light card — phone cameras read the dark
                    squares better on white. Mirrors wallet/deposit QR. */}
                <div className="flex justify-center">
                    <div className="rounded-xl bg-white p-3 shadow-md">
                        <QRCodeSVG
                            value={profileUrl}
                            size={160}
                            level="M"
                            marginSize={0}
                        />
                    </div>
                </div>

                <div className="flex items-center gap-2 rounded-lg border border-border/60 bg-card/60 p-2">
                    <code
                        className="flex-1 truncate text-xs text-foreground select-all"
                        title={profileUrl}
                    >
                        {profileUrl}
                    </code>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        onClick={handleCopy}
                        aria-label={copied ? 'Link copied' : 'Copy link'}
                        className="size-8 shrink-0"
                    >
                        {copied ? (
                            <Check
                                className="size-3.5 text-success"
                                aria-hidden="true"
                            />
                        ) : (
                            <Copy className="size-3.5" aria-hidden="true" />
                        )}
                    </Button>
                </div>
            </PopoverContent>
        </Popover>
    );
}
