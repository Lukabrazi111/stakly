import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { CameraIcon } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { ChangeEvent, FormEvent } from 'react';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { AvatarCropModal } from '@/components/settings/avatar-crop-modal';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useInitials } from '@/hooks/use-initials';
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
        email: string;
        bio: string;
        avatar: Blob | null;
        _method: 'patch';
    }>({
        name: user.name,
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
    const fileInputRef = useRef<HTMLInputElement | null>(null);

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

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        post(ProfileController.update.url(), {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                // Avatar uploaded — clear the staged blob + preview so the
                // form goes back to a clean state showing the saved avatar.
                if (previewUrl) {
                    URL.revokeObjectURL(previewUrl);
                }

                setPreviewUrl(null);
                reset('avatar');
            },
        });
    };

    const displayAvatarSrc = previewUrl ?? user.avatar_url ?? undefined;
    const bioCount = data.bio.length;
    const bioOverCap = bioCount > BIO_MAX;

    return (
        <>
            <Head title="Profile settings" />

            <h1 className="sr-only">Profile settings</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Profile information"
                    description="Update your avatar, display name, bio, and email address"
                />

                <form onSubmit={handleSubmit} className="space-y-6">
                    {/* Avatar — click the avatar tile or "Change" to open the
                        file picker; the picked file flows into the crop modal,
                        and the cropped blob lives in `data.avatar`. */}
                    <div className="grid gap-3">
                        <Label>Avatar</Label>
                        <div className="flex items-center gap-5">
                            <button
                                type="button"
                                onClick={() => fileInputRef.current?.click()}
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
                        <InputError message={rawFileError ?? errors.avatar} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="name">Name</Label>
                        <Input
                            id="name"
                            className="mt-1 block w-full"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            name="name"
                            required
                            autoComplete="name"
                            placeholder="Full name"
                        />
                        <InputError className="mt-2" message={errors.name} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="email">Email address</Label>
                        <Input
                            id="email"
                            type="email"
                            className="mt-1 block w-full"
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                            name="email"
                            required
                            autoComplete="username"
                            placeholder="Email address"
                        />
                        <InputError className="mt-2" message={errors.email} />
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
                        <InputError className="mt-2" message={errors.bio} />
                    </div>

                    {mustVerifyEmail && user.email_verified_at === null && (
                        <div>
                            <p className="-mt-4 text-sm text-muted-foreground">
                                Your email address is unverified.{' '}
                                <Link
                                    href={send()}
                                    as="button"
                                    className="text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
                                >
                                    Click here to resend the verification email.
                                </Link>
                            </p>

                            {status === 'verification-link-sent' && (
                                <div className="mt-2 text-sm font-medium text-green-600">
                                    A new verification link has been sent to
                                    your email address.
                                </div>
                            )}
                        </div>
                    )}

                    <div className="flex items-center gap-4">
                        <Button
                            type="submit"
                            disabled={processing}
                            data-test="update-profile-button"
                        >
                            {processing ? 'Saving…' : 'Save'}
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
        </>
    );
}
