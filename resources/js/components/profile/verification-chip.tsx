import { ExternalLink } from 'lucide-react';

type Provider = 'chess_com' | 'lichess';

// Tint colors from each platform's brand identity, adjusted for the dark surface.
const PROVIDER_META: Record<
    Provider,
    {
        label: string;
        href: (username: string) => string;
        tone: string;
    }
> = {
    chess_com: {
        label: 'chess.com',
        href: (u) => `https://www.chess.com/member/${u}`,
        tone: 'border-[#7c5430]/60 bg-[#7c5430]/15 text-[#d4a574] hover:border-[#7c5430]/90 hover:bg-[#7c5430]/25',
    },
    lichess: {
        label: 'Lichess',
        href: (u) => `https://lichess.org/@/${u}`,
        tone: 'border-neutral-500/50 bg-neutral-500/15 text-neutral-200 hover:border-neutral-400/70 hover:bg-neutral-500/25',
    },
};

interface Props {
    provider: Provider;
    username: string;
}

export function VerificationChip({ provider, username }: Props) {
    const meta = PROVIDER_META[provider];

    return (
        <a
            href={meta.href(username)}
            target="_blank"
            rel="noopener noreferrer"
            aria-label={`View verified ${meta.label} profile ${username} (opens in new tab)`}
            className={`group inline-flex shrink-0 items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium transition-colors duration-150 ease-out focus-visible:ring-2 focus-visible:ring-primary/25 focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none ${meta.tone}`}
        >
            <span>{meta.label}</span>
            <span className="opacity-50" aria-hidden="true">
                ·
            </span>
            <span className="font-mono tracking-tight">{username}</span>
            <ExternalLink
                className="size-3 opacity-60 transition-opacity duration-150 group-hover:opacity-100"
                aria-hidden="true"
            />
        </a>
    );
}
