<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\LinkedAccountProvider;
use App\Enums\MatchStatus;
use App\Enums\WalletTransactionType;
use App\Models\GameMatch;
use App\Models\LinkedAccount;
use App\Models\LinkedAccountRating;
use App\Models\Listing;
use App\Models\LobbyParticipant;
use App\Models\User;
use App\Models\WalletTransaction;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Admin user view. Stacked sections, inline HTML where Filament's component
 * tree can't compose what we need (matches `GameMatchInfolist::autoFetchSummary`
 * precedent). All sections are `columnSpanFull()` because Filament's default
 * panel grid is 3-column — without it, sections collide horizontally and
 * inner fields wrap awkwardly.
 */
class UserInfolist
{
    private const RECENT_LISTINGS_LIMIT = 10;

    private const RECENT_MATCHES_LIMIT = 10;

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                self::profileSection()->columnSpanFull(),
                Grid::make(2)
                    ->columnSpanFull()
                    ->schema([
                        self::linkedAccountsSection(),
                        self::walletSection(),
                    ]),
                self::usernameHistorySection()->columnSpanFull(),
                self::recentListingsSection()->columnSpanFull(),
                self::recentMatchesSection()->columnSpanFull(),
                self::openDisputesSection()->columnSpanFull(),
            ]);
    }

    // ─── Profile summary ───────────────────────────────────────────────────

    private static function profileSection(): Section
    {
        return Section::make('Profile')
            ->icon(Heroicon::User)
            ->columns(4)
            ->schema([
                TextEntry::make('id')->label('User #'),
                TextEntry::make('username')->copyable(),
                TextEntry::make('name'),
                TextEntry::make('email')->copyable(),
                IconEntry::make('email_verified_at')
                    ->label('Email verified')
                    ->boolean(),
                IconEntry::make('two_factor_confirmed_at')
                    ->label('2FA enrolled')
                    ->boolean(),
                TextEntry::make('banned_at')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state ? 'Banned' : 'Active')
                    ->color(fn ($state): string => $state ? 'danger' : 'success'),
                TextEntry::make('created_at')
                    ->label('Member since')
                    ->dateTime('M j, Y')
                    ->since(),
                TextEntry::make('bio')
                    ->placeholder('—')
                    ->columnSpanFull(),
            ]);
    }

    // ─── Linked accounts ───────────────────────────────────────────────────

    private static function linkedAccountsSection(): Section
    {
        return Section::make('Linked game accounts')
            ->icon(Heroicon::Link)
            ->schema([
                TextEntry::make('linked_accounts_summary')
                    ->hiddenLabel()
                    ->state(fn (User $record) => self::linkedAccountsSummary($record))
                    ->html(),
            ]);
    }

    private static function linkedAccountsSummary(User $record): string
    {
        $record->loadMissing('linkedAccounts.ratings');

        if ($record->linkedAccounts->isEmpty()) {
            return '<em>No verified game accounts linked.</em>';
        }

        $html = '<div class="space-y-1">';
        foreach ($record->linkedAccounts as $link) {
            $provider = e($link->provider->displayName());
            $username = e($link->username);
            $ratings = e(self::linkedAccountRatingLabel($link));
            $verifiedAt = $link->verified_at?->format('M j, Y') ?? 'unverified';
            $html .= "<div><strong>{$provider}</strong> · {$username} · {$ratings} · <span class=\"opacity-75\">verified {$verifiedAt}</span></div>";
        }
        $html .= '</div>';

        return $html;
    }

    /**
     * Chess providers (chess.com / Lichess) carry per-time-control ratings;
     * everything else uses the scalar `skill_rating`. A chess link with no
     * rating rows reads "Unrated"; a missing scalar reads "—".
     */
    private static function linkedAccountRatingLabel(LinkedAccount $link): string
    {
        $isChess = in_array(
            $link->provider,
            [LinkedAccountProvider::ChessCom, LinkedAccountProvider::Lichess],
            true,
        );

        if (! $isChess) {
            return $link->skill_rating !== null ? (string) $link->skill_rating : '—';
        }

        if ($link->ratings->isEmpty()) {
            return 'Unrated';
        }

        return $link->ratings
            ->map(function (LinkedAccountRating $rating): string {
                $value = $rating->rating.($rating->is_provisional ? '?' : '');

                return ucfirst($rating->time_control->value).' '.$value;
            })
            ->implode(' · ');
    }

    // ─── Wallet snapshot + invariant verify ────────────────────────────────

    private static function walletSection(): Section
    {
        return Section::make('Wallet snapshot')
            ->icon(Heroicon::Banknotes)
            ->schema([
                TextEntry::make('wallet_snapshot')
                    ->hiddenLabel()
                    ->state(fn (User $record) => self::walletSnapshot($record))
                    ->html(),
            ]);
    }

    /**
     * Renders current balance, per-type sums, and the invariant check
     * (balance must equal SUM(wallet_transactions.amount)). Red badge if
     * drift — should never happen, exists to catch a regression early.
     */
    private static function walletSnapshot(User $record): string
    {
        $balance = (string) $record->usdt_balance;

        $ledgerSum = (string) (WalletTransaction::query()
            ->where('user_id', $record->id)
            ->sum('amount') ?? '0');

        $drifts = bccomp($balance, $ledgerSum, 6) !== 0;

        $typeSums = WalletTransaction::query()
            ->where('user_id', $record->id)
            ->selectRaw('type, COALESCE(SUM(amount), 0) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $rows = '<div><strong>Balance:</strong> $'.number_format((float) $balance, 2).'</div>';
        foreach (WalletTransactionType::cases() as $type) {
            $sum = (string) ($typeSums[$type->value] ?? '0');
            $rows .= '<div class="opacity-75"><strong>'.e($type->value).':</strong> $'
                .number_format((float) $sum, 2).'</div>';
        }

        $badgePalette = $drifts ? ['#dc2626', '#fee2e2'] : ['#16a34a', '#dcfce7'];
        $badgeLabel = $drifts
            ? 'DRIFT — balance $'.number_format((float) $balance, 2)
                .' vs ledger sum $'.number_format((float) $ledgerSum, 2)
            : 'Balance matches ledger sum';

        $badge = '<div style="margin-top:0.5rem;display:inline-block;padding:4px 10px;border-radius:9999px;font-size:0.75rem;font-weight:600;color:'
            .$badgePalette[0].';background:'.$badgePalette[1].';">'.e($badgeLabel).'</div>';

        return '<div class="space-y-1">'.$rows.$badge.'</div>';
    }

    // ─── Username history ──────────────────────────────────────────────────

    private static function usernameHistorySection(): Section
    {
        return Section::make('Username history')
            ->icon(Heroicon::ArrowUturnLeft)
            ->schema([
                TextEntry::make('username_history_summary')
                    ->hiddenLabel()
                    ->state(fn (User $record) => self::usernameHistorySummary($record))
                    ->html(),
            ])
            ->visible(fn (User $record) => $record->usernameHistory()->exists());
    }

    private static function usernameHistorySummary(User $record): string
    {
        $rows = $record->usernameHistory()->orderByDesc('created_at')->get();

        $html = '<div class="space-y-1">';
        foreach ($rows as $row) {
            $username = e($row->username);
            $changedAt = $row->created_at->format('M j, Y');
            $reserved = $row->released_at?->isFuture()
                ? ' · <span style="color:#d97706;">reserved until '.$row->released_at->format('M j, Y').'</span>'
                : ' · <span style="opacity:0.6;">reservation expired</span>';
            $html .= "<div><strong>{$username}</strong> · released {$changedAt}{$reserved}</div>";
        }
        $html .= '</div>';

        return $html;
    }

    // ─── Recent listings ───────────────────────────────────────────────────

    private static function recentListingsSection(): Section
    {
        return Section::make('Recent listings (last '.self::RECENT_LISTINGS_LIMIT.')')
            ->icon(Heroicon::Squares2x2)
            ->schema([
                TextEntry::make('recent_listings_summary')
                    ->hiddenLabel()
                    ->state(fn (User $record) => self::recentListingsSummary($record))
                    ->html(),
            ])
            ->visible(fn (User $record) => $record->listings()->exists());
    }

    private static function recentListingsSummary(User $record): string
    {
        $listings = $record->listings()
            ->orderByDesc('created_at')
            ->limit(self::RECENT_LISTINGS_LIMIT)
            ->get();

        $html = '<div class="space-y-1">';
        foreach ($listings as $listing) {
            $status = e($listing->status->value);
            $stake = '$'.number_format((float) $listing->stake_amount, 2);
            $when = $listing->created_at->format('M j, Y');
            $html .= "<div>#{$listing->id} · {$stake} USDT · {$status} · {$when}</div>";
        }
        $html .= '</div>';

        return $html;
    }

    // ─── Recent matches ────────────────────────────────────────────────────

    private static function recentMatchesSection(): Section
    {
        return Section::make('Recent matches (last '.self::RECENT_MATCHES_LIMIT.')')
            ->icon(Heroicon::Trophy)
            ->schema([
                TextEntry::make('recent_matches_summary')
                    ->hiddenLabel()
                    ->state(fn (User $record) => self::recentMatchesSummary($record))
                    ->html(),
            ])
            ->visible(fn (User $record) => self::matchesQuery($record)->exists());
    }

    private static function recentMatchesSummary(User $record): string
    {
        $matches = self::matchesQuery($record)
            ->with([
                'listing',
                'listing.user:id,username',
                'listing.lobbyParticipants.user:id,username',
                'taker',
                'winner',
            ])
            ->orderByDesc('created_at')
            ->limit(self::RECENT_MATCHES_LIMIT)
            ->get();

        $html = '<div class="space-y-1">';
        foreach ($matches as $match) {
            $status = e($match->status->value);
            $when = $match->created_at->format('M j, Y');
            $opponent = e(self::matchOpponentLabel($match, $record));
            $winner = $match->winner?->username
                ? ' · winner: '.e($match->winner->username)
                : '';
            $html .= "<div>#{$match->id} · vs {$opponent} · {$status}{$winner} · {$when}</div>";
        }
        $html .= '</div>';

        return $html;
    }

    /**
     * Opponent label for the recent-matches row. 1v1 resolves the other party
     * (creator or taker); team matches resolve the opposing side's live roster
     * usernames, falling back to a "team NvN" label when the viewer's side
     * can't be determined.
     */
    private static function matchOpponentLabel(GameMatch $match, User $record): string
    {
        $listing = $match->listing;

        if ($listing?->isTeamPlay()) {
            return self::teamMatchOpponentLabel($listing, $record);
        }

        $opponent = $listing?->user_id === $record->id
            ? $match->taker?->username
            : $listing?->user?->username;

        return $opponent ?? '—';
    }

    private static function teamMatchOpponentLabel(Listing $listing, User $record): string
    {
        $live = $listing->lobbyParticipants->whereNull('kicked_at');
        $viewerSide = $live->firstWhere('user_id', $record->id)?->side;

        if ($viewerSide !== null) {
            $names = $live
                ->where('side', '!=', $viewerSide)
                ->map(fn (LobbyParticipant $participant): ?string => $participant->user?->username)
                ->filter()
                ->values();

            if ($names->isNotEmpty()) {
                return $names->implode(', ');
            }
        }

        return 'team '.$listing->team_size.'v'.$listing->team_size;
    }

    private static function matchesQuery(User $record)
    {
        return GameMatch::query()->forRosterParticipant($record->id);
    }

    // ─── Open disputes ─────────────────────────────────────────────────────

    private static function openDisputesSection(): Section
    {
        return Section::make('Open disputes')
            ->icon(Heroicon::ExclamationTriangle)
            ->schema([
                TextEntry::make('open_disputes_summary')
                    ->hiddenLabel()
                    ->state(fn (User $record) => self::openDisputesSummary($record))
                    ->html(),
            ])
            ->visible(fn (User $record) => self::openDisputesQuery($record)->exists());
    }

    private static function openDisputesSummary(User $record): string
    {
        $disputes = self::openDisputesQuery($record)
            ->orderByDesc('dispute_opened_at')
            ->orderByDesc('updated_at')
            ->get();

        $html = '<div class="space-y-1">';
        foreach ($disputes as $match) {
            $status = e($match->status->value);
            $opened = $match->dispute_opened_at?->format('M j, Y H:i')
                ?? $match->updated_at->format('M j, Y H:i');
            $html .= "<div>#{$match->id} · {$status} · since {$opened}</div>";
        }
        $html .= '</div>';

        return $html;
    }

    private static function openDisputesQuery(User $record)
    {
        return GameMatch::query()
            ->forRosterParticipant($record->id)
            ->whereIn('status', [MatchStatus::Disputed, MatchStatus::ManualReview]);
    }
}
