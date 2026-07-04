import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useT } from '@/lib/i18n';
import type { TimeControl } from '@/types/listings';

const TIME_CONTROL_OPTIONS: ReadonlyArray<[TimeControl, string]> = [
    ['bullet', 'Bullet'],
    ['blitz', 'Blitz'],
    ['rapid', 'Rapid'],
];

interface Props {
    value: TimeControl;
    onChange: (next: TimeControl) => void;
    error?: string;
}

/**
 * Chess time-control toggle (single-select, M41 P3a — one chess listing maps to
 * exactly one time control, so it shows exactly one verified rating). Lifted out
 * of `pages/listings/create.tsx` during M15 Phase 3 so the create form can swap
 * chess-specific filter UI for the per-game equivalent (CS2 has no time-control
 * concept).
 */
export function ChessFormatFilter({ value, onChange, error }: Props) {
    const t = useT();

    return (
        <div className="space-y-2">
            <Label>{t('Time control')}</Label>
            <ToggleGroup
                type="single"
                variant="outline"
                value={value}
                onValueChange={(next) => {
                    // Radix single-select emits '' when the active item is
                    // re-clicked; ignore it so exactly one stays selected.
                    if (next) {
                        onChange(next as TimeControl);
                    }
                }}
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
                {t(
                    'One listing, one time control — post a separate listing for another format.',
                )}
            </p>
            <InputError message={error} />
        </div>
    );
}
