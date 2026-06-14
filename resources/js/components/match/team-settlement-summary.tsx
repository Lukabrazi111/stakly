import { Handshake, Trophy } from 'lucide-react';
import { motion, useReducedMotion } from 'motion/react';
import type { ReactNode } from 'react';
import { useT } from '@/lib/i18n';
import { teamLabel } from '@/lib/team-leader';
import type { TeamMatchPlayer } from '@/types/match';

interface TeamSettlementSummaryProps {
    /** Total pot = stake × team_size × 2. */
    pot: number;
    /** Platform fee. 0 on a draw. */
    fee: number;
    /** Per-player payout for the winning team (= (pot − fee) / team_size).
     *  Slot 0 actually gets the BCMath truncation remainder too, but
     *  surfacing that on the UI would be noise — display the headline
     *  per-player number and leave the remainder to the ledger. */
    perPlayerPayout: number;
    /** Per-player refund on a draw (= stake). */
    perPlayerRefund: number;
    /** 'a' | 'b' | null. Null = draw (no winner). */
    winningTeam: 'a' | 'b' | null;
    /** Which side the viewer is on; null = viewer is not a participant
     *  (admin viewing, etc). */
    viewerTeam: 'a' | 'b' | null;
    /** Team rosters — drive the "Team {leader_username}" winning label. */
    teamA: TeamMatchPlayer[];
    teamB: TeamMatchPlayer[];
    /** Skip the fade-up when landing on an already-settled match. */
    animateEntrance?: boolean;
}

/**
 * Team-aware settle summary. Three variants:
 *   - Draw  → muted handshake card, per-player refund
 *   - Won   → success-tinted trophy card, "Your team won" + per-player payout
 *   - Lost  → muted trophy card, "Team B won" (or "Team A won")
 *
 * Mirrors `SettlementSummary` for 1v1 — same visual rhythm + entrance
 * animation gate.
 */
export function TeamSettlementSummary({
    pot,
    fee,
    perPlayerPayout,
    perPlayerRefund,
    winningTeam,
    viewerTeam,
    teamA,
    teamB,
    animateEntrance = true,
}: TeamSettlementSummaryProps) {
    const t = useT();
    const reduceMotion = useReducedMotion();

    const entrance =
        animateEntrance && !reduceMotion
            ? {
                  initial: { opacity: 0, y: 12 },
                  animate: { opacity: 1, y: 0 },
                  transition: { duration: 0.3, ease: 'easeOut' as const },
              }
            : {};

    if (winningTeam === null) {
        return (
            <motion.section
                {...entrance}
                className="rounded-2xl border border-border/60 bg-card/60 p-6"
            >
                <div className="mb-5 flex items-center gap-3">
                    <div className="rounded-full bg-muted p-2">
                        <Handshake className="size-5 text-muted-foreground" />
                    </div>
                    <div>
                        <h2 className="font-display text-lg font-semibold text-foreground">
                            {t('Match drawn')}
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            {t('All stakes refunded. No platform fee.')}
                        </p>
                    </div>
                </div>

                <dl className="grid gap-5 sm:grid-cols-2">
                    <Stat label={t('Pot')} value={`$${pot}`} />
                    <Stat
                        label={t('Refund (each)')}
                        value={`$${perPlayerRefund.toFixed(2)}`}
                        accent
                    />
                </dl>
            </motion.section>
        );
    }

    const iAmWinner = viewerTeam !== null && viewerTeam === winningTeam;
    const winningTeamLabel = teamLabel(
        winningTeam === 'a' ? teamA : teamB,
        winningTeam === 'a' ? t('Team A') : t('Team B'),
    );

    return (
        <motion.section
            {...entrance}
            className={`rounded-2xl border p-6 ${
                iAmWinner
                    ? 'border-success/40 bg-success/5'
                    : 'border-border/60 bg-card/60'
            }`}
        >
            <div className="mb-5 flex items-center gap-3">
                <div
                    className={`rounded-full p-2 ${
                        iAmWinner ? 'bg-success/15' : 'bg-muted'
                    }`}
                >
                    <Trophy
                        className={`size-5 ${
                            iAmWinner ? 'text-success' : 'text-muted-foreground'
                        }`}
                    />
                </div>
                <div>
                    <h2 className="font-display text-lg font-semibold text-foreground">
                        {t('Match settled')}
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        {iAmWinner
                            ? t('Your team won.')
                            : t(':team won.', { team: winningTeamLabel })}
                    </p>
                </div>
            </div>

            <dl className="grid gap-5 sm:grid-cols-3">
                <Stat label={t('Pot')} value={`$${pot}`} />
                <Stat
                    label={t('Platform fee')}
                    value={`−$${fee.toFixed(2)}`}
                    muted
                />
                <Stat
                    label={
                        iAmWinner ? t('Your payout') : t('Winner payout (each)')
                    }
                    value={`$${perPlayerPayout.toFixed(2)}`}
                    accent={iAmWinner}
                />
            </dl>
        </motion.section>
    );
}

interface StatProps {
    label: string;
    value: string;
    accent?: boolean;
    muted?: boolean;
}

function Stat({ label, value, accent, muted }: StatProps): ReactNode {
    return (
        <div>
            <dt className="text-xs tracking-wide text-muted-foreground uppercase">
                {label}
            </dt>
            <dd
                className={`mt-1.5 font-display text-xl font-bold ${
                    accent
                        ? 'text-success'
                        : muted
                          ? 'font-medium text-muted-foreground'
                          : 'text-foreground'
                }`}
            >
                {value}
            </dd>
        </div>
    );
}
