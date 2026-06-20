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
import { useT } from '@/lib/i18n';

interface Props {
    profileUrl: string;
    username: string;
}

/** Owner-only share button — popover with copyable URL + QR. */
export function ShareProfileButton({ profileUrl, username }: Props) {
    const t = useT();
    const [copied, setCopied] = useState(false);

    const handleCopy = async () => {
        try {
            await navigator.clipboard.writeText(profileUrl);
            setCopied(true);
            toast.success(t('Profile link copied'));
            setTimeout(() => setCopied(false), 2000);
        } catch {
            toast.error(t('Could not copy — try selecting it manually.'));
        }
    };

    return (
        <Popover>
            <PopoverTrigger asChild>
                <Button type="button" variant="outline" size="sm">
                    <Share2 className="size-4" aria-hidden="true" />
                    {t('Share profile')}
                </Button>
            </PopoverTrigger>
            <PopoverContent className="w-80 space-y-4" align="start">
                <div>
                    <h3 className="font-display text-sm font-semibold text-foreground">
                        {t('Share @:username', { username })}
                    </h3>
                    <p className="mt-1 text-xs text-muted-foreground">
                        {t(
                            'Anyone with this link can view your public profile.',
                        )}
                    </p>
                </div>

                {/* Forced-light QR — phone cameras read dark squares better on white. */}
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
                        aria-label={copied ? t('Link copied') : t('Copy link')}
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
