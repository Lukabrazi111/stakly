// FACEIT CS2 level → authentic FACEIT ring color (M41 P7). Official ladder:
// L1 grey, L2–3 green, L4–7 yellow, L8–9 orange, L10 red. The colors live as CSS
// vars in app.css (tunable in one place); this maps a level to its var(). The
// level NUMBER always shows, so the color is decorative — colorblind-safe.

export function faceitLevelColor(level: number): string {
    if (level >= 10) {
        return 'var(--faceit-red)';
    }

    if (level >= 8) {
        return 'var(--faceit-orange)';
    }

    if (level >= 4) {
        return 'var(--faceit-yellow)';
    }

    if (level >= 2) {
        return 'var(--faceit-green)';
    }

    return 'var(--faceit-grey)';
}
