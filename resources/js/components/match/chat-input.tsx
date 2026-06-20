import { FileText, Paperclip, Send, X } from 'lucide-react';
import type {
    ChangeEvent,
    ClipboardEvent,
    FormEvent,
    KeyboardEvent,
} from 'react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { useT } from '@/lib/i18n';

interface ChatInputProps {
    onSend: (content: string, file: File | null) => void;
    /** Disabled while a send is in-flight so double-Enter doesn't double-post. */
    disabled: boolean;
    /** File state owned by ChatPanel so the drag-drop overlay can push a
     *  file in without prop-drilling. */
    file: File | null;
    onFileChange: (file: File | null) => void;
    /** 0..100 while a file upload is in flight, null otherwise. */
    uploadProgress: number | null;
}

// Mirrors `StoreMessageRequest::MAX_CONTENT_LENGTH`.
const MAX_CONTENT_LENGTH = 2000;

// Mirrors `StoreMessageRequest::MAX_FILE_SIZE_KB`.
const MAX_FILE_SIZE_BYTES = 5 * 1024 * 1024;

const ACCEPTED_MIMES = [
    'image/jpeg',
    'image/png',
    'image/webp',
    'application/pdf',
];
const ACCEPT_ATTR = ACCEPTED_MIMES.join(',');

export function ChatInput({
    onSend,
    disabled,
    file,
    onFileChange,
    uploadProgress,
}: ChatInputProps) {
    const t = useT();
    const [content, setContent] = useState('');
    const [previewUrl, setPreviewUrl] = useState<string | null>(null);
    const fileInputRef = useRef<HTMLInputElement>(null);
    const textareaRef = useRef<HTMLTextAreaElement>(null);

    useEffect(() => {
        const handler = () => {
            const el = textareaRef.current;

            if (!el) {
                return;
            }

            el.focus();
            el.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        };

        window.addEventListener('stakly:focus-chat', handler);

        return () => window.removeEventListener('stakly:focus-chat', handler);
    }, []);

    // setState-inside-effect: useMemo + cleanup-only effect breaks under
    // strict mode — the URL gets revoked during double-mount, leaving a
    // dead blob src.
    useEffect(() => {
        // Only image previews need a blob URL — PDFs render as a file icon
        // tile, no inline preview.
        if (!file || !file.type.startsWith('image/')) {
            setPreviewUrl(null);

            return;
        }

        const url = URL.createObjectURL(file);
        setPreviewUrl(url);

        return () => URL.revokeObjectURL(url);
    }, [file]);

    const trimmed = content.trim();
    const overLimit = trimmed.length > MAX_CONTENT_LENGTH;
    const hasFile = file !== null;
    const hasText = trimmed.length > 0;
    const canSend = (hasText || hasFile) && !overLimit && !disabled;

    const submit = (e?: FormEvent) => {
        e?.preventDefault();

        if (!canSend) {
            return;
        }

        onSend(trimmed, file);
        setContent('');
        onFileChange(null);

        if (fileInputRef.current) {
            fileInputRef.current.value = '';
        }
    };

    // Enter sends, Shift+Enter inserts newline.
    const handleKeyDown = (e: KeyboardEvent<HTMLTextAreaElement>) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            submit();
        }
    };

    // Paste-to-upload: pasted image lands in the file slot. If a file is
    // already queued, ignore rather than silently replacing it.
    const handlePaste = (e: ClipboardEvent<HTMLTextAreaElement>) => {
        if (disabled || hasFile) {
            return;
        }

        const items = e.clipboardData?.items;

        if (!items) {
            return;
        }

        for (const item of Array.from(items)) {
            if (item.kind === 'file' && item.type.startsWith('image/')) {
                const pasted = item.getAsFile();

                if (pasted && validateFile(pasted, t)) {
                    e.preventDefault();
                    onFileChange(pasted);
                }

                return;
            }
        }
    };

    const handleFileInputChange = (e: ChangeEvent<HTMLInputElement>) => {
        const next = e.target.files?.[0] ?? null;

        if (next && !validateFile(next, t)) {
            // Clear so re-selecting the same bad file still fires onChange.
            e.target.value = '';

            return;
        }

        onFileChange(next);
    };

    const clearFile = () => {
        onFileChange(null);

        if (fileInputRef.current) {
            fileInputRef.current.value = '';
        }
    };

    return (
        <form
            onSubmit={submit}
            className="border-t border-border/60 bg-card/40"
        >
            {hasFile && (
                <div className="flex items-center gap-3 border-b border-border/40 px-3 py-2">
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
                        {uploadProgress !== null && (
                            <div className="mt-1.5 h-1 w-full overflow-hidden rounded-full bg-border/60">
                                <div
                                    className="h-full bg-primary transition-[width] duration-150 ease-out"
                                    style={{ width: `${uploadProgress}%` }}
                                />
                            </div>
                        )}
                    </div>
                    <Button
                        type="button"
                        size="icon"
                        variant="ghost"
                        onClick={clearFile}
                        aria-label={t('Remove attachment')}
                        disabled={disabled}
                        className="shrink-0"
                    >
                        <X className="size-4" />
                    </Button>
                </div>
            )}

            <div className="flex items-end gap-2 p-3">
                <input
                    ref={fileInputRef}
                    type="file"
                    accept={ACCEPT_ATTR}
                    onChange={handleFileInputChange}
                    className="hidden"
                    aria-hidden
                />
                <Button
                    type="button"
                    size="icon"
                    variant="ghost"
                    onClick={() => fileInputRef.current?.click()}
                    disabled={disabled || hasFile}
                    aria-label={t('Attach file')}
                    className="shrink-0"
                >
                    <Paperclip className="size-4" />
                </Button>
                <div className="flex-1 space-y-1">
                    <Textarea
                        ref={textareaRef}
                        value={content}
                        onChange={(e) => setContent(e.target.value)}
                        onKeyDown={handleKeyDown}
                        onPaste={handlePaste}
                        placeholder={
                            hasFile
                                ? t('Add a caption (optional)…')
                                : t('Type a message…')
                        }
                        rows={1}
                        aria-label={t('Chat message')}
                        aria-invalid={overLimit || undefined}
                        className="max-h-32 min-h-9 resize-none py-2 text-sm"
                    />
                    {overLimit && (
                        <p className="text-[11px] text-destructive">
                            {t(':count / :max — message too long.', {
                                count: trimmed.length.toLocaleString(),
                                max: MAX_CONTENT_LENGTH.toLocaleString(),
                            })}
                        </p>
                    )}
                </div>
                <Button
                    type="submit"
                    size="icon"
                    variant="gradient"
                    disabled={!canSend}
                    aria-label={t('Send message')}
                >
                    <Send className="size-4" />
                </Button>
            </div>
        </form>
    );
}

/** Client-side preflight; server validates again as source of truth. */
function validateFile(
    file: File,
    t: (key: string, replacements?: Record<string, string | number>) => string,
): boolean {
    if (!ACCEPTED_MIMES.includes(file.type)) {
        toast.error(
            t('Only JPG, PNG, WebP, or PDF files can be sent in chat.'),
        );

        return false;
    }

    if (file.size > MAX_FILE_SIZE_BYTES) {
        toast.error(t('File is larger than 5 MB.'));

        return false;
    }

    return true;
}

function formatBytes(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    if (bytes < 1024 * 1024) {
        return `${(bytes / 1024).toFixed(1)} KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}
