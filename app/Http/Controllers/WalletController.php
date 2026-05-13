<?php

namespace App\Http\Controllers;

use App\Enums\WalletTransactionType;
use App\Http\Requests\Wallet\IndexHistoryRequest;
use App\Http\Requests\Wallet\WithdrawRequest;
use App\Http\Resources\WalletTransactionResource;
use App\Models\WalletTransaction;
use App\Services\Wallet;
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
            ->with('listing:id,game')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::INDEX_RECENT_LIMIT)
            ->get();

        return Inertia::render('wallet/index', [
            'balance' => (float) Wallet::balanceFor($user),
            'recentTransactions' => WalletTransactionResource::collection($recent),
        ]);
    }

    /**
     * Deposit page: shows the user's TRC20 address + QR (rendered client-side
     * by `qrcode.react` in Phase 4). v1 addresses are mocks — real HD-derived
     * addresses replace them at the pre-launch chain integration gate.
     */
    public function deposit(Request $request): Response
    {
        $user = $request->user();
        abort_if($user->is_platform, 403);

        return Inertia::render('wallet/deposit', [
            'tronAddress' => $user->tron_address,
        ]);
    }

    /**
     * Withdrawal form. The form fully validates in v1 (TRC20 regex, min, ≤
     * balance) but `withdrawStore` short-circuits with a launch-gated notice
     * — see milestones.md M7 locked decisions.
     */
    public function withdraw(Request $request): Response
    {
        $user = $request->user();
        abort_if($user->is_platform, 403);

        return Inertia::render('wallet/withdraw', [
            'balance' => (float) Wallet::balanceFor($user),
            'minWithdrawal' => WithdrawRequest::MIN_WITHDRAWAL,
        ]);
    }

    /**
     * v1 short-circuit: form validates fully (so all 422 paths can be exercised
     * end-to-end today), but submit returns the user back with an info toast
     * — no ledger write. At launch the worker takes over; this method becomes
     * a `Wallet::withdraw` + queue dispatch.
     *
     * Defensive `abort_if` is here so even the platform user — which should
     * never reach this route given middleware + the GET-page guards — cannot
     * accidentally trigger a notice payload.
     */
    public function withdrawStore(WithdrawRequest $request): RedirectResponse
    {
        abort_if($request->user()->is_platform, 403);

        Inertia::flash('toast', [
            'type' => 'info',
            'message' => __('Withdrawals will be enabled at launch — your balance is safe.'),
        ]);

        return back();
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
                ->with('listing:id,game'),
        )
            ->allowedFilters([
                AllowedFilter::exact('type'),
            ])
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
