import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useT } from '@/lib/i18n';
import type { TimeControl } from '@/types/listings';

const TIME_CONTROL_OPTIONS: ReadonlyArray<[TimeControl, string]> = [
    ['blitz', 'Blitz'],
    ['rapid', 'Rapid'],
    ['classical', 'Classical'],
];

interface Props {
    value: TimeControl[];
    onChange: (next: TimeControl[]) => void;
    error?: string;
}

/**
 * Chess time-control toggle (multi-select). Lifted out of `pages/listings/create.tsx`
 * during M15 Phase 3 so the create form can swap chess-specific filter UI
 * for the per-game equivalent (CS2 has no time-control concept; future games
 * get their own sibling).
 */
export function ChessFormatFilter({ value, onChange, error }: Props) {
    const t = useT();

    return (
        <div className="space-y-2">
            <Label>{t('Time control')}</Label>
            <ToggleGroup
                type="multiple"
                variant="outline"
                value={value}
                onValueChange={(next) => onChange(next as TimeControl[])}
                className="flex flex-wrap"
            >
                {TIME_CONTROL_OPTIONS.map(([slug, label]) => (
                    <ToggleGroupItem
                        key={slug}
                        value={slug}
                        className="rounded-full px-5 py-2"
                    >
                        {t(label)}
                    </ToggleGroupItem>
                ))}
            </ToggleGroup>
            <p className="text-xs text-muted-foreground">
                {t('Pick at least one — the opponent picks which to play.')}
            </p>
            <InputError message={error} />
        </div>
    );
}
