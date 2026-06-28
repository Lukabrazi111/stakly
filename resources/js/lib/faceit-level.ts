// FACEIT CS2 level → authentic FACEIT ring color (M41 P7). Official ladder:
// 1–2 grey, 3 light blue, 4–6 blue, 7–8 green, 9–10 orange/gold. The colors live
// as CSS vars in app.css (tunable in one place); this maps a level to its var().
// The level NUMBER always shows, so the color is decorative — colorblind-safe,
// and won't be mistaken for win/dispute status next to a stake.

export function faceitLevelColor(level: number): string {
    if (level >= 9) {
        return 'var(--faceit-gold)';
    }

    if (level >= 7) {
        return 'var(--faceit-green)';
    }

    if (level >= 4) {
        return 'var(--faceit-blue)';
    }

    if (level >= 3) {
        return 'var(--faceit-light-blue)';
    }

    return 'var(--faceit-grey)';
}
