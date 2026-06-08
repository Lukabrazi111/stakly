import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useT } from '@/lib/i18n';

interface Props {
    min: string;
    max: string;
    onMinChange: (next: string) => void;
    onMaxChange: (next: string) => void;
    errors?: { min?: string; max?: string };
}

/**
 * Chess skill-range filter (Elo min/max). Lifted out of
 * `pages/listings/create.tsx` during M15 Phase 3 so the create form can swap
 * the chess-specific Elo input for the FACEIT ELO equivalent (`Cs2SkillRangeFilter`).
 */
export function ChessSkillRangeFilter({
    min,
    max,
    onMinChange,
    onMaxChange,
    errors,
}: Props) {
    const t = useT();

    return (
        <div className="space-y-2">
            <Label>{t('Skill range (Elo)')}</Label>
            <div className="flex items-center gap-3">
                <Input
                    type="number"
                    min={0}
                    max={3500}
                    placeholder={t('Any min')}
                    value={min}
                    onChange={(e) => onMinChange(e.target.value)}
                    className="flex-1"
                    aria-label={t('Minimum Elo')}
                />
                <span className="text-xs text-muted-foreground">{t('to')}</span>
                <Input
                    type="number"
                    min={0}
                    max={3500}
                    placeholder={t('Any max')}
                    value={max}
                    onChange={(e) => onMaxChange(e.target.value)}
                    className="flex-1"
                    aria-label={t('Maximum Elo')}
                />
            </div>
            <p className="text-xs text-muted-foreground">
                {t('Leave blank to match any skill.')}
            </p>
            <InputError message={errors?.min} />
            <InputError message={errors?.max} />
        </div>
    );
}
