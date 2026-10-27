<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\FundingStatus;
use App\Models\LedgerAccount;
use App\Models\Wallet;
use App\Models\WalletFunding;
use App\Services\WalletProvisioningService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The wallet overview.
 *
 * The wallet's balance is a PROJECTION maintained alongside the ledger. The
 * ledger entries remain the authority.
 *
 * ## Why this controller can write
 *
 * Normally a read-only page would not write. This one does, and deliberately:
 *
 *   - The user's wallet is PROVISIONED when their KYC is approved, in
 *     `KycService::decide()`.
 *   - This controller also ensures the wallet exists when the page is opened.
 *
 * The second step is the self-healing path for users whose wallet was never
 * created — for instance a user approved before the provisioning rule existed,
 * or one whose approval and provisioning were separated by a failure. Because
 * provisioning is idempotent and concurrency-safe, calling it on every page
 * view is harmless: a user who already has a wallet simply keeps it, and no
 * second wallet is ever created.
 *
 * A user who is NOT eligible (no approved KYC) is never provisioned here, so
 * the page correctly shows that there is no wallet, and why.
 */
class WalletController extends Controller
{
    public function __invoke(
        Request $request,
        WalletProvisioningService $wallets,
    ): Response {
        $user = $request->user();

        // Self-heal: an approved user always ends up with exactly one wallet,
        // no matter how they got there. This is a no-op for everyone else.
        $wallets->provision($user);

        $account = LedgerAccount::query()->where('owner_user_id', $user->id)->first();

        $wallet = $account === null
            ? null
            : Wallet::query()->where('account_id', $account->id)->first();

        $addedMinor = (int) WalletFunding::query()
            ->where('owner_user_id', $user->id)
            ->where('status', FundingStatus::SUCCEEDED->value)
            ->sum('amount_minor');

        return Inertia::render('wallet', [
            'wallet' => $wallet === null ? null : [
                'id' => $wallet->id,
                'name' => 'Primary BDT Wallet',
                'currency' => $wallet->currency,
                'balanceMinor' => $wallet->balance_minor,
                'reservedMinor' => $wallet->reserved_minor,
                'availableMinor' => $wallet->availableMinor(),
                'accountId' => $account->id,
                'ledgerSequence' => $account->next_sequence_number,
                'createdAt' => $wallet->created_at?->format('d M Y') ?? '',
            ],
            'account' => $account === null ? null : [
                'id' => $account->id,
                'currency' => $account->currency,
                'nextSequenceNumber' => $account->next_sequence_number,
                'createdAt' => $account->created_at?->format('d M Y') ?? '',
            ],
            'hasWallet' => $wallet !== null,
            'kycApproved' => $wallets->isEligible($user),
            'totals' => [
                'added' => $addedMinor,
            ],
        ]);
    }
}
