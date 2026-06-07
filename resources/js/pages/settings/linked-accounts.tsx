import { Form } from '@inertiajs/react';
import {
    Check,
    Copy,
    ExternalLink,
    Link2,
    RotateCcw,
    ShieldCheck,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import LinkedAccountController from '@/actions/App/Http/Controllers/Settings/LinkedAccountController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { PageMeta } from '@/components/site/page-meta';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useT } from '@/lib/i18n';
import { unlink } from '@/routes/linked-accounts';

// Deep links into each provider's profile-edit page so the user lands on the
// exact screen where the target field lives. Opens in a new tab so the
// Stakly settings page stays put behind it.
const PROVIDER_PROFILE_URL: Record<string, string> = {
    chess_com: 'https://www.chess.com/settings',
    lichess: 'https://lichess.org/account/profile',
};

interface Provider {
    value: string;
    displayName: string;
    username: string | null;
    verifiedAt: string | null;
    verificationType: 'bio_code' | 'oauth';
    // Bio-code providers only (chess.com, Lichess).
    targetFieldLabel?: string;
    targetFieldInstructions?: string;
    // OAuth providers only (FACEIT).
    oauthRedirectUrl?: string;
}

interface PendingVerification {
    provider: string;
    username: string;
    code: string;
    expiresAt: string;
}

interface Props {
    providers: Provider[];
    pending: PendingVerification | null;
}

export default function LinkedAccountsPage({ providers, pending }: Props) {
    const t = useT();

    return (
        <>
            <PageMeta
                title={t('Linked accounts')}
                description={t(
                    'Manage linked chess.com, Lichess, and FACEIT accounts.',
                )}
                noindex
            />

            <h1 className="sr-only">{t('Linked accounts')}</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('Linked game accounts')}
                    description={t(
                        'Verify ownership of your chess.com, Lichess, and FACEIT accounts. Required before you can create or take a listing on the matching platform.',
                    )}
                />

                <div className="space-y-3">
                    {providers.map((provider) => (
                        <ProviderRow
                            key={provider.value}
                            provider={provider}
                            pending={pending}
                        />
                    ))}
                </div>
            </div>
        </>
    );
}

function ProviderRow({
    provider,
    pending,
}: {
    provider: Provider;
    pending: PendingVerification | null;
}) {
    const t = useT();
    const isVerified = provider.verifiedAt !== null;
    const isOAuth = provider.verificationType === 'oauth';
    const isPending = pending?.provider === provider.value;

    return (
        <article className="rounded-xl border border-border/60 bg-card/60 p-4 md:p-5">
            <header className="mb-3 flex items-center gap-2">
                <Link2 className="size-4 text-muted-foreground" />
                <h3 className="font-display text-base font-semibold text-foreground">
                    {provider.displayName}
                </h3>
                {isVerified && (
                    <span className="ml-auto inline-flex items-center gap-1 rounded-full border border-success/30 bg-success/15 px-2.5 py-0.5 text-xs font-medium text-success">
                        <ShieldCheck className="size-3" />
                        {t('Linked')}
                    </span>
                )}
            </header>

            {isVerified ? (
                <VerifiedRow provider={provider} />
            ) : isOAuth ? (
                <OAuthLinkButton provider={provider} />
            ) : isPending ? (
                <PendingRow provider={provider} pending={pending!} />
            ) : (
                <RequestForm provider={provider} />
            )}
        </article>
    );
}

function OAuthLinkButton({ provider }: { provider: Provider }) {
    const t = useT();

    return (
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <p className="text-sm text-muted-foreground">
                {t(
                    'Sign in with :provider to link your account. We use this to verify match outcomes and your skill rating.',
                    { provider: provider.displayName },
                )}
            </p>
            <Button asChild variant="gradient">
                <a
                    href={provider.oauthRedirectUrl ?? '#'}
                    data-test={`link-${provider.value}-button`}
                >
                    {t('Link :provider', { provider: provider.displayName })}
                </a>
            </Button>
        </div>
    );
}

function VerifiedRow({ provider }: { provider: Provider }) {
    const t = useT();
    const [confirmOpen, setConfirmOpen] = useState(false);

    return (
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div className="space-y-1">
                <p className="text-xs tracking-wide text-muted-foreground uppercase">
                    {t('Username')}
                </p>
                <code className="font-mono text-sm text-foreground">
                    {provider.username}
                </code>
            </div>
            <Dialog open={confirmOpen} onOpenChange={setConfirmOpen}>
                <DialogTrigger asChild>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="text-muted-foreground hover:bg-destructive/10 hover:text-destructive hover:[text-shadow:none]"
                    >
                        {t('Unlink')}
                    </Button>
                </DialogTrigger>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {t('Unlink :provider?', {
                                provider: provider.displayName,
                            })}
                        </DialogTitle>
                        <DialogDescription>
                            {t(
                                "You'll need to redo the bio-code verification on :provider before you can create or take :provider listings again. Stakly only removes your verified link — your :provider account itself isn't touched.",
                                { provider: provider.displayName },
                            )}
                        </DialogDescription>
                    </DialogHeader>
                    <Form
                        action={unlink({ provider: provider.value }).url}
                        method="delete"
                        options={{ preserveScroll: true }}
                        onSuccess={() => setConfirmOpen(false)}
                    >
                        {({ processing }) => (
                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={() => setConfirmOpen(false)}
                                >
                                    {t('Keep linked')}
                                </Button>
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    disabled={processing}
                                >
                                    {processing ? t('Unlinking…') : t('Unlink')}
                                </Button>
                            </DialogFooter>
                        )}
                    </Form>
                </DialogContent>
            </Dialog>
        </div>
    );
}

function PendingRow({
    provider,
    pending,
}: {
    provider: Provider;
    pending: PendingVerification;
}) {
    const t = useT();
    const [copied, setCopied] = useState(false);

    const handleCopy = async () => {
        try {
            await navigator.clipboard.writeText(pending.code);
            setCopied(true);
            toast.success(t('Code copied'));
            setTimeout(() => setCopied(false), 2000);
        } catch {
            toast.error(t('Could not copy — try selecting it manually.'));
        }
    };

    const providerUrl = PROVIDER_PROFILE_URL[provider.value];

    return (
        <div className="space-y-4">
            <p className="text-sm text-muted-foreground">
                {t('Linking')}{' '}
                <code className="font-mono text-foreground">
                    {pending.username}
                </code>
                . {t('Paste this code into your')}{' '}
                <span className="font-medium text-foreground">
                    {provider.targetFieldLabel}
                </span>{' '}
                {t(
                    'field on :provider (:instructions), save it there, then come back and verify. After we verify, you can safely remove the code from your profile — we only check it once.',
                    {
                        provider: provider.displayName,
                        instructions: provider.targetFieldInstructions,
                    },
                )}
            </p>

            <div className="flex items-center gap-2 rounded-xl border border-primary/40 bg-primary/5 p-3">
                <code
                    className="flex-1 font-mono text-sm font-medium break-all text-foreground select-all"
                    title={pending.code}
                >
                    {pending.code}
                </code>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    onClick={handleCopy}
                    aria-label={copied ? t('Code copied') : t('Copy code')}
                    className="shrink-0"
                >
                    {copied ? (
                        <Check className="size-4 text-success" />
                    ) : (
                        <Copy className="size-4" />
                    )}
                </Button>
            </div>

            {providerUrl && (
                <a
                    href={providerUrl}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="inline-flex items-center gap-1.5 text-sm font-medium text-primary underline-offset-4 hover:text-primary/80 hover:underline"
                >
                    {t('Open :provider settings', {
                        provider: provider.displayName,
                    })}
                    <ExternalLink className="size-3.5" />
                </a>
            )}

            <div className="flex flex-wrap items-center gap-2">
                <Form
                    {...LinkedAccountController.update.form()}
                    options={{ preserveScroll: true }}
                >
                    {({ processing }) => (
                        <Button
                            type="submit"
                            disabled={processing}
                            data-test="verify-linked-account-button"
                        >
                            {t("I've pasted it — verify")}
                        </Button>
                    )}
                </Form>
                <Form
                    {...LinkedAccountController.cancelPending.form()}
                    options={{ preserveScroll: true }}
                >
                    {({ processing }) => (
                        <Button
                            type="submit"
                            variant="ghost"
                            size="sm"
                            disabled={processing}
                            className="text-muted-foreground hover:text-foreground hover:[text-shadow:none] hover:[&_svg]:!text-foreground"
                            data-test="cancel-pending-button"
                        >
                            <RotateCcw className="size-3.5" />
                            {t('Use a different username')}
                        </Button>
                    )}
                </Form>
            </div>
        </div>
    );
}

function RequestForm({ provider }: { provider: Provider }) {
    const t = useT();

    return (
        <Form
            {...LinkedAccountController.store.form()}
            options={{ preserveScroll: true }}
            className="flex flex-col gap-3 sm:flex-row sm:items-end"
        >
            {({ processing, errors }) => (
                <>
                    <input
                        type="hidden"
                        name="provider"
                        value={provider.value}
                    />
                    <div className="flex-1 space-y-1.5">
                        <Label htmlFor={`username-${provider.value}`}>
                            {t(':provider username', {
                                provider: provider.displayName,
                            })}
                        </Label>
                        <Input
                            id={`username-${provider.value}`}
                            name="username"
                            placeholder={t('Your :provider handle', {
                                provider: provider.displayName,
                            })}
                            autoComplete="off"
                            spellCheck={false}
                            required
                        />
                        <InputError message={errors.username} />
                    </div>
                    <Button
                        type="submit"
                        disabled={processing}
                        data-test={`link-${provider.value}-button`}
                    >
                        {t('Generate code')}
                    </Button>
                </>
            )}
        </Form>
    );
}
