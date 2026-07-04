<?php

namespace App\Filament\Resources\GameMatches\Schemas;

use App\Enums\AutoFetchOutcome;
use App\Enums\MatchStatus;
use App\Filament\Infolists\Components\ChatHistoryEntry;
use App\Models\GameMatch;
use App\Models\LobbyParticipant;
use App\Models\MatchAutoFetchAttempt;
use App\Models\MatchProviderSnapshot;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Admin match detail view. Sections need `columnSpanFull()` because
 * Filament's default panel grid is 3-column — without it, sections collide
 * on the same row and inner fields wrap awkwardly.
 *
 * Branches on team-play (M34): 1v1 matches show the creator/taker pair;
 * team matches (`team_size > 1`, no single taker) show a Team A / Team B
 * roster. Provider handles + money are read at team scale. Eager-loading is
 * done on the resource query (`GameMatchResource::getEloquentQuery`).
 */
class GameMatchInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                self::statusSection()->columnSpanFull(),
                self::moneySection()->columnSpanFull(),
                self::timelineSection()->columnSpanFull(),
                // 1v1 — creator vs taker. Hidden for team-play, where
                // `taker_user_id` is a creator placeholder and the real roster
                // lives per-side below.
                Grid::make(2)
                    ->columnSpanFull()
                    ->visible(fn (GameMatch $record): bool => ! self::isTeamPlay($record))
                    ->schema([
                        self::creatorSection(),
                        self::takerSection(),
                    ]),
                self::rosterSection()->columnSpanFull(),
                self::chatSection()->columnSpanFull(),
                self::autoFetchHistorySection()->columnSpanFull(),
                self::resolutionHistorySection()->columnSpanFull(),
            ]);
    }

    private static function statusSection(): Section
    {
        return Section::make('Status & game')
            ->icon(Heroicon::InformationCircle)
            ->schema([
                TextEntry::make('id')
                    ->label('Match #'),

                TextEntry::make('status')
                    ->badge()
                    ->color(fn (MatchStatus $state): string => match ($state) {
                        MatchStatus::LobbyFilling => 'gray',
                        MatchStatus::Pending => 'gray',
                        MatchStatus::Disputed => 'warning',
                        MatchStatus::ManualReview => 'danger',
                        MatchStatus::Settled => 'success',
                        MatchStatus::Cancelled => 'gray',
                    })
                    ->formatStateUsing(fn (MatchStatus $state): string => match ($state) {
                        MatchStatus::LobbyFilling => 'Lobby Filling',
                        MatchStatus::Pending => 'Pending',
                        MatchStatus::Disputed => 'Disputed',
                        MatchStatus::ManualReview => 'Manual Review',
                        MatchStatus::Settled => 'Settled',
                        MatchStatus::Cancelled => 'Cancelled',
                    }),

                TextEntry::make('listing.game')
                    ->label('Game')
                    ->formatStateUsing(fn ($state) => $state?->displayName() ?? '—'),

                TextEntry::make('listing.team_size')
                    ->label('Format')
                    ->state(fn (GameMatch $record): string => self::isTeamPlay($record)
                        ? $record->listing->team_size.'v'.$record->listing->team_size
                        : '1v1',
                    ),

                TextEntry::make('listing.platform')
                    ->label('Platform')
                    ->formatStateUsing(fn ($state) => $state?->displayName() ?? '—'),

                TextEntry::make('winner.username')
                    ->label('Winner')
                    ->placeholder('—')
                    ->icon(fn (GameMatch $record) => $record->winner_user_id ? Heroicon::Trophy : null)
                    ->iconColor('warning'),
            ])
            ->columns(5);
    }

    /**
     * Pot/fee/payout at team scale: the pot is the per-player stake times the
     * full headcount (`team_size × 2`), so the 1v1 case (team_size 1) is just
     * `stake × 2`. BCMath strings throughout; floats only at the
     * `number_format` display boundary.
     */
    private static function moneySection(): Section
    {
        $feeRate = (float) config('stakly.platform_fee_rate');

        return Section::make('Money breakdown')
            ->icon(Heroicon::Banknotes)
            ->schema([
                TextEntry::make('listing.stake_amount')
                    ->label('Stake / player')
                    ->formatStateUsing(fn ($state) => '$'.number_format((float) $state, 2)),

                TextEntry::make('pot_total')
                    ->label('Pot total')
                    ->state(fn (GameMatch $record) => '$'.number_format((float) self::pot($record), 2)),

                TextEntry::make('pot_fee')
                    ->label('Fee ('.($feeRate * 100).'%)')
                    ->state(fn (GameMatch $record) => '$'.number_format((float) self::fee($record), 2)),

                TextEntry::make('winning_side_payout')
                    ->label('Winning side payout')
                    ->state(fn (GameMatch $record) => '$'.number_format(
                        (float) bcsub(self::pot($record), self::fee($record), 6),
                        2,
                    )),

                TextEntry::make('per_player_share')
                    ->label('Per-player share')
                    ->visible(fn (GameMatch $record): bool => self::isTeamPlay($record))
                    ->state(fn (GameMatch $record) => '$'.number_format(
                        (float) bcdiv(
                            bcsub(self::pot($record), self::fee($record), 6),
                            (string) max(1, (int) ($record->listing?->team_size ?? 1)),
                            6,
                        ),
                        2,
                    )),
            ])
            ->columns(5);
    }

    private static function timelineSection(): Section
    {
        return Section::make('Timeline')
            ->icon(Heroicon::Clock)
            ->schema([
                TextEntry::make('created_at')
                    ->label('Match created')
                    ->dateTime('M j, Y H:i')
                    ->since(),

                TextEntry::make('dispute_opened_at')
                    ->label('Dispute opened')
                    ->dateTime('M j, Y H:i')
                    ->since()
                    ->badge()
                    ->color(function ($state, GameMatch $record): string {
                        // Skip urgency color on terminal matches.
                        if (in_array($record->status, [MatchStatus::Settled, MatchStatus::Cancelled], true)) {
                            return 'gray';
                        }

                        if ($state === null) {
                            return 'gray';
                        }

                        $hours = abs(now()->diffInHours($state));

                        return match (true) {
                            $hours >= 6 => 'danger',
                            $hours >= 1 => 'warning',
                            default => 'success',
                        };
                    })
                    ->placeholder('—'),

                TextEntry::make('disputeOpener.name')
                    ->label('Opened by')
                    ->placeholder('—'),

                TextEntry::make('settled_at')
                    ->label('Settled at')
                    ->dateTime('M j, Y H:i')
                    ->since()
                    ->placeholder('—'),
            ])
            ->columns(4);
    }

    private static function creatorSection(): Section
    {
        return Section::make('Creator')
            ->icon(fn (GameMatch $record): ?Heroicon => self::isWinner($record, $record->listing?->user_id)
                ? Heroicon::Trophy
                : null,
            )
            ->iconColor('warning')
            ->schema(self::playerEntries('listing.user', GameMatch::SIDE_CREATOR));
    }

    private static function takerSection(): Section
    {
        return Section::make('Taker')
            ->icon(fn (GameMatch $record): ?Heroicon => self::isWinner($record, $record->taker_user_id)
                ? Heroicon::Trophy
                : null,
            )
            ->iconColor('warning')
            ->schema(self::playerEntries('taker', GameMatch::SIDE_TAKER));
    }

    /**
     * Stakly contact identity from the live User (name/username/email), plus
     * the verified provider handle(s) from `match_provider_snapshots` — the
     * immutable match-time evidence anchor that survives a post-match unlink
     * and covers every provider (FACEIT/Steam, not just chess).
     *
     * @return array<int, TextEntry>
     */
    private static function playerEntries(string $relationPath, string $side): array
    {
        return [
            TextEntry::make("{$relationPath}.name")
                ->label('Name'),

            TextEntry::make("{$relationPath}.username")
                ->label('Username')
                ->copyable(),

            TextEntry::make("{$relationPath}.email")
                ->label('Email')
                ->copyable(),

            TextEntry::make("provider_handles_{$side}")
                ->label('Verified accounts (at match time)')
                ->state(fn (GameMatch $record): string => self::providerHandlesHtml($record, $side))
                ->html()
                ->columnSpanFull(),
        ];
    }

    /**
     * Team A / Team B roster for a team-play match. Hidden for 1v1.
     */
    private static function rosterSection(): Section
    {
        return Section::make('Roster')
            ->icon(Heroicon::UserGroup)
            ->visible(fn (GameMatch $record): bool => self::isTeamPlay($record))
            ->schema([
                Grid::make(2)->schema([
                    TextEntry::make('team_a')
                        ->label('Team A')
                        ->state(fn (GameMatch $record): string => self::teamRosterHtml($record, LobbyParticipant::SIDE_A))
                        ->html(),

                    TextEntry::make('team_b')
                        ->label('Team B')
                        ->state(fn (GameMatch $record): string => self::teamRosterHtml($record, LobbyParticipant::SIDE_B))
                        ->html(),
                ]),
            ]);
    }

    private static function chatSection(): Section
    {
        return Section::make('Chat history')
            ->icon(Heroicon::ChatBubbleLeftRight)
            ->schema([
                ChatHistoryEntry::make('messages')
                    ->hiddenLabel(),
            ]);
    }

    private static function resolutionHistorySection(): Section
    {
        return Section::make('Admin resolution history')
            ->icon(Heroicon::ClipboardDocumentList)
            ->schema([
                TextEntry::make('admin_resolutions_summary')
                    ->hiddenLabel()
                    ->state(fn (GameMatch $record) => self::resolutionSummary($record))
                    ->html(),
            ])
            ->visible(fn (GameMatch $record) => $record->adminResolutions->isNotEmpty());
    }

    /**
     * Per-match auto-fetch audit. Lets admins answer "why is this match in
     * ManualReview?" by reading the full attempt history.
     */
    private static function autoFetchHistorySection(): Section
    {
        return Section::make('Auto-fetch history')
            ->icon(Heroicon::ArrowPath)
            ->schema([
                TextEntry::make('auto_fetch_summary')
                    ->hiddenLabel()
                    ->state(fn (GameMatch $record) => self::autoFetchSummary($record))
                    ->html(),
            ])
            ->visible(fn (GameMatch $record) => $record->autoFetchAttempts->isNotEmpty());
    }

    private static function isTeamPlay(GameMatch $record): bool
    {
        return $record->listing?->isTeamPlay() ?? false;
    }

    private static function pot(GameMatch $record): string
    {
        return bcmul(
            (string) ($record->listing?->stake_amount ?? '0'),
            (string) (((int) ($record->listing?->team_size ?? 1)) * 2),
            6,
        );
    }

    private static function fee(GameMatch $record): string
    {
        return bcmul(self::pot($record), (string) config('stakly.platform_fee_rate'), 6);
    }

    private static function isWinner(GameMatch $record, ?int $playerId): bool
    {
        return $record->status === MatchStatus::Settled
            && $playerId !== null
            && $record->winner_user_id === $playerId;
    }

    /**
     * Snapshotted provider handles for one snapshot `side` ('creator' / 'taker'
     * for 1v1; 'a' / 'b' for team-play), one line per (provider, slot). Reads
     * the loaded `providerSnapshots` collection — no query.
     */
    private static function providerHandlesHtml(GameMatch $record, string $side): string
    {
        $snaps = $record->providerSnapshots
            ->where('side', $side)
            ->sortBy(['slot_index', 'provider']);

        if ($snaps->isEmpty()) {
            return '<span class="text-sm text-gray-500">No provider accounts snapshotted.</span>';
        }

        $rows = $snaps->map(function (MatchProviderSnapshot $snap): string {
            $provider = e($snap->provider->displayName());
            $username = e((string) $snap->username);
            $rating = $snap->skill_rating_snapshot !== null
                ? ' · <span class="text-gray-500">rating '.e((string) $snap->skill_rating_snapshot).'</span>'
                : '';

            return "<div class=\"text-sm\">{$provider}: <strong>{$username}</strong>{$rating}</div>";
        })->join('');

        return "<div class=\"space-y-0.5\">{$rows}</div>";
    }

    /**
     * One side of a team roster: each live lobby slot's Stakly user (linked to
     * the admin user view) annotated with the match-time verified handle +
     * rating from the snapshot for the listing's platform. Winner's side row
     * carries a "Winner" badge.
     */
    private static function teamRosterHtml(GameMatch $record, string $side): string
    {
        $listing = $record->listing;

        if ($listing === null) {
            return '<span class="text-sm text-gray-500">—</span>';
        }

        $platform = $listing->platform;

        $participants = $listing->lobbyParticipants
            ->whereNull('kicked_at')
            ->where('side', $side)
            ->sortBy('slot_index');

        if ($participants->isEmpty()) {
            return '<span class="text-sm text-gray-500">No players on this side.</span>';
        }

        $rows = $participants->map(function (LobbyParticipant $participant) use ($record, $platform): string {
            $user = $participant->user;

            $name = $user !== null
                ? '<a href="'.e(route('filament.admin.resources.users.view', $user)).'" class="underline">@'.e($user->username).'</a>'
                : 'Unknown';

            $winnerBadge = ($user !== null && $record->winner_user_id === $user->id)
                ? ' <span style="display:inline-block;padding:1px 6px;border-radius:9999px;font-size:0.7rem;font-weight:600;color:#d97706;background:#fef3c7;">Winner</span>'
                : '';

            $snap = $record->providerSnapshots->first(
                fn (MatchProviderSnapshot $s): bool => $s->side === $participant->side
                    && $s->slot_index === $participant->slot_index
                    && $s->provider === $platform,
            );

            $handle = $snap?->username !== null
                ? e($platform->displayName()).': <strong>'.e((string) $snap->username).'</strong>'
                : '<span class="text-gray-500">no '.e($platform->displayName()).' handle snapshotted</span>';

            $rating = $snap?->skill_rating_snapshot !== null
                ? ' · rating '.e((string) $snap->skill_rating_snapshot)
                : '';

            return '<div class="py-1.5">'
                ."<div class=\"text-sm\">{$name}{$winnerBadge}</div>"
                ."<div class=\"text-xs text-gray-500\">{$handle}{$rating}</div>"
                .'</div>';
        })->join('');

        return "<div class=\"divide-y divide-gray-100 dark:divide-white/10\">{$rows}</div>";
    }

    private static function resolutionSummary(GameMatch $record): string
    {
        $rows = $record->adminResolutions;

        if ($rows->isEmpty()) {
            return '<em>No admin resolutions yet.</em>';
        }

        $html = '<div class="space-y-2">';
        foreach ($rows as $row) {
            $when = $row->created_at->format('M j, Y H:i');
            $admin = e($row->admin->name);
            $action = e($row->action->label());
            $winner = $row->winner ? ' → '.e($row->winner->username) : '';
            $reason = e($row->reason);
            $html .= "<div><strong>{$when}</strong> · {$admin} · {$action}{$winner}<br><span class=\"text-sm opacity-75\">{$reason}</span></div>";
        }
        $html .= '</div>';

        return $html;
    }

    private static function autoFetchSummary(GameMatch $record): string
    {
        $rows = $record->autoFetchAttempts;

        if ($rows->isEmpty()) {
            return '<em>No auto-fetch attempts yet.</em>';
        }

        $html = '<div class="space-y-2">';
        foreach ($rows as $row) {
            $html .= self::autoFetchRowHtml($row);
        }
        $html .= '</div>';

        return $html;
    }

    private static function autoFetchRowHtml(MatchAutoFetchAttempt $row): string
    {
        $when = $row->created_at->format('M j, Y H:i:s');
        $provider = e($row->provider->displayName());
        $badge = self::outcomeBadge($row->outcome);
        $detail = self::outcomeDetail($row);
        $attempt = $row->attempt_number > 1 ? " · attempt #{$row->attempt_number}" : '';

        return "<div><strong>{$when}</strong> · {$provider} · {$badge}{$attempt}<br>"
            ."<span class=\"text-sm opacity-75\">{$detail}</span></div>";
    }

    /**
     * Inline-styled span — we're rendering raw HTML inside a TextEntry so we
     * can't compose Filament's Badge component. Color + label come from the
     * enum (single source of truth) so a new `AutoFetchOutcome` case can't
     * 500 this page with an UnhandledMatchError.
     */
    private static function outcomeBadge(AutoFetchOutcome $outcome): string
    {
        [$fg, $bg] = $outcome->badgeColors();
        $label = e($outcome->label());

        return "<span style=\"display:inline-block;padding:2px 8px;border-radius:9999px;font-size:0.75rem;font-weight:600;color:{$fg};background:{$bg};\">{$label}</span>";
    }

    private static function outcomeDetail(MatchAutoFetchAttempt $row): string
    {
        return match ($row->outcome) {
            AutoFetchOutcome::Matched => 'Winner: '.e($row->winner_username ?? '—')
                .' · '.self::latencyLabel($row->latency_ms),
            AutoFetchOutcome::NoMatch => '0 candidates'
                .($row->outcome_reason ? ' · '.e($row->outcome_reason) : '')
                .' · '.self::latencyLabel($row->latency_ms),
            AutoFetchOutcome::Ambiguous => ($row->candidates_count ?? 0).' candidates'
                .($row->outcome_reason ? ' · '.e($row->outcome_reason) : '')
                .' · '.self::latencyLabel($row->latency_ms),
            AutoFetchOutcome::AcIncomplete => 'FACEIT anti-cheat not enforced on every roster slot'
                .($row->outcome_reason ? ' · '.e($row->outcome_reason) : '')
                .' · '.self::latencyLabel($row->latency_ms),
            AutoFetchOutcome::Error => e($row->error_message ?? 'Unknown error')
                .' · '.self::latencyLabel($row->latency_ms),
            AutoFetchOutcome::Skipped => 'Reason: '.e($row->outcome_reason ?? 'unspecified'),
        };
    }

    private static function latencyLabel(?int $ms): string
    {
        return $ms === null ? 'no provider call' : "{$ms}ms";
    }
}
