<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\KycStatus;
use App\Enums\LedgerAccountType;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Gives an eligible user their ledger account and their wallet.
 *
 * ## What this creates, and what it deliberately does not
 *
 * It creates exactly two rows: a USER-type `ledger_accounts` row and the
 * `wallets` row that points at it. Both are created in ONE transaction, as
 * architecture decision **A3** requires — the wallet↔account mapping is
 * created once, in the same transaction that creates the wallet, and is never
 * updated.
 *
 * The wallet's balance is **exactly zero**. There is no approved funding path
 * (H-1 — SYSTEM accounts — is 🟠 Proposed, not approved), so a non-zero
 * starting balance would be inventing money. This service creates the *capacity*
 * to hold money, not money. It writes **no** `ledger_entries`, and therefore
 * appends nothing to the hash chain and never touches the chain head.
 *
 * ## Eligibility
 *
 * A user is eligible when their KYC submission is `APPROVED`. This is the
 * specification's own precondition: SPEC §11.1 states that "KYC status
 * (`KYC_VERIFIED`) is a hard precondition for any ledger write path", and
 * §7.1 step 2 requires the check before a payer wallet may be used. A user
 * without an approved KYC gets no wallet, because a wallet without an approved
 * identity would be a wallet that could never legally be used.
 *
 * ## Idempotency and concurrency
 *
 * The operation is safe to run any number of times, from any number of
 * concurrent workers:
 *
 *   - `uq_ledger_accounts_owner` (UNIQUE on `owner_user_id`) and
 *     `uq_wallets_account` (UNIQUE on `account_id`) make a duplicate
 *     physically impossible at the data layer;
 *   - the read-then-write is guarded by a `lockForUpdate` on the user row, so
 *     two concurrent callers serialise on the same owner;
 *   - a unique-constraint violation (MySQL 1062) is treated as "someone else
 *     won the race" and the winner's rows are returned, not an error.
 *
 * The database constraints are the real guarantee; the lock and the retry are
 * what make the common path quiet rather than noisy.
 */
final class WalletProvisioningService
{
    /**
     * MySQL's ER_DUP_ENTRY. Raised when a unique index rejects the write.
     */
    private const DUPLICATE_ENTRY = 1062;

    /**
     * The currency every wallet and account uses. BDT only — enforced by the
     * `ck_wallets_currency` and `ck_ledger_accounts_currency` CHECK constraints.
     */
    private const CURRENCY = 'BDT';

    /**
     * Whether a user is eligible for a wallet: their KYC is APPROVED.
     */
    public function isEligible(User $user): bool
    {
        return $user->kycSubmission()->where('status', KycStatus::APPROVED->value)->exists();
    }

    /**
     * Provision the user's ledger account and wallet, if they are eligible.
     *
     * Returns the wallet, or null when the user is not eligible. Calling this
     * repeatedly is safe and never produces a second wallet.
     */
    public function provision(User $user): ?Wallet
    {
        if (! $this->isEligible($user)) {
            return null;
        }

        return $this->provisionForEligibleUser($user);
    }

    /**
     * Provision the user's ledger account and wallet without re-checking
     * eligibility.
     *
     * The caller has already established that the user is approved. Used by the
     * KYC approval path, where eligibility was just decided.
     */
    public function provisionForEligibleUser(User $user): Wallet
    {
        // The lock is on the OWNER row, not on the account or wallet rows,
        // because those rows may not exist yet. Locking a row that is about to
        // be created is the classic way to get a deadlock; locking the owner
        // serialises every provisioner for this user, whoever they are.
        return DB::transaction(function () use ($user): Wallet {
            $owner = User::query()->whereKey($user->id)->lockForUpdate()->first();

            if ($owner === null) {
                throw new \RuntimeException(
                    'Cannot provision a wallet for a user that does not exist.'
                );
            }

            $account = $this->findOrCreateAccount($owner);

            return $this->findOrCreateWallet($owner, $account);
        }, 3);
    }

    /**
     * The user's ledger account, created if it does not exist.
     *
     * A SYSTEM account is never created here: H-1 is 🟠 Proposed and not
     * approved, so this service has no funding source to offer.
     */
    private function findOrCreateAccount(User $user): LedgerAccount
    {
        $account = LedgerAccount::query()
            ->where('owner_user_id', $user->id)
            ->first();

        if ($account !== null) {
            return $account;
        }

        // `ledger_accounts` is fully guarded, so the attributes are assigned
        // explicitly rather than mass-assigned from an array.
        $account = new LedgerAccount;
        $account->owner_user_id = $user->id;
        $account->account_type = LedgerAccountType::USER;
        $account->system_role = null;
        $account->currency = self::CURRENCY;
        $account->next_sequence_number = 1;
        $account->created_at = Carbon::now();

        try {
            $account->save();
        } catch (QueryException $exception) {
            // Lost the race against a concurrent provisioner. The unique index
            // on `owner_user_id` did its job; the winner's row is the truth.
            if (! $this->isDuplicateEntry($exception)) {
                throw $exception;
            }

            $existing = LedgerAccount::query()
                ->where('owner_user_id', $user->id)
                ->first();

            if ($existing === null) {
                throw $exception;
            }

            return $existing;
        }

        return $account;
    }

    /**
     * The wallet bound to the given account, created if it does not exist.
     *
     * The balance starts at exactly zero Poisha. `reserved_minor` is zero too,
     * which satisfies `ck_wallets_reserved_within_balance`.
     */
    private function findOrCreateWallet(User $user, LedgerAccount $account): Wallet
    {
        $wallet = Wallet::query()
            ->where('account_id', $account->id)
            ->first();

        if ($wallet !== null) {
            return $wallet;
        }

        // `wallets` is fully guarded for the same reason as the account.
        $wallet = new Wallet;
        $wallet->owner_user_id = $user->id;
        $wallet->account_id = $account->id;
        $wallet->currency = self::CURRENCY;
        $wallet->balance_minor = 0;
        $wallet->reserved_minor = 0;

        try {
            $wallet->save();
        } catch (QueryException $exception) {
            // Same race, same resolution: the unique index on `account_id`
            // rejected a second wallet for one account.
            if (! $this->isDuplicateEntry($exception)) {
                throw $exception;
            }

            $existing = Wallet::query()
                ->where('account_id', $account->id)
                ->first();

            if ($existing === null) {
                throw $exception;
            }

            return $existing;
        }

        return $wallet;
    }

    /**
     * Whether the exception is a unique-constraint violation.
     */
    private function isDuplicateEntry(QueryException $exception): bool
    {
        return $exception->getCode() === self::DUPLICATE_ENTRY;
    }
}
