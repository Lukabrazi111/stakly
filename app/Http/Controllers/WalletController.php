<?php

namespace App\Http\Controllers;

use App\Enums\WalletTransactionType;
use App\Enums\WithdrawalStatus;
use App\Http\Requests\Wallet\IndexHistoryRequest;
use App\Http\Requests\Wallet\WithdrawRequest;
use App\Http\Resources\WalletTransactionResource;
use App\Http\Resources\WithdrawalResource;
use App\Models\WalletTransaction;
use App\Services\Payments\PaymentGateway;
use App\Services\Wallet;
use App\Services\Withdrawals;
use App\Services\WithdrawalTwoFactor;
use App\Services\WithdrawalVelocity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class WalletController extends Controller
{
    private const HISTORY_PER_PAGE = 20;

    private const INDEX_RECENT_LIMIT = 5;

    /**
     * Wallet overview: balance + 3 action cards + last 5 transactions inline.
     * Closes the listing-create → balance-changed feedback loop that's missing
     * pre-M7.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_if($user->is_platform, 403);

        $recent = $user->walletTransactions()
            ->with(['listing:id,game', 'listing.gameMatch:id,listing_id'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::INDEX_RECENT_LIMIT)
            ->get();

        return Inertia::render('wallet/index', [
            'balance' => (float) Wallet::balanceFor($user),
            'availableBalance' => (float) Wallet::availableBalance($user),
            'clearingBalance' => (float) Wallet::unclearedBalance($user),
            'nextClearanceAt' => Wallet::nextClearanceAt($user)?->toIso8601String(),
            'recentTransactions' => WalletTransactionResource::collection($recent),
            'pendingWithdrawals' => WithdrawalResource::collection(
                $user->withdrawals()
                    ->whereIn('status', [WithdrawalStatus::Pending, WithdrawalStatus::Sending])
                    ->orderByDesc('id')
                    ->get(),
            ),
        ]);
    }

    /**
     * Deposit page: shows the user's TRC20 address + QR (rendered client-side
     * by `qrcode.react`). The address is resolved through the active
     * `PaymentGateway` driver — `MockGateway` locally, a real custodial
     * provider once wired. The call is idempotent, so it doubles as lazy
     * provisioning for any user without an address yet.
     */
    public function deposit(Request $request): Response
    {
        $user = $request->user();
        abort_if($user->is_platform, 403);

        $account = app(PaymentGateway::class)->ensureDepositAccount($user);

        return Inertia::render('wallet/deposit', [
            'tronAddress' => $account->address,
        ]);
    }

    /**
     * Withdrawal form. Ships BOTH balances: the total, and the portion that's
     * actually withdrawable once payouts still inside their insurance window
     * are excluded (M9 Phase 0b). The form caps against `availableBalance`.
     *
     * The network-fee estimate comes from the active `PaymentGateway` driver
     * rather than config, so it tracks real chain conditions once a provider is
     * wired. `MockGateway` returns a flat figure and ignores both arguments.
     */
    public function withdraw(Request $request): Response
    {
        $user = $request->user();
        abort_if($user->is_platform, 403);

        $available = Wallet::availableBalance($user);
        $estimate = app(PaymentGateway::class)
            ->estimatePayoutFee($available, (string) $user->tron_address);

        return Inertia::render('wallet/withdraw', [
            'balance' => (float) Wallet::balanceFor($user),
            'availableBalance' => (float) $available,
            'clearingBalance' => (float) Wallet::unclearedBalance($user),
            'nextClearanceAt' => Wallet::nextClearanceAt($user)?->toIso8601String(),
            'minWithdrawal' => (float) WithdrawRequest::minWithdrawal(),
            'platformFee' => (float) config('stakly.withdrawal_margin'),
            'estimatedNetworkFee' => (float) $estimate->networkFee,
            // M9 Phase 0d — the form renders a code field, or an enrol prompt
            // when 2FA is required but not set up yet.
            'twoFactorRequired' => WithdrawalTwoFactor::required($user),
            'twoFactorEnrolled' => WithdrawalTwoFactor::hasEnrolled($user),
            // M9 Phase 0f — shown up front so a player sees the ceiling before
            // submitting rather than bouncing off a 422.
            'dailyLimit' => WithdrawalVelocity::enabled()
                ? (float) WithdrawalVelocity::limit()
                : null,
            'dailyRemaining' => WithdrawalVelocity::enabled()
                ? (float) WithdrawalVelocity::remainingToday($user)
                : null,
        ]);
    }

    /**
     * Debits the user and queues the payout. The anti-abuse hold already
     * happened upstream on the payout itself, so there's no review gate here.
     *
     * `WithdrawRequest` validates the address, minimum, and available balance;
     * `Withdrawals::request` re-asserts the balance under a row lock, because
     * validation and the debit aren't atomic together.
     *
     * Defensive `abort_if` is here so even the platform user — which should
     * never reach this route given middleware + the GET-page guards — cannot
     * move money.
     */
    public function withdrawStore(WithdrawRequest $request): RedirectResponse
    {
        $user = $request->user();
        abort_if($user->is_platform, 403);

        Withdrawals::request(
            user: $user,
            amount: (string) $request->validated('amount'),
            address: (string) $request->validated('address'),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Withdrawal requested — it is on its way.'),
        ]);

        return back();
    }

    /**
     * Full withdrawal history, paginated. Separate from the ledger view because
     * a withdrawal has a lifecycle (pending → sending → completed/failed) that
     * a single immutable ledger row can't express.
     */
    public function withdrawals(Request $request): Response
    {
        $user = $request->user();
        abort_if($user->is_platform, 403);

        return Inertia::render('wallet/withdrawals', [
            'withdrawals' => WithdrawalResource::collection(
                $user->withdrawals()
                    ->orderByDesc('id')
                    ->paginate(self::HISTORY_PER_PAGE)
                    ->withQueryString(),
            ),
        ]);
    }

    /**
     * Paginated ledger history. Same Spatie query-builder URL contract as
     * the listings index — type filter, page param. `WalletTransaction::query`
     * is scoped to the auth user; there is no path that lets one user view
     * another user's ledger.
     */
    public function history(IndexHistoryRequest $request): Response
    {
        $user = $request->user();
        abort_if($user->is_platform, 403);

        $transactions = QueryBuilder::for(
            WalletTransaction::query()
                ->where('user_id', $user->id)
                ->with(['listing:id,game', 'listing.gameMatch:id,listing_id']),
        )
            ->allowedFilters(
                AllowedFilter::exact('type'),
            )
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::HISTORY_PER_PAGE)
            ->withQueryString();

        return Inertia::render('wallet/history', [
            'transactions' => WalletTransactionResource::collection($transactions),
            'filters' => $request->filters(),
            'types' => array_map(fn (WalletTransactionType $t) => $t->value, WalletTransactionType::cases()),
        ]);
    }
}
