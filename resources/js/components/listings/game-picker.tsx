import { Check, ChevronDown, Crown, Link2, Swords, Target } from 'lucide-react';
import type { ElementType } from 'react';
import { useState } from 'react';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import type { GameId } from '@/config/games';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { GameTile } from '@/types/home';
import type { ListingPlatform } from '@/types/listings';

const PROVIDER_DISPLAY: Record<ListingPlatform, string> = {
    chess_com: 'chess.com',
    lichess: 'Lichess',
    faceit: 'FACEIT',
    steam: 'Steam',
};

const GAME_ICONS: Record<string, ElementType> = {
    chess: Crown,
    cs2: Target,
    dota2: Swords,
};

interface Props {
    games: readonly GameTile[];
    selected: GameId;
    onSelect: (slug: GameId) => void;
    requirementsByGame: Record<
        string,
        { providers: ListingPlatform[]; verified: boolean }
    >;
}

/**
 * Game picker for the create-listing form. Mirrors the original chess-only
 * card aesthetic (gradient icon square + title + subtext inside a `border-glow`
 * wrapper) but the card is now an interactive trigger — clicking opens a
 * Popover with all Active games. Selected state in the dropdown is the check
 * icon only (no bg) so it doesn't collide visually with the hover pink wash.
 */
export function GamePicker({
    games,
    selected,
    onSelect,
    requirementsByGame,
}: Props) {
    const t = useT();
    const [open, setOpen] = useState(false);
    const activeGames = games.filter((g) => g.status === 'active');
    const selectedGame = activeGames.find((g) => g.slug === selected);
    const selectedRequirements = requirementsByGame[selected];
    const selectedProviders = selectedRequirements?.providers ?? [];
    const selectedVerified = selectedRequirements?.verified ?? false;
    const SelectedIcon = GAME_ICONS[selected] ?? Crown;

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <button
                    type="button"
                    aria-haspopup="listbox"
                    aria-expanded={open}
                    className="flex w-full cursor-pointer items-center gap-3 rounded-xl border border-primary/40 bg-card/60 p-4 text-left shadow-glow transition-colors hover:border-primary/60 focus-visible:border-primary focus-visible:ring-2 focus-visible:ring-primary/25 focus-visible:outline-none"
                >
                    <div className="inline-flex size-10 shrink-0 items-center justify-center rounded-lg bg-gradient-primary">
                        <SelectedIcon
                            className="size-5 text-primary-foreground"
                            aria-hidden="true"
                        />
                    </div>
                    <div className="min-w-0 flex-1">
                        <div className="font-semibold text-foreground">
                            {selectedGame?.display_name ?? '—'}
                        </div>
                        <div className="mt-0.5 truncate text-xs text-muted-foreground">
                            {selectedProviders
                                .map((p) => PROVIDER_DISPLAY[p] ?? p)
                                .join(' · ')}
                        </div>
                    </div>
                    <VerificationChip
                        verified={selectedVerified}
                        providers={selectedProviders}
                    />
                    <ChevronDown
                        className={cn(
                            'size-4 shrink-0 text-muted-foreground transition-transform',
                            open && 'rotate-180',
                        )}
                        aria-hidden="true"
                    />
                </button>
            </PopoverTrigger>
            <PopoverContent
                align="start"
                role="listbox"
                aria-label={t('Game')}
                className="w-[var(--radix-popover-trigger-width)] p-1"
            >
                {activeGames.map((game) => {
                    const requirements = requirementsByGame[game.slug];
                    const isSelected = game.slug === selected;
                    const providers = requirements?.providers ?? [];
                    const verified = requirements?.verified ?? false;

                    return (
                        <button
                            key={game.slug}
                            type="button"
                            role="option"
                            aria-selected={isSelected}
                            onClick={() => {
                                onSelect(game.slug as GameId);
                                setOpen(false);
                            }}
                            className="flex w-full cursor-pointer items-center gap-3 rounded-lg px-3 py-2.5 text-left transition-colors hover:bg-primary/10"
                        >
                            <Check
                                className={cn(
                                    'size-4 shrink-0',
                                    isSelected
                                        ? 'text-primary'
                                        : 'text-transparent',
                                )}
                                aria-hidden="true"
                            />
                            <div className="min-w-0 flex-1">
                                <div className="font-semibold text-foreground">
                                    {game.display_name}
                                </div>
                                <div className="mt-0.5 truncate text-xs text-muted-foreground">
                                    {providers
                                        .map((p) => PROVIDER_DISPLAY[p] ?? p)
                                        .join(' · ')}
                                </div>
                            </div>
                            <VerificationChip
                                verified={verified}
                                providers={providers}
                            />
                        </button>
                    );
                })}
            </PopoverContent>
        </Popover>
    );
}

interface VerificationChipProps {
    verified: boolean;
    providers: ListingPlatform[];
}

function VerificationChip({ verified, providers }: VerificationChipProps) {
    const t = useT();

    if (verified) {
        return (
            <span className="inline-flex shrink-0 items-center gap-1 text-xs font-medium text-success">
                <Check className="size-3" aria-hidden="true" />
                {t('Verified')}
            </span>
        );
    }

    const providerNames =
        providers.map((p) => PROVIDER_DISPLAY[p] ?? p).join(t(' or ')) ||
        t('account');

    return (
        <span className="inline-flex shrink-0 items-center gap-1 text-xs font-medium text-warning">
            <Link2 className="size-3" aria-hidden="true" />
            {t('Link :provider', { provider: providerNames })}
        </span>
    );
}
