import { Paperclip, Send, X } from 'lucide-react';
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

interface ChatInputProps {
    onSend: (content: string, file: File | null) => void;
    // Disabled while a send is in-flight so double-Enter doesn't double-post.
    // (Server-side rate limit catches it too, but UI feedback is nicer.)
    disabled: boolean;
    // Pending image attachment + clearer. Owned by ChatPanel so the
    // drag-drop overlay above can push a file in here without prop-drilling.
    file: File | null;
    onFileChange: (file: File | null) => void;
    // 0..100 while a file upload is in flight, null otherwise.
    uploadProgress: number | null;
}

// Locked at 2000 in milestones.md M8 Phase 2. Matches `StoreMessageRequest::MAX_CONTENT_LENGTH`.
const MAX_CONTENT_LENGTH = 2000;

// 5 MB cap matches `StoreMessageRequest::MAX_FILE_SIZE_KB` and the Phase 3 lock.
const MAX_FILE_SIZE_BYTES = 5 * 1024 * 1024;

const ACCEPTED_MIMES = ['image/jpeg', 'image/png', 'image/webp'];
const ACCEPT_ATTR = ACCEPTED_MIMES.join(',');

export function ChatInput({
    onSend,
    disabled,
    file,
    onFileChange,
    uploadProgress,
}: ChatInputProps) {
    const [content, setContent] = useState('');
    const [previewUrl, setPreviewUrl] = useState<string | null>(null);
    const fileInputRef = useRef<HTMLInputElement>(null);

    // Local object-URL preview so the user sees the thumb before send.
    // Revoke on unmount / file-change to prevent the browser from holding
    // a blob in memory after the upload completes. setState-inside-effect
    // is the right shape here — useMemo + cleanup-only effect breaks under
    // React strict mode (the URL gets revoked during the strict double-mount
    // and the image src then points at a dead blob).
    useEffect(() => {
        if (!file) {
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

    // Enter sends, Shift+Enter inserts newline. See milestones.md M8 Phase 2.
    const handleKeyDown = (e: KeyboardEvent<HTMLTextAreaElement>) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            submit();
        }
    };

    // Paste-to-upload — copy a screenshot, Cmd/Ctrl+V into the textarea, the
    // image lands in the file slot instead of pasting as text. If a file is
    // already queued, ignore the paste rather than silently replacing it
    // (user has to clear the existing one first — feels less surprising).
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

                if (pasted && validateFile(pasted)) {
                    e.preventDefault();
                    onFileChange(pasted);
                }

                return;
            }
        }
    };

    const handleFileInputChange = (e: ChangeEvent<HTMLInputElement>) => {
        const next = e.target.files?.[0] ?? null;

        if (next && !validateFile(next)) {
            // Clear the input so re-selecting the same bad file still fires
            // onChange and re-runs validation.
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
            {/* File preview strip — only when a file is queued. Shows the
                local object-URL thumb + a clear button + (when in flight)
                an upload progress bar. */}
            {hasFile && (
                <div className="flex items-center gap-3 border-b border-border/40 px-3 py-2">
                    {previewUrl && (
                        <img
                            src={previewUrl}
                            alt={file.name}
                            className="size-12 shrink-0 rounded-md border border-border/60 object-cover"
                        />
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
                        aria-label="Remove attachment"
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
                    aria-label="Attach image"
                    className="shrink-0"
                >
                    <Paperclip className="size-4" />
                </Button>
                <div className="flex-1 space-y-1">
                    <Textarea
                        value={content}
                        onChange={(e) => setContent(e.target.value)}
                        onKeyDown={handleKeyDown}
                        onPaste={handlePaste}
                        placeholder={
                            hasFile
                                ? 'Add a caption (optional)…'
                                : 'Type a message…'
                        }
                        rows={1}
                        aria-label="Chat message"
                        aria-invalid={overLimit || undefined}
                        className="max-h-32 min-h-9 resize-none py-2 text-sm"
                    />
                    {overLimit && (
                        <p className="text-[11px] text-destructive">
                            {trimmed.length.toLocaleString()} /{' '}
                            {MAX_CONTENT_LENGTH.toLocaleString()} — message too
                            long.
                        </p>
                    )}
                </div>
                <Button
                    type="submit"
                    size="icon"
                    variant="gradient"
                    disabled={!canSend}
                    aria-label="Send message"
                >
                    <Send className="size-4" />
                </Button>
            </div>
        </form>
    );
}

/**
 * Client-side preflight on file picker. Server validates again (defense in
 * depth + the source of truth for accepted types / sizes), but rejecting
 * here saves the user a round-trip when they obviously picked the wrong file.
 */
function validateFile(file: File): boolean {
    if (!ACCEPTED_MIMES.includes(file.type)) {
        toast.error('Only JPEG, PNG, or WebP images can be sent in chat.');

        return false;
    }

    if (file.size > MAX_FILE_SIZE_BYTES) {
        toast.error('Image is larger than 5 MB.');

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
