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
 * CS2 skill-range filter (FACEIT ELO min/max). Sibling to
 * `ChessSkillRangeFilter`; bounds and label are FACEIT-specific (skill levels
 * 1–10 map to ELO ~100 through 2001+). Backend caps both at 3500 today.
 */
export function Cs2SkillRangeFilter({
    min,
    max,
    onMinChange,
    onMaxChange,
    errors,
}: Props) {
    const t = useT();

    return (
        <div className="space-y-2">
            <Label>{t('Skill range (FACEIT ELO)')}</Label>
            <div className="flex items-center gap-3">
                <Input
                    type="number"
                    min={0}
                    max={3500}
                    placeholder={t('Any min')}
                    value={min}
                    onChange={(e) => onMinChange(e.target.value)}
                    className="flex-1"
                    aria-label={t('Minimum FACEIT ELO')}
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
                    aria-label={t('Maximum FACEIT ELO')}
                />
            </div>
            <p className="text-xs text-muted-foreground">
                {t(
                    'Leave blank to match any skill. FACEIT levels 1–10 map roughly to 100–2000+.',
                )}
            </p>
            <InputError message={errors?.min} />
            <InputError message={errors?.max} />
        </div>
    );
}
