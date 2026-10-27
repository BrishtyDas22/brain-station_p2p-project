<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\KycStatus;
use App\Enums\LedgerDirection;
use App\Enums\LoanStatus;
use App\Enums\RiskLevel;
use App\Enums\TransactionStatus;
use App\Models\LedgerAccount;
use App\Models\LoanInvestment;
use App\Models\LoanRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Support\DemoAmount;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The loan marketplace: requests, lender commitments, escrow and disbursement.
 *
 * ## The PRD flow, in ledger terms
 *
 * `../PRD_SRS_Document.pdf` Epic 3 describes it as:
 *
 * > When a lender commits 5,000 BDT from their available wallet balance, then the system
 * > locks the 5,000 BDT under the status "ESCROW_LOCKED", and detects that the target
 * > funding threshold (100%) has been achieved, and transitions the loan state to
 * > "DISBURSED", and transfers the total principal into the borrower's spendable wallet
 * > balance.
 *
 * This service implements exactly that, as three balanced ledger postings:
 *
 *   1. **Commit** — the lender's wallet is DEBITED and the escrow account is CREDITED by
 *      the same amount, in one balanced pair. The capital is now in custody.
 *   2. **Disburse** — when the listing reaches 100%, the escrow account is DEBITED and
 *      the borrower's wallet is CREDITED by the same amount, in one balanced pair.
 *   3. **Repay** (see `RepaymentService`) — the borrower's wallet is DEBITED and each
 *      lender is CREDITED their proportional principal and interest share.
 *
 * ## No external gateway is involved
 *
 * A lender funds a loan **only** from their existing wallet balance. There is no
 * bKash/Nagad/Bank/Card step in the funding path, because no money leaves the platform
 * when a lender commits capital — it moves from one internal wallet into custody.
 *
 * ## Concurrency
 *
 * The PRD's non-functional requirements demand "database-level pessimistic locking on
 * loan objects during funding calls to prevent race-condition overfunding". This service
 * does that literally: the loan row is selected `FOR UPDATE` inside the funding
 * transaction, so two concurrent lenders serialise on it and the second one sees the
 * first one's committed amount.
 *
 * The lender's wallet is locked as well, so two concurrent commitments from the SAME
 * lender cannot both see the same available balance and both spend it.
 *
 * ## Idempotency
 *
 * `uq_loan_investments_idempotency_key` makes a replayed funding request a no-op: the
 * original investment is returned instead of a second one being made. The commitment is
 * also exactly-once even without the key, because the whole thing is one transaction and
 * the unique `(loan_request_id, lender_user_id)` index refuses a second commitment by
 * the same lender on the same listing.
 */
final class LoanService
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
     * The listings on the marketplace, newest first.
     *
     * A borrower never sees their OWN listing in the marketplace — they manage it from
     * their loan list instead.
     *
     * @return list<LoanRequest>
     */
    public function marketplaceFor(User $user, int $limit = 50): array
    {
        /** @var list<LoanRequest> */
        return LoanRequest::query()
            ->openForFunding()
            ->where('borrower_user_id', '!=', $user->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->all();
    }

    /**
     * The borrower's own requests.
     *
     * @return list<LoanRequest>
     */
    public function requestsFor(User $user, int $limit = 50): array
    {
        /** @var list<LoanRequest> */
        return LoanRequest::query()
            ->forBorrower($user->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->all();
    }

    /**
     * The lender's commitments.
     *
     * @return list<LoanInvestment>
     */
    public function investmentsFor(User $user, int $limit = 50): array
    {
        /** @var list<LoanInvestment> */
        return LoanInvestment::query()
            ->forLender($user->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->all();
    }

    /**
     * One listing, as the viewer is allowed to see it.
     *
     * A listing is visible to its borrower and to every lender — a marketplace listing
     * is public by design. The *actions* on it are what are restricted, not the read.
     */
    public function findRequest(int $loanRequestId): ?LoanRequest
    {
        return LoanRequest::query()->whereKey($loanRequestId)->first();
    }

    // -----------------------------------------------------------------------
    // Creating a request
    // -----------------------------------------------------------------------

    /**
     * Create a loan request on the marketplace.
     *
     * No money moves here. The PRD's User Story 3.1 requires a `KYC_VERIFIED` borrower
     * with "zero active overdue loans", and a listing that has been created and is
     * awaiting funding.
     *
     * @param  string  $principalTaka  The principal as a Taka **string**, parsed into
     *                                 integer Poisha with string arithmetic.
     */
    public function createRequest(
        User $user,
        string $principalTaka,
        int $tenureMonths,
        int $interestRateBps,
        string $purpose,
    ): LoanRequest {
        $this->assertBorrowerEligible($user);

        $principalMinor = $this->parsePrincipal($principalTaka);

        $this->assertPrincipalAllowed($principalMinor);
        $this->assertTenureAllowed($tenureMonths);
        $this->assertRateAllowed($interestRateBps);

        $purpose = trim($purpose);

        if (mb_strlen($purpose) < 10) {
            throw ValidationException::withMessages([
                'purpose' => 'Describe the purpose of the loan in at least 10 characters.',
            ]);
        }

        $request = new LoanRequest;
        $request->reference = $this->generateReference();
        $request->borrower_user_id = $user->id;
        $request->principal_minor = $principalMinor;
        $request->currency = 'BDT';
        $request->interest_rate_bps = $interestRateBps;
        $request->tenure_months = $tenureMonths;
        $request->purpose = $purpose;
        $request->status = LoanStatus::MARKETPLACE_LISTED;
        $request->funded_minor = 0;
        $request->investor_count = 0;
        $request->funded_at = null;
        $request->repaid_at = null;
        $request->save();

        $this->audit->record(
            'LOAN_REQUEST_CREATED',
            $user,
            LoanRequest::class,
            $request->id,
            context: [
                'reference' => $request->reference,
                'principal_minor' => $request->principal_minor,
                'tenure_months' => $request->tenure_months,
                'interest_rate_bps' => $request->interest_rate_bps,
            ],
        );

        return $request;
    }

    // -----------------------------------------------------------------------
    // Funding a listing
    // -----------------------------------------------------------------------

    /**
     * Commit capital from the lender's wallet to a listing.
     *
     * This is the PRD's "100% funding achieved and loan disbursed" scenario, in two
     * halves. The commitment is always escrowed; the disbursement happens only if and
     * when the listing reaches 100%.
     *
     * @param  string  $amountTaka  The commitment as a Taka **string**.
     * @param  string|null  $idempotencyKey  The client's idempotency key. A replay
     *                                       returns the original commitment.
     */
    public function fund(
        User $lender,
        LoanRequest $request,
        string $amountTaka,
        ?string $idempotencyKey = null,
    ): LoanInvestment {
        $this->assertLenderEligible($lender);

        $amountMinor = $this->parseCommitment($amountTaka);

        $key = $idempotencyKey !== null && trim($idempotencyKey) !== ''
            ? Str::limit(trim($idempotencyKey), 64, '')
            : (string) Str::uuid();

        // A replayed request returns the original commitment. The unique index is the
        // real guarantee; this read is just the fast path.
        $existing = LoanInvestment::query()
            ->where('idempotency_key', $key)
            ->where('lender_user_id', $lender->id)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            $investment = DB::transaction(function () use (
                $lender,
                $request,
                $amountMinor,
                $key,
            ): LoanInvestment {
                // THE PESSIMISTIC LOCK the PRD's non-functional requirements ask for.
                // Two lenders clicking "Fund Loan" on the same listing at the same
                // moment serialise here, so the second one sees the first one's
                // committed amount and cannot overfund.
                $locked = LoanRequest::query()
                    ->whereKey($request->id)
                    ->lockForUpdate()
                    ->first();

                if ($locked === null) {
                    throw ValidationException::withMessages([
                        'loan' => 'That loan request no longer exists.',
                    ]);
                }

                if (! $locked->status->acceptsFunding()) {
                    throw ValidationException::withMessages([
                        'loan' => 'That loan request is no longer open for funding.',
                    ]);
                }

                // A borrower may not lend to themselves.
                if ($locked->borrower_user_id === $lender->id) {
                    throw ValidationException::withMessages([
                        'loan' => 'You cannot fund your own loan request.',
                    ]);
                }

                // The lender may not commit twice to the same listing.
                $alreadyInvested = LoanInvestment::query()
                    ->where('loan_request_id', $locked->id)
                    ->where('lender_user_id', $lender->id)
                    ->exists();

                if ($alreadyInvested) {
                    throw ValidationException::withMessages([
                        'loan' => 'You have already funded this loan request.',
                    ]);
                }

                $remaining = $locked->remainingMinor();

                if ($amountMinor > $remaining) {
                    throw ValidationException::withMessages([
                        'amount' => sprintf(
                            'This loan needs %s more. You cannot commit more than that.',
                            $this->formatTaka($remaining),
                        ),
                    ]);
                }

                // The lender's wallet is locked too, so two concurrent commitments from
                // the same lender cannot both see the same balance and both spend it.
                $wallet = Wallet::query()
                    ->where('owner_user_id', $lender->id)
                    ->lockForUpdate()
                    ->first();

                if ($wallet === null) {
                    throw ValidationException::withMessages([
                        'wallet' => 'Your wallet is not ready yet. Please try again shortly.',
                    ]);
                }

                $available = $wallet->availableMinor();

                if ($amountMinor > $available) {
                    throw ValidationException::withMessages([
                        'amount' => sprintf(
                            'Your available balance is %s. Add money before committing more than that.',
                            $this->formatTaka($available),
                        ),
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

                $escrow = $this->ledger->escrowAccount();

                $transaction = $this->createEscrowTransaction($locked, $lender, $amountMinor);

                // The balanced pair. The lender's wallet is DEBITED and the escrow
                // account is CREDITED by the same amount, so the capital is now held in
                // custody and SUM(DEBIT) = SUM(CREDIT) for this transaction.
                $this->ledger->postPair(
                    amountMinor: $amountMinor,
                    sourceAccountId: $account->id,
                    destinationAccountId: $escrow->id,
                    transactionId: $transaction->id,
                    destinationWalletId: null,
                    sourceWalletId: $wallet->id,
                );

                $investment = new LoanInvestment;
                $investment->reference = $this->generateInvestmentReference();
                $investment->loan_request_id = $locked->id;
                $investment->lender_user_id = $lender->id;
                $investment->amount_minor = $amountMinor;
                $investment->transaction_id = $transaction->id;
                $investment->idempotency_key = $key;
                $investment->save();

                // The funding projection advances by exactly what was committed.
                $locked->funded_minor = $locked->funded_minor + $amountMinor;
                $locked->investor_count = $locked->investor_count + 1;
                $locked->save();

                // 100% funded → the PRD's "DISBURSED" transition, releasing the
                // principal to the borrower's spendable wallet.
                if ($locked->isFullyFunded()) {
                    $this->disburse($locked, $lender);
                }

                return $investment;
            }, 3);
        } catch (QueryException $exception) {
            // Lost the race against a concurrent identical request. The unique index on
            // `idempotency_key` did its job; the winner's row is the truth.
            if (! $this->isDuplicateEntry($exception)) {
                throw $exception;
            }

            $existing = LoanInvestment::query()
                ->where('idempotency_key', $key)
                ->where('lender_user_id', $lender->id)
                ->first();

            if ($existing === null) {
                throw $exception;
            }

            return $existing;
        }

        $this->audit->record(
            'LOAN_FUNDED',
            $lender,
            LoanInvestment::class,
            $investment->id,
            context: [
                'reference' => $investment->reference,
                'loan_request_id' => $investment->loan_request_id,
                'amount_minor' => $investment->amount_minor,
            ],
        );

        return $investment;
    }

    // -----------------------------------------------------------------------
    // Disbursement
    // -----------------------------------------------------------------------

    /**
     * Release the escrow to the borrower's wallet.
     *
     * Called from inside the funding transaction, only when the listing has reached
     * 100% funding. The escrow account is DEBITED and the borrower's wallet is CREDITED
     * by the same amount, in one balanced pair.
     *
     * @param  User  $actingUser  The lender whose commitment triggered the threshold.
     *                            Used for the audit record only.
     */
    private function disburse(LoanRequest $request, User $actingUser): void
    {
        $principal = $request->principal_minor;

        $borrower = User::query()->whereKey($request->borrower_user_id)->first();

        if ($borrower === null) {
            throw ValidationException::withMessages([
                'loan' => 'The borrower for this loan no longer exists.',
            ]);
        }

        $borrowerWallet = Wallet::query()
            ->where('owner_user_id', $borrower->id)
            ->lockForUpdate()
            ->first();

        if ($borrowerWallet === null) {
            throw ValidationException::withMessages([
                'loan' => 'The borrower does not have a wallet yet.',
            ]);
        }

        $borrowerAccount = LedgerAccount::query()
            ->whereKey($borrowerWallet->account_id)
            ->first();

        if ($borrowerAccount === null) {
            throw ValidationException::withMessages([
                'loan' => 'The borrower does not have a wallet yet.',
            ]);
        }

        $escrow = $this->ledger->escrowAccount();

        $transaction = $this->createDisbursementTransaction($request, $borrower, $principal);

        // The balanced pair. The escrow account gives up the capital and the borrower's
        // spendable wallet receives it.
        $this->ledger->postPair(
            amountMinor: $principal,
            sourceAccountId: $escrow->id,
            destinationAccountId: $borrowerAccount->id,
            transactionId: $transaction->id,
            destinationWalletId: $borrowerWallet->id,
            sourceWalletId: null,
        );

        $request->status = LoanStatus::DISBURSED;
        $request->funded_at = Carbon::now();
        $request->save();

        // The repayment schedule is created at disbursement — that is the moment the
        // loan starts running. The PRD's Epic 4 describes the installments being
        // collected on their due dates from here.
        app(RepaymentService::class)->buildSchedule($request);

        $this->audit->record(
            'LOAN_DISBURSED',
            $actingUser,
            LoanRequest::class,
            $request->id,
            context: [
                'reference' => $request->reference,
                'principal_minor' => $principal,
                'borrower_user_id' => $borrower->id,
            ],
        );
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * The ledger transaction the escrow-locking entry pair belongs to.
     */
    private function createEscrowTransaction(
        LoanRequest $request,
        User $lender,
        int $amountMinor,
    ): Transaction {
        $escrow = $this->ledger->escrowAccount();

        $transaction = new Transaction;
        $transaction->reference = $this->generateTransactionReference('ESC');
        $transaction->sender_account_id = $lender->ledgerAccountId();
        $transaction->recipient_account_id = $escrow->id;
        $transaction->counterparty_name = 'Escrow — '.$request->reference;
        $transaction->counterparty_account = 'ESCROW';
        $transaction->method = 'LOAN_ESCROW';
        $transaction->amount_minor = $amountMinor;
        $transaction->fee_minor = 0;
        $transaction->currency = 'BDT';
        $transaction->direction = LedgerDirection::DEBIT;
        $transaction->status = TransactionStatus::POSTED;
        $transaction->risk_level = RiskLevel::LOW;
        $transaction->note = 'Loan funding — capital locked in escrow';
        $transaction->idempotency_key = 'LOANESCROW-'.$lender->id.'-'.$request->id.'-'.Str::uuid();
        $transaction->save();

        return $transaction;
    }

    /**
     * The ledger transaction the disbursement entry pair belongs to.
     */
    private function createDisbursementTransaction(
        LoanRequest $request,
        User $borrower,
        int $amountMinor,
    ): Transaction {
        $escrow = $this->ledger->escrowAccount();

        $transaction = new Transaction;
        $transaction->reference = $this->generateTransactionReference('DIS');
        $transaction->sender_account_id = $escrow->id;
        $transaction->recipient_account_id = $borrower->ledgerAccountId();
        $transaction->counterparty_name = 'Loan disbursement — '.$request->reference;
        $transaction->counterparty_account = 'ESCROW';
        $transaction->method = 'LOAN_DISBURSEMENT';
        $transaction->amount_minor = $amountMinor;
        $transaction->fee_minor = 0;
        $transaction->currency = 'BDT';
        $transaction->direction = LedgerDirection::CREDIT;
        $transaction->status = TransactionStatus::POSTED;
        $transaction->risk_level = RiskLevel::LOW;
        $transaction->note = 'Loan disbursement — principal released to wallet';
        $transaction->idempotency_key = 'LOANDISB-'.$request->id.'-'.Str::uuid();
        $transaction->save();

        return $transaction;
    }

    /**
     * Refuse a borrower whose identity is not approved, or who already has an
     * overdue loan.
     *
     * The PRD's User Story 3.1 states the precondition: "the borrower holds a
     * 'KYC_VERIFIED' status and has zero active overdue loans".
     */
    private function assertBorrowerEligible(User $user): void
    {
        $approved = $user->kycSubmission()
            ->where('status', KycStatus::APPROVED->value)
            ->exists();

        if (! $approved) {
            throw ValidationException::withMessages([
                'kyc' => 'Your identity must be approved before you can request a loan.',
            ]);
        }

        $overdue = LoanRequest::query()
            ->forBorrower($user->id)
            ->whereIn('status', [LoanStatus::REPAYING->value, LoanStatus::DEFAULTED->value])
            ->exists();

        if ($overdue) {
            throw ValidationException::withMessages([
                'loan' => 'You already have an active loan. Finish repaying it before requesting another.',
            ]);
        }
    }

    /**
     * Refuse a lender whose identity is not approved.
     *
     * A lender commits real money, so the same KYC gate applies.
     */
    private function assertLenderEligible(User $lender): void
    {
        $approved = $lender->kycSubmission()
            ->where('status', KycStatus::APPROVED->value)
            ->exists();

        if (! $approved) {
            throw ValidationException::withMessages([
                'kyc' => 'Your identity must be approved before you can fund a loan.',
            ]);
        }
    }

    /**
     * Parse a Taka string into integer Poisha.
     *
     * @throws ValidationException
     */
    private function parsePrincipal(string $principalTaka): int
    {
        try {
            return DemoAmount::toPoisha($principalTaka);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'principal' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Parse a commitment amount into integer Poisha.
     *
     * @throws ValidationException
     */
    private function parseCommitment(string $amountTaka): int
    {
        try {
            $minor = DemoAmount::toPoisha($amountTaka);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'amount' => $exception->getMessage(),
            ]);
        }

        if ($minor <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'Enter an amount greater than zero.',
            ]);
        }

        return $minor;
    }

    /**
     * Refuse a principal outside the PRD's loan range.
     *
     * The range is the PRD's own ("Loan Range: BDT 5,000 - 50,000"). It is enforced
     * here, in the request validation, and by a database CHECK constraint.
     */
    private function assertPrincipalAllowed(int $principalMinor): void
    {
        $min = (int) config('p2p-lending.min_principal_minor');
        $max = (int) config('p2p-lending.max_principal_minor');

        if ($principalMinor < $min || $principalMinor > $max) {
            throw ValidationException::withMessages([
                'principal' => sprintf(
                    'The loan amount must be between %s and %s.',
                    $this->formatTaka($min),
                    $this->formatTaka($max),
                ),
            ]);
        }
    }

    /**
     * Refuse a tenure outside the permitted range.
     */
    private function assertTenureAllowed(int $tenureMonths): void
    {
        $min = (int) config('p2p-lending.min_tenure_months');
        $max = (int) config('p2p-lending.max_tenure_months');

        if ($tenureMonths < $min || $tenureMonths > $max) {
            throw ValidationException::withMessages([
                'tenure' => sprintf(
                    'The repayment tenure must be between %d and %d months.',
                    $min,
                    $max,
                ),
            ]);
        }
    }

    /**
     * Refuse an interest rate outside the sandbox bounds.
     */
    private function assertRateAllowed(int $interestRateBps): void
    {
        if ($interestRateBps < 0) {
            throw ValidationException::withMessages([
                'interest' => 'The interest rate cannot be negative.',
            ]);
        }

        $max = (int) config('p2p-lending.max_interest_rate_bps');

        if ($interestRateBps > $max) {
            throw ValidationException::withMessages([
                'interest' => sprintf(
                    'The interest rate cannot be above %s%%.',
                    $this->formatBps($max),
                ),
            ]);
        }
    }

    /**
     * A fresh, unique loan reference.
     */
    private function generateReference(): string
    {
        return $this->uniqueReference((string) config('p2p-lending.reference_prefix', 'LOAN'));
    }

    /**
     * A fresh, unique investment reference.
     */
    private function generateInvestmentReference(): string
    {
        return $this->uniqueReference((string) config('p2p-lending.investment_reference_prefix', 'INV'));
    }

    /**
     * A fresh, unique transaction reference.
     */
    private function generateTransactionReference(string $prefix): string
    {
        return $this->uniqueReference($prefix);
    }

    /**
     * A reference that is guaranteed not to collide with an existing one.
     */
    private function uniqueReference(string $prefix): string
    {
        do {
            $reference = sprintf(
                '%s-%s-%s',
                $prefix,
                Carbon::now()->format('Ymd'),
                Str::upper(Str::random(10)),
            );
        } while (Transaction::query()->where('reference', $reference)->exists()
            || LoanRequest::query()->where('reference', $reference)->exists()
            || LoanInvestment::query()->where('reference', $reference)->exists());

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

    /**
     * Format basis points as a percentage string.
     */
    private function formatBps(int $bps): string
    {
        return rtrim(rtrim(number_format($bps / 100, 2), '0'), '.');
    }
}
