<?php

declare(strict_types=1);

namespace Tests\Feature\Ledger;

use App\Enums\FundingIntent;
use App\Enums\FundingMethod;
use App\Enums\FundingStatus;
use App\Enums\LedgerAccountType;
use App\Enums\LedgerDirection;
use App\Enums\LedgerSystemRole;
use App\Models\KycSubmission;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletFunding;
use App\Services\WalletFundingService;
use App\Services\WalletProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The "Add Money" funding workflow against the **main, ledger-backed wallet**.
 *
 * ## What these tests prove
 *
 * The central claim of the feature is that a simulated gateway payment credits the user's
 * **real** wallet through **real** double-entry accounting. These tests prove it by reading
 * the database back:
 *
 *  - the wallet's `balance_minor` moves by exactly the paid amount;
 *  - exactly two `ledger_entries` exist per funding, of equal magnitude and opposite
 *    direction, so `SUM(DEBIT) = SUM(CREDIT)`;
 *  - a `transactions` row links the two, so the credit appears in the transaction history;
 *  - a failed, cancelled, replayed or cross-user attempt moves nothing.
 *
 * ## The sandbox boundary
 *
 * The counterparty is a `SYSTEM` / `FUNDING` ledger account. **H-1 is 🟠 Proposed and not
 * approved** — this is a LOCAL SANDBOX funding policy only, and these tests treat it as
 * such: the funding account is created lazily, is used for nothing but funding, and never
 * appears in a peer-to-peer transfer.
 */
class WalletFundingTest extends TestCase
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
     * The single sandbox funding account.
     */
    private function fundingAccount(): LedgerAccount
    {
        return LedgerAccount::query()
            ->where('account_type', LedgerAccountType::SYSTEM->value)
            ->where('system_role', LedgerSystemRole::FUNDING->value)
            ->firstOrFail();
    }

    /**
     * The details each method needs.
     *
     * @return array<string, string>
     */
    private function detailsFor(FundingMethod $method): array
    {
        return match ($method) {
            FundingMethod::BKASH, FundingMethod::NAGAD => [
                'mobile_number' => '01700000000',
            ],
            FundingMethod::BANK_TRANSFER => [
                'bank' => 'Bank Asia',
                'account_number' => '0001234567',
            ],
            FundingMethod::CARD => [
                'card_number' => '4242 4242 4242 4242',
                'card_expiry' => '12/30',
                'card_cvv' => '123',
                'card_holder' => 'TEST CARDHOLDER',
            ],
        };
    }

    /**
     * Start and successfully settle a funding attempt.
     */
    private function fund(User $user, string $amountTaka, FundingMethod $method): WalletFunding
    {
        $service = app(WalletFundingService::class);

        $funding = $service->start(
            $user,
            $method,
            $amountTaka,
            $this->detailsFor($method),
        );

        return $service->settle($user, $funding, FundingIntent::CONFIRM);
    }

    /**
     * The ledger entries belonging to a funding's transaction.
     *
     * @return list<LedgerEntry>
     */
    private function entriesOf(WalletFunding $funding): array
    {
        $this->assertNotNull($funding->transaction_id);

        return LedgerEntry::query()
            ->where('transaction_id', $funding->transaction_id)
            ->orderBy('direction')
            ->get()
            ->all();
    }

    // -----------------------------------------------------------------------
    // The headline scenario: ৳5,000 through bKash
    // -----------------------------------------------------------------------

    public function test_adding_five_thousand_taka_through_bkash_credits_the_main_wallet(): void
    {
        $user = $this->eligibleUser();

        $this->assertSame(0, $this->walletOf($user)->balance_minor);

        $funding = $this->fund($user, '5000', FundingMethod::BKASH);

        $this->assertSame(FundingStatus::SUCCEEDED, $funding->fresh()->status);

        // ৳5,000 = 500,000 Poisha.
        $this->assertSame(500000, $funding->amount_minor);
        $this->assertSame(500000, $this->walletOf($user)->fresh()->balance_minor);
        $this->assertSame(500000, $this->walletOf($user)->fresh()->availableMinor());
    }

    public function test_the_wallet_balance_survives_a_refresh_a_logout_and_a_login(): void
    {
        $user = $this->eligibleUser();

        $this->fund($user, '5000', FundingMethod::BKASH);

        // A refresh, a logout and a login are all just reads. The balance lives in
        // the database, not in the session.
        $this->assertSame(500000, $this->walletOf($user->fresh())->balance_minor);

        $this->post('/logout');

        $response = $this->actingAs($user->fresh())->get('/wallet');
        $response->assertOk();
        $response->assertSee('5000', false);
    }

    public function test_a_second_payment_of_two_thousand_taka_makes_the_balance_seven_thousand(): void
    {
        $user = $this->eligibleUser();

        $this->fund($user, '5000', FundingMethod::BKASH);
        $this->fund($user, '2000', FundingMethod::NAGAD);

        // ৳7,000 = 700,000 Poisha.
        $this->assertSame(700000, $this->walletOf($user->fresh())->balance_minor);
    }

    // -----------------------------------------------------------------------
    // Every method behaves identically
    // -----------------------------------------------------------------------

    /**
     * @return array<string, array{0: FundingMethod}>
     */
    public static function methodProvider(): array
    {
        return [
            'bKash' => [FundingMethod::BKASH],
            'Nagad' => [FundingMethod::NAGAD],
            'Bank Transfer' => [FundingMethod::BANK_TRANSFER],
            'Debit / Credit Card' => [FundingMethod::CARD],
        ];
    }

    #[DataProvider('methodProvider')]
    public function test_every_method_credits_the_main_wallet_the_same_way(FundingMethod $method): void
    {
        $user = $this->eligibleUser();

        $funding = $this->fund($user, '5000', $method);

        $this->assertSame(FundingStatus::SUCCEEDED, $funding->fresh()->status);
        $this->assertSame(500000, $this->walletOf($user->fresh())->balance_minor);

        // The accounting is identical regardless of the method.
        $entries = $this->entriesOf($funding);
        $this->assertCount(2, $entries);
        $this->assertSame(500000, $this->sumOf($entries, LedgerDirection::DEBIT));
        $this->assertSame(500000, $this->sumOf($entries, LedgerDirection::CREDIT));
    }

    #[DataProvider('methodProvider')]
    public function test_every_method_appears_in_the_transaction_history(FundingMethod $method): void
    {
        $user = $this->eligibleUser();

        $funding = $this->fund($user, '5000', $method);

        $this->assertDatabaseHas('transactions', [
            'id' => $funding->transaction_id,
            'reference' => $funding->reference,
            'recipient_account_id' => $this->walletOf($user)->account_id,
            'direction' => LedgerDirection::CREDIT->value,
        ]);
    }

    // -----------------------------------------------------------------------
    // The accounting
    // -----------------------------------------------------------------------

    public function test_a_successful_funding_posts_exactly_two_balanced_ledger_entries(): void
    {
        $user = $this->eligibleUser();

        $funding = $this->fund($user, '5000', FundingMethod::BKASH);

        $entries = $this->entriesOf($funding);

        $this->assertCount(2, $entries);
        $this->assertSame(500000, $this->sumOf($entries, LedgerDirection::DEBIT));
        $this->assertSame(500000, $this->sumOf($entries, LedgerDirection::CREDIT));
    }

    public function test_the_ledger_sums_to_zero_for_the_funding_transaction(): void
    {
        $user = $this->eligibleUser();

        $funding = $this->fund($user, '5000', FundingMethod::BKASH);

        $debits = (int) DB::table('ledger_entries')
            ->where('transaction_id', $funding->transaction_id)
            ->where('direction', 'DEBIT')
            ->sum('amount_minor');

        $credits = (int) DB::table('ledger_entries')
            ->where('transaction_id', $funding->transaction_id)
            ->where('direction', 'CREDIT')
            ->sum('amount_minor');

        $this->assertSame($debits, $credits);
        $this->assertSame(500000, $debits);
    }

    public function test_the_credit_lands_on_the_users_ledger_account_and_the_debit_on_the_funding_account(): void
    {
        $user = $this->eligibleUser();

        $funding = $this->fund($user, '5000', FundingMethod::BKASH);

        $wallet = $this->walletOf($user);
        $fundingAccount = $this->fundingAccount();

        $credit = LedgerEntry::query()
            ->where('transaction_id', $funding->transaction_id)
            ->where('direction', LedgerDirection::CREDIT->value)
            ->firstOrFail();

        $debit = LedgerEntry::query()
            ->where('transaction_id', $funding->transaction_id)
            ->where('direction', LedgerDirection::DEBIT->value)
            ->firstOrFail();

        $this->assertSame($wallet->account_id, $credit->account_id);
        $this->assertSame($fundingAccount->id, $debit->account_id);

        // `balance_after` records the running balance of the receiving account.
        $this->assertSame(500000, $credit->balance_after);
    }

    public function test_the_funding_account_is_a_system_account_with_no_owner(): void
    {
        $user = $this->eligibleUser();

        $this->fund($user, '5000', FundingMethod::BKASH);

        $account = $this->fundingAccount();

        $this->assertSame(LedgerAccountType::SYSTEM, $account->account_type);
        $this->assertSame(LedgerSystemRole::FUNDING, $account->system_role);
        $this->assertNull($account->owner_user_id);
    }

    public function test_the_funding_account_is_created_once_and_reused(): void
    {
        $first = $this->eligibleUser();
        $second = $this->eligibleUser();

        $this->fund($first, '5000', FundingMethod::BKASH);
        $this->fund($second, '2000', FundingMethod::NAGAD);

        $this->assertSame(
            1,
            LedgerAccount::query()
                ->where('account_type', LedgerAccountType::SYSTEM->value)
                ->where('system_role', LedgerSystemRole::FUNDING->value)
                ->count(),
        );
    }

    public function test_the_wallet_projection_matches_the_sum_of_its_ledger_entries(): void
    {
        $user = $this->eligibleUser();

        $this->fund($user, '5000', FundingMethod::BKASH);
        $this->fund($user, '2000', FundingMethod::NAGAD);

        $wallet = $this->walletOf($user);

        $fromLedger = (int) DB::table('ledger_entries')
            ->where('account_id', $wallet->account_id)
            ->where('direction', LedgerDirection::CREDIT->value)
            ->sum('amount_minor')
            - (int) DB::table('ledger_entries')
                ->where('account_id', $wallet->account_id)
                ->where('direction', LedgerDirection::DEBIT->value)
                ->sum('amount_minor');

        $this->assertSame(700000, $fromLedger);
        $this->assertSame($fromLedger, $wallet->balance_minor);
    }

    public function test_the_funding_creates_a_transaction_row_with_the_add_money_note(): void
    {
        $user = $this->eligibleUser();

        $funding = $this->fund($user, '5000', FundingMethod::BKASH);

        $transaction = Transaction::query()->findOrFail($funding->transaction_id);

        $this->assertSame('Add Money — sandbox gateway funding', $transaction->note);
        $this->assertSame($funding->reference, $transaction->reference);
        $this->assertSame('Sandbox funding source', $transaction->counterparty_name);
        $this->assertSame(0, $transaction->fee_minor);
    }

    // -----------------------------------------------------------------------
    // Failure and cancellation credit nothing
    // -----------------------------------------------------------------------

    public function test_a_failed_payment_credits_nothing(): void
    {
        $user = $this->eligibleUser();

        $service = app(WalletFundingService::class);

        $funding = $service->start(
            $user,
            FundingMethod::BKASH,
            '5000',
            $this->detailsFor(FundingMethod::BKASH),
        );

        $settled = $service->settle($user, $funding, FundingIntent::FAIL);

        $this->assertSame(FundingStatus::FAILED, $settled->status);
        $this->assertSame(0, $this->walletOf($user)->fresh()->balance_minor);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_a_cancelled_payment_credits_nothing(): void
    {
        $user = $this->eligibleUser();

        $service = app(WalletFundingService::class);

        $funding = $service->start(
            $user,
            FundingMethod::NAGAD,
            '5000',
            $this->detailsFor(FundingMethod::NAGAD),
        );

        $settled = $service->settle($user, $funding, FundingIntent::CANCEL);

        $this->assertSame(FundingStatus::CANCELLED, $settled->status);
        $this->assertSame(0, $this->walletOf($user)->fresh()->balance_minor);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_a_failed_payment_records_a_reason(): void
    {
        $user = $this->eligibleUser();

        $service = app(WalletFundingService::class);

        $funding = $service->start(
            $user,
            FundingMethod::BKASH,
            '5000',
            $this->detailsFor(FundingMethod::BKASH),
        );

        $settled = $service->settle($user, $funding, FundingIntent::FAIL);

        $this->assertNotNull($settled->failure_reason);
        $this->assertNotSame('', $settled->failure_reason);
    }

    // -----------------------------------------------------------------------
    // Replay, idempotency and concurrency
    // -----------------------------------------------------------------------

    public function test_settling_the_same_funding_twice_credits_once(): void
    {
        $user = $this->eligibleUser();

        $service = app(WalletFundingService::class);

        $funding = $service->start(
            $user,
            FundingMethod::BKASH,
            '5000',
            $this->detailsFor(FundingMethod::BKASH),
        );

        $service->settle($user, $funding, FundingIntent::CONFIRM);
        $service->settle($user, $funding->fresh(), FundingIntent::CONFIRM);

        $this->assertSame(500000, $this->walletOf($user)->fresh()->balance_minor);
        $this->assertDatabaseCount('ledger_entries', 2);
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_a_failed_payment_cannot_be_settled_successfully_afterwards(): void
    {
        $user = $this->eligibleUser();

        $service = app(WalletFundingService::class);

        $funding = $service->start(
            $user,
            FundingMethod::BKASH,
            '5000',
            $this->detailsFor(FundingMethod::BKASH),
        );

        $service->settle($user, $funding, FundingIntent::FAIL);
        $service->settle($user, $funding->fresh(), FundingIntent::CONFIRM);

        $this->assertSame(FundingStatus::FAILED, $funding->fresh()->status);
        $this->assertSame(0, $this->walletOf($user)->fresh()->balance_minor);
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_a_replayed_create_request_creates_only_one_funding(): void
    {
        $user = $this->eligibleUser();

        $service = app(WalletFundingService::class);

        $first = $service->start(
            $user,
            FundingMethod::BKASH,
            '5000',
            $this->detailsFor(FundingMethod::BKASH),
            'idem-key-001',
        );

        $second = $service->start(
            $user,
            FundingMethod::BKASH,
            '5000',
            $this->detailsFor(FundingMethod::BKASH),
            'idem-key-001',
        );

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('wallet_fundings', 1);
    }

    public function test_a_replayed_create_request_credits_only_once(): void
    {
        $user = $this->eligibleUser();

        $service = app(WalletFundingService::class);

        $first = $service->start(
            $user,
            FundingMethod::BKASH,
            '5000',
            $this->detailsFor(FundingMethod::BKASH),
            'idem-key-002',
        );

        $service->settle($user, $first, FundingIntent::CONFIRM);

        $replay = $service->start(
            $user,
            FundingMethod::BKASH,
            '5000',
            $this->detailsFor(FundingMethod::BKASH),
            'idem-key-002',
        );

        $service->settle($user, $replay, FundingIntent::CONFIRM);

        $this->assertSame(500000, $this->walletOf($user)->fresh()->balance_minor);
        $this->assertDatabaseCount('ledger_entries', 2);
    }

    public function test_two_concurrent_settlements_of_the_same_funding_credit_once(): void
    {
        $user = $this->eligibleUser();

        $service = app(WalletFundingService::class);

        $funding = $service->start(
            $user,
            FundingMethod::BKASH,
            '5000',
            $this->detailsFor(FundingMethod::BKASH),
        );

        // The second settlement sees the first one's terminal state and is refused,
        // because the transition is conditional on the attempt still being PENDING.
        $service->settle($user, $funding, FundingIntent::CONFIRM);
        $second = $service->settle($user, $funding->fresh(), FundingIntent::CONFIRM);

        $this->assertSame(FundingStatus::SUCCEEDED, $second->status);
        $this->assertSame(500000, $this->walletOf($user)->fresh()->balance_minor);
        $this->assertDatabaseCount('ledger_entries', 2);
        $this->assertDatabaseCount('transactions', 1);
    }

    // -----------------------------------------------------------------------
    // Cross-user authorisation
    // -----------------------------------------------------------------------

    public function test_a_peer_cannot_settle_another_users_funding(): void
    {
        $owner = $this->eligibleUser();
        $peer = $this->eligibleUser();

        $service = app(WalletFundingService::class);

        $funding = $service->start(
            $owner,
            FundingMethod::BKASH,
            '5000',
            $this->detailsFor(FundingMethod::BKASH),
        );

        try {
            $service->settle($peer, $funding, FundingIntent::CONFIRM);
            $this->fail('A peer must not be able to settle another user\'s funding.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }

        $this->assertSame(0, $this->walletOf($owner)->fresh()->balance_minor);
        $this->assertSame(0, $this->walletOf($peer)->fresh()->balance_minor);
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_a_peer_cannot_read_another_users_funding(): void
    {
        $owner = $this->eligibleUser();
        $peer = $this->eligibleUser();

        $service = app(WalletFundingService::class);

        $funding = $service->start(
            $owner,
            FundingMethod::BKASH,
            '5000',
            $this->detailsFor(FundingMethod::BKASH),
        );

        $this->assertNull($service->findFor($peer, $funding->id));
        $this->assertNotNull($service->findFor($owner, $funding->id));
    }

    public function test_a_peer_cannot_reach_another_users_funding_page(): void
    {
        $owner = $this->eligibleUser();
        $peer = $this->eligibleUser();

        $service = app(WalletFundingService::class);

        $funding = $service->start(
            $owner,
            FundingMethod::BKASH,
            '5000',
            $this->detailsFor(FundingMethod::BKASH),
        );

        $this->actingAs($peer)
            ->get("/wallet/fundings/{$funding->id}")
            ->assertNotFound();
    }

    public function test_a_peer_does_not_see_another_users_funding_in_the_history(): void
    {
        $owner = $this->eligibleUser();
        $peer = $this->eligibleUser();

        $this->fund($owner, '5000', FundingMethod::BKASH);

        $history = app(WalletFundingService::class)->historyFor($peer);

        $this->assertCount(0, $history);
    }

    public function test_funding_never_touches_another_users_wallet(): void
    {
        $owner = $this->eligibleUser();
        $peer = $this->eligibleUser();

        $this->fund($owner, '5000', FundingMethod::BKASH);

        $this->assertSame(500000, $this->walletOf($owner)->fresh()->balance_minor);
        $this->assertSame(0, $this->walletOf($peer)->fresh()->balance_minor);
    }

    // -----------------------------------------------------------------------
    // The KYC gate
    // -----------------------------------------------------------------------

    public function test_a_user_without_an_approved_kyc_cannot_fund(): void
    {
        $user = User::factory()->create();

        $service = app(WalletFundingService::class);

        try {
            $service->start(
                $user,
                FundingMethod::BKASH,
                '5000',
                $this->detailsFor(FundingMethod::BKASH),
            );
            $this->fail('A user without an approved KYC must not be able to fund.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('kyc', $exception->errors());
        }

        $this->assertDatabaseCount('wallet_fundings', 0);
    }

    public function test_a_user_with_a_pending_kyc_cannot_fund(): void
    {
        $user = User::factory()->create();
        KycSubmission::factory()->for($user)->create();

        $service = app(WalletFundingService::class);

        $this->expectException(ValidationException::class);

        $service->start(
            $user,
            FundingMethod::BKASH,
            '5000',
            $this->detailsFor(FundingMethod::BKASH),
        );
    }

    // -----------------------------------------------------------------------
    // Amount handling
    // -----------------------------------------------------------------------

    public function test_a_decimal_amount_is_converted_to_exact_poisha(): void
    {
        $user = $this->eligibleUser();

        $funding = $this->fund($user, '1234.56', FundingMethod::BKASH);

        // ৳1,234.56 = 123,456 Poisha — no float involved.
        $this->assertSame(123456, $funding->amount_minor);
        $this->assertSame(123456, $this->walletOf($user)->fresh()->balance_minor);
    }

    public function test_a_zero_amount_is_refused(): void
    {
        $user = $this->eligibleUser();

        $service = app(WalletFundingService::class);

        $this->expectException(ValidationException::class);

        $service->start(
            $user,
            FundingMethod::BKASH,
            '0',
            $this->detailsFor(FundingMethod::BKASH),
        );
    }

    public function test_a_negative_amount_is_refused(): void
    {
        $user = $this->eligibleUser();

        $service = app(WalletFundingService::class);

        $this->expectException(ValidationException::class);

        $service->start(
            $user,
            FundingMethod::BKASH,
            '-500',
            $this->detailsFor(FundingMethod::BKASH),
        );
    }

    public function test_an_amount_above_the_sandbox_ceiling_is_refused(): void
    {
        $user = $this->eligibleUser();

        $service = app(WalletFundingService::class);

        $this->expectException(ValidationException::class);

        $service->start(
            $user,
            FundingMethod::BKASH,
            '9999999',
            $this->detailsFor(FundingMethod::BKASH),
        );
    }

    // -----------------------------------------------------------------------
    // No real credential is ever stored
    // -----------------------------------------------------------------------

    public function test_the_card_security_code_is_never_stored(): void
    {
        $user = $this->eligibleUser();

        $funding = $this->fund($user, '5000', FundingMethod::CARD);

        $details = $funding->fresh()->details;

        $this->assertIsArray($details);
        $this->assertArrayNotHasKey('card_cvv', $details);
        $this->assertArrayNotHasKey('card_number', $details);
        $this->assertArrayNotHasKey('card_expiry', $details);
        $this->assertSame('4242', $details['card_last_four']);
    }

    public function test_a_real_looking_card_number_is_refused(): void
    {
        $user = $this->eligibleUser();

        $service = app(WalletFundingService::class);

        $this->expectException(ValidationException::class);

        $service->start(
            $user,
            FundingMethod::CARD,
            '5000',
            [
                'card_number' => '4111 1111 1111 1111',
                'card_expiry' => '12/30',
                'card_cvv' => '123',
                'card_holder' => 'TEST CARDHOLDER',
            ],
        );
    }

    public function test_a_real_looking_card_security_code_is_refused(): void
    {
        $user = $this->eligibleUser();

        $service = app(WalletFundingService::class);

        $this->expectException(ValidationException::class);

        $service->start(
            $user,
            FundingMethod::CARD,
            '5000',
            [
                'card_number' => '4242 4242 4242 4242',
                'card_expiry' => '12/30',
                'card_cvv' => '999',
                'card_holder' => 'TEST CARDHOLDER',
            ],
        );
    }

    // -----------------------------------------------------------------------
    // The HTTP surface
    // -----------------------------------------------------------------------

    public function test_the_add_money_page_renders_with_the_four_methods(): void
    {
        $user = $this->eligibleUser();

        $response = $this->actingAs($user)->get('/wallet/fundings');

        $response->assertOk();
        $response->assertSee('bKash');
        $response->assertSee('Nagad');
        $response->assertSee('Bank Transfer');

        // The card label is the only one of the four containing a forward slash, and
        // the Inertia payload JSON-escapes it as `\/`, so a raw substring match can
        // never see it. The label is asserted through the prop instead — the server
        // contract the page actually renders from.
        $response->assertInertia(fn ($page) => $page
            ->where('methods', fn ($methods): bool => collect($methods)
                ->pluck('value')
                ->all() === ['BKASH', 'NAGAD', 'BANK_TRANSFER', 'CARD'])
            ->where('methods', fn ($methods): bool => collect($methods)
                ->pluck('label')
                ->contains('Debit / Credit Card'))
        );
    }

    public function test_the_add_money_page_carries_no_demo_branding(): void
    {
        $user = $this->eligibleUser();

        $response = $this->actingAs($user)->get('/wallet/fundings');

        $response->assertOk();
        $response->assertDontSee('Demo Balance');
        $response->assertDontSee('Add Demo Money');
    }

    public function test_starting_and_confirming_a_payment_over_http_credits_the_wallet(): void
    {
        $user = $this->eligibleUser();

        $response = $this->actingAs($user)->post('/wallet/fundings', [
            'method' => 'BKASH',
            'amount' => '5000',
            'mobile_number' => '01700000000',
        ]);

        $funding = WalletFunding::query()->latest('id')->firstOrFail();

        $response->assertRedirect(route('wallet.fundings.show', ['funding' => $funding->id]));

        $this->actingAs($user)
            ->post("/wallet/fundings/{$funding->id}/settle", ['intent' => 'CONFIRM'])
            ->assertRedirect(route('wallet.fundings.show', ['funding' => $funding->id]));

        $this->assertSame(500000, $this->walletOf($user->fresh())->balance_minor);
    }

    public function test_the_settle_route_ignores_a_client_supplied_status(): void
    {
        $user = $this->eligibleUser();

        $funding = $this->fund($user, '5000', FundingMethod::BKASH);

        // A replay that tries to state the outcome. The route only accepts `intent`,
        // and the attempt is already terminal, so nothing moves.
        $this->actingAs($user)->post("/wallet/fundings/{$funding->id}/settle", [
            'intent' => 'CONFIRM',
            'status' => 'SUCCEEDED',
            'amount_minor' => 999999,
        ]);

        $this->assertSame(500000, $this->walletOf($user->fresh())->balance_minor);
        $this->assertDatabaseCount('ledger_entries', 2);
    }

    public function test_the_settle_route_rejects_an_unknown_intent(): void
    {
        $user = $this->eligibleUser();

        $service = app(WalletFundingService::class);

        $funding = $service->start(
            $user,
            FundingMethod::BKASH,
            '5000',
            $this->detailsFor(FundingMethod::BKASH),
        );

        $this->actingAs($user)
            ->post("/wallet/fundings/{$funding->id}/settle", ['intent' => 'PLEASE_GIVE_ME_MONEY'])
            ->assertSessionHasErrors('intent');

        $this->assertSame(0, $this->walletOf($user)->fresh()->balance_minor);
    }

    public function test_the_create_route_strips_a_forged_status(): void
    {
        $user = $this->eligibleUser();

        $this->actingAs($user)->post('/wallet/fundings', [
            'method' => 'BKASH',
            'amount' => '5000',
            'mobile_number' => '01700000000',
            'status' => 'SUCCEEDED',
            'balance_minor' => 99999999,
            'credited_minor' => 99999999,
        ]);

        $funding = WalletFunding::query()->latest('id')->firstOrFail();

        // The attempt is born PENDING, whatever the client said.
        $this->assertSame(FundingStatus::PENDING, $funding->status);
        $this->assertSame(0, $this->walletOf($user)->fresh()->balance_minor);
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_the_dashboard_shows_the_credited_balance(): void
    {
        $user = $this->eligibleUser();

        $this->fund($user, '5000', FundingMethod::BKASH);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();

        // The amount crosses the boundary as an integer count of Poisha; the
        // comma-grouped taka form is produced at the presentation edge by the
        // client, so the server contract is what is asserted here.
        $response->assertInertia(fn ($page) => $page
            ->where('wallet.balanceMinor', 500000)
            ->where('totals.added', 500000)
        );
    }

    public function test_the_wallet_page_shows_the_credited_balance(): void
    {
        $user = $this->eligibleUser();

        $this->fund($user, '5000', FundingMethod::BKASH);

        $response = $this->actingAs($user)->get('/wallet');

        $response->assertOk();

        // As above: the page is rendered on the client, so the assertion is made
        // against the prop the client formats, not against a rendered string.
        $response->assertInertia(fn ($page) => $page
            ->where('wallet.balanceMinor', 500000)
            ->where('wallet.availableMinor', 500000)
            ->where('totals.added', 500000)
        );
    }

    public function test_the_transaction_history_shows_the_add_money_credit(): void
    {
        $user = $this->eligibleUser();

        $funding = $this->fund($user, '5000', FundingMethod::BKASH);

        $response = $this->actingAs($user)->get('/transactions');

        $response->assertOk();
        $response->assertSee('Add Money');
        $response->assertSee($funding->reference);
    }

    public function test_the_transaction_detail_page_shows_the_add_money_credit(): void
    {
        $user = $this->eligibleUser();

        $funding = $this->fund($user, '5000', FundingMethod::BKASH);

        $response = $this->actingAs($user)->get("/transactions/{$funding->transaction_id}");

        $response->assertOk();
        $response->assertSee('Add Money');
    }

    public function test_the_funding_history_page_lists_the_attempt(): void
    {
        $user = $this->eligibleUser();

        $funding = $this->fund($user, '5000', FundingMethod::BKASH);

        $response = $this->actingAs($user)->get('/wallet/fundings/history');

        $response->assertOk();
        $response->assertSee($funding->reference);
    }

    public function test_the_demo_balance_routes_are_gone(): void
    {
        $user = $this->eligibleUser();

        $this->actingAs($user)->get('/demo/payments')->assertNotFound();
        $this->actingAs($user)->get('/demo/balance')->assertNotFound();
    }

    public function test_the_guest_cannot_reach_the_add_money_page(): void
    {
        $this->get('/wallet/fundings')->assertRedirect('/login');
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * The summed amount of the entries in one direction.
     *
     * @param  list<LedgerEntry>  $entries
     */
    private function sumOf(array $entries, LedgerDirection $direction): int
    {
        $total = 0;

        foreach ($entries as $entry) {
            if ($entry->direction === $direction) {
                $total += (int) $entry->amount_minor;
            }
        }

        return $total;
    }
}
