<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\KycStatus;
use App\Enums\LedgerDirection;
use App\Enums\RiskLevel;
use App\Enums\TransactionStatus;
use App\Enums\WithdrawalIntent;
use App\Enums\WithdrawalMethod;
use App\Enums\WithdrawalStatus;
use App\Models\LedgerAccount;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletWithdrawal;
use App\Support\DemoAmount;
use App\Support\WithdrawalDetails;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The "Withdraw Money" workflow — paying a borrower's wallet funds back out.
 *
 * ## What this is
 *
 * The PRD's borrower persona "Withdraws approved funds to third-party accounts
 * (Bank / Mobile Financial Services)". This service moves the money from the owner's
 * wallet out to a simulated external destination.
 *
 * ## What this is NOT
 *
 * **No provider is contacted and no real money moves.** The destination is simulated,
 * exactly like the "Add Money" gateway: the client sends an *intent*, the server
 * decides the outcome, and only a successful settlement posts a balanced pair of
 * ledger entries.
 *
 * **No credential is ever collected.** `WithdrawalDetails` reduces the destination to a
 * masked label, and refuses a PIN, an OTP, a password or any card detail outright.
 *
 * ## The money path
 *
 * On a successful settlement, ONE transaction:
 *
 *   - creates a `transactions` row;
 *   - posts **two** `ledger_entries`: a DEBIT against the owner's ledger account and an
 *     equal CREDIT against the sandbox payout account (the external sink the money left
 *     the platform for), so `SUM(DEBIT) = SUM(CREDIT)` holds;
 *   - advances the `wallets.balance_minor` projection by exactly the withdrawn amount;
 *   - marks the attempt `SUCCEEDED`.
 *
 * A failed or cancelled attempt posts **no** entries and moves **no** balance.
 */
final class WithdrawalService
{
    /**
     * MySQL's ER_DUP_ENTRY. Raised when a unique index rejects the write.
     */
    private const DUPLICATE_ENTRY = 1062;

    public function __construct(
        private readonly LedgerPostingService $ledger,
        private readonly AuditService $audit,
    ) {}

    // -----------------------------------------------------------------------
    // Reading
    // -----------------------------------------------------------------------

    /**
     * One withdrawal attempt, scoped to its owner.
     */
    public function findFor(User $user, int $withdrawalId): ?WalletWithdrawal
    {
        return WalletWithdrawal::query()
            ->where('owner_user_id', $user->id)
            ->whereKey($withdrawalId)
            ->first();
    }

    /**
     * The user's withdrawal attempts, newest first.
     *
     * @return list<WalletWithdrawal>
     */
    public function historyFor(User $user, int $limit = 50): array
    {
        /** @var list<WalletWithdrawal> */
        return WalletWithdrawal::query()
            ->forOwner($user->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->all();
    }

    // -----------------------------------------------------------------------
    // Writing
    // -----------------------------------------------------------------------

    /**
     * Create a withdrawal attempt in its pending state.
     *
     * Creating an attempt never moves money. The balance changes only when the attempt
     * is settled with a `CONFIRM` intent.
     *
     * @param  string  $amountTaka  The amount as a Taka **string**.
     * @param  array<string, mixed>  $details  The destination inputs.
     * @param  string|null  $idempotencyKey  The client's idempotency key.
     */
    public function start(
        User $user,
        WithdrawalMethod $method,
        string $amountTaka,
        array $details = [],
        ?string $idempotencyKey = null,
    ): WalletWithdrawal {
        $this->assertEligible($user);

        $amountMinor = $this->parseAmount($amountTaka);

        $details = WithdrawalDetails::for($method, $details);

        $this->assertAmountAllowed($user, $amountMinor);

        $key = $idempotencyKey !== null && trim($idempotencyKey) !== ''
            ? Str::limit(trim($idempotencyKey), 64, '')
            : (string) Str::uuid();

        $existing = WalletWithdrawal::query()
            ->where('idempotency_key', $key)
            ->where('owner_user_id', $user->id)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            // `wallet_withdrawals` is fully guarded, so the attributes are assigned
            // explicitly rather than mass-assigned from an array.
            $withdrawal = new WalletWithdrawal;
            $withdrawal->reference = $this->generateReference();
            $withdrawal->owner_user_id = $user->id;
            $withdrawal->method = $method;
            $withdrawal->status = WithdrawalStatus::PENDING;
            $withdrawal->amount_minor = $amountMinor;
            $withdrawal->currency = 'BDT';
            $withdrawal->failure_reason = null;
            $withdrawal->details = $details;
            $withdrawal->transaction_id = null;
            $withdrawal->idempotency_key = $key;
            $withdrawal->settled_at = null;
            $withdrawal->save();
        } catch (QueryException $exception) {
            if (! $this->isDuplicateEntry($exception)) {
                throw $exception;
            }

            $existing = WalletWithdrawal::query()
                ->where('idempotency_key', $key)
                ->where('owner_user_id', $user->id)
                ->first();

            if ($existing === null) {
                throw $exception;
            }

            return $existing;
        }

        $this->audit->record(
            'WALLET_WITHDRAWAL_STARTED',
            $user,
            WalletWithdrawal::class,
            $withdrawal->id,
            context: [
                'reference' => $withdrawal->reference,
                'method' => $withdrawal->method->value,
                'amount_minor' => $withdrawal->amount_minor,
            ],
        );

        return $withdrawal;
    }

    /**
     * Settle a withdrawal attempt according to the requested intent.
     *
     * The server decides the outcome. The transition is refused if the attempt is not
     * still pending, and the attempt's existing state is returned unchanged — which is
     * what makes a replayed settlement harmless.
     */
    public function settle(User $user, WalletWithdrawal $withdrawal, WithdrawalIntent $intent): WalletWithdrawal
    {
        if ($withdrawal->owner_user_id !== $user->id) {
            $this->audit->deny(
                'WALLET_WITHDRAWAL_SETTLE_DENIED',
                $user,
                WalletWithdrawal::class,
                $withdrawal->id,
                ['reason' => 'not_owner'],
            );

            throw ValidationException::withMessages([
                'withdrawal' => 'That withdrawal does not belong to you.',
            ]);
        }

        $target = $intent->targetStatus();

        $settled = DB::transaction(function () use ($withdrawal, $intent, $target): WalletWithdrawal {
            $locked = WalletWithdrawal::query()
                ->whereKey($withdrawal->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw ValidationException::withMessages([
                    'withdrawal' => 'That withdrawal no longer exists.',
                ]);
            }

            if (! $locked->status->canTransitionTo($target)) {
                return $locked;
            }

            // The conditional transition is what makes the debit exactly-once.
            $affected = WalletWithdrawal::query()
                ->whereKey($locked->id)
                ->where('status', WithdrawalStatus::PENDING->value)
                ->update([
                    'status' => $target->value,
                    'failure_reason' => $intent->failureReason(),
                    'settled_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);

            if ($affected === 0) {
                return WalletWithdrawal::query()->whereKey($locked->id)->firstOrFail();
            }

            if ($target === WithdrawalStatus::SUCCEEDED) {
                $this->debit($locked);
            }

            return WalletWithdrawal::query()->whereKey($locked->id)->firstOrFail();
        }, 3);

        $this->audit->record(
            match ($settled->status) {
                WithdrawalStatus::SUCCEEDED => 'WALLET_WITHDRAWAL_SUCCEEDED',
                WithdrawalStatus::FAILED => 'WALLET_WITHDRAWAL_FAILED',
                WithdrawalStatus::CANCELLED => 'WALLET_WITHDRAWAL_CANCELLED',
                default => 'WALLET_WITHDRAWAL_SETTLED',
            },
            $user,
            WalletWithdrawal::class,
            $settled->id,
            context: [
                'reference' => $settled->reference,
                'intent' => $intent->value,
                'status' => $settled->status->value,
                'amount_minor' => $settled->amount_minor,
                'transaction_id' => $settled->transaction_id,
            ],
        );

        return $settled;
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * Debit the owner's wallet for the payout.
     *
     * The owner's ledger account is debited and the sandbox payout account — the external
     * sink the money left the platform for — is credited by the same amount, in one
     * balanced pair.
     */
    private function debit(WalletWithdrawal $withdrawal): void
    {
        $wallet = Wallet::query()
            ->where('owner_user_id', $withdrawal->owner_user_id)
            ->lockForUpdate()
            ->first();

        if ($wallet === null) {
            throw ValidationException::withMessages([
                'wallet' => 'Your wallet is not ready yet. Please try again shortly.',
            ]);
        }

        $account = LedgerAccount::query()
            ->whereKey($wallet->account_id)
            ->first();

        if ($account === null) {
            throw ValidationException::withMessages([
                'wallet' => 'Your wallet is not ready yet. Please try again shortly.',
            ]);
        }

        $destination = $this->ledger->sandboxPayoutAccount();

        $transaction = $this->createTransaction($withdrawal, $account, $destination);

        $this->ledger->postPair(
            amountMinor: $withdrawal->amount_minor,
            sourceAccountId: $account->id,
            destinationAccountId: $destination->id,
            transactionId: $transaction->id,
            destinationWalletId: null,
            sourceWalletId: $wallet->id,
        );

        $withdrawal->transaction_id = $transaction->id;
        $withdrawal->save();
    }

    /**
     * Create the ledger transaction the payout entries belong to.
     */
    private function createTransaction(
        WalletWithdrawal $withdrawal,
        LedgerAccount $source,
        LedgerAccount $destination,
    ): Transaction {
        $transaction = new Transaction;
        $transaction->reference = $withdrawal->reference;
        $transaction->sender_account_id = $source->id;
        $transaction->recipient_account_id = $destination->id;
        $transaction->counterparty_name = 'Withdrawal — '.$withdrawal->method->label();
        $transaction->counterparty_account = 'SANDBOX-PAYOUT';
        $transaction->method = 'WALLET_WITHDRAWAL';
        $transaction->amount_minor = $withdrawal->amount_minor;
        $transaction->fee_minor = 0;
        $transaction->currency = $withdrawal->currency;
        $transaction->direction = LedgerDirection::DEBIT;
        $transaction->status = TransactionStatus::POSTED;
        $transaction->risk_level = RiskLevel::LOW;
        $transaction->note = 'Withdraw Money — simulated sandbox payout';
        $transaction->idempotency_key = $withdrawal->idempotency_key;
        $transaction->save();

        return $transaction;
    }

    /**
     * Parse a Taka string into integer Poisha.
     *
     * @throws ValidationException
     */
    private function parseAmount(string $amountTaka): int
    {
        try {
            return DemoAmount::toPoisha($amountTaka);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'amount' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Refuse a user whose identity is not approved.
     */
    private function assertEligible(User $user): void
    {
        $approved = $user->kycSubmission()
            ->where('status', KycStatus::APPROVED->value)
            ->exists();

        if (! $approved) {
            throw ValidationException::withMessages([
                'kyc' => 'Your identity must be approved before you can withdraw money.',
            ]);
        }
    }

    /**
     * Refuse an amount that is not a positive, permitted amount, or one the wallet
     * cannot cover.
     */
    private function assertAmountAllowed(User $user, int $amountMinor): void
    {
        if ($amountMinor <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'Enter an amount greater than zero.',
            ]);
        }

        $max = (int) config('p2p-lending.max_withdrawal_minor');

        if ($amountMinor > $max) {
            throw ValidationException::withMessages([
                'amount' => sprintf(
                    'The maximum amount per withdrawal is %s. Enter %s or less.',
                    $this->formatTaka($max),
                    $this->formatTaka($max),
                ),
            ]);
        }

        $wallet = Wallet::query()
            ->where('owner_user_id', $user->id)
            ->first();

        $available = $wallet === null ? 0 : $wallet->availableMinor();

        if ($amountMinor > $available) {
            throw ValidationException::withMessages([
                'amount' => sprintf(
                    'Your available balance is %s. Enter %s or less.',
                    $this->formatTaka($available),
                    $this->formatTaka($available),
                ),
            ]);
        }
    }

    /**
     * A fresh, unique withdrawal reference.
     */
    private function generateReference(): string
    {
        $prefix = (string) config('p2p-lending.withdrawal_reference_prefix', 'WDR');

        do {
            $reference = sprintf(
                '%s-%s-%s',
                $prefix,
                Carbon::now()->format('Ymd'),
                Str::upper(Str::random(10)),
            );
        } while (WalletWithdrawal::query()->where('reference', $reference)->exists());

        return $reference;
    }

    /**
     * Whether the exception is a unique-constraint violation.
     */
    private function isDuplicateEntry(QueryException $exception): bool
    {
        return $exception->getCode() === self::DUPLICATE_ENTRY;
    }

    /**
     * Format an integer Poisha amount as a Taka string for an error message.
     */
    private function formatTaka(int $minor): string
    {
        $whole = intdiv($minor, 100);
        $fraction = $minor % 100;

        return $fraction === 0
            ? sprintf('৳%s', number_format($whole))
            : sprintf('৳%s.%02d', number_format($whole), $fraction);
    }
}
