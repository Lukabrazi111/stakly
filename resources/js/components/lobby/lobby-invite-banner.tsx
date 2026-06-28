import { Check, Copy, Link2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useClipboard } from '@/hooks/use-clipboard';
import { useT } from '@/lib/i18n';
import { invite as lobbyInvite } from '@/routes/lobbies';

interface Props {
    inviteToken: string;
}

/**
 * Owner-only banner surfacing the private invite link on the lobby page.
 * Renders above the 3-col team grid so a creator who just opened a private
 * listing can grab the URL to share with teammates without hunting through
 * the page. Parent gates rendering on `lobby.invite_token !== null` AND a
 * live lobby state — the LobbyController invite endpoint 404s once the
 * lobby locks or expires, so the banner hides at that point too.
 */
export function LobbyInviteBanner({ inviteToken }: Props) {
    const t = useT();
    const [copiedText, copy] = useClipboard();
    const [origin, setOrigin] = useState('');

    useEffect(() => {
        setOrigin(window.location.origin);
    }, []);

    const path = lobbyInvite({ token: inviteToken }).url;
    const url = `${origin}${path}`;
    const isCopied = copiedText === url;

    return (
        <div className="rounded-xl border border-border/60 bg-card/60 p-4">
            <div className="flex items-start gap-3">
                <span className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                    <Link2 className="size-5" aria-hidden="true" />
                </span>
                <div className="flex-1 space-y-3">
                    <div>
                        <h3 className="font-display text-base font-semibold text-foreground">
                            {t('Private invite link')}
                        </h3>
                        <p className="text-xs text-muted-foreground">
                            {t(
                                'Only people with this link can see your lobby. Share it with teammates to recruit.',
                            )}
                        </p>
                    </div>
                    <div className="flex flex-col gap-2 sm:flex-row">
                        <input
                            type="text"
                            readOnly
                            value={url}
                            onClick={(event) =>
                                (event.target as HTMLInputElement).select()
                            }
                            className="flex-1 truncate rounded-md border border-border/60 bg-background/80 px-3 py-2 font-mono text-xs text-foreground focus-visible:border-primary/40 focus-visible:ring-2 focus-visible:ring-primary/25 focus-visible:outline-none"
                            aria-label={t('Invite link')}
                        />
                        <button
                            type="button"
                            onClick={() => copy(url)}
                            className="inline-flex shrink-0 cursor-pointer items-center justify-center gap-1.5 rounded-md border border-border/60 bg-card/60 px-3 py-2 text-xs font-medium text-foreground transition-colors hover:border-primary/40 hover:bg-primary/10 hover:text-primary"
                        >
                            {isCopied ? (
                                <>
                                    <Check
                                        className="size-3.5 text-success"
                                        aria-hidden="true"
                                    />
                                    {t('Copied')}
                                </>
                            ) : (
                                <>
                                    <Copy
                                        className="size-3.5"
                                        aria-hidden="true"
                                    />
                                    {t('Copy')}
                                </>
                            )}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
}
