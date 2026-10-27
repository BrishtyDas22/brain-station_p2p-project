<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\KycReviewController;
use App\Http\Controllers\Admin\MonitoringController;
use App\Http\Controllers\Admin\ReconciliationController;
use App\Http\Controllers\Admin\ReversalController;
use App\Http\Controllers\Admin\SettlementController;
use App\Http\Controllers\Admin\UserManagementController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Demo\DemoController;
use App\Http\Controllers\KycController;
use App\Http\Controllers\LoanController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\WalletController;
use App\Http\Controllers\WalletFundingController;
use App\Http\Controllers\WithdrawalController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| P2P platform routes
|--------------------------------------------------------------------------
|
| Every route here is served through Inertia — there is no separate REST API.
| A request goes: React -> Inertia -> controller -> validation / service
| layer -> MySQL -> Inertia response -> React.
|
*/

/*
|--------------------------------------------------------------------------
| Customer area
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::get('wallet', WalletController::class)->name('wallet');

    Route::get('transactions', [TransactionController::class, 'index'])->name('transactions.index');
    Route::get('transactions/{transaction}', [TransactionController::class, 'show'])->name('transactions.show');

    Route::get('kyc', [KycController::class, 'show'])->name('kyc.index');
    Route::post('kyc', [KycController::class, 'store'])->name('kyc.store');

    // Screens whose write path is not implemented. They render the full UI
    // and are explicitly labelled DEMO — no ledger write happens here.
    Route::get('receive', [DemoController::class, 'receive'])->name('receive');
    Route::get('notifications', [DemoController::class, 'notifications'])->name('notifications');

    // The "Add Money" flow. A simulated payment gateway tops up the user's REAL,
    // ledger-backed main wallet: a successful attempt posts a balanced pair of
    // `ledger_entries` (a DEBIT against the sandbox funding account and an equal
    // CREDIT against the user's ledger account) and advances the wallet projection
    // by exactly that amount.
    //
    // The gateway is simulated — no provider is contacted and no real money moves —
    // but the ACCOUNTING is real. See `config/p2p-funding.php` and the OpenSpec
    // change `add-sandbox-wallet-funding` for the sandbox funding policy.
    Route::prefix('wallet/fundings')->name('wallet.fundings.')->group(function (): void {
        Route::get('/', [WalletFundingController::class, 'index'])->name('index');
        Route::post('/', [WalletFundingController::class, 'store'])->name('store');
        Route::get('history', [WalletFundingController::class, 'history'])->name('history');
        Route::get('{funding}', [WalletFundingController::class, 'show'])->name('show');
        Route::post('{funding}/settle', [WalletFundingController::class, 'settle'])->name('settle');
    });

    // The "Withdraw Money" flow. The mirror image of "Add Money": a successful
    // payout posts a balanced pair of `ledger_entries` (a DEBIT against the user's
    // ledger account and an equal CREDIT against the sandbox payout account) and
    // advances the wallet projection down by exactly that amount.
    //
    // The payout is SIMULATED — no provider is contacted, no real money moves and no
    // credential is ever collected — but the ACCOUNTING is real.
    Route::prefix('wallet/withdrawals')->name('wallet.withdrawals.')->group(function (): void {
        Route::get('/', [WithdrawalController::class, 'index'])->name('index');
        Route::post('/', [WithdrawalController::class, 'store'])->name('store');
        Route::get('history', [WithdrawalController::class, 'history'])->name('history');
        Route::get('{withdrawal}', [WithdrawalController::class, 'show'])->name('show');
        Route::post('{withdrawal}/settle', [WithdrawalController::class, 'settle'])->name('settle');
    });

    // The loan marketplace.
    //
    // A lender commits capital to a listing using ONLY their existing wallet balance —
    // there is no external gateway in this path, because no money leaves the platform
    // when a lender commits: it moves from one internal wallet into escrow custody.
    // At 100% funding the escrow releases the principal into the borrower's wallet, as
    // a balanced pair of `ledger_entries`.
    //
    // There is NO admin approval step for an individual loan funding. The PRD does not
    // define one, and inventing one would stall every loan behind a manual queue.
    Route::prefix('loans')->name('loans.')->group(function (): void {
        Route::get('/', [LoanController::class, 'index'])->name('index');
        Route::post('/', [LoanController::class, 'store'])->name('store');
        Route::get('my', [LoanController::class, 'myLoans'])->name('mine');
        Route::get('investments', [LoanController::class, 'myInvestments'])->name('investments');
        Route::get('{loan}', [LoanController::class, 'show'])->name('show');
        Route::post('{loan}/fund', [LoanController::class, 'fund'])->name('fund');
        Route::post('installments/{installment}/collect', [LoanController::class, 'collect'])->name('installments.collect');
    });
});

/*
|--------------------------------------------------------------------------
| Administrator area
|--------------------------------------------------------------------------
|
| Guarded by three layers: the `admin` middleware, the controller's own
| service-level role check, and the fact that a non-admin can never reach
| these routes because the middleware aborts with 403 before the controller
| runs.
|
*/
Route::middleware(['auth', 'verified', 'admin'])->prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/', AdminDashboardController::class)->name('dashboard');

    Route::get('users', [UserManagementController::class, 'index'])->name('users');
    Route::patch('users/{user}/role', [UserManagementController::class, 'updateRole'])->name('users.role');

    Route::get('kyc', [KycReviewController::class, 'index'])->name('kyc');
    Route::post('kyc/{submission}/review', [KycReviewController::class, 'beginReview'])->name('kyc.review');
    Route::post('kyc/{submission}/approve', [KycReviewController::class, 'approve'])->name('kyc.approve');
    Route::post('kyc/{submission}/reject', [KycReviewController::class, 'reject'])->name('kyc.reject');

    Route::get('monitoring', MonitoringController::class)->name('monitoring');

    Route::get('settlement', SettlementController::class)->name('settlement');

    Route::get('reversals', ReversalController::class)->name('reversals');

    Route::get('audit', [AuditController::class, 'index'])->name('audit');

    Route::get('reconciliation', ReconciliationController::class)->name('reconciliation');

    Route::get('reports', [DemoController::class, 'reports'])->name('reports');
});
