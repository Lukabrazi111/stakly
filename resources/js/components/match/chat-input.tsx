import { Send } from 'lucide-react';
import type { FormEvent, KeyboardEvent } from 'react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';

interface ChatInputProps {
    onSend: (content: string) => void;
    // Disabled while a send is in-flight so double-Enter doesn't double-post.
    // (Server-side rate limit catches it too, but UI feedback is nicer.)
    disabled: boolean;
}

// Locked at 2000 in milestones.md M8 Phase 2. Matches `StoreMessageRequest::MAX_CONTENT_LENGTH`.
const MAX_CONTENT_LENGTH = 2000;

export function ChatInput({ onSend, disabled }: ChatInputProps) {
    const [content, setContent] = useState('');

    const trimmed = content.trim();
    const canSend = trimmed.length > 0 && trimmed.length <= MAX_CONTENT_LENGTH && !disabled;

    const submit = (e?: FormEvent) => {
        e?.preventDefault();

        if (!canSend) {
            return;
        }

        onSend(trimmed);
        setContent('');
    };

    // Enter sends, Shift+Enter inserts newline. Locked in milestones.md M8 Phase 2.
    const handleKeyDown = (e: KeyboardEvent<HTMLTextAreaElement>) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            submit();
        }
    };

    const overLimit = trimmed.length > MAX_CONTENT_LENGTH;

    return (
        <form
            onSubmit={submit}
            className="border-border/60 bg-card/40 flex items-end gap-2 border-t p-3"
        >
            <div className="flex-1 space-y-1">
                <Textarea
                    value={content}
                    onChange={(e) => setContent(e.target.value)}
                    onKeyDown={handleKeyDown}
                    placeholder="Type a message…"
                    rows={1}
                    aria-label="Chat message"
                    aria-invalid={overLimit || undefined}
                    className="max-h-32 min-h-9 resize-none py-2 text-sm"
                />
                {overLimit && (
                    <p className="text-destructive text-[11px]">
                        {trimmed.length.toLocaleString()} / {MAX_CONTENT_LENGTH.toLocaleString()} — message too long.
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
        </form>
    );
}
