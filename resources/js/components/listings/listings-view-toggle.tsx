import { LayoutGrid, List } from 'lucide-react';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import type { ListingsViewMode } from '@/hooks/use-listings-view';
import { useT } from '@/lib/i18n';

interface Props {
    value: ListingsViewMode;
    onChange: (mode: ListingsViewMode) => void;
}

/**
 * Rows / Grid layout switcher. Sits in the filter bar above the listings
 * grid. Visual styling inherited from the Stakly-skinned `toggle` variants
 * (primary-tinted hover + active states, no upstream `bg-accent` leakage).
 */
export function ListingsViewToggle({ value, onChange }: Props) {
    const t = useT();

    return (
        <ToggleGroup
            type="single"
            value={value}
            onValueChange={(next) => {
                // Radix fires '' when the user clicks the already-active item;
                // ignore that to keep the toggle behaving as a single-select.
                if (next === 'rows' || next === 'grid') {
                    onChange(next);
                }
            }}
            variant="outline"
            size="sm"
            aria-label={t('Listings layout')}
        >
            <ToggleGroupItem value="rows" aria-label={t('Rows view')}>
                <List className="size-4" aria-hidden="true" />
            </ToggleGroupItem>
            <ToggleGroupItem value="grid" aria-label={t('Grid view')}>
                <LayoutGrid className="size-4" aria-hidden="true" />
            </ToggleGroupItem>
        </ToggleGroup>
    );
}
