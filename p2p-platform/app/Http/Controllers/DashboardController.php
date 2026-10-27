<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\FundingStatus;
use App\Enums\KycStatus;
use App\Enums\LoanStatus;
use App\Enums\TransactionStatus;
use App\Models\LedgerAccount;
use App\Models\LoanInvestment;
use App\Models\LoanRequest;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Models\WalletFunding;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The authenticated user's dashboard.
 *
 * Everything here is read from the database. The balance shown is the user's REAL,
 * ledger-backed main wallet balance — the projection of their `ledger_entries`. An
 * "Add Money" credit appears here and in the transaction history, because it is a real
 * movement of the main wallet's balance.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        $account = LedgerAccount::query()
            ->where('owner_user_id', $user->id)
            ->first();

        $wallet = $account === null
            ? null
            : Wallet::query()->where('account_id', $account->id)->first();

        $accountId = $account === null ? 0 : $account->id;

        $transactions = Transaction::query()
            ->where('sender_account_id', $accountId)
            ->orWhere('recipient_account_id', $accountId)
            ->latest()
            ->limit(5)
            ->get();

        $kyc = $user->kycSubmission()->first();
        $kycStatus = $kyc === null ? KycStatus::NOT_SUBMITTED : $kyc->status;

        $balanceMinor = $wallet === null ? 0 : $wallet->balance_minor;
        $reservedMinor = $wallet === null ? 0 : $wallet->reserved_minor;

        // The total the user has added through "Add Money". This is the sum of the
        // successful funding attempts, which is exactly the credit side of the ledger
        // postings those attempts made.
        $addedMinor = (int) WalletFunding::query()
            ->where('owner_user_id', $user->id)
            ->where('status', FundingStatus::SUCCEEDED->value)
            ->sum('amount_minor');

        // The lending position of this user, in one place.
        //
        //  - `borrowed` is the principal disbursed to this user and not yet repaid;
        //  - `committed` is the capital this user has locked in escrow by funding
        //    other members' loans;
        //  - `earning` is the principal that has been disbursed and is generating
        //    repayments for this user.
        $borrowedMinor = (int) LoanRequest::query()
            ->where('borrower_user_id', $user->id)
            ->whereIn('status', [LoanStatus::DISBURSED->value, LoanStatus::REPAYING->value])
            ->sum('principal_minor');

        $committedMinor = (int) LoanInvestment::query()
            ->where('lender_user_id', $user->id)
            ->sum('amount_minor');

        $earningLoanIds = LoanInvestment::query()
            ->where('lender_user_id', $user->id)
            ->pluck('loan_request_id')
            ->all();

        $earningMinor = (int) LoanRequest::query()
            ->whereIn('id', $earningLoanIds)
            ->whereIn('status', [LoanStatus::DISBURSED->value, LoanStatus::REPAYING->value, LoanStatus::REPAID->value])
            ->sum('principal_minor');

        return Inertia::render('dashboard', [
            'account' => $account === null ? null : [
                'id' => $account->id,
                'currency' => $account->currency,
                'nextSequenceNumber' => $account->next_sequence_number,
            ],
            'wallet' => $wallet === null ? null : [
                'id' => $wallet->id,
                'name' => 'Primary BDT Wallet',
                'balanceMinor' => $balanceMinor,
                'reservedMinor' => $reservedMinor,
                'availableMinor' => $balanceMinor - $reservedMinor,
            ],
            'kycStatus' => $kycStatus->value,
            'kycLabel' => $kycStatus->label(),
            'recentTransactions' => $transactions->map(fn (Transaction $t): array => [
                'id' => $t->id,
                'reference' => $t->reference,
                'counterpartyName' => $t->counterparty_name,
                'amountMinor' => $t->amount_minor,
                'direction' => $t->direction->value,
                'status' => $t->status->value,
                'statusLabel' => $t->status->label(),
                'createdAt' => $t->created_at?->format('d M Y, H:i') ?? '',
            ])->all(),
            'totals' => [
                'sent' => (int) Transaction::query()
                    ->where('sender_account_id', $accountId)
                    ->whereNotIn('status', [TransactionStatus::FAILED->value])
                    ->sum('amount_minor'),
                'received' => (int) Transaction::query()
                    ->where('recipient_account_id', $accountId)
                    ->sum('amount_minor'),
                'count' => Transaction::query()
                    ->where('sender_account_id', $accountId)
                    ->orWhere('recipient_account_id', $accountId)
                    ->count(),
                'added' => $addedMinor,
            ],
            'lending' => [
                'borrowedMinor' => $borrowedMinor,
                'committedMinor' => $committedMinor,
                'earningMinor' => $earningMinor,
            ],
        ]);
    }
}
