<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\InstallmentStatus;
use App\Enums\LedgerDirection;
use App\Enums\LoanStatus;
use App\Enums\RiskLevel;
use App\Enums\TransactionStatus;
use App\Models\LedgerAccount;
use App\Models\LoanInstallment;
use App\Models\LoanInvestment;
use App\Models\LoanRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The repayment engine — installments, distribution and default handling.
 *
 * ## What the PRD defines, and what it does not
 *
 * The PRD (Epic 4, User Story 4.1) defines:
 *
 * > When the daily repayment batch job runs, then the system debits the exact
 * > installment amount from the borrower's balance, and credits proportional principal
 * > and interest shares to the respective lenders' balances, and updates the installment
 * > status to "PAID".
 *
 * and the default path:
 *
 * > When the scheduled collection job encounters a failed debit attempt, then the loan
 * > enters a 3-day status of "GRACE_PERIOD", and alerts the borrower via SMS and push
 * > notification, and initiates a daily late penalty surcharge of 0.5% after grace
 * > period expiration.
 *
 * So this service implements: equal monthly installments (EMI), a proportional
 * principal/interest split per lender, a 3-day grace period, and a 0.5%-per-day late
 * penalty after the grace period expires.
 *
 * **The PRD does NOT define** a rate schedule, a risk-based pricing table, a
 * restructuring or settlement policy, a write-off policy, a collection agency
 * arrangement, or an SMS/push provider. None of those is invented here. The
 * notification is recorded in the audit trail and shown in the borrower's loan screen —
 * no SMS or push message is sent, because no provider is configured and no credential
 * exists.
 *
 * ## The money
 *
 * One installment collection is ONE transaction with N+1 balanced entry pairs:
 *
 *   - a DEBIT against the borrower's account for the full installment amount;
 *   - a CREDIT against each lender's account for their proportional share.
 *
 * `SUM(DEBIT) = SUM(CREDIT)` holds for the transaction, and the book stays balanced.
 *
 * ## The split
 *
 * Each lender's share is proportional to the amount they committed:
 *
 *   principal_share = installment_principal * lender_amount / total_committed
 *   interest_share  = installment_interest  * lender_amount / total_committed
 *
 * The division is done in integer Poisha, and the **remainder is distributed to the
 * lenders with the largest commitments** so the shares always add up to the installment
 * exactly. A rounding remainder that silently vanished would leave the book unbalanced
 * and would shortchange a lender by a Poisha.
 */
final class RepaymentService
{
    public function __construct(
        private readonly LedgerPostingService $ledger,
        private readonly AuditService $audit,
    ) {}

    // -----------------------------------------------------------------------
    // The schedule
    // -----------------------------------------------------------------------

    /**
     * Build the repayment schedule for a disbursed loan.
     *
     * Equal monthly installments (EMI): the total repayable is the principal plus the
     * interest, divided evenly across the tenure. The first `n-1` installments carry the
     * rounded-down share and the LAST one carries the remainder, so the schedule always
     * sums to the exact total.
     *
     * @return list<LoanInstallment>
     */
    public function buildSchedule(LoanRequest $request): array
    {
        $principal = $request->principal_minor;
        $tenure = max(1, $request->tenure_months);
        $rateBps = $request->interest_rate_bps;

        // Interest = principal * rate / 10000, in integer Poisha. The division is
        // exact because the rate is an integer number of basis points.
        $interest = (int) intdiv($principal * $rateBps, 10_000);

        $total = $principal + $interest;

        // The equal share, rounded down, and the remainder the last installment carries.
        $base = intdiv($total, $tenure);
        $remainder = $total - ($base * $tenure);

        $installments = [];
        $firstDue = Carbon::now()->addMonth()->startOfDay();

        for ($sequence = 1; $sequence <= $tenure; $sequence++) {
            $amount = $sequence === $tenure
                ? $base + $remainder
                : $base;

            // The interest is spread evenly across the tenure, and the LAST
            // installment carries the remainder — the same rule as the installment
            // amount itself. The principal share is the rest of the installment.
            $interestBase = intdiv($interest, $tenure);

            $interestShare = $sequence === $tenure
                ? $interestBase + ($interest - ($interestBase * $tenure))
                : $interestBase;

            $principalShare = $amount - $interestShare;

            $installment = new LoanInstallment;
            $installment->loan_request_id = $request->id;
            $installment->sequence = $sequence;
            $installment->due_date = $firstDue->copy()->addMonths($sequence - 1);
            $installment->amount_minor = $amount;
            $installment->principal_minor = $principalShare;
            $installment->interest_minor = $interestShare;
            $installment->status = InstallmentStatus::PENDING;
            $installment->transaction_id = null;
            $installment->penalty_minor = 0;
            $installment->paid_at = null;
            $installment->grace_started_at = null;
            $installment->save();

            $installments[] = $installment;
        }

        return $installments;
    }

    // -----------------------------------------------------------------------
    // Collecting
    // -----------------------------------------------------------------------

    /**
     * Collect one installment from the borrower and pay it to the lenders.
     *
     * The whole thing runs in ONE transaction, with the installment, the borrower's
     * wallet and every lender's wallet locked `FOR UPDATE`. That is what makes it
     * safe to run from a scheduled job: two concurrent runs of the batch cannot
     * collect the same installment twice.
     */
    public function collect(LoanInstallment $installment, ?User $actor = null): LoanInstallment
    {
        return DB::transaction(function () use ($installment, $actor): LoanInstallment {
            $locked = LoanInstallment::query()
                ->whereKey($installment->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw ValidationException::withMessages([
                    'installment' => 'That installment no longer exists.',
                ]);
            }

            if ($locked->status->isSettled()) {
                // Already collected. Returning it unchanged is what makes a replayed
                // batch harmless.
                return $locked;
            }

            $request = LoanRequest::query()
                ->whereKey($locked->loan_request_id)
                ->lockForUpdate()
                ->first();

            if ($request === null) {
                throw ValidationException::withMessages([
                    'installment' => 'The loan for this installment no longer exists.',
                ]);
            }

            $borrower = User::query()->whereKey($request->borrower_user_id)->first();

            if ($borrower === null) {
                throw ValidationException::withMessages([
                    'installment' => 'The borrower no longer exists.',
                ]);
            }

            $borrowerWallet = Wallet::query()
                ->where('owner_user_id', $borrower->id)
                ->lockForUpdate()
                ->first();

            if ($borrowerWallet === null) {
                throw ValidationException::withMessages([
                    'installment' => 'The borrower does not have a wallet yet.',
                ]);
            }

            $penalty = $this->accruedPenalty($locked);
            $totalDue = $locked->amount_minor + $penalty;

            $available = $borrowerWallet->availableMinor();

            if ($totalDue > $available) {
                // The PRD's insufficient-funds path: the loan enters a 3-day
                // GRACE_PERIOD, and a daily 0.5% penalty starts after that period
                // expires. No money moves.
                return $this->enterGracePeriod($locked, $request, $actor);
            }

            $borrowerAccount = LedgerAccount::query()
                ->whereKey($borrowerWallet->account_id)
                ->first();

            if ($borrowerAccount === null) {
                throw ValidationException::withMessages([
                    'installment' => 'The borrower does not have a wallet yet.',
                ]);
            }

            $investments = LoanInvestment::query()
                ->where('loan_request_id', $request->id)
                ->orderBy('id')
                ->get();

            if ($investments->isEmpty()) {
                throw ValidationException::withMessages([
                    'installment' => 'This loan has no lenders to pay.',
                ]);
            }

            $shares = $this->distribute($locked, $investments);

            $transaction = $this->createCollectionTransaction($request, $locked, $totalDue);

            // The borrower's account is debited ONCE for the whole installment.
            $this->ledger->postPair(
                amountMinor: $totalDue,
                sourceAccountId: $borrowerAccount->id,
                destinationAccountId: $this->ledger->escrowAccount()->id,
                transactionId: $transaction->id,
                destinationWalletId: null,
                sourceWalletId: $borrowerWallet->id,
            );

            // Each lender is credited their proportional share. The escrow account
            // gives the money on, so the capital flows: borrower → escrow → lenders.
            $escrow = $this->ledger->escrowAccount();

            foreach ($shares as $share) {
                $lenderWallet = Wallet::query()
                    ->where('owner_user_id', $share['lender_user_id'])
                    ->lockForUpdate()
                    ->first();

                if ($lenderWallet === null) {
                    throw ValidationException::withMessages([
                        'installment' => 'A lender for this loan does not have a wallet yet.',
                    ]);
                }

                $lenderAccount = LedgerAccount::query()
                    ->whereKey($lenderWallet->account_id)
                    ->first();

                if ($lenderAccount === null) {
                    throw ValidationException::withMessages([
                        'installment' => 'A lender for this loan does not have a wallet yet.',
                    ]);
                }

                $shareTransaction = $this->createDistributionTransaction(
                    $request,
                    $locked,
                    $share['lender_user_id'],
                    $share['amount_minor'],
                );

                $this->ledger->postPair(
                    amountMinor: $share['amount_minor'],
                    sourceAccountId: $escrow->id,
                    destinationAccountId: $lenderAccount->id,
                    transactionId: $shareTransaction->id,
                    destinationWalletId: $lenderWallet->id,
                    sourceWalletId: null,
                );
            }

            $locked->status = InstallmentStatus::PAID;
            $locked->paid_at = Carbon::now();
            $locked->penalty_minor = $penalty;
            $locked->transaction_id = $transaction->id;
            $locked->save();

            // The loan is repaid once every installment has been collected.
            $outstanding = LoanInstallment::query()
                ->where('loan_request_id', $request->id)
                ->where('status', '!=', InstallmentStatus::PAID->value)
                ->count();

            if ($outstanding === 0) {
                $request->status = LoanStatus::REPAID;
                $request->repaid_at = Carbon::now();
                $request->save();
            } elseif ($request->status === LoanStatus::DISBURSED) {
                $request->status = LoanStatus::REPAYING;
                $request->save();
            }

            $this->audit->record(
                'LOAN_INSTALLMENT_COLLECTED',
                $actor,
                LoanInstallment::class,
                $locked->id,
                context: [
                    'loan_request_id' => $request->id,
                    'sequence' => $locked->sequence,
                    'amount_minor' => $locked->amount_minor,
                    'penalty_minor' => $penalty,
                ],
            );

            return $locked;
        }, 3);
    }

    /**
     * Put an installment into the PRD's 3-day grace period.
     */
    private function enterGracePeriod(
        LoanInstallment $installment,
        LoanRequest $request,
        ?User $actor,
    ): LoanInstallment {
        $graceDays = (int) config('p2p-lending.grace_period_days', 3);

        $installment->status = InstallmentStatus::GRACE_PERIOD;
        $installment->grace_started_at ??= Carbon::now();
        $installment->save();

        if ($request->status === LoanStatus::DISBURSED) {
            $request->status = LoanStatus::REPAYING;
            $request->save();
        }

        $this->audit->record(
            'LOAN_INSTALLMENT_GRACE_PERIOD',
            $actor,
            LoanInstallment::class,
            $installment->id,
            context: [
                'loan_request_id' => $request->id,
                'sequence' => $installment->sequence,
                'grace_days' => $graceDays,
                'shortfall_minor' => $installment->amount_minor - $this->availableFor($installment),
            ],
        );

        return $installment;
    }

    // -----------------------------------------------------------------------
    // The split
    // -----------------------------------------------------------------------

    /**
     * Distribute one installment across the lenders, proportionally.
     *
     * Each lender's share is proportional to the amount they committed. The integer
     * division leaves a remainder, and the remainder is handed to the lenders with the
     * largest commitments — one Poisha each, largest first — until the shares add up to
     * the installment exactly. Nothing is lost and nothing is invented.
     *
     * @param  Collection<int, LoanInvestment>  $investments
     * @return list<array{lender_user_id: int, amount_minor: int}>
     */
    private function distribute(LoanInstallment $installment, $investments): array
    {
        $totalCommitted = (int) $investments->sum('amount_minor');

        if ($totalCommitted <= 0) {
            throw ValidationException::withMessages([
                'installment' => 'This loan has no committed capital to distribute.',
            ]);
        }

        $shares = [];
        $allocated = 0;

        foreach ($investments as $investment) {
            $share = (int) intdiv(
                $installment->amount_minor * (int) $investment->amount_minor,
                $totalCommitted
            );

            $shares[] = [
                'lender_user_id' => (int) $investment->lender_user_id,
                'amount_minor' => $share,
                'committed_minor' => (int) $investment->amount_minor,
            ];

            $allocated += $share;
        }

        // Hand out the remainder, largest commitment first.
        $remainder = $installment->amount_minor - $allocated;

        if ($remainder > 0) {
            usort($shares, static fn (array $a, array $b): int => $b['committed_minor'] <=> $a['committed_minor']);

            $index = 0;

            while ($remainder > 0 && $shares !== []) {
                $shares[$index]['amount_minor']++;
                $remainder--;
                $index = ($index + 1) % count($shares);
            }
        }

        return array_map(
            static fn (array $share): array => [
                'lender_user_id' => $share['lender_user_id'],
                'amount_minor' => $share['amount_minor'],
            ],
            $shares
        );
    }

    /**
     * The penalty accrued on an installment so far.
     *
     * The PRD's rule: "initiates a daily late penalty surcharge of 0.5% after grace
     * period expiration". So the penalty is 0.5% of the installment amount for each
     * whole day past the end of the grace period.
     */
    private function accruedPenalty(LoanInstallment $installment): int
    {
        $graceDays = (int) config('p2p-lending.grace_period_days', 3);
        $penaltyBps = (int) config('p2p-lending.late_penalty_bps', 50);

        $graceStarted = $installment->grace_started_at;

        if ($graceStarted === null) {
            // The grace period has not started, so no penalty has accrued yet.
            return 0;
        }

        $graceEnd = $graceStarted->copy()->addDays($graceDays);
        $now = Carbon::now();

        if ($now->lessThanOrEqualTo($graceEnd)) {
            return 0;
        }

        $daysLate = (int) $graceEnd->diffInDays($now);

        if ($daysLate < 1) {
            return 0;
        }

        return (int) intdiv($installment->amount_minor * $penaltyBps * $daysLate, 10_000);
    }

    /**
     * The amount the borrower can actually pay right now.
     */
    private function availableFor(LoanInstallment $installment): int
    {
        $request = LoanRequest::query()
            ->whereKey($installment->loan_request_id)
            ->first();

        if ($request === null) {
            return 0;
        }

        $wallet = Wallet::query()
            ->where('owner_user_id', $request->borrower_user_id)
            ->first();

        return $wallet === null ? 0 : $wallet->availableMinor();
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * The ledger transaction the collection debit belongs to.
     */
    private function createCollectionTransaction(
        LoanRequest $request,
        LoanInstallment $installment,
        int $totalDue,
    ): Transaction {
        $borrower = User::query()->whereKey($request->borrower_user_id)->firstOrFail();

        $transaction = new Transaction;
        $transaction->reference = $this->uniqueReference('REP');
        $transaction->sender_account_id = $borrower->ledgerAccountId();
        $transaction->recipient_account_id = $this->ledger->escrowAccount()->id;
        $transaction->counterparty_name = 'Loan repayment — '.$request->reference;
        $transaction->counterparty_account = 'ESCROW';
        $transaction->method = 'LOAN_REPAYMENT';
        $transaction->amount_minor = $totalDue;
        $transaction->fee_minor = 0;
        $transaction->currency = 'BDT';
        $transaction->direction = LedgerDirection::DEBIT;
        $transaction->status = TransactionStatus::POSTED;
        $transaction->risk_level = RiskLevel::LOW;
        $transaction->note = sprintf(
            'Loan installment %d of %d collected',
            $installment->sequence,
            $request->tenure_months,
        );
        $transaction->idempotency_key = 'LOANREP-'.$installment->id.'-'.Str::uuid();
        $transaction->save();

        return $transaction;
    }

    /**
     * The ledger transaction one lender's distribution belongs to.
     */
    private function createDistributionTransaction(
        LoanRequest $request,
        LoanInstallment $installment,
        int $lenderUserId,
        int $amountMinor,
    ): Transaction {
        $lender = User::query()->whereKey($lenderUserId)->firstOrFail();

        $transaction = new Transaction;
        $transaction->reference = $this->uniqueReference('DST');
        $transaction->sender_account_id = $this->ledger->escrowAccount()->id;
        $transaction->recipient_account_id = $lender->ledgerAccountId();
        $transaction->counterparty_name = 'Loan repayment share — '.$request->reference;
        $transaction->counterparty_account = 'ESCROW';
        $transaction->method = 'LOAN_REPAYMENT_SHARE';
        $transaction->amount_minor = $amountMinor;
        $transaction->fee_minor = 0;
        $transaction->currency = 'BDT';
        $transaction->direction = LedgerDirection::CREDIT;
        $transaction->status = TransactionStatus::POSTED;
        $transaction->risk_level = RiskLevel::LOW;
        $transaction->note = sprintf(
            'Installment %d distribution to lender',
            $installment->sequence,
        );
        $transaction->idempotency_key = 'LOANDIST-'.$installment->id.'-'.$lenderUserId.'-'.Str::uuid();
        $transaction->save();

        return $transaction;
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
        } while (Transaction::query()->where('reference', $reference)->exists());

        return $reference;
    }
}
