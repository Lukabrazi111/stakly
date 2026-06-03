import { Link, router, useForm, usePage } from '@inertiajs/react';
import { CameraIcon, CheckCircle2, MailWarning } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { ChangeEvent, FormEvent } from 'react';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import InputError from '@/components/input-error';
import { AvatarCropModal } from '@/components/settings/avatar-crop-modal';
import { ProfilePreview } from '@/components/settings/profile-preview';
import { PageMeta } from '@/components/site/page-meta';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useInitials } from '@/hooks/use-initials';
import { show as userShow } from '@/routes/users';
import { send } from '@/routes/verification';

const BIO_MAX = 500;

// Cap the *original* image the user can feed into the cropper — well above
// any realistic phone photo (typical 12 MP JPEG = 3-5 MB). The actual upload
// is the cropped 512×512 JPEG (~50-100 KB), unrelated to this cap; we only
// guard against a 100 MB file blowing up FileReader / canvas memory.
const RAW_FILE_MAX_BYTES = 20 * 1024 * 1024;

export default function Profile({
    mustVerifyEmail,
    status,
}: {
    mustVerifyEmail: boolean;
    status?: string;
}) {
    const { auth } = usePage().props;
    const user = auth.user!;
    const getInitials = useInitials();

    const { data, setData, post, processing, errors, reset } = useForm<{
        name: string;
        username: string;
        email: string;
        bio: string;
        avatar: Blob | null;
        _method: 'patch';
    }>({
        name: user.name,
        username: user.username,
        email: user.email,
        bio: user.bio ?? '',
        avatar: null,
        // Method-spoof so Inertia POSTs a multipart body Laravel translates
        // back into a PATCH (browsers can't send PATCH with multipart/form-data).
        _method: 'patch',
    });

    const [cropFile, setCropFile] = useState<File | null>(null);
    const [cropOpen, setCropOpen] = useState(false);
    const [previewUrl, setPreviewUrl] = useState<string | null>(null);
    const [rawFileError, setRawFileError] = useState<string | null>(null);
    const [isRemovingAvatar, setIsRemovingAvatar] = useState(false);
    const [removeConfirmOpen, setRemoveConfirmOpen] = useState(false);
    const [usernameConfirmOpen, setUsernameConfirmOpen] = useState(false);
    const fileInputRef = useRef<HTMLInputElement | null>(null);

    const usernameEdit = user.username_edit;
    const usernameBlocker = usernameEdit.blockers[0] ?? null;
    const usernameDirty = data.username.trim() !== user.username;

    // Revoke the cropped-blob object URL on unmount or when it's replaced —
    // otherwise the blob stays alive in memory for the page's lifetime.
    useEffect(() => {
        return () => {
            if (previewUrl) {
                URL.revokeObjectURL(previewUrl);
            }
        };
    }, [previewUrl]);

    const handleFilePicked = (e: ChangeEvent<HTMLInputElement>) => {
        const picked = e.target.files?.[0];
        // Reset the input value so picking the SAME file twice in a row still
        // re-fires `onChange`. Otherwise the second pick is a no-op.
        e.target.value = '';
        setRawFileError(null);

        if (!picked) {
            return;
        }

        if (picked.size > RAW_FILE_MAX_BYTES) {
            setRawFileError(
                'That image is too large to process. Pick a file under 20 MB.',
            );

            return;
        }

        setCropFile(picked);
        setCropOpen(true);
    };

    const handleCropConfirm = (blob: Blob) => {
        if (previewUrl) {
            URL.revokeObjectURL(previewUrl);
        }

        setPreviewUrl(URL.createObjectURL(blob));
        setData('avatar', blob);
    };

    const handleCropClose = () => {
        setCropOpen(false);
        setCropFile(null);
    };

    const handleRemoveAvatar = () => {
        setIsRemovingAvatar(true);
        router.delete(ProfileController.destroyAvatar.url(), {
            preserveScroll: true,
            onFinish: () => {
                setIsRemovingAvatar(false);
                setRemoveConfirmOpen(false);
            },
        });
    };

    const submitForm = () => {
        post(ProfileController.update.url(), {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                if (previewUrl) {
                    URL.revokeObjectURL(previewUrl);
                }

                setPreviewUrl(null);
                reset('avatar');
                setUsernameConfirmOpen(false);
            },
        });
    };

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();

        if (usernameDirty && usernameEdit.can_change) {
            setUsernameConfirmOpen(true);

            return;
        }

        submitForm();
    };

    const displayAvatarSrc = previewUrl ?? user.avatar_url ?? undefined;
    const bioCount = data.bio.length;
    const bioOverCap = bioCount > BIO_MAX;
    const isEmailVerified = user.email_verified_at !== null;
    // The middleware eager-loads `linkedAccounts` so `user.linked_accounts`
    // is reliably present at runtime; the `?? []` is belt-and-braces.
    const linkedAccountsForPreview = (user.linked_accounts ?? []).map(
        (account) => ({
            provider: account.provider,
            username: account.username,
        }),
    );
    const publicProfileUrl = userShow(user.username).url;

    return (
        <>
            <PageMeta
                title="Profile settings"
                description="Edit your profile details."
                noindex
            />

            <h1 className="sr-only">Profile settings</h1>

            <div className="space-y-6">
                <ProfilePreview
                    name={data.name}
                    username={data.username || user.username}
                    bio={data.bio}
                    avatarSrc={displayAvatarSrc}
                    joinedAt={user.created_at}
                    linkedAccounts={linkedAccountsForPreview}
                    profileUrl={publicProfileUrl}
                />

                <form onSubmit={handleSubmit} className="space-y-6">
                    {/* Section: Public profile — fields visible to other
                        players. Visually scaffolded as a card to match the
                        rest of the redesign (listing detail / wallet index
                        sections). */}
                    <section className="space-y-6 rounded-2xl border border-border/60 bg-card p-6">
                        <header>
                            <h2 className="font-display text-base font-semibold text-foreground">
                                Public profile
                            </h2>
                            <p className="mt-0.5 text-xs text-muted-foreground">
                                What other players see on your profile page +
                                listings.
                            </p>
                        </header>

                        {/* Avatar — click the avatar tile or "Change" to open
                            the file picker; the picked file flows into the
                            crop modal, and the cropped blob lives in
                            `data.avatar`. */}
                        <div className="grid gap-3">
                            <Label>Avatar</Label>
                            <div className="flex items-center gap-5">
                                <button
                                    type="button"
                                    onClick={() =>
                                        fileInputRef.current?.click()
                                    }
                                    className="group relative cursor-pointer rounded-full transition-shadow duration-200 ease-out hover:shadow-glow focus-visible:shadow-glow focus-visible:outline-none"
                                    aria-label="Change avatar"
                                >
                                    <Avatar className="size-20 overflow-hidden rounded-full ring-2 ring-border/60 transition-colors duration-200 ease-out group-hover:ring-primary/50 group-focus-visible:ring-primary/60">
                                        <AvatarImage
                                            src={displayAvatarSrc}
                                            alt={user.name}
                                        />
                                        <AvatarFallback className="bg-gradient-primary text-2xl font-semibold text-primary-foreground">
                                            {getInitials(user.name)}
                                        </AvatarFallback>
                                    </Avatar>
                                    <span className="absolute inset-0 flex items-center justify-center rounded-full bg-background/70 opacity-0 backdrop-blur-sm transition-opacity duration-200 ease-out group-hover:opacity-100 group-focus-visible:opacity-100">
                                        <CameraIcon className="size-6 text-foreground" />
                                    </span>
                                </button>
                                <div className="text-sm">
                                    <div className="flex items-center gap-1">
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                fileInputRef.current?.click()
                                            }
                                        >
                                            Change avatar
                                        </Button>
                                        {user.avatar_url && !previewUrl && (
                                            <Dialog
                                                open={removeConfirmOpen}
                                                onOpenChange={
                                                    setRemoveConfirmOpen
                                                }
                                            >
                                                <DialogTrigger asChild>
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        className="text-destructive [text-shadow:none] hover:text-destructive hover:[text-shadow:none]"
                                                    >
                                                        Remove
                                                    </Button>
                                                </DialogTrigger>
                                                <DialogContent>
                                                    <DialogHeader>
                                                        <DialogTitle>
                                                            Remove avatar?
                                                        </DialogTitle>
                                                        <DialogDescription>
                                                            Your profile will go
                                                            back to showing your
                                                            initials. You can
                                                            upload a new avatar
                                                            any time.
                                                        </DialogDescription>
                                                    </DialogHeader>
                                                    <DialogFooter>
                                                        <DialogClose asChild>
                                                            <Button
                                                                type="button"
                                                                variant="ghost"
                                                                disabled={
                                                                    isRemovingAvatar
                                                                }
                                                            >
                                                                Cancel
                                                            </Button>
                                                        </DialogClose>
                                                        <Button
                                                            type="button"
                                                            variant="destructive"
                                                            onClick={
                                                                handleRemoveAvatar
                                                            }
                                                            disabled={
                                                                isRemovingAvatar
                                                            }
                                                        >
                                                            {isRemovingAvatar
                                                                ? 'Removing…'
                                                                : 'Remove'}
                                                        </Button>
                                                    </DialogFooter>
                                                </DialogContent>
                                            </Dialog>
                                        )}
                                    </div>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        JPG, PNG, or WebP. Max 2 MB after crop.
                                    </p>
                                </div>
                            </div>
                            <input
                                ref={fileInputRef}
                                type="file"
                                accept="image/jpeg,image/png,image/webp"
                                className="hidden"
                                onChange={handleFilePicked}
                            />
                            <InputError
                                message={rawFileError ?? errors.avatar}
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="username">Username</Label>
                            <Input
                                id="username"
                                className="block w-full"
                                value={data.username}
                                onChange={(e) =>
                                    setData(
                                        'username',
                                        e.target.value.toLowerCase(),
                                    )
                                }
                                name="username"
                                required
                                autoComplete="off"
                                spellCheck={false}
                                disabled={!usernameEdit.can_change}
                                placeholder="your-handle"
                            />
                            <UsernameHelper
                                blocker={usernameBlocker}
                                availableAt={usernameEdit.available_at}
                            />
                            <InputError message={errors.username} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="name">Name</Label>
                            <Input
                                id="name"
                                className="block w-full"
                                value={data.name}
                                onChange={(e) =>
                                    setData('name', e.target.value)
                                }
                                name="name"
                                required
                                autoComplete="name"
                                placeholder="Full name"
                            />
                            <InputError message={errors.name} />
                        </div>

                        <div className="grid gap-2">
                            <div className="flex items-baseline justify-between">
                                <Label htmlFor="bio">Bio</Label>
                                <span
                                    className={
                                        bioOverCap
                                            ? 'text-xs text-destructive'
                                            : 'text-xs text-muted-foreground'
                                    }
                                    aria-live="polite"
                                >
                                    {bioCount} / {BIO_MAX}
                                </span>
                            </div>
                            <Textarea
                                id="bio"
                                name="bio"
                                value={data.bio}
                                onChange={(e) => setData('bio', e.target.value)}
                                placeholder="Tell other players a bit about yourself…"
                                maxLength={BIO_MAX}
                                rows={4}
                            />
                            <InputError message={errors.bio} />
                        </div>
                    </section>

                    {/* Section: Account — private fields. Email is what we
                        use to reach the user; not displayed publicly. */}
                    <section className="space-y-6 rounded-2xl border border-border/60 bg-card p-6">
                        <header>
                            <h2 className="font-display text-base font-semibold text-foreground">
                                Account
                            </h2>
                            <p className="mt-0.5 text-xs text-muted-foreground">
                                Private — used for sign-in and notifications.
                            </p>
                        </header>

                        <div className="grid gap-2">
                            <div className="flex items-center justify-between gap-3">
                                <Label htmlFor="email">Email address</Label>
                                {isEmailVerified ? (
                                    <span className="inline-flex shrink-0 items-center gap-1 rounded-full border border-success/30 bg-success/10 px-2 py-0.5 text-[11px] font-medium text-success">
                                        <CheckCircle2
                                            className="size-3"
                                            aria-hidden="true"
                                        />
                                        Verified
                                    </span>
                                ) : (
                                    <span className="inline-flex shrink-0 items-center gap-1 rounded-full border border-warning/40 bg-warning/10 px-2 py-0.5 text-[11px] font-medium text-warning">
                                        <MailWarning
                                            className="size-3"
                                            aria-hidden="true"
                                        />
                                        Unverified
                                    </span>
                                )}
                            </div>
                            <Input
                                id="email"
                                type="email"
                                className="block w-full"
                                value={data.email}
                                onChange={(e) =>
                                    setData('email', e.target.value)
                                }
                                name="email"
                                required
                                autoComplete="username"
                                placeholder="Email address"
                            />
                            <InputError message={errors.email} />

                            {mustVerifyEmail && !isEmailVerified && (
                                <p className="text-xs text-muted-foreground">
                                    <Link
                                        href={send()}
                                        as="button"
                                        className="text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current dark:decoration-neutral-500"
                                    >
                                        Resend verification email
                                    </Link>
                                    {status === 'verification-link-sent' && (
                                        <span className="ml-2 text-success">
                                            Sent — check your inbox.
                                        </span>
                                    )}
                                </p>
                            )}
                        </div>
                    </section>

                    <div className="flex flex-wrap items-center gap-3">
                        <Button
                            type="submit"
                            variant="gradient"
                            size="pill"
                            disabled={processing}
                            data-test="update-profile-button"
                        >
                            {processing ? 'Saving…' : 'Save changes'}
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            size="pill"
                            asChild
                            className="rounded-full"
                        >
                            <Link href={publicProfileUrl}>
                                View public profile
                            </Link>
                        </Button>
                    </div>
                </form>
            </div>

            <AvatarCropModal
                file={cropFile}
                open={cropOpen}
                onClose={handleCropClose}
                onConfirm={handleCropConfirm}
            />

            <Dialog
                open={usernameConfirmOpen}
                onOpenChange={(next) => {
                    if (!processing) {
                        setUsernameConfirmOpen(next);
                    }
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Change your username?</DialogTitle>
                        <DialogDescription>
                            Renaming{' '}
                            <span className="font-medium text-foreground">
                                {user.username}
                            </span>{' '}
                            to{' '}
                            <span className="font-medium text-foreground">
                                {data.username}
                            </span>{' '}
                            also updates your profile URL. You won't be able to
                            change it again for 30 days.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => setUsernameConfirmOpen(false)}
                            disabled={processing}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            variant="gradient"
                            size="pill"
                            onClick={submitForm}
                            disabled={processing}
                        >
                            {processing ? 'Saving…' : 'Confirm change'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

function UsernameHelper({
    blocker,
    availableAt,
}: {
    blocker: 'cooldown' | 'in_flight_match' | null;
    availableAt: string | null;
}) {
    if (blocker === 'cooldown' && availableAt) {
        const date = new Date(availableAt).toLocaleDateString('en-US', {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
        });

        return (
            <p className="text-xs text-muted-foreground">
                You can change it again on {date}.
            </p>
        );
    }

    if (blocker === 'in_flight_match') {
        return (
            <p className="text-xs text-muted-foreground">
                You can't change your username while you have a match in
                progress or an open dispute.
            </p>
        );
    }

    return (
        <p className="text-xs text-muted-foreground">
            Lowercase letters, numbers, and hyphens. 3–30 characters. Changing
            it locks the field for 30 days.
        </p>
    );
}
