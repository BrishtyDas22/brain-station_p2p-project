<?php

declare(strict_types=1);

namespace Tests\Feature\Lending;

use App\Enums\FundingIntent;
use App\Enums\FundingMethod;
use App\Enums\InstallmentStatus;
use App\Enums\LedgerAccountType;
use App\Enums\LedgerDirection;
use App\Enums\LedgerSystemRole;
use App\Enums\LoanStatus;
use App\Enums\WithdrawalIntent;
use App\Enums\WithdrawalMethod;
use App\Enums\WithdrawalStatus;
use App\Models\KycSubmission;
use App\Models\LedgerAccount;
use App\Models\LoanInstallment;
use App\Models\LoanRequest;
use App\Models\User;
use App\Models\Wallet;
use App\Services\LoanService;
use App\Services\RepaymentService;
use App\Services\WalletFundingService;
use App\Services\WalletProvisioningService;
use App\Services\WithdrawalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The micro-lending workflow, end to end, against the real database.
 *
 * ## What these tests prove
 *
 * The PRD's central scenario is:
 *
 * > When a lender commits 5,000 BDT from their available wallet balance, then the system
 * > locks the 5,000 BDT under the status "ESCROW_LOCKED", and detects that the target
 * > funding threshold (100%) has been achieved, and transitions the loan state to
 * > "DISBURSED", and transfers the total principal into the borrower's spendable wallet
 * > balance.
 *
 * These tests prove it by reading the database back:
 *
 *  - the lender's wallet is debited and the escrow account credited by the same amount;
 *  - the escrow account holds the capital until the listing is fully funded;
 *  - at 100% funding the escrow account is debited and the borrower's wallet credited;
 *  - every movement is a balanced pair, so `SUM(DEBIT) = SUM(CREDIT)`;
 *  - overfunding, duplicate funding, insufficient balance and cross-user access are
 *    all refused, and none of them moves any money.
 *
 * ## The money is real, the gateway is not
 *
 * A lender funds from their **real** wallet balance. There is no external gateway in
 * the funding path, because no money leaves the platform when a lender commits
 * capital — it moves from one internal wallet into custody. Only "Withdraw Money"
 * involves a simulated external payout, and that is simulated exactly like "Add Money".
 */
class LoanMarketplaceTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * A signed-in user with an approved KYC and a provisioned, zero-balance wallet.
     */
    private function eligibleUser(): User
    {
        $user = User::factory()->create();
        KycSubmission::factory()->for($user)->approved()->create();

        app(WalletProvisioningService::class)->provision($user);

        return $user->fresh();
    }

    /**
     * The user's main wallet.
     */
    private function walletOf(User $user): Wallet
    {
        return Wallet::query()
            ->where('owner_user_id', $user->id)
            ->firstOrFail();
    }

    /**
     * The single escrow account.
     */
    private function escrowAccount(): LedgerAccount
    {
        return LedgerAccount::query()
            ->where('account_type', LedgerAccountType::SYSTEM->value)
            ->where('system_role', LedgerSystemRole::ESCROW->value)
            ->firstOrFail();
    }

    /**
     * Give a user money the honest way: through the "Add Money" gateway, so the
     * balance is backed by real ledger entries.
     */
    private function addMoney(User $user, string $amountTaka): void
    {
        $service = app(WalletFundingService::class);

        $funding = $service->start(
            $user,
            FundingMethod::BKASH,
            $amountTaka,
            ['mobile_number' => '01700000000'],
        );

        $service->settle($user, $funding, FundingIntent::CONFIRM);
    }

    /**
     * A borrower with a live listing on the marketplace.
     */
    private function listedLoan(User $borrower, string $principalTaka = '20000'): LoanRequest
    {
        return app(LoanService::class)->createRequest(
            $borrower,
            $principalTaka,
            3,
            1200,
            'Working capital for a small trading inventory.',
        );
    }

    /**
     * The ledger entries for one transaction.
     *
     * @return Collection<int, object>
     */
    private function entriesOf(int $transactionId)
    {
        return DB::table('ledger_entries')
            ->where('transaction_id', $transactionId)
            ->orderBy('direction')
            ->get();
    }

    /**
     * The total debited and credited across the whole book.
     *
     * @return array{int, int}
     */
    private function wholeBook(): array
    {
        $debits = (int) DB::table('ledger_entries')
            ->where('direction', LedgerDirection::DEBIT->value)
            ->sum('amount_minor');

        $credits = (int) DB::table('ledger_entries')
            ->where('direction', LedgerDirection::CREDIT->value)
            ->sum('amount_minor');

        return [$debits, $credits];
    }

    // -----------------------------------------------------------------------
    // Requesting a loan
    // -----------------------------------------------------------------------

    public function test_a_kyc_approved_borrower_can_list_a_loan_request(): void
    {
        $borrower = $this->eligibleUser();

        $request = $this->listedLoan($borrower, '20000');

        $this->assertSame(LoanStatus::MARKETPLACE_LISTED, $request->status);
        $this->assertSame(2000000, $request->principal_minor);
        $this->assertSame(1200, $request->interest_rate_bps);
        $this->assertSame(3, $request->tenure_months);
        $this->assertSame(0, $request->funded_minor);
        $this->assertSame(0, $request->investor_count);
    }

    public function test_listing_a_loan_moves_no_money(): void
    {
        $borrower = $this->eligibleUser();

        $before = $this->wholeBook();

        $this->listedLoan($borrower);

        $after = $this->wholeBook();

        $this->assertSame($before, $after);
        $this->assertSame(0, $this->walletOf($borrower)->balance_minor);
    }

    public function test_a_borrower_without_approved_kyc_cannot_request_a_loan(): void
    {
        $borrower = User::factory()->create();

        $this->expectException(ValidationException::class);

        app(LoanService::class)->createRequest(
            $borrower,
            '20000',
            3,
            1200,
            'Working capital for a small trading inventory.',
        );
    }

    public function test_a_principal_below_the_prd_minimum_is_refused(): void
    {
        $borrower = $this->eligibleUser();

        $this->expectException(ValidationException::class);

        app(LoanService::class)->createRequest(
            $borrower,
            '4999',
            3,
            1200,
            'Working capital for a small trading inventory.',
        );
    }

    public function test_a_principal_above_the_prd_maximum_is_refused(): void
    {
        $borrower = $this->eligibleUser();

        $this->expectException(ValidationException::class);

        app(LoanService::class)->createRequest(
            $borrower,
            '50001',
            3,
            1200,
            'Working capital for a small trading inventory.',
        );
    }

    // -----------------------------------------------------------------------
    // Funding
    // -----------------------------------------------------------------------

    public function test_a_lender_funding_a_loan_moves_the_money_into_escrow(): void
    {
        $borrower = $this->eligibleUser();
        $lender = $this->eligibleUser();

        $this->addMoney($lender, '10000');

        $request = $this->listedLoan($borrower, '20000');

        $investment = app(LoanService::class)->fund($lender, $request, '5000');

        $this->assertSame(500000, $investment->amount_minor);

        // The lender's wallet gave up the money.
        $this->assertSame(500000, $this->walletOf($lender)->balance_minor);

        // The escrow account holds it.
        $entries = $this->entriesOf($investment->transaction_id);
        $this->assertCount(2, $entries);

        $debit = $entries->firstWhere('direction', LedgerDirection::DEBIT->value);
        $credit = $entries->firstWhere('direction', LedgerDirection::CREDIT->value);

        $this->assertNotNull($debit);
        $this->assertNotNull($credit);
        $this->assertSame(500000, (int) $debit->amount_minor);
        $this->assertSame(500000, (int) $credit->amount_minor);
        $this->assertSame($this->escrowAccount()->id, (int) $credit->account_id);

        // The listing's progress advanced.
        $request->refresh();
        $this->assertSame(500000, $request->funded_minor);
        $this->assertSame(1, $request->investor_count);
        $this->assertSame(25, $request->fundingPercent());
        $this->assertSame(LoanStatus::MARKETPLACE_LISTED, $request->status);
    }

    public function test_the_escrow_account_holds_the_capital_between_funding_and_disbursement(): void
    {
        $borrower = $this->eligibleUser();
        $lender = $this->eligibleUser();

        $this->addMoney($lender, '10000');

        $request = $this->listedLoan($borrower, '20000');

        app(LoanService::class)->fund($lender, $request, '5000');

        // The escrow's running balance is the capital currently held.
        $escrowEntries = DB::table('ledger_entries')
            ->where('account_id', $this->escrowAccount()->id)
            ->get();

        $credited = (int) $escrowEntries
            ->where('direction', LedgerDirection::CREDIT->value)
            ->sum('amount_minor');

        $debited = (int) $escrowEntries
            ->where('direction', LedgerDirection::DEBIT->value)
            ->sum('amount_minor');

        $this->assertSame(500000, $credited - $debited);

        // The borrower has nothing yet.
        $this->assertSame(0, $this->walletOf($borrower)->balance_minor);
    }

    public function test_funding_beyond_the_remaining_amount_is_refused(): void
    {
        $borrower = $this->eligibleUser();
        $lender = $this->eligibleUser();

        $this->addMoney($lender, '30000');

        $request = $this->listedLoan($borrower, '20000');

        $this->expectException(ValidationException::class);

        app(LoanService::class)->fund($lender, $request, '25000');
    }

    public function test_a_lender_cannot_fund_the_same_listing_twice(): void
    {
        $borrower = $this->eligibleUser();
        $lender = $this->eligibleUser();

        $this->addMoney($lender, '20000');

        $request = $this->listedLoan($borrower, '20000');

        app(LoanService::class)->fund($lender, $request, '5000');

        $this->expectException(ValidationException::class);

        app(LoanService::class)->fund($lender, $request, '5000');
    }

    public function test_funding_more_than_the_available_balance_is_refused(): void
    {
        $borrower = $this->eligibleUser();
        $lender = $this->eligibleUser();

        $this->addMoney($lender, '5000');

        $request = $this->listedLoan($borrower, '20000');

        $this->expectException(ValidationException::class);

        app(LoanService::class)->fund($lender, $request, '10000');
    }

    public function test_a_borrower_cannot_fund_their_own_listing(): void
    {
        $borrower = $this->eligibleUser();

        $this->addMoney($borrower, '20000');

        $request = $this->listedLoan($borrower, '20000');

        $this->expectException(ValidationException::class);

        app(LoanService::class)->fund($borrower, $request, '5000');
    }

    public function test_a_replayed_funding_request_credits_once(): void
    {
        $borrower = $this->eligibleUser();
        $lender = $this->eligibleUser();

        $this->addMoney($lender, '10000');

        $request = $this->listedLoan($borrower, '20000');

        $service = app(LoanService::class);

        $first = $service->fund($lender, $request, '5000', 'replay-key-1');
        $second = $service->fund($lender, $request, '5000', 'replay-key-1');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(500000, $this->walletOf($lender)->balance_minor);

        $request->refresh();
        $this->assertSame(500000, $request->funded_minor);
        $this->assertSame(1, $request->investor_count);
    }

    // -----------------------------------------------------------------------
    // Disbursement
    // -----------------------------------------------------------------------

    public function test_reaching_full_funding_disburses_the_principal_to_the_borrower(): void
    {
        $borrower = $this->eligibleUser();
        $lender = $this->eligibleUser();

        $this->addMoney($lender, '20000');

        $request = $this->listedLoan($borrower, '20000');

        app(LoanService::class)->fund($lender, $request, '20000');

        $request->refresh();

        // The PRD's transition.
        $this->assertSame(LoanStatus::DISBURSED, $request->status);
        $this->assertNotNull($request->funded_at);

        // The borrower's spendable wallet now holds the principal.
        $this->assertSame(2000000, $this->walletOf($borrower)->balance_minor);

        // The escrow is empty again.
        $escrowEntries = DB::table('ledger_entries')
            ->where('account_id', $this->escrowAccount()->id)
            ->get();

        $credited = (int) $escrowEntries
            ->where('direction', LedgerDirection::CREDIT->value)
            ->sum('amount_minor');

        $debited = (int) $escrowEntries
            ->where('direction', LedgerDirection::DEBIT->value)
            ->sum('amount_minor');

        $this->assertSame(0, $credited - $debited);
    }

    public function test_two_lenders_funding_the_same_loan_disburse_once(): void
    {
        $borrower = $this->eligibleUser();
        $lenderOne = $this->eligibleUser();
        $lenderTwo = $this->eligibleUser();

        $this->addMoney($lenderOne, '12000');
        $this->addMoney($lenderTwo, '8000');

        $request = $this->listedLoan($borrower, '20000');

        $service = app(LoanService::class);

        $service->fund($lenderOne, $request, '12000');
        $service->fund($lenderTwo, $request, '8000');

        $request->refresh();

        $this->assertSame(LoanStatus::DISBURSED, $request->status);
        $this->assertSame(2000000, $request->funded_minor);
        $this->assertSame(2, $request->investor_count);

        // The borrower receives the principal exactly once.
        $this->assertSame(2000000, $this->walletOf($borrower)->balance_minor);

        // And the whole book is balanced.
        [$debits, $credits] = $this->wholeBook();
        $this->assertSame($debits, $credits);
    }

    public function test_the_whole_book_stays_balanced_through_the_whole_flow(): void
    {
        $borrower = $this->eligibleUser();
        $lender = $this->eligibleUser();

        $this->addMoney($lender, '20000');

        $request = $this->listedLoan($borrower, '20000');

        app(LoanService::class)->fund($lender, $request, '20000');

        [$debits, $credits] = $this->wholeBook();

        $this->assertSame($debits, $credits);
        $this->assertGreaterThan(0, $debits);
    }

    public function test_the_repayment_schedule_is_created_at_disbursement(): void
    {
        $borrower = $this->eligibleUser();
        $lender = $this->eligibleUser();

        $this->addMoney($lender, '20000');

        $request = $this->listedLoan($borrower, '20000');

        app(LoanService::class)->fund($lender, $request, '20000');

        $installments = LoanInstallment::query()
            ->where('loan_request_id', $request->id)
            ->orderBy('sequence')
            ->get();

        $this->assertCount(3, $installments);

        // Principal 20000 at 12% over 3 months → 22400 total.
        $total = (int) $installments->sum('amount_minor');
        $this->assertSame(2240000, $total);

        // The split adds up on every installment.
        foreach ($installments as $installment) {
            $this->assertSame(
                (int) $installment->principal_minor + (int) $installment->interest_minor,
                (int) $installment->amount_minor,
            );
            $this->assertSame(InstallmentStatus::PENDING, $installment->status);
        }
    }

    // -----------------------------------------------------------------------
    // Repayment
    // -----------------------------------------------------------------------

    public function test_collecting_an_installment_pays_the_lenders_proportionally(): void
    {
        $borrower = $this->eligibleUser();
        $lenderOne = $this->eligibleUser();
        $lenderTwo = $this->eligibleUser();

        $this->addMoney($lenderOne, '20000');
        $this->addMoney($lenderTwo, '20000');

        $request = $this->listedLoan($borrower, '20000');

        $service = app(LoanService::class);
        $service->fund($lenderOne, $request, '12000');
        $service->fund($lenderTwo, $request, '8000');

        // The borrower now has the principal, so it can repay.
        $this->assertSame(2000000, $this->walletOf($borrower)->balance_minor);

        // What each lender still holds after committing its capital to escrow.
        $lenderOneAfterFunding = $this->walletOf($lenderOne)->balance_minor;
        $lenderTwoAfterFunding = $this->walletOf($lenderTwo)->balance_minor;

        $this->assertSame(800000, $lenderOneAfterFunding);
        $this->assertSame(1200000, $lenderTwoAfterFunding);

        $installment = LoanInstallment::query()
            ->where('loan_request_id', $request->id)
            ->where('sequence', 1)
            ->firstOrFail();

        app(RepaymentService::class)->collect($installment);

        $installment->refresh();

        $this->assertSame(InstallmentStatus::PAID, $installment->status);
        $this->assertNotNull($installment->paid_at);

        // 12/20 of the first installment went to lender one, 8/20 to lender two.
        $installmentAmount = 746666;

        // The integer division leaves a remainder Poisha, which the service hands to
        // the largest commitment — so lender one takes it and the two shares still add
        // up to exactly the installment.
        $shareOne = (int) intdiv($installmentAmount * 1200000, 2000000) + 1;
        $shareTwo = (int) intdiv($installmentAmount * 800000, 2000000);

        $this->assertSame(
            $lenderOneAfterFunding + $shareOne,
            $this->walletOf($lenderOne)->balance_minor,
        );

        $this->assertSame(
            $lenderTwoAfterFunding + $shareTwo,
            $this->walletOf($lenderTwo)->balance_minor,
        );

        // The two shares add up to exactly the installment — the remainder Poisha is
        // handed to the largest commitment, never lost.
        $this->assertSame(
            $installmentAmount,
            $shareOne + $shareTwo,
        );

        // The book stays balanced.
        [$debits, $credits] = $this->wholeBook();
        $this->assertSame($debits, $credits);
    }

    public function test_collecting_the_same_installment_twice_moves_no_extra_money(): void
    {
        $borrower = $this->eligibleUser();
        $lender = $this->eligibleUser();

        $this->addMoney($lender, '20000');

        $request = $this->listedLoan($borrower, '20000');

        app(LoanService::class)->fund($lender, $request, '20000');

        $installment = LoanInstallment::query()
            ->where('loan_request_id', $request->id)
            ->where('sequence', 1)
            ->firstOrFail();

        $service = app(RepaymentService::class);

        $service->collect($installment);
        $afterFirst = $this->walletOf($borrower)->balance_minor;

        $service->collect($installment);

        $this->assertSame($afterFirst, $this->walletOf($borrower)->balance_minor);
    }

    public function test_an_unaffordable_installment_enters_the_grace_period(): void
    {
        $borrower = $this->eligibleUser();
        $lender = $this->eligibleUser();

        $this->addMoney($lender, '20000');

        $request = $this->listedLoan($borrower, '20000');

        app(LoanService::class)->fund($lender, $request, '20000');

        // The borrower spends the principal down, so the installment cannot be paid.
        $wallet = $this->walletOf($borrower);
        $wallet->balance_minor = 100;
        $wallet->save();

        $installment = LoanInstallment::query()
            ->where('loan_request_id', $request->id)
            ->where('sequence', 1)
            ->firstOrFail();

        app(RepaymentService::class)->collect($installment);

        $installment->refresh();

        $this->assertSame(InstallmentStatus::GRACE_PERIOD, $installment->status);
        $this->assertNotNull($installment->grace_started_at);
        $this->assertSame(100, $this->walletOf($borrower)->balance_minor);
    }

    public function test_paying_every_installment_marks_the_loan_repaid(): void
    {
        $borrower = $this->eligibleUser();
        $lender = $this->eligibleUser();

        $this->addMoney($lender, '50000');

        // The borrower needs the principal AND the interest to clear the schedule,
        // so they top their wallet up beyond the disbursed amount.
        $this->addMoney($borrower, '3000');

        $request = $this->listedLoan($borrower, '20000');

        app(LoanService::class)->fund($lender, $request, '20000');

        $service = app(RepaymentService::class);

        $installments = LoanInstallment::query()
            ->where('loan_request_id', $request->id)
            ->orderBy('sequence')
            ->get();

        foreach ($installments as $installment) {
            $service->collect($installment);
        }

        $request->refresh();

        $this->assertSame(LoanStatus::REPAID, $request->status);
        $this->assertNotNull($request->repaid_at);

        // Every installment was collected.
        $this->assertSame(
            0,
            LoanInstallment::query()
                ->where('loan_request_id', $request->id)
                ->where('status', '!=', InstallmentStatus::PAID->value)
                ->count(),
        );

        // The lender started with 50000, put 20000 into escrow, and got 22400 back.
        $this->assertSame(5240000, $this->walletOf($lender)->balance_minor);
    }

    // -----------------------------------------------------------------------
    // Withdrawals
    // -----------------------------------------------------------------------

    public function test_a_successful_withdrawal_debits_the_wallet(): void
    {
        $user = $this->eligibleUser();

        $this->addMoney($user, '10000');

        $service = app(WithdrawalService::class);

        $withdrawal = $service->start(
            $user,
            WithdrawalMethod::BKASH,
            '5000',
            ['mobile_number' => '01700000000'],
        );

        $settled = $service->settle($user, $withdrawal, WithdrawalIntent::CONFIRM);

        $this->assertSame(WithdrawalStatus::SUCCEEDED, $settled->status);
        $this->assertSame(500000, $this->walletOf($user)->balance_minor);

        $entries = $this->entriesOf((int) $settled->transaction_id);
        $this->assertCount(2, $entries);

        [$debits, $credits] = $this->wholeBook();
        $this->assertSame($debits, $credits);
    }

    public function test_a_failed_withdrawal_debits_nothing(): void
    {
        $user = $this->eligibleUser();

        $this->addMoney($user, '10000');

        $service = app(WithdrawalService::class);

        $withdrawal = $service->start(
            $user,
            WithdrawalMethod::NAGAD,
            '5000',
            ['mobile_number' => '01800000000'],
        );

        $settled = $service->settle($user, $withdrawal, WithdrawalIntent::FAIL);

        $this->assertSame(WithdrawalStatus::FAILED, $settled->status);
        $this->assertSame(1000000, $this->walletOf($user)->balance_minor);
    }

    public function test_a_withdrawal_larger_than_the_balance_is_refused(): void
    {
        $user = $this->eligibleUser();

        $this->addMoney($user, '5000');

        $this->expectException(ValidationException::class);

        app(WithdrawalService::class)->start(
            $user,
            WithdrawalMethod::BKASH,
            '10000',
            ['mobile_number' => '01700000000'],
        );
    }

    public function test_a_withdrawal_never_stores_a_credential(): void
    {
        $user = $this->eligibleUser();

        $this->addMoney($user, '10000');

        $this->expectException(\InvalidArgumentException::class);

        app(WithdrawalService::class)->start(
            $user,
            WithdrawalMethod::BKASH,
            '5000',
            ['mobile_number' => '01700000000', 'pin' => '1234'],
        );
    }

    public function test_a_withdrawal_masks_the_destination(): void
    {
        $user = $this->eligibleUser();

        $this->addMoney($user, '10000');

        $withdrawal = app(WithdrawalService::class)->start(
            $user,
            WithdrawalMethod::BKASH,
            '5000',
            ['mobile_number' => '01712345678'],
        );

        $this->assertSame('••••5678', $withdrawal->details['mobile_number']);
        $this->assertStringNotContainsString('01712345678', json_encode($withdrawal->details));
    }

    public function test_a_peer_cannot_settle_another_users_withdrawal(): void
    {
        $owner = $this->eligibleUser();
        $peer = $this->eligibleUser();

        $this->addMoney($owner, '10000');

        $withdrawal = app(WithdrawalService::class)->start(
            $owner,
            WithdrawalMethod::BKASH,
            '5000',
            ['mobile_number' => '01700000000'],
        );

        $this->expectException(ValidationException::class);

        app(WithdrawalService::class)->settle($peer, $withdrawal, WithdrawalIntent::CONFIRM);
    }
}
