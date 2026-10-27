<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FundingIntent;
use App\Enums\FundingMethod;
use App\Enums\FundingStatus;
use App\Enums\KycStatus;
use App\Enums\LedgerDirection;
use App\Enums\RiskLevel;
use App\Enums\TransactionStatus;
use App\Models\LedgerAccount;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletFunding;
use App\Support\DemoAmount;
use App\Support\FundingDetails;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The "Add Money" funding workflow.
 *
 * ## What this service does
 *
 * It runs the whole "Add Money" journey against the **main, ledger-backed wallet**:
 *
 *  1. `start()` — validate the amount and the method's inputs, and create a funding
 *     attempt in the `PENDING` state with a server-generated reference.
 *  2. `settle()` — map the user's **intent** to a terminal state and, only on success,
 *     post a **balanced pair of ledger entries** that credit the user's main wallet.
 *
 * ## The money path
 *
 * On a successful settlement, ONE transaction:
 *
 *   - creates a `transactions` row (the ledger transaction the entries belong to);
 *   - creates a `transactions` row's counterpart — a `SYSTEM` / `FUNDING` ledger account
 *     is the counterparty, created lazily and exactly once;
 *   - posts **two** `ledger_entries`: a DEBIT against the funding account and a CREDIT
 *     against the user's ledger account, of equal magnitude, so
 *     `SUM(DEBIT) = SUM(CREDIT)` for that transaction;
 *   - advances the `wallets.balance_minor` projection by exactly the credited amount;
 *   - marks the funding attempt `SUCCEEDED`.
 *
 * The wallet projection is **never** assigned an arbitrary number: it moves by the amount
 * the ledger entries carry, and it moves only after those entries exist.
 *
 * ## The outcome is server-owned
 *
 * The client sends an **intent** (`CONFIRM`, `FAIL`, `CANCEL`), never an outcome. The
 * server maps the intent to a terminal state and performs the transition. A failed or
 * cancelled attempt posts **no ledger entries** and moves **no balance**.
 *
 * ## Idempotency and concurrency
 *
 *  - A unique `idempotency_key` makes a replayed *creation* request a no-op: the original
 *    attempt is returned instead of a second one being made.
 *  - The settlement transition is a **conditional** `UPDATE ... WHERE status = 'PENDING'`,
 *    so a replayed or concurrent settlement of the same attempt posts the entry pair
 *    **exactly once**.
 *  - The whole settlement runs in one transaction with the funding attempt and both
 *    ledger accounts locked `FOR UPDATE`.
 *
 * ## The sandbox funding policy
 *
 * The counterparty is a `SYSTEM` account with the `FUNDING` role. **H-1 is 🟠 Proposed and
 * not approved** — see `config/p2p-funding.php` and the OpenSpec change
 * `add-sandbox-wallet-funding`. This is a LOCAL SANDBOX mechanism only: no external
 * provider is contacted, no real money moves, and no production money-movement path may
 * use this funding account.
 */
final class WalletFundingService
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
     * One of the user's funding attempts, scoped to them.
     *
     * A peer asking for another user's attempt receives `null` — the row is not found,
     * because it was never theirs to find.
     */
    public function findFor(User $user, int $fundingId): ?WalletFunding
    {
        return WalletFunding::query()
            ->where('owner_user_id', $user->id)
            ->whereKey($fundingId)
            ->first();
    }

    /**
     * The user's funding attempts, newest first.
     *
     * @return list<WalletFunding>
     */
    public function historyFor(User $user, int $limit = 50): array
    {
        /** @var list<WalletFunding> $fundings */
        $fundings = WalletFunding::query()
            ->where('owner_user_id', $user->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->all();

        return $fundings;
    }

    // -----------------------------------------------------------------------
    // Writing
    // -----------------------------------------------------------------------

    /**
     * Create a funding attempt in its pending state.
     *
     * Creating a funding attempt never credits anything. The balance moves only when the
     * attempt is settled with a `CONFIRM` intent.
     *
     * @param  string  $amountTaka  The amount as a Taka **string**. It is parsed into
     *                              integer Poisha here, with string arithmetic, so no float
     *                              ever touches the value.
     * @param  array<string, mixed>  $details  The method-specific inputs. Validated and
     *                                         reduced to their minimal storable form; never a
     *                                         real credential.
     * @param  string|null  $idempotencyKey  The client's idempotency key. When supplied,
     *                                       a replay returns the original attempt.
     */
    public function start(
        User $user,
        FundingMethod $method,
        string $amountTaka,
        array $details = [],
        ?string $idempotencyKey = null,
    ): WalletFunding {
        $this->assertEligible($user);

        $amountMinor = $this->parseAmount($amountTaka);

        $details = FundingDetails::for($method, $details);

        $this->assertAmountAllowed($user, $amountMinor);

        $key = $idempotencyKey !== null && trim($idempotencyKey) !== ''
            ? Str::limit(trim($idempotencyKey), 64, '')
            : (string) Str::uuid();

        // A replayed request returns the original attempt instead of creating a second
        // one. The unique index on `idempotency_key` is the real guarantee; this read
        // is just the fast path.
        $existing = WalletFunding::query()
            ->where('idempotency_key', $key)
            ->where('owner_user_id', $user->id)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            // `wallet_fundings` is fully guarded, so the attributes are assigned
            // explicitly rather than mass-assigned from an array. The status here is
            // the ONLY status a funding attempt is ever born in.
            $funding = new WalletFunding;
            $funding->reference = $this->generateReference();
            $funding->owner_user_id = $user->id;
            $funding->method = $method;
            $funding->status = FundingStatus::PENDING;
            $funding->amount_minor = $amountMinor;
            $funding->currency = $this->currency();
            $funding->failure_reason = null;
            $funding->details = $details;
            $funding->transaction_id = null;
            $funding->idempotency_key = $key;
            $funding->settled_at = null;
            $funding->save();
        } catch (QueryException $exception) {
            // Lost the race against a concurrent identical request. The unique index did
            // its job; the winner's row is the truth.
            if (! $this->isDuplicateEntry($exception)) {
                throw $exception;
            }

            $existing = WalletFunding::query()
                ->where('idempotency_key', $key)
                ->where('owner_user_id', $user->id)
                ->first();

            if ($existing === null) {
                throw $exception;
            }

            return $existing;
        }

        $this->audit->record(
            'WALLET_FUNDING_STARTED',
            $user,
            WalletFunding::class,
            $funding->id,
            context: [
                'reference' => $funding->reference,
                'method' => $funding->method->value,
                'amount_minor' => $funding->amount_minor,
            ],
        );

        return $funding;
    }

    /**
     * Settle a funding attempt according to the requested intent.
     *
     * The server decides the outcome. The intent is a request, not a command: the
     * transition is refused if the attempt is not still pending, and the attempt's
     * existing state is returned unchanged.
     */
    public function settle(User $user, WalletFunding $funding, FundingIntent $intent): WalletFunding
    {
        if ($funding->owner_user_id !== $user->id) {
            $this->audit->deny(
                'WALLET_FUNDING_SETTLE_DENIED',
                $user,
                WalletFunding::class,
                $funding->id,
                ['reason' => 'not_owner'],
            );

            throw ValidationException::withMessages([
                'funding' => 'That payment does not belong to you.',
            ]);
        }

        $target = $intent->targetStatus();

        $settled = DB::transaction(function () use ($funding, $intent, $target): WalletFunding {
            // Lock the funding attempt row. Two concurrent settlements of the same
            // attempt serialise here, so the second one sees the first one's terminal
            // state.
            $locked = WalletFunding::query()
                ->whereKey($funding->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw ValidationException::withMessages([
                    'funding' => 'That payment no longer exists.',
                ]);
            }

            if (! $locked->status->canTransitionTo($target)) {
                // Already terminal. Refusing here is what makes a replay harmless:
                // nothing is written and the balance is not credited again.
                return $locked;
            }

            // The transition is conditional on the attempt still being PENDING. The first
            // writer updates one row; a racing second writer updates zero and never
            // posts the entry pair.
            $affected = WalletFunding::query()
                ->whereKey($locked->id)
                ->where('status', FundingStatus::PENDING->value)
                ->update([
                    'status' => $target->value,
                    'failure_reason' => $intent->failureReason(),
                    'settled_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);

            if ($affected === 0) {
                // Lost the race. The other writer's transition stands and the credit it
                // posted is the only credit.
                return WalletFunding::query()->whereKey($locked->id)->firstOrFail();
            }

            if ($target === FundingStatus::SUCCEEDED) {
                $this->credit($locked);
            }

            return WalletFunding::query()->whereKey($locked->id)->firstOrFail();
        }, 3);

        $this->audit->record(
            match ($settled->status) {
                FundingStatus::SUCCEEDED => 'WALLET_FUNDING_SUCCEEDED',
                FundingStatus::FAILED => 'WALLET_FUNDING_FAILED',
                FundingStatus::CANCELLED => 'WALLET_FUNDING_CANCELLED',
                default => 'WALLET_FUNDING_SETTLED',
            },
            $user,
            WalletFunding::class,
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
     * Credit the user's main wallet through a balanced pair of ledger entries.
     *
     * Called only from inside the settlement transaction, and only after the conditional
     * transition to `SUCCEEDED` has actually changed a row. This is where the money
     * actually moves:
     *
     *   - the user's ledger account is credited;
     *   - the sandbox funding account is debited by the same amount;
     *   - the `wallets.balance_minor` projection advances by exactly that amount;
     *   - a `transactions` row records the movement so it appears in the transaction
     *     history.
     */
    private function credit(WalletFunding $funding): void
    {
        $user = $funding->owner;

        $wallet = Wallet::query()
            ->where('owner_user_id', $funding->owner_user_id)
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

        $fundingAccount = $this->ledger->sandboxFundingAccount();

        $transaction = $this->createTransaction($funding, $account, $fundingAccount);

        // The balanced pair. The DEBIT is against the sandbox funding account; the
        // CREDIT is against the user's ledger account. Equal magnitude, opposite
        // direction, one transaction — so SUM(DEBIT) = SUM(CREDIT).
        $this->ledger->postPair(
            amountMinor: $funding->amount_minor,
            sourceAccountId: $fundingAccount->id,
            destinationAccountId: $account->id,
            transactionId: $transaction->id,
            destinationWalletId: $wallet->id,
            sourceWalletId: null,
        );

        $funding->transaction_id = $transaction->id;
        $funding->save();
    }

    /**
     * Create the ledger transaction the funding entries belong to.
     *
     * The transaction is what makes the credit visible in the transaction history as an
     * "Add Money" credit, and what the two ledger entries are grouped under.
     */
    private function createTransaction(
        WalletFunding $funding,
        LedgerAccount $destination,
        LedgerAccount $source,
    ): Transaction {
        $transaction = new Transaction;
        $transaction->reference = $funding->reference;
        $transaction->sender_account_id = $source->id;
        $transaction->recipient_account_id = $destination->id;
        $transaction->counterparty_name = 'Sandbox funding source';
        $transaction->counterparty_account = 'SANDBOX-FUNDING';
        $transaction->method = $funding->method->value;
        $transaction->amount_minor = $funding->amount_minor;
        $transaction->fee_minor = 0;
        $transaction->currency = $funding->currency;
        $transaction->direction = LedgerDirection::CREDIT;
        $transaction->status = TransactionStatus::POSTED;
        $transaction->risk_level = RiskLevel::LOW;
        $transaction->note = 'Add Money — sandbox gateway funding';
        $transaction->idempotency_key = $funding->idempotency_key;
        $transaction->save();

        return $transaction;
    }

    /**
     * Parse a Taka string into integer Poisha.
     *
     * The conversion is the server's alone. The client sends a Taka string; the server turns
     * it into a number of Poisha with string arithmetic, so no float ever touches the value
     * and "exactly the approved amount" is provable.
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
     *
     * SPEC §11.1 makes verified KYC a hard precondition for any money-shaped write path, and
     * §7.1 step 2 requires it before a payer may be used. Funding the wallet is
     * money-shaped, so the same gate applies.
     */
    private function assertEligible(User $user): void
    {
        $approved = $user->kycSubmission()
            ->where('status', KycStatus::APPROVED->value)
            ->exists();

        if (! $approved) {
            throw ValidationException::withMessages([
                'kyc' => 'Your identity must be approved before you can add money.',
            ]);
        }
    }

    /**
     * Refuse an amount that is not a positive, permitted amount.
     *
     * The limits are **sandbox limits**, configurable in `config/p2p-funding.php`. They are
     * not regulatory ceilings: decision **C-3 — the business limit — is ⚪ Open**, and no
     * Bangladesh compliance rule is invented here.
     */
    private function assertAmountAllowed(User $user, int $amountMinor): void
    {
        if ($amountMinor <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'Enter an amount greater than zero.',
            ]);
        }

        $max = (int) config('p2p-funding.max_amount_minor');

        if ($amountMinor > $max) {
            throw ValidationException::withMessages([
                'amount' => sprintf(
                    'The maximum amount per payment is %s. Enter %s or less.',
                    $this->formatTaka($max),
                    $this->formatTaka($max),
                ),
            ]);
        }

        $dailyMax = (int) config('p2p-funding.daily_max_amount_minor');

        if ($dailyMax > 0) {
            $todayTotal = (int) WalletFunding::query()
                ->where('owner_user_id', $user->id)
                ->where('status', FundingStatus::SUCCEEDED->value)
                ->whereDate('created_at', Carbon::now()->toDateString())
                ->sum('amount_minor');

            if ($todayTotal + $amountMinor > $dailyMax) {
                throw ValidationException::withMessages([
                    'amount' => sprintf(
                        'The daily maximum is %s. Enter %s or less.',
                        $this->formatTaka($dailyMax),
                        $this->formatTaka(max(0, $dailyMax - $todayTotal)),
                    ),
                ]);
            }
        }
    }

    /**
     * A fresh, unique funding reference.
     *
     * Generated server-side and never accepted from a client. The uniqueness is additionally
     * guaranteed by `uq_wallet_fundings_reference`, so a collision is impossible even if the
     * random part repeats.
     */
    private function generateReference(): string
    {
        $prefix = (string) config('p2p-funding.reference_prefix', 'ADD');

        do {
            $reference = sprintf(
                '%s-%s-%s',
                $prefix,
                Carbon::now()->format('Ymd'),
                Str::upper(Str::random(10)),
            );
        } while (WalletFunding::query()->where('reference', $reference)->exists());

        return $reference;
    }

    /**
     * The currency the funding workflow operates in.
     */
    private function currency(): string
    {
        return (string) config('p2p-funding.currency', 'BDT');
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
     *
     * Integer arithmetic only — the same rule the money path follows everywhere else.
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
