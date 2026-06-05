import { router } from '@inertiajs/react';
import { AlertTriangle, FileText, ImagePlus, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { ChangeEvent } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { openDispute as openDisputeRoute } from '@/routes/matches';

interface OpenDisputeButtonProps {
    matchId: number;
}

const REASON_MAX = 1000;
const ACCEPTED_MIMES = [
    'image/jpeg',
    'image/png',
    'image/webp',
    'application/pdf',
];
const MAX_FILE_SIZE_BYTES = 5 * 1024 * 1024;

/** Report-a-problem escape hatch — flips the match to Disputed for admin review. */
export function OpenDisputeButton({ matchId }: OpenDisputeButtonProps) {
    const t = useT();
    const [open, setOpen] = useState(false);
    const [reason, setReason] = useState('');
    const [file, setFile] = useState<File | null>(null);
    const [fileError, setFileError] = useState<string | null>(null);
    const [previewUrl, setPreviewUrl] = useState<string | null>(null);
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<{
        reason?: string;
        evidence?: string;
    }>({});
    const fileInputRef = useRef<HTMLInputElement>(null);

    useEffect(() => {
        // Only image previews need an object URL — PDFs render as a file
        // icon tile, no inline preview.
        if (!file || !file.type.startsWith('image/')) {
            setPreviewUrl(null);

            return;
        }

        const url = URL.createObjectURL(file);
        setPreviewUrl(url);

        return () => URL.revokeObjectURL(url);
    }, [file]);

    const trimmed = reason.trim();
    const reasonLen = trimmed.length;
    const overCap = reasonLen > REASON_MAX;
    const hasReason = reasonLen > 0;
    const hasFile = file !== null;
    const canSubmit = (hasReason || hasFile) && !overCap && !processing;

    const resetForm = () => {
        setReason('');
        setFile(null);
        setFileError(null);
        setErrors({});

        if (fileInputRef.current) {
            fileInputRef.current.value = '';
        }
    };

    const handleOpenChange = (next: boolean) => {
        if (!next && !processing) {
            resetForm();
        }

        setOpen(next);
    };

    const handleFilePicked = (e: ChangeEvent<HTMLInputElement>) => {
        const picked = e.target.files?.[0];
        e.target.value = '';
        setFileError(null);

        if (!picked) {
            return;
        }

        if (!ACCEPTED_MIMES.includes(picked.type)) {
            setFileError(t('JPG, PNG, WebP, or PDF only.'));

            return;
        }

        if (picked.size > MAX_FILE_SIZE_BYTES) {
            setFileError(t('File too large. Max 5 MB.'));

            return;
        }

        setFile(picked);
    };

    const clearFile = () => {
        setFile(null);
        setFileError(null);

        if (fileInputRef.current) {
            fileInputRef.current.value = '';
        }
    };

    const handleSubmit = () => {
        if (!canSubmit) {
            return;
        }

        setProcessing(true);
        setErrors({});

        const formData = new FormData();
        formData.append('reason', trimmed);

        if (file) {
            formData.append('evidence', file);
        }

        router.post(openDisputeRoute({ match: matchId }).url, formData, {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                resetForm();
                setOpen(false);
            },
            onError: (serverErrors) => {
                setErrors({
                    reason: serverErrors.reason,
                    evidence: serverErrors.evidence,
                });
            },
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <>
            <button
                type="button"
                onClick={() => setOpen(true)}
                className="inline-flex cursor-pointer items-center gap-1.5 rounded-sm text-xs text-muted-foreground underline-offset-4 transition-colors hover:text-warning hover:underline focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
            >
                <AlertTriangle className="size-3.5" aria-hidden="true" />
                {t('Report a problem')}
            </button>

            <Dialog open={open} onOpenChange={handleOpenChange}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{t('Report a problem?')}</DialogTitle>
                        <DialogDescription>
                            {t(
                                'Tell us what happened and (optionally) attach a screenshot. Your stake stays in escrow while a Stakly admin reviews. Your opponent sees this reason as soon as you submit.',
                            )}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="min-w-0 space-y-4">
                        <div className="space-y-1.5">
                            <Textarea
                                value={reason}
                                onChange={(e) => setReason(e.target.value)}
                                placeholder={t(
                                    'e.g., opponent claims they won but the game shows me winning, or opponent is suspected of using a chess engine',
                                )}
                                rows={8}
                                aria-label={t('Reason for dispute')}
                                aria-invalid={overCap || undefined}
                                disabled={processing}
                                className={cn(
                                    'min-h-60 resize-y',
                                    (errors.reason || overCap) &&
                                        'border-destructive/60',
                                )}
                            />
                            <div className="flex items-center justify-between text-[11px]">
                                <span
                                    className={cn(
                                        'text-muted-foreground',
                                        overCap && 'text-destructive',
                                    )}
                                >
                                    {reasonLen} / {REASON_MAX}
                                </span>
                                {errors.reason && (
                                    <span className="text-destructive">
                                        {errors.reason}
                                    </span>
                                )}
                            </div>
                        </div>

                        <div>
                            <input
                                ref={fileInputRef}
                                type="file"
                                accept={ACCEPTED_MIMES.join(',')}
                                onChange={handleFilePicked}
                                className="hidden"
                                aria-hidden
                            />
                            {file ? (
                                <div className="flex min-w-0 items-center gap-3 overflow-hidden rounded-lg border border-border/60 bg-card/60 p-2.5">
                                    {previewUrl ? (
                                        <img
                                            src={previewUrl}
                                            alt={file.name}
                                            className="size-12 shrink-0 rounded-md border border-border/60 object-cover"
                                        />
                                    ) : (
                                        <span className="flex size-12 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary">
                                            <FileText className="size-5" />
                                        </span>
                                    )}
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-xs font-medium text-foreground">
                                            {file.name}
                                        </p>
                                        <p className="text-[11px] text-muted-foreground">
                                            {formatBytes(file.size)}
                                        </p>
                                    </div>
                                    <Button
                                        type="button"
                                        size="icon"
                                        variant="ghost"
                                        onClick={clearFile}
                                        aria-label={t('Remove attachment')}
                                        disabled={processing}
                                        className="shrink-0"
                                    >
                                        <X className="size-4" />
                                    </Button>
                                </div>
                            ) : (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        fileInputRef.current?.click()
                                    }
                                    disabled={processing}
                                >
                                    <ImagePlus className="size-4" />
                                    {t('Attach screenshot or PDF')}
                                </Button>
                            )}
                            {(fileError || errors.evidence) && (
                                <p className="mt-1.5 text-[11px] text-destructive">
                                    {fileError ?? errors.evidence}
                                </p>
                            )}
                        </div>
                    </div>

                    <DialogFooter>
                        <Button
                            variant="ghost"
                            onClick={() => handleOpenChange(false)}
                            disabled={processing}
                        >
                            {t('Cancel')}
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={handleSubmit}
                            disabled={!canSubmit}
                        >
                            {processing ? t('Reporting…') : t('Report match')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

function formatBytes(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    if (bytes < 1024 * 1024) {
        return `${(bytes / 1024).toFixed(1)} KB`;
    }

    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}
