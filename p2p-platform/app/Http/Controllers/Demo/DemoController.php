<?php

declare(strict_types=1);

namespace App\Http\Controllers\Demo;

use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Models\LedgerAccount;
use App\Models\LoanInvestment;
use App\Models\LoanRequest;
use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The screens whose write path is not built yet.
 *
 * These pages render the complete UI but perform NO ledger write. The receive
 * screen shows the real, live balance (read from the database) and then refuses
 * to issue a receiving handle, because there is no verified inbound channel
 * behind the account yet.
 *
 * Nothing here writes a fake transaction, invents a balance, or presents a
 * simulated transfer as settled. The pages say so explicitly.
 */
class DemoController extends Controller
{
    /**
     * The receive-money screen.
     *
     * On a lending platform, money arrives in a member's wallet in a handful of
     * ways — a disbursed loan principal, a repayment share from a borrower, or an
     * "Add Money" top-up — and this screen points at each of them.
     */
    public function receive(Request $request): Response
    {
        $user = $request->user();

        $account = LedgerAccount::query()->where('owner_user_id', $user->id)->first();

        $wallet = $account === null
            ? null
            : Wallet::query()->where('account_id', $account->id)->first();

        $disbursedMinor = (int) LoanRequest::query()
            ->where('borrower_user_id', $user->id)
            ->whereNotNull('funded_at')
            ->sum('principal_minor');

        $committedMinor = (int) LoanInvestment::query()
            ->where('lender_user_id', $user->id)
            ->sum('amount_minor');

        return Inertia::render('receive/index', [
            'wallet' => [
                'id' => $wallet?->id,
                'name' => 'Primary BDT Wallet',
                'currency' => $wallet === null ? 'BDT' : $wallet->currency,
                'accountId' => $account?->id,
                'balanceMinor' => $wallet === null ? 0 : $wallet->balance_minor,
                'createdAt' => $wallet?->created_at?->format('d M Y') ?? '',
            ],
            'incoming' => [
                'disbursedMinor' => $disbursedMinor,
                'committedMinor' => $committedMinor,
            ],
            'availability' => [
                'enabled' => false,
                'reason' => 'A shareable receiving handle is not issued yet. The account exists, but there is no verified inbound channel behind it. On this platform money reaches your wallet through the loan workflow — a disbursed principal, a repayment share — or through Add Money.',
            ],
        ]);
    }

    /**
     * The notifications screen.
     *
     * Notifications are derived from the audit trail and the user's own
     * records — there is no separate notification store yet.
     */
    public function notifications(Request $request): Response
    {
        $user = $request->user();

        $account = LedgerAccount::query()->where('owner_user_id', $user->id)->first();

        $accountId = $account === null ? 0 : $account->id;

        $recent = Transaction::query()
            ->where(function ($builder) use ($accountId): void {
                $builder->where('sender_account_id', $accountId)
                    ->orWhere('recipient_account_id', $accountId);
            })
            ->latest()
            ->limit(10)
            ->get();

        return Inertia::render('notifications/index', [
            'notifications' => $recent->map(fn (Transaction $t): array => [
                'id' => 'txn-'.$t->id,
                'title' => $t->direction->value === 'CREDIT'
                    ? 'Money received'
                    : 'Transfer sent',
                'body' => sprintf(
                    '%s — %s %s',
                    $t->reference,
                    $t->counterparty_name !== '' ? $t->counterparty_name : 'Counterparty',
                    $t->status->label(),
                ),
                'at' => $t->created_at?->format('d M Y, H:i') ?? '',
                'read' => true,
                'tone' => $t->status->value === 'FAILED' ? 'danger' : 'info',
            ])->all(),
            'availability' => [
                'enabled' => false,
                'reason' => 'There is no notification store yet. The list below is derived from your own transfer history, so it shows records rather than stored alerts.',
            ],
        ]);
    }

    /**
     * The operational reports screen.
     */
    public function reports(Request $request): Response
    {
        $accounts = LedgerAccount::query()->count();
        $wallets = Wallet::query()->count();
        $transactions = Transaction::query()->count();

        $byStatus = Transaction::query()
            ->selectRaw('status')
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('COALESCE(SUM(amount_minor), 0) AS volume')
            ->groupBy('status')
            // The aggregates are raw SQL aliases, so the result is a base query
            // rather than a set of transaction models.
            ->toBase()
            ->get();

        return Inertia::render('admin/reports', [
            'totals' => [
                'accounts' => $accounts,
                'wallets' => $wallets,
                'transactions' => $transactions,
            ],
            'byStatus' => $byStatus->map(fn (\stdClass $row): array => [
                'status' => (string) $row->status,
                'label' => TransactionStatus::tryFrom((string) $row->status)?->label() ?? (string) $row->status,
                'count' => (int) $row->total,
                'volumeMinor' => (int) $row->volume,
            ])->all(),
            'availability' => [
                'enabled' => false,
                'reason' => 'Settlement-network reporting, time-series analytics and scheduled exports are not implemented. These figures are live counts from the current tables.',
            ],
        ]);
    }
}
